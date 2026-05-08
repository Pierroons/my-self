<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Crypto;

use InvalidArgumentException;

/**
 * Result of an AES-256-GCM authenticated encryption.
 *
 * The ciphertext field already includes the 16-byte authentication tag
 * appended at the end (libsodium convention for AEAD).
 *
 * Immutable value object.
 */
final class EncryptedBlob
{
    public function __construct(
        public readonly string $ciphertext,
        public readonly string $nonce
    ) {
        if (strlen($nonce) !== Primitives::NONCE_LEN) {
            throw new InvalidArgumentException(
                'Nonce must be exactly ' . Primitives::NONCE_LEN . ' bytes'
            );
        }
        if (strlen($ciphertext) < SODIUM_CRYPTO_AEAD_AES256GCM_ABYTES) {
            throw new InvalidArgumentException(
                'Ciphertext must be at least ' . SODIUM_CRYPTO_AEAD_AES256GCM_ABYTES
                . ' bytes (auth tag); got ' . strlen($ciphertext)
            );
        }
    }

    /**
     * Serialize to a single base64 string for storage in DB columns.
     * Layout: base64(nonce || ciphertext_with_tag)
     */
    public function toBase64(): string
    {
        return base64_encode($this->nonce . $this->ciphertext);
    }

    /**
     * Deserialize from base64 produced by toBase64().
     */
    public static function fromBase64(string $b64): self
    {
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            throw new InvalidArgumentException('Invalid base64 input');
        }
        $minLength = Primitives::NONCE_LEN + SODIUM_CRYPTO_AEAD_AES256GCM_ABYTES;
        if (strlen($raw) < $minLength) {
            throw new InvalidArgumentException(
                'Encoded blob too short; need ≥' . $minLength . ' bytes, got ' . strlen($raw)
            );
        }
        return new self(
            ciphertext: substr($raw, Primitives::NONCE_LEN),
            nonce: substr($raw, 0, Primitives::NONCE_LEN)
        );
    }
}
