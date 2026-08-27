<?php
/**
 * MySelf-Lab — connexion SQLite (PDO) + init schema idempotent.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    /**
     * `LAB_DB_PATH` déroute la base, comme `SELFRECOVER_STATE_DIR` déroute
     * l'état du SU : sans cette prise, aucun contrôle ne peut s'exécuter
     * ailleurs que sur la base réelle.
     */
    public static function path(): string
    {
        $override = getenv('LAB_DB_PATH');
        if ($override) {
            $dir = dirname($override);
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            return $override;
        }
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir . '/lab.db';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO('sqlite:' . self::path());
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            // Schema idempotent (CREATE TABLE/INDEX IF NOT EXISTS) — exécuté à chaque
            // démarrage pour appliquer les nouvelles tables sans migration manuelle.
            self::initSchema();
        }
        return self::$pdo;
    }

    public static function initSchema(): void
    {
        $sql = file_get_contents(__DIR__ . '/../schema.sql');
        if ($sql === false) {
            throw new \RuntimeException('schema.sql introuvable');
        }
        self::$pdo->exec($sql);
        self::migrate();
    }

    /** Migrations légères pour les bases déjà créées (CREATE IF NOT EXISTS ne modifie pas l'existant). */
    private static function migrate(): void
    {
        $cols = self::$pdo->query('PRAGMA table_info(accounts)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('is_admin', $cols, true)) {
            self::$pdo->exec('ALTER TABLE accounts ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0');
        }

        // 🔑 Le sel de dérivation. Vide = compte créé avant le 27/08/2026, quand le
        // lab dérivait sans sel : son empreinte n'est plus reproductible par le
        // client actuel, et la colonne vide est ce qui permet de le reconnaître
        // au lieu de le croire salé.
        if (!in_array('recovery_salt', $cols, true)) {
            self::$pdo->exec("ALTER TABLE accounts ADD COLUMN recovery_salt TEXT NOT NULL DEFAULT ''");
        }

        // Traces d'usage lues par le faisceau du niveau 3.
        foreach ([
            'last_login_at' => 'INTEGER',
            'login_count'   => 'INTEGER NOT NULL DEFAULT 0',
            'banned_until'  => 'INTEGER',
        ] as $col => $type) {
            if (!in_array($col, $cols, true)) {
                self::$pdo->exec("ALTER TABLE accounts ADD COLUMN $col $type");
            }
        }

        // recovery_codes a longtemps référencé accounts SANS cascade : toute
        // suppression de compte échouait alors sur la contrainte. SQLite ne sait
        // pas modifier une clé étrangère — il faut recréer la table.
        $ddl = (string) self::$pdo->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='recovery_codes'"
        )->fetchColumn();
        if ($ddl !== '' && !str_contains($ddl, 'ON DELETE CASCADE')) {
            self::$pdo->exec('PRAGMA foreign_keys = OFF');
            self::$pdo->exec('
                CREATE TABLE recovery_codes_new (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id  INTEGER NOT NULL,
                    code_lookup TEXT NOT NULL,
                    code_hash   TEXT NOT NULL,
                    used        INTEGER NOT NULL DEFAULT 0,
                    used_at     INTEGER,
                    created_at  INTEGER NOT NULL,
                    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
                );
                INSERT INTO recovery_codes_new SELECT id, account_id, code_lookup, code_hash, used, used_at, created_at FROM recovery_codes;
                DROP TABLE recovery_codes;
                ALTER TABLE recovery_codes_new RENAME TO recovery_codes;
                CREATE INDEX IF NOT EXISTS idx_codes_lookup ON recovery_codes(code_lookup);
                CREATE INDEX IF NOT EXISTS idx_codes_account ON recovery_codes(account_id, used);
            ');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
        }

        // Niveau de récupération : le niveau 3 compte ses tentatives à part.
        $cols = self::$pdo->query('PRAGMA table_info(login_attempts)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('level', $cols, true)) {
            self::$pdo->exec('ALTER TABLE login_attempts ADD COLUMN level INTEGER NOT NULL DEFAULT 0');
        }

        // SelfModerate — cause du signalement, et convalescence (remontée passive).
        $cols = self::$pdo->query('PRAGMA table_info(member_moderation)')->fetchAll(PDO::FETCH_COLUMN, 1);
        foreach ([
            'review_reason'    => 'TEXT',
            'convalescent'     => 'INTEGER NOT NULL DEFAULT 0',
            'last_regen_at'    => 'INTEGER NOT NULL DEFAULT 0',
            'vote_muted_until' => 'INTEGER NOT NULL DEFAULT 0',
        ] as $col => $type) {
            if (!in_array($col, $cols, true)) {
                self::$pdo->exec("ALTER TABLE member_moderation ADD COLUMN $col $type");
            }
        }

        // SelfModerate — motif de vote.
        $cols = self::$pdo->query('PRAGMA table_info(mod_votes)')->fetchAll(PDO::FETCH_COLUMN, 1);
        foreach (['reason' => 'TEXT', 'reason_code' => 'TEXT'] as $col => $type) {
            if (!in_array($col, $cols, true)) {
                self::$pdo->exec("ALTER TABLE mod_votes ADD COLUMN $col $type");
            }
        }
    }
}
