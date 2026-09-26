<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Crypto;

use InvalidArgumentException;

/**
 * Result of an authenticated encryption, in one of two formats:
 *
 *   v2  XChaCha20-Poly1305 (IETF), 24-byte nonce. Every write since 0.4.0.
 *       Stored as "SDG2." . base64(nonce || ciphertext_with_tag).
 *   v1  AES-256-GCM, 12-byte nonce. Written up to 0.3.0, now read only.
 *       Stored as base64(nonce || ciphertext_with_tag), with no marker.
 *
 * In memory, the nonce length tells the two apart. In storage, "." is not a
 * base64 character, so no v1 string can begin with a v2 prefix.
 *
 * The ciphertext field includes the 16-byte authentication tag appended at the
 * end (libsodium convention for AEAD).
 *
 * Immutable value object.
 */
final class EncryptedBlob
{
    public const PREFIX_V2    = 'SDG2.';
    public const NONCE_LEN_V2 = 24;
    public const NONCE_LEN_V1 = 12;
    public const TAG_LEN      = 16;

    public function __construct(
        public readonly string $ciphertext,
        public readonly string $nonce
    ) {
        $nonceLen = strlen($nonce);
        if ($nonceLen !== self::NONCE_LEN_V2 && $nonceLen !== self::NONCE_LEN_V1) {
            throw new InvalidArgumentException(
                'Nonce must be ' . self::NONCE_LEN_V2 . ' bytes (XChaCha20-Poly1305) or '
                . self::NONCE_LEN_V1 . ' bytes (legacy AES-256-GCM); got ' . $nonceLen
            );
        }
        if (strlen($ciphertext) < self::TAG_LEN) {
            throw new InvalidArgumentException(
                'Ciphertext must be at least ' . self::TAG_LEN
                . ' bytes (auth tag); got ' . strlen($ciphertext)
            );
        }
    }

    /** True for a v1 blob: AES-256-GCM, written before 0.4.0. */
    public function isLegacy(): bool
    {
        return strlen($this->nonce) === self::NONCE_LEN_V1;
    }

    /**
     * Serialize to a single string for storage in DB columns, in the format the
     * blob was written in.
     */
    public function toBase64(): string
    {
        $encoded = base64_encode($this->nonce . $this->ciphertext);

        return $this->isLegacy() ? $encoded : self::PREFIX_V2 . $encoded;
    }

    /**
     * Deserialize a string produced by toBase64(), of either format.
     *
     * @throws InvalidArgumentException on malformed input, or on a "SDG<n>." prefix
     *                                  this version does not know
     */
    public static function fromBase64(string $encoded): self
    {
        if (str_starts_with($encoded, self::PREFIX_V2)) {
            return self::split(substr($encoded, strlen(self::PREFIX_V2)), self::NONCE_LEN_V2);
        }
        if (preg_match('/^SDG(\d+)\./', $encoded, $m) === 1) {
            throw new InvalidArgumentException(
                "Blob format SDG{$m[1]} is unknown to this version of SelfDataGuard: "
                . 'it was written by a newer one. Upgrade the library to read it.'
            );
        }

        return self::split($encoded, self::NONCE_LEN_V1);
    }

    private static function split(string $b64, int $nonceLen): self
    {
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            throw new InvalidArgumentException('Invalid base64 input');
        }
        $minLength = $nonceLen + self::TAG_LEN;
        if (strlen($raw) < $minLength) {
            throw new InvalidArgumentException(
                'Encoded blob too short; need ≥' . $minLength . ' bytes, got ' . strlen($raw)
            );
        }

        return new self(
            ciphertext: substr($raw, $nonceLen),
            nonce: substr($raw, 0, $nonceLen)
        );
    }
}
