<?php

declare(strict_types=1);

/**
 * Keepers that each break one clause of the EscrowKeeper contract: what the
 * keeper conformance suite must fail, on the case that names the clause.
 */

require_once __DIR__ . '/keeper_fixture.php';

/** Takes any box for any read: a box moved from one record to another is released as the other's. */
final class OpensAnyBoxKeeper extends BenchKeeper
{
    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, int $seq, string $hmac): int
    {
        $this->pending[bin2hex($escrowId) . '|' . $caseRef] ??= [
            'case' => $caseRef, 'due' => $this->time(), 'objection' => null, 'objected' => false, 'box' => hash('sha256', $outerBox),
        ];
        return $this->time();
    }
}

/** Hands the box back and records nothing: the holder never sees the read. */
final class UnrecordedReleaseKeeper extends BenchKeeper
{
    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string
    {
        $inner = parent::release($escrowId, $outerBox, $caseRef, $seq, $hmac);
        array_pop($this->releases[bin2hex($escrowId)]);
        return $inner;
    }
}

/** Releases what was never announced. */
final class UnannouncedReleaseKeeper extends BenchKeeper
{
    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string
    {
        $this->pending[bin2hex($escrowId) . '|' . $caseRef] ??= [
            'case' => $caseRef, 'due' => $this->time(), 'objection' => null, 'objected' => false, 'box' => hash('sha256', $outerBox),
        ];
        return parent::release($escrowId, $outerBox, $caseRef, $seq, $hmac);
    }
}

/** Refuses a log head that is not above the last one it took: a new audit log, or one head forced high, closes it. */
final class HeadJudgeKeeper extends BenchKeeper
{
    private int $lastSeq = -1;

    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string
    {
        if ($seq <= $this->lastSeq) {
            throw new RuntimeException("The log head {$seq} is not above the last one this keeper took ({$this->lastSeq})");
        }
        $this->lastSeq = $seq;
        return parent::release($escrowId, $outerBox, $caseRef, $seq, $hmac);
    }
}

/** Hands the box back before the delay ends. */
final class EarlyReleaseKeeper extends BenchKeeper
{
    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string
    {
        $key = bin2hex($escrowId) . '|' . $caseRef;
        if (isset($this->pending[$key])) {
            $this->pending[$key]['due'] = min($this->pending[$key]['due'], $this->time());
        }
        return parent::release($escrowId, $outerBox, $caseRef, $seq, $hmac);
    }
}

/** Releases whatever box it is handed, once a read of that escrow and case is due: a deposit since is read at once. */
final class AnyBoxReleaseKeeper extends BenchKeeper
{
    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string
    {
        $key = bin2hex($escrowId) . '|' . $caseRef;
        if (isset($this->pending[$key])) {
            $this->pending[$key]['box'] = hash('sha256', $outerBox);
        }
        return parent::release($escrowId, $outerBox, $caseRef, $seq, $hmac);
    }
}

/** Keeps the first announcement of a case, whatever box comes after: its deadline, and an objection key it may not carry. */
final class FirstBoxKeeper extends BenchKeeper
{
    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, int $seq, string $hmac): int
    {
        $held = $this->pending[bin2hex($escrowId) . '|' . $caseRef] ?? null;
        return $held !== null ? $held['due'] : parent::announce($escrowId, $outerBox, $userId, $caseRef, $seq, $hmac);
    }
}

/** Never lets an announcement lapse: one made months ago is released today. */
final class NeverLapsesKeeper extends BenchKeeper
{
    protected function lapsed(array $held): bool
    {
        return false;
    }
}

/** Signs once per escrow, and hands that attestation back for every later request. */
final class StaleAttestationKeeper extends BenchKeeper
{
    /** @var array<string, string> */
    private array $signed = [];

    public function attest(string $escrowId, string $nonce): string
    {
        return $this->signed[$escrowId] ??= parent::attest($escrowId, $nonce);
    }
}

/** Takes any objection: the administrator objects, or withdraws, in the holder's place. */
final class ObjectionBlindKeeper extends BenchKeeper
{
    public function object(string $escrowId, string $caseRef, string $signature): void
    {
        $key = bin2hex($escrowId) . '|' . $caseRef;
        if (isset($this->pending[$key])) {
            $this->pending[$key]['objected'] = true;
        }
    }
}

/** Clears an objection when the read is announced again. */
final class ForgetsObjectionKeeper extends BenchKeeper
{
    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, int $seq, string $hmac): int
    {
        $key = bin2hex($escrowId) . '|' . $caseRef;
        if (isset($this->pending[$key])) {
            $this->pending[$key]['objected'] = false;
        }
        return parent::announce($escrowId, $outerBox, $userId, $caseRef, $seq, $hmac);
    }
}
