<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use Pierroons\SelfDataGuard\Crypto\Primitives;
use RuntimeException;

/**
 * Holds the unwrapped escrow_key in memory for the duration of an escrow
 * operation (user access OR admin recovery).
 *
 * Mirrors UnlockedVault: best-effort zeroize on lock/destruct, no serialization.
 * The escrow_key opens ONLY the escrow fields — never the main vault. This is
 * the crypto enforcement of "the admin reads the recovery data, never the
 * private zone".
 *
 * NEVER serialize, log, or persist this object.
 */
final class UnlockedEscrow
{
    private string $escrowKey;
    private bool $locked = false;

    public function __construct(
        public readonly string $userId,
        string $escrowKey
    ) {
        if (strlen($escrowKey) !== Primitives::KEY_LEN) {
            throw new RuntimeException(
                'escrowKey must be exactly ' . Primitives::KEY_LEN . ' bytes'
            );
        }
        $this->escrowKey = $escrowKey;
    }

    public function getEscrowKey(): string
    {
        if ($this->locked) {
            throw new RuntimeException('Escrow is locked — re-unlock to access again');
        }
        return $this->escrowKey;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function lock(): void
    {
        if ($this->locked) {
            return;
        }
        Primitives::zeroize($this->escrowKey);
        $this->locked = true;
    }

    public function __destruct()
    {
        $this->lock();
    }

    public function __serialize(): array
    {
        throw new RuntimeException('UnlockedEscrow must not be serialized');
    }

    public function __unserialize(array $data): void
    {
        throw new RuntimeException('UnlockedEscrow must not be unserialized');
    }
}
