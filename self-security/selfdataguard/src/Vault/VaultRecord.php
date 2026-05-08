<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use DateTimeImmutable;
use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\Primitives;

/**
 * Persistent record of a user's vault state.
 *
 * Layout matches what gets stored in the database:
 *   - user_id     : opaque application-level identifier (string)
 *   - user_salt   : 16-byte per-user salt, in clear (it is NOT a secret)
 *   - wrap_pwd    : data_master_key wrapped by password_key
 *   - wrap_recov  : data_master_key wrapped by recov_key (null if memorized
 *                   secret not configured — degrades to single-factor recovery)
 *   - wrap_admin  : data_master_key wrapped by admin_op_key (Hybrid mode only,
 *                   reserved for v0.2.0+; null for now)
 *   - created_at / updated_at: bookkeeping
 *
 * Immutable. Update operations return a new VaultRecord.
 */
final class VaultRecord
{
    public function __construct(
        public readonly string $userId,
        public readonly string $userSalt,
        public readonly EncryptedBlob $wrapPwd,
        public readonly ?EncryptedBlob $wrapRecov,
        public readonly ?EncryptedBlob $wrapAdmin,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt
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

    public function withWrapPwd(EncryptedBlob $newWrapPwd, DateTimeImmutable $now): self
    {
        return new self(
            userId:    $this->userId,
            userSalt:  $this->userSalt,
            wrapPwd:   $newWrapPwd,
            wrapRecov: $this->wrapRecov,
            wrapAdmin: $this->wrapAdmin,
            createdAt: $this->createdAt,
            updatedAt: $now
        );
    }

    public function withWrapRecov(?EncryptedBlob $newWrapRecov, DateTimeImmutable $now): self
    {
        return new self(
            userId:    $this->userId,
            userSalt:  $this->userSalt,
            wrapPwd:   $this->wrapPwd,
            wrapRecov: $newWrapRecov,
            wrapAdmin: $this->wrapAdmin,
            createdAt: $this->createdAt,
            updatedAt: $now
        );
    }

    public function hasMemorizedRecovery(): bool
    {
        return $this->wrapRecov !== null;
    }
}
