<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use RuntimeException;

/**
 * The escrow row does not open the escrow fields the database holds: the row
 * was deleted while its fields stayed, put back from a backup without them, or
 * rewritten. Nothing was written, and no former keeper was asked to retire the
 * escrow. The fields stay under the key of the row that wrote them: putting
 * that row back opens them again.
 */
final class EscrowEnvelopeMismatchException extends RuntimeException
{
}
