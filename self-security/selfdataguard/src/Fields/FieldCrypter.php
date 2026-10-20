<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Fields;

use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Escrow\EscrowVault;
use Pierroons\SelfDataGuard\Vault\UnlockedVault;
use Pierroons\SelfDataGuard\Crypto\Aad;

/**
 * Encrypt and decrypt individual database fields with a user's data_master_key.
 *
 * Protocol per whitepaper §2.2 step 6:
 *
 *     email_encrypted = XChaCha20-Poly1305(email, key=data_master_key, nonce=random_192)
 *
 * Each field gets its own random nonce. Output is a single ASCII string
 * (EncryptedBlob::toBase64()) suitable for storage in a TEXT/VARCHAR column.
 *
 * The Additional Authenticated Data (AAD) is built by aadFor() from the userId
 * and the field name (a v2 blob is read under the flat "userId|fieldName"), to
 * prevent two classes of attack:
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
     * Encrypt a single field value. Returns an ASCII string ready for SQL storage.
     */
    public static function encrypt(
        UnlockedVault $vault,
        string $fieldName,
        #[\SensitiveParameter] string $plaintext
    ): string {
        $blob = Primitives::encrypt(
            plaintext: $plaintext,
            key: $vault->getMasterKey(),
            aad: self::aadFor($vault->userId, $fieldName)
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
        return Primitives::decryptAcrossFormats(
            $blob,
            $vault->getMasterKey(),
            self::aadFor($vault->userId, $fieldName),
            self::legacyAad($vault->userId, $fieldName)
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
     * What a private field of this account is bound to, since 0.7.0.
     *
     * Public because it describes, it does not unlock: an AAD is not a secret.
     * A test that needs it — or an integrator auditing what a stored ciphertext
     * belongs to — references it here instead of copying the segments, so that
     * a change of domain cannot leave a copy behind that still looks right.
     */
    public static function aadFor(string $userId, string $fieldName): Aad
    {
        self::assertFieldNameAllowed($fieldName);

        return Aad::of('field', $userId, $fieldName);
    }

    /**
     * The flat AAD a field was sealed under before 0.7.0 — for reading those
     * blobs back, and nothing else.
     *
     * Its pipe separator is the reason the reserved-name check below still
     * earns its place: "alice" + "escrow" joined by a pipe is also what the
     * escrow user-wrap was sealed under, so a field named "escrow" shared its
     * AAD. Length-prefixed segments close that by construction, but only for
     * blobs written since 0.7.0.
     */
    private static function legacyAad(string $userId, string $fieldName): string
    {
        self::assertFieldNameAllowed($fieldName);

        return $userId . '|' . $fieldName;
    }

    /**
     * Refused on read as well as on write: a row planted in storage under this
     * name would otherwise decrypt the escrow user-wrap and hand its key to the
     * application.
     */
    private static function assertFieldNameAllowed(string $fieldName): void
    {
        if ($fieldName === EscrowVault::WRAP_AAD_TAG) {
            throw new InvalidArgumentException(sprintf(
                'Field name "%s" is reserved: it is the context of the escrow user-wrap.',
                $fieldName
            ));
        }
    }
}
