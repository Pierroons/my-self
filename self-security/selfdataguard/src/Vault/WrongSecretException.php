<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use RuntimeException;

/**
 * The secret given does not open the vault by this lock — or the vault has no
 * such lock: MissingEnvelopeException, a subclass with the same message.
 */
class WrongSecretException extends RuntimeException
{
}
