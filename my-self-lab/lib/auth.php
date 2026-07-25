<?php
/**
 * MySelf-Lab — couche auth (reprise du protocole SelfRecover).
 *
 * Modèle : compte sans email. À l'inscription, l'utilisateur choisit son
 * recovery word. Le serveur génère un password (16 chars) + une passphrase
 * diceware EFF (L1). On dérive recovery_key = HMAC(recovery_word, domain||salt)
 * et on stocke Argon2id(password), Argon2id(passphrase), Argon2id(recovery_key).
 *
 * Aucun secret n'est conservé en clair en base (version prod-like).
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

require_once __DIR__ . '/diceware/wordlist.php';

final class Auth
{
    public const DOMAIN = 'myself-lab.example';
    private const COOKIE = 'lab_session';
    private const SESSION_TTL = 86400;        // 24h
    private const REGISTER_MAX_PER_IP = 5;    // max comptes créés / IP / heure
    private const LOGIN_MAX_FAILS = 5;        // échecs / username avant blocage temporaire
    private const LOGIN_MAX_FAILS_PER_IP = 12; // échecs cumulés / IP / fenêtre (anti-spraying, tolère un foyer NAT)
    private const LOGIN_WINDOW = 900;         // fenêtre de comptage (15 min)
    private const RECOVER_ESCALATE = 3;       // échecs à un niveau de récup → propose le niveau suivant
    private const RECOVERY_CODE_COUNT = 10;   // lot de recovery codes généré à l'inscription
    /** Options Argon2id (R9-06, alignées sur le profil OWASP de SelfRecover). */
    private const ARGON2 = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2];
    /**
     * Hash Argon2id factice (R9-06), exécuté quand le compte n'existe pas, pour que
     * password_verify prenne le même temps qu'avec un vrai compte (anti-énumération
     * par timing — DOIT être du même algo que les vrais hash). Aucun mot de passe réel.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=2$SXQ3V2s0SHVuaEZWR003bQ$FM4OpKIf6dEQsf0BMOE6uFqG+OyWDcTRR6+tUoKpOWA';

    /** derived_key = HMAC-SHA256(recovery_word, domain || site_salt). */
    public static function deriveKey(string $recoveryWord, string $domain, string $siteSalt): string
    {
        return hash_hmac('sha256', $domain . $siteSalt, $recoveryWord);
    }

    /** Site salt persistant (32 bytes), propre à ce déploiement. Hors webroot. */
    public static function siteSalt(): string
    {
        $f = __DIR__ . '/../data/.sitesalt';
        if (!file_exists($f)) {
            file_put_contents($f, bin2hex(random_bytes(32)));
            @chmod($f, 0600);
        }
        return trim((string) file_get_contents($f));
    }

    /**
     * Pepper serveur (SERVER_SECRET, 32 bytes) hors-DB. Sert au lookup HMAC O(1) des
     * recovery codes : code_lookup = HMAC(serverSecret, code) → retrouve le compte sans
     * identifiant et sans énumération, non réversible même si la DB fuite.
     */
    public static function serverSecret(): string
    {
        $f = __DIR__ . '/../data/.serversecret';
        if (!file_exists($f)) {
            file_put_contents($f, bin2hex(random_bytes(32)));
            @chmod($f, 0600);
        }
        return trim((string) file_get_contents($f));
    }

    public static function generatePassword(int $length = 16): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!?@#$';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    public static function generateSessionToken(): string
    {
        return bin2hex(random_bytes(24)); // 48 hex chars
    }

    /** Options Argon2id du déploiement (réutilisées par le facteur appareil). */
    public static function argon2Options(): array
    {
        return self::ARGON2;
    }

    /**
     * Inscription. Retourne ['ok'=>bool, ...].
     * En cas de succès : credentials (password + passphrase) à copier par l'user.
     */
    public static function register(PDO $pdo, string $username, string $recoveryDerivedKey, ?string $ip = null): array
    {
        // Anti-abus : limite la création massive de comptes par IP (anti-énumération + anti-spam)
        if ($ip !== null) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM login_attempts WHERE username = ? AND ip = ? AND attempted_at > ?'
            );
            $stmt->execute(['__register__', $ip, time() - 3600]);
            if ((int) $stmt->fetchColumn() >= self::REGISTER_MAX_PER_IP) {
                return ['ok' => false, 'error' => 'rate_limited',
                        'message' => 'Trop de comptes créés depuis cette connexion. Réessaie plus tard.'];
            }
        }

        $username = strtolower(trim($username));
        if (!preg_match('/^[a-z0-9_]{3,20}$/', $username)) {
            return ['ok' => false, 'error' => 'invalid_username',
                    'message' => "Identifiant : 3 à 20 caractères minuscules, chiffres ou _."];
        }
        // La clé du mot mémorisé est dérivée CÔTÉ CLIENT (HMAC-SHA256) : le mot brut ne
        // parvient jamais ici (promesse SelfRecover). On valide juste le format (64 hex).
        // La force/longueur du mot est contrôlée côté navigateur.
        if (!preg_match('/^[a-f0-9]{64}$/', $recoveryDerivedKey)) {
            return ['ok' => false, 'error' => 'invalid_recovery',
                    'message' => 'Mot de récupération invalide.'];
        }

        $stmt = $pdo->prepare('SELECT 1 FROM accounts WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetchColumn()) {
            // LAB-05 : une sonde d'existence consomme le quota IP au même titre qu'une création,
            // pour casser l'énumération de masse (les usernames restent publics par nature sur un forum).
            if ($ip !== null) {
                $pdo->prepare('INSERT INTO login_attempts (username, success, ip, attempted_at) VALUES (?, 1, ?, ?)')
                    ->execute(['__register__', $ip, time()]);
            }
            return ['ok' => false, 'error' => 'username_taken',
                    'message' => 'Cet identifiant est déjà pris.'];
        }

        $password = self::generatePassword(16);
        $diceware = \DicewareWordlist::generate(4, 'en');
        $passphrase = implode(' ', $diceware['words']);

        $pwHash       = password_hash($password, PASSWORD_ARGON2ID, self::ARGON2);
        $passHash     = password_hash($passphrase, PASSWORD_ARGON2ID, self::ARGON2);
        $recoveryHash = password_hash($recoveryDerivedKey, PASSWORD_ARGON2ID, self::ARGON2);

        $stmt = $pdo->prepare(
            'INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$username, $pwHash, $passHash, $recoveryHash, time()]);
        $accountId = (int) $pdo->lastInsertId();

        // Lot de recovery codes (facteur possession de L2) — affichés UNE seule fois.
        $recoveryCodes = self::generateRecoveryCodes($pdo, $accountId);

        // Trace la création pour le rate-limit IP
        if ($ip !== null) {
            $pdo->prepare('INSERT INTO login_attempts (username, success, ip, attempted_at) VALUES (?, 1, ?, ?)')
                ->execute(['__register__', $ip, time()]);
        }

        return [
            'ok' => true,
            'account_id' => $accountId,
            'username' => $username,
            'credentials' => [
                'password' => $password,
                'passphrase' => $passphrase,
                'entropy_bits' => $diceware['entropy_bits'] ?? null,
                'recovery_codes' => $recoveryCodes,
            ],
            'note' => 'Copie ton mot de passe, ta passphrase ET tes codes de secours maintenant — ils ne seront plus jamais affichés. Ton mot de récupération, tu le connais déjà.',
        ];
    }

    /**
     * Login par password. Retourne un array clair :
     *   ['ok'=>true,  'token'=>...]
     *   ['ok'=>false, 'status'=>'locked'|'wrong', 'remaining'=>int, 'message'=>...]
     */
    public static function login(PDO $pdo, string $username, string $password, ?string $ip = null): array
    {
        self::purgeExpiredSessions($pdo);
        $username = strtolower(trim($username));

        // Échecs récents par USERNAME (anti-bruteforce ciblé)
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE username = ? AND success = 0 AND attempted_at > ?'
        );
        $stmt->execute([$username, time() - self::LOGIN_WINDOW]);
        $fails = (int) $stmt->fetchColumn();

        // LAB-03 : échecs récents par IP (anti-spraying — un même mot de passe testé sur
        // de nombreux comptes ne dépasse jamais le seuil par username, mais sature le seuil IP).
        $failsIp = 0;
        if ($ip !== null) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at > ?'
            );
            $stmt->execute([$ip, time() - self::LOGIN_WINDOW]);
            $failsIp = (int) $stmt->fetchColumn();
        }

        // Déjà bloqué (compte OU IP)
        if ($fails >= self::LOGIN_MAX_FAILS || $failsIp >= self::LOGIN_MAX_FAILS_PER_IP) {
            return ['ok' => false, 'status' => 'locked',
                    'message' => 'Trop de tentatives. Réessaie dans 15 minutes.'];
        }

        $stmt = $pdo->prepare('SELECT id, pw_hash FROM accounts WHERE username = ?');
        $stmt->execute([$username]);
        $acc = $stmt->fetch();
        // LAB-02 : toujours exécuter un Argon2id (hash factice si le compte n'existe pas) pour que
        // le temps de réponse ne trahisse pas l'existence du compte.
        $ok = password_verify($password, $acc ? $acc['pw_hash'] : self::DUMMY_HASH) && (bool) $acc;

        $pdo->prepare(
            'INSERT INTO login_attempts (username, success, ip, attempted_at) VALUES (?, ?, ?, ?)'
        )->execute([$username, $ok ? 1 : 0, $ip, time()]);

        if (!$ok) {
            // LAB-07 : message générique, sans compteur de tentatives restantes.
            return ['ok' => false, 'status' => 'wrong',
                    'message' => 'Identifiant ou mot de passe incorrect.'];
        }

        // R9-06 : migration douce des anciens hash bcrypt → Argon2id à la connexion réussie.
        if (password_needs_rehash($acc['pw_hash'], PASSWORD_ARGON2ID, self::ARGON2)) {
            $pdo->prepare('UPDATE accounts SET pw_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_ARGON2ID, self::ARGON2), (int) $acc['id']]);
        }

        // Trace la dernière connexion + le compteur (signaux contextuels pour la récup L3).
        $pdo->prepare('UPDATE accounts SET last_login_at = ?, login_count = login_count + 1 WHERE id = ?')
            ->execute([time(), (int) $acc['id']]);

        $token = self::generateSessionToken();
        $pdo->prepare(
            'INSERT INTO app_sessions (account_id, token, created_at) VALUES (?, ?, ?)'
        )->execute([(int) $acc['id'], $token, time()]);

        return ['ok' => true, 'token' => $token];
    }

    /**
     * Récupération SANS email (protocole SelfRecover). username + mot de récupération
     * → on recalcule la clé dérivée et on la vérifie contre recovery_hash. Si OK :
     * régénère password + passphrase, invalide les sessions existantes.
     * Retour : ['ok'=>true,'credentials'=>[...]] ou ['ok'=>false,'message'=>...].
     */
    public static function recover(PDO $pdo, string $username, string $recoveryDerivedKey, ?string $ip = null): array
    {
        self::purgeExpiredSessions($pdo);
        $username = strtolower(trim($username));
        $marker = 'recover:' . $username;
        if (!preg_match('/^[a-f0-9]{64}$/', $recoveryDerivedKey)) {
            return ['ok' => false, 'message' => 'Identifiant ou mot de récupération incorrect.'];
        }

        // Rate-limit dédié récupération : par username ET par IP (anti-spraying / anti-énumération).
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND attempted_at > ?'
        );
        $stmt->execute([$marker, time() - self::LOGIN_WINDOW]);
        $failsUser = (int) $stmt->fetchColumn();
        $failsIp = 0;
        if ($ip !== null) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at > ?'
            );
            $stmt->execute([$ip, time() - self::LOGIN_WINDOW]);
            $failsIp = (int) $stmt->fetchColumn();
        }
        if ($failsUser >= self::LOGIN_MAX_FAILS || $failsIp >= self::LOGIN_MAX_FAILS_PER_IP) {
            return ['ok' => false, 'message' => 'Trop de tentatives de récupération. Réessaie dans 15 minutes.'];
        }

        $stmt = $pdo->prepare('SELECT id, recovery_hash FROM accounts WHERE username = ?');
        $stmt->execute([$username]);
        $acc = $stmt->fetch();

        // Le mot mémorisé est déjà dérivé côté client → on vérifie directement contre recovery_hash.
        // LAB-02 : Argon2id systématique (hash factice si compte absent) pour égaliser le timing.
        $ok = password_verify($recoveryDerivedKey, $acc ? $acc['recovery_hash'] : self::DUMMY_HASH) && (bool) $acc;

        $pdo->prepare('INSERT INTO login_attempts (username, success, ip, attempted_at) VALUES (?, ?, ?, ?)')
            ->execute([$marker, $ok ? 1 : 0, $ip, time()]);

        if (!$ok) {
            // message non-discriminant (anti-énumération)
            return ['ok' => false, 'message' => 'Identifiant ou mot de récupération incorrect.'];
        }

        $newPassword = self::generatePassword(16);
        $diceware = \DicewareWordlist::generate(4, 'en');
        $newPassphrase = implode(' ', $diceware['words']);

        $pdo->prepare('UPDATE accounts SET pw_hash = ?, pass_hash = ? WHERE id = ?')->execute([
            password_hash($newPassword, PASSWORD_ARGON2ID, self::ARGON2),
            password_hash($newPassphrase, PASSWORD_ARGON2ID, self::ARGON2),
            (int) $acc['id'],
        ]);
        // sécurité : on coupe toutes les sessions ouvertes du compte
        $pdo->prepare('DELETE FROM app_sessions WHERE account_id = ?')->execute([(int) $acc['id']]);

        return [
            'ok' => true,
            'credentials' => ['password' => $newPassword, 'passphrase' => $newPassphrase],
            'note' => 'Nouveau mot de passe généré — copie-le maintenant. ⚠️ Si tu avais un mémo chiffré E2E : déverrouille-le avec ta PASSPHRASE de secours, puis recrée le coffre (il était chiffré avec l\'ancien mot de passe).',
        ];
    }

    /**
     * Échecs récents à un niveau de récupération donné, scopés username + IP.
     * Scope conjoint (R10-SR-04) : un tiers depuis une autre IP ne peut PAS verrouiller
     * l'escalade du titulaire légitime — chaque IP a son propre compteur pour ce compte.
     */
    private static function recentRecoverFails(PDO $pdo, string $username, int $level, ?string $ip): int
    {
        $sql = 'SELECT COUNT(*) FROM login_attempts
                WHERE username = ? AND level = ? AND success = 0 AND attempted_at > ?';
        $params = [$username, $level, time() - self::LOGIN_WINDOW];
        if ($ip !== null) { $sql .= ' AND ip = ?'; $params[] = $ip; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * L1 — récupération par PASSPHRASE diceware (secours fort). username + passphrase →
     * vérif contre pass_hash. Succès : nouveau mot de passe (la passphrase reste valable).
     * Après RECOVER_ESCALATE échecs (scope username+IP) → propose le niveau L2.
     */
    public static function recoverL1(PDO $pdo, string $username, string $passphrase, ?string $ip = null): array
    {
        self::purgeExpiredSessions($pdo);
        $username = strtolower(trim($username));

        if (self::recentRecoverFails($pdo, $username, 1, $ip) >= self::RECOVER_ESCALATE) {
            return ['ok' => false, 'escalate' => 'l2',
                    'message' => 'Trop de tentatives. Utilise ta récupération par mot mémorisé + code.'];
        }

        $stmt = $pdo->prepare('SELECT id, pass_hash FROM accounts WHERE username = ?');
        $stmt->execute([$username]);
        $acc = $stmt->fetch();
        // Argon2id systématique (hash factice si compte absent) : timing plat anti-énumération.
        $ok = password_verify($passphrase, $acc ? $acc['pass_hash'] : self::DUMMY_HASH) && (bool) $acc;

        $pdo->prepare('INSERT INTO login_attempts (username, success, level, ip, attempted_at) VALUES (?, ?, 1, ?, ?)')
            ->execute([$username, $ok ? 1 : 0, $ip, time()]);

        if (!$ok) {
            $resp = ['ok' => false, 'message' => 'Identifiant ou passphrase incorrect.'];
            if (self::recentRecoverFails($pdo, $username, 1, $ip) >= self::RECOVER_ESCALATE) {
                $resp['escalate'] = 'l2';
            }
            return $resp;
        }

        // Succès : nouveau mot de passe (on ne régénère PAS la passphrase ni le mot mémorisé).
        $newPassword = self::generatePassword(16);
        $pdo->prepare('UPDATE accounts SET pw_hash = ? WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_ARGON2ID, self::ARGON2), (int) $acc['id']]);
        $pdo->prepare('DELETE FROM app_sessions WHERE account_id = ?')->execute([(int) $acc['id']]);

        return ['ok' => true, 'credentials' => ['password' => $newPassword],
                'note' => 'Nouveau mot de passe généré — copie-le maintenant.'];
    }

    /** Un recovery code lisible : xxxxx-xxxxx (alphabet sans caractères ambigus). */
    private static function formatCode(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789'; // sans i,l,o,0,1
        $max = strlen($alphabet) - 1;
        $s = '';
        for ($i = 0; $i < 10; $i++) {
            if ($i === 5) { $s .= '-'; }
            $s .= $alphabet[random_int(0, $max)];
        }
        return $s;
    }

    /** Échecs récents à un niveau, scopés IP seule (L2 : le code localise le compte, pas de username). */
    private static function recentFailsByIpLevel(PDO $pdo, int $level, ?string $ip): int
    {
        if ($ip === null) { return 0; }
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND level = ? AND success = 0 AND attempted_at > ?'
        );
        $stmt->execute([$ip, $level, time() - self::LOGIN_WINDOW]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Génère (ou régénère) le lot de recovery codes d'un compte — affichés UNE fois.
     * Stockage : code_lookup = HMAC(pepper, code) (recherche O(1) sans identifiant, non
     * réversible) + code_hash = Argon2id(code) (vérif + résistance fuite DB). Usage unique.
     */
    public static function generateRecoveryCodes(PDO $pdo, int $accountId): array
    {
        $pdo->prepare('DELETE FROM recovery_codes WHERE account_id = ?')->execute([$accountId]);
        $pepper = self::serverSecret();
        $ins = $pdo->prepare(
            'INSERT INTO recovery_codes (account_id, code_lookup, code_hash, created_at) VALUES (?, ?, ?, ?)'
        );
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $code = self::formatCode();
            $ins->execute([
                $accountId,
                hash_hmac('sha256', $code, $pepper),
                password_hash($code, PASSWORD_ARGON2ID, self::ARGON2),
                time(),
            ]);
            $codes[] = $code;
        }
        return $codes;
    }

    /**
     * L2 — 2FA SANS identifiant : recovery code (POSSESSION) + mot mémorisé (CONNAISSANCE).
     * Le code localise le compte par lookup HMAC (O(1)). On vérifie les DEUX facteurs ;
     * l'erreur est générique (ne révèle jamais lequel a échoué). Succès : code consommé
     * (usage unique) + nouveau mot de passe. ≥3 échecs L2/IP → propose L3.
     */
    public static function recoverL2Code(PDO $pdo, string $code, string $memorizedDerived, ?string $ip = null): array
    {
        self::purgeExpiredSessions($pdo);
        $code = strtolower(trim($code));

        if (self::recentFailsByIpLevel($pdo, 2, $ip) >= self::RECOVER_ESCALATE) {
            return ['ok' => false, 'escalate' => 'l3',
                    'message' => 'Trop de tentatives. Passe par la récupération assistée.'];
        }

        $formatOk = (bool) preg_match('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $code)
                 && (bool) preg_match('/^[a-f0-9]{64}$/', $memorizedDerived);

        $row = null;
        if ($formatOk) {
            $stmt = $pdo->prepare(
                'SELECT rc.id, rc.account_id, rc.code_hash, a.recovery_hash
                   FROM recovery_codes rc JOIN accounts a ON a.id = rc.account_id
                  WHERE rc.code_lookup = ? AND rc.used = 0'
            );
            $stmt->execute([hash_hmac('sha256', $code, self::serverSecret())]);
            $row = $stmt->fetch() ?: null;
        }

        // Timing plat : on exécute TOUJOURS deux Argon2id (facteurs factices si rien trouvé).
        $codeOk = password_verify($code, $row ? $row['code_hash'] : self::DUMMY_HASH);
        $wordOk = password_verify($memorizedDerived, $row ? $row['recovery_hash'] : self::DUMMY_HASH);
        $ok = $row !== null && $codeOk && $wordOk;

        // On ne loggue jamais le username en L2 (le code ne le divulgue pas) : marqueur générique.
        $pdo->prepare('INSERT INTO login_attempts (username, success, level, ip, attempted_at) VALUES (?, ?, 2, ?, ?)')
            ->execute(['__l2__', $ok ? 1 : 0, $ip, time()]);

        if (!$ok) {
            $resp = ['ok' => false, 'message' => 'Code ou mot mémorisé incorrect.']; // générique : jamais lequel
            if (self::recentFailsByIpLevel($pdo, 2, $ip) >= self::RECOVER_ESCALATE) {
                $resp['escalate'] = 'l3';
            }
            return $resp;
        }

        // Succès : le code est consommé (usage unique), nouveau mot de passe, sessions coupées.
        $pdo->prepare('UPDATE recovery_codes SET used = 1, used_at = ? WHERE id = ?')
            ->execute([time(), (int) $row['id']]);
        $newPassword = self::generatePassword(16);
        $pdo->prepare('UPDATE accounts SET pw_hash = ? WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_ARGON2ID, self::ARGON2), (int) $row['account_id']]);
        $pdo->prepare('DELETE FROM app_sessions WHERE account_id = ?')->execute([(int) $row['account_id']]);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM recovery_codes WHERE account_id = ? AND used = 0');
        $stmt->execute([(int) $row['account_id']]);
        $remaining = (int) $stmt->fetchColumn();

        $resp = ['ok' => true, 'credentials' => ['password' => $newPassword], 'codes_remaining' => $remaining,
                 'note' => 'Nouveau mot de passe généré — copie-le maintenant.'];
        if ($remaining <= 2) {
            $resp['low_codes_warning'] = "Il te reste $remaining code(s) de secours. Pense à en régénérer un lot.";
        }
        return $resp;
    }

    /**
     * Régénère le lot de recovery codes d'un compte AUTHENTIFIÉ par son mot mémorisé
     * (connaissance). Self-service : pas besoin de mot de passe. Retourne les nouveaux codes.
     */
    public static function regenerateCodes(PDO $pdo, string $username, string $memorizedDerived, ?string $ip = null): array
    {
        $username = strtolower(trim($username));
        $marker = 'regen:' . $username;

        // Rate-limit dédié (username+IP) pour ne pas laisser bruteforcer le mot mémorisé ici.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND attempted_at > ?');
        $stmt->execute([$marker, time() - self::LOGIN_WINDOW]);
        if ((int) $stmt->fetchColumn() >= self::LOGIN_MAX_FAILS) {
            return ['ok' => false, 'message' => 'Trop de tentatives. Réessaie dans 15 minutes.'];
        }

        $stmt = $pdo->prepare('SELECT id, recovery_hash FROM accounts WHERE username = ?');
        $stmt->execute([$username]);
        $acc = $stmt->fetch();
        $ok = password_verify($memorizedDerived, $acc ? $acc['recovery_hash'] : self::DUMMY_HASH) && (bool) $acc;

        $pdo->prepare('INSERT INTO login_attempts (username, success, level, ip, attempted_at) VALUES (?, ?, 2, ?, ?)')
            ->execute([$marker, $ok ? 1 : 0, $ip, time()]);

        if (!$ok) {
            return ['ok' => false, 'message' => 'Identifiant ou mot mémorisé incorrect.'];
        }
        $codes = self::generateRecoveryCodes($pdo, (int) $acc['id']);
        return ['ok' => true, 'codes' => $codes,
                'note' => 'Nouveaux codes de secours — copie-les maintenant, les anciens sont invalidés.'];
    }

    public static function logout(PDO $pdo, string $token): void
    {
        $pdo->prepare('DELETE FROM app_sessions WHERE token = ?')->execute([$token]);
    }

    /** Compte courant depuis le cookie de session (avec TTL), ou null. */
    public static function currentAccount(PDO $pdo): ?array
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT a.id, a.username, a.is_admin, s.created_at AS session_started
               FROM accounts a
               JOIN app_sessions s ON s.account_id = a.id
              WHERE s.token = ?'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        // TTL : session expirée → suppression + null
        if (time() - (int) $row['session_started'] > self::SESSION_TTL) {
            $pdo->prepare('DELETE FROM app_sessions WHERE token = ?')->execute([$token]);
            return null;
        }
        return ['id' => $row['id'], 'username' => $row['username'], 'is_admin' => (int) $row['is_admin']];
    }

    /** Purge les sessions expirées (appelé opportunément au login). */
    public static function purgeExpiredSessions(PDO $pdo): void
    {
        $pdo->prepare('DELETE FROM app_sessions WHERE created_at < ?')
            ->execute([time() - self::SESSION_TTL]);
    }

    public static function setSessionCookie(string $token, int $lifetime = 86400): void
    {
        setcookie(self::COOKIE, $token, [
            'expires' => time() + $lifetime,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function clearSessionCookie(): void
    {
        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600, 'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    public static function cookieName(): string
    {
        return self::COOKIE;
    }
}
