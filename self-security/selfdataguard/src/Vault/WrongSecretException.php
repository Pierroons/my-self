<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use RuntimeException;

/**
 * The envelope exists, and the secret given does not open it.
 */
final class WrongSecretException extends RuntimeException
{
}
