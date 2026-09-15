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
 * edit breaks verification — even by root.
 *
 * On-disk format: one JSON object per line, keys in fixed order
 *   {"seq":N,"ts":"ISO8601","event":{...},"prev":"<hmac of entry N-1>","hmac":"<hmac>"}
 * where hmac = HMAC-SHA256( json({seq,ts,event,prev}), auditSecret ).
 *
 * Deployment hardening (ops, not code): set the file `chattr +a` (append-only,
 * inaltérable même en root). The HMAC chain detects tampering regardless.
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
        private readonly string $auditSecret
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
     * Append a signed, chained entry. Returns the written record.
     *
     * @param array<string, mixed> $event application-defined payload
     * @return array<string, mixed>
     */
    public function append(array $event): array
    {
        $entries = $this->readAll();
        $prev    = $entries === [] ? '' : (string) end($entries)['hmac'];

        $signable = [
            'seq'   => count($entries),
            'ts'    => (new DateTimeImmutable())->format('c'),
            'event' => $event,
            'prev'  => $prev,
        ];
        $record = $signable + ['hmac' => $this->hmac($signable)];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            throw new RuntimeException('Failed to encode audit record');
        }
        if (file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Cannot append to audit log at {$this->path}");
        }
        return $record;
    }

    /**
     * Verify the whole chain: each entry's HMAC and its linkage to the previous.
     *
     * @return array{ok: bool, count: int, brokenAt: ?int}
     */
    public function verify(): array
    {
        $entries = $this->readAll();
        $prev = '';
        foreach ($entries as $i => $rec) {
            $signable = [
                'seq'   => $rec['seq'] ?? null,
                'ts'    => $rec['ts'] ?? null,
                'event' => $rec['event'] ?? null,
                'prev'  => $rec['prev'] ?? null,
            ];
            $expected = $this->hmac($signable);
            $hmacOk   = isset($rec['hmac']) && hash_equals($expected, (string) $rec['hmac']);
            $linkOk   = ($rec['prev'] ?? null) === $prev && ($rec['seq'] ?? null) === $i;
            if (!$hmacOk || !$linkOk) {
                return ['ok' => false, 'count' => count($entries), 'brokenAt' => $i];
            }
            $prev = (string) $rec['hmac'];
        }
        return ['ok' => true, 'count' => count($entries), 'brokenAt' => null];
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
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException when the log cannot be read, or when its absence
     *                          cannot be established
     */
    public function readAll(): array
    {
        if (!is_file($this->path)) {
            $dir = dirname($this->path);
            if (!is_dir($dir) || !is_readable($dir) || !is_executable($dir)) {
                throw new RuntimeException(sprintf(
                    'Cannot establish whether the audit log exists: %s is not traversable. '
                    . 'Refusing to answer "no events" — unreadable is not empty.',
                    $dir
                ));
            }

            return [];
        }
        $lines = @file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException(sprintf(
                'Audit log present but unreadable: %s. '
                . 'Refusing to answer "no events" — unreadable is not empty.',
                $this->path
            ));
        }
        $out = [];
        foreach ($lines as $line) {
            $rec = json_decode($line, true);
            if (!is_array($rec)) {
                throw new RuntimeException('Corrupted audit log: non-JSON line');
            }
            $out[] = $rec;
        }

        return $out;
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
}
