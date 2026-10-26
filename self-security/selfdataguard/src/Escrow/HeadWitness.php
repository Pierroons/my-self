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

    /**
     * The last head this witness took, as "seq:hmac", or null when it holds
     * none. The ceremony verifies the log against it before writing anything: a
     * log cut or rewritten since the last ceremony stops the next one, instead
     * of being chained on and handed back to the witness as a lower head. It
     * throws when the head cannot be read back, and the ceremony stops.
     */
    public function lastHead(): ?string;
}
