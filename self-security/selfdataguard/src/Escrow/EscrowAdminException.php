<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use RuntimeException;
use Throwable;

/**
 * The administrator key given is not the one this act takes. $reason says
 * which, and is what the ceremony's log writes:
 *
 *   - admin-unrecorded: the database recorded no administrator key — the
 *     operator records it by SelfDataGuard::adoptEscrowAdmin(), proving it
 *     holds it; a deposit records nothing;
 *   - admin-mismatch: the database recorded another key, or the escrow's label
 *     names another key than the one given — the ceremony compares it before
 *     the keeper is asked;
 *   - admin-not-the-pair: the sealed secret key does not belong to the public
 *     key given.
 *
 * Not an InvalidArgumentException: the ceremony and its tool read that one as
 * a sealed key file that does not parse.
 */
final class EscrowAdminException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
