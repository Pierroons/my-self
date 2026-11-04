<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Storage;

use RuntimeException;

/**
 * The escrow row is there but does not parse: a column rewritten — a blob
 * that is not one, a date, a revision that is not an integer. Raised by
 * StorageInterface::loadEscrow(); any other exception it raises is an outage
 * of the storage, and the library lets it through as it is.
 */
final class UnreadableEscrowException extends RuntimeException
{
}
