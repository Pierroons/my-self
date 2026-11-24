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
 * Rotation, when the log is given $rotateAfter: once the current file holds
 * that many entries, the next append() renames it to an archive and opens a
 * new file — the current file keeps the path given. Archives are numbered in
 * order and never renumbered, the number before the extension when the name
 * has one: `moderation.log` gives `moderation.1.log`, `moderation.2.log`; a
 * name without one gives `<path>.1`. A rule that guards the extension guards
 * the archives too; the log still belongs outside any directory a web server
 * serves. Nothing is deleted: how long archives are kept is the host's — the
 * most recent one is read again by an anchor taken just before a rotation.
 *
 * A new file opens with an entry of the log itself, chained on the archive's
 * last one: `{"_selfdataguard":"segment","previous":"<archive name>",
 * "head":"seq:hmac","count":n}`. `seq` goes on across files, never back to 0.
 * A rotation cut short — the archive renamed, the new file not yet written —
 * is finished by the next append(): a current file absent, or holding no
 * entry, while archives exist is never taken for an empty log.
 *
 * Writers of a rotating log lock `<path>.lock`, a file of its own, created
 * once and never removed: a writer waiting through a rotation takes the lock
 * on the same file, never on a renamed one. A log without $rotateAfter locks
 * the log file itself, as before 0.10.0: every writer of one log is given the
 * same $rotateAfter, or two of them would not wait on each other.
 *
 * Deployment hardening (ops, not code): set the file `chattr +a` (append-only):
 * no process rewrites it while the flag is set, but root can lift the flag. A
 * log flagged so does not rotate — rename is refused, and so is the append.
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

    /** The smallest file that rotates: its opening entry and one act. */
    public const MIN_ROTATE_AFTER = 2;

    private const ANCHOR_FORM = '/^(\d+):([0-9a-f]{64})$/';

    /**
     * @param ?int $rotateAfter entries a file holds before the next append() rotates it; null: never
     * @param ?int $mode        permissions of every file this log creates, its first one included;
     *                          null: a new file takes those of the file it follows, the very first
     *                          one those the umask leaves
     */
    public function __construct(
        private readonly string $path,
        #[\SensitiveParameter] private readonly string $auditSecret,
        private readonly ?int $rotateAfter = null,
        private readonly ?int $mode = null
    ) {
        if (strlen($auditSecret) < self::PLANCHER_SECRET) {
            throw new InvalidArgumentException(sprintf(
                'auditSecret must be ≥%d characters (got %d) — it signs the escrow audit chain.',
                self::PLANCHER_SECRET,
                strlen($auditSecret)
            ));
        }
        if ($rotateAfter !== null && $rotateAfter < self::MIN_ROTATE_AFTER) {
            throw new InvalidArgumentException(sprintf('rotateAfter must be ≥%d (got %d)', self::MIN_ROTATE_AFTER, $rotateAfter));
        }
        if ($mode !== null && ($mode < 0 || $mode > 0o777 || ($mode & 0o600) !== 0o600)) {
            throw new InvalidArgumentException('mode is a permission mode up to 0777 that keeps the owner\'s read and write: the log reads itself back');
        }
    }

    /**
     * The key no application event may carry: it marks the entries the log
     * writes for itself.
     */
    public const RESERVED_KEY = '_selfdataguard';

    /**
     * Append a signed, chained entry. Returns the written record — its `seq`
     * counts every entry of the log before it, across files.
     *
     * The line is forced to disk before this returns. Two limits: when the sync
     * fails, the exception leaves the line in the file — an entry without its
     * act, the safe way round for a log written before the act; and the
     * directory is not synced when a file is created or renamed, so a crash can
     * take the very first entry of a new file with it, which only an anchor
     * taken after that entry can reveal.
     *
     * A tail of lines that do not have the shape of an entry — what a crash
     * leaves, a line cut short — is put in quarantine first: a signed entry,
     * chained on the last verified one, that records how many lines it sets
     * aside and their SHA-256. The decision is in the chain, and the bytes it
     * covers stay checkable. A cut line has no end of line: one is written
     * before the quarantine, which would otherwise continue it. A quarantine
     * stays in the file it covers, before any rotation; in a file that holds no
     * entry yet, the opening entry covers those lines itself. An entry whose
     * signature does not hold, or a signed entry standing where the chain does
     * not lead, is not a crash: nothing is appended over it.
     *
     * A rotation that fails makes the append fail, and the act is written
     * nowhere.
     *
     * With $anchor ("seq:hmac", a head taken out of the machine earlier), the
     * entry is appended only if the log still holds that head — checked under
     * the same lock as the write; a head older than the current file is looked
     * for in the archives, back to it.
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
        $rotating = $this->rotateAfter !== null;
        $lock = @fopen($rotating ? $this->lockPath() : $this->path, $rotating ? 'c' : 'a');
        if ($lock === false) {
            throw new RuntimeException($rotating ? "Cannot open the lock of audit log {$this->path}" : "Cannot open audit log at {$this->path}");
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException("Cannot lock audit log at {$this->path}");
            }
            return $this->appendLocked($event, $anchorAt);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<string, mixed>              $event
     * @param array{seq: int, hmac: string}|null $anchorAt
     * @return array<string, mixed>
     */
    private function appendLocked(array $event, ?array $anchorAt): array
    {
        [$lines, $cutOff] = $this->readRaw($this->path);
        $current = $this->scan($lines) + ['file' => $this->path];
        if ($current['broken']) {
            throw new RuntimeException(sprintf(
                'Audit log %s is broken mid-chain at entry %d: nothing is appended over it — it is an incident to examine',
                $this->path,
                $current['brokenAt']
            ));
        }
        if ($anchorAt !== null) {
            $walk  = $this->segments($anchorAt['seq'], $current);
            $found = match ($walk['reason']) {
                'chain', 'segment-mismatch' => 'rewritten',
                'segment-missing'           => 'truncated',
                default                     => self::anchorLost(self::entriesOf($walk), $anchorAt),
            };
            if ($found !== null) {
                throw new AuditAnchorException(sprintf(
                    'Audit log %s no longer holds the anchor %d:%s — %s: nothing is appended',
                    $this->path,
                    $anchorAt['seq'],
                    $anchorAt['hmac'],
                    $found
                ), $found);
            }
        }

        $out   = $cutOff ? "\n" : '';
        $count = count($current['chain']);
        $last  = $count === 0 ? $this->lastArchive() : null;
        if ($last !== null) {
            // A file that holds no entry after archives: a rotation cut short. It opens on the last archive.
            $head    = end($last['chain']);
            $opening = $this->record((int) $head['seq'] + 1, self::opening($last, $current['pending']), (string) $head['hmac']);
            $out    .= $this->line($opening);
            $seq     = (int) $opening['seq'] + 1;
            $prev    = (string) $opening['hmac'];
            $count   = 1;
            $perms   = $this->mode ?? self::permsOf($last['file']);
        } else {
            $seq   = $count === 0 ? 0 : (int) end($current['chain'])['seq'] + 1;
            $prev  = $count === 0 ? '' : (string) end($current['chain'])['hmac'];
            $perms = $lines === [] && !$cutOff ? $this->mode : null;
            if ($current['pending'] !== []) {
                $quarantine = $this->record($seq++, [
                    self::RESERVED_KEY => 'quarantine',
                    'lines'            => count($current['pending']),
                    'sha256'           => hash('sha256', implode("\n", $current['pending'])),
                ], $prev);
                $prev = (string) $quarantine['hmac'];
                $out .= $this->line($quarantine);
                $count++;
            }
        }

        $fresh = false;
        if ($this->rotateAfter !== null && $count >= $this->rotateAfter) {
            if ($out !== '') {
                $this->write($out, false, $perms);
            }
            $perms   = $this->mode ?? self::permsOf($this->path);
            $archive = $this->nextArchive();
            if (!@rename($this->path, $archive)) {
                throw new RuntimeException("Cannot rotate audit log {$this->path} to {$archive}: nothing is appended");
            }
            $opening = $this->record($seq, [
                self::RESERVED_KEY => 'segment',
                'previous'         => basename($archive),
                'head'             => ($seq - 1) . ':' . $prev,
                'count'            => $count,
            ], $prev);
            $out   = $this->line($opening);
            $seq   = (int) $opening['seq'] + 1;
            $prev  = (string) $opening['hmac'];
            $fresh = true;
        }

        $record = $this->record($seq, $event, $prev);
        $this->write($out . $this->line($record), $fresh, $perms);
        return $record;
    }

    /**
     * $out written at the end of the current file and forced to disk — the
     * file created first when $fresh, and given $perms when not null.
     */
    private function write(string $out, bool $fresh, ?int $perms): void
    {
        clearstatcache(true, $this->path);
        $handle = @fopen($this->path, $fresh ? 'x' : 'a');
        if ($handle === false) {
            throw new RuntimeException($fresh
                ? "Cannot start a new file of audit log {$this->path}: the act is written nowhere — the next append finishes the rotation"
                : "Cannot open audit log at {$this->path}");
        }
        try {
            if ($perms !== null && !@chmod($this->path, $perms)) {
                throw new RuntimeException("Cannot set the mode of audit log {$this->path}: nothing is appended");
            }
            if (fwrite($handle, $out) !== strlen($out) || !fflush($handle)) {
                throw new RuntimeException("Cannot append to audit log at {$this->path}");
            }
            // fflush() hands the line to the kernel, not to the disk: a power cut
            // after it loses the entry, while the act it records stays done.
            if (!@fsync($handle)) {
                throw new RuntimeException("Cannot sync audit log at {$this->path} to disk");
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Verify the chain against an anchor: a head recorded earlier, outside this
     * machine, as "seq:hmac". The chain alone cannot see its own end cut off — a
     * log truncated after entry N, or deleted outright, is a valid chain of N
     * entries, or of none. The entry `seq` must still be there with that HMAC.
     * The files of the log are walked from the one that holds the anchor to the
     * current one, each linked to the next.
     *
     * @return array{ok: bool, count: int, total: int, brokenAt: ?int, reason: ?string,
     *               head: ?array{seq: int, hmac: string}, quarantined: int}
     *         count: the entries this call verified; total: the entries of the whole
     *         log up to the last one verified (head.seq + 1, 0 when none); brokenAt:
     *         the `seq` where the chain stops, across files; reason: chain,
     *         unsigned-tail, truncated, rewritten, or segment-missing — the anchor
     *         is older than any file on disk, or segment-mismatch — a file does not
     *         end as the next one opening on it says; quarantined: how many lines
     *         quarantine entries have set aside
     */
    public function verify(string $anchor): array
    {
        $anchorAt = self::parseAnchor($anchor);
        return $this->readLocked(fn (): array => self::report($this->segments($anchorAt['seq']), $anchorAt));
    }

    /**
     * Verify the current file alone, from its opening entry, without an
     * anchor — and so without seeing a cut tail, a deleted log or a rewritten
     * archive. Named for that: there is no verify() that forgets it. A current
     * file that holds no entry while archives exist is verified as the last
     * archive: a rotation cut short.
     *
     * @return array{ok: bool, count: int, total: int, brokenAt: ?int, reason: ?string,
     *               head: ?array{seq: int, hmac: string}, quarantined: int}
     */
    public function verifyUnanchored(): array
    {
        return $this->readLocked(fn (): array => self::report($this->segments(PHP_INT_MAX), null));
    }

    /**
     * Verify every file of the log: each archive, each link between a file and
     * the one opening on it, the current file — and that no archive on disk
     * stands outside the chain. Older archives the host removed read
     * segment-missing: the entries left are verified all the same.
     *
     * @return array{ok: bool, count: int, total: int, brokenAt: ?int, reason: ?string,
     *               head: ?array{seq: int, hmac: string}, quarantined: int, segments: int}
     *         as verify(); brokenAt null for an archive the chain does not reach
     */
    public function verifyAll(): array
    {
        return $this->readLocked(function (): array {
            $walk   = $this->segments(null);
            $report = self::report($walk, null) + ['segments' => count($walk['segments'])];
            if ($walk['reason'] === null) {
                $walked = array_column($walk['segments'], 'file');
                foreach ($this->archives() as $archive) {
                    if (!in_array($archive, $walked, true)) {
                        return ['ok' => false, 'brokenAt' => null, 'reason' => 'segment-mismatch'] + $report;
                    }
                }
            }
            return $report;
        });
    }

    /**
     * @return array{seq: int, hmac: string}
     */
    private static function parseAnchor(string $anchor): array
    {
        if (!preg_match(self::ANCHOR_FORM, $anchor, $m)) {
            throw new InvalidArgumentException('Malformed audit anchor (expected "seq:hmac", hmac in hex)');
        }
        return ['seq' => (int) $m[1], 'hmac' => $m[2]];
    }

    /**
     * Null when $entries — consecutive, from the oldest walked — hold the
     * anchor; otherwise how it was lost.
     *
     * @param list<array<string, mixed>>        $entries
     * @param array{seq: int, hmac: string}     $anchorAt
     * @return 'truncated'|'rewritten'|null
     */
    private static function anchorLost(array $entries, array $anchorAt): ?string
    {
        $first = $entries === [] ? null : (int) $entries[0]['seq'];
        $kept  = $first === null || $anchorAt['seq'] < $first ? null : ($entries[$anchorAt['seq'] - $first]['hmac'] ?? null);
        if ($kept === null) {
            return 'truncated';
        }
        return hash_equals($anchorAt['hmac'], (string) $kept) ? null : 'rewritten';
    }

    /**
     * The files of the log, newest first, each walked: the current one, then
     * — following the archive each opening entry names, and checking it ends as
     * that entry says — back to the file that holds $downTo, to the first file
     * of the log, or to an archive no longer on disk. $downTo null walks back
     * as far as the files go. A current file that holds no entry while
     * archives exist goes on with the last archive.
     *
     * @param array<string, mixed>|null $current the current file, already walked
     * @return array{segments: list<array<string, mixed>>, reason: ?string, brokenAt: ?int}
     *         reason: chain, segment-mismatch or segment-missing; null when the walk ended
     */
    private function segments(?int $downTo, ?array $current = null): array
    {
        $seg = $current ?? $this->scan($this->readLines($this->path)) + ['file' => $this->path];
        $out = [];
        $nextOpening = null;
        if (!$seg['broken'] && $seg['chain'] === []) {
            $last = $this->lastArchive(false);
            if ($last === null) {
                return ['segments' => [$seg], 'reason' => null, 'brokenAt' => null];
            }
            $out[] = $seg;
            if (!$last['broken'] && $last['pending'] !== []) {
                return ['segments' => $out, 'reason' => 'segment-mismatch', 'brokenAt' => $last['brokenAt']];
            }
            $seg = $last;
        }
        while (true) {
            if ($seg['broken']) {
                $out[] = $seg;
                return ['segments' => $out, 'reason' => 'chain', 'brokenAt' => $seg['brokenAt']];
            }
            if ($nextOpening !== null && ($seg['pending'] !== [] || !self::ends($seg, $nextOpening))) {
                return ['segments' => $out, 'reason' => 'segment-mismatch', 'brokenAt' => (int) $nextOpening['seq']];
            }
            $out[] = $seg;
            $first = $seg['chain'][0] ?? null;
            if ($first === null || !self::isOpening($first) || ($downTo !== null && (int) $first['seq'] <= $downTo)) {
                return ['segments' => $out, 'reason' => null, 'brokenAt' => null];
            }
            $previous = $this->archiveNamed((string) $first['event']['previous']);
            if ($previous === null) {
                return ['segments' => $out, 'reason' => 'segment-mismatch', 'brokenAt' => (int) $first['seq']];
            }
            clearstatcache(true, $previous);
            if (!is_file($previous)) {
                return ['segments' => $out, 'reason' => 'segment-missing', 'brokenAt' => (int) $first['seq'] - 1];
            }
            $nextOpening = $first;
            $seg = $this->scan($this->readLines($previous)) + ['file' => $previous];
        }
    }

    /**
     * @param array{segments: list<array<string, mixed>>, reason: ?string, brokenAt: ?int} $walk
     * @param array{seq: int, hmac: string}|null $anchorAt
     * @return array{ok: bool, count: int, total: int, brokenAt: ?int, reason: ?string,
     *               head: ?array{seq: int, hmac: string}, quarantined: int}
     */
    private static function report(array $walk, ?array $anchorAt): array
    {
        $entries = self::entriesOf($walk);
        $last    = $entries === [] ? null : end($entries);
        $head    = $last === null ? null : ['seq' => (int) $last['seq'], 'hmac' => (string) $last['hmac']];
        $report  = [
            'count'       => count($entries),
            'total'       => $head === null ? 0 : $head['seq'] + 1,
            'quarantined' => array_sum(array_column($walk['segments'], 'quarantined')),
        ];
        $reason = $walk['reason'];
        if ($reason === 'chain' || $reason === 'segment-mismatch') {
            return $report + ['ok' => false, 'brokenAt' => $walk['brokenAt'], 'reason' => $reason, 'head' => null];
        }
        if ($reason === 'segment-missing') {
            return $report + ['ok' => false, 'brokenAt' => $walk['brokenAt'], 'reason' => $reason, 'head' => $head];
        }
        if ($anchorAt !== null && ($lost = self::anchorLost($entries, $anchorAt)) !== null) {
            return $report + ['ok' => false, 'brokenAt' => $anchorAt['seq'], 'reason' => $lost, 'head' => $head];
        }
        if ($walk['segments'][0]['pending'] !== []) {
            return $report + ['ok' => false, 'brokenAt' => $report['total'], 'reason' => 'unsigned-tail', 'head' => $head];
        }
        return $report + ['ok' => true, 'brokenAt' => null, 'reason' => null, 'head' => $head];
    }

    /**
     * @param array{segments: list<array<string, mixed>>} $walk
     * @return list<array<string, mixed>> the entries walked, oldest first
     */
    private static function entriesOf(array $walk): array
    {
        $entries = [];
        foreach (array_reverse($walk['segments']) as $seg) {
            array_push($entries, ...$seg['chain']);
        }
        return $entries;
    }

    /**
     * Walk the lines of one file once. Its first entry opens the log (`seq` 0,
     * no `prev`) or opens on an archive (isOpening()); every other entry links
     * to the one before it. A line without the shape of an entry waits, and
     * only a quarantine entry covering exactly the lines waiting — their number
     * and their SHA-256 — lets the chain go on past them; in front of the
     * first entry, an opening entry may cover them the same way. An entry
     * whose signature does not hold, or a signed entry anywhere else, breaks
     * it.
     *
     * @param list<string> $lines
     * @return array{chain: list<array<string, mixed>>, pending: list<string>, quarantined: int,
     *               broken: bool, brokenAt: ?int}
     *         brokenAt: the `seq` where the chain stops — of the log entire, not a line
     */
    private function scan(array $lines): array
    {
        $chain = [];
        $pending = [];
        $quarantined = 0;
        $prev = '';
        $next = 0;
        foreach ($lines as $line) {
            $rec = json_decode($line, true);
            $shaped = is_array($rec) && is_string($rec['hmac'] ?? null)
                && array_key_exists('seq', $rec) && array_key_exists('ts', $rec)
                && array_key_exists('event', $rec) && array_key_exists('prev', $rec);
            if (!$shaped) {
                $pending[] = $line;
                continue;
            }
            $signed = hash_equals($this->hmac([
                'seq'   => $rec['seq'],
                'ts'    => $rec['ts'],
                'event' => $rec['event'],
                'prev'  => $rec['prev'],
            ]), $rec['hmac']);
            $kind = is_array($rec['event']) ? ($rec['event'][self::RESERVED_KEY] ?? null) : null;
            $linked = $chain === []
                ? ($kind === 'segment' ? self::isOpening($rec) : $rec['seq'] === 0 && $rec['prev'] === '')
                : $kind !== 'segment' && $rec['prev'] === $prev && $rec['seq'] === $next;
            $covers = match (true) {
                $kind === 'quarantine'                => $pending !== [] && self::coversLines($rec['event'], $pending),
                $kind === 'segment' && $pending !== [] => self::coversLines($rec['event'], $pending),
                $kind === 'segment'                   => !array_key_exists('lines', $rec['event']),
                default                               => $pending === [],
            };
            if (!$signed || !$linked || !$covers) {
                return ['chain' => $chain, 'pending' => $pending, 'quarantined' => $quarantined, 'broken' => true,
                        'brokenAt' => $chain !== [] ? $next : (is_int($rec['seq']) && $rec['seq'] >= 0 ? $rec['seq'] : 0)];
            }
            $quarantined += count($pending);
            $pending = [];
            $chain[] = $rec;
            $prev = (string) $rec['hmac'];
            $next = (int) $rec['seq'] + 1;
        }
        return ['chain' => $chain, 'pending' => $pending, 'quarantined' => $quarantined, 'broken' => false,
                'brokenAt' => $pending === [] ? null : $next];
    }

    /** @param list<string> $pending */
    private static function coversLines(array $event, array $pending): bool
    {
        return ($event['lines'] ?? null) === count($pending)
            && hash_equals(hash('sha256', implode("\n", $pending)), (string) ($event['sha256'] ?? ''));
    }

    /**
     * Whether $rec is an opening entry that holds together: it names an
     * archive, a head, a count of at least one entry, and chains on that head.
     *
     * @param array<string, mixed> $rec
     */
    private static function isOpening(array $rec): bool
    {
        $event = $rec['event'] ?? null;
        return is_array($event)
            && ($event[self::RESERVED_KEY] ?? null) === 'segment'
            && is_string($event['previous'] ?? null)
            && is_int($event['count'] ?? null) && $event['count'] >= 1
            && is_string($event['head'] ?? null) && preg_match(self::ANCHOR_FORM, $event['head'], $m) === 1
            && ($rec['prev'] ?? null) === $m[2]
            && ($rec['seq'] ?? null) === (int) $m[1] + 1;
    }

    /**
     * Whether the walked file $seg ends as $opening, the entry opening on it,
     * says: its name, its head, its count.
     *
     * @param array<string, mixed> $seg
     * @param array<string, mixed> $opening
     */
    private static function ends(array $seg, array $opening): bool
    {
        $last = $seg['chain'] === [] ? null : end($seg['chain']);
        return $last !== null
            && $opening['event']['previous'] === basename((string) $seg['file'])
            && $opening['event']['head'] === $last['seq'] . ':' . $last['hmac']
            && $opening['event']['count'] === count($seg['chain']);
    }

    /**
     * The opening event of a file that follows the walked archive $last, and
     * covers the lines $pending its file holds before it.
     *
     * @param array<string, mixed> $last
     * @param list<string>          $pending
     * @return array<string, mixed>
     */
    private static function opening(array $last, array $pending): array
    {
        $head  = end($last['chain']);
        $event = [
            self::RESERVED_KEY => 'segment',
            'previous'         => basename((string) $last['file']),
            'head'             => $head['seq'] . ':' . $head['hmac'],
            'count'            => count($last['chain']),
        ];
        return $pending === []
            ? $event
            : $event + ['lines' => count($pending), 'sha256' => hash('sha256', implode("\n", $pending))];
    }

    private function lockPath(): string
    {
        return $this->path . '.lock';
    }

    /** @return array{0: string, 1: string, 2: string} directory, name before the number, name after it */
    private function nameParts(): array
    {
        $base = basename($this->path);
        $dot  = strrpos($base, '.');
        return $dot === false || $dot === 0
            ? [dirname($this->path), $base, '']
            : [dirname($this->path), substr($base, 0, $dot), substr($base, $dot)];
    }

    private function archiveName(int $n): string
    {
        [$dir, $stem, $ext] = $this->nameParts();
        return "{$dir}/{$stem}.{$n}{$ext}";
    }

    /** The path of the archive an opening entry names, or null when the name is not one of this log's archives. */
    private function archiveNamed(string $name): ?string
    {
        [$dir, $stem, $ext] = $this->nameParts();
        return preg_match('/\A' . preg_quote($stem, '/') . '\.[1-9][0-9]*' . preg_quote($ext, '/') . '\z/', $name) === 1
            ? "{$dir}/{$name}"
            : null;
    }

    /**
     * The archives on disk, by number.
     *
     * @return array<int, string>
     */
    private function archives(): array
    {
        [$dir, $stem, $ext] = $this->nameParts();
        $names = @scandir($dir);
        if ($names === false) {
            if ($this->rotateAfter === null) {
                return [];
            }
            throw new RuntimeException("Cannot list the archives of audit log {$this->path}: {$dir} is not readable");
        }
        $found = [];
        foreach ($names as $name) {
            if (preg_match('/\A' . preg_quote($stem, '/') . '\.([1-9][0-9]*)' . preg_quote($ext, '/') . '\z/', $name, $m) === 1) {
                $found[(int) $m[1]] = "{$dir}/{$name}";
            }
        }
        ksort($found);
        return $found;
    }

    /**
     * The highest-numbered archive, walked; null when there is none.
     *
     * @return array<string, mixed>|null
     * @throws RuntimeException when $strict and it is broken, or holds no entry
     */
    private function lastArchive(bool $strict = true): ?array
    {
        $archives = $this->archives();
        if ($archives === []) {
            return null;
        }
        $file = end($archives);
        $seg  = $this->scan($this->readLines($file)) + ['file' => $file];
        if ($strict && ($seg['broken'] || $seg['chain'] === [] || $seg['pending'] !== [])) {
            throw new RuntimeException(sprintf(
                'Audit log %s holds no entry, and its last archive %s does not end as a file of the log: nothing is appended — it is an incident to examine',
                $this->path,
                $file
            ));
        }
        return $seg;
    }

    /** The name after the highest archive on disk: a file in the way of the numbering is numbered past, never written over. */
    private function nextArchive(): string
    {
        $archives = $this->archives();
        return $this->archiveName(($archives === [] ? 0 : (int) array_key_last($archives)) + 1);
    }

    private static function permsOf(string $file): int
    {
        clearstatcache(true, $file);
        $perms = @fileperms($file);
        if ($perms === false) {
            throw new RuntimeException("Cannot read the mode of audit log file {$file}: nothing is appended");
        }
        return $perms & 0o777;
    }

    /**
     * $read run under a shared lock on `<path>.lock`, when the log has one: a
     * rotation then never happens between the files a reader opens.
     *
     * @template T
     * @param callable(): T $read
     * @return T
     */
    private function readLocked(callable $read): mixed
    {
        clearstatcache(true, $this->lockPath());
        $lock = is_file($this->lockPath()) ? @fopen($this->lockPath(), 'r') : false;
        if ($lock !== false && !flock($lock, LOCK_SH)) {
            fclose($lock);
            $lock = false;
        }
        try {
            return $read();
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
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
     * What it lists is the chain the files of the log hold, oldest first,
     * quarantine and opening entries included: the lines a quarantine sets aside
     * are not entries. Archives the host removed are not read; the entries left
     * are listed.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException when the log cannot be read, when its absence
     *                          cannot be established, or when it is broken mid-chain
     */
    public function readAll(): array
    {
        return $this->readLocked(function (): array {
            $walk = $this->segments(null);
            if ($walk['reason'] === 'chain' || $walk['reason'] === 'segment-mismatch') {
                throw new RuntimeException(sprintf(
                    'Audit log %s is broken mid-chain at entry %d: its entries cannot be listed as the chain',
                    $this->path,
                    (int) $walk['brokenAt']
                ));
            }
            return self::entriesOf($walk);
        });
    }

    /**
     * The lines of $file — the same refusal as readAll() when it, or the fact
     * of its absence, cannot be read.
     *
     * @return list<string>
     */
    private function readLines(string $file): array
    {
        return $this->readRaw($file)[0];
    }

    /**
     * @return array{0: list<string>, 1: bool} the lines, and whether the last one
     *         has no end of line — what a write cut short by a crash leaves
     */
    private function readRaw(string $file): array
    {
        // PHP caches stat results (and resolved paths) for the life of the
        // process: a log this process wrote, whose directory was closed since,
        // would still look present — before PHP 8.4, is_file() then answers
        // from the cache, and the traversability cause is never named.
        clearstatcache(true, $file);
        if (!is_file($file)) {
            $dir = dirname($file);
            if (!is_dir($dir) || !is_readable($dir) || !is_executable($dir)) {
                throw new RuntimeException(sprintf(
                    'Cannot establish whether the audit log exists: %s is not traversable. '
                    . 'Refusing to answer "no events" — unreadable is not empty.',
                    $dir
                ));
            }

            return [[], false];
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException(sprintf(
                'Audit log present but unreadable: %s. '
                . 'Refusing to answer "no events" — unreadable is not empty.',
                $file
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
        return ['path' => $this->path, 'auditSecret' => '[redacted]', 'rotateAfter' => $this->rotateAfter, 'mode' => $this->mode];
    }
}
