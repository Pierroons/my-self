<?php
/**
 * Bi-Self Demo — Session Manager.
 *
 * Chaque session démo = un UUID + un dossier isolé + une base SQLite propre
 * + un log.jsonl + un meta.json.
 *
 * TTL : 30 minutes. Cleanup par cron.
 *
 * Le SessionManager est responsable de :
 *  - lire/valider le cookie sj_demo_session
 *  - créer une nouvelle session (dossier, SQLite, meta)
 *  - fournir l'accès à la DB et au logger
 *  - vérifier la vie (not expired, not invalidated)
 */

declare(strict_types=1);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/rate_limit.php';

final class DemoSession {
    public const BASE_DIR      = '/var/lib/selfjustice/demo-sessions';
    public const COOKIE_NAME   = 'sj_demo_session';
    public const TTL_SECONDS   = 30 * 60;
    private const SQLITE_FILE  = 'demo.sqlite';

    public string $id;
    public string $dir;
    public string $module; // 'selfrecover' | 'selfmoderate' | 'duo'
    public int    $createdAt;
    public int    $expiresAt;
    public bool   $bypass;
    public int    $warningLevel;

    private ?SQLite3 $db = null;
    private DemoLogger $logger;

    private function __construct(string $id, string $module) {
        $this->id = $id;
        $this->dir = self::BASE_DIR . '/' . $id;
        $this->module = $module;
        $this->logger = new DemoLogger($this->dir);
        $meta = self::readMeta($this->dir);
        $this->createdAt = (int) ($meta['created_at'] ?? time());
        $this->expiresAt = (int) ($meta['expires_at'] ?? (time() + self::TTL_SECONDS));
        $this->bypass    = (bool) ($meta['bypass'] ?? false);
        $this->warningLevel = (int) ($meta['warning_level'] ?? 0);
    }

    public function logger(): DemoLogger {
        return $this->logger;
    }

    public function db(): SQLite3 {
        if ($this->db === null) {
            $path = $this->dir . '/' . self::SQLITE_FILE;
            $this->db = new SQLite3($path, SQLITE3_OPEN_READWRITE);
            $this->db->busyTimeout(2000);
            $this->db->exec('PRAGMA foreign_keys = ON');
            $this->db->exec('PRAGMA journal_mode = WAL');
        }
        return $this->db;
    }

    public function isExpired(): bool {
        return time() > $this->expiresAt;
    }

    public function close(): void {
        if ($this->db !== null) {
            $this->db->close();
            $this->db = null;
        }
    }

    public function toArray(): array {
        return [
            'session_id'    => $this->id,
            'module'        => $this->module,
            'created_at'    => $this->createdAt,
            'expires_at'    => $this->expiresAt,
            'remaining_s'   => max(0, $this->expiresAt - time()),
            'bypass'        => $this->bypass,
            'warning_level' => $this->warningLevel,
        ];
    }

    /**
     * Récupère la session depuis le cookie, ou null si absente / expirée / invalide.
     */
    public static function current(): ?self {
        $id = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $id)) {
            return null;
        }
        $dir = self::BASE_DIR . '/' . $id;
        if (!is_dir($dir) || !is_readable($dir . '/meta.json')) {
            return null;
        }
        $meta = self::readMeta($dir);
        if (!is_array($meta)) return null;
        $s = new self($id, (string) ($meta['module'] ?? 'selfrecover'));
        if ($s->isExpired()) return null;
        return $s;
    }

    /**
     * Crée une nouvelle session. Vérifie le rate-limit, le quota, etc.
     *
     * @param string $module 'selfrecover' | 'selfmoderate' | 'duo'
     * @return array ['ok' => bool, 'session' => ?self, 'error' => ?string, 'warning_level' => int]
     */
    public static function create(string $module): array {
        $check = RateLimit::canCreateSession();
        if (!$check['ok']) {
            return [
                'ok'            => false,
                'session'       => null,
                'error'         => $check['reason'],
                'warning_level' => 0,
            ];
        }

        // UUID v4
        $id = self::uuidV4();
        $dir = self::BASE_DIR . '/' . $id;

        if (!@mkdir($dir, 0770, true)) {
            return ['ok' => false, 'session' => null, 'error' => 'mkdir_failed', 'warning_level' => 0];
        }

        // meta.json
        $now = time();
        $meta = [
            'created_at'    => $now,
            'expires_at'    => $now + self::TTL_SECONDS,
            'module'        => $module,
            'bypass'        => $check['bypass'] ?? false,
            'warning_level' => $check['warning_level'] ?? 0,
            'ip_hash'       => substr(hash('sha256', 'biself|' . RateLimit::clientIp()), 0, 16),
        ];
        file_put_contents($dir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT));

        // log.jsonl vide
        touch($dir . '/log.jsonl');
        chmod($dir . '/log.jsonl', 0664);

        // SQLite
        $db_path = $dir . '/' . self::SQLITE_FILE;
        $db = new SQLite3($db_path, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA journal_mode = WAL');

        // Init schéma selon le module
        $schema_file = __DIR__ . '/../schemas/' . preg_replace('/[^a-z]/', '', $module) . '.sql';
        if (is_readable($schema_file)) {
            $sql = file_get_contents($schema_file);
            if ($sql !== false) {
                $db->exec($sql);
            }
        }
        $db->close();

        // Record session start pour rate-limit (sauf si bypass)
        if (!($check['bypass'] ?? false)) {
            RateLimit::recordSessionStart(RateLimit::clientIp());
        }

        // Cookie
        self::setCookie($id);

        $session = new self($id, $module);
        $session->logger()->info('session', 'Session démo ouverte', [
            'module'        => $module,
            'bypass'        => $session->bypass,
            'warning_level' => $session->warningLevel,
        ]);

        return [
            'ok'            => true,
            'session'       => $session,
            'error'         => null,
            'warning_level' => $session->warningLevel,
        ];
    }

    /**
     * Récupère session courante OU en crée une nouvelle.
     */
    public static function getOrCreate(string $module): array {
        $existing = self::current();
        if ($existing !== null && $existing->module === $module) {
            return ['ok' => true, 'session' => $existing, 'error' => null, 'warning_level' => $existing->warningLevel];
        }
        return self::create($module);
    }

    private static function readMeta(string $dir): array {
        $raw = @file_get_contents($dir . '/meta.json');
        if ($raw === false) return [];
        $meta = @json_decode($raw, true);
        return is_array($meta) ? $meta : [];
    }

    private static function uuidV4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
        $hex = bin2hex($data);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private static function setCookie(string $id): void {
        setcookie(
            self::COOKIE_NAME,
            $id,
            [
                'expires'  => time() + self::TTL_SECONDS,
                'path'     => '/',
                'domain'   => 'bi-self.my-self.fr',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }
}
