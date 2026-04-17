<?php
/**
 * SelfRecover demo — helpers (session app, password gen, etc.).
 */

declare(strict_types=1);

require_once __DIR__ . '/session_manager.php';
require_once __DIR__ . '/diceware/wordlist.php';

final class RecoverHelper {
    /**
     * Génère un password random (16 chars alphanum + quelques symboles mémorisables).
     */
    public static function generatePassword(int $length = 16): string {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!?@#$';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * Calcule derived_key = HMAC-SHA256(recovery_word, domain || site_salt).
     *
     * Dans le vrai protocole SelfRecover le HMAC est calculé côté client (JS).
     * Pour la démo on le fait aussi côté serveur pour la simulation du flux
     * inscription (où le serveur génère tout pour pédagogie). Pour la récupération
     * L2 (session 4), le frontend calculera le HMAC lui-même et n'enverra que le
     * derived_key au serveur, comme dans le vrai protocole.
     */
    public static function deriveKey(string $recoveryWord, string $domain, string $siteSalt): string {
        return hash_hmac('sha256', $recoveryWord, $domain . $siteSalt);
    }

    /**
     * Site salt de la session démo. Pour la prod, 32 bytes random persistants.
     * Ici, dérivé de l'UUID de session pour qu'il soit stable dans la durée
     * de la session et différent entre sessions (montre que chaque déploiement
     * aurait son propre salt).
     */
    public static function siteSalt(DemoSession $session): string {
        return hash('sha256', 'demo-salt|' . $session->id);
    }

    public static function generateSessionToken(): string {
        return bin2hex(random_bytes(24));
    }

    public static function getLoggedAccount(DemoSession $session): ?array {
        $token = $_COOKIE['sr_app_session'] ?? '';
        if ($token === '' || !preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $db = $session->db();
        $stmt = $db->prepare('
            SELECT a.id, a.username
              FROM accounts a
              JOIN app_sessions s ON s.account_id = a.id
             WHERE s.token = :token
        ');
        $stmt->bindValue(':token', $token);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function setAppSessionCookie(string $token, int $lifetimeSeconds = 1800): void {
        setcookie('sr_app_session', $token, [
            'expires'  => time() + $lifetimeSeconds,
            'path'     => '/',
            'domain'   => 'bi-self.my-self.fr',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function clearAppSessionCookie(): void {
        setcookie('sr_app_session', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => 'bi-self.my-self.fr',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
