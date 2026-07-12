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

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  AuditLog Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
