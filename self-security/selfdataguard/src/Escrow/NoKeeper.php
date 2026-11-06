<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use RuntimeException;

/**
 * Says by name that a deployment has no keeper: escrows stay readable by the
 * administrator alone, as before 0.9.0. A compartment already sealed to a
 * keeper does not open through it, and the ceremony logs why.
 */
final class NoKeeper implements EscrowKeeper
{
    public function publicKeys(): array
    {
        throw new RuntimeException('NoKeeper holds no key: there is no keeper to seal to');
    }

    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, int $seq, string $hmac): int
    {
        throw new RuntimeException('NoKeeper opens no box');
    }

    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string
    {
        throw new RuntimeException('NoKeeper opens no box');
    }

    public function attest(string $escrowId, string $nonce): string
    {
        throw new RuntimeException('NoKeeper attests nothing');
    }

    public function object(string $escrowId, string $caseRef, string $token): void
    {
        throw new RuntimeException('NoKeeper takes no objection');
    }

    public function retire(string $escrowId, string $token): void
    {
        throw new RuntimeException('NoKeeper holds no escrow to retire');
    }
}
