<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Append-only, hash-chained, HMAC-signed audit log for privileged escrow acts
 * (admin recovery unlocks). Same bar as the SelfRecover-SU journal: every entry
 * is chained to the previous one and signed, so any deletion, reordering or
 * edit made without the secret breaks verification. Whoever holds the secret can
 * rewrite the chain after the last anchor: `verify($anchor)` covers what precedes it.
 *
 * On-disk format: one JSON object per line, keys in fixed order
 *   {"seq":N,"ts":"ISO8601","event":{...},"prev":"<hmac of entry N-1>","hmac":"<hmac>"}
 * where hmac = HMAC-SHA256( json({seq,ts,event,prev}), auditSecret ).
 *
 * Deployment hardening (ops, not code): set the file `chattr +a` (append-only):
 * no process rewrites it while the flag is set, but root can lift the flag.
 */
final class AuditLog
{
    /**
     * Floor for a deployment-provided shared secret, in characters.
     *
     * 🔑 **One declared value, checked mechanically.** This class asked for ≥16 while
     * every other secret consumer in the repository asks for 32 — `demo/lab/lib/auth.php`,
     * `dataguard.php`, `security.php` and their benches. Three floors for one kind of
     * value, and nothing made the divergence visible: a 20-character secret was accepted
     * here and refused next door, and the operator met "service misconfigured" from the
     * strict side while the lax side had already taken it (measured on an integrator
     * deployment, 15/09/2026, on secrets of 28 characters).
     *
     * Raising 16 → 32 refuses nothing this project generates: every generator writes
     * hex from at least 32 random bytes, i.e. 64 characters. `scripts/check-plancher-secret.sh`
     * fails the build if any declared floor drifts from this one.
     */
    public const PLANCHER_SECRET = 32;

    public function __construct(
        private readonly string $path,
        #[\SensitiveParameter] private readonly string $auditSecret
    ) {
        if (strlen($auditSecret) < self::PLANCHER_SECRET) {
            throw new InvalidArgumentException(sprintf(
                'auditSecret must be ≥%d characters (got %d) — it signs the escrow audit chain.',
                self::PLANCHER_SECRET,
                strlen($auditSecret)
            ));
        }
    }

    /**
     * The key no application event may carry: it marks the entries the log
     * writes for itself.
     */
    public const RESERVED_KEY = '_selfdataguard';

    /**
     * Append a signed, chained entry. Returns the written record.
     *
     * The line is forced to disk before this returns. Two limits: when the sync
     * fails, the exception leaves the line in the file — an entry without its
     * act, the safe way round for a log written before the act; and the
     * directory is not synced when the file is created, so a crash can take
     * the very first entry of a new log with it, which only an anchor taken
     * after that entry can reveal.
     *
     * A tail of lines that do not have the shape of an entry — what a crash
     * leaves, a line cut short — is put in quarantine first: a signed entry,
     * chained on the last verified one, that records how many lines it sets
     * aside and their SHA-256. The decision is in the chain, and the bytes it
     * covers stay checkable. A cut line has no end of line: one is written
     * before the quarantine, which would otherwise continue it. An entry whose
     * signature does not hold, or a signed entry standing where the chain does
     * not lead, is not a crash: nothing is appended over it.
     *
     * With $anchor ("seq:hmac", a head taken out of the machine earlier), the
     * entry is appended only if the log still holds that head — checked under
     * the same lock as the write.
     *
     * @param array<string, mixed> $event application-defined payload
     * @return array<string, mixed>
     */
    public function append(array $event, ?string $anchor = null): array
    {
        if (array_key_exists(self::RESERVED_KEY, $event)) {
            throw new InvalidArgumentException('The event key ' . self::RESERVED_KEY . ' is reserved for the log itself');
        }
        $anchorAt = $anchor === null ? null : self::parseAnchor($anchor);
        // 🔑 The chain is read and extended under ONE exclusive lock. Read outside
        // it, two concurrent writers computed the same `seq` and `prev`, and the
        // second entry broke verification for every entry after it.
        $handle = @fopen($this->path, 'a');
        if ($handle === false) {
            throw new RuntimeException("Cannot open audit log at {$this->path}");
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("Cannot lock audit log at {$this->path}");
            }
            [$lines, $cutOff] = $this->readRaw();
            $state = $this->scan($lines);
            if ($state['broken'] !== null) {
                throw new RuntimeException(sprintf(
                    'Audit log %s is broken mid-chain at line %d: nothing is appended over it — it is an incident to examine',
                    $this->path,
                    $state['brokenAt']
                ));
            }
            if ($anchorAt !== null && ($found = self::anchorFound($state['chain'], $anchorAt)) !== null) {
                throw new AuditAnchorException(sprintf(
                    'Audit log %s no longer holds the anchor %d:%s — %s: nothing is appended',
                    $this->path,
                    $anchorAt['seq'],
                    $anchorAt['hmac'],
                    $found
                ), $found);
            }
            $seq  = count($state['chain']);
            $prev = $state['chain'] === [] ? '' : (string) end($state['chain'])['hmac'];

            $out = $cutOff ? "\n" : '';
            if ($state['pending'] !== []) {
                $quarantine = $this->record($seq++, [
                    self::RESERVED_KEY => 'quarantine',
                    'lines'            => count($state['pending']),
                    'sha256'           => hash('sha256', implode("\n", $state['pending'])),
                ], $prev);
                $prev = (string) $quarantine['hmac'];
                $out .= $this->line($quarantine);
            }
            $record = $this->record($seq, $event, $prev);
            $out   .= $this->line($record);

            if (fwrite($handle, $out) !== strlen($out) || !fflush($handle)) {
                throw new RuntimeException("Cannot append to audit log at {$this->path}");
            }
            // fflush() hands the line to the kernel, not to the disk: a power cut
            // after it loses the entry, while the act it records stays done.
            if (!@fsync($handle)) {
                throw new RuntimeException("Cannot sync audit log at {$this->path} to disk");
            }
            return $record;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Verify the chain against an anchor: a head recorded earlier, outside this
     * machine, as "seq:hmac". The chain alone cannot see its own end cut off — a
     * log truncated after entry N, or deleted outright, is a valid chain of N
     * entries, or of none. The entry `seq` must still be there with that HMAC.
     *
     * @return array{ok: bool, count: int, brokenAt: ?int, reason: ?string,
     *               head: ?array{seq: int, hmac: string}, quarantined: int}
     *         reason: chain, unsigned-tail, truncated or rewritten; quarantined:
     *         how many lines quarantine entries have set aside
     */
    public function verify(string $anchor): array
    {
        return $this->check(self::parseAnchor($anchor));
    }

    /**
     * Verify the chain without an anchor — and so without seeing a cut tail or a
     * deleted log. Named for that: there is no verify() that forgets it.
     *
     * @return array{ok: bool, count: int, brokenAt: ?int, reason: ?string,
     *               head: ?array{seq: int, hmac: string}, quarantined: int}
     */
    public function verifyUnanchored(): array
    {
        return $this->check(null);
    }

    /**
     * @param array{seq: int, hmac: string}|null $anchorAt
     * @return array{ok: bool, count: int, brokenAt: ?int, reason: ?string,
     *               head: ?array{seq: int, hmac: string}, quarantined: int}
     */
    private function check(?array $anchorAt): array
    {
        $state   = $this->scan($this->readLines());
        $entries = $state['chain'];
        $report  = ['count' => count($entries), 'quarantined' => $state['quarantined']];
        if ($state['broken'] !== null) {
            return $report + ['ok' => false, 'brokenAt' => $state['brokenAt'], 'reason' => 'chain', 'head' => null];
        }

        $head = $entries === []
            ? null
            : ['seq' => count($entries) - 1, 'hmac' => (string) end($entries)['hmac']];

        if ($anchorAt !== null && ($found = self::anchorFound($entries, $anchorAt)) !== null) {
            return $report + ['ok' => false, 'brokenAt' => $anchorAt['seq'], 'reason' => $found, 'head' => $head];
        }
        if ($state['pending'] !== []) {
            return $report + ['ok' => false, 'brokenAt' => $state['brokenAt'], 'reason' => 'unsigned-tail', 'head' => $head];
        }
        return $report + ['ok' => true, 'brokenAt' => null, 'reason' => null, 'head' => $head];
    }

    /**
     * @return array{seq: int, hmac: string}
     */
    private static function parseAnchor(string $anchor): array
    {
        if (!preg_match('/^(\d+):([0-9a-f]{64})$/', $anchor, $m)) {
            throw new InvalidArgumentException('Malformed audit anchor (expected "seq:hmac", hmac in hex)');
        }
        return ['seq' => (int) $m[1], 'hmac' => $m[2]];
    }

    /**
     * Null when the chain holds the anchor; otherwise how it lost it.
     *
     * @param list<array<string, mixed>>        $chain
     * @param array{seq: int, hmac: string}     $anchorAt
     * @return 'truncated'|'rewritten'|null
     */
    private static function anchorFound(array $chain, array $anchorAt): ?string
    {
        $kept = $chain[$anchorAt['seq']]['hmac'] ?? null;
        if ($kept === null) {
            return 'truncated';
        }
        return hash_equals($anchorAt['hmac'], (string) $kept) ? null : 'rewritten';
    }

    /**
     * Walk the lines once. A signed entry that links extends the chain; a line
     * without the shape of an entry waits, and only a quarantine entry covering
     * exactly the lines waiting — their number and their SHA-256 — lets the
     * chain go on past them. An entry whose signature does not hold, or a signed
     * entry anywhere else, breaks it.
     *
     * @param list<string> $lines
     * @return array{chain: list<array<string, mixed>>, pending: list<string>, quarantined: int,
     *               broken: ?int, brokenAt: ?int}
     *         broken: the line of the signed entry out of place; brokenAt: the first
     *         line that is not part of the chain
     */
    private function scan(array $lines): array
    {
        $chain = [];
        $pending = [];
        $pendingFrom = null;
        $quarantined = 0;
        $prev = '';
        foreach ($lines as $n => $line) {
            $rec = json_decode($line, true);
            $shaped = is_array($rec) && is_string($rec['hmac'] ?? null)
                && array_key_exists('seq', $rec) && array_key_exists('ts', $rec)
                && array_key_exists('event', $rec) && array_key_exists('prev', $rec);
            if (!$shaped) {
                $pendingFrom ??= $n;
                $pending[] = $line;
                continue;
            }
            $signed = hash_equals($this->hmac([
                'seq'   => $rec['seq'],
                'ts'    => $rec['ts'],
                'event' => $rec['event'],
                'prev'  => $rec['prev'],
            ]), $rec['hmac']);
            $linked = ($rec['prev'] ?? null) === $prev && ($rec['seq'] ?? null) === count($chain);
            $isQuarantine = (($rec['event'][self::RESERVED_KEY] ?? null) === 'quarantine');
            $covers = $isQuarantine
                ? $pending !== []
                    && ($rec['event']['lines'] ?? null) === count($pending)
                    && hash_equals(hash('sha256', implode("\n", $pending)), (string) ($rec['event']['sha256'] ?? ''))
                : $pending === [];
            if (!$signed || !$linked || !$covers) {
                return ['chain' => $chain, 'pending' => $pending, 'quarantined' => $quarantined,
                        'broken' => $n, 'brokenAt' => $pendingFrom ?? $n];
            }
            $quarantined += count($pending);
            $pending = [];
            $pendingFrom = null;
            $chain[] = $rec;
            $prev = (string) $rec['hmac'];
        }
        return ['chain' => $chain, 'pending' => $pending, 'quarantined' => $quarantined,
                'broken' => null, 'brokenAt' => $pendingFrom];
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function record(int $seq, array $event, string $prev): array
    {
        $signable = [
            'seq'   => $seq,
            'ts'    => (new DateTimeImmutable())->format('c'),
            'event' => $event,
            'prev'  => $prev,
        ];
        return $signable + ['hmac' => $this->hmac($signable)];
    }

    /** @param array<string, mixed> $record */
    private function line(array $record): string
    {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            throw new RuntimeException('Failed to encode audit record');
        }
        return $line . "\n";
    }

    /**
     * 🔑 **"Unreadable" is never reported as "no events".**
     *
     * This method used to swallow failure twice — `!is_file()` returned `[]`, and
     * `file(...) ?: []` turned a read error into an empty array. Either one made an
     * audit log we could not read look exactly like an audit log with nothing in it.
     *
     * The consequence is worse than it first appears, because `verify()` reads through
     * here: an unreadable log made the whole chain check answer `{ok: true, count: 0}`.
     * The tamper detector reassured itself about a file it had never opened.
     *
     * The distinction also has to survive an unreadable *parent directory*: when the
     * directory is not traversable, `is_file()` answers false for a file that is really
     * there. That case was met on an integrator deployment (15/09/2026), with a
     * container running as uid 1000 against a `700 root` directory — the service
     * announced "certificate absent" for a certificate that existed.
     *
     * What it lists is the chain `verify()` walks, quarantine entries included:
     * the lines a quarantine sets aside are not entries.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException when the log cannot be read, when its absence
     *                          cannot be established, or when it is broken mid-chain
     */
    public function readAll(): array
    {
        $state = $this->scan($this->readLines());
        if ($state['broken'] !== null) {
            throw new RuntimeException(sprintf(
                'Audit log %s is broken mid-chain at line %d: its entries cannot be listed as the chain',
                $this->path,
                $state['brokenAt']
            ));
        }
        return $state['chain'];
    }

    /**
     * The raw lines of the log — the same refusal as readAll() when the log, or
     * the fact of its absence, cannot be read.
     *
     * @return list<string>
     */
    private function readLines(): array
    {
        return $this->readRaw()[0];
    }

    /**
     * @return array{0: list<string>, 1: bool} the lines, and whether the last one
     *         has no end of line — what a write cut short by a crash leaves
     */
    private function readRaw(): array
    {
        // PHP caches stat results (and resolved paths) for the life of the
        // process: a log this process wrote, whose directory was closed since,
        // would still look present — before PHP 8.4, is_file() then answers
        // from the cache, and the traversability cause is never named.
        clearstatcache(true, $this->path);
        if (!is_file($this->path)) {
            $dir = dirname($this->path);
            if (!is_dir($dir) || !is_readable($dir) || !is_executable($dir)) {
                throw new RuntimeException(sprintf(
                    'Cannot establish whether the audit log exists: %s is not traversable. '
                    . 'Refusing to answer "no events" — unreadable is not empty.',
                    $dir
                ));
            }

            return [[], false];
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw new RuntimeException(sprintf(
                'Audit log present but unreadable: %s. '
                . 'Refusing to answer "no events" — unreadable is not empty.',
                $this->path
            ));
        }
        $lines = [];
        foreach (explode("\n", $raw) as $line) {
            $line = str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return [$lines, $raw !== '' && !str_ends_with($raw, "\n")];
    }

    /**
     * @param array<string, mixed> $signable
     */
    private function hmac(array $signable): string
    {
        $canonical = json_encode($signable, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($canonical === false) {
            throw new RuntimeException('Failed to canonicalize audit record');
        }
        return hash_hmac('sha256', $canonical, $this->auditSecret);
    }

    /** Keep the chain's signing secret out of var_dump() and print_r(). */
    public function __debugInfo(): array
    {
        return ['path' => $this->path, 'auditSecret' => '[redacted]'];
    }
}
