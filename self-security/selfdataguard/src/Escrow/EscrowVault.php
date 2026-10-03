<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use DateTimeImmutable;
use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\LegacyCipherUnavailableException;
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
 *     wrap_user    ←  XChaCha20-Poly1305(escrow_key, data_master_key) (AAD userId|escrow)
 *     wrap_admin   ←  crypto_box_seal(tag ‖ len(userId) ‖ userId ‖ escrow_key, admin_public_key)
 *
 * A sealed box has no AAD: the account goes inside it, and unlockAsAdmin()
 * refuses a wrap_admin that names another account than its record. A wrap_admin
 * sealed before 0.6.0 holds the bare 32-byte key and names no account; it is
 * still opened, and SelfDataGuard::rebindEscrowAdmin() re-seals it in the new form.
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
    /**
     * AAD binding the user-wrap to its owner and purpose. The user-wrap is sealed
     * under the data_master_key, like private fields, whose AAD is "userId|name":
     * a field named after this tag would share its context, so FieldCrypter
     * refuses the name.
     */
    public const WRAP_AAD_TAG = 'escrow';
    public const WRAP_AAD_SUFFIX = '|' . self::WRAP_AAD_TAG;

    /** Prefixes the plaintext of a wrap_admin that names its account. */
    private const ADMIN_WRAP_TAG = "selfdataguard/escrow-admin/v1\0";

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

        $wrapUser  = Primitives::encrypt(
            $escrowKey,
            $session->getMasterKey(),
            aad: $session->userId . self::WRAP_AAD_SUFFIX
        );
        $wrapAdmin = self::sealForAdmin($session->userId, $escrowKey, $adminPublicKey);

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
            $escrowKey = Primitives::decrypt(
                $record->wrapUser,
                $session->getMasterKey(),
                aad: $record->userId . self::WRAP_AAD_SUFFIX
            );
        } catch (LegacyCipherUnavailableException $e) {
            // A RuntimeException too: without this, the next catch hides a machine limit.
            throw $e;
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
    public function unlockAsAdmin(EscrowRecord $record, #[\SensitiveParameter] string $adminSecretKey, string $adminPublicKeyB64): UnlockedEscrow
    {
        $adminPublicKey = self::decodePublicKey($adminPublicKeyB64);
        if (strlen($adminSecretKey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            throw new InvalidArgumentException('adminSecretKey must be a raw box secret key');
        }

        $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($adminSecretKey, $adminPublicKey);
        $plain   = sodium_crypto_box_seal_open($record->wrapAdmin, $keypair);
        sodium_memzero($keypair);

        if ($plain === false) {
            throw new RuntimeException('Escrow sealed box failed to open — wrong admin key');
        }

        if (strlen($plain) === Primitives::KEY_LEN) {
            $escrowKey = $plain;   // sealed before 0.6.0: names no account
        } else {
            $head = self::adminHead($record->userId);
            if (strlen($plain) !== strlen($head) + Primitives::KEY_LEN
                || !hash_equals($head, substr($plain, 0, strlen($head)))) {
                Primitives::zeroize($plain);
                throw new RuntimeException(
                    'Escrow admin wrap was sealed for another account than its record — refused'
                );
            }
            $escrowKey = substr($plain, strlen($head));
            Primitives::zeroize($plain);
        }

        $unlocked = new UnlockedEscrow(userId: $record->userId, escrowKey: $escrowKey);
        Primitives::zeroize($escrowKey);
        return $unlocked;
    }

    /**
     * Whether this wrap_admin names its account. Told by length alone, without
     * the admin key: a pre-0.6.0 one seals the bare key.
     */
    public static function isAccountBound(EscrowRecord $record): bool
    {
        return strlen($record->wrapAdmin) !== Primitives::KEY_LEN + SODIUM_CRYPTO_BOX_SEALBYTES;
    }

    /**
     * Re-seal wrap_admin in the form that names the account, from the user's
     * side: the escrow key is in hand, the admin public key is all it takes.
     */
    public function rebindAdmin(EscrowRecord $record, UnlockedEscrow $unlocked, string $adminPublicKeyB64): EscrowRecord
    {
        if ($unlocked->userId !== $record->userId) {
            throw new InvalidArgumentException('Unlocked escrow userId does not match escrow record');
        }

        return new EscrowRecord(
            userId:    $record->userId,
            wrapUser:  $record->wrapUser,
            wrapAdmin: self::sealForAdmin(
                $record->userId,
                $unlocked->getEscrowKey(),
                self::decodePublicKey($adminPublicKeyB64)
            ),
            createdAt: $record->createdAt,
            updatedAt: $this->now()
        );
    }

    private static function sealForAdmin(string $userId, #[\SensitiveParameter] string $escrowKey, string $adminPublicKey): string
    {
        $plain  = self::adminHead($userId) . $escrowKey;
        $sealed = sodium_crypto_box_seal($plain, $adminPublicKey);
        Primitives::zeroize($plain);
        return $sealed;
    }

    private static function adminHead(string $userId): string
    {
        return self::ADMIN_WRAP_TAG . pack('N', strlen($userId)) . $userId;
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
