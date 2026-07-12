<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use DateTimeImmutable;
use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Vault\UnlockedVault;
use RuntimeException;

/**
 * Stateless service implementing the escrow (recovery-escrow) envelope.
 *
 * The escrow compartment has its OWN key, distinct from the vault's
 * data_master_key — this is the crux of compartmentalisation:
 *
 *     escrow_key   ←  random(256 bits)
 *
 *     wrap_user    ←  AES-256-GCM(escrow_key, data_master_key)      (AAD userId|escrow)
 *     wrap_admin   ←  crypto_box_seal(escrow_key, admin_public_key) (anonymous sealed box)
 *
 * The user opens wrap_user with the master key they already hold. The admin
 * opens wrap_admin with the recovery secret key (itself passphrase-sealed, see
 * AdminKey) during a genuine recovery. Either path yields escrow_key — and ONLY
 * escrow_key, so the private zone (notes, passwords…) stays out of reach.
 *
 * Policy gates (litige open, SU audit logging) live in the application/adapter,
 * NOT here: this class is pure crypto.
 */
final class EscrowVault
{
    /** AAD binding the user-wrap to its owner and purpose. */
    public const WRAP_AAD_SUFFIX = '|escrow';

    public function __construct(
        private readonly ?DateTimeImmutable $clock = null
    ) {
    }

    /**
     * Create a fresh escrow compartment for a user. Requires an active session
     * (to bind wrap_user to the master key) and the admin recovery public key
     * (to seal wrap_admin).
     *
     * @return array{record: EscrowRecord, unlocked: UnlockedEscrow}
     */
    public function create(UnlockedVault $session, string $adminPublicKeyB64): array
    {
        $adminPublicKey = self::decodePublicKey($adminPublicKeyB64);

        $escrowKey = Primitives::randomBytes(Primitives::KEY_LEN);

        $wrapUser  = Primitives::aesGcmEncrypt(
            $escrowKey,
            $session->getMasterKey(),
            aad: $session->userId . self::WRAP_AAD_SUFFIX
        );
        $wrapAdmin = sodium_crypto_box_seal($escrowKey, $adminPublicKey);

        $now = $this->now();
        $record = new EscrowRecord(
            userId:    $session->userId,
            wrapUser:  $wrapUser,
            wrapAdmin: $wrapAdmin,
            createdAt: $now,
            updatedAt: $now
        );

        $unlocked = new UnlockedEscrow(userId: $session->userId, escrowKey: $escrowKey);
        Primitives::zeroize($escrowKey);

        return ['record' => $record, 'unlocked' => $unlocked];
    }

    /**
     * Open the escrow as the USER (daily access) via their unlocked main vault.
     *
     * @throws RuntimeException if the session doesn't match or the wrap fails.
     */
    public function unlockAsUser(EscrowRecord $record, UnlockedVault $session): UnlockedEscrow
    {
        if ($session->userId !== $record->userId) {
            throw new InvalidArgumentException('Session userId does not match escrow record');
        }

        try {
            $escrowKey = Primitives::aesGcmDecrypt(
                $record->wrapUser,
                $session->getMasterKey(),
                aad: $record->userId . self::WRAP_AAD_SUFFIX
            );
        } catch (RuntimeException $e) {
            throw new RuntimeException('Could not unwrap escrow with user master key', previous: $e);
        }

        $unlocked = new UnlockedEscrow(userId: $record->userId, escrowKey: $escrowKey);
        Primitives::zeroize($escrowKey);
        return $unlocked;
    }

    /**
     * Open the escrow as the ADMIN (recovery ceremony) with the recovery secret
     * key + public key. The caller is responsible for the passphrase unseal
     * (AdminKey::unseal) and for zeroizing the secret key afterwards.
     *
     * @param string $adminSecretKey raw 32-byte secret key (from AdminKey::unseal)
     * @throws RuntimeException if the sealed box fails to open (wrong key).
     */
    public function unlockAsAdmin(EscrowRecord $record, string $adminSecretKey, string $adminPublicKeyB64): UnlockedEscrow
    {
        $adminPublicKey = self::decodePublicKey($adminPublicKeyB64);
        if (strlen($adminSecretKey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            throw new InvalidArgumentException('adminSecretKey must be a raw box secret key');
        }

        $keypair   = sodium_crypto_box_keypair_from_secretkey_and_publickey($adminSecretKey, $adminPublicKey);
        $escrowKey = sodium_crypto_box_seal_open($record->wrapAdmin, $keypair);
        sodium_memzero($keypair);

        if ($escrowKey === false) {
            throw new RuntimeException('Escrow sealed box failed to open — wrong admin key');
        }

        $unlocked = new UnlockedEscrow(userId: $record->userId, escrowKey: $escrowKey);
        Primitives::zeroize($escrowKey);
        return $unlocked;
    }

    private static function decodePublicKey(string $b64): string
    {
        $pk = base64_decode($b64, true);
        if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('Invalid admin public key (expected base64 of 32 bytes)');
        }
        return $pk;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock ?? new DateTimeImmutable();
    }
}
