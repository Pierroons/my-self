-- SelfRecover demo — SQLite schema
-- Minimal setup : users + recovery_attempts

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    identifier TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    passphrase_hash TEXT NOT NULL,
    recovery_derived_hash TEXT NOT NULL,
    l1_block_count INTEGER DEFAULT 0,
    l1_blocked_until TEXT,
    -- SelfRecover Lite (V0.1.1) : email + mot mémorisé pour reset 2FA-style
    email TEXT,
    memorized_word_hash TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
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
    attempted_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
CREATE INDEX IF NOT EXISTS idx_users_identifier ON users(identifier);
CREATE INDEX IF NOT EXISTS idx_attempts_time ON recovery_attempts(attempted_at);
