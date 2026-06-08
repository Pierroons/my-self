<?php
/**
 * SelfRecover demo — SQLite database helper
 */

// SITE_SALT — DÉPRÉCIÉ (R9-02) : remplacé par un sel par-utilisateur (users.user_salt).
// Plus exposé via l'API. Conservé seulement pour d'éventuels comptes legacy non migrés.
define('SITE_SALT', getenv('SELFRECOVER_SITE_SALT') ?: 'demo-site-salt-1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d');

// SERVER_SECRET — clé du sel factice anti-énumération de l'endpoint user-salt (R9-02).
// JAMAIS exposé. En prod : valeur aléatoire forte via l'environnement.
define('SERVER_SECRET', getenv('SELFRECOVER_SERVER_SECRET') ?: 'demo-server-secret-CHANGE-IN-PROD-9f8e7d6c5b4a3210');

// DEBUG_MODE — expose _trace dans les réponses JSON. true par défaut en démo, false en prod.
define('DEBUG_MODE', filter_var(getenv('SELFRECOVER_DEBUG') ?: 'true', FILTER_VALIDATE_BOOLEAN));

// ALLOWED_ORIGIN — domaine autorisé en CORS (whitelist stricte)
define('ALLOWED_ORIGIN', getenv('SELFRECOVER_ORIGIN') ?: 'http://localhost:8080');

// Argon2id options — profil OWASP 2026 (memory 64 MiB, 4 itérations, 2 threads)
define('ARGON2_OPTIONS', [
    'memory_cost' => 65536,
    'time_cost'   => 4,
    'threads'     => 2,
]);

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $path = __DIR__ . '/../selfrecover.sqlite';
    $init = !file_exists($path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    if ($init) {
        $pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));
    } else {
        // Migrations idempotentes (PDO::ERRMODE_EXCEPTION nécessite try/catch)
        foreach ([
            "ALTER TABLE users ADD COLUMN email TEXT",
            "ALTER TABLE users ADD COLUMN memorized_word_hash TEXT",
            "ALTER TABLE recovery_attempts ADD COLUMN ip_address TEXT",
            "ALTER TABLE recovery_attempts ADD COLUMN fingerprint TEXT",
            "ALTER TABLE recovery_attempts ADD COLUMN user_agent TEXT",
            "ALTER TABLE users ADD COLUMN last_login_at TEXT",
            "ALTER TABLE users ADD COLUMN login_count INTEGER DEFAULT 0",
            "ALTER TABLE users ADD COLUMN banned_until TEXT",
            "ALTER TABLE users ADD COLUMN user_salt TEXT",
        ] as $migration) {
            try { $pdo->exec($migration); } catch (Exception $e) { /* duplicate column = OK */ }
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS reset_requests (
                id TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                salt TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                used INTEGER DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )");
        } catch (Exception $e) { /* table exists = OK */ }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS suspicious_fingerprints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                fingerprint TEXT,
                user_agent TEXT,
                attempt_count INTEGER DEFAULT 1,
                blocked_until TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                last_seen TEXT DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_susp_ip ON suspicious_fingerprints(ip)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_susp_fp ON suspicious_fingerprints(fingerprint)");
        } catch (Exception $e) { /* table exists = OK */ }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS disputes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                dispute_number TEXT UNIQUE NOT NULL,
                user_id INTEGER NOT NULL,
                identifier TEXT,
                status TEXT NOT NULL DEFAULT 'open',
                refusal_count INTEGER DEFAULT 0,
                signals_json TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS dispute_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                dispute_id INTEGER NOT NULL,
                sender TEXT NOT NULL,
                body TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_disputes_user ON disputes(user_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_disputes_number ON disputes(dispute_number)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_disputes_status ON disputes(status)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_dispute_msg ON dispute_messages(dispute_id)");
        } catch (Exception $e) { /* tables exist = OK */ }
    }
    return $pdo;
}

/**
 * Système de traces "transparency live".
 * Chaque endpoint peut empiler des messages via addTrace() qui seront
 * renvoyés au client dans le champ `_trace` de la réponse JSON.
 *
 * RÈGLE D'OR : ne JAMAIS pousser dans les traces une donnée sensible
 * en clair (mot de passe, passphrase brute, mot de récupération brut,
 * hashes Argon2id complets). Seulement des métadonnées (longueurs,
 * durées, indices DB, codes d'erreur structurés).
 */
function addTrace(string $msg): void {
    if (!isset($GLOBALS['_trace'])) {
        $GLOBALS['_trace'] = [];
    }
    $GLOBALS['_trace'][] = '[' . date('H:i:s') . '] ' . $msg;
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    // _trace exposé uniquement si DEBUG_MODE=true (désactivable en prod)
    if (DEBUG_MODE && !empty($GLOBALS['_trace'])) {
        $data['_trace'] = $GLOBALS['_trace'];
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $code = 400): void {
    if (!isset($GLOBALS['_trace'])) {
        $GLOBALS['_trace'] = [];
    }
    $GLOBALS['_trace'][] = '[' . date('H:i:s') . '] [error] ' . $message;
    jsonResponse(['error' => $message], $code);
}

function getInput(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}
