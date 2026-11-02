<?php

declare(strict_types=1);

/**
 * Keepers that each break one clause of the EscrowKeeper contract: what the
 * keeper conformance suite must fail, on the case that names the clause.
 */

require_once __DIR__ . '/keeper_fixture.php';

/** Gives public keys that are not keys. */
final class BadKeysKeeper extends BenchKeeper
{
    public function publicKeys(): array
    {
        return ['sign' => 'not a key', 'box' => 'not a key'];
    }
}

/** Takes any box for any read: a box moved from one record to another is released as the other's. */
final class OpensAnyBoxKeeper extends BenchKeeper
{
    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, int $seq, string $hmac): int
    {
        $this->pending[bin2hex($escrowId) . '|' . $caseRef] ??= ['case' => $caseRef, 'due' => $this->time(), 'objected' => false];
        return $this->time();
    }
}

/** Moves the deadline of a read announced again with the same box. */
final class MovingDeadlineKeeper extends BenchKeeper
{
    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, int $seq, string $hmac): int
    {
        $due = parent::announce($escrowId, $outerBox, $userId, $caseRef, $seq, $hmac);
        $this->pending[bin2hex($escrowId) . '|' . $caseRef]['due'] = $due + 1;
        return $due + 1;
    }
}

/** Starts the delay again when another box of the escrow is announced: whoever holds one more box puts the read off for ever. */
final class RestartingKeeper extends BenchKeeper
{
    /** @var array<string, string> the hash of the box each case was announced with */
    private array $boxes = [];

    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, int $seq, string $hmac): int
    {
        $key = bin2hex($escrowId) . '|' . $caseRef;
        if (isset($this->pending[$key], $this->boxes[$key]) && !hash_equals($this->boxes[$key], hash('sha256', $outerBox))) {
            unset($this->pending[$key]);
        }
        $this->boxes[$key] = hash('sha256', $outerBox);
        return parent::announce($escrowId, $outerBox, $userId, $caseRef, $seq, $hmac);
    }
}

/** Takes a box whose objection key is not the one its escrowId names: whoever forges a box objects in the holder's place. */
final class ForeignKeyKeeper extends BenchKeeper
{
    protected function fits(array $box, string $escrowId): bool
    {
        return hash_equals($box['escrowId'], $escrowId);
    }
}

/** Takes a box without objection key for an escrow sealed with one. */
final class KeylessAfterKeyedKeeper extends BenchKeeper
{
    protected function refusesKeyless(string $escrowIdHex): bool
    {
        return false;
    }
}

/** Hands back another inner box than the one the outer box holds. */
final class WrongInnerKeeper extends BenchKeeper
{
    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string
    {
        return strrev(parent::release($escrowId, $outerBox, $caseRef, $seq, $hmac));
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
        $this->pending[bin2hex($escrowId) . '|' . $caseRef] ??= ['case' => $caseRef, 'due' => $this->time(), 'objected' => false];
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

/** Lets an announcement lapse as soon as it falls due: a ceremony run again a minute late never reads. */
final class LapsesAtOnceKeeper extends BenchKeeper
{
    protected function lapsed(array $held): bool
    {
        return $this->time() > $held['due'];
    }
}

/** Ends the announcement at its first release: whoever releases a box of their own first leaves the operator nothing. */
final class ConsumingReleaseKeeper extends BenchKeeper
{
    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string
    {
        $inner = parent::release($escrowId, $outerBox, $caseRef, $seq, $hmac);
        unset($this->pending[bin2hex($escrowId) . '|' . $caseRef]);
        return $inner;
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

/** Takes any objection: the administrator objects in the holder's place. */
final class ObjectionBlindKeeper extends BenchKeeper
{
    public function object(string $escrowId, string $caseRef, string $token): void
    {
        $key = bin2hex($escrowId) . '|' . $caseRef;
        if (isset($this->pending[$key])) {
            $this->pending[$key]['objected'] = true;
        }
    }
}

/** Takes an objection to a case nothing announced: the holder is told "objected" for a read that goes on elsewhere. */
final class ObjectionWithoutAnnouncementKeeper extends BenchKeeper
{
    public function object(string $escrowId, string $caseRef, string $token): void
    {
        $this->pending[bin2hex($escrowId) . '|' . $caseRef] ??= ['case' => $caseRef, 'due' => $this->time(), 'objected' => false];
        parent::object($escrowId, $caseRef, $token);
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

/** Takes a retirement and keeps serving the escrow: the keeper the deployment replaced stays a door. */
final class ForgetsRetirementKeeper extends BenchKeeper
{
    public function retire(string $escrowId, string $token): void
    {
        parent::retire($escrowId, $token);
        unset($this->retired[bin2hex($escrowId)]);
    }
}
