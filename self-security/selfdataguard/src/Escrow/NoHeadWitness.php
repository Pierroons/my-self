<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

/**
 * No witness, chosen by name: the head of the log stays on this machine, and
 * whoever holds its secret can rewrite what follows the last anchor taken by
 * hand, while whoever can write the file can cut it before a ceremony that will
 * not see it. The ceremony writes this class to the log like any other witness.
 */
final class NoHeadWitness implements HeadWitness
{
    public function witness(int $seq, string $hmac): void
    {
    }

    public function lastHead(): ?string
    {
        return null;
    }
}
