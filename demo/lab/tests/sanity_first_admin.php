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

// La base et les quatre secrets de ce banc, à lui seul — jamais ceux de `data/`.
require __DIR__ . '/instance_isolee.inc';

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

// Les secrets de l'instance isolée suivent, sous les prises du banc qui l'emportent :
// sans eux, le sous-processus retombe sur `data/`.
$env = [
    'PATH'                         => getenv('PATH') ?: '/usr/bin:/bin',
    'LAB_DB_PATH'                  => $base,
    'SELFRECOVER_STATE_DIR'        => $dir,
    'SELFRECOVER_SU_AUDIT_SECRET'  => 'banc-first-admin-secret-fixe',
    'SELFRECOVER_SU_SECRET_INPUT'  => $passphrase,
    'SU_FORENSIC_MINIMAL'          => '1',
] + instance_isolee();
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

// ── 4 bis. Un déclencheur au corps périmé est réécrit à l'ouverture ─────────
// Une base créée par une version antérieure garde le corps qu'elle a reçu, tant
// que rien ne compare les textes.
$pdo = base();
$pdo->exec('DROP TRIGGER dernier_admin_update');
$pdo->exec('CREATE TRIGGER dernier_admin_update BEFORE UPDATE OF is_admin ON accounts WHEN 0 BEGIN SELECT 1; END');
su(['list-admins']);
try {
    base()->exec("UPDATE accounts SET is_admin = 0 WHERE username = 'alice'");
    nok("le déclencheur périmé est resté en place : le dernier admin est parti");
    base()->exec("UPDATE accounts SET is_admin = 1 WHERE username = 'alice'");
} catch (PDOException $e) {
    str_contains($e->getMessage(), 'dernier administrateur') && est_admin('alice')
        ? ok("déclencheur au corps périmé : réécrit à l'ouverture, la garde tient")
        : nok('refus inattendu : ' . $e->getMessage());
}
$avant = (int) base()->query('PRAGMA schema_version')->fetchColumn();
su(['list-admins']);
$apres = (int) base()->query('PRAGMA schema_version')->fetchColumn();
$avant === $apres
    ? ok("déclencheurs à jour : l'ouverture n'écrit rien dans le schéma")
    : nok("l'ouverture réécrit le schéma à chaque fois (schema_version $avant → $apres)");

// ── 5. Le remplacement atomique ─────────────────────────────────────────────
// Un journal qui refuse d'écrire : la base ne doit pas bouger non plus.
$journalMuet = "$dir/journal-dossier";
mkdir($journalMuet);
[$c] = su(['revoke-admin', 'alice', '--remplacant', 'bob'], ['SELFRECOVER_SU_AUDIT_LOG' => $journalMuet]);
rmdir($journalMuet);
$c !== 0 && est_admin('alice') && !est_admin('bob')
    ? ok('journal qui refuse : le remplacement échoue et la base revient en arrière')
    : nok("un remplacement que le journal refuse a changé la base (code $c)");

[$c] = su(['revoke-admin', 'alice', '--remplacant', 'bob']);
$c === 0 && est_admin('bob') && !est_admin('alice')
    ? ok('revoke-admin alice --remplacant bob : bob admin, alice non')
    : nok("le remplacement devait passer (code $c)");

// ── 6. Contre-témoin : avec deux admins, la révocation passe ────────────────
// Sans lui, une garde qui refuserait toute révocation passerait aussi.
base()->prepare(
    "INSERT INTO admin_requests (requester_username, target_username, reason, created_at) VALUES ('bob', 'carol', 'banc', ?)"
)->execute([time()]);

// Une base qui refuse : le journal ne doit rien dire.
$entrees = count(SuAudit::read());
base()->exec("CREATE TRIGGER banc_refus BEFORE UPDATE ON admin_requests BEGIN SELECT RAISE(ABORT, 'banc : la base refuse'); END");
[$c] = su(['approve-request', '1', 'observation du banc']);
base()->exec('DROP TRIGGER banc_refus');
$c !== 0 && !est_admin('carol') && count(SuAudit::read()) === $entrees
    ? ok("base qui refuse : l'approbation échoue et le journal ne l'inscrit pas")
    : nok("une approbation refusée par la base a été journalisée ou appliquée (code $c)");

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
// Un message chiffré sous la clé de cette base, avant le reset — dans un autre
// processus : la clé dérivée se mémoïse, et ce banc doit relire comme une requête neuve.
$lab = "$dir/lab.php";
file_put_contents($lab, '<?php
require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . ';
require_once ' . var_export(__DIR__ . '/../lib/dm.php', true) . ';
require_once ' . var_export(__DIR__ . '/../lib/profile.php', true) . ';
use Pierroons\MySelfLab\{Db, DataGuard, Dm, Profile};
$pdo = Db::pdo();
if ($argv[1] === "ouvre") {
    echo "ouverte";
    exit;
}
if ($argv[1] === "profil") {
    echo json_encode(Profile::save($pdo, 1, ["bio" => "PROFIL-SEUL", "localisation" => "", "lien" => ""]));
    exit;
}
if ($argv[1] === "graine") {
    Dm::send($pdo, 1, "bob", "SECRET-DE-LA-BASE-FIGEE");
    echo json_encode([Db::generation(), $pdo->query("SELECT ciphertext FROM dm ORDER BY id DESC LIMIT 1")->fetchColumn(),
        $pdo->query("SELECT graine FROM accounts WHERE id = 1")->fetchColumn(), $pdo->query("SELECT graine FROM accounts WHERE id = 2")->fetchColumn()]);
    exit;
}
// Le rejeu de qui écrit partout : la base neuve reçoit sa première requête, puis l\'écriture
// recopie l\'ancien numéro — avec l\'empreinte de sa trace, qu\'il recalcule —, les graines
// des deux anciens comptes et l\'ancien blob ; et le destinataire n° 2 lit.
DataGuard::exigerCle();
$pdo->exec("INSERT OR IGNORE INTO accounts (id, username, pw_hash, pass_hash, recovery_hash, created_at) VALUES (1, \'r1\', \'x\', \'x\', \'x\', 0), (2, \'r2\', \'x\', \'x\', \'x\', 0)");
$pdo->prepare("UPDATE lab_generation SET valeur = ?")->execute([$argv[2]]);
$pdo->prepare("UPDATE lab_migrations SET valeur = ? WHERE nom = \'numero-de-base\'")->execute([hash("sha256", $argv[2])]);
$pdo->prepare("UPDATE accounts SET graine = ? WHERE id = 1")->execute([$argv[4]]);
$pdo->prepare("UPDATE accounts SET graine = ? WHERE id = 2")->execute([$argv[5]]);
$pdo->prepare("INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (1, 2, ?, 0)")->execute([$argv[3]]);
Db::fermer();
echo Dm::inbox(Db::pdo(), 2)[0]["message"] ?? "(rien)";
');
/** Lance l'aide `lab.php` sur une base et une clé ; rend sa sortie. */
function lab(string $base, string $cle, string ...$args): string
{
    global $env, $lab;
    $p = proc_open(array_merge([PHP_BINARY, $lab], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuyaux, null,
        ['LAB_DB_PATH' => $base, 'LAB_BLINDKEY_PATH' => $cle] + $env);
    $out = stream_get_contents($tuyaux[1]) . stream_get_contents($tuyaux[2]);
    fclose($tuyaux[1]);
    fclose($tuyaux[2]);
    proc_close($p);

    return $out;
}
$cle = $env['LAB_BLINDKEY_PATH'];
[$numeroAncien, $blobAncien, $graine1, $graine2] = json_decode(lab($base, $cle, 'graine'), true) ?? [null, null, null, null];
touch("$cle.scelle");   // le témoin d'une passe de migration faite : il part aussi

// ── 10 a. La console ne crée jamais la base, et `--forcer` est un geste explicite ──
// Sur une instance à côté, son propre dossier d'état : chaque reset y fige le secret SU,
// que la suite du banc emploie.
$cote = chemin_isole('cote');
mkdir($cote, 0700);
$enCote = ['LAB_DB_PATH' => "$cote/lab.db", 'LAB_BLINDKEY_PATH' => "$cote/.blindkey", 'SELFRECOVER_STATE_DIR' => $cote];
$suCote = static fn (array $args, array $plus = []): array => su($args, $plus + $enCote);
/** Remet l'instance à côté à l'état voulu : un secret SU, une copie de la base du banc, sa clé et ses témoins. */
$cloner = static function (bool $avecBase, bool $avecCle) use ($cote, $base, $cle, $passphrase): void {
    foreach (glob("$cote/{,.}*", GLOB_BRACE) ?: [] as $f) {
        if (is_file($f)) {
            unlink($f);
        }
    }
    file_put_contents("$cote/su-secret", password_hash($passphrase, PASSWORD_ARGON2ID, Hashing::ARGON2));
    if ($avecBase) {
        copy($base, "$cote/lab.db");
    }
    if ($avecCle) {
        copy($cle, "$cote/.blindkey");
        touch("$cote/.blindkey.existe");
    }
};

// Une console qui ne voit ni base ni clé — lancée sans les prises du site — fabriquait une
// base au chemin qu'elle voyait, la figeait, et annonçait un succès : la base compromise
// restait servie.
$cloner(false, false);
[$c, $o] = $suCote(['reset-db', '--confirm', '--forcer']);
$c !== 0 && str_contains($o, 'ni base') && !is_file("$cote/lab.db") && glob("$cote/*.frozen-*") === []
    ? ok('⭐ une console qui ne voit ni base ni clé refuse, même forcée, et ne crée rien')
    : nok("sans base ni clé, reset-db devait refuser sans rien créer (code $c) : " . trim(substr($o, 0, 200)));

// Base perdue, clé présente : le remède nommé par le refus du site mourait sur ce refus.
$cloner(false, true);
[$c, $o] = $suCote(['reset-db', '--confirm']);
$c === 1 && str_contains($o, 'absente ou vide') && str_contains($o, '--forcer') && is_file("$cote/.blindkey")
    ? ok('⭐ base absente sous une clé qui a servi : refus nommé, qui dit le geste, et rien de figé')
    : nok("base absente : reset-db devait refuser en nommant --forcer (code $c) : " . trim(substr($o, 0, 200)));
[$c, $o] = $suCote(['reset-db', '--confirm', '--forcer']);
$c === 0 && !is_file("$cote/.blindkey") && !is_file("$cote/.blindkey.existe")
    && count(glob("$cote/.blindkey.frozen-*") ?: []) === 1 && !is_file("$cote/lab.db")
    ? ok('⭐ --forcer fige la clé et son témoin, et ne crée pas de base')
    : nok("reset-db --forcer devait figer la clé sans créer de base (code $c) : " . trim(substr($o, 0, 200)));
$ouverte = lab("$cote/lab.db", "$cote/.blindkey", 'ouvre');
str_contains($ouverte, 'ouverte') && is_file("$cote/lab.db")
    ? ok('après --forcer, la requête suivante du site fait naître la base')
    : nok('le site devait recréer la base après --forcer : ' . trim(substr($ouverte, 0, 200)));

// Numéro et trace réécrits par qui écrit : la vérification échoue, et `--forcer` passe outre.
$cloner(true, true);
$numeroFaux = bin2hex(random_bytes(16));
base("$cote/lab.db")->prepare('UPDATE lab_generation SET valeur = ?')->execute([$numeroFaux]);
base("$cote/lab.db")->prepare("UPDATE lab_migrations SET valeur = ? WHERE nom = 'numero-de-base'")
    ->execute([hash('sha256', $numeroFaux)]);
[$c, $o] = $suCote(['reset-db', '--confirm']);
$c === 1 && str_contains($o, '--forcer') && glob("$cote/*.frozen-*") === [] && glob("$cote/.*.frozen-*") === []
    ? ok('une base dont le numéro est réécrit fait refuser reset-db, qui nomme le passage forcé')
    : nok("numéro réécrit : reset-db devait refuser et nommer --forcer (code $c) : " . trim(substr($o, 0, 200)));
[$c, $o] = $suCote(['reset-db', '--confirm', '--forcer']);
$c === 0 && count(glob("$cote/lab.db.frozen-*") ?: []) === 1 && count(glob("$cote/.blindkey.frozen-*") ?: []) === 1
    && !is_file("$cote/lab.db") && str_contains($o, "n'est pas vérifiée")
    ? ok('⭐ et --forcer fige la base et la clé, en disant qu\'elle n\'est pas vérifiée : qui écrit ne bloque plus le remède')
    : nok("reset-db --forcer devait figer base et clé (code $c) : " . trim(substr($o, 0, 200)));

// Une base dont les membres n'ont rempli que leur profil : la vérification essaie aussi les profils.
$cloner(true, true);
$profil = lab("$cote/lab.db", "$cote/.blindkey", 'profil');
base("$cote/lab.db")->exec('DELETE FROM dm');
file_put_contents("$cote/.blindkey-etrangere", bin2hex(random_bytes(48)));
[$c, $o] = $suCote(['reset-db', '--confirm'], ['LAB_BLINDKEY_PATH' => "$cote/.blindkey-etrangere"]);
str_contains($profil, '"ok":true') && $c !== 0 && str_contains($o, "ne s'ouvre sous la clé")
    ? ok('⭐ une base qui ne porte qu\'un profil, sous une clé étrangère : refus')
    : nok("profil seul sous une clé étrangère : reset-db devait refuser (code $c) : " . trim(substr($profil . ' | ' . $o, 0, 220)));

// Une clé que la console ne peut pas lire se dit illisible, pas étrangère.
$cloner(true, true);
chmod("$cote/.blindkey", 0000);
[$c, $o] = $suCote(['reset-db', '--confirm']);
chmod("$cote/.blindkey", 0600);
$c !== 0 && str_contains($o, 'illisible')
    ? ok('une clé illisible pour la console se dit illisible, et rien ne bouge')
    : nok("clé illisible : le refus devait le dire (code $c) : " . trim(substr($o, 0, 200)));

// Une base scellée dont les chiffrés essayés ne portent pas de liaison — abîmés, ou d'avant la
// passe : sous une lecture fermée, aucun ne peut confirmer la clé, et le refus le dit.
$cloner(true, true);
touch("$cote/.blindkey.scelle");
base("$cote/lab.db")->exec("UPDATE dm SET ciphertext = 'AAAA' || ciphertext; DELETE FROM profiles");
[$c, $o] = $suCote(['reset-db', '--confirm']);
$c === 1 && str_contains($o, 'ne portent pas de liaison') && glob("$cote/*.frozen-*") === []
    ? ok('des chiffrés sans liaison sous une lecture fermée : refus qui le dit, rien de figé')
    : nok("chiffrés sans liaison : reset-db devait refuser en le disant (code $c) : " . trim(substr($o, 0, 200)));

// Une base que SQLite n'ouvre pas : un refus qui le dit, et `--forcer` la fige telle quelle.
$cloner(false, true);
file_put_contents("$cote/lab.db", random_bytes(4096));
[$c, $o] = $suCote(['reset-db', '--confirm']);
$c === 1 && str_contains($o, "ne s'ouvre pas") && is_file("$cote/lab.db") && is_file("$cote/.blindkey")
    ? ok('une base que SQLite n\'ouvre pas fait refuser reset-db, en le disant')
    : nok("base illisible : reset-db devait refuser en le disant (code $c) : " . trim(substr($o, 0, 200)));
[$c, $o] = $suCote(['reset-db', '--confirm', '--forcer']);
$c === 0 && count(glob("$cote/lab.db.frozen-*") ?: []) === 1 && count(glob("$cote/.blindkey.frozen-*") ?: []) === 1 && !is_file("$cote/lab.db")
    ? ok('et --forcer la fige telle quelle, avec sa clé')
    : nok("base illisible : reset-db --forcer devait la figer (code $c) : " . trim(substr($o, 0, 200)));

// Une base sans aucun chiffré, une console sous une clé étrangère : elle passe, mais ne
// recrée plus la base suivante sous la clé du site — c'est le site qui refuse, à voix haute.
$cloner(true, true);
base("$cote/lab.db")->exec('DELETE FROM dm; DELETE FROM profiles');
[$c, $o] = $suCote(['reset-db', '--confirm'], ['LAB_BLINDKEY_PATH' => "$cote/.blindkey-etrangere"]);
$apres = lab("$cote/lab.db", "$cote/.blindkey", 'ouvre');
$c === 0 && !is_file("$cote/lab.db") && str_contains($apres, 'absente ou vide')
    ? ok('⭐ une console mal prise ne fait plus naître la base suivante sous la clé du site : le site refuse')
    : nok("reset-db sous une clé étrangère ne devait pas recréer la base (code $c) : " . trim(substr($o . ' | ' . $apres, 0, 220)));

// 🔑 Le reset ne fige que la clé de CETTE base. Une console qui ne porte pas la prise
// du site la cherchait ailleurs, ne la trouvait pas, et répondait « rien n'avait été
// chiffré » : la clé du site restait (mesuré par la neuvième passe d'audit).
$comptesAvant = (int) base()->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
mkdir(chemin_isole('ailleurs'), 0700);
[$c, $o] = su(['reset-db', '--confirm'], ['LAB_BLINDKEY_PATH' => chemin_isole('ailleurs/.blindkey')]);
$c !== 0 && str_contains($o, 'aucune clé') && is_file($cle) && glob("$base.frozen-*") === []
    && (int) base()->query('SELECT COUNT(*) FROM accounts')->fetchColumn() === $comptesAvant
    ? ok('⭐ une console qui ne voit pas la clé du site refuse le reset, et rien ne bouge')
    : nok("sans la clé du site, reset-db devait refuser sans rien figer (code $c) : " . trim(substr($o, 0, 200)));
mkdir(chemin_isole('etrangere'), 0700);
file_put_contents(chemin_isole('etrangere/.blindkey'), bin2hex(random_bytes(48)));
[$c, $o] = su(['reset-db', '--confirm'], ['LAB_BLINDKEY_PATH' => chemin_isole('etrangere/.blindkey')]);
$c !== 0 && str_contains($o, "ne s'ouvre sous la clé") && is_file($cle) && glob("$base.frozen-*") === []
    ? ok('une clé valide mais étrangère à la base : refus aussi, rien de figé')
    : nok("une clé étrangère devait faire refuser reset-db (code $c) : " . trim(substr($o, 0, 200)));

$avant = count(SuAudit::read());
[$c] = su(['reset-db']);
$c === 1 && (int) base()->query('SELECT COUNT(*) FROM accounts')->fetchColumn() === $comptes
    ? ok('reset-db sans --confirm : rien ne bouge')
    : nok("reset-db sans --confirm ne doit rien supprimer (code $c)");

[$c] = su(['reset-db', '--confirm']);
$figees = glob("$base.frozen-*") ?: [];
$c === 0 && !is_file($base)
    ? ok('reset-db --confirm : la base part, et la console n\'en recrée aucune')
    : nok("reset-db devait figer la base sans en recréer (code $c)");
$neuveOuverte = lab($base, $cle, 'ouvre');
str_contains($neuveOuverte, 'ouverte') && (int) base()->query('SELECT COUNT(*) FROM accounts')->fetchColumn() === 0
    ? ok('la requête suivante du site fait naître une base sans compte')
    : nok('le site devait faire naître la base neuve : ' . trim(substr($neuveOuverte, 0, 200)));
count($figees) === 1 && (int) base($figees[0])->query('SELECT COUNT(*) FROM accounts')->fetchColumn() === $comptes
    ? ok('la base figée garde les ' . $comptes . ' comptes — pièce à conviction')
    : nok('base figée absente ou vide : ' . json_encode($figees));
!is_file("$dir/su-secret") && count(glob("$dir/su-secret.frozen-*") ?: []) === 1
    ? ok('le secret SU est figé')
    : nok('le secret SU devait être mis de côté');

$quand = $figees === [] ? '?' : substr($figees[0], strlen("$base.frozen-"));
$cleFigee = "$cle.frozen-$quand";
is_string($blobAncien) && !is_file($cle) && !is_file("$cle.existe") && !is_file("$cle.scelle")
    && is_file($cleFigee) && is_file("$cle.existe.frozen-$quand") && is_file("$cle.scelle.frozen-$quand")
    ? ok('la clé des messages est figée avec la base, sous son horodatage, ses deux témoins compris')
    : nok('la clé et ses témoins devaient partir avec la base : ' . json_encode(array_map('basename', glob(dirname($cle) . '/.blindkey*') ?: [])));

// La base neuve se copie : le rejeu ne doit rien laisser dans celle que la suite emploie.
copy($base, "$dir/rejeu.db");
$lu = lab("$dir/rejeu.db", $cle, 'rejeu', (string) $numeroAncien, (string) $blobAncien, (string) $graine1, (string) $graine2);
!str_contains($lu, 'SECRET-DE-LA-BASE-FIGEE') && str_contains($lu, 'illisible')
    ? ok('⭐ le message de la base figée, replanté avec son numéro dans la base neuve, ne s\'ouvre pas')
    : nok('après reset-db, l\'ancien blob et l\'ancien numéro replantés devaient rester illisibles : ' . trim($lu));
copy($base, "$dir/rejeu-temoin.db");
str_contains(lab("$dir/rejeu-temoin.db", $cleFigee, 'rejeu', (string) $numeroAncien, (string) $blobAncien, (string) $graine1, (string) $graine2), 'SECRET-DE-LA-BASE-FIGEE')
    ? ok('contre-témoin : sous la clé figée, le même rejeu rend le clair — seule la rotation l\'arrête')
    : nok('contre-témoin : sous la clé figée, le rejeu devait rendre le clair, sinon le cas ne mesure rien');

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
$neuve = 'habitat igloo jackal kennel ladder oasis paddle';
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

// ── 10 bis. Une prise du secret SU posée mais vide est refusée ─────────────
// Retomber en silence sur le dossier d'état ferait lire un autre secret que celui
// que l'exploitant a désigné. ⚠️ Par `env` : `proc_open` ne transmet pas une variable
// vide, et le cas aurait mesuré une variable absente (mesuré).
$p = proc_open(['env', 'SELFRECOVER_SU_SECRET_FILE=', PHP_BINARY, __DIR__ . '/../selfrecover-su', 'list-admins'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuyaux, null, $env);
$o = stream_get_contents($tuyaux[1]) . stream_get_contents($tuyaux[2]);
fclose($tuyaux[1]);
fclose($tuyaux[2]);
$c = proc_close($p);
$c !== 0 && str_contains($o, 'SELFRECOVER_SU_SECRET_FILE est posée mais vide')
    ? ok('SELFRECOVER_SU_SECRET_FILE posée mais vide : la console refuse')
    : nok("SELFRECOVER_SU_SECRET_FILE vide devait être refusée (code $c) : " . trim(substr($o, 0, 200)));

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
