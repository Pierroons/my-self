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
    private ?string $objectionSeed;
    private bool $locked = false;

    /**
     * Sealed to a keeper, an escrow also holds what wrap_user carries beside its
     * key: the escrowId, the keeper's two public keys, and the seed of the key
     * its holder objects with. The escrowId and the keeper's keys are public;
     * the seed is not, and stays here like the key.
     *
     * @param array{sign: string, box: string}|null $keeper the keeper's public keys
     */
    public function __construct(
        public readonly string $userId,
        #[\SensitiveParameter] string $escrowKey,
        public readonly ?string $escrowId = null,
        public readonly ?array $keeper = null,
        #[\SensitiveParameter] ?string $objectionSeed = null
    ) {
        if (strlen($escrowKey) !== Primitives::KEY_LEN) {
            throw new RuntimeException(
                'escrowKey must be exactly ' . Primitives::KEY_LEN . ' bytes'
            );
        }
        if (($escrowId === null) !== ($keeper === null) || ($keeper === null) !== ($objectionSeed === null)) {
            throw new RuntimeException('An escrow sealed to a keeper names its escrowId, its keeper and its objection seed');
        }
        if ($keeper !== null) {
            KeeperSeal::checkKeys($keeper);
            if (strlen((string) $escrowId) !== KeeperSeal::ESCROW_ID_LEN || strlen((string) $objectionSeed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
                throw new RuntimeException('Malformed keeper binding: escrowId or objection seed of the wrong length');
            }
        }
        $this->escrowKey     = $escrowKey;
        $this->objectionSeed = $objectionSeed;
        if ($keeper !== null && !hash_equals(KeeperSeal::escrowIdFor($this->objectionPublic()), (string) $escrowId)) {
            $this->lock();
            throw new RuntimeException('This escrowId is not the fingerprint of the objection key wrap_user carries');
        }
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

    /**
     * wrap_admin under this escrow's keeper: the account-bound wrap of
     * sealToAdmin(), sealed again to the keeper, with the public half of the
     * objection key inside.
     */
    public function sealToKeeper(string $adminPublicKeyB64): string
    {
        if ($this->keeper === null) {
            throw new RuntimeException('This escrow is not bound to a keeper');
        }
        $inner = $this->sealToAdmin($adminPublicKeyB64);
        return KeeperSeal::wrap((string) $this->escrowId, $this->userId, $this->objectionPublic(), $inner, $this->keeper['box']);
    }

    private function objectionPublic(): string
    {
        $pair = sodium_crypto_sign_seed_keypair($this->seed());
        try {
            return sodium_crypto_sign_publickey($pair);
        } finally {
            Primitives::zeroize($pair);
        }
    }

    private function seed(): string
    {
        if ($this->locked) {
            throw new RuntimeException('Escrow is locked — re-unlock to access again');
        }
        return (string) $this->objectionSeed;
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
        if ($this->objectionSeed !== null) {
            Primitives::zeroize($this->objectionSeed);
        }
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
        return ['userId' => $this->userId, 'locked' => $this->locked, 'escrowKey' => '[redacted]',
                'escrowId' => $this->escrowId === null ? null : bin2hex($this->escrowId),
                'keeper' => $this->keeper === null ? null : KeeperSeal::fingerprint($this->keeper),
                'objectionSeed' => $this->objectionSeed === null ? null : '[redacted]'];
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
