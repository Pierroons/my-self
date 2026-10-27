<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use DateTimeImmutable;
use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\LegacyCipherUnavailableException;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\Escrow\EscrowVault;
use Pierroons\SelfDataGuard\Escrow\UnlockedEscrow;
use Pierroons\SelfDataGuard\Fields\FieldCrypter;
use RuntimeException;

/**
 * Holds the unwrapped data_master_key in memory for the duration of an
 * authenticated session.
 *
 * After lock() (or destruction), the key is best-effort wiped via
 * sodium_memzero. PHP cannot guarantee true memory wiping, but combined with
 * short session timeouts and sensitive deployment hygiene (no swap, etc.),
 * this is acceptable for the threat model.
 *
 * It also carries the user_salt of the vault it was opened on. A vault
 * re-created for the same userId has another salt, so a session from the old
 * one cannot write into the new one (StaleVaultException).
 *
 * And the Argon2id profile it was opened under — proven by the opening, since
 * the key depends on it. A re-seal uses this one, not the profile read from
 * the database at that moment, which whoever writes the database can lower.
 *
 * It never hands the key out: what needs it is done here — a field encrypted
 * or decrypted under the AAD of its name, the escrow created or opened, a lock
 * re-sealed from its secret. A method that took an AAD or a key from the caller
 * would hand it out in two calls: wrap it under a key the caller holds, then
 * unwrap it there.
 *
 * NEVER serialize, log, or persist this object.
 */
final class UnlockedVault
{
    private string $masterKey;
    private bool $locked = false;

    public function __construct(
        public readonly string $userId,
        #[\SensitiveParameter] string $masterKey,
        public readonly string $vaultSalt,
        public readonly int $kdfOpslimit,
        public readonly int $kdfMemlimit
    ) {
        if (strlen($masterKey) !== Primitives::KEY_LEN) {
            throw new RuntimeException(
                'masterKey must be exactly ' . Primitives::KEY_LEN . ' bytes'
            );
        }
        if (strlen($vaultSalt) !== Primitives::SALT_LEN) {
            throw new RuntimeException(
                'vaultSalt must be exactly ' . Primitives::SALT_LEN . ' bytes'
            );
        }
        $this->masterKey = $masterKey;
    }

    /** A private field, bound to this account and to its name. */
    public function encryptField(string $fieldName, #[\SensitiveParameter] string $plaintext): EncryptedBlob
    {
        return Primitives::encrypt($plaintext, $this->key(), FieldCrypter::aadFor($this->userId, $fieldName));
    }

    /**
     * @throws RuntimeException if the tag fails: another key, another account,
     *                          another field name, or a tampered ciphertext
     */
    public function decryptField(string $fieldName, EncryptedBlob $blob): string
    {
        return Primitives::decryptAcrossFormats(
            $blob,
            $this->key(),
            FieldCrypter::aadFor($this->userId, $fieldName),
            FieldCrypter::legacyAadFor($this->userId, $fieldName)
        );
    }

    /**
     * The envelope of $lock, sealed anew from its secret under this vault's salt
     * and the profile this session was opened under.
     */
    public function sealLock(Lock $lock, #[\SensitiveParameter] string $secret): EncryptedBlob
    {
        $kek = UserVault::keyFor($lock, $secret, $this->vaultSalt, $this->kdfOpslimit, $this->kdfMemlimit);
        try {
            return Primitives::encrypt($this->key(), $kek, UserVault::wrapAadFor($this->userId));
        } finally {
            Primitives::zeroize($kek);
        }
    }

    /**
     * A new escrow compartment: its key drawn here, wrapped under the master key
     * for the user, sealed to the administrator's public key.
     *
     * @return array{record: EscrowRecord, unlocked: UnlockedEscrow}
     */
    public function createEscrow(string $adminPublicKeyB64, DateTimeImmutable $now): array
    {
        $escrowKey = Primitives::randomBytes(Primitives::KEY_LEN);
        try {
            $wrapUser = Primitives::encrypt($escrowKey, $this->key(), EscrowVault::wrapAad($this->userId));
            $unlocked = new UnlockedEscrow(userId: $this->userId, escrowKey: $escrowKey);
        } finally {
            Primitives::zeroize($escrowKey);
        }
        $record = new EscrowRecord(
            userId:    $this->userId,
            wrapUser:  $wrapUser,
            wrapAdmin: $unlocked->sealToAdmin($adminPublicKeyB64),
            createdAt: $now,
            updatedAt: $now
        );
        return ['record' => $record, 'unlocked' => $unlocked];
    }

    /**
     * Open this account's escrow with the master key — the user's daily access.
     *
     * @throws RuntimeException if the record is another account's, or the wrap fails
     */
    public function openEscrow(EscrowRecord $record): UnlockedEscrow
    {
        if ($record->userId !== $this->userId) {
            throw new InvalidArgumentException('Session userId does not match escrow record');
        }
        try {
            $escrowKey = Primitives::decryptAcrossFormats(
                $record->wrapUser,
                $this->key(),
                EscrowVault::wrapAad($record->userId),
                $record->userId . EscrowVault::WRAP_AAD_SUFFIX
            );
        } catch (LegacyCipherUnavailableException $e) {
            // A RuntimeException too: without this, the next catch hides a machine limit.
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Could not unwrap escrow with user master key', previous: $e);
        }
        try {
            return new UnlockedEscrow(userId: $record->userId, escrowKey: $escrowKey);
        } finally {
            Primitives::zeroize($escrowKey);
        }
    }

    private function key(): string
    {
        if ($this->locked) {
            throw new RuntimeException('Vault is locked — re-authenticate to unlock again');
        }
        return $this->masterKey;
    }

    /**
     * The vault this session was opened on. Writes made through it are refused
     * once the userId has been given another vault.
     */
    public function generation(): VaultGeneration
    {
        return new VaultGeneration($this->userId, $this->vaultSalt);
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    /**
     * Best-effort memory wipe + mark locked. Idempotent.
     */
    public function lock(): void
    {
        if ($this->locked) {
            return;
        }
        Primitives::zeroize($this->masterKey);
        $this->locked = true;
    }

    public function __destruct()
    {
        $this->lock();
    }

    /**
     * Forbid serialization of the unwrapped key.
     */
    public function __serialize(): array
    {
        throw new RuntimeException('UnlockedVault must not be serialized');
    }

    public function __unserialize(array $data): void
    {
        throw new RuntimeException('UnlockedVault must not be unserialized');
    }

    /**
     * Keep the key out of var_dump() and print_r(): a debug page or a log line is
     * where it would land first. var_export() reads properties directly and is
     * not covered — the rule above (never log this object) still holds.
     */
    public function __debugInfo(): array
    {
        return ['userId' => $this->userId, 'locked' => $this->locked, 'masterKey' => '[redacted]'];
    }

    /**
     * Forbid cloning: a clone carries its own copy of the key, which lock() on
     * the original never wipes.
     */
    public function __clone()
    {
        throw new RuntimeException('UnlockedVault must not be cloned');
    }
}
