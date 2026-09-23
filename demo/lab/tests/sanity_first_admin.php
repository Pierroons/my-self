#!/usr/bin/env php
<?php
/**
 * Contrôles de l'amorçage et du dernier administrateur — la console elle-même.
 *
 * `sanity_su.php` éprouve la bibliothèque du journal ; celui-ci lance
 * `selfrecover-su` en sous-processus, parce que les gardes vivent dans ses verbes
 * et dans la base : `first-admin` ne sert qu'une fois par cycle, le dernier admin
 * ne part pas, et seuls `reset-shell` et `reset-db` rouvrent l'amorçage.
 *
 * Chaque cas lit le code de sortie ET l'état de la base : un refus qui laisserait
 * la base modifiée n'est pas un refus.
 *
 * Usage : php tests/sanity_first_admin.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/su_audit.php';
require_once __DIR__ . '/../../../bi-self/selfrecover/src/autoload.php';

use Pierroons\MySelfLab\SuAudit;
use Pierroons\SelfRecover\Crypto\Hashing;

$echecs = 0; $reussites = 0;
function ok(string $m): void  { global $reussites; echo "  ✓ $m\n"; $reussites++; }
function nok(string $m): void { global $echecs; fwrite(STDERR, "  ✗ $m\n"); $echecs++; }

// ── Bac à sable ─────────────────────────────────────────────────────────────
$dir = sys_get_temp_dir() . '/sanity_first_admin_' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
$base       = "$dir/lab.db";
$passphrase = 'banc-passphrase-du-su-premier';
file_put_contents("$dir/su-secret", password_hash($passphrase, PASSWORD_ARGON2ID, Hashing::ARGON2));

register_shutdown_function(static function () use ($dir): void {
    foreach (glob("$dir/*") ?: [] as $f) {
        unlink($f);
    }
    @rmdir($dir);
});

$env = [
    'PATH'                         => getenv('PATH') ?: '/usr/bin:/bin',
    'LAB_DB_PATH'                  => $base,
    'SELFRECOVER_STATE_DIR'        => $dir,
    'SELFRECOVER_SU_AUDIT_SECRET'  => 'banc-first-admin-secret-fixe',
    'SELFRECOVER_SU_SECRET_INPUT'  => $passphrase,
    'SU_FORENSIC_MINIMAL'          => '1',
];
foreach ($env as $k => $v) {
    putenv("$k=$v");
}
putenv('SELFRECOVER_NTFY_URL');
putenv('SELFRECOVER_SU_AUDIT_LOG');

/** Lance la console ; rend [code, sortie]. `$en_plus` surcharge l'environnement. */
function su(array $args, array $en_plus = []): array
{
    global $env;
    $cmd = array_merge([PHP_BINARY, __DIR__ . '/../selfrecover-su'], $args);
    $p   = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuyaux, null, $en_plus + $env);
    $out = stream_get_contents($tuyaux[1]) . stream_get_contents($tuyaux[2]);
    fclose($tuyaux[1]);
    fclose($tuyaux[2]);

    return [proc_close($p), $out];
}

/** Une connexion neuve à chaque appel : `reset-db` remplace le fichier sous nos pieds. */
function base(?string $chemin = null): PDO
{
    global $base;
    $pdo = new PDO('sqlite:' . ($chemin ?? $base));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function est_admin(string $u): bool
{
    $st = base()->prepare('SELECT is_admin FROM accounts WHERE username = ?');
    $st->execute([$u]);

    return (int) $st->fetchColumn() === 1;
}

function nb_admins(): int
{
    return (int) base()->query('SELECT COUNT(*) FROM accounts WHERE is_admin = 1')->fetchColumn();
}

function inscrire(string ...$noms): void
{
    $st = base()->prepare(
        "INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at) VALUES (?, 'x', 'x', 'x', ?)"
    );
    foreach ($noms as $n) {
        $st->execute([$n, time()]);
    }
}

// La base du bac à sable n'est pas celle du site : la console lit `LAB_DB_PATH`
// en premier, et un banc qui écrirait dans la vraie base ne se rattrape pas.
$reelle = realpath(__DIR__ . '/../data/lab.db');
if ($reelle !== false && $reelle === realpath($base)) {
    fwrite(STDERR, "Le bac à sable pointe la base réelle — arrêt.\n");
    exit(1);
}

// La console crée la base (schéma + gardes) à sa première ouverture.
[$c] = su(['list-admins']);
$c === 0 && is_file($base)
    ? ok('la console ouvre la base du bac à sable')
    : nok("la console n'a pas ouvert la base du bac à sable (code $c)");
inscrire('alice', 'bob', 'carol', 'mallory', 'erin');

// ── 1. L'amorçage ───────────────────────────────────────────────────────────
[$c] = su(['first-admin', 'alice']);
$c === 0 && est_admin('alice')
    ? ok('first-admin alice : premier administrateur')
    : nok("first-admin alice devait passer (code $c)");

// ── 2. Il ne sert qu'une fois ───────────────────────────────────────────────
[$c, $o] = su(['first-admin', 'bob']);
$c !== 0 && !est_admin('bob') && str_contains($o, 'déjà nommé')
    ? ok('first-admin bob : refusé, la voie est close')
    : nok("un second first-admin devait être refusé (code $c)");

[$c] = su(['add-admin', 'bob']);
$c !== 0 && !est_admin('bob')
    ? ok('add-admin : ne promeut plus')
    : nok("add-admin ne doit plus promouvoir (code $c)");

// ── 3. Le dernier administrateur ne se révoque pas ──────────────────────────
[$c] = su(['revoke-admin', 'alice']);
$c === 6 && est_admin('alice')
    ? ok('revoke-admin du dernier admin : code 6, alice reste admin')
    : nok("le dernier admin devait être gardé (code $c, admin=" . var_export(est_admin('alice'), true) . ')');

// ── 4. La garde est dans la base, pas seulement dans la console ─────────────
try {
    base()->exec("UPDATE accounts SET is_admin = 0 WHERE username = 'alice'");
    nok('un UPDATE direct a retiré le dernier admin : la garde ne vit que dans la console');
} catch (PDOException $e) {
    str_contains($e->getMessage(), 'dernier administrateur') && est_admin('alice')
        ? ok('UPDATE direct du dernier admin : refusé par la base')
        : nok('refus inattendu : ' . $e->getMessage());
}
try {
    base()->exec("DELETE FROM accounts WHERE username = 'alice'");
    nok('un DELETE direct a supprimé le dernier admin');
} catch (PDOException $e) {
    str_contains($e->getMessage(), 'dernier administrateur')
        ? ok('DELETE direct du dernier admin : refusé par la base')
        : nok('refus inattendu : ' . $e->getMessage());
}

// ── 5. Le remplacement atomique ─────────────────────────────────────────────
[$c] = su(['revoke-admin', 'alice', '--remplacant', 'bob']);
$c === 0 && est_admin('bob') && !est_admin('alice')
    ? ok('revoke-admin alice --remplacant bob : bob admin, alice non')
    : nok("le remplacement devait passer (code $c)");

// ── 6. Contre-témoin : avec deux admins, la révocation passe ────────────────
// Sans lui, une garde qui refuserait toute révocation passerait aussi.
base()->prepare(
    "INSERT INTO admin_requests (requester_username, target_username, reason, created_at) VALUES ('bob', 'carol', 'banc', ?)"
)->execute([time()]);
[$c1] = su(['approve-request', '1', 'observation du banc']);
[$c2] = su(['revoke-admin', 'carol']);
$c1 === 0 && $c2 === 0 && !est_admin('carol') && est_admin('bob')
    ? ok('deux admins : la révocation de l\'un passe (la garde ne vise que le dernier)')
    : nok("contre-témoin : approve=$c1, revoke=$c2");

// ── 7. audit ne vide jamais la liste ────────────────────────────────────────
base()->exec("UPDATE accounts SET is_admin = 1 WHERE username = 'mallory'");
[$c] = su(['audit']);
$c === 4 && !est_admin('mallory') && est_admin('bob')
    ? ok('audit : un fantôme parmi des légitimes part en quarantaine')
    : nok("audit devait mettre mallory seule en quarantaine (code $c)");

[$c, $o] = su(['audit'], ['SELFRECOVER_SU_AUDIT_LOG' => "$dir/journal-vide.log"]);
$c === 7 && est_admin('bob')
    ? ok('audit face à un journal vide : code 7, aucune quarantaine')
    : nok("audit ne doit pas vider la liste des admins (code $c, bob admin=" . var_export(est_admin('bob'), true) . ')');

// ── 8. Zéro admin sans reset : la voie ne se rouvre pas ─────────────────────
$pdo = base();
$pdo->exec('UPDATE garde_admin SET reset_en_cours = 1');
$pdo->exec('UPDATE accounts SET is_admin = 0');
$pdo->exec('UPDATE garde_admin SET reset_en_cours = 0');
[$c] = su(['first-admin', 'erin']);
$c === 5 && !est_admin('erin')
    ? ok('base vidée hors reset : first-admin refuse (code 5)')
    : nok("first-admin ne doit pas se rouvrir sans reset (code $c)");
base()->exec("UPDATE accounts SET is_admin = 1 WHERE username = 'bob'");

// ── 9. reset-shell rouvre l'amorçage et garde les comptes ───────────────────
$comptes = (int) base()->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
[$c] = su(['reset-shell', '--confirm']);
$c === 0 && nb_admins() === 0 && (int) base()->query('SELECT COUNT(*) FROM accounts')->fetchColumn() === $comptes
    ? ok("reset-shell : zéro admin, les $comptes comptes restent")
    : nok("reset-shell devait révoquer les admins sans toucher aux comptes (code $c)");
[$c] = su(['first-admin', 'carol']);
$c === 0 && est_admin('carol')
    ? ok('après reset-shell : first-admin carol passe')
    : nok("first-admin devait être rouvert par reset-shell (code $c)");

// ── 10. reset-db : kill all, journal gardé ──────────────────────────────────
$avant = count(SuAudit::read());
[$c] = su(['reset-db']);
$c === 1 && (int) base()->query('SELECT COUNT(*) FROM accounts')->fetchColumn() === $comptes
    ? ok('reset-db sans --confirm : rien ne bouge')
    : nok("reset-db sans --confirm ne doit rien supprimer (code $c)");

[$c] = su(['reset-db', '--confirm']);
$figees = glob("$base.frozen-*") ?: [];
$c === 0 && (int) base()->query('SELECT COUNT(*) FROM accounts')->fetchColumn() === 0
    ? ok('reset-db --confirm : zéro compte')
    : nok("reset-db devait vider les comptes (code $c)");
count($figees) === 1 && (int) base($figees[0])->query('SELECT COUNT(*) FROM accounts')->fetchColumn() === $comptes
    ? ok('la base figée garde les ' . $comptes . ' comptes — pièce à conviction')
    : nok('base figée absente ou vide : ' . json_encode($figees));
!is_file("$dir/su-secret") && count(glob("$dir/su-secret.frozen-*") ?: []) === 1
    ? ok('le secret SU est figé')
    : nok('le secret SU devait être mis de côté');

[$c] = su(['list-admins']);
$c === 2
    ? ok('l\'ancienne passphrase SU ne vaut plus rien (code 2)')
    : nok("après reset-db, l'ancienne passphrase devait être refusée (code $c)");

$journal = SuAudit::read();
$derniere = end($journal);
count($journal) === $avant + 1 && ($derniere['action'] ?? '') === SuAudit::ACTION_RESET_DB
    ? ok("journal gardé : ses $avant entrées + l'entrée reset-db")
    : nok('le journal devait être gardé et porter le reset-db');
SuAudit::verify()['ok']
    ? ok('chaîne intègre après reset-db')
    : nok('chaîne rompue après reset-db : ' . json_encode(SuAudit::verify()));

// Un secret neuf, comme à l'installation, puis l'amorçage.
$neuve = 'banc-passphrase-neuve-apres-reset';
[$c] = su(['change-passphrase'], [
    'SELFRECOVER_SU_SECRET'       => 'secret-temporaire-d-installation',
    'SELFRECOVER_SU_SECRET_INPUT' => 'secret-temporaire-d-installation',
    'SELFRECOVER_SU_NEW_INPUT'    => $neuve,
]);
$c === 0 && is_file("$dir/su-secret")
    ? ok('change-passphrase repose un secret SU')
    : nok("change-passphrase devait reposer un secret (code $c)");
$env['SELFRECOVER_SU_SECRET_INPUT'] = $neuve;

inscrire('dave', 'carol');
[$c] = su(['first-admin', 'dave']);
$c === 0 && est_admin('dave')
    ? ok('après reset-db : first-admin dave passe, malgré le first-admin plus haut dans le même journal')
    : nok("first-admin devait être rouvert par reset-db (code $c)");

[$c, $o] = su(['show-log']);
$c === 0 && str_contains($o, 'RESET-DB — tous les comptes supprimés')
    ? ok('show-log affiche le reset-db en bandeau')
    : nok("show-log devait afficher le bandeau reset-db (code $c)");
[, $o] = su(['list-admins']);
str_contains($o, 'Dernier reset-db')
    ? ok('list-admins rappelle le dernier reset-db')
    : nok('list-admins devait rappeler le dernier reset-db');

// ── 11. Un octroi d'avant le reset ne vaut plus ─────────────────────────────
// carol a été nommée dans ce même journal, avant le reset-db : son octroi y est
// encore lisible, et c'est le reset qui doit l'annuler.
base()->exec("UPDATE accounts SET is_admin = 1 WHERE username = 'carol'");
[$c, $o] = su(['audit']);
$c === 4 && !est_admin('carol') && est_admin('dave')
    ? ok('carol, nommée avant le reset-db dans le même journal, revient en base : fantôme')
    : nok("un octroi antérieur au reset ne doit plus être légitime (code $c)");

echo "\n";
$total = $reussites + $echecs;
if ($echecs === 0) {
    echo "OK — $total/$total contrôles conformes.\n";
    exit(0);
}
fwrite(STDERR, "ÉCHEC — $echecs contrôle(s) sur $total.\n");
exit(1);
