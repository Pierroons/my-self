<?php

declare(strict_types=1);

/**
 * Sanity smoke test for AuditLog — append-only, hash-chained, HMAC-signed.
 *
 * Run:  php tests/sanity_audit.php
 * Exit: 0 on success, non-zero on first failure.
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Escrow\AuditLog;

$failures = 0;
$passes = 0;
function ok(string $l): void { global $passes; $passes++; echo "  ✅ {$l}\n"; }
function ko(string $l, string $d = ''): void { global $failures; $failures++; echo "  ❌ {$l}" . ($d ? " — {$d}" : '') . "\n"; }
function section(string $t): void { echo "\n→ {$t}\n"; }

$path   = tempnam(sys_get_temp_dir(), 'dg_audit_');
$secret = str_repeat('k', 32);
$log    = new AuditLog($path, $secret);

// -----------------------------------------------------------------------------

section('Append + verify a clean chain');

$log->append(['action' => 'escrow-unlock', 'target' => 'alice', 'seq_hint' => 1]);
$log->append(['action' => 'escrow-unlock-denied', 'reason' => 'no-open-litige', 'target' => 'bob']);
$log->append(['action' => 'escrow-unlock', 'target' => 'carol']);

$r = $log->verify();
$r['ok'] && $r['count'] === 3 ? ok('3 entries, chain + signatures valid') : ko('clean chain should verify', json_encode($r));

$entries = $log->readAll();
$entries[0]['seq'] === 0 && $entries[2]['seq'] === 2 ? ok('sequence numbers increment from 0') : ko('seq wrong');
$entries[1]['prev'] === $entries[0]['hmac'] ? ok('entry N.prev links to entry N-1.hmac') : ko('chain linkage wrong');

// -----------------------------------------------------------------------------

section('Tamper detection — edit an entry payload');

$lines = file($path, FILE_IGNORE_NEW_LINES);
$rec1  = json_decode($lines[1], true);
$rec1['event']['target'] = 'mallory';                 // forge the target
$lines[1] = json_encode($rec1, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
file_put_contents($path, implode("\n", $lines) . "\n");

$r = $log->verify();
!$r['ok'] && $r['brokenAt'] === 1 ? ok('edited payload → chain broken at #1') : ko('tamper not detected', json_encode($r));

// -----------------------------------------------------------------------------

section('Tamper detection — delete a middle entry');

@unlink($path);
$log2 = new AuditLog($path, $secret);
$log2->append(['action' => 'a']);
$log2->append(['action' => 'b']);
$log2->append(['action' => 'c']);
$lines = file($path, FILE_IGNORE_NEW_LINES);
unset($lines[1]);                                     // drop the middle entry
file_put_contents($path, implode("\n", array_values($lines)) . "\n");

$r = $log2->verify();
!$r['ok'] ? ok('deleted middle entry → chain broken') : ko('deletion not detected');

// -----------------------------------------------------------------------------

section('Wrong secret cannot verify');

@unlink($path);
$log3 = new AuditLog($path, $secret);
$log3->append(['action' => 'x']);
$forged = new AuditLog($path, str_repeat('z', 32));
!$forged->verify()['ok'] ? ok('verification fails under a different secret') : ko('HMAC not binding the secret');

@unlink($path);

// -----------------------------------------------------------------------------

section('"Unreadable" is never answered as "no events"');

// 🔑 The defect this section pins: readAll() used to return [] both when the log was
// absent AND when it could not be read, and verify() reads through readAll() — so an
// unreadable chain answered {ok: true, count: 0}. The tamper detector reassured itself
// about a file it had never opened. Met on an integrator deployment, 15/09/2026.

$secret32 = str_repeat('k', 32);

// A log that is genuinely absent, in a directory we CAN see, is still an empty log.
$vide = sys_get_temp_dir() . '/dg_absent_' . bin2hex(random_bytes(4)) . '.jsonl';
try {
    (new AuditLog($vide, $secret32))->readAll() === []
        ? ok('a genuinely absent log still reads as empty')
        : ko('an absent log should read as empty');
} catch (Throwable $e) {
    ko('an absent log should not throw', $e->getMessage());
}

// The directory case — the one met in the field: the file exists, the directory is not
// traversable, and is_file() answers false for a file that is really there.
// Running as root bypasses the permission, so the case is NOT silently skipped:
// it is announced, because a check that quietly does not run is the very fault
// this section exists to close.
if (posix_getuid() === 0) {
    echo "  ⏭️  répertoire non traversable : NON ÉPROUVÉ ici (lancé en root, qui outrepasse les droits)\n";
} else {
    $dir = sys_get_temp_dir() . '/dg_opaque_' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    $cache = $dir . '/audit.jsonl';
    (new AuditLog($cache, $secret32))->append(['action' => 'escrow-unlock', 'target' => 'dave']);
    chmod($dir, 0000);
    try {
        $r = (new AuditLog($cache, $secret32))->readAll();
        ko('an unreadable directory must not read as empty', 'got ' . count($r) . ' entries');
    } catch (RuntimeException $e) {
        str_contains($e->getMessage(), 'not traversable')
            ? ok('an unreadable directory throws instead of answering "no events"')
            : ko('threw, but not on the traversability cause', $e->getMessage());
    }
    // And the consequence that made the defect severe: verify() must not answer ok.
    try {
        (new AuditLog($cache, $secret32))->verify();
        ko('verify() must not report a valid chain it could not read');
    } catch (RuntimeException) {
        ok('verify() refuses instead of reporting {ok: true, count: 0}');
    }
    chmod($dir, 0700);
    @unlink($cache);
    @rmdir($dir);
}

section('One declared floor for a deployment secret');

try {
    new AuditLog($vide, str_repeat('k', AuditLog::PLANCHER_SECRET - 1));
    ko('a secret below the floor must be refused');
} catch (InvalidArgumentException $e) {
    str_contains($e->getMessage(), (string) AuditLog::PLANCHER_SECRET)
        ? ok('a secret below the floor is refused, and the message names the floor')
        : ko('refused, but the message does not name the floor', $e->getMessage());
}
AuditLog::PLANCHER_SECRET === 32
    ? ok('the floor is 32 — the value every other consumer already required')
    : ko('floor drifted', (string) AuditLog::PLANCHER_SECRET);

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  AuditLog Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
