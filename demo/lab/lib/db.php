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

        return $override ? $override : __DIR__ . '/../data/lab.db';
    }

    /**
     * SQLite rend « unable to open database file » pour un disque plein comme pour
     * un répertoire où l'utilisateur courant n'écrit pas — et le second cas est la
     * règle dès que la console et le site tournent sous deux identités. La trace
     * PDO ne nomme ni l'utilisateur, ni le droit manquant, ni la prise qui déroute
     * la base : elle a coûté une nuit à qui la lisait le 09/09/2026.
     */
    private static function exigerAcces(string $chemin): void
    {
        $dossier = dirname($chemin);

        if (file_exists($chemin)) {
            if (!is_readable($chemin) || !is_writable($chemin)) {
                throw new \RuntimeException(
                    "Base du lab présente mais fermée à « " . self::utilisateur() . " » : {$chemin}\n"
                    . '   → ouvre les droits sur ce fichier, ou pose LAB_DB_PATH ailleurs.'
                );
            }
            // 🔑 Le fichier ne suffit pas. SQLite pose `lab.db-journal` (ou `-wal`)
            // À CÔTÉ de la base à chaque transaction — mesuré. Un répertoire fermé
            // laisse donc l'ouverture réussir et le premier INSERT échouer sur la
            // `PDOException` nue que cette méthode existe pour ne plus jamais rendre.
            if (!is_writable($dossier)) {
                throw new \RuntimeException(
                    "Base du lab lisible mais {$dossier} n'est pas inscriptible par « "
                    . self::utilisateur() . " » — SQLite y écrit son journal à chaque transaction.\n"
                    . '   → ouvre les droits sur ce dossier, ou pose LAB_DB_PATH ailleurs.'
                );
            }

            return;
        }

        if (!is_dir($dossier) && !@mkdir($dossier, 0750, true) && !is_dir($dossier)) {
            throw new \RuntimeException(
                "Base du lab : {$dossier} introuvable et non créable par « " . self::utilisateur() . " ».\n"
                . '   → pose LAB_DB_PATH sur un dossier accessible à cet utilisateur.'
            );
        }
        if (!is_writable($dossier)) {
            throw new \RuntimeException(
                "Base du lab absente, et {$dossier} n'est pas inscriptible par « " . self::utilisateur() . " ».\n"
                . '   → pose LAB_DB_PATH sur un dossier où cet utilisateur écrit.'
            );
        }
    }

    /** Le nom de l'utilisateur courant, pour que le message dise à QUI l'accès manque. */
    private static function utilisateur(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $u = posix_getpwuid(posix_geteuid());
            if (is_array($u) && isset($u['name'])) {
                return (string) $u['name'];
            }
        }

        return get_current_user() ?: '?';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $chemin = self::path();
            self::exigerAcces($chemin);
            self::$pdo = new PDO('sqlite:' . $chemin);
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

        // 🔑 Date d'émission de la passphrase L1, NULLABLE et sans valeur par
        // défaut. Un compte antérieur à cette colonne rend `null` — « on ne sait
        // pas quand » — et l'affichage se tait. Poser `DEFAULT 0` ferait dire à
        // chaque compte existant qu'il tient sa passphrase depuis 1970, ce qui
        // est le même défaut que `login_count` rendant zéro pour « jamais
        // connecté » : une valeur neutre qui se lit comme un fait.
        //
        // Elle n'expire rien. La faire expirer tuerait le secours au moment
        // précis où il sert.
        //
        // Traces d'usage lues par le faisceau du niveau 3.
        foreach ([
            'pass_emise_le' => 'INTEGER',
            'last_login_at' => 'INTEGER',
            'login_count'   => 'INTEGER NOT NULL DEFAULT 0',
            'banned_until'  => 'INTEGER',
        ] as $col => $type) {
            if (!in_array($col, $cols, true)) {
                self::$pdo->exec("ALTER TABLE accounts ADD COLUMN $col $type");
            }
        }

        // Colonnes du niveau 3 remonté dans la bibliothèque le 07/09/2026. La
        // décision et son auteur n'étaient nulle part : un dossier tranché ne
        // disait ni quand ni par qui, et le comptage des refus dans une fenêtre
        // glissante a besoin de la date.
        $dsp = array_column(self::$pdo->query('PRAGMA table_info(disputes)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach ([
            'decided_at'   => 'INTEGER',
            'decided_by'   => 'TEXT',
            'submitted_at' => 'INTEGER NOT NULL DEFAULT 0',
        ] as $col => $type) {
            if (!in_array($col, $dsp, true)) {
                self::$pdo->exec("ALTER TABLE disputes ADD COLUMN $col $type");
            }
        }

        // 🔑 Le gel porte sur la PROCÉDURE, jamais sur le compte. La ligne est
        // gardée après un dégel plutôt que supprimée : qui a dégelé et quand
        // vaut d'être conservé pour l'arbitre suivant.
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS l3_gel (
                account_id   INTEGER PRIMARY KEY REFERENCES accounts(id) ON DELETE CASCADE,
                gele_jusqu_a INTEGER NOT NULL,
                pose_le      INTEGER NOT NULL,
                degele_par   TEXT,
                degele_le    INTEGER
            )'
        );

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
