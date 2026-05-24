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

    public static function path(): string
    {
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
    }
}
