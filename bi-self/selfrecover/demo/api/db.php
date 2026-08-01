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

// DEBUG_MODE — expose _trace dans les réponses JSON. false par défaut (prod-safe) ; la démo pose =true.
define('DEBUG_MODE', filter_var(getenv('SELFRECOVER_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN));

// ALLOWED_ORIGIN — domaine autorisé en CORS (whitelist stricte)
define('ALLOWED_ORIGIN', getenv('SELFRECOVER_ORIGIN') ?: 'http://localhost:8080');

// Argon2id options — profil OWASP 2026 (memory 64 MiB, 4 itérations, 2 threads)
define('ARGON2_OPTIONS', [
    'memory_cost' => 65536,
    'time_cost'   => 4,
    'threads'     => 2,
]);

// === Timings d'éjection ===
// Mode démo : SELFRECOVER_FAST_TIMINGS=true → tout à 30s (au lieu de 15min/1h/24h)
// pour tester les blocages sans attendre. En prod : laisser false.
define('FAST_TIMINGS', filter_var(getenv('SELFRECOVER_FAST_TIMINGS') ?: 'false', FILTER_VALIDATE_BOOLEAN));
define('L1_WINDOW_SEC', FAST_TIMINGS ? 30 : 900);    // fenêtre de comptage des échecs L1 (défaut 15 min)
define('L1_BLOCK_SEC',  FAST_TIMINGS ? 30 : 3600);   // durée de blocage L1 (défaut 1 h)
define('IP_THRESHOLD',  FAST_TIMINGS ? 3  : 10);     // nb d'échecs par IP avant blocage
define('IP_BLOCK_SEC',  FAST_TIMINGS ? 30 : 86400);  // durée de blocage IP (défaut 24 h)
// L2 : 3 échecs dans cette fenêtre → L2 fermé, éjection OBLIGATOIRE en L3.
// Indépendant du mode démo : ne doit PAS se réinitialiser en 30s (sinon on réessaie L2 sans fin).
define('L2_ESCALATE_WINDOW_SEC', 600); // 10 min

// R10-SR-02 : rate-limit login applicatif + hash factice anti-timing (l'oracle temporel du login).
define('LOGIN_RL_WINDOW_SEC', FAST_TIMINGS ? 30 : 900); // fenêtre de comptage des échecs login (défaut 15 min)
define('LOGIN_RL_MAX',        FAST_TIMINGS ? 3  : 5);    // échecs login/IP tolérés avant 429
// Hash Argon2id factice (mêmes ARGON2_OPTIONS) → password_verify s'exécute même quand le compte est absent (timing plat).
define('DUMMY_PASSWORD_HASH', '$argon2id$v=19$m=65536,t=4,p=2$VVpTTEREeXptSVhUZDZZWA$lH3Sdr5BMh6F8X+7whAdHl8H8OdHp/ImkENlF+cGXdE');

// Purge auto de la DB démo (borne la taille sous test de masse).
// ⚠️ DEMO-ONLY : supprime des comptes par âge. false par défaut (prod-safe) ; la démo pose =true.
define('DEMO_AUTOPURGE', filter_var(getenv('SELFRECOVER_DEMO_PURGE') ?: 'false', FILTER_VALIDATE_BOOLEAN));

// ─── Garde-fou prod « fail fast » ──────────────────────────────────────────
// En mode prod (DEBUG_MODE=false), refuser de tourner avec les secrets/réglages de démo.
// Mieux vaut un service qui ne démarre pas qu'un service silencieusement affaibli.
function fail_boot(string $why): void {
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "FATAL prod-hardening : $why\n"); exit(78); }
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Service mal configuré — voir les logs serveur.']);
    exit;
}
if (!DEBUG_MODE) {
    if (SERVER_SECRET === 'demo-server-secret-CHANGE-IN-PROD-9f8e7d6c5b4a3210')
        fail_boot('SERVER_SECRET = valeur démo. Pose SELFRECOVER_SERVER_SECRET (aléatoire fort).');
    if (DEMO_AUTOPURGE)
        fail_boot('DEMO_AUTOPURGE actif en prod (purge auto des comptes !). Retire SELFRECOVER_DEMO_PURGE.');
    // Secrets SU/audit : vérifiés côté CLI (le serveur web n'y touche pas).
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    // Chemin DB configurable (prod : hors du webroot immutable → /var/lib/selfrecover/, writable).
    // Leçon DataGuard : env OU fastcgi_param, fallback local pour la démo.
    $path = getenv('SELFRECOVER_DB_PATH') ?: ($_SERVER['SELFRECOVER_DB_PATH'] ?? '') ?: (__DIR__ . '/../selfrecover.sqlite');
    $init = !file_exists($path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    // SQLite n'applique PAS les clés étrangères sans ce PRAGMA : sans lui, celles
    // déclarées au schéma restent décoratives et la suppression d'un compte laisse
    // ses codes, ses litiges et ses appareils derrière elle.
    $pdo->exec('PRAGMA foreign_keys = ON');
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
            "ALTER TABLE users ADD COLUMN is_admin INTEGER DEFAULT 0", // rôle admin (posé uniquement par le SU via CLI)
            // R9-01b : capability liée à un sésame propriétaire + TTL + signal multi-demandeur
            "ALTER TABLE disputes ADD COLUMN claim_hash TEXT",
            "ALTER TABLE disputes ADD COLUMN expires_at TEXT",
            "ALTER TABLE disputes ADD COLUMN init_collisions INTEGER DEFAULT 0",
            "ALTER TABLE disputes ADD COLUMN source_ip TEXT", // fil rouge L2↔L3 côté serveur, JAMAIS exposé à l'admin
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
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
                claim_hash TEXT,
                expires_at TEXT,
                init_collisions INTEGER DEFAULT 0,
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
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS recovery_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                code_lookup TEXT NOT NULL,
                code_hash TEXT NOT NULL,
                used INTEGER DEFAULT 0,
                used_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_reccodes_lookup ON recovery_codes(code_lookup)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_reccodes_user ON recovery_codes(user_id)");
        } catch (Exception $e) { /* table exists = OK */ }

        // ── Cascade sur les clés étrangères ───────────────────────────────
        // Les contraintes ont longtemps été déclarées SANS cascade, et le PRAGMA
        // qui les applique n'était pas posé : elles étaient décoratives. Les
        // activer telles quelles ferait échouer toute suppression de compte —
        // la contrainte rejetterait le DELETE. SQLite ne sait pas modifier une
        // clé étrangère : chaque table concernée est recréée puis réalimentée.
        $aCascade = function (PDO $pdo, string $table): bool {
            $sql = (string) $pdo->query(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name='" . $table . "'"
            )->fetchColumn();
            return $sql === '' || str_contains($sql, 'ON DELETE CASCADE');
        };
        $recree = [
            'recovery_codes' => "CREATE TABLE recovery_codes__new (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
                code_lookup TEXT NOT NULL, code_hash TEXT NOT NULL,
                used INTEGER DEFAULT 0, used_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)",
            'device_credentials' => "CREATE TABLE device_credentials__new (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
                credential_id TEXT NOT NULL UNIQUE, public_key TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)",
            'dispute_messages' => "CREATE TABLE dispute_messages__new (
                id INTEGER PRIMARY KEY AUTOINCREMENT, dispute_id INTEGER NOT NULL,
                sender TEXT NOT NULL, body TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (dispute_id) REFERENCES disputes(id) ON DELETE CASCADE)",
        ];
        foreach ($recree as $table => $ddl) {
            if ($aCascade($pdo, $table)) { continue; }
            try {
                $cols = [];
                foreach ($pdo->query("PRAGMA table_info(" . $table . ")") as $c) { $cols[] = $c['name']; }
                $liste = implode(', ', $cols);
                $pdo->exec('PRAGMA foreign_keys = OFF');
                $pdo->exec($ddl);
                $pdo->exec("INSERT INTO " . $table . "__new (" . $liste . ") SELECT " . $liste . " FROM " . $table);
                $pdo->exec("DROP TABLE " . $table);
                $pdo->exec("ALTER TABLE " . $table . "__new RENAME TO " . $table);
                $pdo->exec('PRAGMA foreign_keys = ON');
            } catch (Exception $e) {
                // Une migration qui échoue ne doit pas empêcher le site de servir :
                // on repasse le PRAGMA et on laisse la table en l'état.
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS device_credentials (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                credential_id TEXT NOT NULL UNIQUE,
                public_key TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_devcred_cid ON device_credentials(credential_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_devcred_user ON device_credentials(user_id)");
            $pdo->exec("CREATE TABLE IF NOT EXISTS device_challenges (
                challenge TEXT PRIMARY KEY,
                credential_id TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )");
        } catch (Exception $e) { /* tables exist = OK */ }
        try {
            // Modèle SU→Admin→User : demandes de promotion + sessions de login
            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                requester_username TEXT NOT NULL,
                target_username TEXT NOT NULL,
                reason TEXT,
                status TEXT NOT NULL DEFAULT 'pending',
                su_note TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                decided_at TEXT
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_admreq_status ON admin_requests(status)");
            $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
                token TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                is_admin INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                expires_at TEXT NOT NULL
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at)");
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
