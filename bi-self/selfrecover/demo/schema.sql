-- SelfRecover demo — SQLite schema
-- Minimal setup : users + recovery_attempts

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    identifier TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    passphrase_hash TEXT NOT NULL,
    recovery_derived_hash TEXT NOT NULL,
    user_salt TEXT,                       -- R9-02 : sel par-utilisateur (public, non secret) pour la dérivation HMAC client
    l1_block_count INTEGER DEFAULT 0,
    l1_blocked_until TEXT,
    -- SelfRecover Lite (V0.1.1) : email + mot mémorisé pour reset 2FA-style
    email TEXT,
    memorized_word_hash TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    -- L3 : signaux contextuels + ban temporaire après refus de litige
    last_login_at TEXT,
    login_count INTEGER DEFAULT 0,
    banned_until TEXT
);

-- SelfRecover Lite : table des demandes de reset par email + HMAC mot mémorisé
CREATE TABLE IF NOT EXISTS reset_requests (
    id TEXT PRIMARY KEY,                     -- 32 bytes hex (request_id)
    user_id INTEGER NOT NULL,
    salt TEXT NOT NULL,                      -- 32 bytes hex (HMAC salt côté client)
    expires_at TEXT NOT NULL,                -- TTL 15 min
    used INTEGER DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_reset_user ON reset_requests(user_id);
CREATE INDEX IF NOT EXISTS idx_reset_expires ON reset_requests(expires_at);

CREATE TABLE IF NOT EXISTS recovery_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT,
    level INTEGER NOT NULL,
    success INTEGER DEFAULT 0,
    ip_address TEXT,
    fingerprint TEXT,
    user_agent TEXT,
    attempted_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Suspicious fingerprints — rate limit per-IP + tracking comportement
CREATE TABLE IF NOT EXISTS suspicious_fingerprints (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL,
    fingerprint TEXT,
    user_agent TEXT,
    attempt_count INTEGER DEFAULT 1,
    blocked_until TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    last_seen TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
CREATE INDEX IF NOT EXISTS idx_users_identifier ON users(identifier);
CREATE INDEX IF NOT EXISTS idx_attempts_time ON recovery_attempts(attempted_at);
CREATE INDEX IF NOT EXISTS idx_attempts_ip ON recovery_attempts(ip_address);
CREATE INDEX IF NOT EXISTS idx_susp_ip ON suspicious_fingerprints(ip);
CREATE INDEX IF NOT EXISTS idx_susp_fp ON suspicious_fingerprints(fingerprint);

-- L3 : litiges (chat admin obligatoire — le scoring n'ouvre jamais rien, il aide l'admin)
CREATE TABLE IF NOT EXISTS disputes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    dispute_number TEXT UNIQUE NOT NULL,      -- LIT-XXXX
    user_id INTEGER NOT NULL,
    identifier TEXT,
    status TEXT NOT NULL DEFAULT 'open',       -- open | awaiting_admin | granted | resolved | refused | closed
    refusal_count INTEGER DEFAULT 0,           -- 3 refus → suppression du compte
    signals_json TEXT,                         -- faisceau de signaux contextuels (faits bruts pour l'admin, jamais un score chiffré)
    claim_hash TEXT,                           -- R9-01b : SHA-256 du sésame généré côté demandeur à l'init (autorise le fil, pas l'identifiant semi-public)
    expires_at TEXT,                           -- R9-01b : TTL de la capability (défaut +24h)
    init_collisions INTEGER DEFAULT 0,         -- R9-01b : inits concurrentes sur un litige ouvert = signal multi-demandeur pour l'admin
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS dispute_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    dispute_id INTEGER NOT NULL,
    sender TEXT NOT NULL,                      -- user | admin
    body TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dispute_id) REFERENCES disputes(id)
);

CREATE INDEX IF NOT EXISTS idx_disputes_user ON disputes(user_id);
CREATE INDEX IF NOT EXISTS idx_disputes_number ON disputes(dispute_number);
CREATE INDEX IF NOT EXISTS idx_disputes_status ON disputes(status);
CREATE INDEX IF NOT EXISTS idx_dispute_msg ON dispute_messages(dispute_id);

-- Recovery codes (foyer possession portable de L2) — lot par user, usage unique
CREATE TABLE IF NOT EXISTS recovery_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    code_lookup TEXT NOT NULL,      -- HMAC(SERVER_SECRET, code) : recherche O(1) sans identifier, non réversible (pepper)
    code_hash TEXT NOT NULL,        -- Argon2id(code) : vérif + résistance fuite DB
    used INTEGER DEFAULT 0,
    used_at TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_reccodes_lookup ON recovery_codes(code_lookup);
CREATE INDEX IF NOT EXISTS idx_reccodes_user ON recovery_codes(user_id);

-- Credential « cet appareil » (foyer possession device-bound, enveloppe par le mot mémorisé)
-- Le serveur ne détient QUE la clé publique. La privée vit chiffrée dans le navigateur.
CREATE TABLE IF NOT EXISTS device_credentials (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    credential_id TEXT NOT NULL UNIQUE,   -- id aléatoire, localise le compte (comme un recovery code)
    public_key TEXT NOT NULL,             -- clé publique ECDSA P-256 (SPKI DER, base64url)
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_devcred_cid ON device_credentials(credential_id);
CREATE INDEX IF NOT EXISTS idx_devcred_user ON device_credentials(user_id);

CREATE TABLE IF NOT EXISTS device_challenges (
    challenge TEXT PRIMARY KEY,            -- base64url, usage unique, TTL court
    credential_id TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
