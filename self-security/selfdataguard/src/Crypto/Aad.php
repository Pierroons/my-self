<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Crypto;

use InvalidArgumentException;

/**
 * What a ciphertext is bound to — the additional authenticated data, as a value.
 *
 * Why an object and not an optional string. An optional string AAD that
 * defaults to null has three defects, each of which this class rules out:
 *
 *   1. the safe call is the one you have to remember: encrypt($data, $key)
 *      seals an unbound ciphertext, with no warning;
 *   2. null and "" encode to the same bytes, so nothing — the blob, the
 *      database, a decryption — tells an unbound ciphertext from one bound to
 *      an empty AAD;
 *   3. a flat concatenation is ambiguous: joined by a pipe, (alice,
 *      escrow|email) and (alice, escrow, email) give the same bytes, and the tag
 *      binds bytes, not segments.
 *
 * Hence: the binding is a required argument, none() names its absence, and
 * segments are length-prefixed, so no two segment lists encode alike.
 *
 * Immutable value object.
 */
final class Aad
{
    /** Domain tag. Keeps a v3 AAD from ever colliding with a v2 one. */
    private const DOMAIN = "SDG3\x00";

    /** @var list<string> */
    private readonly array $segments;

    /**
     * @param list<string> $segments
     * @param ?string      $raw      set only by legacy(): the bytes to use as-is
     */
    private function __construct(
        array $segments,
        private readonly bool $bound,
        private readonly ?string $raw = null
    ) {
        $this->segments = $segments;
    }

    /**
     * Bind a ciphertext to its context. Each segment is a part of the answer to
     * "where does this ciphertext belong" — typically the account, the kind of
     * record, and the field name.
     *
     * Pass them as separate segments rather than joined: that is the whole point
     * of the length prefix.
     */
    public static function of(string ...$segments): self
    {
        if ($segments === []) {
            throw new InvalidArgumentException(
                'Aad::of() needs at least one segment. For a ciphertext that is '
                . 'deliberately bound to nothing, call Aad::none() — which says so.'
            );
        }

        return new self(array_values($segments), bound: true);
    }

    /**
     * A ciphertext deliberately bound to nothing.
     *
     * It still differs from every bound AAD, including one built from empty
     * segments: "not bound" is itself part of what the tag authenticates. So a
     * blob written with none() cannot be read as a bound one, nor the reverse —
     * which is what makes the absence of a binding visible instead of silent.
     *
     * Reach for it only where there is genuinely no context to bind: a
     * self-contained file, a one-shot transport envelope. If an account, a
     * field or a record is involved, that IS the context.
     */
    public static function none(): self
    {
        return new self([], bound: false);
    }

    /**
     * The exact bytes a v1 or v2 ciphertext was sealed under — a flat string,
     * with no domain tag and no length prefixes — for reading those blobs back.
     * It is the only way to produce them, and encrypt() refuses it.
     */
    public static function legacy(string $rawAad): self
    {
        return new self([], bound: $rawAad !== '', raw: $rawAad);
    }

    /** True for an AAD that reproduces a pre-0.7.0 binding; it may only decrypt. */
    public function isLegacy(): bool
    {
        return $this->raw !== null;
    }

    public function isBound(): bool
    {
        return $this->bound;
    }

    /**
     * The bytes the AEAD tag authenticates.
     *
     * Format: domain tag, one byte saying bound or not, then each segment
     * preceded by its length on 4 bytes, big-endian. Unambiguous by
     * construction: the length prefixes mean no two segment lists encode to the
     * same bytes.
     */
    public function encode(): string
    {
        if ($this->raw !== null) {
            return $this->raw;
        }
        $out = self::DOMAIN . ($this->bound ? 'b' : 'n');
        foreach ($this->segments as $segment) {
            $out .= pack('N', strlen($segment)) . $segment;
        }

        return $out;
    }

    /** Segments, for an error message or a test — never for a security decision. */
    public function __debugInfo(): array
    {
        return ['bound' => $this->bound, 'segments' => $this->segments];
    }
}
