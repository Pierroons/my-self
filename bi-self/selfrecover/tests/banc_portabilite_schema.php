<?php

/**
 * Le schéma passe-t-il sur le moteur visé, et la liste de ses écarts est-elle exacte ?
 *
 * 🔑 **L'invariant que ce banc fixe.** `schema.sql` est écrit pour SQLite et
 * documente, en tête, les constructions qu'un autre moteur refuse. Cette liste
 * est de la prose : elle vieillit quand le schéma gagne une table, et rien ne le
 * dit. Le banc la confronte au moteur — chaque écart annoncé doit vraiment
 * casser, et aucune autre instruction ne doit casser.
 *
 * ⚠️ **Ce qu'il n'éprouve pas.** Les quatre écarts de l'ADAPTATEUR (`INSERT OR
 * REPLACE`, `ON CONFLICT … excluded`, `LIMIT ?` paramétré, `INSERT … SELECT …
 * WHERE NOT EXISTS` sans `FROM`) demandent d'exécuter des requêtes applicatives,
 * pas de poser un schéma. Ils restent hors de ce banc, et son verdict ne dit
 * rien d'eux.
 *
 * 🔑 **Sur un moteur dont l'attendu n'est pas déclaré, il ROUGIT en rendant la
 * liste des refus.** Un vert dont personne ne connaît la portée est ce que ce
 * dépôt combat ailleurs ; ici, le premier passage sur un moteur neuf est une
 * mesure, pas un succès. L'attendu s'inscrit ensuite dans ECARTS_ATTENDUS, et le
 * banc devient un garde sans changer d'une ligne.
 *
 * Usage  : php tests/banc_portabilite_schema.php
 *          BANC_DSN='mysql:host=127.0.0.1;dbname=sr_portabilite' \
 *          BANC_USER=… BANC_PASS=… php tests/banc_portabilite_schema.php
 * Sortie : « OK — N/N » et 0 si le moteur se comporte comme annoncé, 1 sinon.
 */

declare(strict_types=1);

$module = dirname(__DIR__);
$schema = $module . '/schema.sql';

$dsn  = getenv('BANC_DSN') ?: 'sqlite::memory:';
$user = getenv('BANC_USER') ?: null;
$pass = getenv('BANC_PASS') ?: null;

/**
 * Les écarts annoncés par `schema.sql`, par moteur.
 *
 * La clé est le moteur tel que ce banc le nomme ; la valeur, le nombre
 * d'instructions du schéma que le moteur doit refuser. Un moteur absent de cette
 * table n'a pas d'attendu : le banc rougit et rend ce qu'il a mesuré.
 */
const ECARTS_ATTENDUS = [
    'sqlite' => 0,
];

/** Découpe le schéma en instructions, commentaires de ligne retirés. */
function instructions(string $chemin): array
{
    $brut = file_get_contents($chemin);
    if ($brut === false) {
        throw new RuntimeException("schéma illisible : $chemin");
    }
    $sans = [];
    foreach (explode("\n", $brut) as $ligne) {
        if (!str_starts_with(ltrim($ligne), '--')) {
            $sans[] = $ligne;
        }
    }
    $inst = [];
    foreach (explode(';', implode("\n", $sans)) as $i) {
        $i = trim($i);
        if ($i !== '') {
            $inst[] = $i;
        }
    }
    return $inst;
}

/** Le moteur, nommé par ce qu'il répond — pas par ce que le DSN annonce. */
function moteur(PDO $pdo): string
{
    $pilote = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($pilote !== 'mysql') {
        return $pilote;
    }
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    return str_contains($version, 'MariaDB') ? 'mariadb' : 'mysql';
}

$passes = 0;
$echecs = 0;

function verifier(string $quoi, bool $vrai, string $detail = ''): void
{
    global $passes, $echecs;
    if ($vrai) {
        $passes++;
        echo "  ✓ $quoi\n";
        return;
    }
    $echecs++;
    echo "  ✗ $quoi" . ($detail !== '' ? " — $detail" : '') . "\n";
}

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fwrite(STDERR, "✗ connexion refusée sur « $dsn » : " . $e->getMessage() . "\n");
    exit(1);
}

$m    = moteur($pdo);
$inst = instructions($schema);

echo "\n▸ Portabilité du schéma — moteur « $m », " . count($inst) . " instruction(s)\n\n";

$refus = [];
foreach ($inst as $n => $i) {
    try {
        $pdo->exec($i);
    } catch (PDOException $e) {
        $tete = trim(substr(preg_replace('/\s+/', ' ', $i), 0, 68));
        $refus[] = ['n' => $n + 1, 'tete' => $tete, 'message' => $e->getMessage()];
    }
}

if ($refus !== []) {
    echo "  Instructions refusées par « $m » :\n";
    foreach ($refus as $r) {
        echo sprintf("    %2d. %s\n        → %s\n", $r['n'], $r['tete'],
            trim(substr(preg_replace('/\s+/', ' ', $r['message']), 0, 120)));
    }
    echo "\n";
}

if (!array_key_exists($m, ECARTS_ATTENDUS)) {
    echo "✗ aucun écart attendu n'est déclaré pour « $m » : ce passage MESURE, il ne garde rien.\n";
    echo "  " . count($refus) . " instruction(s) refusée(s) — à confronter à la liste de `schema.sql:48-56`,\n";
    echo "  puis à inscrire dans ECARTS_ATTENDUS pour que ce banc devienne un garde.\n";
    exit(1);
}

$attendu = ECARTS_ATTENDUS[$m];
verifier(
    "« $m » refuse exactement les $attendu instruction(s) annoncée(s)",
    count($refus) === $attendu,
    count($refus) . ' mesurée(s) — la liste de `schema.sql` est ' .
        (count($refus) > $attendu ? 'incomplète' : 'pessimiste')
);

if ($attendu === 0 && $refus === []) {
    // Le schéma est posé : les contraintes qu'il déclare se vérifient ici, parce
    // qu'une table créée ne dit pas encore qu'elle refuse ce qu'elle doit refuser.
    $pdo->exec('PRAGMA foreign_keys = ON');
    $tables = $pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
    )->fetchColumn();
    verifier('les 9 tables du schéma existent après exécution', (int) $tables === 9, "$tables trouvée(s)");

    $uniques = $pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND sql LIKE '%UNIQUE%'"
    )->fetchColumn();
    verifier('les 2 index uniques déclarés sont posés', (int) $uniques === 2, "$uniques trouvé(s)");

    // 🔑 Les colonnes ne sont pas écrites ici : elles sont demandées à la base que
    // ce banc vient de créer. Un schéma qui gagne une colonne obligatoire ne
    // périme donc pas ce contrôle — et l'alternative, les nommer en dur, a cassé
    // la première version de ce fichier parce que je les avais supposées.
    $obligatoires = [];
    foreach ($pdo->query('PRAGMA table_info(accounts)') as $col) {
        if ((int) $col['notnull'] === 1 && $col['dflt_value'] === null && (int) $col['pk'] === 0) {
            $obligatoires[] = $col['name'];
        }
    }
    verifier('les colonnes obligatoires de `accounts` se lisent dans la base', $obligatoires !== []);

    $champs  = implode(', ', $obligatoires);
    $valeurs = implode(', ', array_map(
        static fn (string $c): string => $c === 'username' ? "'memeNom'" : "'x'",
        $obligatoires
    ));
    $insert = "INSERT INTO accounts ($champs) VALUES ($valeurs)";

    $pdo->exec($insert);
    $doublon = false;
    try {
        $pdo->exec($insert);
    } catch (PDOException) {
        $doublon = true;
    }
    verifier('⭐ un second compte du même nom est refusé par le moteur', $doublon);

    $orphelin = false;
    try {
        $pdo->exec("INSERT INTO l3_gel (account_id, gele_jusqu_a, pose_le) VALUES (9999, 1, 1)");
    } catch (PDOException) {
        $orphelin = true;
    }
    verifier('⭐ une clé étrangère vers un compte absent est refusée', $orphelin);
}

$total = $passes + $echecs;
echo "\n" . ($echecs === 0
    ? "OK — $passes/$total contrôles conformes.\n"
    : "✗ $echecs/$total contrôle(s) en échec.\n");
exit($echecs === 0 ? 0 : 1);
