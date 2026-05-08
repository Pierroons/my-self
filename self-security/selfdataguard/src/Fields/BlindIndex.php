<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Fields;

use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\Primitives;

/**
 * Searchable encryption helper — produces a deterministic HMAC over a value
 * so that the application can perform `WHERE field_hash = ?` lookups without
 * decrypting any row.
 *
 * Per whitepaper §5: HMAC-SHA256(value, server_blind_key) → base64 hash for
 * SQL storage / equality matching.
 *
 *   - The blindKey is a SERVER-SIDE secret, NOT user-derived.
 *   - It must NOT be confused with the per-user data_master_key.
 *   - Two users with the same email will share the same blind index value
 *     (necessary for unique-email constraints, but it leaks duplicate
 *     equality across rows — this is an accepted, documented trade-off).
 *   - Range queries, sort order, and substring search are NOT supported.
 *     Only equality lookups.
 *
 * Recommended fieldKey separation:
 *
 *     emailKey  = HMAC(blindKey, "email")
 *     phoneKey  = HMAC(blindKey, "phone")
 *
 * Distinct fields therefore have orthogonal key spaces — one rainbow table
 * per field, not one across the whole application.
 */
final class BlindIndex
{
    private function __construct()
    {
    }

    /**
     * Compute a deterministic blind index for a field value.
     *
     * @param string $value     The plaintext to index (will NOT be stored)
     * @param string $blindKey  Server-side secret of length ≥32 bytes
     * @param string $fieldName For per-field key separation (e.g. "email", "phone")
     * @return string Base64 of the 32-byte HMAC, suitable for SQL storage and
     *                indexing (idempotent per (value, blindKey, fieldName)).
     */
    public static function compute(
        string $value,
        string $blindKey,
        string $fieldName
    ): string {
        if ($blindKey === '' || strlen($blindKey) < 32) {
            throw new InvalidArgumentException(
                'blindKey must be ≥32 bytes of high-entropy server-side secret'
            );
        }
        if ($fieldName === '') {
            throw new InvalidArgumentException('fieldName must not be empty');
        }

        $derivedFieldKey = hash_hmac(
            Primitives::HMAC_ALGO,
            $fieldName,
            $blindKey,
            true
        );
        $hash = hash_hmac(Primitives::HMAC_ALGO, $value, $derivedFieldKey, true);

        Primitives::zeroize($derivedFieldKey);

        return base64_encode($hash);
    }

    /**
     * Constant-time comparison of two blind index values, e.g. when
     * cross-checking a recomputed lookup hash against a stored row.
     */
    public static function equals(string $a, string $b): bool
    {
        return Primitives::secureCompare($a, $b);
    }
}
