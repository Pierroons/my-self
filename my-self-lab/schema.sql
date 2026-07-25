-- MySelf-Lab — schéma SQLite (PDO)
-- Forum vitrine : auth SelfRecover + DM chiffrés SelfDataGuard.
-- Version prod-like : AUCUN secret en clair (pas de recovery_word clair contrairement à la démo péda).

PRAGMA foreign_keys = ON;

-- Comptes (modèle SelfRecover)
CREATE TABLE IF NOT EXISTS accounts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT UNIQUE NOT NULL,
    pw_hash         TEXT NOT NULL,          -- Argon2id(password)
    pass_hash       TEXT NOT NULL,          -- Argon2id(passphrase diceware L1)
    recovery_hash   TEXT NOT NULL,          -- Argon2id(derived_key) — mot mémorisé (facteur connaissance L2)
    is_admin        INTEGER NOT NULL DEFAULT 0,  -- panel admin (promotion via promote_admin.php)
    created_at      INTEGER NOT NULL,
    -- L3 : signaux contextuels (jamais un score) + ban temporaire après refus de litige
    last_login_at   INTEGER,
    login_count     INTEGER NOT NULL DEFAULT 0,
    banned_until    INTEGER NOT NULL DEFAULT 0
);

-- Sessions applicatives (token cookie)
CREATE TABLE IF NOT EXISTS app_sessions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL,
    token       TEXT UNIQUE NOT NULL,
    created_at  INTEGER NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_app_sessions_token ON app_sessions(token);

-- Rate-limit login + récupération (anti-bruteforce applicatif).
-- level : 0=login, 1=recover L1, 2=recover L2, 3=recover L3 (rate-limit propre par niveau).
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    username     TEXT NOT NULL,
    success      INTEGER NOT NULL,
    level        INTEGER NOT NULL DEFAULT 0,
    ip           TEXT,
    attempted_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_login_attempts ON login_attempts(username, attempted_at);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip, attempted_at);

-- Forum : sujets
CREATE TABLE IF NOT EXISTS threads (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL,
    titre       TEXT NOT NULL,
    categorie   TEXT NOT NULL DEFAULT 'general',  -- general / libre / rgpd / autohebergement / crypto
    created_at  INTEGER NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_threads_cat ON threads(categorie, created_at);

-- Forum : messages (posts)
CREATE TABLE IF NOT EXISTS posts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    thread_id   INTEGER NOT NULL,
    account_id  INTEGER NOT NULL,
    contenu     TEXT NOT NULL,
    created_at  INTEGER NOT NULL,
    FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_posts_thread ON posts(thread_id, created_at);

-- Messages privés — contenu CHIFFRÉ at-rest (AES-256-GCM via SelfDataGuard Primitives).
-- Un dump SQL ne révèle QUE le ciphertext : illisible sans la blind key serveur.
CREATE TABLE IF NOT EXISTS dm (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    sender_id     INTEGER NOT NULL,
    recipient_id  INTEGER NOT NULL,
    ciphertext    TEXT NOT NULL,         -- base64(AES-256-GCM blob) — JAMAIS de plaintext
    created_at    INTEGER NOT NULL,
    lu            INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (sender_id) REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_dm_recipient ON dm(recipient_id, created_at);

-- Profil membre — champs perso CHIFFRÉS at-rest (AES-256-GCM via SelfDataGuard).
-- Un dump SQL ne révèle que des blobs : aucune donnée personnelle en clair.
CREATE TABLE IF NOT EXISTS profiles (
    account_id   INTEGER PRIMARY KEY,
    ciphertext   TEXT NOT NULL,        -- base64(AES-256-GCM) du JSON {bio, localisation, lien}
    updated_at   INTEGER NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

-- SelfModerate — réputation + sanctions par membre
CREATE TABLE IF NOT EXISTS member_moderation (
    account_id     INTEGER PRIMARY KEY,
    reputation     INTEGER NOT NULL DEFAULT 20,
    strikes        INTEGER NOT NULL DEFAULT 0,
    voting_rights  INTEGER NOT NULL DEFAULT 1,
    banned_until   INTEGER NOT NULL DEFAULT 0,
    needs_review   INTEGER NOT NULL DEFAULT 0,   -- R10-LAB-01 : rep<=0 sans pack détecté -> revue humaine (plus de ban auto)
    updated_at     INTEGER NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

-- SelfModerate — votes (sur un post OU un membre)
CREATE TABLE IF NOT EXISTS mod_votes (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    voter_id       INTEGER NOT NULL,
    target_type    TEXT NOT NULL CHECK (target_type IN ('post','member')),
    target_id      INTEGER NOT NULL,        -- post.id ou account.id
    target_author  INTEGER NOT NULL,        -- account dont la réputation est affectée
    value          INTEGER NOT NULL CHECK (value IN (-1, 1)),
    blocked        INTEGER NOT NULL DEFAULT 0,
    blocked_reason TEXT,
    created_at     INTEGER NOT NULL,
    FOREIGN KEY (voter_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_modvotes_target ON mod_votes(target_type, target_id);
CREATE INDEX IF NOT EXISTS idx_modvotes_author ON mod_votes(target_author, created_at);
CREATE UNIQUE INDEX IF NOT EXISTS idx_modvotes_unique ON mod_votes(voter_id, target_type, target_id);

-- Rapports red team — soumis via formulaire public. Le corps du rapport
-- (titre, description, repro, contact) est CHIFFRÉ at-rest via SelfDataGuard :
-- la base ne révèle qu'un blob. handle/severity/target restent en clair (tri + hall of fame).
-- L'IP n'est jamais stockée en clair : seulement un HMAC (rate-limit anti-spam).
CREATE TABLE IF NOT EXISTS redteam_reports (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    handle      TEXT,                              -- pseudo public (hall of fame), peut être vide
    severity    TEXT NOT NULL DEFAULT 'info',      -- info|faible|moyen|eleve|critique
    target      TEXT,                              -- scénario ciblé (memo|auth|dm|moderation|web|autre)
    ciphertext  TEXT NOT NULL,                     -- base64(AES-256-GCM) du JSON {titre, description, repro, contact}
    status      TEXT NOT NULL DEFAULT 'nouveau',   -- nouveau|valide|rejete
    ip_hash     TEXT,                              -- HMAC-SHA256 de l'IP (rate-limit), jamais l'IP en clair
    created_at  INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_redteam_status ON redteam_reports(status, created_at);
CREATE INDEX IF NOT EXISTS idx_redteam_iphash ON redteam_reports(ip_hash, created_at);

-- Coffre E2E du mémo perso (POC chiffrement de bout en bout côté client).
-- Le serveur ne fait AUCUNE crypto : il stocke des blobs opaques produits par le
-- navigateur. Aucune clé, aucun plaintext ne touche jamais le serveur.
--   memo_ct  : le mémo chiffré par une vault_key aléatoire (AES-256-GCM)
--   wrap_pw  : la vault_key chiffrée par la clé dérivée du PASSWORD (enveloppe A)
--   wrap_rec : la vault_key chiffrée par la clé dérivée de la PASSPHRASE (enveloppe B)
-- Tous les champs *_ct/*_iv sont en base64. kdf_salt/kdf_iter = paramètres PBKDF2.
CREATE TABLE IF NOT EXISTS memo_vault (
    account_id   INTEGER PRIMARY KEY,
    kdf_salt     TEXT NOT NULL,
    kdf_iter     INTEGER NOT NULL,
    memo_iv      TEXT NOT NULL,
    memo_ct      TEXT NOT NULL,
    wrap_pw_iv   TEXT NOT NULL,
    wrap_pw_ct   TEXT NOT NULL,
    wrap_rec_iv  TEXT NOT NULL,
    wrap_rec_ct  TEXT NOT NULL,
    updated_at   INTEGER NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

-- =====================================================================
-- SelfRecover — escalade L1/L2/L3 + facteurs (parité démo bi-self)
-- =====================================================================

-- Recovery codes (foyer POSSESSION portable de L2) — lot de 10 par compte, usage unique.
-- Le mot mémorisé (recovery_hash, connaissance) + un recovery code (possession) = vrai 2FA.
CREATE TABLE IF NOT EXISTS recovery_codes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL,
    code_lookup TEXT NOT NULL,          -- HMAC(SERVER_SECRET, code) : recherche O(1) sans identifiant (pepper)
    code_hash   TEXT NOT NULL,          -- Argon2id(code) : vérif + résistance fuite DB
    used        INTEGER NOT NULL DEFAULT 0,
    used_at     INTEGER,
    created_at  INTEGER NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_reccodes_lookup ON recovery_codes(code_lookup);
CREATE INDEX IF NOT EXISTS idx_reccodes_account ON recovery_codes(account_id);

-- Credential « cet appareil » (foyer POSSESSION device-bound). Le serveur ne détient
-- QUE la clé publique ; la privée vit chiffrée dans le navigateur (enveloppe mot mémorisé).
CREATE TABLE IF NOT EXISTS device_credentials (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id    INTEGER NOT NULL,
    credential_id TEXT NOT NULL UNIQUE,  -- id aléatoire, localise le compte (comme un recovery code)
    public_key    TEXT NOT NULL,         -- clé publique ECDSA P-256 (SPKI DER, base64url)
    created_at    INTEGER NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_devcred_cid ON device_credentials(credential_id);
CREATE INDEX IF NOT EXISTS idx_devcred_account ON device_credentials(account_id);

CREATE TABLE IF NOT EXISTS device_challenges (
    challenge     TEXT PRIMARY KEY,      -- base64url, usage unique, TTL court
    credential_id TEXT,
    created_at    INTEGER NOT NULL
);

-- L3 : litiges. Chat admin OBLIGATOIRE — le scoring n'ouvre jamais rien, il aide l'humain.
CREATE TABLE IF NOT EXISTS disputes (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    dispute_number  TEXT UNIQUE NOT NULL,          -- LIT-XXXX (non énumérable)
    account_id      INTEGER NOT NULL,
    status          TEXT NOT NULL DEFAULT 'open',  -- open|awaiting_admin|granted|resolved|refused|closed
    refusal_count   INTEGER NOT NULL DEFAULT 0,
    signals_json    TEXT,                          -- faisceau de faits bruts pour l'admin (jamais un score chiffré)
    claim_hash      TEXT,                          -- SHA-256 du sésame généré côté demandeur (autorise le fil)
    expires_at      INTEGER,                       -- TTL de la capability (défaut +24h)
    init_collisions INTEGER NOT NULL DEFAULT 0,    -- inits concurrentes = signal multi-demandeur
    source_ip       TEXT,
    created_at      INTEGER NOT NULL,
    updated_at      INTEGER NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_disputes_account ON disputes(account_id);
CREATE INDEX IF NOT EXISTS idx_disputes_number ON disputes(dispute_number);
CREATE INDEX IF NOT EXISTS idx_disputes_status ON disputes(status);

CREATE TABLE IF NOT EXISTS dispute_messages (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    dispute_id INTEGER NOT NULL,
    sender     TEXT NOT NULL,                       -- user | admin
    body       TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    FOREIGN KEY (dispute_id) REFERENCES disputes(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_dispute_msg ON dispute_messages(dispute_id);

-- Fingerprints suspects — rate-limit per-IP sur le brute-force de n° de litige / sésame
-- (jamais sur les échecs L1/L2/L3 légitimes, cf modèle de menace : bloquer la source pas la cible).
CREATE TABLE IF NOT EXISTS suspicious_fingerprints (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    ip            TEXT NOT NULL,
    fingerprint   TEXT,
    user_agent    TEXT,
    attempt_count INTEGER NOT NULL DEFAULT 1,
    blocked_until INTEGER,
    created_at    INTEGER NOT NULL,
    last_seen     INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_susp_ip ON suspicious_fingerprints(ip);
