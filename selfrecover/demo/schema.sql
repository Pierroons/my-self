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
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

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
