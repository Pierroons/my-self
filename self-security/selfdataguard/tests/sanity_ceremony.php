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
require_once __DIR__ . '/pepper_fixture.php';
require_once __DIR__ . '/admin_fixture.php';

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
$dg      = new SelfDataGuard($storage, random_bytes(32), pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
$admin   = SelfDataGuard::generateAdminRecoveryKey(ADMIN_PASS);
benchAdminRecorded($storage, $admin['publicKey']);
file_put_contents($pubFile, $admin['publicKey']);
file_put_contents($sealFile, $admin['sealedSecret']);

$session = $dg->register('alice', 'motdepasse-fort', hash('sha256', 'sentier-brume-rocher'));
$dg->setEscrowFields($session, $admin['publicKey'], ['contact_secours' => CONTACT]);

$pdo = new PDO("sqlite:{$db}");
$pdo->exec('CREATE TABLE litiges (id TEXT PRIMARY KEY, user_id TEXT, status TEXT)');
$pdo->exec("INSERT INTO litiges VALUES ('L3-open','alice','open')");
$pdo->exec("INSERT INTO litiges VALUES ('L3-closed','alice','closed')");
$pdo->exec("INSERT INTO litiges VALUES ('L3-bob','bob','open')");

// bob went through level 3: his old vault, escrow included, is an archive.
$bob = $dg->register('bob', 'motdepasse-bob-fort', hash('sha256', 'bob-memorise'));
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
        public function verdict(string $userId, string $caseRef, ?string $archiveId, array $fieldNames): Verdict
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
$keeperFile = "{$ws}/gardien.php";
file_put_contents($keeperFile, sprintf(
    "<?php\nrequire %s;\nreturn new Pierroons\\SelfDataGuard\\Escrow\\NoKeeper();\n",
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
    'DATAGUARD_KEEPER_FILE'       => $keeperFile,
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

$r = run(['unlock', 'alice', 'L3-open', 'contact_secours'], ADMIN_PASS . "\n", $env);
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

section('verify-log on a log that rotates: every file and every link, and a config it cannot read');

$rotDir = sys_get_temp_dir() . '/dg_ceremony_rot_' . bin2hex(random_bytes(4));
mkdir($rotDir, 0700);
$rotLog = "{$rotDir}/journal.log";
$rotEnv = ['DATAGUARD_AUDIT_LOG' => $rotLog, 'DATAGUARD_AUDIT_ROTATE_AFTER' => '2', 'DATAGUARD_AUDIT_MODE' => '0640'] + $env;
$rotAudit = new AuditLog($rotLog, $auditSecret, rotateAfter: 2, mode: 0o640);
foreach (['r0', 'r1', 'r2', 'r3', 'r4'] as $act) {
    $rotAudit->append(['action' => $act]);
}
$r = run(['verify-log'], '', $rotEnv);
$r['code'] === 0 && str_contains($r['out'], '8 entrée(s) vérifiée(s) sur 8') && str_contains($r['out'], '4 fichiers liés')
    ? ok('verify-log without anchor verifies every file of a rotated log: 8 entries over 4 linked files')
    : ko('verify-log did not verify the whole rotated log', $r['code'] . ' ' . trim($r['out'] . $r['err']));
unlink("{$rotDir}/journal.1.log");
$r = run(['verify-log'], '', $rotEnv);
$r['code'] === 1 && str_contains($r['err'], 'UNE ARCHIVE MANQUE')
    ? ok('an archive removed: verify-log says one is missing, exit 1')
    : ko('verify-log missed a removed archive', $r['code'] . ' ' . trim($r['out'] . $r['err']));
foreach (['DATAGUARD_AUDIT_ROTATE_AFTER' => '1', 'DATAGUARD_AUDIT_MODE' => '640x'] as $key => $bad) {
    $r = run(['verify-log'], '', [$key => $bad] + $rotEnv);
    $r['code'] === 3 && str_contains($r['err'], 'Config illisible')
        ? ok("{$key}={$bad} → exit 3, Config illisible")
        : ko("{$key}={$bad} was taken", $r['code'] . ' ' . trim($r['out'] . $r['err']));
}
array_map('unlink', glob("{$rotDir}/*") ?: []);
rmdir($rotDir);

// -----------------------------------------------------------------------------

section('Anti-curieux — closed litige is refused');

$before = count((new AuditLog($auditLog, $auditSecret))->readAll());
$r = run(['unlock', 'alice', 'L3-closed', 'contact_secours'], ADMIN_PASS . "\n", $env);
$r['code'] === 2 ? ok('exit 2 (refused)') : ko('should refuse closed litige', (string) $r['code']);
str_contains($r['err'], 'Refusé') ? ok('stderr says Refusé') : ko('no refusal message');
$denyEntries = (new AuditLog($auditLog, $auditSecret))->readAll();
$deny = end($denyEntries);
$deny['event']['action'] === 'escrow-unlock-denied' && $deny['event']['reason'] === 'verdict' && $deny['event']['verdict'] === 'closed'
    ? ok('denied attempt is logged (reason=verdict, verdict=closed)') : ko('denial not logged', json_encode($deny));

// -----------------------------------------------------------------------------

section('Anti-curieux — unknown litige is refused');

$r = run(['unlock', 'alice', 'L3-does-not-exist', 'contact_secours'], ADMIN_PASS . "\n", $env);
$r['code'] === 2 ? ok('unknown litige → exit 2') : ko('should refuse unknown litige');

foreach (['DATAGUARD_ARBITER_FILE' => "{$ws}/absent.php", 'DATAGUARD_WITNESS_FILE' => $arbiterFile, 'DATAGUARD_KEEPER_FILE' => $witnessFile] as $key => $wrong) {
    $r = run(['unlock', 'alice', 'L3-open', 'contact_secours'], ADMIN_PASS . "\n", [$key => $wrong] + $env);
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
$r = run(['unlock-archive', 'bob', $bobArchive, 'L3-bob', 'contact_secours'], ADMIN_PASS . "\n", $env);
$r['code'] === 0 && str_contains($r['out'], 'bob-secours@example.org')
    ? ok('unlock-archive → the escrow of the archive, after its own intention in the log')
    : ko('unlock-archive did not read the archived escrow', $r['code'] . ' ' . trim($r['out'] . $r['err']));
$r = run(['archives', 'bob', 'L3-closed'], '', $env);
$r['code'] === 2
    ? ok('archives under a case that is not bob\'s → refused: how often an account was re-enrolled is arbitrated too')
    : ko('archives were listed under another account\'s case', (string) $r['code']);

// -----------------------------------------------------------------------------

section('A read names its fields; a comparison prints no value of the escrow');

$r = run(['unlock', 'alice', 'L3-open'], ADMIN_PASS . "\n", $env);
$r['code'] === 3 && str_contains($r['err'], 'unlock <user> <dossier> <champ>')
    ? ok('unlock without a field stops at the usage: no read takes every field by omission')
    : ko('unlock without a field went on', $r['code'] . ' ' . trim($r['out'] . $r['err']));
$r = run(['unlock', 'alice', 'L3-open', 'contact_secours', 'contact_secours'], ADMIN_PASS . "\n", $env);
$r['code'] === 3 && str_contains($r['err'], 'deux fois')
    ? ok('a field named twice stops before the ceremony')
    : ko('a field named twice went on', $r['code'] . ' ' . trim($r['err']));
$r = run(['unlock', 'alice', 'L3-open', 'contact_secours'], ADMIN_PASS . "\n", $env);
$r['code'] === 0 && !str_contains($r['out'] . $r['err'], 'imposteur') && str_contains($r['out'], 'remise')
    ? ok('the read ends without calling anyone an impostor: it says the read is logged, and shown as a release')
    : ko('the read still ends on a verdict about the claimant', trim($r['out']));
$r = run(['compare', 'alice', 'L3-open', 'contact_secours'], ADMIN_PASS . "\n" . CONTACT . "\n", $env);
$r['code'] === 0 && str_contains($r['out'], 'contact_secours : concorde') && !str_contains($r['out'] . $r['err'], CONTACT)
    ? ok('compare: the declared value matches, exit 0 — and the value of the escrow is not printed')
    : ko('compare did not answer concorde without the value', $r['code'] . ' ' . trim($r['out'] . $r['err']));
$all  = (new AuditLog($auditLog, $auditSecret))->readAll();
$last = end($all);
($last['event']['action'] ?? null) === 'escrow-compare' && ($last['event']['returned'] ?? null) === ['contact_secours' => 'matches']
    && ($last['event']['normalization'] ?? null) === 'exact' && !str_contains((string) file_get_contents($auditLog), CONTACT)
    ? ok('the log writes the comparison, its normalization and its outcome — never the value')
    : ko('the comparison is not logged as one', json_encode($last));
$r = run(['compare', 'alice', 'L3-open', 'contact_secours'], ADMIN_PASS . "\n" . strtoupper(CONTACT) . "\n", $env);
$r['code'] === 5 && str_contains($r['out'], 'ne concorde pas sous la normalisation « exact »') && !str_contains($r['out'] . $r['err'], CONTACT)
    ? ok('another case: exit 5, « ne concorde pas sous la normalisation exact », and still no value printed')
    : ko('a value in another case was not answered under its normalization', $r['code'] . ' ' . trim($r['out'] . $r['err']));
$r = run(['compare', 'alice', 'L3-open', 'contact_secours', '--normalisation', 'case-and-spaces'], ADMIN_PASS . "\n " . strtoupper(CONTACT) . "\n", $env);
$r['code'] === 0 && str_contains($r['out'], 'contact_secours : concorde') && str_contains($r['out'], '« case-and-spaces »')
    ? ok('--normalisation case-and-spaces folds case and spaces, and the output names it')
    : ko('case-and-spaces did not match', $r['code'] . ' ' . trim($r['out'] . $r['err']));
$r = run(['compare', 'alice', 'L3-open', 'absent'], ADMIN_PASS . "\nquoi que ce soit\n", $env);
$r['code'] === 5 && str_contains($r['out'], 'absent : indisponible')
    ? ok('a field the escrow does not hold: « indisponible », exit 5 — never « ne concorde pas »')
    : ko('an absent field was not answered unavailable', $r['code'] . ' ' . trim($r['out'] . $r['err']));
$r = run(['compare', 'alice', 'L3-open', 'contact_secours', '--normalisation=phonetique'], ADMIN_PASS . "\n" . CONTACT . "\n", $env);
$r['code'] === 3 && str_contains($r['err'], 'normalisation inconnue')
    ? ok('a normalization the library does not have stops at the configuration, before the ceremony')
    : ko('an unknown normalization went on', $r['code'] . ' ' . trim($r['err']));
$r = run(['compare', 'alice', 'L3-closed', 'contact_secours'], ADMIN_PASS . "\n" . CONTACT . "\n", $env);
$r['code'] === 2
    ? ok('a comparison under a closed case is refused like a read: the arbiter decides it too')
    : ko('a comparison went through under a closed case', (string) $r['code']);
$r = run(['compare-archive', 'bob', $bobArchive, 'L3-bob', 'contact_secours'], ADMIN_PASS . "\nbob-secours@example.org\n", $env);
$r['code'] === 0 && str_contains($r['out'], 'contact_secours : concorde') && !str_contains($r['out'], 'bob-secours@example.org')
    ? ok('compare-archive: the escrow of the archive, compared without being printed')
    : ko('compare-archive did not compare', $r['code'] . ' ' . trim($r['out'] . $r['err']));

// -----------------------------------------------------------------------------

section('adopt-admin — the operator records the administrator key, proving they hold it');

$adoptDb  = "{$ws}/adopt.sqlite";
$adoptEnv = ['DATAGUARD_DB' => $adoptDb] + $env;
$autre    = SelfDataGuard::generateAdminRecoveryKey('une autre passphrase admin du banc de cérémonie');
file_put_contents("{$ws}/autre.pub", $autre['publicKey']);
file_put_contents("{$ws}/autre.sealed", $autre['sealedSecret']);
$autreEnv = ['DATAGUARD_ADMIN_PUBKEY_FILE' => "{$ws}/autre.pub", 'DATAGUARD_ADMIN_SEALED_FILE' => "{$ws}/autre.sealed"];
$recorded = static fn (): ?string => (new SqliteAdapter("sqlite:{$adoptDb}"))->loadSetting(SelfDataGuard::SETTING_ESCROW_ADMIN);
$lastAct  = static function () use ($auditLog, $auditSecret): array {
    $all = (new AuditLog($auditLog, $auditSecret))->readAll();
    return end($all)['event'] ?? [];
};
$r = run(['adopt-admin'], "mauvaise-passphrase\n", $adoptEnv);
$r['code'] === 2 && str_contains($r['err'], 'bad-passphrase') && $recorded() === null
    && ($lastAct()['action'] ?? null) === 'adopt-admin-denied' && ($lastAct()['reason'] ?? null) === 'bad-passphrase'
    ? ok('adopt-admin under a wrong passphrase: exit 2, nothing recorded, the refusal logged')
    : ko('a wrong passphrase was not refused as such', $r['code'] . ' ' . trim($r['err']) . ' ' . json_encode($lastAct()));
$r = run(['adopt-admin'], ADMIN_PASS . "\n", ['DATAGUARD_ADMIN_PUBKEY_FILE' => "{$ws}/autre.pub"] + $adoptEnv);
$r['code'] === 2 && str_contains($r['err'], 'admin-not-the-pair') && $recorded() === null
    ? ok('adopt-admin with the sealed key of one key and the public key of another: exit 2, nothing recorded')
    : ko('two halves of two keys were not refused', $r['code'] . ' ' . trim($r['err']));
$r = run(['adopt-admin'], ADMIN_PASS . "\n", $adoptEnv);
$print = Pierroons\SelfDataGuard\Escrow\AdminKey::fingerprint($admin['publicKey']);
$r['code'] === 0 && str_contains($r['out'], $print) && $recorded() === $print
    && ($lastAct()['action'] ?? null) === 'adopt-admin' && ($lastAct()['admin'] ?? null) === $print
    ? ok('adopt-admin with the passphrase and both halves: exit 0, the key recorded, the act logged with its fingerprint')
    : ko('adopt-admin did not record the key', $r['code'] . ' ' . trim($r['out'] . $r['err']));
$r = run(['adopt-admin'], ADMIN_PASS . "\n", $adoptEnv);
$r['code'] === 0 && $recorded() === $print
    ? ok('adopt-admin of the key already recorded: exit 0, nothing changes')
    : ko('adopting the recorded key again failed', $r['code'] . ' ' . trim($r['err']));
$r = run(['adopt-admin'], "une autre passphrase admin du banc de cérémonie\n", $autreEnv + $adoptEnv);
$r['code'] === 2 && str_contains($r['err'], 'admin-mismatch') && $recorded() === $print
    ? ok('adopt-admin of another key on a database that recorded one: exit 2, the record kept')
    : ko('another key was adopted over the recorded one', $r['code'] . ' ' . trim($r['err']));
$r = run(['unlock', 'alice', 'L3-open', 'contact_secours'], "une autre passphrase admin du banc de cérémonie\n", $autreEnv + $env);
$r['code'] === 2 && str_contains($r['err'], "clé d'administration (admin-mismatch)") && !str_contains($r['out'], CONTACT)
    ? ok('unlock under another administrator key than the escrow\'s label: exit 2, named as such, nothing read')
    : ko('a read under another key was not refused by the label', $r['code'] . ' ' . trim($r['out'] . $r['err']));

section('change-admin, then reseal-admin — the key changes, and the pass re-seals what the old one holds');

$rotDb  = "{$ws}/rotation.sqlite";
copy($db, $rotDb);
$rotEnv = ['DATAGUARD_DB' => $rotDb] + $env;
$oldEnv = ['DATAGUARD_OLD_ADMIN_PUBKEY_FILE' => $pubFile, 'DATAGUARD_OLD_ADMIN_SEALED_FILE' => $sealFile];
$rotHeld = static fn (): ?string => (new SqliteAdapter("sqlite:{$rotDb}"))->loadSetting(SelfDataGuard::SETTING_ESCROW_ADMIN);
$autrePrint = Pierroons\SelfDataGuard\Escrow\AdminKey::fingerprint($autre['publicKey']);
$r = run(['change-admin'], "pas la passphrase\n", $oldEnv + $autreEnv + $rotEnv);
$r['code'] === 2 && str_contains($r['err'], 'bad-passphrase') && $rotHeld() === $print
    ? ok('change-admin under a wrong passphrase of the new key: exit 2, the record kept')
    : ko('change-admin under a wrong passphrase was not refused', $r['code'] . ' ' . trim($r['err']));
$r = run(['change-admin'], "une autre passphrase admin du banc de cérémonie\n", $oldEnv + $autreEnv + $rotEnv);
$r['code'] === 0 && $rotHeld() === $autrePrint && str_contains($r['out'], 'reseal-admin')
    && ($lastAct()['action'] ?? null) === 'change-admin' && ($lastAct()['former'] ?? null) === $print
    ? ok('change-admin: exit 0, the new key recorded in place of the old one, the act logged, and the pass named')
    : ko('change-admin did not change the key', $r['code'] . ' ' . trim($r['out'] . $r['err']));
$r = run(['unlock', 'alice', 'L3-open', 'contact_secours'], "une autre passphrase admin du banc de cérémonie\n", $autreEnv + $rotEnv);
$r['code'] === 2 && str_contains($r['err'], 'admin-mismatch')
    ? ok('before the pass, the new key is refused on an escrow the old one seals: its label says so')
    : ko('the new key read before the pass', $r['code'] . ' ' . trim($r['err']));
$r = run(['reseal-admin'], ADMIN_PASS . "\n", $oldEnv + $autreEnv + $rotEnv);
$r['code'] === 0 && str_contains($r['out'], 'alice : coffre vivant re-scellé à la nouvelle clé')
    && str_contains($r['out'], 'bob : coffre vivant sans changement, 1 archive(s) re-scellée(s)')
    ? ok('reseal-admin: exit 0, the live escrow and the archive re-sealed to the new key, account by account')
    : ko('reseal-admin did not re-seal', $r['code'] . ' ' . trim($r['out'] . $r['err']));
$r = run(['unlock', 'alice', 'L3-open', 'contact_secours'], "une autre passphrase admin du banc de cérémonie\n", $autreEnv + $rotEnv);
$r['code'] === 0 && str_contains($r['out'], CONTACT)
    ? ok('after the pass, the new key reads the escrow: the escrow key and its field stayed')
    : ko('the new key does not read after the pass', $r['code'] . ' ' . trim($r['out'] . $r['err']));
(new PDO("sqlite:{$rotDb}"))->exec("UPDATE selfdataguard_escrow SET admin_key = '" . str_repeat('c3', 16) . "' WHERE user_id = 'alice'");
$r = run(['reseal-admin', 'alice'], ADMIN_PASS . "\n", $oldEnv + $autreEnv + $rotEnv);
$r['code'] === 5 && str_contains($r['out'], "laissé sous l'ancienne clé — live : other-key") && str_contains($r['err'], 'garde-la')
    ? ok('reseal-admin that leaves an escrow: exit 5, what it left and why, and to keep the old key')
    : ko('reseal-admin did not report what it left', $r['code'] . ' ' . trim($r['out'] . $r['err']));
$r = run(['reseal-admin', 'alice'], "pas la passphrase\n", $oldEnv + $autreEnv + $rotEnv);
$r['code'] === 2 && str_contains($r['err'], "passphrase de l'ancienne clé invalide")
    ? ok('reseal-admin under a wrong passphrase of the old key: exit 2, nothing re-sealed')
    : ko('reseal-admin under a wrong passphrase was not refused', $r['code'] . ' ' . trim($r['err']));

// -----------------------------------------------------------------------------

section('Passphrase gate — wrong passphrase is refused + logged');

$r = run(['unlock', 'alice', 'L3-open', 'contact_secours'], "mauvaise-passphrase\n", $env);
$r['code'] === 2 ? ok('wrong passphrase → exit 2') : ko('should refuse wrong passphrase', (string) $r['code']);
!str_contains($r['out'], CONTACT) ? ok('no secret leaked on wrong passphrase') : ko('LEAK on wrong passphrase');
$badEntries = (new AuditLog($auditLog, $auditSecret))->readAll();
$deny = end($badEntries);
$deny['event']['reason'] === 'bad-passphrase' ? ok('bad-passphrase attempt logged') : ko('bad-passphrase not logged', json_encode($deny));

// A sealed-key file with an out-of-range profile is a broken file, not a wrong passphrase.
$sealIntact = file_get_contents($sealFile);
file_put_contents($sealFile, preg_replace('/^v2:\d+:/', 'v2:0:', $sealIntact));
$r = run(['unlock', 'alice', 'L3-open', 'contact_secours'], ADMIN_PASS . "\n", $env);
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

run(['unlock', 'alice', 'L3-open', 'contact_secours'], ADMIN_PASS . "\n", $seedEnv);
unlink($headFile);
$r = run(['unlock', 'alice', 'L3-open', 'contact_secours'], ADMIN_PASS . "\n", $seedEnv);
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
$r = run(['unlock', 'alice', 'L3-open', 'contact_secours'], ADMIN_PASS . "\n", $seedEnv);
$r['code'] === 0 && str_contains($r['out'], CONTACT)
    ? ok('the seeded witness serves the next ceremony')
    : ko('the seeded witness did not serve the next ceremony', $r['code'] . ' ' . trim($r['err']));

// -----------------------------------------------------------------------------

section('Under a keeper — a read announced, read once due, and the operator\'s pass');

require_once __DIR__ . '/keeper_fixture.php';
$keeperState = "{$ws}/gardien-etat.json";
$fileKeeper  = new FileBenchKeeper($keeperState, 3600);
$keeperBench = "{$ws}/gardien-banc.php";
file_put_contents($keeperBench, sprintf(
    "<?php\nrequire %s;\nrequire_once %s;\nreturn new FileBenchKeeper(%s, 3600);\n",
    var_export(__DIR__ . '/../src/autoload.php', true),
    var_export(__DIR__ . '/keeper_fixture.php', true),
    var_export($keeperState, true)
));
$kdb    = "{$ws}/escrow-keeper.sqlite";
$kstore = new SqliteAdapter("sqlite:{$kdb}");
benchAdminRecorded($kstore, $admin['publicKey']);
$kblind = random_bytes(32);
$kplain = new SelfDataGuard($kstore, $kblind, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
$frank  = $kplain->register('frank', 'motdepasse-frank-fort');
$kplain->setEscrowFields($frank, $admin['publicKey'], ['contact_secours' => 'frank-secours@example.org']);
$kdg  = new SelfDataGuard($kstore, $kblind, keeper: $fileKeeper, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
$erin = $kdg->register('erin', 'motdepasse-erin-fort');
$kdg->setEscrowFields($erin, $admin['publicKey'], ['contact_secours' => 'erin-secours@example.org']);
$pdo->exec("INSERT INTO litiges VALUES ('K-erin','erin','open')");
$kenv = ['DATAGUARD_DB' => $kdb, 'DATAGUARD_KEEPER_FILE' => $keeperBench, 'DATAGUARD_AUDIT_LOG' => "{$ws}/audit-k.log"] + $env;

$r = run(['unlock', 'erin', 'K-erin', 'contact_secours'], ADMIN_PASS . "\n", $kenv);
$r['code'] === 4 && str_contains($r['err'], 'Lecture annoncée') && !str_contains($r['out'], 'erin-secours@example.org')
    ? ok('a read under a delay exits 4: nothing unsealed, and the command says when to run it again')
    : ko('a read under a delay did not exit 4 as announced', $r['code'] . ' ' . trim($r['err']));
$state = json_decode((string) file_get_contents($keeperState), true);
foreach ($state['pending'] as $k => $held) {
    $state['pending'][$k]['due'] = time() - 1;
}
file_put_contents($keeperState, json_encode($state));
$r = run(['unlock', 'erin', 'K-erin', 'contact_secours'], ADMIN_PASS . "\n", $kenv);
$r['code'] === 0 && str_contains($r['out'], 'erin-secours@example.org')
    ? ok('the same command, run again once due, reads: the keeper released the box')
    : ko('a due read did not go through the command', $r['code'] . ' ' . trim($r['err']));

$r = run(['seal-to-keeper'], '', $kenv);
$r['code'] === 0 && str_contains($r['out'], 'frank : coffre vivant scellé au gardien')
    && Pierroons\SelfDataGuard\Escrow\EscrowVault::isKeeperSealed($kstore->loadEscrow('frank'))
    ? ok("seal-to-keeper: the escrow the administrator alone still opened is sealed to the keeper, without any secret in hand")
    : ko('seal-to-keeper did not seal the escrow sealed before the keeper', $r['code'] . ' ' . trim($r['out'] . $r['err']));
$r = run(['seal-to-keeper'], '', $kenv);
$r['code'] === 0 && str_contains($r['out'], 'frank : coffre vivant sans changement')
    ? ok('a second pass changes nothing')
    : ko('a second pass sealed again', trim($r['out']));
$kdg->getEscrowFieldsAsUser($frank) === ['contact_secours' => 'frank-secours@example.org']
    ? ok('its holder still reads it')
    : ko('the operator\'s pass took the escrow from its holder');
foreach (['gus', 'hugo'] as $name) {
    $kplain->setEscrowFields($kplain->register($name, "motdepasse-{$name}-fort"), $admin['publicKey'], ['contact_secours' => "{$name}@example.org"]);
}
$r = run(['unlock', 'erin', 'K-erin', 'contact_secours'], ADMIN_PASS . "\n", ['DATAGUARD_FORMER_KEEPER_FILES' => "{$ws}/absent-gardien.php"] + $kenv);
$r['code'] === 3 && str_contains($r['err'], 'DATAGUARD_FORMER_KEEPER_FILES')
    ? ok('a former keeper file that returns no keeper exits 3, naming the variable')
    : ko('a former keeper file that returns no keeper did not exit 3', $r['code'] . ' ' . trim($r['err']));
(new PDO("sqlite:{$kdb}"))->exec("UPDATE selfdataguard_escrow SET wrap_admin = '%%%' WHERE user_id = 'gus'");
$r = run(['seal-to-keeper', 'gus', 'hugo'], '', $kenv);
$failed = array_values(array_filter(
    array_map(static fn (string $l): array => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents("{$ws}/audit-k.log")))),
    static fn (array $e): bool => ($e['event']['action'] ?? '') === 'seal-to-keeper-failed'
));
$r['code'] === 2 && str_contains($r['out'], 'hugo : coffre vivant scellé au gardien') && str_contains($r['err'], 'à relancer : gus')
    && ($failed[0]['event']['target'] ?? null) === 'gus'
    ? ok('an account that fails is named and logged, the pass goes on to the next, and exits 2')
    : ko('the pass stopped at the account that failed, or did not name it', $r['code'] . ' ' . trim($r['out'] . $r['err']));

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
