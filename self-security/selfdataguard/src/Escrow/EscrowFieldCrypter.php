<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\Primitives;

/**
 * Encrypt/decrypt escrow fields with the escrow_key (mirror of FieldCrypter,
 * keyed by the escrow sub-key instead of the vault master key).
 *
 * AAD = "userId|escrow|fieldName" — same anti-swap guarantees as the main
 * vault (cross-field and cross-user immunity), plus the "|escrow|" marker keeps
 * escrow ciphertexts non-interchangeable with private-zone ciphertexts even for
 * the same field name.
 */
final class EscrowFieldCrypter
{
    private function __construct()
    {
    }

    public static function encrypt(UnlockedEscrow $escrow, string $fieldName, string $plaintext): string
    {
        $blob = Primitives::aesGcmEncrypt(
            plaintext: $plaintext,
            key: $escrow->getEscrowKey(),
            aad: self::buildAad($escrow->userId, $fieldName)
        );
        return $blob->toBase64();
    }

    public static function decrypt(UnlockedEscrow $escrow, string $fieldName, string $serialized): string
    {
        $blob = EncryptedBlob::fromBase64($serialized);
        return Primitives::aesGcmDecrypt(
            $blob,
            $escrow->getEscrowKey(),
            aad: self::buildAad($escrow->userId, $fieldName)
        );
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

    private static function buildAad(string $userId, string $fieldName): string
    {
        return $userId . '|escrow|' . $fieldName;
    }
}
