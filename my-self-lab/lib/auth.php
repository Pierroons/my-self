<?php
/**
 * MySelf-Lab — couche auth (reprise du protocole SelfRecover).
 *
 * Modèle : compte sans email. À l'inscription, l'utilisateur choisit son
 * recovery word. Le serveur génère un password (16 chars) + une passphrase
 * diceware EFF (L1). On dérive recovery_key = HMAC(recovery_word, domain||salt)
 * et on stocke bcrypt(password), bcrypt(passphrase), bcrypt(recovery_key).
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
    /**
     * Hash bcrypt cost 12 factice, exécuté quand le compte n'existe pas, pour que
     * password_verify prenne le même temps qu'avec un vrai compte (anti-énumération
     * par timing). Ne correspond à aucun mot de passe réel.
     */
    private const DUMMY_BCRYPT = '$2y$12$U8kdsw/Okk6pxMpEUl2LVeWR3cBuwvkT2YX4teyvvvttXY93ubAb2';

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
    public static function register(PDO $pdo, string $username, string $recoveryWord, ?string $ip = null): array
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
        if (mb_strlen($recoveryWord) < 4) {
            return ['ok' => false, 'error' => 'weak_recovery',
                    'message' => 'Le mot de récupération doit faire au moins 4 caractères.'];
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

        $derivedKey = self::deriveKey($recoveryWord, self::DOMAIN, self::siteSalt());

        $pwHash       = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $passHash     = password_hash($passphrase, PASSWORD_BCRYPT, ['cost' => 12]);
        $recoveryHash = password_hash($derivedKey, PASSWORD_BCRYPT, ['cost' => 12]);

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

        return [
            'ok' => true,
            'account_id' => $accountId,
            'username' => $username,
            'credentials' => [
                'password' => $password,
                'passphrase' => $passphrase,
                'entropy_bits' => $diceware['entropy_bits'] ?? null,
            ],
            'note' => 'Copie ton mot de passe et ta passphrase maintenant — ils ne seront plus jamais affichés. Ton mot de récupération, tu le connais déjà.',
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
        // LAB-02 : toujours exécuter un bcrypt (hash factice si le compte n'existe pas) pour que
        // le temps de réponse ne trahisse pas l'existence du compte.
        $ok = password_verify($password, $acc ? $acc['pw_hash'] : self::DUMMY_BCRYPT) && (bool) $acc;

        $pdo->prepare(
            'INSERT INTO login_attempts (username, success, ip, attempted_at) VALUES (?, ?, ?, ?)'
        )->execute([$username, $ok ? 1 : 0, $ip, time()]);

        if (!$ok) {
            // LAB-07 : message générique, sans compteur de tentatives restantes.
            return ['ok' => false, 'status' => 'wrong',
                    'message' => 'Identifiant ou mot de passe incorrect.'];
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
    public static function recover(PDO $pdo, string $username, string $recoveryWord, ?string $ip = null): array
    {
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

        $derived = self::deriveKey($recoveryWord, self::DOMAIN, self::siteSalt());
        // LAB-02 : bcrypt systématique (hash factice si compte absent) pour égaliser le timing.
        $ok = password_verify($derived, $acc ? $acc['recovery_hash'] : self::DUMMY_BCRYPT) && (bool) $acc;

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
            password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
            password_hash($newPassphrase, PASSWORD_BCRYPT, ['cost' => 12]),
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
