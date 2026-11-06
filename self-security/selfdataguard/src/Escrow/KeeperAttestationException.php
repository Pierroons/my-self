<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use RuntimeException;

/** An attestation that does not hold, with the reason, one of: unreadable, signature, escrow, nonce. */
final class KeeperAttestationException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
