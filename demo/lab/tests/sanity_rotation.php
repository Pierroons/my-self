#!/usr/bin/env php
<?php
/**
 * Contrôles de `rotate-audit-key` — la console elle-même, en sous-processus.
 *
 * La rotation re-signe le journal : seul `hmac` change, `entry_hash` et
 * `prev_hash` restent. Le cas qui compte est l'entrée forgée par qui n'a pas la
 * clé — un `entry_hash` juste, un HMAC faux. Re-signée sans être d'abord
 * vérifiée sous l'ancienne clé, elle deviendrait authentique sous la nouvelle.
 *
 * Chaque refus vérifie le code de sortie ET que le journal n'a pas bougé.
 *
 * Usage : php tests/sanity_rotation.php
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
$dir = sys_get_temp_dir() . '/sanity_rotation_' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
$base       = "$dir/lab.db";
$journal    = "$dir/su-audit.log";
$passphrase = 'banc-passphrase-du-su-rotation';
$ancienne   = 'banc-rotation-ancienne-cle-0123456789';
$nouvelle   = bin2hex(random_bytes(32));
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
    'SELFRECOVER_SU_AUDIT_SECRET'  => $ancienne,
    'SELFRECOVER_SU_SECRET_INPUT'  => $passphrase,
    'SU_FORENSIC_MINIMAL'          => '1',
];
foreach ($env as $k => $v) {
    putenv("$k=$v");
}
putenv('SELFRECOVER_NTFY_URL');
putenv('SELFRECOVER_SU_AUDIT_LOG');

$reelle = realpath(__DIR__ . '/../data/lab.db');
if ($reelle !== false && $reelle === realpath($base)) {
    fwrite(STDERR, "Le bac à sable pointe la base réelle — arrêt.\n");
    exit(1);
}

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

function cle(string $nom, string $valeur, int $mode = 0600): string
{
    global $dir;
    $f = "$dir/$nom";
    file_put_contents($f, $valeur . "\n");
    chmod($f, $mode);

    return $f;
}

function entrees(string $chemin): array
{
    return SuAudit::decoder(file($chemin, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
}

/** Un refus : le code attendu, et le journal octet pour octet inchangé. */
function refus(string $cas, array $args, int $attendu): void
{
    global $journal;
    $avant = hash_file('sha256', $journal);
    [$c, $out] = su($args);
    $intact = hash_file('sha256', $journal) === $avant && glob("$journal.rotation-*") === [];
    ($c === $attendu && $intact)
        ? ok("$cas → refusé (code $c), journal intact")
        : nok("$cas : code $c (attendu $attendu), journal " . ($intact ? 'intact' : 'MODIFIÉ') . " — " . trim($out));
}

// ── Un journal qui vit : sceau, puis un admin ──────────────────────────────
su(['list-admins']);
$pdo = new PDO("sqlite:$base");
$pdo->prepare("INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at) VALUES ('alice', 'x', 'x', 'x', ?)")
    ->execute([time()]);
$pdo = null;
[$c1] = su(['record-seal']);
[$c2] = su(['first-admin', 'alice']);
($c1 === 0 && $c2 === 0 && count(entrees($journal)) === 2)
    ? ok('journal de départ : sceau + first-admin, 2 entrées')
    : nok("mise en place du journal (codes $c1/$c2)");

// ── Les refus ───────────────────────────────────────────────────────────────
refus('sans --nouvelle-cle', ['rotate-audit-key'], 1);
refus('clé lisible par le groupe (0640)', ['rotate-audit-key', '--nouvelle-cle', cle('k-640', $nouvelle, 0640)], 1);
refus('clé de 16 caractères', ['rotate-audit-key', '--nouvelle-cle', cle('k-courte', 'trop-courte-16ch')], 1);
refus('valeur de démonstration', ['rotate-audit-key', '--nouvelle-cle', cle('k-demo', SuAudit::DEMO_SECRET)], 1);
refus('clé identique à celle en place', ['rotate-audit-key', '--nouvelle-cle', cle('k-meme', $ancienne)], 1);

$bonne = cle('k-neuve', $nouvelle);
$sauve = file_get_contents($journal);

// Le cas qui compte : une entrée forgée sans la clé. Chaînage juste, HMAC faux.
$es    = entrees($journal);
$der   = $es[count($es) - 1];
$forge = [
    'seq' => $der['seq'] + 1, 'ts_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'ts_paris' => date('Y-m-d H:i:s'),
    'action' => SuAudit::ACTION_FIRST_ADMIN, 'target' => 'intrus', 'extra' => [],
    'forensic' => ['mode' => 'minimal'], 'prev_hash' => $der['entry_hash'],
];
$forge['entry_hash'] = SuAudit::entryHash($forge, $forge['prev_hash']);
$forge['hmac']       = str_repeat('0', 64);
file_put_contents($journal, json_encode($forge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
refus('entrée forgée sans la clé (HMAC faux, chaînage juste)', ['rotate-audit-key', '--nouvelle-cle', $bonne], 4);
file_put_contents($journal, $sauve);

// Une entrée dont le contenu a été modifié après coup.
$es[0]['target'] = 'modifie';
file_put_contents($journal, SuAudit::encoder($es));
refus('entrée altérée après coup', ['rotate-audit-key', '--nouvelle-cle', $bonne], 4);
file_put_contents($journal, $sauve);

// Une ligne que `read()` ignore : la réécriture l'effacerait.
file_put_contents($journal, "pas du json\n", FILE_APPEND);
refus('ligne indéchiffrable dans le journal', ['rotate-audit-key', '--nouvelle-cle', $bonne], 4);
file_put_contents($journal, $sauve);

// ── La rotation ─────────────────────────────────────────────────────────────
$avant = entrees($journal);
[$c, $out] = su(['rotate-audit-key', '--nouvelle-cle', $bonne]);
$c === 0
    ? ok('rotate-audit-key → 0')
    : nok("rotate-audit-key devait réussir (code $c) — " . trim($out));

$apres = entrees($journal);
$figes = glob("$journal.frozen-*") ?: [];
$fige  = $figes[0] ?? '';

(count($apres) === count($avant) + 1
    && array_column(array_slice($apres, 0, count($avant)), 'entry_hash') === array_column($avant, 'entry_hash')
    && array_column(array_slice($apres, 0, count($avant)), 'prev_hash') === array_column($avant, 'prev_hash'))
    ? ok('entry_hash et prev_hash inchangés : les témoins distants restent valables')
    : nok('la chaîne a changé de forme pendant la rotation');

($apres[count($apres) - 1]['action'] ?? '') === SuAudit::ACTION_ROTATE_KEY
    ? ok('dernière entrée : rotate-audit-key')
    : nok('aucune entrée rotate-audit-key en tête');

[$c] = su(['verify-log'], ['SELFRECOVER_SU_AUDIT_SECRET' => $nouvelle]);
$c === 0
    ? ok('verify-log sous la nouvelle clé → 0 (chaîne intègre ET sceau concordant)')
    : nok("verify-log sous la nouvelle clé devait passer (code $c)");

[$c] = su(['verify-log']);
$c === 4
    ? ok("verify-log sous l'ancienne clé → 4")
    : nok("l'ancienne clé ne devait plus vérifier (code $c)");

(count($figes) === 1 && (fileperms($fige) & 0777) === 0600
    && SuAudit::verifierEntrees(entrees($fige), $ancienne)['ok']
    && entrees($fige) === $avant)
    ? ok("ancien journal figé en 0600, identique, et vérifiable sous l'ancienne clé")
    : nok('journal figé absent, modifié, ou mal protégé');

(glob("$journal.rotation-*") === [] && (fileperms($journal) & 0777) === 0600)
    ? ok('aucun fichier temporaire restant, journal en 0600')
    : nok('fichier temporaire resté, ou journal mal protégé');

[$c] = su(['audit', '--dry-run'], ['SELFRECOVER_SU_AUDIT_SECRET' => $nouvelle]);
$c === 0
    ? ok("audit sous la nouvelle clé → 0 : alice reste légitime")
    : nok("audit après rotation (code $c)");

[$c, $out] = su(['record-seal'], ['SELFRECOVER_SU_AUDIT_SECRET' => $nouvelle]);
($c === 0 && str_contains($out, 'Déjà scellé'))
    ? ok('record-seal : « déjà scellé », le sceau a suivi la rotation')
    : nok("le sceau devait suivre la rotation (code $c) — " . trim($out));

$total = $reussites + $echecs;
if ($echecs === 0) {
    echo "OK — $total/$total contrôles conformes.\n";
    exit(0);
}
fwrite(STDERR, "ÉCHEC — $echecs/$total contrôle(s) en défaut.\n");
exit(1);
