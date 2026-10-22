#!/usr/bin/env php
<?php
/**
 * Deux requêtes qui écrivent en même temps ne se perdent pas l'une l'autre.
 *
 * 🔑 Une lecture d'une ligne garde son curseur, donc sa transaction de lecture, tant que la
 * requête vit. L'envoi d'un message lisait son destinataire, dérivait la clé, puis écrivait ;
 * la connexion lisait le hachage, le vérifiait, puis écrivait. Deux requêtes simultanées
 * tenaient chacune sa lecture ouverte, et SQLite refusait l'écriture de la seconde sans
 * attendre, pour ne pas s'interbloquer : un message perdu en 500 par paire d'envois, une
 * connexion par paire de connexions.
 *
 * Chaque manche lance deux processus neufs, qui attendent le même signal puis écrivent.
 * Le contre-témoin tient exprès sa lecture ouverte : il doit perdre des envois, sans quoi le
 * montage ne provoque aucune collision et les deux cas suivants ne prouvent rien.
 *
 * Usage : php tests/sanity_ecritures_simultanees.php
 */

declare(strict_types=1);

// La base et les quatre secrets de ce banc, à lui seul — jamais ceux de `data/`.
require __DIR__ . '/instance_isolee.inc';
$prises = instance_isolee();

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/dm.php';

use Pierroons\MySelfLab\{Db, DM};

$reussites = 0;
$echecs = 0;
function verifier(string $intitule, bool $condition, string $detail = ''): void
{
    global $reussites, $echecs;
    $condition ? $reussites++ : $echecs++;
    echo ($condition ? '  ✓ ' : '  ✗ ') . $intitule . ($detail !== '' ? " — $detail" : '') . "\n";
}

const MANCHES = 5;
const MOT_DE_PASSE = 'mot-de-passe-du-banc';

$pdo = Db::pdo();
$hache = password_hash(MOT_DE_PASSE, PASSWORD_ARGON2ID);
$ins = $pdo->prepare('INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at) VALUES (?, ?, ?, ?, ?)');
foreach (['alice', 'bob', 'carol', 'dave'] as $nom) {
    $ins->execute([$nom, $hache, 'x', 'x', time()]);
}
// La clé naît au premier envoi : hors des manches, pour qu'aucune ne mesure sa naissance.
DM::send($pdo, 1, 'bob', 'premier message');
Db::fermer();

$travailleur = chemin_isole('travailleur.php');
file_put_contents($travailleur, '<?php
require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . ';
require_once ' . var_export(__DIR__ . '/../lib/db.php', true) . ';
require_once ' . var_export(__DIR__ . '/../lib/dm.php', true) . ';
require_once ' . var_export(__DIR__ . '/../lib/auth.php', true) . ';
use Pierroons\MySelfLab\{Auth, Db, DM};
[, $signal, $pret, $geste, $qui, $vers] = $argv;
$pdo = Db::pdo();
touch($pret);
while (!is_file($signal)) {
    usleep(200);
}
try {
    if ($geste === "tenu") {
        $st = $pdo->prepare("SELECT id FROM accounts WHERE username = ?");
        $st->execute([$vers]);
        $id = (int) $st->fetchColumn();
        usleep(100000);
        $pdo->prepare("INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)")
            ->execute([(int) $qui, $id, "SDG3.contre-temoin", time()]);
        $r = ["ok" => true];
    } elseif ($geste === "dm") {
        $r = DM::send($pdo, (int) $qui, $vers, "message simultané");
    } else {
        $r = Auth::login($pdo, $qui, ' . var_export(MOT_DE_PASSE, true) . ', $vers);
    }
    echo ($r["ok"] ?? false) ? "OK" : "REFUS " . json_encode($r);
} catch (\Throwable $e) {
    echo "ERREUR " . $e->getMessage();
}
');

/**
 * Joue une manche : deux processus, un même signal ; rend leurs deux sorties.
 *
 * @param list<array{string, string, string}> $gestes geste, qui, vers
 * @return list<string>
 */
function manche(string $travailleur, array $prises, array $gestes): array
{
    $signal = chemin_isole('signal-' . bin2hex(random_bytes(4)));
    $env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'] + $prises;
    $procs = [];
    foreach ($gestes as $k => [$geste, $qui, $vers]) {
        $pret = "$signal.pret$k";
        $p = proc_open([PHP_BINARY, $travailleur, $signal, $pret, $geste, $qui, $vers], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuyaux, null, $env);
        $procs[] = [$p, $tuyaux, $pret];
    }
    for ($i = 0; $i < 1000 && array_filter($procs, static fn ($x) => !is_file($x[2])) !== []; $i++) {
        usleep(5000);
    }
    touch($signal);
    $sorties = [];
    foreach ($procs as [$p, $tuyaux]) {
        $sorties[] = trim(stream_get_contents($tuyaux[1]) . stream_get_contents($tuyaux[2]));
        fclose($tuyaux[1]);
        fclose($tuyaux[2]);
        proc_close($p);
    }

    return $sorties;
}

/** @return array{int, list<string>} le nombre de « OK », et les autres sorties */
function bilan(array $sorties): array
{
    $autres = array_values(array_filter($sorties, static fn (string $s): bool => $s !== 'OK'));

    return [count($sorties) - count($autres), $autres];
}

echo "# Le montage provoque bien la collision\n";
$sorties = [];
for ($m = 0; $m < 3; $m++) {
    $sorties = array_merge($sorties, manche($travailleur, $prises, [['tenu', '1', 'bob'], ['tenu', '3', 'dave']]));
}
[$ok, $autres] = bilan($sorties);
verifier(
    'contre-témoin : deux écritures qui tiennent leur lecture ouverte en perdent au moins une',
    $ok < count($sorties) && str_contains(implode(' ', $autres), 'locked'),
    "$ok/" . count($sorties) . ' passées'
);

echo "\n# Deux écritures simultanées, toutes rangées\n";
$avant = (int) Db::lectureSeule()->query('SELECT COUNT(*) FROM dm')->fetchColumn();
$sorties = [];
for ($m = 0; $m < MANCHES; $m++) {
    $sorties = array_merge($sorties, manche($travailleur, $prises, [['dm', '1', 'bob'], ['dm', '3', 'dave']]));
}
[$ok, $autres] = bilan($sorties);
verifier(
    '⭐ deux envois de messages simultanés : aucun n\'est perdu',
    $ok === count($sorties),
    "$ok/" . count($sorties) . ($autres !== [] ? ' · ' . substr($autres[0], 0, 160) : '')
);
$apres = (int) Db::lectureSeule()->query('SELECT COUNT(*) FROM dm')->fetchColumn();
verifier('chaque envoi réussi est en base', $apres - $avant === $ok, ($apres - $avant) . " ligne(s) pour $ok envoi(s)");

$sorties = [];
for ($m = 0; $m < MANCHES; $m++) {
    $sorties = array_merge($sorties, manche($travailleur, $prises, [['login', 'alice', '192.0.2.10'], ['login', 'carol', '192.0.2.11']]));
}
[$ok, $autres] = bilan($sorties);
verifier(
    '⭐ deux connexions simultanées sur deux comptes : aucune n\'est perdue',
    $ok === count($sorties),
    "$ok/" . count($sorties) . ($autres !== [] ? ' · ' . substr($autres[0], 0, 160) : '')
);

$total = $reussites + $echecs;
echo "\n" . ($echecs === 0 ? "OK — $reussites/$total contrôles conformes." : "ÉCHEC — $echecs sur $total") . "\n";
exit($echecs === 0 ? 0 : 1);
