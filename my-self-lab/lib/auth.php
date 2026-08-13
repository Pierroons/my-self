<?php
/**
 * MySelf-Lab — couche auth (reprise du protocole SelfRecover).
 *
 * Modèle : compte sans email. À l'inscription, l'utilisateur choisit son
 * recovery word. Le serveur génère un password (16 chars) + une passphrase
 * diceware EFF (L1). La clé de récupération est dérivée DANS LE NAVIGATEUR
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
    public const DOMAIN = 'lab.my-self.fr';
    private const COOKIE = 'lab_session';
    private const SESSION_TTL = 86400;        // 24h
    private const REGISTER_MAX_PER_IP = 5;    // max comptes créés / IP / heure
    private const LOGIN_MAX_FAILS = 5;        // échecs / username avant blocage temporaire
    private const LOGIN_MAX_FAILS_PER_IP = 12; // échecs cumulés / IP / fenêtre (anti-spraying, tolère un foyer NAT)
    private const LOGIN_WINDOW = 900;         // fenêtre de comptage (15 min)
    /** Options Argon2id (R9-06, alignées sur le profil OWASP de SelfRecover). */
    private const ARGON2 = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2];
    /**
     * Hash Argon2id factice (R9-06), exécuté quand le compte n'existe pas, pour que
     * password_verify prenne le même temps qu'avec un vrai compte (anti-énumération
     * par timing — DOIT être du même algo que les vrais hash). Aucun mot de passe réel.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=2$SXQ3V2s0SHVuaEZWR003bQ$FM4OpKIf6dEQsf0BMOE6uFqG+OyWDcTRR6+tUoKpOWA';

    /**
     * Options Argon2id, exposées pour les modules qui reposent un mot de passe
     * hors de cette classe (Device notamment).
     *
     * 🔑 Une seule source de vérité : un module qui recopierait ces valeurs
     * finirait par diverger silencieusement le jour où on durcit le profil,
     * et produirait des hash plus faibles que les autres sans que rien
     * ne le signale.
     */
    public static function dummyHash(): string
    {
        return self::DUMMY_HASH;
    }

    public static function argon2Options(): array
    {
        return self::ARGON2;
    }

    /**
     * Valide une clé de récupération dérivée par le navigateur.
     *
     * 🔑 **Le mot mémorisé n'arrive jamais ici.** Le client calcule
     * `HMAC-SHA256(clé = mot, message = label de service)` et n'envoie que le
     * résultat : 64 caractères hexadécimaux. Le serveur en stocke un Argon2id
     * et ne peut donc reconstituer ni le mot, ni ce qu'il ouvrirait ailleurs.
     *
     * C'est la promesse centrale du protocole — la tenir suppose de refuser
     * toute entrée qui n'a pas la forme d'une clé dérivée. Sans ce contrôle, un
     * client resté sur l'ancienne version enverrait le mot en clair et le
     * serveur l'accepterait sans que rien ne le signale.
     */
    public static function isDerivedKey(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/', $value);
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
        // La longueur du mot est vérifiée par le navigateur, seul à le voir.
        // Ici on ne peut contrôler que la forme de la clé dérivée.
        if (!self::isDerivedKey($recoveryDerivedKey)) {
            return ['ok' => false, 'error' => 'invalid_derived_key',
                    'message' => 'Clé de récupération invalide : la dérivation doit se faire dans le navigateur.'];
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

        $derivedKey = $recoveryDerivedKey;   // déjà dérivée côté client

        $pwHash       = password_hash($password, PASSWORD_ARGON2ID, self::ARGON2);
        $passHash     = password_hash($passphrase, PASSWORD_ARGON2ID, self::ARGON2);
        $recoveryHash = password_hash($derivedKey, PASSWORD_ARGON2ID, self::ARGON2);

        $stmt = $pdo->prepare(
            'INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$username, $pwHash, $passHash, $recoveryHash, time()]);
        $accountId = (int) $pdo->lastInsertId();

        // Trace la création pour le rate-limit IP
        if ($ip !== null) {
            $pdo->prepare('INSERT INTO login_attempts (username, success, ip, attempted_at) VALUES (?, 1, ?, ?)')
                ->execute(['__register__', $ip, time()]);
        }

        // Le lot de codes de récupération accompagne l'inscription : c'est le
        // facteur de possession du niveau 2, inutile de le demander plus tard.
        $codes = self::generateRecoveryCodes($pdo, $accountId);

        // 🔑 Une session s'ouvre avec le compte. L'enrôlement d'appareil qui
        // suit l'inscription l'exige désormais : sans elle, il faudrait
        // rouvrir un chemin où l'on enrôle sur un compte qu'on ne prouve pas
        // posséder — celui qui a permis une prise de compte le 13/08/2026.
        $sessionToken = self::generateSessionToken();
        $pdo->prepare(
            'INSERT INTO app_sessions (account_id, token, created_at) VALUES (?, ?, ?)'
        )->execute([(int) $accountId, $sessionToken, time()]);

        return [
            'ok' => true,
            'account_id' => $accountId,
            'username' => $username,
            'token' => $sessionToken,
            // Les codes sont des secrets remis une fois, au même titre que le
            // mot de passe et la passphrase : ils appartiennent à `credentials`.
            // Les laisser à la racine les rendait invisibles au client, qui lit
            // credentials — le lot était donc généré puis perdu aussitôt.
            'credentials' => [
                'password' => $password,
                'passphrase' => $passphrase,
                'entropy_bits' => $diceware['entropy_bits'] ?? null,
                'recovery_codes' => $codes,
            ],
            'note' => 'Copie ton mot de passe, ta passphrase et tes codes maintenant — ils ne seront plus jamais affichés. Ton mot de récupération, tu le connais déjà.',
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
        // Refus explicite d'une entrée qui n'a pas la forme d'une clé dérivée :
        // sans ce contrôle, un client resté sur l'ancienne version enverrait le
        // mot mémorisé en clair et le serveur le recevrait sans rien signaler.
        if (!self::isDerivedKey($recoveryDerivedKey)) {
            return ['ok' => false, 'error' => 'invalid_derived_key',
                    'message' => 'Clé de récupération invalide : la dérivation doit se faire dans le navigateur.'];
        }

        self::purgeExpiredSessions($pdo);
        $username = strtolower(trim($username));
        $marker = 'recover:' . $username;

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

        $derived = $recoveryDerivedKey;   // déjà dérivée côté client
        // LAB-02 : Argon2id systématique (hash factice si compte absent) pour égaliser le timing.
        $ok = password_verify($derived, $acc ? $acc['recovery_hash'] : self::DUMMY_HASH) && (bool) $acc;

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
     * Récupération par PASSPHRASE (niveau L1 du protocole SelfRecover).
     *
     * La passphrase diceware est générée à l'inscription et stockée en
     * `pass_hash` — elle l'était déjà, mais aucun chemin ne s'en servait :
     * seul le mot de récupération (L2) permettait de reprendre la main. Un
     * utilisateur ayant perdu son mot mémorisé mais conservé sa passphrase
     * restait dehors, alors même que le serveur détenait de quoi le vérifier.
     *
     * Différence de nature avec L2, et c'est ce qui justifie deux chemins :
     * la passphrase est un secret **généré** (51,7 bits, à conserver), le mot
     * de récupération un secret **choisi** et mémorisé, potentiellement faible.
     * Le second dépend donc entièrement du rate-limit ; le premier tient par
     * son entropie.
     *
     * Aucune dérivation ici : contrairement au mot de récupération, la
     * passphrase ne transite pas par HMAC côté client — elle est vérifiée
     * telle quelle contre son Argon2id.
     */
    /** Nombre de codes remis à l'inscription. */
    private const RECOVERY_CODES = 10;

    /**
     * Génère un lot de codes de récupération — le facteur de POSSESSION du L2.
     *
     * Retourne les codes en clair : ils ne seront plus jamais affichés. Un lot
     * précédent est purgé, une régénération invalidant l'ancien papier.
     *
     * 40 bits par code (10 caractères hexadécimaux). C'est peu face à une
     * attaque hors ligne, mais ce code ne vit jamais seul : il faut AUSSI le
     * mot mémorisé, et le rate-limit s'applique. Sa fonction est d'être
     * imprimable et transportable, pas d'être un secret maximal.
     */
    public static function generateRecoveryCodes(PDO $pdo, int $accountId, int $n = self::RECOVERY_CODES): array
    {
        $pdo->prepare('DELETE FROM recovery_codes WHERE account_id = ?')->execute([$accountId]);
        $ins = $pdo->prepare(
            'INSERT INTO recovery_codes (account_id, code_lookup, code_hash, created_at) VALUES (?, ?, ?, ?)'
        );
        $codes = [];
        for ($i = 0; $i < $n; $i++) {
            $brut = bin2hex(random_bytes(5));
            $code = substr($brut, 0, 5) . '-' . substr($brut, 5, 5);
            $ins->execute([
                $accountId,
                hash_hmac('sha256', $code, self::siteSalt()),
                password_hash($code, PASSWORD_ARGON2ID, self::ARGON2),
                time(),
            ]);
            $codes[] = $code;
        }
        return $codes;
    }

    /**
     * Récupération de niveau 2 conforme : code de récupération ET mot mémorisé.
     *
     * 🔑 **Aucun identifiant n'est demandé.** Le code retrouve le compte par son
     * lookup HMAC — d'où la disparition de toute énumération : il n'y a plus de
     * champ où tester si un compte existe.
     *
     * Deux facteurs de nature différente : le code est une **possession**
     * (imprimable, transportable, à usage unique), le mot une **connaissance**
     * (mémorisé, potentiellement faible). Voler le papier ne suffit pas ;
     * connaître le mot non plus.
     *
     * L'erreur ne dit jamais lequel des deux a échoué — le préciser rendrait
     * chaque facteur attaquable séparément, ce qui annulerait le bénéfice.
     */
    public static function recoverByCode(PDO $pdo, string $code, string $recoveryDerivedKey, ?string $ip = null): array
    {
        // Refus explicite d'une entrée qui n'a pas la forme d'une clé dérivée :
        // sans ce contrôle, un client resté sur l'ancienne version enverrait le
        // mot mémorisé en clair et le serveur le recevrait sans rien signaler.
        if (!self::isDerivedKey($recoveryDerivedKey)) {
            return ['ok' => false, 'error' => 'invalid_derived_key',
                    'message' => 'Clé de récupération invalide : la dérivation doit se faire dans le navigateur.'];
        }

        self::purgeExpiredSessions($pdo);
        $code = strtolower(trim($code));

        // Le compteur porte sur l'IP seule : sans identifiant, il n'y a pas de
        // compte à désigner tant que le code n'a pas été reconnu.
        if ($ip !== null) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at > ?'
            );
            $stmt->execute([$ip, time() - self::LOGIN_WINDOW]);
            if ((int) $stmt->fetchColumn() >= self::LOGIN_MAX_FAILS_PER_IP) {
                return ['ok' => false, 'message' => 'Trop de tentatives. Réessaie dans 15 minutes.', 'escalate_l3' => true];
            }
        }

        if (!preg_match('/^[a-f0-9]{5}-[a-f0-9]{5}$/', $code)) {
            return ['ok' => false, 'message' => 'Code de récupération ou mot mémorisé incorrect.'];
        }

        $stmt = $pdo->prepare(
            'SELECT c.id AS code_id, c.code_hash, c.used, a.id AS account_id, a.username, a.recovery_hash
               FROM recovery_codes c JOIN accounts a ON a.id = c.account_id
              WHERE c.code_lookup = ?'
        );
        $stmt->execute([hash_hmac('sha256', $code, self::siteSalt())]);
        $row = $stmt->fetch();

        $derived = $recoveryDerivedKey;   // déjà dérivée côté client
        // Argon2id systématiquement exécuté, même sur code inconnu : sinon le
        // temps de réponse dirait si le code existe.
        $codeOk = $row ? password_verify($code, $row['code_hash']) : password_verify('x', self::DUMMY_HASH);
        $motOk  = password_verify($derived, $row ? $row['recovery_hash'] : self::DUMMY_HASH);

        $ok = (bool) $row && !$row['used'] && $codeOk && $motOk;

        $pdo->prepare('INSERT INTO login_attempts (username, success, ip, attempted_at) VALUES (?, ?, ?, ?)')
            ->execute(['recovercode:' . ($row['username'] ?? 'inconnu'), $ok ? 1 : 0, $ip, time()]);

        if (!$ok) {
            usleep(300000); // délai constant : ne pas trahir l'étape qui a échoué
            return ['ok' => false, 'message' => 'Code de récupération ou mot mémorisé incorrect.'];
        }

        $newPassword = self::generatePassword(16);
        $diceware = \DicewareWordlist::generate(4, 'en');
        $newPassphrase = implode(' ', $diceware['words']);

        $pdo->prepare('UPDATE accounts SET pw_hash = ?, pass_hash = ? WHERE id = ?')->execute([
            password_hash($newPassword, PASSWORD_ARGON2ID, self::ARGON2),
            password_hash($newPassphrase, PASSWORD_ARGON2ID, self::ARGON2),
            (int) $row['account_id'],
        ]);
        // Usage unique : un code consommé ne peut plus resservir, même volé.
        $pdo->prepare('UPDATE recovery_codes SET used = 1, used_at = ? WHERE id = ?')
            ->execute([time(), (int) $row['code_id']]);
        $pdo->prepare('DELETE FROM app_sessions WHERE account_id = ?')->execute([(int) $row['account_id']]);

        $restants = (int) $pdo->query(
            'SELECT COUNT(*) FROM recovery_codes WHERE account_id = ' . (int) $row['account_id'] . ' AND used = 0'
        )->fetchColumn();

        return [
            'ok' => true,
            'username' => $row['username'],
            'credentials' => ['password' => $newPassword, 'passphrase' => $newPassphrase],
            'codes_restants' => $restants,
            'note' => 'Code consommé — il ne resservira pas. Il te reste ' . $restants . ' code(s) sur ce lot.',
        ];
    }

    public static function recoverByPassphrase(PDO $pdo, string $username, string $passphrase, ?string $ip = null): array
    {
        self::purgeExpiredSessions($pdo);
        $username = strtolower(trim($username));
        // Compteur distinct de celui de L2 : épuiser un chemin ne doit pas
        // fermer l'autre à un titulaire légitime.
        $marker = 'recoverpp:' . $username;

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

        $stmt = $pdo->prepare('SELECT id, pass_hash FROM accounts WHERE username = ?');
        $stmt->execute([$username]);
        $acc = $stmt->fetch();

        // Argon2id systématique, hash factice si le compte n'existe pas : sans
        // cela, le temps de réponse trahirait l'existence du compte.
        $passphrase = trim(preg_replace('/\s+/', ' ', $passphrase));
        $ok = password_verify($passphrase, $acc ? $acc['pass_hash'] : self::DUMMY_HASH) && (bool) $acc;

        $pdo->prepare('INSERT INTO login_attempts (username, success, ip, attempted_at) VALUES (?, ?, ?, ?)')
            ->execute([$marker, $ok ? 1 : 0, $ip, time()]);

        if (!$ok) {
            return ['ok' => false, 'message' => 'Identifiant ou passphrase incorrect.'];
        }

        $newPassword = self::generatePassword(16);
        $diceware = \DicewareWordlist::generate(4, 'en');
        $newPassphrase = implode(' ', $diceware['words']);

        $pdo->prepare('UPDATE accounts SET pw_hash = ?, pass_hash = ? WHERE id = ?')->execute([
            password_hash($newPassword, PASSWORD_ARGON2ID, self::ARGON2),
            password_hash($newPassphrase, PASSWORD_ARGON2ID, self::ARGON2),
            (int) $acc['id'],
        ]);
        $pdo->prepare('DELETE FROM app_sessions WHERE account_id = ?')->execute([(int) $acc['id']]);

        return [
            'ok' => true,
            'credentials' => ['password' => $newPassword, 'passphrase' => $newPassphrase],
            'note' => 'Nouveau mot de passe et nouvelle passphrase générés — copie-les maintenant. Ton mot de récupération, lui, reste inchangé.',
        ];
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
