<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Crypto;

use InvalidArgumentException;
use RuntimeException;
use SodiumException;

/**
 * Cryptographic primitives for SelfDataGuard.
 *
 * All inputs and outputs are raw binary unless explicitly named "base64".
 * Encoding/decoding is the caller's responsibility — keeps the surface clean
 * and avoids ambiguity about which layer encodes.
 *
 * Per whitepaper §5:
 *   - Password derivation : Argon2id (m=64 MiB, t=3) — RFC 9106 / OWASP / ANSSI
 *   - Memorized derivation: Argon2id, same profile — see below
 *   - Encryption          : AES-256-GCM (NIST, hardware-accelerated, AEAD)
 *   - Randomness          : random_bytes (PHP CSPRNG)
 *
 * 🔑 Both human secrets go through the SAME cost. Until 0.2.0 the memorized
 * secret was derived with a single HMAC-SHA256 pass, on the stated assumption
 * that "entropy comes from the secret". Measured on the dev host, that made the
 * two doors 78 100 times apart: 213.7 ms per Argon2id attempt against 0.0027 ms
 * per HMAC attempt. Both doors open the same data_master_key, and a wrapped key
 * is attacked offline with no attempt counter — so the security of the pair was
 * that of the cheaper door, whatever the other one cost.
 *
 * A salt defeats precomputation; it adds no bit against one targeted person. An
 * AEAD tag tells an attacker which guess was right; it slows nothing. Only the
 * cost per attempt buys time, and it buys a MULTIPLIER, never entropy.
 *
 * ⚠️ Which is why this change is necessary and not sufficient. The library
 * enforces NO entropy floor on the memorized secret: a weak word stays weak, and
 * ~13 bits of guessing plus ~13 bits of added cost is still ~26 bits of work on a
 * database dump. Whether a floor belongs here — and what it would cost, since a
 * floor high enough to matter ends the "one memorized word, two uses" pairing
 * with SelfRecover — is an open question, stated as such in whitepaper §7. It is
 * not a question this file may answer on its own.
 */
final class Primitives
{
    public const ARGON2_OPSLIMIT = 3;
    public const ARGON2_MEMLIMIT = 65536 * 1024;
    public const SALT_LEN        = 16;
    public const KEY_LEN         = 32;
    public const NONCE_LEN       = 12;
    public const HMAC_ALGO       = 'sha256';

    private function __construct()
    {
    }

    /**
     * Derive a 256-bit key from a user password using Argon2id.
     *
     * Memory-hard, GPU/ASIC-resistant. The salt MUST be unique per user and
     * stored alongside the wrapped key (it is not a secret).
     *
     * @param string $password Plain-text password (UTF-8)
     * @param string $salt     Per-user random salt of length ≥SALT_LEN bytes
     * @return string 32 raw bytes
     */
    public static function deriveFromPassword(
        string $password,
        string $salt,
        int $opslimit = self::ARGON2_OPSLIMIT,
        int $memlimit = self::ARGON2_MEMLIMIT
    ): string {
        if ($password === '') {
            throw new InvalidArgumentException('Password must not be empty');
        }
        if (strlen($salt) < self::SALT_LEN) {
            throw new InvalidArgumentException(
                'Salt must be ≥' . self::SALT_LEN . ' bytes; got ' . strlen($salt)
            );
        }

        $saltCanonical = substr($salt, 0, SODIUM_CRYPTO_PWHASH_SALTBYTES);

        return sodium_crypto_pwhash(
            self::KEY_LEN,
            $password,
            $saltCanonical,
            $opslimit,
            $memlimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }

    /**
     * Derive a 256-bit key from a memorized secret using Argon2id.
     *
     * Same cost profile as deriveFromPassword: the two keys unwrap the same
     * data_master_key, so the pair is only as strong as its cheaper derivation.
     *
     * The context string carries domain separation and is of free length:
     *   - SelfDataGuard: context = "<user_salt>/dataguard"
     * Argon2id wants exactly SODIUM_CRYPTO_PWHASH_SALTBYTES, so the context is
     * condensed into the salt. A salt is not a secret — it only has to be unique
     * per target, and the context already carries the per-user salt.
     *
     * @param string $memorized Plain-text memorized secret
     * @param string $context   Domain separation string (non-empty)
     * @return string 32 raw bytes
     */
    public static function deriveFromMemorized(
        string $memorized,
        string $context,
        int $opslimit = self::ARGON2_OPSLIMIT,
        int $memlimit = self::ARGON2_MEMLIMIT
    ): string {
        if ($memorized === '') {
            throw new InvalidArgumentException('Memorized secret must not be empty');
        }
        if ($context === '') {
            throw new InvalidArgumentException('Context separator must not be empty');
        }

        $salt = substr(
            hash(self::HMAC_ALGO, $context, true),
            0,
            SODIUM_CRYPTO_PWHASH_SALTBYTES
        );

        return sodium_crypto_pwhash(
            self::KEY_LEN,
            $memorized,
            $salt,
            $opslimit,
            $memlimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }

    /**
     * The pre-0.2.0 derivation: one HMAC-SHA256 pass.
     *
     * ⚠️ DIAGNOSTIC ONLY. This must never gate access to anything. It exists so
     * that a recovery wrap sealed by an older version can be RECOGNISED and
     * named — "this vault predates the Argon2id recovery derivation, re-seal it"
     * — instead of failing as "invalid memorized secret", which would send
     * someone hunting for a typo in a secret that is perfectly correct.
     *
     * A wrap it opens is not a wrap the caller may use. UserVault throws.
     */
    public static function deriveFromMemorizedLegacyV1(string $memorized, string $context): string
    {
        if ($memorized === '' || $context === '') {
            throw new InvalidArgumentException('Legacy derivation needs both arguments');
        }
        return hash_hmac(self::HMAC_ALGO, $context, $memorized, true);
    }

    /**
     * Authenticated encryption with AES-256-GCM and a fresh random nonce.
     *
     * @param string      $plaintext Raw data to encrypt
     * @param string      $key       Exactly 32 bytes
     * @param string|null $aad       Optional additional authenticated data (bound to the ciphertext, not encrypted)
     */
    public static function aesGcmEncrypt(
        string $plaintext,
        string $key,
        ?string $aad = null
    ): EncryptedBlob {
        self::assertKeyLength($key);
        if (!sodium_crypto_aead_aes256gcm_is_available()) {
            throw new RuntimeException(
                'AES-256-GCM not supported on this CPU (no AES-NI)'
            );
        }
        $nonce = random_bytes(self::NONCE_LEN);
        $ciphertext = sodium_crypto_aead_aes256gcm_encrypt(
            $plaintext,
            (string) $aad,
            $nonce,
            $key
        );
        return new EncryptedBlob(ciphertext: $ciphertext, nonce: $nonce);
    }

    /**
     * Verify and decrypt an EncryptedBlob.
     *
     * @throws RuntimeException if the auth tag fails to verify (tampered ciphertext, wrong key, tampered AAD).
     */
    public static function aesGcmDecrypt(
        EncryptedBlob $blob,
        string $key,
        ?string $aad = null
    ): string {
        self::assertKeyLength($key);
        if (!sodium_crypto_aead_aes256gcm_is_available()) {
            throw new RuntimeException(
                'AES-256-GCM not supported on this CPU (no AES-NI)'
            );
        }
        try {
            $plaintext = sodium_crypto_aead_aes256gcm_decrypt(
                $blob->ciphertext,
                (string) $aad,
                $blob->nonce,
                $key
            );
        } catch (SodiumException $e) {
            throw new RuntimeException('Decryption failed: ' . $e->getMessage(), 0, $e);
        }
        if ($plaintext === false) {
            throw new RuntimeException(
                'Decryption failed: auth tag mismatch (corrupted ciphertext, wrong key, or tampered AAD)'
            );
        }
        return $plaintext;
    }

    /**
     * Cryptographically secure random bytes from the OS CSPRNG.
     */
    public static function randomBytes(int $length): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException('Length must be ≥1');
        }
        return random_bytes($length);
    }

    /**
     * Constant-time equality check. Use for any secret-equal comparison
     * (MACs, tokens, hashes) to prevent timing attacks.
     */
    public static function secureCompare(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /**
     * Best-effort zeroing of a sensitive variable.
     *
     * PHP strings are not guaranteed mutable, but sodium_memzero at least
     * gives the underlying libsodium buffer a chance to be wiped before GC.
     */
    public static function zeroize(string &$secret): void
    {
        if (function_exists('sodium_memzero')) {
            try {
                sodium_memzero($secret);
            } catch (SodiumException) {
                $secret = str_repeat("\0", strlen($secret));
            }
        } else {
            $secret = str_repeat("\0", strlen($secret));
        }
        $secret = '';
    }

    private static function assertKeyLength(string $key): void
    {
        if (strlen($key) !== self::KEY_LEN) {
            throw new InvalidArgumentException(
                'Key must be exactly ' . self::KEY_LEN . ' bytes; got ' . strlen($key)
            );
        }
    }
}
