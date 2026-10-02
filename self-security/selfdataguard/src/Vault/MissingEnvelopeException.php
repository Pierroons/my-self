<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use RuntimeException;

/**
 * This vault was never sealed for the lock asked — no secret can open it
 * that way. Distinct from a wrong secret: asking the user to retype it is
 * pointless.
 */
final class MissingEnvelopeException extends RuntimeException
{
}
