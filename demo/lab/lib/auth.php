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

use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Device\Device as Protocole;
use Pierroons\SelfRecover\Recovery\Recovery;

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
    /**
     * Hash Argon2id factice (R9-06), exécuté quand le compte n'existe pas, pour que
     * password_verify prenne le même temps qu'avec un vrai compte (anti-énumération
     * par timing — DOIT être du même algo que les vrais hash). Aucun mot de passe réel.
     */

    /**
     * Options Argon2id, exposées pour les modules qui reposent un mot de passe
     * hors de cette classe (Device notamment).
     *
     * 🔑 Une seule source de vérité : un module qui recopierait ces valeurs
     * finirait par diverger silencieusement le jour où on durcit le profil,
     * et produirait des hash plus faibles que les autres sans que rien
     * ne le signale.
     */
    /**
     * Le hash factice du protocole.
     *
     * 🔑 Sa valeur et le profil qu'il porte vivent dans
     * `Pierroons\\SelfRecover\\Crypto\\Hashing`. Les recopier ici les ferait
     * diverger au premier ajustement, sans qu'aucune erreur ne survienne :
     * c'est ce qui a coûté soixante-dix jours au constat du 17/08/2026.
     */
    public static function dummyHash(): string
    {
        return Hashing::dummyHash();
    }

    /** Options Argon2id du projet — une seule définition, dans la bibliothèque. */
    public static function argon2Options(): array
    {
        return Hashing::argon2Options();
    }

    /** Le mot mémorisé doit arriver dérivé — cf. la bibliothèque. */
    public static function isDerivedKey(string $value): bool
    {
        return Protocole::estCleDerivee($value);
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

    /** Mot de passe temporaire rendu après une récupération. */
    public static function generatePassword(int $length = 16): string
    {
        return Protocole::engendrerMotDePasse($length);
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

        $pwHash       = Hashing::hash($password);
        $passHash     = Hashing::hash($passphrase);
        $recoveryHash = Hashing::hash($derivedKey);

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
        $ok = password_verify($password, $acc ? $acc['pw_hash'] : Hashing::dummyHash()) && (bool) $acc;

        $pdo->prepare(
            'INSERT INTO login_attempts (username, success, ip, attempted_at) VALUES (?, ?, ?, ?)'
        )->execute([$username, $ok ? 1 : 0, $ip, time()]);

        if (!$ok) {
            // LAB-07 : message générique, sans compteur de tentatives restantes.
            return ['ok' => false, 'status' => 'wrong',
                    'message' => 'Identifiant ou mot de passe incorrect.'];
        }

        // R9-06 : réencodage à la connexion réussie — un hash produit avec des paramètres
        // Argon2id périmés est refait avec le profil courant, sans que l'utilisateur agisse.
        if (password_needs_rehash($acc['pw_hash'], PASSWORD_ARGON2ID, Hashing::argon2Options())) {
            $pdo->prepare('UPDATE accounts SET pw_hash = ? WHERE id = ?')
                ->execute([Hashing::hash($password), (int) $acc['id']]);
        }

        $token = self::generateSessionToken();
        $pdo->prepare(
            'INSERT INTO app_sessions (account_id, token, created_at) VALUES (?, ?, ?)'
        )->execute([(int) $acc['id'], $token, time()]);

        return ['ok' => true, 'token' => $token];
    }

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
        return self::protocole($pdo)->emettreCodes($accountId, $n);
    }

    /**
     * Niveau 2 — code de récupération ET mot mémorisé, sans identifiant.
     *
     * Le protocole est dans `Pierroons\SelfRecover\Recovery`. Ne reste ici que
     * la forme du retour attendue par `public/recover.php`.
     */
    public static function recoverByCode(PDO $pdo, string $code, string $recoveryDerivedKey, ?string $ip = null): array
    {
        $r = self::protocole($pdo)->parCode($code, $recoveryDerivedKey, $ip);
        if (!$r['ok']) {
            return $r;
        }

        return [
            'ok'             => true,
            'username'       => $r['compte'],
            'credentials'    => ['password' => $r['mot_de_passe'], 'passphrase' => $r['passphrase']],
            'codes_restants' => $r['codes_restants'],
            'note'           => 'Code consommé — il ne resservira pas. Il te reste '
                                . $r['codes_restants'] . ' code(s) sur ce lot.',
        ];
    }

    public static function recoverByPassphrase(PDO $pdo, string $username, string $passphrase, ?string $ip = null): array
    {
        $r = self::protocole($pdo)->parPassphrase($username, $passphrase, $ip);
        if (!$r['ok']) {
            return $r;
        }

        return [
            'ok'          => true,
            'credentials' => ['password' => $r['mot_de_passe'], 'passphrase' => $r['passphrase']],
            'note'        => 'Nouveau mot de passe et nouvelle passphrase générés — copie-les maintenant. '
                             . 'Ton mot de récupération, lui, reste inchangé.',
        ];
    }

    /**
     * La bibliothèque, montée sur le schéma du lab.
     *
     * ⚠️ Le sel de déploiement localise les codes émis : le changer les rendrait
     * tous introuvables, puisqu'ils ne sont retrouvés que par leur HMAC.
     */
    private static function protocole(PDO $pdo): Recovery
    {
        return new Recovery(
            new StockageSelfRecover($pdo),
            self::siteSalt(),
            maxEchecsCompte: self::LOGIN_MAX_FAILS,
            maxEchecsIp: self::LOGIN_MAX_FAILS_PER_IP,
        );
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
