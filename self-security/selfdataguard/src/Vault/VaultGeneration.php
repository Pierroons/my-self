<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\Primitives;

/**
 * Which vault of a userId a write is meant for: the userId and the user_salt
 * the caller read. A re-enrolment gives the userId a vault with another salt,
 * so a write that names the old generation is refused instead of landing in
 * the new vault, sealed under a key that can no longer read it.
 *
 * Every write of StorageInterface takes one and compares it inside the write's
 * own transaction, or in the same statement. Compared beforehand only, a
 * re-enrolment committed in between slips through.
 */
final class VaultGeneration
{
    public function __construct(
        public readonly string $userId,
        public readonly string $userSalt
    ) {
        if ($userId === '') {
            throw new InvalidArgumentException('userId must not be empty');
        }
        if (strlen($userSalt) !== Primitives::SALT_LEN) {
            throw new InvalidArgumentException(
                'userSalt must be exactly ' . Primitives::SALT_LEN . ' bytes'
            );
        }
    }
}
