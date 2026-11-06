<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use RuntimeException;

/**
 * The escrow envelope was replaced since this record was read.
 *
 * Writing through it would put back an envelope another writer replaced — a
 * re-sealing undone, or fields left under a key the envelope no longer wraps.
 * The caller loads the escrow again and redoes its work from it.
 */
final class StaleEscrowException extends RuntimeException
{
}
