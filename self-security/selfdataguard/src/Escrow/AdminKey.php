<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Vault\UserVault;

/**
 * Admin recovery keypair for the escrow compartment (whitepaper §4.2, revised).
 *
 * The escrow sub-vault is sealed to an admin PUBLIC key (anonymous sealed box).
 * The matching SECRET key must be openable only during a genuine recovery
 * ceremony — so it is never stored in the clear. Instead it lives on the
 * deployment server (VPS/NAS) SEALED by an admin passphrase (Argon2id), exactly
 * like the SelfRecover-SU secret model:
 *
 *   sealed_secret = "v2:" || opslimit || ":" || memlimit || ":" || base64(salt) || ":"
 *                   || EncryptedBlob( XChaCha20-Poly1305(secret_key, key = Argon2id(passphrase,
 *                        salt, opslimit, memlimit)) )
 *
 * The Argon2id profile travels with the sealed secret, so that changing
 * Primitives::ARGON2_* never locks out a key sealed earlier. The format before
 * 0.6.0, "salt:blob", is still opened, under Primitives::LEGACY_*; a secret sealed
 * before 0.4.0 carries an AES-256-GCM blob, which unseal() still opens too.
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

    /** Version tag of the sealed-secret format that records its Argon2id profile. */
    public const FORMAT_V2 = 'v2';

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
    public static function generate(#[\SensitiveParameter] string $passphrase): array
    {
        // This passphrase guards the escrow of every account: it gets at least the
        // vault's floor. Applied when sealing only — unseal() must still open a key
        // sealed under a shorter passphrase.
        if (strlen($passphrase) < UserVault::PASSWORD_MIN_LEN) {
            throw new InvalidArgumentException(sprintf(
                'Admin passphrase must be at least %d bytes; got %d.',
                UserVault::PASSWORD_MIN_LEN,
                strlen($passphrase)
            ));
        }

        $keypair   = sodium_crypto_box_keypair();
        $secretKey = sodium_crypto_box_secretkey($keypair);
        $publicKey = sodium_crypto_box_publickey($keypair);

        $ops     = Primitives::ARGON2_OPSLIMIT;
        $mem     = Primitives::ARGON2_MEMLIMIT;
        $salt    = Primitives::randomBytes(Primitives::SALT_LEN);
        $sealKey = Primitives::deriveFromPassword($passphrase, $salt, $ops, $mem);
        $blob    = Primitives::encrypt($secretKey, $sealKey, aad: self::SEAL_AAD);

        Primitives::zeroize($sealKey);
        sodium_memzero($secretKey);
        sodium_memzero($keypair);

        return [
            'publicKey'    => base64_encode($publicKey),
            'sealedSecret' => implode(':', [self::FORMAT_V2, $ops, $mem, base64_encode($salt), $blob->toBase64()]),
        ];
    }

    /**
     * Unseal the admin secret key using the passphrase. Returns the RAW 32-byte
     * secret key — the caller MUST zeroize it after use (sodium_memzero).
     *
     * @throws \RuntimeException on wrong passphrase (auth tag mismatch)
     */
    public static function unseal(string $sealedSecret, #[\SensitiveParameter] string $passphrase): string
    {
        if ($passphrase === '') {
            throw new InvalidArgumentException('Admin passphrase must not be empty');
        }
        [$ops, $mem, $saltB64, $blobB64] = self::parse($sealedSecret);
        $salt = base64_decode($saltB64, true);
        if ($salt === false || strlen($salt) < Primitives::SALT_LEN) {
            throw new InvalidArgumentException('Malformed sealed secret (invalid salt)');
        }

        $sealKey   = Primitives::deriveFromPassword($passphrase, $salt, $ops, $mem);
        $secretKey = Primitives::decrypt(EncryptedBlob::fromBase64($blobB64), $sealKey, aad: self::SEAL_AAD);
        Primitives::zeroize($sealKey);

        return $secretKey;
    }

    /**
     * Split a sealed secret into its Argon2id profile, salt and blob. A base64 salt
     * never reads as a version tag (v<digits>), so the first field tells the formats
     * apart; a version this code does not know is refused rather than read as a salt.
     *
     * @return array{0: int, 1: int, 2: string, 3: string}
     */
    private static function parse(string $sealedSecret): array
    {
        $parts = explode(':', $sealedSecret);
        if (preg_match('/^v\d+\z/', $parts[0]) !== 1) {
            if (count($parts) !== 2) {
                throw new InvalidArgumentException(
                    'Malformed sealed secret (expected "salt:blob" or "v2:ops:mem:salt:blob")'
                );
            }
            return [Primitives::LEGACY_OPSLIMIT, Primitives::LEGACY_MEMLIMIT, $parts[0], $parts[1]];
        }
        if ($parts[0] !== self::FORMAT_V2) {
            throw new InvalidArgumentException(
                sprintf('Sealed secret format %s is newer than this version of SelfDataGuard', $parts[0])
            );
        }
        if (count($parts) !== 5 || preg_match('/^\d+:\d+\z/', $parts[1] . ':' . $parts[2]) !== 1) {
            throw new InvalidArgumentException('Malformed sealed secret (expected "v2:ops:mem:salt:blob")');
        }

        return [(int) $parts[1], (int) $parts[2], $parts[3], $parts[4]];
    }
}
