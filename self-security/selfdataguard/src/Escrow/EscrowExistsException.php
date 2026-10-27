<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use RuntimeException;

/**
 * The userId already has an escrow compartment. Creating one never replaces
 * another: the fields already in it are encrypted under its escrow key, and a
 * second key would leave them unreadable.
 */
final class EscrowExistsException extends RuntimeException
{
}
