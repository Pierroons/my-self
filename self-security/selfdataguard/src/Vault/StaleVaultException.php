<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use RuntimeException;

/**
 * The record or session belongs to a vault that has since been replaced for
 * this userId (archived and re-created).
 *
 * Writing through it would seal the old master key into the new vault, or
 * store fields the new key cannot read. The caller must load the current
 * vault and unlock it again.
 */
final class StaleVaultException extends RuntimeException
{
}
