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

$r = $log->verifyUnanchored();
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

$r = $log->verifyUnanchored();
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

$r = $log2->verifyUnanchored();
!$r['ok'] ? ok('deleted middle entry → chain broken') : ko('deletion not detected');

// -----------------------------------------------------------------------------

section('Wrong secret cannot verify');

@unlink($path);
$log3 = new AuditLog($path, $secret);
$log3->append(['action' => 'x']);
$forged = new AuditLog($path, str_repeat('z', 32));
!$forged->verifyUnanchored()['ok'] ? ok('verification fails under a different secret') : ko('HMAC not binding the secret');

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
        (new AuditLog($cache, $secret32))->verifyUnanchored();
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

section('Concurrent writers keep one chain');

// Four processes append to the same log at once. Without the lock around the
// read-then-append, two of them read the same tail and write the same `seq`:
// the chain breaks at the first collision.
$shared  = tempnam(sys_get_temp_dir(), 'dg_audit_conc_');
$writers = 4;
$each    = 150;
$child   = sprintf(
    'require %s; $l = new Pierroons\SelfDataGuard\Escrow\AuditLog(%s, %s);'
    . ' for ($i = 0; $i < %d; $i++) { $l->append(["writer" => getmypid(), "n" => $i]); }',
    var_export(realpath(__DIR__ . '/../src/autoload.php'), true),
    var_export($shared, true),
    var_export($secret, true),
    $each
);
$procs = [];
for ($w = 0; $w < $writers; $w++) {
    $procs[] = proc_open([PHP_BINARY, '-r', $child], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
}
$childFailures = 0;
foreach ($procs as $proc) {
    $childFailures += proc_close($proc) === 0 ? 0 : 1;
}
$r = (new AuditLog($shared, $secret))->verifyUnanchored();
$childFailures === 0 && $r['ok'] && $r['count'] === $writers * $each
    ? ok(sprintf('%d writers × %d appends: one unbroken chain of %d entries', $writers, $each, $r['count']))
    : ko('concurrent appends broke the chain', json_encode($r + ['child_failures' => $childFailures]));
@unlink($shared);

// -----------------------------------------------------------------------------

section('The anchor sees what the chain cannot: a cut tail, a deleted log');

$anchored = tempnam(sys_get_temp_dir(), 'dg_audit_anchor_');
$alog = new AuditLog($anchored, $secret);
foreach (['un', 'deux', 'trois'] as $n) {
    $alog->append(['action' => 'escrow-unlock', 'target' => $n]);
}
$head = $alog->verifyUnanchored()['head'];
$head !== null && $head['seq'] === 2 ? ok('verify() returns the head of the chain') : ko('no head returned', json_encode($head));
$anchor = $head['seq'] . ':' . $head['hmac'];

$alog->verify($anchor)['ok'] ? ok('an intact log matches its anchor') : ko('intact log refused by its own anchor');

// Cut the last entry: the remaining two still form a valid chain.
$lines = file($anchored, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
file_put_contents($anchored, implode("\n", array_slice($lines, 0, 2)) . "\n");
$r = $alog->verifyUnanchored();
$r['ok'] && $r['count'] === 2
    ? ok('without the anchor, a cut tail still verifies (the limit the anchor closes)')
    : ko('the chain was expected to verify once cut', json_encode($r));
$r = $alog->verify($anchor);
!$r['ok'] && $r['reason'] === 'truncated'
    ? ok('with the anchor, the cut tail is refused as truncated')
    : ko('a truncated log passed its anchor', json_encode($r));
try {
    $alog->append(['action' => 'after the cut'], $anchor);
    ko('an entry was appended to a log that no longer holds the anchor it was given');
} catch (RuntimeException $e) {
    count(file($anchored, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) === 2 && str_contains($e->getMessage(), 'truncated')
        ? ok('append() given the anchor writes nothing to a log cut before it, and says how it lost it')
        : ko('append() refused for another reason, or wrote', $e->getMessage());
}

unlink($anchored);
$r = $alog->verify($anchor);
!$r['ok'] && $r['reason'] === 'truncated'
    ? ok('with the anchor, a deleted log is refused, not read as empty')
    : ko('a deleted log passed its anchor', json_encode($r));

try {
    $alog->verify('2:not-a-hmac');
    ko('a malformed anchor was accepted');
} catch (InvalidArgumentException) {
    ok('a malformed anchor is refused');
}

section('A tail a crash leaves is quarantined; an entry that does not hold, or a break in the middle, is not');

$qpath = tempnam(sys_get_temp_dir(), 'dg_audit_q_');
register_shutdown_function(static fn () => @unlink($qpath));
$qlog = new AuditLog($qpath, $secret);
$qlog->append(['action' => 'a']);
$qlog->append(['action' => 'b']);
$lines = static fn (): int => count(file($qpath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$signedTwo = file_get_contents($qpath);

file_put_contents($qpath, json_encode(['seq' => 2, 'ts' => 'x', 'event' => ['action' => 'forged'], 'prev' => 'p', 'hmac' => str_repeat('0', 64)]) . "\n", FILE_APPEND);
$r = $qlog->verifyUnanchored();
!$r['ok'] && $r['reason'] === 'chain' && $r['brokenAt'] === 2
    ? ok('an entry whose signature does not hold, at the end: a break at line 2, not a tail')
    : ko('a forged entry at the end was reported as something else', json_encode($r));
try {
    $qlog->append(['action' => 'over it']);
    ko('an entry was appended over a forged one: the forgery would have been set aside in silence');
} catch (RuntimeException) {
    $lines() === 3
        ? ok('nothing is appended over it: no crash writes an entry whose signature does not hold')
        : ko('the refused append wrote to the log');
}

file_put_contents($qpath, $signedTwo . '{"seq":2,"ts":"cut sho');
$r = $qlog->verifyUnanchored();
!$r['ok'] && $r['reason'] === 'unsigned-tail' && $r['brokenAt'] === 2 && ($r['head']['seq'] ?? null) === 1
    ? ok('a write cut short by a crash, without its end of line: unsigned-tail, from line 2, the head still the last signed entry')
    : ko('a cut tail was not reported as such', json_encode($r));

$act = $qlog->append(['action' => 'c']);
$r = $qlog->verifyUnanchored();
$act['seq'] === 3 && $r['ok'] && $r['quarantined'] === 1 && $r['count'] === 4 && $lines() === 5
    ? ok('the next append ends the cut line, quarantines it in a signed entry, then writes its act: the log verifies, and says one line was set aside')
    : ko('the cut tail was not quarantined on its own line', json_encode($r));
try {
    $qlog->append(['action' => 'c2']);
    $qlog->verifyUnanchored()['ok']
        ? ok('and the append after it goes on: the log is not broken for good')
        : ko('the log broke after the quarantine of a cut line');
} catch (RuntimeException $e) {
    ko('the log broke after the quarantine of a cut line', $e->getMessage());
}

file_put_contents($qpath, '{"seq":5,"ts":"cut' . "\n" . '{"seq":5,"ts":"cut again', FILE_APPEND);
$qlog->append(['action' => 'd']);
$r = $qlog->verifyUnanchored();
$r['ok'] && $r['quarantined'] === 3
    ? ok('two crashes in a row — a cut line, then a cut write after it — are quarantined together')
    : ko('two cut lines blocked the log', json_encode($r));

try {
    count($qlog->readAll()) === $r['count']
        ? ok('readAll() lists the chain: the lines a quarantine sets aside are not entries, and do not stop it')
        : ko('readAll() does not list the chain verify() walks');
} catch (RuntimeException $e) {
    ko('readAll() does not list the chain verify() walks', $e->getMessage());
}

$all = file($qpath, FILE_IGNORE_NEW_LINES);
$all[2] = str_replace('cut sho', 'cut shx', $all[2]);
file_put_contents($qpath, implode("\n", $all) . "\n");
$r = $qlog->verifyUnanchored();
!$r['ok'] && $r['reason'] === 'chain'
    ? ok('a quarantined line changed afterwards breaks the chain: a second incident, not the first one again')
    : ko('a change to quarantined bytes went unnoticed', json_encode($r));
try {
    $qlog->readAll();
    ko('readAll() listed a log broken mid-chain');
} catch (RuntimeException) {
    ok('readAll() refuses a log broken mid-chain, as append() does');
}

$mpath = tempnam(sys_get_temp_dir(), 'dg_audit_m_');
register_shutdown_function(static fn () => @unlink($mpath));
$mlog = new AuditLog($mpath, $secret);
foreach (['a', 'b', 'c'] as $action) {
    $mlog->append(['action' => $action]);
}
$all = file($mpath, FILE_IGNORE_NEW_LINES);
$all[1] = str_replace('"b"', '"B"', $all[1]);
file_put_contents($mpath, implode("\n", $all) . "\n");
try {
    $mlog->append(['action' => 'over it']);
    ko('an entry was appended over a break in the middle of the chain');
} catch (RuntimeException $e) {
    $r = $mlog->verifyUnanchored();
    count(file($mpath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) === 3 && !$r['ok'] && $r['reason'] === 'chain' && $r['brokenAt'] === 1
        ? ok('a signed entry after a changed one is a break in the middle: never quarantined, nothing appended, reported at line 1')
        : ko('the break in the middle was handled as a tail', json_encode($r));
}

try {
    $qlog->append([AuditLog::RESERVED_KEY => 'quarantine', 'lines' => 1]);
    ko('an application event carried the key the log reserves for itself');
} catch (InvalidArgumentException) {
    ok('an application event cannot carry the key the log reserves for itself');
}

// -----------------------------------------------------------------------------
section('An entry that cannot be forced to disk is reported as failed');

/**
 * A stream over a real file that does everything the log needs except fsync():
 * PHP refuses fsync() on any userspace stream. An append that never asks for
 * the disk passes through it unnoticed; one that does fails, and must say so.
 */
final class NoSyncStream
{
    /** @var resource|null */
    public $context;
    /** @var resource */
    private $h;

    private static function file(string $url): string
    {
        return substr($url, strlen('nosync://'));
    }

    public function stream_open(string $url, string $mode, int $options, ?string &$opened): bool
    {
        $h = @fopen(self::file($url), $mode);
        if ($h === false) {
            return false;
        }
        $this->h = $h;

        return true;
    }

    public function stream_read(int $n): string|false { return fread($this->h, $n); }
    public function stream_write(string $d): int { return (int) fwrite($this->h, $d); }
    public function stream_flush(): bool { return fflush($this->h); }
    public function stream_eof(): bool { return feof($this->h); }
    public function stream_lock(int $op): bool { return flock($this->h, $op); }
    public function stream_stat(): array|false { return fstat($this->h); }
    public function stream_close(): void { fclose($this->h); }

    public function url_stat(string $url, int $flags): array|false
    {
        $f = self::file($url);

        return file_exists($f) ? stat($f) : false;
    }
}

stream_wrapper_register('nosync', NoSyncStream::class);
$durable = tempnam(sys_get_temp_dir(), 'dg_audit_sync_');
(new AuditLog($durable, $secret))->append(['action' => 'before']);
$viaStream = new AuditLog('nosync://' . $durable, $secret);
count($viaStream->readAll()) === 1
    ? ok('control: the log reads through the stream')
    : ko('the stream does not read the log — the check below would prove nothing');
try {
    $viaStream->append(['action' => 'after']);
    ko('an append was reported written on a stream that cannot be synced');
} catch (RuntimeException $e) {
    str_starts_with($e->getMessage(), 'Cannot sync audit log')
        ? ok('an append that cannot be forced to disk is reported as failed')
        : ko('the append failed, but not on the sync', $e->getMessage());
}
unlink($durable);

section('The audit secret does not ride an exception trace');

/**
 * The arguments a stack trace keeps for the frame that called `$function`, or
 * null when the runtime drops them. `zend.exception_ignore_args` is On in a
 * production php.ini: left alone, the check below would read an empty frame and
 * pass on nothing, so the setting is forced off and the frame must carry the
 * public argument before the secret one is looked for.
 */
function traceArgs(callable $call, string $function): ?array
{
    ini_set('zend.exception_ignore_args', '0');
    try {
        $call();
    } catch (Throwable $e) {
        foreach ($e->getTrace() as $frame) {
            if (($frame['function'] ?? '') === $function) {
                return $frame['args'] ?? null;
            }
        }
    }

    return null;
}

$dumpedLog = print_r($log, true);
str_contains($dumpedLog, $path)
    ? ok('precondition: the AuditLog dump names its file')
    : ko('precondition: the AuditLog dump does not name its file — the check below would measure nothing');
!str_contains($dumpedLog, $secret)
    ? ok('print_r() of an AuditLog does not carry auditSecret')
    : ko('auditSecret is readable in a dump of the AuditLog');

$tooShort = 'short-audit-secret';
$args = traceArgs(static fn () => new AuditLog($path, $tooShort), '__construct');
if ($args === null || !in_array($path, $args, true)) {
    ko('precondition: the trace does not show the log path — nothing below would be measured');
} elseif (PHP_VERSION_ID < 80200) {
    // #[\SensitiveParameter] only takes effect from PHP 8.2; the library runs on 8.1.
    echo '  ↷ PHP ' . PHP_VERSION . ': #[\SensitiveParameter] only takes effect from 8.2 — '
        . "on this runtime the trace still carries auditSecret (declared limit, not counted)\n";
} else {
    !in_array($tooShort, $args, true)
        ? ok('the AuditLog constructor hides auditSecret from the trace')
        : ko('auditSecret is readable in the trace of a refused constructor');
}

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  AuditLog Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
