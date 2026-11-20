<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Storage;

use RuntimeException;

/**
 * The escrow row is there but does not parse: a column rewritten — a blob
 * that is not one, a date, a revision the next write cannot compare or bump.
 * Raised by StorageInterface::loadEscrow(), through EscrowRecord::parse();
 * any other exception it raises is an outage of the storage, and the library
 * lets it through as it is. Raised too by the ceremony's readers of an archive's
 * escrow when the archive keeps one that does not parse
 * (ArchivedVault::$escrowUnreadable); the holder's readers take it as a row
 * rewritten.
 */
final class UnreadableEscrowException extends RuntimeException
{
}
