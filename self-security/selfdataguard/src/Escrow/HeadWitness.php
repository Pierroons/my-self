<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

/**
 * Takes the head of the audit log out of this machine — called after the
 * ceremony has written its intention, before anything is unsealed. It throws
 * when the head did not get there, and the ceremony stops: whoever could cut
 * the network, read, then rewrite the log, finds the intention already outside.
 */
interface HeadWitness
{
    public function witness(int $seq, string $hmac): void;
}
