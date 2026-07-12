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
    public function __construct(
        private readonly string $path,
        private readonly string $auditSecret
    ) {
        if (strlen($auditSecret) < 16) {
            throw new InvalidArgumentException('auditSecret must be ≥16 bytes');
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
     * @return array<int, array<string, mixed>>
     */
    public function readAll(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $out = [];
        foreach (file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
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
