<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\Primitives;

/**
 * Admin recovery keypair for the escrow compartment (whitepaper §4.2, revised).
 *
 * The escrow sub-vault is sealed to an admin PUBLIC key (anonymous sealed box).
 * The matching SECRET key must be openable only during a genuine recovery
 * ceremony — so it is never stored in the clear. Instead it lives on the
 * deployment server (VPS/NAS) SEALED by an admin passphrase (Argon2id), exactly
 * like the SelfRecover-SU secret model:
 *
 *   sealed_secret = base64(salt) || ":" || base64( AES-256-GCM(secret_key,
 *                        key = Argon2id(passphrase, salt)) )
 *
 * Threat model consequence: a server seized cold gives the attacker the DB, the
 * blindKey, the admin PUBLIC key and this sealed blob — but WITHOUT the admin
 * passphrase the secret key stays encrypted, so every escrow stays closed.
 *
 * The public key is not a secret and is stored in the clear (needed at deposit
 * time to seal each user's escrow_key).
 */
final class AdminKey
{
    /** Domain separator bound into the sealed-secret AAD. */
    public const SEAL_AAD = 'selfdataguard/admin-recovery-sk';

    private function __construct()
    {
    }

    /**
     * Generate a fresh admin recovery keypair and seal the secret key under a
     * passphrase. Returns the public key (clear) and the sealed secret (to be
     * persisted on the deployment server).
     *
     * @return array{publicKey: string, sealedSecret: string} both base64
     */
    public static function generate(string $passphrase): array
    {
        if ($passphrase === '') {
            throw new InvalidArgumentException('Admin passphrase must not be empty');
        }

        $keypair   = sodium_crypto_box_keypair();
        $secretKey = sodium_crypto_box_secretkey($keypair);
        $publicKey = sodium_crypto_box_publickey($keypair);

        $salt    = Primitives::randomBytes(Primitives::SALT_LEN);
        $sealKey = Primitives::deriveFromPassword($passphrase, $salt);
        $blob    = Primitives::aesGcmEncrypt($secretKey, $sealKey, aad: self::SEAL_AAD);

        Primitives::zeroize($sealKey);
        sodium_memzero($secretKey);
        sodium_memzero($keypair);

        return [
            'publicKey'    => base64_encode($publicKey),
            'sealedSecret' => base64_encode($salt) . ':' . $blob->toBase64(),
        ];
    }

    /**
     * Unseal the admin secret key using the passphrase. Returns the RAW 32-byte
     * secret key — the caller MUST zeroize it after use (sodium_memzero).
     *
     * @throws \RuntimeException on wrong passphrase (auth tag mismatch)
     */
    public static function unseal(string $sealedSecret, string $passphrase): string
    {
        if ($passphrase === '') {
            throw new InvalidArgumentException('Admin passphrase must not be empty');
        }
        if (!str_contains($sealedSecret, ':')) {
            throw new InvalidArgumentException('Malformed sealed secret (expected "salt:blob")');
        }

        [$saltB64, $blobB64] = explode(':', $sealedSecret, 2);
        $salt = base64_decode($saltB64, true);
        if ($salt === false || strlen($salt) < Primitives::SALT_LEN) {
            throw new InvalidArgumentException('Malformed sealed secret (invalid salt)');
        }

        $sealKey   = Primitives::deriveFromPassword($passphrase, $salt);
        $secretKey = Primitives::aesGcmDecrypt(EncryptedBlob::fromBase64($blobB64), $sealKey, aad: self::SEAL_AAD);
        Primitives::zeroize($sealKey);

        return $secretKey;
    }
}
