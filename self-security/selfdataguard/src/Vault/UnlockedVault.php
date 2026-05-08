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
 * NEVER serialize, log, or persist this object.
 */
final class UnlockedVault
{
    private string $masterKey;
    private bool $locked = false;

    public function __construct(
        public readonly string $userId,
        string $masterKey
    ) {
        if (strlen($masterKey) !== Primitives::KEY_LEN) {
            throw new RuntimeException(
                'masterKey must be exactly ' . Primitives::KEY_LEN . ' bytes'
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
}
