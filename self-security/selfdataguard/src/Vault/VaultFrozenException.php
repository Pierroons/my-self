<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use RuntimeException;

/**
 * The live vault is frozen: it reads, and refuses every write until whoever
 * froze it unfreezes it — SelfRecover's window after a level 3, until the
 * arbiter closes the window the returning holder took back.
 *
 * Erasing the account stays possible: a freeze holds the data still, it does
 * not stand in the way of eraseAccount().
 */
final class VaultFrozenException extends RuntimeException
{
}
