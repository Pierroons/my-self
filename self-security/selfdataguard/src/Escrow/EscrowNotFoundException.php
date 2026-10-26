<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use RuntimeException;

/**
 * The userId has no escrow compartment to replace: a first one is created,
 * never written over a missing one.
 */
final class EscrowNotFoundException extends RuntimeException
{
}
