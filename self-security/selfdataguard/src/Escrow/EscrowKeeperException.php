<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use RuntimeException;
use Throwable;

/**
 * The compartment is sealed to a keeper, and the ceremony could not go
 * through it: none was given (reason keeper-missing), or it refused or did not
 * answer (keeper-failed). Nothing was unsealed.
 */
final class EscrowKeeperException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
