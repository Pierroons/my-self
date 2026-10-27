<?php

declare(strict_types=1);

/**
 * End-to-end sanity test for the escrow-ceremony CLI.
 *
 * Spins up a throwaway DB + admin recovery key + litiges, then drives
 * bin/escrow-ceremony.php as a subprocess (passphrase fed on stdin) and asserts
 * the full policy: litige gate, passphrase gate, audit logging, verify-log.
 *
 * Run:  php tests/sanity_ceremony.php
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Escrow\AuditLog;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;

$failures = 0;
$passes = 0;
function ok(string $l): void { global $passes; $passes++; echo "  ✅ {$l}\n"; }
function ko(string $l, string $d = ''): void { global $failures; $failures++; echo "  ❌ {$l}" . ($d ? " — {$d}" : '') . "\n"; }
function section(string $t): void { echo "\n→ {$t}\n"; }

const ADMIN_PASS  = 'ceremonie-admin-recuperation-tres-longue-2026';
const CONTACT     = 'alice-secours@example.org';

// ── Workspace jetable ────────────────────────────────────────────────────────
$ws = sys_get_temp_dir() . '/dg_ceremony_' . getmypid();
@mkdir($ws);
$db      = "{$ws}/escrow-test.sqlite";
$pubFile = "{$ws}/admin.pub";
$sealFile = "{$ws}/admin.sealed";
$auditLog = "{$ws}/audit.log";
$auditSecret = str_repeat('s', 32);

// ── Setup : user + escrow + admin key + litiges ──────────────────────────────
$storage = new SqliteAdapter("sqlite:{$db}");
$dg      = new SelfDataGuard($storage, random_bytes(32));
$admin   = SelfDataGuard::generateAdminRecoveryKey(ADMIN_PASS);
file_put_contents($pubFile, $admin['publicKey']);
file_put_contents($sealFile, $admin['sealedSecret']);

$session = $dg->register('alice', 'motdepasse-fort', 'sentier-brume-rocher');
$dg->setEscrowFields($session, $admin['publicKey'], ['contact_secours' => CONTACT]);

$pdo = new PDO("sqlite:{$db}");
$pdo->exec('CREATE TABLE litiges (id TEXT PRIMARY KEY, user_id TEXT, status TEXT)');
$pdo->exec("INSERT INTO litiges VALUES ('L3-open','alice','open')");
$pdo->exec("INSERT INTO litiges VALUES ('L3-closed','alice','closed')");
$pdo->exec("INSERT INTO litiges VALUES ('L3-bob','bob','open')");

// bob went through level 3: his old vault, escrow included, is an archive.
$bob = $dg->register('bob', 'motdepasse-bob-fort', 'bob-memorise');
$dg->setEscrowFields($bob, $admin['publicKey'], ['contact_secours' => 'bob-secours@example.org']);
$bobArchive = $dg->reEnroll($dg->generationOf('bob'), 'motdepasse-bob-neuf')['archiveId'];

// The arbiter and the witness, as an operator writes them: files that return the
// object. This arbiter reads the bench's case table.
$arbiterFile = "{$ws}/arbitre.php";
file_put_contents($arbiterFile, sprintf(<<<'PHP'
    <?php
    require %s;
    use Pierroons\SelfDataGuard\Escrow\EscrowArbiter;
    use Pierroons\SelfDataGuard\Escrow\Verdict;
    return new class (%s) implements EscrowArbiter {
        public function __construct(private readonly string $db) {}
        public function verdict(string $userId, string $caseRef, ?string $archiveId): Verdict
        {
            $st = (new PDO('sqlite:' . $this->db))->prepare('SELECT status, user_id FROM litiges WHERE id = ?');
            $st->execute([$caseRef]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row === false || $row['user_id'] !== $userId) {
                return Verdict::Unknown;
            }
            return $row['status'] === 'open' ? Verdict::Open : Verdict::Closed;
        }
    };
    PHP, var_export(__DIR__ . '/../src/autoload.php', true), var_export($db, true)));
$witnessFile = "{$ws}/temoin.php";
file_put_contents($witnessFile, sprintf(
    "<?php\nrequire %s;\nreturn new Pierroons\\SelfDataGuard\\Escrow\\NoHeadWitness();\n",
    var_export(__DIR__ . '/../src/autoload.php', true)
));

// ── Harnais subprocess ───────────────────────────────────────────────────────
$env = [
    'PATH'                        => getenv('PATH') ?: '/usr/bin:/bin',
    'DATAGUARD_DB'                => $db,
    'DATAGUARD_ADMIN_PUBKEY_FILE' => $pubFile,
    'DATAGUARD_ADMIN_SEALED_FILE' => $sealFile,
    'DATAGUARD_AUDIT_LOG'         => $auditLog,
    'DATAGUARD_ARBITER_FILE'      => $arbiterFile,
    'DATAGUARD_WITNESS_FILE'      => $witnessFile,
    'DATAGUARD_AUDIT_SECRET'      => $auditSecret,
    'DATAGUARD_OPERATOR'          => 'operator@testhost',
];

function run(array $args, string $stdin, array $env): array
{
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd  = array_merge([PHP_BINARY, __DIR__ . '/../bin/escrow-ceremony.php'], $args);
    $p    = proc_open($cmd, $desc, $pipes, null, $env);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    return ['code' => $code, 'out' => $out, 'err' => $err];
}

// -----------------------------------------------------------------------------

section('Happy path — open litige + correct passphrase');

$r = run(['unlock', 'alice', 'L3-open'], ADMIN_PASS . "\n", $env);
$r['code'] === 0 ? ok('exit 0') : ko('exit code', (string) $r['code'] . ' err=' . trim($r['err']));
str_contains($r['out'], CONTACT) ? ok('escrow contact_secours revealed to admin') : ko('secret not in output', $r['out']);

$audit = new AuditLog($auditLog, $auditSecret);
$entries = $audit->readAll();
$last = end($entries);
$last['event']['action'] === 'escrow-unlock' && $last['event']['target'] === 'alice'
    ? ok('success written to audit log (action=escrow-unlock)') : ko('audit entry wrong', json_encode($last));
in_array('contact_secours', $last['event']['returned'] ?? [], true) ? ok('audit records which fields were opened') : ko('fields not logged');
$intent = $entries[count($entries) - 2]['event'] ?? [];
($intent['action'] ?? null) === 'escrow-unlock-intent' && ($intent['verdict'] ?? null) === 'open'
    && str_contains((string) ($intent['arbiter'] ?? ''), 'EscrowArbiter@anonymous')
    && ($intent['witness'] ?? null) === 'Pierroons\SelfDataGuard\Escrow\NoHeadWitness'
    ? ok('the intention precedes the act, naming the verdict, the arbiter and the witness')
    : ko('no intention before the act', json_encode($intent));
$last['event']['operator'] === 'operator@testhost' ? ok('audit records the operator (forensic)') : ko('operator not logged');

// -----------------------------------------------------------------------------

section('verify-log passes on the intact chain');

$r = run(['verify-log'], '', $env);
$r['code'] === 0 && str_contains($r['out'], 'intègre') ? ok('verify-log → exit 0, chain intact') : ko('verify-log failed', trim($r['out'] . $r['err']));
preg_match('/tête : (\d+:[0-9a-f]{64})/', $r['out'], $tete)
    ? ok('verify-log prints the head to note off the machine')
    : ko('verify-log printed no head', trim($r['out']));
$r = run(['verify-log', '--ancre', $tete[1] ?? ''], '', $env);
$r['code'] === 0 && str_contains($r['out'], 'ancre retrouvée')
    ? ok('verify-log --ancre accepts the head it printed')
    : ko('verify-log --ancre refused its own head', trim($r['out'] . $r['err']));
$r = run(['verify-log', '--ancre', '999:' . str_repeat('0', 64)], '', $env);
$r['code'] === 1 && str_contains($r['err'], 'TRONQUÉ')
    ? ok('verify-log --ancre refuses an anchor beyond the end (truncation)')
    : ko('verify-log --ancre missed a truncation', $r['code'] . ' ' . trim($r['out'] . $r['err']));
$r = run(['verify-log', '--ancre=999:' . str_repeat('0', 64)], '', $env);
$r['code'] === 1 && str_contains($r['err'], 'TRONQUÉ')
    ? ok('verify-log --ancre=… is read the same way')
    : ko('verify-log --ancre=… was not read as an anchor', $r['code'] . ' ' . trim($r['out'] . $r['err']));
foreach ([['--anchor', '999:' . str_repeat('0', 64)], ['--ancre'], ['--ancre', 'x', 'y']] as $mal) {
    $r = run(array_merge(['verify-log'], $mal), '', $env);
    $r['code'] === 2
        ? ok('verify-log ' . implode(' ', $mal) . ' → exit 2, not a silent check without anchor')
        : ko('verify-log ' . implode(' ', $mal) . ' was accepted', $r['code'] . ' ' . trim($r['out'] . $r['err']));
}

// -----------------------------------------------------------------------------

section('Anti-curieux — closed litige is refused');

$before = count((new AuditLog($auditLog, $auditSecret))->readAll());
$r = run(['unlock', 'alice', 'L3-closed'], ADMIN_PASS . "\n", $env);
$r['code'] === 2 ? ok('exit 2 (refused)') : ko('should refuse closed litige', (string) $r['code']);
str_contains($r['err'], 'Refusé') ? ok('stderr says Refusé') : ko('no refusal message');
$denyEntries = (new AuditLog($auditLog, $auditSecret))->readAll();
$deny = end($denyEntries);
$deny['event']['action'] === 'escrow-unlock-denied' && $deny['event']['reason'] === 'verdict' && $deny['event']['verdict'] === 'closed'
    ? ok('denied attempt is logged (reason=verdict, verdict=closed)') : ko('denial not logged', json_encode($deny));

// -----------------------------------------------------------------------------

section('Anti-curieux — unknown litige is refused');

$r = run(['unlock', 'alice', 'L3-does-not-exist'], ADMIN_PASS . "\n", $env);
$r['code'] === 2 ? ok('unknown litige → exit 2') : ko('should refuse unknown litige');

foreach (['DATAGUARD_ARBITER_FILE' => "{$ws}/absent.php", 'DATAGUARD_WITNESS_FILE' => $arbiterFile] as $key => $wrong) {
    $r = run(['unlock', 'alice', 'L3-open'], ADMIN_PASS . "\n", [$key => $wrong] + $env);
    $r['code'] === 3 && str_contains($r['err'], $key)
        ? ok("{$key} that does not return its object → exit 3, nothing attempted")
        : ko("{$key} pointing elsewhere was accepted", $r['code'] . ' ' . trim($r['err']));
}

// -----------------------------------------------------------------------------

section('An archive, found then read through the same ceremony');

$r = run(['archives', 'bob', 'L3-bob'], '', $env);
$r['code'] === 0 && str_contains($r['out'], $bobArchive)
    ? ok('archives → the archive id of bob, for the operator to name')
    : ko('archives did not list bob\'s archive', $r['code'] . ' ' . trim($r['out'] . $r['err']));
$r = run(['unlock-archive', 'bob', $bobArchive, 'L3-bob'], ADMIN_PASS . "\n", $env);
$r['code'] === 0 && str_contains($r['out'], 'bob-secours@example.org')
    ? ok('unlock-archive → the escrow of the archive, after its own intention in the log')
    : ko('unlock-archive did not read the archived escrow', $r['code'] . ' ' . trim($r['out'] . $r['err']));
$r = run(['archives', 'bob', 'L3-closed'], '', $env);
$r['code'] === 2
    ? ok('archives under a case that is not bob\'s → refused: how often an account was re-enrolled is arbitrated too')
    : ko('archives were listed under another account\'s case', (string) $r['code']);

// -----------------------------------------------------------------------------

section('Passphrase gate — wrong passphrase is refused + logged');

$r = run(['unlock', 'alice', 'L3-open'], "mauvaise-passphrase\n", $env);
$r['code'] === 2 ? ok('wrong passphrase → exit 2') : ko('should refuse wrong passphrase', (string) $r['code']);
!str_contains($r['out'], CONTACT) ? ok('no secret leaked on wrong passphrase') : ko('LEAK on wrong passphrase');
$badEntries = (new AuditLog($auditLog, $auditSecret))->readAll();
$deny = end($badEntries);
$deny['event']['reason'] === 'bad-passphrase' ? ok('bad-passphrase attempt logged') : ko('bad-passphrase not logged', json_encode($deny));

// A sealed-key file with an out-of-range profile is a broken file, not a wrong passphrase.
$sealIntact = file_get_contents($sealFile);
file_put_contents($sealFile, preg_replace('/^v2:\d+:/', 'v2:0:', $sealIntact));
$r = run(['unlock', 'alice', 'L3-open'], ADMIN_PASS . "\n", $env);
file_put_contents($sealFile, $sealIntact);
$brokenEntries = (new AuditLog($auditLog, $auditSecret))->readAll();
$deny = end($brokenEntries);
$r['code'] === 2 && $deny['event']['reason'] === 'sealed-key-unreadable'
    ? ok('an out-of-range profile in the sealed file is logged as sealed-key-unreadable')
    : ko('a broken sealed file was logged as something else', json_encode($deny['event']));

// -----------------------------------------------------------------------------

section('seed-witness — a witness that lost its store takes the head of a log found to hold its anchor');

$seedLog     = "{$ws}/audit-seed.log";
$headFile    = "{$ws}/temoin.head";
$fileWitness = "{$ws}/temoin-fichier.php";
file_put_contents($fileWitness, sprintf(<<<'PHP'
    <?php
    require %s;
    return new class (%s) implements Pierroons\SelfDataGuard\Escrow\HeadWitness {
        public function __construct(private readonly string $store) {}
        public function witness(int $seq, string $hmac): void { file_put_contents($this->store, "{$seq}:{$hmac}"); }
        public function lastHead(): ?string { return is_file($this->store) ? (string) file_get_contents($this->store) : null; }
    };
    PHP, var_export(__DIR__ . '/../src/autoload.php', true), var_export($headFile, true)));
$seedEnv = ['DATAGUARD_AUDIT_LOG' => $seedLog, 'DATAGUARD_WITNESS_FILE' => $fileWitness] + $env;

run(['unlock', 'alice', 'L3-open'], ADMIN_PASS . "\n", $seedEnv);
unlink($headFile);
$r = run(['unlock', 'alice', 'L3-open'], ADMIN_PASS . "\n", $seedEnv);
$r['code'] === 2 && !str_contains($r['out'], CONTACT) && str_contains($r['err'], 'seedWitness()')
    ? ok('a witness that lost its store is refused, and the refusal names the seed')
    : ko('a witness that lost its store was not refused', $r['code'] . ' ' . trim($r['err']));
$r = run(['seed-witness'], '', $seedEnv);
$r['code'] === 2 && !is_file($headFile)
    ? ok('seed-witness without --ancre → exit 2, nothing seeded')
    : ko('seed-witness ran without an anchor', $r['code'] . ' ' . trim($r['err']));
$r = run(['seed-witness', '--ancre', '0:' . str_repeat('0', 64)], '', $seedEnv);
$r['code'] === 2 && !is_file($headFile)
    ? ok('seed-witness on an anchor the log does not hold → exit 2, nothing seeded')
    : ko('seed-witness seeded on an anchor the log does not hold', $r['code'] . ' ' . trim($r['err']));
preg_match('/tête : (\d+:[0-9a-f]{64})/', run(['verify-log'], '', $seedEnv)['out'], $seedHead);
$r = run(['seed-witness', '--ancre', $seedHead[1] ?? ''], '', $seedEnv);
$r['code'] === 0 && is_file($headFile)
    ? ok('seed-witness on the anchor the log holds → exit 0, and the witness holds a head')
    : ko('seed-witness refused the anchor the log holds', $r['code'] . ' ' . trim($r['err']));
$r = run(['unlock', 'alice', 'L3-open'], ADMIN_PASS . "\n", $seedEnv);
$r['code'] === 0 && str_contains($r['out'], CONTACT)
    ? ok('the seeded witness serves the next ceremony')
    : ko('the seeded witness did not serve the next ceremony', $r['code'] . ' ' . trim($r['err']));

// -----------------------------------------------------------------------------

section('Tampered audit log is caught by verify-log');

$lines = file($auditLog, FILE_IGNORE_NEW_LINES);
$rec = json_decode($lines[0], true);
$rec['event']['target'] = 'mallory';
$lines[0] = json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
file_put_contents($auditLog, implode("\n", $lines) . "\n");
$r = run(['verify-log'], '', $env);
$r['code'] === 1 ? ok('verify-log → exit 1 on tampered chain') : ko('tamper not caught', (string) $r['code']);

// ── Cleanup ──────────────────────────────────────────────────────────────────
array_map('unlink', glob("{$ws}/*") ?: []);
@rmdir($ws);

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Ceremony CLI Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
