<?php
/**
 * MySelf-Lab — connexion SQLite (PDO) + init schema idempotent.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

require_once __DIR__ . '/secret_instance.php';

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
        return SecretInstance::prise('LAB_DB_PATH') ?? __DIR__ . '/../data/lab.db';
    }

    /**
     * Combien de lignes porte chaque table, lu sans ouvrir la base pour écrire.
     *
     * Sert à la question que pose un secret absent (« quelque chose en dépend-il ? »),
     * qui ne doit rien changer à ce qu'elle mesure : `pdo()` crée une base absente et y
     * applique schéma et migrations. Base absente ou table absente comptent zéro. Une
     * base illisible ou verrouillée lève, et le secret n'est alors pas posé.
     *
     * @param list<string> $tables
     * @return array<string, int>
     */
    public static function lignes(array $tables): array
    {
        $compte = array_fill_keys($tables, 0);
        $pdo = self::lectureSeule();
        if ($pdo === null) {
            return $compte;
        }
        $presentes = self::tables();
        foreach ($tables as $table) {
            if (in_array($table, $presentes, true)) {
                $compte[$table] = (int) $pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
            }
        }

        return $compte;
    }

    /**
     * Les tables non vides, nommées pour un message — « 3 message(s) privé(s), 1 profil(s) » —,
     * ou `null` si toutes sont vides : la réponse que `SecretInstance::lire()` attend de qui
     * déclare ce qui dépend d'un secret.
     *
     * @param array<string, string> $libelles table => ce que sont ses lignes
     */
    public static function ceQuiDepend(array $libelles): ?string
    {
        $morceaux = [];
        foreach (self::lignes(array_keys($libelles)) as $table => $n) {
            if ($n > 0) {
                $morceaux[] = $n . ' ' . $libelles[$table];
            }
        }

        return $morceaux === [] ? null : implode(', ', $morceaux);
    }

    /**
     * Une base est celle du lab si elle porte la table des comptes — pas parce qu'elle a des tables.
     *
     * @param list<string>|null $tables celles qu'on a déjà lues, ou `null` pour les lire
     */
    public static function estBaseDuLab(?array $tables = null): bool
    {
        return in_array('accounts', $tables ?? self::tables(), true);
    }

    /**
     * @return list<string> les tables de la base, lues sans l'ouvrir pour écrire ; aucune si elle est
     *                      absente. Les tables internes de SQLite n'en sont pas : `sqlite_sequence`
     *                      survit à la suppression de toutes les autres, et une base vidée ainsi
     *                      passait pour une base existante.
     */
    public static function tables(): array
    {
        $pdo = self::lectureSeule();

        return $pdo === null ? [] : $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /** La base ouverte en lecture seule — sans la créer, sans schéma ni migration ; `null` si elle est absente. */
    public static function lectureSeule(): ?PDO
    {
        $chemin = self::path();
        if (!is_file($chemin)) {
            return null;
        }

        return new PDO('sqlite:' . $chemin, null, null, [
            PDO::ATTR_ERRMODE           => PDO::ERRMODE_EXCEPTION,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);
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
            // Lues avant le schéma, qui les crée. Un fichier sans la table des comptes — vidé,
            // tronqué à zéro, pré-créé par l'exploitation, une autre base SQLite restaurée à sa
            // place — est une base neuve : SQLite l'ouvre sans un mot, et le schéma s'y posait
            // sous la clé de la base qu'il remplace.
            $tables = self::tables();
            $neuve = !self::estBaseDuLab($tables);
            if ($neuve) {
                self::exigerCleNeuve($chemin);
            }
            self::$pdo = new PDO('sqlite:' . $chemin);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            // 🔑 Une lecture d'une ligne garde son curseur, donc sa transaction de lecture, tant que
            // la requête vit : une écriture qui la suit sur la même connexion rend alors « database
            // is locked » sans attendre dès qu'une autre connexion a écrit entre-temps — SQLite le
            // refuse pour ne pas s'interbloquer, et le délai d'attente n'y peut rien. Après un
            // `fetch()` unique suivi d'une écriture : `closeCursor()`.

            // Une base qui avait déjà la table du numéro a déjà tiré un numéro.
            self::$numeroDejaTire = in_array('lab_generation', $tables, true);
            // Schema idempotent (CREATE TABLE/INDEX IF NOT EXISTS) — exécuté à chaque
            // démarrage pour appliquer les nouvelles tables sans migration manuelle.
            self::initSchema();
            // 🔑 Deux identités peuvent écrire cette base : le site et `selfrecover-su`.
            // Née sous le masque par défaut, elle serait en 0644 : une commande de la
            // console qui la crée la poserait au nom de l'opérateur, fermée en écriture au site.
            // 0660 la laisse au groupe que l'exploitation pose sur `data/` (bit
            // setgid) quand la console tourne sous une autre identité, et la ferme
            // aux autres comptes.
            if ($neuve) {
                @chmod($chemin, 0660);
            }
        }
        return self::$pdo;
    }

    /**
     * Une base ne naît pas sous une clé qui a déjà servi.
     *
     * 🔑 Une base disparue ou vide — effacée à la main, déplacée, tronquée, un chemin
     * changé — se recréait sans un mot sous la clé de l'ancienne : un forum vide qui
     * renumérote ses comptes depuis 1 et, pour qui détient une copie de l'ancienne et
     * écrit dans la nouvelle, ses chiffrés rouverts en y recopiant son numéro.
     * `selfrecover-su reset-db` fige la clé avec la base, et la suivante naît sous une clé
     * neuve. Aucune route ne lit la clé avant d'ouvrir la base : sur une instance neuve,
     * la base naît la première. Une clé posée à la main avant la première requête bloque
     * donc l'instance, et `reset-db --confirm --forcer` la fige.
     */
    private static function exigerCleNeuve(string $chemin): void
    {
        require_once __DIR__ . '/dataguard.php';
        [$cle, $temoin] = DataGuard::fichiersDeLaCle();
        if (is_file($cle) || is_file($temoin)) {
            throw new \RuntimeException(
                "Base du lab absente ou vide ({$chemin} ne porte pas la table des comptes) alors que la clé de ses "
                . "chiffrés a servi ici ({$cle}) : une base neuve sous cette clé rouvrirait, à qui y écrit, les chiffrés "
                . 'de l\'ancienne. Restaurer la '
                . 'base ; pour repartir à vide, `selfrecover-su reset-db --confirm --forcer`, qui fige la clé et ses '
                . 'deux témoins — ce qu\'elle a chiffré ne se relira plus.'
            );
        }
    }

    /** La base ouverte portait-elle déjà la table du numéro, avant que le schéma ne la pose ? */
    private static bool $numeroDejaTire = false;

    public static function initSchema(): void
    {
        $sql = file_get_contents(__DIR__ . '/../schema.sql');
        if ($sql === false) {
            throw new \RuntimeException('schema.sql introuvable');
        }
        self::$pdo->exec($sql);
        self::migrate();
    }

    /**
     * La forme sous laquelle une session est rangée : l'empreinte du jeton, jamais le jeton.
     *
     * 🔑 Rangé en clair, un jeton de session faisait d'une base volée la clé de toutes
     * les sessions en cours — la lecture des messages privés comprise, que le serveur
     * déchiffre pour qui est connecté. Le cookie porte le jeton, la base son empreinte :
     * un SHA-256 suffit, le jeton étant tiré au hasard (`Auth::JETON_OCTETS`), sans rien
     * d'un mot mémorisé à protéger d'une recherche exhaustive.
     */
    public static function empreinteJeton(string $jeton): string
    {
        return self::PREFIXE_JETON . hash('sha256', $jeton);
    }

    private const PREFIXE_JETON = 'sha256:';

    /**
     * Le numéro de cette base, tiré au hasard à sa naissance.
     *
     * 🔑 Un identifiant de compte n'est unique que dans UNE base : recréée, elle repart
     * de 1. Sans ce numéro dans le contexte d'un chiffré, le message d'Alice (n° 1) à
     * Bob (n° 2) d'une base effacée s'ouvrait pour le nouveau n° 2 de la suivante —
     * même clé, même contexte (mesuré par la septième passe d'audit du lot AAD, après
     * un `reset-db`). Tiré au hasard et non compté : un compteur rangé dans la base
     * repartirait avec elle. Il n'a pas à être secret — le secret, c'est `.blindkey` —,
     * il a à changer quand la base change, et à voyager avec elle quand on la restaure.
     */
    public static function generation(): string
    {
        if (self::$generation === null) {
            $ligne = self::pdo()->query(
                "SELECT g.valeur, m.valeur AS empreinte FROM lab_generation g
                   LEFT JOIN lab_migrations m ON m.nom = 'numero-de-base' WHERE g.id = 1"
            )->fetch(PDO::FETCH_ASSOC);
            $valeur = $ligne === false ? false : $ligne['valeur'];
            if ($valeur === false) {
                throw new \RuntimeException(
                    'Numéro de base absent (table lab_generation) alors qu\'il a déjà servi ici : un numéro neuf '
                    . 'rendrait illisible, sans le dire, ce qui a été scellé sous lui. Restaurer la ligne depuis une '
                    . 'sauvegarde de cette base. Pour repartir d\'un numéro neuf en connaissance de cause : '
                    . self::NUMERO_NEUF . ' — ce qui était scellé sous l\'ancien sera perdu.'
                );
            }
            if (!is_string($valeur) || strlen($valeur) !== 32) {
                throw new \RuntimeException('Numéro de base absent ou altéré (table lab_generation) : refus de chiffrer sans lui.');
            }
            // Réécrit, il rendait tout illisible, et le journal accusait la clé : le site offrait
            // même au titulaire de remplacer un profil intact (mesuré par la neuvième passe
            // d'audit). La trace de son tirage porte son empreinte.
            if (is_string($ligne['empreinte']) && !hash_equals($ligne['empreinte'], hash('sha256', $valeur))) {
                throw new \RuntimeException(
                    'Numéro de base réécrit (table lab_generation) : il ne correspond plus à la trace de son tirage '
                    . '(lab_migrations, numero-de-base), et ce qui a été scellé sous lui ne s\'ouvrirait plus. Restaurer '
                    . 'la ligne depuis une sauvegarde de cette base, ou repartir d\'un numéro neuf en connaissance de '
                    . 'cause : ' . self::NUMERO_NEUF . '.'
                );
            }
            self::$generation = $valeur;
        }

        return self::$generation;
    }

    private static ?string $generation = null;

    /** Le geste qui tire un numéro neuf à la main : la ligne, et la trace de l'ancien retirée. */
    private const NUMERO_NEUF = "DELETE FROM lab_generation; DELETE FROM lab_migrations WHERE nom = 'numero-de-base'; "
        . "INSERT INTO lab_generation (id, valeur, nee_le) VALUES (1, lower(hex(randomblob(16))), strftime('%s', 'now'))";

    /**
     * Remplace le numéro mémoïsé, et rend celui qu'il remplace.
     *
     * Réservé au simulateur d'attaques : sa base jetable n'est pas celle du site, et
     * ses blobs ne doivent ni porter le numéro de la vraie base ni l'ouvrir pour le
     * lire. Il remet l'ancien en sortant, comme il remet le journal de modération.
     */
    public static function substituerGeneration(?string $numero): ?string
    {
        $avant = self::$generation;
        self::$generation = $numero;

        return $avant;
    }

    /**
     * Le préfixe d'un blob scellé sous son contexte, donc sous un numéro de base.
     *
     * Recopié d'`EncryptedBlob::PREFIX_V3` : cette classe s'ouvre aussi sans la
     * bibliothèque (la console SU), et `tests/sanity_dataguard.php` tient les deux égaux.
     */
    public const PREFIXE_BLOB_LIE = 'SDG3.';

    /**
     * Un numéro a-t-il déjà servi dans cette base : tiré ici, ou scellé dans un blob ?
     *
     * Trois témoins indépendants : la table du numéro, présente avant que le schéma la
     * pose ; la trace du tirage ; les blobs liés. Une ligne effacée par un script de purge
     * laisse les deux premiers — c'est le cas d'une base qui ne porte que des rapports,
     * dont les jetons de suivi portent le numéro (mesuré par la neuvième passe d'audit) ;
     * une base reconstruite à partir de ses seules tables `dm` et `profiles` garde le
     * troisième. ⚠️ Une base dont la table et la trace ont disparu, et qui ne porte que des
     * rapports, ne se distingue pas d'une base d'avant le numéro : elle en reçoit un neuf.
     */
    private static function numeroADejaServi(): bool
    {
        if (self::$numeroDejaTire) {
            return true;
        }
        $lie = self::$pdo->prepare(
            "SELECT EXISTS (SELECT 1 FROM lab_migrations WHERE nom = 'numero-de-base')
                 OR EXISTS (SELECT 1 FROM dm WHERE substr(ciphertext, 1, ?) = ?)
                 OR EXISTS (SELECT 1 FROM profiles WHERE substr(ciphertext, 1, ?) = ?)"
        );
        $n = strlen(self::PREFIXE_BLOB_LIE);
        $lie->execute([$n, self::PREFIXE_BLOB_LIE, $n, self::PREFIXE_BLOB_LIE]);

        return (bool) $lie->fetchColumn();
    }

    /** L'instant où ce numéro a été tiré : ce qui est plus ancien a été écrit sans lui. */
    public static function generationNeeLe(): int
    {
        return (int) self::pdo()->query('SELECT nee_le FROM lab_generation WHERE id = 1')->fetchColumn();
    }

    /** Migrations légères pour les bases déjà créées (CREATE IF NOT EXISTS ne modifie pas l'existant). */
    private static function migrate(): void
    {
        // Le numéro de la base, tiré une fois : à sa naissance, ou à la première ouverture
        // d'une base d'avant. Deux premières ouvertures concurrentes : la première écrite
        // l'emporte, et chacune relit ensuite celui qui est rangé.
        // 🔑 Un numéro perdu ne se remplace pas en silence : comme un secret absent, il
        // n'est une panne que si quelque chose en dépend. Retiré à chaque ligne manquante,
        // il rendait illisibles tous les messages et profils sans un mot, et la passe de
        // migration se disait finie (mesuré par la huitième passe d'audit). La ligne reste
        // alors absente, et `generation()` refuse là où le numéro sert.
        $colonnes = self::$pdo->query('PRAGMA table_info(lab_migrations)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('valeur', $colonnes, true)) {
            self::$pdo->exec('ALTER TABLE lab_migrations ADD COLUMN valeur TEXT');
        }
        if (self::$pdo->query('SELECT 1 FROM lab_generation WHERE id = 1')->fetchColumn() === false
            && !self::numeroADejaServi()) {
            self::$pdo->prepare('INSERT OR IGNORE INTO lab_generation (id, valeur, nee_le) VALUES (1, ?, ?)')
                ->execute([bin2hex(random_bytes(16)), time()]);
            // L'empreinte du numéro RANGÉ, relu : celui d'une ouverture concurrente a pu l'emporter.
            $range = (string) self::$pdo->query('SELECT valeur FROM lab_generation WHERE id = 1')->fetchColumn();
            self::$pdo->prepare("INSERT OR IGNORE INTO lab_migrations (nom, faite_le, valeur) VALUES ('numero-de-base', ?, ?)")
                ->execute([time(), hash('sha256', $range)]);
        }

        // Les sessions ouvertes avant l'empreinte : converties UNE fois, sans déconnecter
        // personne, et la conversion se note. Rejouée à chaque ouverture, elle réparait
        // en silence un chemin qui rangerait de nouveau un jeton en clair — aucun banc ne
        // rougissait (mesuré par la septième passe d'audit). Un jeton est hexadécimal :
        // il ne commence jamais par le préfixe.
        if (self::$pdo->query("SELECT 1 FROM lab_migrations WHERE nom = 'sessions-sous-empreinte'")->fetchColumn() === false) {
            $enClair = self::$pdo->prepare('SELECT id, token FROM app_sessions WHERE substr(token, 1, ?) <> ?');
            $enClair->execute([strlen(self::PREFIXE_JETON), self::PREFIXE_JETON]);
            $convertir = self::$pdo->prepare('UPDATE app_sessions SET token = ? WHERE id = ?');
            foreach ($enClair->fetchAll() as $session) {
                $convertir->execute([self::empreinteJeton((string) $session['token']), (int) $session['id']]);
            }
            self::$pdo->prepare("INSERT OR IGNORE INTO lab_migrations (nom, faite_le) VALUES ('sessions-sous-empreinte', ?)")
                ->execute([time()]);
        }

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

        // 🔑 La graine d'un compte et d'un rapport, tirée à leur création, entre dans le contexte
        // de leurs chiffrés et dans le jeton de suivi. Une sauvegarde restaurée rend au prochain
        // inscrit le numéro d'un compte créé depuis, sous la même clé et le même numéro de base :
        // sous le seul numéro, le chiffré du compte disparu s'ouvrait pour lui. Une base neuve la
        // tire par le défaut de sa colonne ; une base plus ancienne la reçoit ici une fois, puis
        // par un déclencheur — `ALTER TABLE` n'accepte pas de défaut calculé.
        foreach (['accounts', 'redteam_reports'] as $table) {
            $colonnes = self::$pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('graine', $colonnes, true)) {
                self::$pdo->exec("ALTER TABLE $table ADD COLUMN graine TEXT");
                self::$pdo->exec("UPDATE $table SET graine = lower(hex(randomblob(16))) WHERE graine IS NULL");
            }
        }

        // Colonnes du niveau 3 remonté dans la bibliothèque le 07/09/2026. La
        // décision et son auteur n'étaient nulle part : un dossier tranché ne
        // disait ni quand ni par qui, et le comptage des refus dans une fenêtre
        // glissante a besoin de la date.
        // L'abandon est le seul acte d'arbitre qui ne rangeait personne :
        // `cloreLitige($litigeId, $quand)` ne reçoit pas son auteur, là où
        // `leverGel()` le reçoit et l'écrit dans `l3_gel.degele_par`. Ces deux
        // colonnes ferment l'asymétrie du côté du lab, qui tient le schéma.
        $dsp = array_column(self::$pdo->query('PRAGMA table_info(disputes)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach ([
            'decided_at'    => 'INTEGER',
            'decided_by'    => 'TEXT',
            'submitted_at'  => 'INTEGER NOT NULL DEFAULT 0',
            'abandonne_par' => 'TEXT',
            'abandonne_le'  => 'INTEGER',
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
                gele_par     TEXT,
                degele_par   TEXT,
                degele_le    INTEGER
            )'
        );

        // `gele_par` est arrivée avec le gel manuel : une base montée avant lui
        // a la table sans la colonne, et `CREATE TABLE IF NOT EXISTS` ne la
        // rattrape pas.
        $gel = array_column(self::$pdo->query('PRAGMA table_info(l3_gel)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('gele_par', $gel, true)) {
            self::$pdo->exec('ALTER TABLE l3_gel ADD COLUMN gele_par TEXT');
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

        // Une tentative sans compte — un code de récupération introuvable — portait
        // une étiquette nulle jusqu'à SelfRecover 0.12.0, et une base en service
        // garde ces lignes. SQLite ne sait pas retirer un NOT NULL : la table se
        // reconstruit. La contrainte se lit au PRAGMA, jamais dans le texte du
        // schéma : une réindentation de `schema.sql` sauterait la reprise, et la
        // panne n'arriverait qu'au premier code mal tapé.
        $notnull = 0;
        foreach (self::$pdo->query('PRAGMA table_info(login_attempts)')->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if ($col['name'] === 'username') {
                $notnull = (int) $col['notnull'];
            }
        }
        if ($notnull === 1) {
            self::$pdo->exec('
                CREATE TABLE login_attempts_new (
                    id           INTEGER PRIMARY KEY AUTOINCREMENT,
                    username     TEXT,
                    success      INTEGER NOT NULL,
                    ip           TEXT,
                    attempted_at INTEGER NOT NULL
                );
                INSERT INTO login_attempts_new SELECT id, username, success, ip, attempted_at FROM login_attempts;
                DROP TABLE login_attempts;
                ALTER TABLE login_attempts_new RENAME TO login_attempts;
                CREATE INDEX IF NOT EXISTS idx_login_attempts ON login_attempts(username, attempted_at);
            ');
        }

        // SelfModerate — cause du signalement, convalescence (remontée passive), et
        // début, origine et motif d'un ban.
        $cols = self::$pdo->query('PRAGMA table_info(member_moderation)')->fetchAll(PDO::FETCH_COLUMN, 1);
        foreach ([
            'review_reason'    => 'TEXT',
            'convalescent'     => 'INTEGER NOT NULL DEFAULT 0',
            'last_regen_at'    => 'INTEGER NOT NULL DEFAULT 0',
            'vote_muted_until' => 'INTEGER NOT NULL DEFAULT 0',
            'ban_debut'        => 'INTEGER NOT NULL DEFAULT 0',
            'ban_origine'      => 'TEXT',
            'ban_motif'        => 'TEXT',
        ] as $col => $type) {
            if (!in_array($col, $cols, true)) {
                self::$pdo->exec("ALTER TABLE member_moderation ADD COLUMN $col $type");
            }
        }

        // SelfModerate — les signaux passent de la case à `mod_signaux`, UNE fois. Rejouée à
        // chaque ouverture, la copie relirait le résumé que le moteur tient dans la case : un
        // écrivain qui y écrirait encore à la main deviendrait un signal, sans un mot. Une case
        // ouverte sans cause devient `signal_anterieur`, que seul un arbitre ferme ; sa date est
        // celle de la dernière écriture de la ligne. Un signal d'état dont l'état a cessé n'a
        // plus rien à montrer et ne passe pas — la case unique en laissait (un `reputation_zero`
        // après la fin d'un ban automatique) —, et la case se recale sur ce qui a passé.
        if (self::$pdo->query("SELECT 1 FROM lab_migrations WHERE nom = 'signaux-en-table'")->fetchColumn() === false) {
            self::$pdo->exec('BEGIN IMMEDIATE');
            try {
                if (self::$pdo->query("SELECT 1 FROM lab_migrations WHERE nom = 'signaux-en-table'")->fetchColumn() === false) {
                    self::$pdo->exec(
                        "INSERT OR IGNORE INTO mod_signaux (account_id, cause, ouvert_le, derniere_le)
                         SELECT account_id, COALESCE(review_reason, 'signal_anterieur'), updated_at, updated_at
                           FROM member_moderation
                          WHERE needs_review = 1
                            AND CASE review_reason
                                  WHEN 'ban_auto'         THEN ban_origine = 'auto' AND banned_until > 0
                                  WHEN 'journal_en_panne' THEN banned_until > 0
                                  WHEN 'reputation_zero'  THEN convalescent = 1
                                  ELSE 1
                                END"
                    );
                    self::$pdo->exec(
                        "UPDATE member_moderation
                            SET needs_review = 0, review_reason = NULL
                          WHERE needs_review = 1
                            AND NOT EXISTS (SELECT 1 FROM mod_signaux s WHERE s.account_id = member_moderation.account_id)"
                    );
                    self::$pdo->exec("UPDATE member_moderation SET review_reason = 'signal_anterieur' WHERE needs_review = 1 AND review_reason IS NULL");
                    self::$pdo->prepare("INSERT INTO lab_migrations (nom, faite_le) VALUES ('signaux-en-table', ?)")
                        ->execute([time()]);
                }
                self::$pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                self::$pdo->exec('ROLLBACK');
                throw $e;
            }
        }

        // SelfModerate — motif de vote, et ce que le vote a changé à la réputation.
        // Un vote d'avant cette colonne la garde à NULL : le moteur le lit sans effet connu.
        $cols = self::$pdo->query('PRAGMA table_info(mod_votes)')->fetchAll(PDO::FETCH_COLUMN, 1);
        foreach (['reason' => 'TEXT', 'reason_code' => 'TEXT', 'effet' => 'INTEGER'] as $col => $type) {
            if (!in_array($col, $cols, true)) {
                self::$pdo->exec("ALTER TABLE mod_votes ADD COLUMN $col $type");
            }
        }

        // Un coffre mémo sans `kdf` a été scellé par PBKDF2 : le client refuse de le relire.
        $cols = self::$pdo->query('PRAGMA table_info(memo_vault)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('kdf', $cols, true)) {
            self::$pdo->exec('ALTER TABLE memo_vault ADD COLUMN kdf TEXT');
        }

        // 🔑 Le dernier administrateur ne part pas. La règle vit dans la base et
        // non dans la console : tout chemin qui écrit `accounts` s'y heurte, y
        // compris celui qu'on écrira demain. Seul `reset-shell` lève la garde, en
        // posant `reset_en_cours` dans sa propre transaction ; `reset-db` ne la lève
        // pas, il remplace la base entière.
        //
        // Ici plutôt que dans `schema.sql` : les triggers nomment `is_admin`, que
        // les bases anciennes ne reçoivent qu'au début de cette méthode.
        //
        // ⚠️ Qui écrit directement dans le fichier lève la garde aussi. Elle arrête
        // un chemin de code oublieux, pas un intrus sur le serveur : contre une base
        // compromise, le remède est `reset-db`.
        self::$pdo->exec(
            "CREATE TABLE IF NOT EXISTS garde_admin (
                id             INTEGER PRIMARY KEY CHECK (id = 1),
                reset_en_cours INTEGER NOT NULL DEFAULT 0
            );
            INSERT OR IGNORE INTO garde_admin (id) VALUES (1);"
        );
        self::poserDeclencheurs();
    }

    private const DECLENCHEURS = [
        'dernier_admin_update' => "CREATE TRIGGER dernier_admin_update
            BEFORE UPDATE OF is_admin ON accounts
            WHEN OLD.is_admin = 1 AND NEW.is_admin = 0
             AND (SELECT COUNT(*) FROM accounts WHERE is_admin = 1) <= 1
             AND (SELECT reset_en_cours FROM garde_admin WHERE id = 1) = 0
            BEGIN
                SELECT RAISE(ABORT, 'dernier administrateur : la base en garde toujours un');
            END",
        'dernier_admin_delete' => "CREATE TRIGGER dernier_admin_delete
            BEFORE DELETE ON accounts
            WHEN OLD.is_admin = 1
             AND (SELECT COUNT(*) FROM accounts WHERE is_admin = 1) <= 1
             AND (SELECT reset_en_cours FROM garde_admin WHERE id = 1) = 0
            BEGIN
                SELECT RAISE(ABORT, 'dernier administrateur : la base en garde toujours un');
            END",
        'accounts_graine' => "CREATE TRIGGER accounts_graine
            AFTER INSERT ON accounts
            WHEN NEW.graine IS NULL
            BEGIN
                UPDATE accounts SET graine = lower(hex(randomblob(16))) WHERE id = NEW.id;
            END",
        'redteam_graine' => "CREATE TRIGGER redteam_graine
            AFTER INSERT ON redteam_reports
            WHEN NEW.graine IS NULL
            BEGIN
                UPDATE redteam_reports SET graine = lower(hex(randomblob(16))) WHERE id = NEW.id;
            END",
    ];

    /**
     * Réécrit tout déclencheur dont le texte en base diffère de `DECLENCHEURS`.
     * `CREATE TRIGGER IF NOT EXISTS` garderait l'ancien corps sur toute base
     * existante. SQLite conserve le texte tel qu'il a été posé : à texte égal,
     * l'ouverture n'écrit rien dans le schéma.
     */
    private static function poserDeclencheurs(): void
    {
        $lire = self::$pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?");
        foreach (self::DECLENCHEURS as $nom => $sql) {
            $lire->execute([$nom]);
            $enBase = $lire->fetchColumn();
            $lire->closeCursor();
            if ($enBase === $sql) {
                continue;
            }
            // Le verrou d'écriture d'emblée : différée, la transaction lisait le schéma puis
            // écrivait, et SQLite la refusait sans attendre dès qu'une autre connexion écrivait
            // — la première ouverture d'une base à mettre à jour tombait sous un site actif.
            self::$pdo->exec('BEGIN IMMEDIATE');
            try {
                self::$pdo->exec("DROP TRIGGER IF EXISTS $nom");
                self::$pdo->exec($sql);
                self::$pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                self::$pdo->exec('ROLLBACK');
                throw $e;
            }
        }
    }

    /**
     * Lâche la connexion. `reset-db` renomme le fichier : sans ce geste, la
     * console continuerait d'écrire dans la base figée.
     */
    public static function fermer(): void
    {
        self::$pdo = null;
        self::$generation = null;
    }
}
