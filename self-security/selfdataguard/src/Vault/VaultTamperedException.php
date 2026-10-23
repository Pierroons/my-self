<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use RuntimeException;

/**
 * The stored vault is not what SelfDataGuard wrote: its Argon2id profile is out
 * of bounds, does not match its seal, carries no seal, or changed under an open
 * session. Raised before any derivation.
 *
 * Distinct from WrongSecretException on purpose. A profile changed in the
 * database makes every lock fail as a wrong secret would, and the holder,
 * certain of a password they did type right, goes down to level 3 — where the
 * archive keeps the same false profile. This says what happened instead.
 */
final class VaultTamperedException extends RuntimeException
{
}
