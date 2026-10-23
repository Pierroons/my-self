<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\Aad;

/**
 * Encrypt/decrypt escrow fields with the escrow_key (mirror of FieldCrypter,
 * keyed by the escrow sub-key instead of the vault master key).
 *
 * AAD = aadFor(userId, fieldName), in its own "escrow-field" domain (a v2 blob
 * is read under the flat "userId|escrow|fieldName") — same anti-swap guarantees
 * as the main vault (cross-field and cross-user immunity). Escrow and private
 * ciphertexts are sealed under different keys, so they do not open one another
 * even for the same field name.
 */
final class EscrowFieldCrypter
{
    private function __construct()
    {
    }

    public static function encrypt(UnlockedEscrow $escrow, string $fieldName, #[\SensitiveParameter] string $plaintext): string
    {
        return $escrow->encryptField($fieldName, $plaintext)->toBase64();
    }

    public static function decrypt(UnlockedEscrow $escrow, string $fieldName, string $serialized): string
    {
        return $escrow->decryptField($fieldName, EncryptedBlob::fromBase64($serialized));
    }

    /**
     * @param array<string, string> $fields field_name => plaintext
     * @return array<string, string> field_name => ciphertext (base64)
     */
    public static function encryptBatch(UnlockedEscrow $escrow, array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $value) {
            $out[$name] = self::encrypt($escrow, $name, $value);
        }
        return $out;
    }

    /**
     * @param array<string, string> $serialized field_name => ciphertext (base64)
     * @return array<string, string> field_name => plaintext
     */
    public static function decryptBatch(UnlockedEscrow $escrow, array $serialized): array
    {
        $out = [];
        foreach ($serialized as $name => $value) {
            $out[$name] = self::decrypt($escrow, $name, $value);
        }
        return $out;
    }

    /**
     * What an escrow field of this account is bound to, since 0.7.0. Public for
     * the reason FieldCrypter::aadFor() gives.
     */
    public static function aadFor(string $userId, string $fieldName): Aad
    {
        return Aad::of('escrow-field', $userId, $fieldName);
    }

    /** The flat AAD used before 0.7.0 — for reading those blobs back. */
    public static function legacyAadFor(string $userId, string $fieldName): string
    {
        return $userId . '|escrow|' . $fieldName;
    }
}
