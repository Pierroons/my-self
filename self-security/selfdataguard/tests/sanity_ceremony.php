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
const CONTACT     = 'pierroons-secours@example.org';

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

$session = $dg->register('pierroons', 'motdepasse-fort', 'sentier-brume-rocher');
$dg->setEscrowFields($session, $admin['publicKey'], ['contact_secours' => CONTACT]);

$pdo = new PDO("sqlite:{$db}");
$pdo->exec('CREATE TABLE litiges (id TEXT PRIMARY KEY, user_id TEXT, status TEXT)');
$pdo->exec("INSERT INTO litiges VALUES ('L3-open','pierroons','open')");
$pdo->exec("INSERT INTO litiges VALUES ('L3-closed','pierroons','closed')");

// ── Harnais subprocess ───────────────────────────────────────────────────────
$env = [
    'PATH'                        => getenv('PATH') ?: '/usr/bin:/bin',
    'DATAGUARD_DB'                => $db,
    'DATAGUARD_ADMIN_PUBKEY_FILE' => $pubFile,
    'DATAGUARD_ADMIN_SEALED_FILE' => $sealFile,
    'DATAGUARD_AUDIT_LOG'         => $auditLog,
    'DATAGUARD_AUDIT_SECRET'      => $auditSecret,
    'DATAGUARD_OPERATOR'          => 'pierroons@testhost',
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

$r = run(['unlock', 'pierroons', 'L3-open'], ADMIN_PASS . "\n", $env);
$r['code'] === 0 ? ok('exit 0') : ko('exit code', (string) $r['code'] . ' err=' . trim($r['err']));
str_contains($r['out'], CONTACT) ? ok('escrow contact_secours revealed to admin') : ko('secret not in output', $r['out']);

$audit = new AuditLog($auditLog, $auditSecret);
$entries = $audit->readAll();
$last = end($entries);
$last['event']['action'] === 'escrow-unlock' && $last['event']['target'] === 'pierroons'
    ? ok('success written to audit log (action=escrow-unlock)') : ko('audit entry wrong', json_encode($last));
in_array('contact_secours', $last['event']['fields'] ?? [], true) ? ok('audit records which fields were opened') : ko('fields not logged');
$last['event']['operator'] === 'pierroons@testhost' ? ok('audit records the operator (forensic)') : ko('operator not logged');

// -----------------------------------------------------------------------------

section('verify-log passes on the intact chain');

$r = run(['verify-log'], '', $env);
$r['code'] === 0 && str_contains($r['out'], 'intègre') ? ok('verify-log → exit 0, chain intact') : ko('verify-log failed', trim($r['out'] . $r['err']));

// -----------------------------------------------------------------------------

section('Anti-curieux — closed litige is refused');

$before = count((new AuditLog($auditLog, $auditSecret))->readAll());
$r = run(['unlock', 'pierroons', 'L3-closed'], ADMIN_PASS . "\n", $env);
$r['code'] === 2 ? ok('exit 2 (refused)') : ko('should refuse closed litige', (string) $r['code']);
str_contains($r['err'], 'Refusé') ? ok('stderr says Refusé') : ko('no refusal message');
$denyEntries = (new AuditLog($auditLog, $auditSecret))->readAll();
$deny = end($denyEntries);
$deny['event']['action'] === 'escrow-unlock-denied' && $deny['event']['reason'] === 'no-open-litige'
    ? ok('denied attempt is logged (reason=no-open-litige)') : ko('denial not logged', json_encode($deny));

// -----------------------------------------------------------------------------

section('Anti-curieux — unknown litige is refused');

$r = run(['unlock', 'pierroons', 'L3-does-not-exist'], ADMIN_PASS . "\n", $env);
$r['code'] === 2 ? ok('unknown litige → exit 2') : ko('should refuse unknown litige');

// -----------------------------------------------------------------------------

section('Passphrase gate — wrong passphrase is refused + logged');

$r = run(['unlock', 'pierroons', 'L3-open'], "mauvaise-passphrase\n", $env);
$r['code'] === 2 ? ok('wrong passphrase → exit 2') : ko('should refuse wrong passphrase', (string) $r['code']);
!str_contains($r['out'], CONTACT) ? ok('no secret leaked on wrong passphrase') : ko('LEAK on wrong passphrase');
$badEntries = (new AuditLog($auditLog, $auditSecret))->readAll();
$deny = end($badEntries);
$deny['event']['reason'] === 'bad-passphrase' ? ok('bad-passphrase attempt logged') : ko('bad-passphrase not logged', json_encode($deny));

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
