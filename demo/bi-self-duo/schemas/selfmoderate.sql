-- SelfModerate demo — schéma SQLite éphémère par session.
-- Démarre avec 5 bots préconfigurés (profils scénarisés).

CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT UNIQUE NOT NULL,
    is_bot          INTEGER NOT NULL DEFAULT 0,
    bot_profile     TEXT,                     -- 'toxic', 'neutral', 'upvoter', 'pack', 'victim'
    reputation      INTEGER NOT NULL DEFAULT 20,
    max_reputation  INTEGER NOT NULL DEFAULT 30,
    strikes         INTEGER NOT NULL DEFAULT 0,
    voting_rights   INTEGER NOT NULL DEFAULT 1,
    banned_until    INTEGER NOT NULL DEFAULT 0,
    created_at      INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_users_reputation ON users(reputation);

CREATE TABLE IF NOT EXISTS invitations (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    from_user    INTEGER NOT NULL,
    to_user      INTEGER NOT NULL,
    accepted_at  INTEGER NOT NULL,
    FOREIGN KEY (from_user) REFERENCES users(id),
    FOREIGN KEY (to_user) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_invitations_users ON invitations(from_user, to_user);

CREATE TABLE IF NOT EXISTS votes (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    invitation_id   INTEGER NOT NULL,
    voter_id        INTEGER NOT NULL,
    target_id       INTEGER NOT NULL,
    value           INTEGER NOT NULL,          -- +1 ou -1
    reason          TEXT,
    blocked         INTEGER NOT NULL DEFAULT 0,
    blocked_reason  TEXT,
    created_at      INTEGER NOT NULL,
    FOREIGN KEY (invitation_id) REFERENCES invitations(id),
    FOREIGN KEY (voter_id) REFERENCES users(id),
    FOREIGN KEY (target_id) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_votes_target ON votes(target_id, created_at);
CREATE INDEX IF NOT EXISTS idx_votes_voter ON votes(voter_id, created_at);

CREATE TABLE IF NOT EXISTS time_state (
    id              INTEGER PRIMARY KEY DEFAULT 1,
    simulated_time  INTEGER NOT NULL,
    tick_count      INTEGER NOT NULL DEFAULT 0
);

-- État initial : temps simulé = temps réel à la création
INSERT INTO time_state (id, simulated_time, tick_count)
    VALUES (1, strftime('%s', 'now'), 0);

-- 5 bots préconfigurés pour la démo
INSERT INTO users (username, is_bot, bot_profile, reputation, created_at) VALUES
    ('@alice_toxique',    1, 'toxic',    18, strftime('%s', 'now')),
    ('@bob_neutre',       1, 'neutral',  20, strftime('%s', 'now')),
    ('@charlie_upvoter',  1, 'upvoter',  20, strftime('%s', 'now')),
    ('@dave_pack',        1, 'pack',     20, strftime('%s', 'now')),
    ('@eve_victim',       1, 'victim',   20, strftime('%s', 'now'));

-- Quelques invitations initiales pour permettre de voter
-- alice ↔ bob, alice ↔ charlie, charlie ↔ dave (base pour pack-voting)
INSERT INTO invitations (from_user, to_user, accepted_at) VALUES
    (1, 2, strftime('%s', 'now') - 3600),  -- alice ↔ bob
    (2, 1, strftime('%s', 'now') - 3600),
    (1, 3, strftime('%s', 'now') - 3600),  -- alice ↔ charlie
    (3, 1, strftime('%s', 'now') - 3600),
    (3, 4, strftime('%s', 'now') - 1800),  -- charlie ↔ dave (coordonnés)
    (4, 3, strftime('%s', 'now') - 1800),
    (5, 1, strftime('%s', 'now') - 600);   -- eve → alice (victime potentielle)
