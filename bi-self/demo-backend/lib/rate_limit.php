<?php
/**
 * Bi-Self Demo — Rate limiter & bans.
 *
 * Règles :
 *  - LAN (192.168.1.0/24) bypass systématique
 *  - Cookie sj_bypass avec token valide bypass systématique
 *  - Max 10 sessions concurrentes totales
 *  - Max 3 sessions par IP / heure = OK
 *  - 4ᵉ = warning jaune, 5ᵉ = rouge, 6ᵉ+ = blacklist 30 jours (log CrowdSec)
 *  - Max 50 actions par session, max 30 requêtes API / minute / session
 *
 * Stockage : fichiers texte dans /var/lib/selfjustice/demo-sessions/.counters/
 */

declare(strict_types=1);

final class RateLimit {
    private const COUNTERS_DIR    = '/var/lib/selfjustice/demo-sessions/.counters';
    private const BAN_LIST        = '/var/lib/selfjustice/demo-sessions/.banned_ips';
    private const CROWDSEC_ALERT  = '/var/log/selfjustice-demo-abuse.log';
    private const BYPASS_TOKEN    = '/var/lib/selfjustice/admin/bypass_token.txt';
    private const MAX_CONCURRENT  = 10;
    private const MAX_PER_IP_HOUR = 3;
    private const BAN_DURATION_S  = 30 * 86400; // 30 jours

    public static function clientIp(): string {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function isLan(string $ip): bool {
        // IPv4 LAN
        if (preg_match('/^192\.168\.1\./', $ip)) return true;
        if (preg_match('/^10\./', $ip)) return true;
        if (preg_match('/^127\./', $ip)) return true;
        // IPv6 loopback / link-local
        if ($ip === '::1') return true;
        if (str_starts_with($ip, 'fe80:')) return true;
        return false;
    }

    public static function hasBypassCookie(): bool {
        $provided = $_COOKIE['sj_bypass'] ?? '';
        if ($provided === '' || strlen($provided) < 16) return false;
        $stored = @file_get_contents(self::BYPASS_TOKEN);
        if ($stored === false) return false;
        return hash_equals(trim($stored), $provided);
    }

    public static function isBanned(string $ip): bool {
        if (!is_readable(self::BAN_LIST)) return false;
        $lines = @file(self::BAN_LIST, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $now = time();
        foreach ($lines as $line) {
            $parts = explode(' ', $line, 2);
            if (count($parts) !== 2) continue;
            [$banned_ip, $until] = $parts;
            if ($banned_ip === $ip && (int) $until > $now) {
                return true;
            }
        }
        return false;
    }

    /**
     * Vérifie si l'IP peut créer une nouvelle session.
     * Retourne : ['ok' => bool, 'reason' => string, 'warning_level' => 0|1|2, 'bypass' => bool]
     */
    public static function canCreateSession(): array {
        $ip = self::clientIp();

        // Bypass LAN / cookie — toujours OK
        if (self::isLan($ip) || self::hasBypassCookie()) {
            return ['ok' => true, 'reason' => 'bypass', 'warning_level' => 0, 'bypass' => true];
        }

        if (self::isBanned($ip)) {
            return ['ok' => false, 'reason' => 'banned', 'warning_level' => 0, 'bypass' => false];
        }

        // Sessions concurrentes globales
        @mkdir(self::COUNTERS_DIR, 0775, true);
        $concurrent = self::countActiveSessions();
        if ($concurrent >= self::MAX_CONCURRENT) {
            return ['ok' => false, 'reason' => 'capacity_full', 'warning_level' => 0, 'bypass' => false];
        }

        // Compteur par IP / heure glissante
        $count = self::countSessionsForIpLastHour($ip);
        if ($count >= 6) {
            self::banIp($ip);
            return ['ok' => false, 'reason' => 'abuse_banned', 'warning_level' => 0, 'bypass' => false];
        }
        $warning = 0;
        if ($count >= 3) $warning = 1; // jaune
        if ($count >= 4) $warning = 2; // rouge
        return ['ok' => true, 'reason' => 'quota_ok', 'warning_level' => $warning, 'bypass' => false];
    }

    public static function countActiveSessions(): int {
        $dir = dirname(self::COUNTERS_DIR);
        if (!is_dir($dir)) return 0;
        $entries = @scandir($dir) ?: [];
        $now = time();
        $count = 0;
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..' || str_starts_with($e, '.')) continue;
            $meta = @json_decode((string) @file_get_contents($dir . '/' . $e . '/meta.json'), true);
            if (!is_array($meta)) continue;
            if (($meta['expires_at'] ?? 0) > $now) {
                $count++;
            }
        }
        return $count;
    }

    public static function countSessionsForIpLastHour(string $ip): int {
        $file = self::COUNTERS_DIR . '/ip-' . self::hashIp($ip) . '.log';
        if (!is_readable($file)) return 0;
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $cutoff = time() - 3600;
        $count = 0;
        foreach ($lines as $ts) {
            if ((int) $ts >= $cutoff) $count++;
        }
        return $count;
    }

    public static function recordSessionStart(string $ip): void {
        @mkdir(self::COUNTERS_DIR, 0775, true);
        $file = self::COUNTERS_DIR . '/ip-' . self::hashIp($ip) . '.log';
        @file_put_contents($file, time() . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function banIp(string $ip): void {
        $until = time() + self::BAN_DURATION_S;
        @file_put_contents(self::BAN_LIST, "$ip $until\n", FILE_APPEND | LOCK_EX);
        @file_put_contents(self::CROWDSEC_ALERT,
            date('c') . " abuse_detected ip=$ip action=banned until=" . date('c', $until) . "\n",
            FILE_APPEND | LOCK_EX);
    }

    /**
     * Hash léger de l'IP pour nommer les fichiers de compteur sans exposer l'IP en clair
     * sur le disque (mais déterministe pour comptabilité).
     */
    private static function hashIp(string $ip): string {
        return substr(hash('sha256', 'biself-demo|' . $ip), 0, 16);
    }

    /**
     * Check du quota d'actions par session (50 max).
     * Incrémente et retourne false si dépassé.
     */
    public static function checkAndIncrementActions(string $sessionDir): bool {
        $file = rtrim($sessionDir, '/') . '/actions.counter';
        $current = (int) @file_get_contents($file);
        if ($current >= 50) return false;
        @file_put_contents($file, (string) ($current + 1), LOCK_EX);
        return true;
    }
}
