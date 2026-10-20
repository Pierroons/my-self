<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Crypto;

use InvalidArgumentException;

/**
 * Result of an authenticated encryption, in one of three formats:
 *
 *   v3  XChaCha20-Poly1305 (IETF), 24-byte nonce. Every write since 0.7.0.
 *       Stored as "SDG3." . base64(nonce || ciphertext_with_tag).
 *       Its AAD is an Aad value — always declared, "bound to nothing" included.
 *   v2  The same cipher, written from 0.4.0 to 0.6.x, under a flat string AAD
 *       that could be absent without anything saying so. Read only.
 *       Stored as "SDG2." . base64(nonce || ciphertext_with_tag).
 *   v1  AES-256-GCM, 12-byte nonce. Written up to 0.3.0. Read only.
 *       Stored as base64(nonce || ciphertext_with_tag), with no marker.
 *
 * v2 and v3 share a cipher and a nonce length, so the format is a field here,
 * not something to deduce. In storage the prefix tells them apart, and "." is
 * not a base64 character, so no v1 string can begin with one.
 *
 * Changing the prefix of a stored blob gains nothing: v3 authenticates a domain
 * tag its AAD carries, so a v3 read as v2 — or the reverse — fails the tag.
 *
 * The ciphertext field includes the 16-byte authentication tag appended at the
 * end (libsodium convention for AEAD).
 *
 * Immutable value object.
 */
final class EncryptedBlob
{
    public const PREFIX_V2    = 'SDG2.';
    public const PREFIX_V3    = 'SDG3.';
    public const NONCE_LEN_V2 = 24;
    public const NONCE_LEN_V1 = 12;
    public const TAG_LEN      = 16;

    public const FORMAT_V1 = 1;
    public const FORMAT_V2 = 2;
    public const FORMAT_V3 = 3;

    /**
     * @param int $format one of FORMAT_V1, FORMAT_V2, FORMAT_V3 — required, so
     *                    that no caller writes an older format by omission
     */
    public function __construct(
        public readonly string $ciphertext,
        public readonly string $nonce,
        public readonly int $format
    ) {
        if (!in_array($format, [self::FORMAT_V1, self::FORMAT_V2, self::FORMAT_V3], true)) {
            throw new InvalidArgumentException("Unknown blob format: {$format}");
        }
        $nonceLen = strlen($nonce);
        $expected = $format === self::FORMAT_V1 ? self::NONCE_LEN_V1 : self::NONCE_LEN_V2;
        if ($nonceLen !== $expected) {
            throw new InvalidArgumentException(
                "Format v{$format} takes a {$expected}-byte nonce; got {$nonceLen}"
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
        return $this->format === self::FORMAT_V1;
    }

    /**
     * True for a blob whose AAD is an Aad value, so whose binding — or its
     * declared absence — is part of what the tag authenticates.
     */
    public function bindsItsContext(): bool
    {
        return $this->format === self::FORMAT_V3;
    }

    /**
     * Serialize to a single string for storage in DB columns, in the format the
     * blob was written in.
     */
    public function toBase64(): string
    {
        $encoded = base64_encode($this->nonce . $this->ciphertext);

        return match ($this->format) {
            self::FORMAT_V1 => $encoded,
            self::FORMAT_V2 => self::PREFIX_V2 . $encoded,
            self::FORMAT_V3 => self::PREFIX_V3 . $encoded,
        };
    }

    /**
     * Deserialize a string produced by toBase64(), of any format.
     *
     * @throws InvalidArgumentException on malformed input, or on a "SDG<n>." prefix
     *                                  this version does not know
     */
    public static function fromBase64(string $encoded): self
    {
        if (str_starts_with($encoded, self::PREFIX_V3)) {
            return self::split(substr($encoded, strlen(self::PREFIX_V3)), self::FORMAT_V3);
        }
        if (str_starts_with($encoded, self::PREFIX_V2)) {
            return self::split(substr($encoded, strlen(self::PREFIX_V2)), self::FORMAT_V2);
        }
        if (preg_match('/^SDG(\d+)\./', $encoded, $m) === 1) {
            throw new InvalidArgumentException(
                "Blob format SDG{$m[1]} is unknown to this version of SelfDataGuard: "
                . 'it was written by a newer one. Upgrade the library to read it.'
            );
        }

        return self::split($encoded, self::FORMAT_V1);
    }

    private static function split(string $b64, int $format): self
    {
        $nonceLen = $format === self::FORMAT_V1 ? self::NONCE_LEN_V1 : self::NONCE_LEN_V2;
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
            nonce: substr($raw, 0, $nonceLen),
            format: $format
        );
    }
}
