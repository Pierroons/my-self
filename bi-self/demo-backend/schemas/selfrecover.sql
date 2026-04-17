-- SelfRecover demo — schéma SQLite éphémère par session.

CREATE TABLE IF NOT EXISTS accounts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT UNIQUE NOT NULL,
    pw_hash         TEXT NOT NULL,          -- bcrypt du password
    pass_hash       TEXT NOT NULL,          -- bcrypt de la passphrase L1
    recovery_hash   TEXT NOT NULL,          -- bcrypt(derived_key) — L2
    recovery_word   TEXT,                   -- conservé en clair DANS LA DEMO
                                            -- pour pouvoir comparer avec le HMAC
                                            -- saisi par l'user (visibilité pédago).
                                            -- JAMAIS en prod.
    created_at      INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS app_sessions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL,
    token       TEXT UNIQUE NOT NULL,
    created_at  INTEGER NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id)
);

CREATE INDEX IF NOT EXISTS idx_app_sessions_token ON app_sessions(token);

-- Compteur de tentatives de login failed (rate-limit applicatif, pas infra)
CREATE TABLE IF NOT EXISTS login_attempts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    username    TEXT NOT NULL,
    success     INTEGER NOT NULL,
    attempted_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_login_attempts ON login_attempts(username, attempted_at);
