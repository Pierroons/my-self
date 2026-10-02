<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use RuntimeException;

/**
 * No live vault for this userId.
 */
final class VaultNotFoundException extends RuntimeException
{
}
