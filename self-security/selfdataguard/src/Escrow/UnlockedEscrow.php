<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use RuntimeException;

/**
 * Holds the unwrapped escrow_key in memory for the duration of an escrow
 * operation (user access OR admin recovery).
 *
 * Mirrors UnlockedVault: best-effort zeroize on lock/destruct, no serialization.
 * The escrow_key opens ONLY the escrow fields — never the main vault. This is
 * the crypto enforcement of "the admin reads the recovery data, never the
 * private zone". Like UnlockedVault, it never hands its key out: it encrypts,
 * decrypts and seals itself to the administrator.
 *
 * NEVER serialize, log, or persist this object.
 */
final class UnlockedEscrow
{
    private string $escrowKey;
    private bool $locked = false;

    public function __construct(
        public readonly string $userId,
        #[\SensitiveParameter] string $escrowKey
    ) {
        if (strlen($escrowKey) !== Primitives::KEY_LEN) {
            throw new RuntimeException(
                'escrowKey must be exactly ' . Primitives::KEY_LEN . ' bytes'
            );
        }
        $this->escrowKey = $escrowKey;
    }

    /** An escrow field, bound to this account and to its name. */
    public function encryptField(string $fieldName, #[\SensitiveParameter] string $plaintext): EncryptedBlob
    {
        return Primitives::encrypt($plaintext, $this->key(), EscrowFieldCrypter::aadFor($this->userId, $fieldName));
    }

    public function decryptField(string $fieldName, EncryptedBlob $blob): string
    {
        return Primitives::decryptAcrossFormats(
            $blob,
            $this->key(),
            EscrowFieldCrypter::aadFor($this->userId, $fieldName),
            EscrowFieldCrypter::legacyAadFor($this->userId, $fieldName)
        );
    }

    /**
     * wrap_admin: this escrow's key sealed to the administrator's public key,
     * in the form that names the account.
     */
    public function sealToAdmin(string $adminPublicKeyB64): string
    {
        $plain = EscrowVault::adminHead($this->userId) . $this->key();
        try {
            return sodium_crypto_box_seal($plain, EscrowVault::decodePublicKey($adminPublicKeyB64));
        } finally {
            Primitives::zeroize($plain);
        }
    }

    private function key(): string
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

    /** Keep the key out of var_dump() and print_r(), as UnlockedVault does. */
    public function __debugInfo(): array
    {
        return ['userId' => $this->userId, 'locked' => $this->locked, 'escrowKey' => '[redacted]'];
    }

    /**
     * Forbid cloning: a clone carries its own copy of the key, which lock() on
     * the original never wipes.
     */
    public function __clone()
    {
        throw new RuntimeException('UnlockedEscrow must not be cloned');
    }
}
