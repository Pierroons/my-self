<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use RuntimeException;

/**
 * The keeper recorded the read and holds it until $due (Unix time): nothing
 * was unsealed. The holder may object meanwhile; the same ceremony, run again
 * after $due, reads.
 */
final class EscrowAnnouncedException extends RuntimeException
{
    public function __construct(public readonly int $due, string $userId, string $caseRef)
    {
        parent::__construct(sprintf(
            "The keeper holds the read of '%s' for case '%s' until %s: nothing is unsealed before then",
            $userId,
            $caseRef,
            gmdate('Y-m-d H:i:s', $due) . ' UTC'
        ));
    }
}
