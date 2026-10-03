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
 *   - wrap_admin  : reserved, always null — the escrow compartment covers the
 *                   administrator's access instead
 *   - created_at / updated_at: bookkeeping
 *   - wrap_phrase : data_master_key wrapped by phrase_key (null if no
 *                   passphrase was set)
 *   - revision    : bumped by every stored update; a write applies only over
 *                   the revision it was read at
 *   - kdf_opslimit / kdf_memlimit : the Argon2id profile of every envelope,
 *                   fixed at creation (a vault stored before 0.6.0 carries the
 *                   legacy profile, Primitives::LEGACY_*)
 *
 * user_salt is also the vault's identity: it is never rotated, and a
 * re-created vault gets a new one. Sessions and conditional writes compare it.
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
        public readonly DateTimeImmutable $updatedAt,
        public readonly ?EncryptedBlob $wrapPhrase = null,
        public readonly int $revision = 0,
        // Defaults to the current constants: a storage that does not read these
        // columns back opens its vaults under them, not under their own profile.
        public readonly int $kdfOpslimit = Primitives::ARGON2_OPSLIMIT,
        public readonly int $kdfMemlimit = Primitives::ARGON2_MEMLIMIT
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
            updatedAt: $now,
            wrapPhrase: $this->wrapPhrase,
            revision:  $this->revision,
            kdfOpslimit: $this->kdfOpslimit,
            kdfMemlimit: $this->kdfMemlimit
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
            updatedAt: $now,
            wrapPhrase: $this->wrapPhrase,
            revision:  $this->revision,
            kdfOpslimit: $this->kdfOpslimit,
            kdfMemlimit: $this->kdfMemlimit
        );
    }

    public function withWrapPhrase(?EncryptedBlob $newWrapPhrase, DateTimeImmutable $now): self
    {
        return new self(
            userId:    $this->userId,
            userSalt:  $this->userSalt,
            wrapPwd:   $this->wrapPwd,
            wrapRecov: $this->wrapRecov,
            wrapAdmin: $this->wrapAdmin,
            createdAt: $this->createdAt,
            updatedAt: $now,
            wrapPhrase: $newWrapPhrase,
            revision:  $this->revision,
            kdfOpslimit: $this->kdfOpslimit,
            kdfMemlimit: $this->kdfMemlimit
        );
    }

    public function hasMemorizedRecovery(): bool
    {
        return $this->wrapRecov !== null;
    }

    public function hasPassphrase(): bool
    {
        return $this->wrapPhrase !== null;
    }
}
