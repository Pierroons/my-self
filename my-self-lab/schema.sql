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
    recovery_hash   TEXT NOT NULL,          -- Argon2id(derived_key) — L2 recovery
    is_admin        INTEGER NOT NULL DEFAULT 0,  -- panel admin (promotion via promote_admin.php)
    created_at      INTEGER NOT NULL
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

-- Rate-limit login (anti-bruteforce applicatif)
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    username     TEXT NOT NULL,
    success      INTEGER NOT NULL,
    ip           TEXT,
    attempted_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_login_attempts ON login_attempts(username, attempted_at);

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

-- ─────────────────────────────────────────────────────────────────────────────
-- Litiges — niveau 3, escalade humaine.
--
-- Les faits déclarés par le demandeur sont chiffrés ; l'IP n'est jamais stockée
-- telle quelle, seulement un HMAC. La décision de l'admin n'ouvre pas le compte :
-- elle est enregistrée, la remise en main se fait hors ligne.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS disputes (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    username     TEXT NOT NULL,                    -- compte revendiqué
    ciphertext   TEXT NOT NULL,                    -- base64(AES-256-GCM) des faits déclarés
    status       TEXT NOT NULL DEFAULT 'ouvert',   -- ouvert | accepte | refuse
    admin_note   TEXT,                             -- motif de la décision, en clair (pas de secret)
    ip_hash      TEXT,                             -- HMAC de l'IP, jamais l'IP
    created_at   INTEGER NOT NULL,
    decided_at   INTEGER
);
CREATE INDEX IF NOT EXISTS idx_disputes_status ON disputes(status, created_at);
CREATE INDEX IF NOT EXISTS idx_disputes_user ON disputes(username, created_at);

-- ─────────────────────────────────────────────────────────────────────────────
-- Codes de récupération — facteur de possession du niveau 2.
--
-- Deux colonnes pour deux métiers que l'une ne peut pas cumuler :
--   code_lookup = HMAC(code, sel serveur)  → retrouve le compte en O(1) SANS
--                 identifiant, ce qui retire l'oracle d'énumération
--   code_hash   = Argon2id(code)           → résiste à une fuite de la base
-- Argon2id seul imposerait de hacher chaque ligne à 64 Mo pour trouver la
-- correspondance ; HMAC seul tomberait à la première attaque hors ligne.
--
-- ⚠️ Cette table existait en production sans figurer ici : une réinstallation
-- depuis ce fichier produisait un lab où l'inscription plantait.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS recovery_codes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL,
    code_lookup TEXT NOT NULL,
    code_hash   TEXT NOT NULL,
    used        INTEGER NOT NULL DEFAULT 0,
    used_at     INTEGER,
    created_at  INTEGER NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id)
);
CREATE INDEX IF NOT EXISTS idx_codes_lookup ON recovery_codes(code_lookup);
CREATE INDEX IF NOT EXISTS idx_codes_account ON recovery_codes(account_id, used);

-- ─────────────────────────────────────────────────────────────────────────────
-- Appareil de confiance — facteur de possession alternatif au code, en L2.
--
-- Le navigateur génère une paire ECDSA P-256 à l'enrôlement : seule la clé
-- PUBLIQUE arrive ici. La privée est chiffrée par le mot mémorisé et ne quitte
-- jamais l'appareil, ce qui lie les deux facteurs cryptographiquement : un
-- appareil volé ne signe rien sans le mot.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS device_credentials (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id    INTEGER NOT NULL,
    credential_id TEXT NOT NULL UNIQUE,  -- id aléatoire, localise le compte (comme un recovery code)
    public_key    TEXT NOT NULL,         -- clé publique ECDSA P-256 (SPKI DER, base64url)
    created_at    INTEGER NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS device_challenges (
    challenge     TEXT PRIMARY KEY,      -- base64url, usage unique, TTL court
    credential_id TEXT,
    created_at    INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_devcred_cid ON device_credentials(credential_id);
CREATE INDEX IF NOT EXISTS idx_devcred_account ON device_credentials(account_id);
