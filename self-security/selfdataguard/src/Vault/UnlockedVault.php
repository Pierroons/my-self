<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use Pierroons\SelfDataGuard\Crypto\Primitives;
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

    /**
     * Get the data master key. Caller MUST treat the return as ephemeral and
     * not persist it.
     */
    public function getMasterKey(): string
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
