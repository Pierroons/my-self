-- SelfRecover demo — schéma SQLite éphémère par session.

CREATE TABLE IF NOT EXISTS accounts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT UNIQUE NOT NULL,
    pw_hash         TEXT NOT NULL,          -- Argon2id du password
    pass_hash       TEXT NOT NULL,          -- Argon2id de la passphrase L1
    -- Argon2id(derived_key) — facteur de connaissance du niveau 2.
    --
    -- ⚠️ Argon2id ralentit ici, il ne protège pas : le mot fait 24 bits
    -- (bin2hex(random_bytes(3))), soit 16,7 millions de possibilités. Qui
    -- détient cette base les épuise en quelques heures. C'est un mot de
    -- démonstration, engendré par le serveur pour montrer le flux ; dans un
    -- vrai déploiement l'utilisateur choisit le sien, et sa longueur est ce
    -- qui décide du coût.
    --
    -- 🔑 Ce qui protège du HAMEÇONNAGE est ailleurs, et cette démo l'a
    -- longtemps mal dit : c'est le nom d'hôte que le NAVIGATEUR lit, entré tel
    -- quel dans la dérivation (`srDerive`, mode `hostname`). Une page qui imite
    -- ce service est servie sous un autre nom d'hôte, donc elle fait dériver
    -- une autre clé, inutilisable ici. Le sel, lui, ne protège de rien de tout
    -- cela — il n'est même pas secret.
    recovery_hash   TEXT NOT NULL,
    -- 🔑 Le sel de dérivation, propre à ce compte, engendré par le NAVIGATEUR
    -- (`srEngendrerSel`) et rangé tel quel. Il n'est pas secret : la route
    -- `/api/recover/sel` le rend à qui le demande. Ce qu'il ferme est autre
    -- chose — sans lui, deux personnes qui choisissent le même mot mémorisé
    -- produisent la même empreinte, et une table précalculée sert alors pour
    -- tout le service.
    recovery_salt   TEXT NOT NULL DEFAULT '',
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

-- ── Codes de secours — le facteur de possession portable du niveau 2 ────────
--
-- 🔑 Deux colonnes pour un seul code, et chacune répond à une menace distincte.
--
--   code_lookup : HMAC(code, secret du service) → retrouve le compte en O(1)
--                 SANS identifiant. C'est ce qui fait disparaître l'énumération :
--                 il n'y a plus de champ où tester si un compte existe.
--   code_hash   : Argon2id(code) → vérifie, et résiste à une fuite de la base.
--
-- Un lookup HMAC seul serait rejouable si la base fuitait ; un Argon2id seul
-- imposerait de parcourir toute la table à chaque tentative. Les deux ensemble
-- donnent la recherche immédiate ET la résistance.
--
-- ⚠️ Le code LOCALISE et AUTORISE, mais il n'authentifie pas à lui seul : le
-- mot mémorisé reste exigé. Un code trouvé sur un papier ne suffit donc pas.
CREATE TABLE IF NOT EXISTS recovery_codes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL,
    code_lookup TEXT NOT NULL,           -- HMAC-SHA256, recherche sans identifiant
    code_hash   TEXT NOT NULL,           -- Argon2id, vérification
    used        INTEGER NOT NULL DEFAULT 0,
    used_at     INTEGER,
    created_at  INTEGER NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_recovery_codes_lookup ON recovery_codes(code_lookup);
CREATE INDEX IF NOT EXISTS idx_recovery_codes_account ON recovery_codes(account_id);
