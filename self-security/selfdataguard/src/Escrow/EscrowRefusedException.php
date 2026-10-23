<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use RuntimeException;

/**
 * The arbiter did not answer Open: nothing was unsealed. The intention and the
 * refusal are both in the log.
 */
final class EscrowRefusedException extends RuntimeException
{
    public function __construct(public readonly Verdict $verdict, string $userId, string $caseRef)
    {
        parent::__construct("Case '{$caseRef}' does not authorize reading the escrow of '{$userId}': {$verdict->value}");
    }
}
