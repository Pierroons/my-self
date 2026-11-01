<?php

declare(strict_types=1);

/**
 * Keepers that each break one clause of the EscrowKeeper contract: what the
 * keeper conformance suite must fail, on the case that names the clause.
 */

require_once __DIR__ . '/keeper_fixture.php';

use Pierroons\SelfDataGuard\Escrow\KeeperSeal;

/** Takes any box for any read: a box moved from one record to another is released as the other's. */
final class OpensAnyBoxKeeper extends BenchKeeper
{
    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, bool $archive, int $seq, string $hmac): int
    {
        $this->pending[bin2hex($escrowId) . '|' . $caseRef] ??= ['case' => $caseRef, 'due' => time(), 'objection' => null, 'objected' => false];
        return time();
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
        $this->pending[bin2hex($escrowId) . '|' . $caseRef] ??= ['case' => $caseRef, 'due' => 0, 'objection' => null, 'objected' => false];
        return parent::release($escrowId, $outerBox, $caseRef, $seq, $hmac);
    }
}

/** Takes any log head: a log cut back and replayed is released again. */
final class SeqBlindKeeper extends BenchKeeper
{
    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string
    {
        $this->lastSeq = -1;
        return parent::release($escrowId, $outerBox, $caseRef, $seq, $hmac);
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

/** Delays archive reads like the others. */
final class DelayedArchiveKeeper extends BenchKeeper
{
    public function __construct()
    {
        parent::__construct(3600);
    }

    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, bool $archive, int $seq, string $hmac): int
    {
        return parent::announce($escrowId, $outerBox, $userId, $caseRef, false, $seq, $hmac);
    }
}
