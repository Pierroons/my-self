<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Fields;

use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Vault\UnlockedVault;

/**
 * Encrypt and decrypt individual database fields with a user's data_master_key.
 *
 * Protocol per whitepaper §2.2 step 6:
 *
 *     email_encrypted = AES-256-GCM(email, key=data_master_key, nonce=random_96)
 *
 * Each field gets its own random nonce. Output is a single base64 string
 * suitable for storage in a TEXT/VARCHAR column.
 *
 * The Additional Authenticated Data (AAD) is automatically built as
 * "userId|fieldName" to prevent two classes of attack:
 *
 *   1. Cross-field swap: Alice's encrypted email cannot be silently stored
 *      as her phone (or vice-versa) — the auth tag would fail.
 *   2. Cross-user swap : Bob cannot copy Alice's encrypted blob into his
 *      own row to claim her data — Bob's userId in AAD would mismatch.
 */
final class FieldCrypter
{
    private function __construct()
    {
    }

    /**
     * Encrypt a single field value. Returns a base64 string ready for SQL storage.
     */
    public static function encrypt(
        UnlockedVault $vault,
        string $fieldName,
        string $plaintext
    ): string {
        $blob = Primitives::aesGcmEncrypt(
            plaintext: $plaintext,
            key: $vault->getMasterKey(),
            aad: self::buildAad($vault->userId, $fieldName)
        );
        return $blob->toBase64();
    }

    /**
     * Decrypt a single field value previously encrypted by encrypt().
     *
     * @throws \RuntimeException if the auth tag fails (wrong key, swapped blob,
     *                           tampered ciphertext, mismatched fieldName/userId)
     */
    public static function decrypt(
        UnlockedVault $vault,
        string $fieldName,
        string $serialized
    ): string {
        $blob = EncryptedBlob::fromBase64($serialized);
        return Primitives::aesGcmDecrypt(
            $blob,
            $vault->getMasterKey(),
            aad: self::buildAad($vault->userId, $fieldName)
        );
    }

    /**
     * Encrypt a map of field => plaintext. Same vault for all fields.
     *
     * @param array<string, string> $fields
     * @return array<string, string> Same keys, encrypted base64 values.
     */
    public static function encryptBatch(UnlockedVault $vault, array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $value) {
            $out[$name] = self::encrypt($vault, $name, $value);
        }
        return $out;
    }

    /**
     * Decrypt a map of field => ciphertext. Same vault for all fields.
     *
     * @param array<string, string> $serialized
     * @return array<string, string>
     */
    public static function decryptBatch(UnlockedVault $vault, array $serialized): array
    {
        $out = [];
        foreach ($serialized as $name => $value) {
            $out[$name] = self::decrypt($vault, $name, $value);
        }
        return $out;
    }

    /**
     * Construct the AAD string. The pipe separator is safe since neither
     * userId nor fieldName should contain raw "|" by convention; even if they
     * did, GCM's auth tag still binds the exact bytes.
     */
    private static function buildAad(string $userId, string $fieldName): string
    {
        return $userId . '|' . $fieldName;
    }
}
