-- Un schéma d'exemple pour SelfRecover, et le seul que `src/Storage/StockagePdo.php`
-- sache lire.
--
-- 🔑 **Fourni, jamais imposé.** `StorageInterface` existe pour qu'une application
-- garde le schéma qu'elle a déjà : la démo du lab nomme son empreinte de
-- passphrase `pass_hash` là où ce fichier dit `passphrase_hash`, et elle n'a rien
-- eu à migrer — elle écrit son propre adaptateur, c'est tout ce que le contrat
-- demande. Ce schéma-ci s'adresse à qui n'a pas encore de base : il se charge tel
-- quel, et `StockagePdo` le branche sans une ligne à écrire.
--
-- ── Les empreintes ─────────────────────────────────────────────────────────
--
-- Cinq colonnes de ce schéma portent une empreinte. Les trois du compte sont
-- celles qu'on confond, et les confondre coûte cher — d'où des noms entiers
-- plutôt que des abréviations :
--
--   pw_hash          le mot de passe de connexion, ordinaire
--   passphrase_hash  la passphrase diceware du niveau 1, émise par le serveur
--   recovery_hash    le mot mémorisé, DÉRIVÉ par le navigateur (niveau 2)
--
-- Les deux autres vivent ailleurs et ne se confondent avec rien : `code_hash`
-- (un code de récupération papier) et `claim_hash` (le sésame d'un litige).
--
-- ── Portabilité ────────────────────────────────────────────────────────────
--
-- Écrit pour SQLite. Sur un autre moteur, **le schéma ET l'adaptateur** demandent
-- des retouches, et les oublier casse au `CREATE TABLE`, pas à l'exécution.
--
-- Dans ce fichier, sur MySQL et MariaDB :
--
--   INTEGER PRIMARY KEY AUTOINCREMENT  → BIGINT AUTO_INCREMENT / BIGSERIAL
--   TEXT UNIQUE  (quatre colonnes)     → un index unique sur TEXT veut une longueur
--   TEXT PRIMARY KEY  (device_challenges) → même refus
--   CREATE INDEX IF NOT EXISTS         → inconnu de MySQL (MariaDB l'accepte)
--
-- Dans l'adaptateur, trois constructions :
--
--   INSERT OR REPLACE     (deux fois)  → ON DUPLICATE KEY UPDATE / ON CONFLICT
--   ON CONFLICT … excluded (poserGel)  → inconnu de MariaDB
--   LIMIT ?  paramétré    (listerLitiges) → refusé par MySQL en requête préparée
--
-- ⚠️ Le `PRAGMA` ci-dessous ne vaut QUE pour la connexion qui exécute ce
-- fichier — souvent `sqlite3(1)`, jetée aussitôt. Il ne protège donc rien par
-- lui-même : c'est `StockagePdo::__construct()` qui le repose sur la connexion
-- de l'application, et sans lui les cascades déclarées plus bas sont
-- décoratives. Mesuré : effacer un compte laisse alors ses codes, ses clés
-- d'appareil et le texte qu'il a écrit à un arbitre.
--
-- ⚠️ Déclarer une colonne `INTEGER` ne contraint rien non plus : SQLite accepte
-- `'2026-07-12 08:00:00'` dans une telle colonne, la range en `text`, et le cast
-- rend `2026`. Les instants se comptent en secondes depuis 1970 ; c'est
-- `Litige::PLANCHER_EPOQUE` qui refuse les valeurs aberrantes, pas le type.

PRAGMA foreign_keys = ON;

-- ── Les comptes ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS accounts (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    username         TEXT    UNIQUE NOT NULL,

    pw_hash          TEXT    NOT NULL,   -- Argon2id(mot de passe de connexion)
    passphrase_hash  TEXT    NOT NULL,   -- Argon2id(passphrase diceware, niveau 1)
    recovery_hash    TEXT    NOT NULL,   -- Argon2id(empreinte dérivée du mot mémorisé, niveau 2)

    -- 🔑 Le sel de dérivation, propre à ce compte, engendré par le NAVIGATEUR à
    -- l'inscription. Il n'est pas secret. Sans lui, deux personnes qui
    -- choisissent le même mot mémorisé produisent la même empreinte, et une
    -- table précalculée sert alors pour tout le service.
    recovery_salt    TEXT    NOT NULL DEFAULT '',

    -- 🔑 L'adresse sous laquelle le mot mémorisé a été dérivé. Ce n'est pas un
    -- secret et ce n'est pas une garde : le navigateur mêle le nom d'hôte à sa
    -- dérivation, donc une empreinte produite sur un site d'hameçonnage ne vaut
    -- rien ici. La colonne sert à l'ARBITRE du niveau 3, à qui elle dit sous
    -- quelle adresse ce compte a été enrôlé — ce qui distingue les générations
    -- après un changement de domaine.
    --
    -- ⚠️ Elle se réécrit à chaque `reposerSecrets()`, et l'adaptateur REFUSE de
    -- s'exécuter s'il n'a pas reçu d'hôte, plutôt que d'y laisser une chaîne
    -- vide sur un compte dont le navigateur vient de dériver sur une adresse
    -- bien réelle.
    derivation_host  TEXT    NOT NULL DEFAULT '',

    -- 🔑 Date d'émission de la passphrase du niveau 1. Elle INFORME, elle
    -- n'expire rien : une passphrase de récupération sert quand tout le reste
    -- est perdu, parfois des années après, et l'expiration la tuerait au moment
    -- précis où elle sert. Nullable exprès — un compte antérieur à la colonne
    -- rend `null`, jamais zéro, qui se lirait « émise en 1970 ».
    pass_emise_le    INTEGER,

    created_at       INTEGER NOT NULL,

    -- Traces d'usage, lues par le faisceau du niveau 3 : elles disent si le
    -- compte vivait, sans rien révéler de ses secrets.
    --
    -- ⚠️ `login_count` est NOT NULL DEFAULT 0, donc « jamais enregistré » y est
    -- indiscernable de « zéro connexion ». C'est `last_login_at`, nullable, qui
    -- tranche — et l'adaptateur rend les DEUX à `null` tant qu'il est vide.
    last_login_at    INTEGER,
    login_count      INTEGER NOT NULL DEFAULT 0
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_accounts_username ON accounts(username);

-- Les sessions applicatives. Présentes ici parce que `revoquerSessions()` est
-- au contrat : après un ré-enrôlement, ce qui restait ouvert au nom de la
-- personne d'avant doit tomber. Une application qui gère déjà ses sessions
-- ailleurs adapte cette seule méthode.
CREATE TABLE IF NOT EXISTS sessions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL,
    token       TEXT    UNIQUE NOT NULL,
    created_at  INTEGER NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token);

-- ── Les freins ─────────────────────────────────────────────────────────────

-- ⚠️ `ip` est nullable, et ce n'est pas une négligence : derrière un service
-- caché ou un proxy mutualisé, l'adresse vue est la même pour tout le monde.
-- Un déploiement dans ce cas passe `null` et compte sur une preuve de travail,
-- pas sur cette colonne — sinon le premier échec bloque tous les visiteurs.
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    username     TEXT    NOT NULL,
    success      INTEGER NOT NULL,
    ip           TEXT,
    attempted_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_attempts_username ON login_attempts(username, attempted_at);
CREATE INDEX IF NOT EXISTS idx_attempts_ip       ON login_attempts(ip, attempted_at);

-- ── Niveau 2 : les codes de récupération papier ─────────────────────────────

-- `code_lookup` LOCALISE le compte sans le nommer : c'est un index de recherche
-- dérivé du code, pas une empreinte de vérification. `code_hash` est celle qui
-- vérifie. Les séparer est ce qui permet de retrouver un compte sans que
-- l'utilisateur ait à taper son identifiant — et sans rendre la table
-- énumérable.
CREATE TABLE IF NOT EXISTS recovery_codes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL,
    code_lookup TEXT    NOT NULL,
    code_hash   TEXT    NOT NULL,
    used        INTEGER NOT NULL DEFAULT 0,
    used_at     INTEGER,
    created_at  INTEGER NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_codes_lookup  ON recovery_codes(code_lookup);
CREATE INDEX IF NOT EXISTS idx_codes_account ON recovery_codes(account_id, used);

-- ── Le facteur « cet appareil » ─────────────────────────────────────────────
--
-- 🔑 Toutes les applications ne servent pas ce facteur, et le contrat ne
-- l'exige pas.
-- Une paire de clés engendrée dans le navigateur, dont la privée ne quitte
-- jamais la machine : la possession se prouve par une signature, sans TPM ni
-- clé matérielle. Un déploiement qui ne le sert pas laisse ces deux tables
-- vides et fait lever ses six méthodes ; c'est ce que fait l'adaptateur de la
-- démo `bi-self-duo`.

CREATE TABLE IF NOT EXISTS device_credentials (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id    INTEGER NOT NULL,
    -- Aléatoire, localise le compte comme le fait `code_lookup` d'un code papier.
    credential_id TEXT    NOT NULL UNIQUE,
    -- Clé publique ECDSA P-256, SPKI DER en base64url. Publique : sa fuite
    -- n'autorise rien, c'est la privée restée dans le navigateur qui signe.
    public_key    TEXT    NOT NULL,
    created_at    INTEGER NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

-- ⚠️ Pas de clé étrangère vers `device_credentials` : un défi est émis AVANT
-- qu'on sache si le `credential_id` présenté existe, et une contrainte ici
-- ferait de l'échec d'insertion un oracle d'existence de compte.
CREATE TABLE IF NOT EXISTS device_challenges (
    challenge     TEXT PRIMARY KEY,   -- base64url, usage unique, TTL court
    credential_id TEXT,
    created_at    INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_challenges_age ON device_challenges(created_at);

-- ── Niveau 3 : le dossier et l'arbitrage humain ─────────────────────────────

CREATE TABLE IF NOT EXISTS disputes (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    -- Non énumérable : c'est aussi la capability qui donne accès au fil.
    dispute_number  TEXT    UNIQUE NOT NULL,
    account_id      INTEGER NOT NULL,
    -- open | awaiting_admin | accepted | refused | closed
    status          TEXT    NOT NULL DEFAULT 'open',
    -- Le faisceau de faits BRUTS montré à l'arbitre. Jamais un score chiffré :
    -- un nombre décide à la place de l'humain, et personne ne sait plus dire
    -- pourquoi.
    signals_json    TEXT,
    -- SHA-256 du sésame détenu par le demandeur. Vidé à la clôture.
    claim_hash      TEXT,
    expires_at      INTEGER,
    -- Combien de personnes ont ouvert un dossier concurrent sur ce compte.
    -- C'est le signal le plus utile à un arbitre, et il ne se reconstitue pas
    -- après coup.
    init_collisions INTEGER NOT NULL DEFAULT 0,
    submitted_at    INTEGER NOT NULL DEFAULT 0,
    decided_at      INTEGER,
    decided_by      TEXT,
    created_at      INTEGER NOT NULL,
    updated_at      INTEGER NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_disputes_number  ON disputes(dispute_number);
CREATE INDEX        IF NOT EXISTS idx_disputes_account ON disputes(account_id, status);
CREATE INDEX        IF NOT EXISTS idx_disputes_expiry  ON disputes(expires_at, status);

CREATE TABLE IF NOT EXISTS dispute_messages (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    dispute_id INTEGER NOT NULL,
    sender     TEXT    NOT NULL,   -- user | admin
    body       TEXT    NOT NULL,
    created_at INTEGER NOT NULL,
    FOREIGN KEY (dispute_id) REFERENCES disputes(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_messages_dispute ON dispute_messages(dispute_id, id);

-- Le gel de procédure : après trop de refus, plus aucun dossier ne s'ouvre
-- pendant un temps. La ligne est GARDÉE au dégel, pas supprimée — qui a dégelé
-- et quand vaut d'être conservé, y compris pour l'arbitre suivant.
CREATE TABLE IF NOT EXISTS l3_gel (
    account_id   INTEGER PRIMARY KEY REFERENCES accounts(id) ON DELETE CASCADE,
    gele_jusqu_a INTEGER NOT NULL,
    pose_le      INTEGER NOT NULL,
    degele_par   TEXT,
    degele_le    INTEGER
);
