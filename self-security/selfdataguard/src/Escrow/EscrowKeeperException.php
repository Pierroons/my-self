<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use RuntimeException;
use Throwable;

/**
 * An act could not go through the keeper, and nothing was unsealed. The
 * reason says why: keeper-missing (none given), keeper-mismatch (another
 * keeper than the database recorded, or a record of former keepers that is
 * unreadable, lacks one, or lists the façade's own keeper), keeper-changed (a
 * keeper to ask was not given), keeper-unreachable (its public keys or its
 * attestation do not come), keeper-failed (it refused or did not answer), or
 * the reason of a KeeperAttestationException. Where each comes from: the
 * docblocks of the methods that raise it.
 */
final class EscrowKeeperException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
