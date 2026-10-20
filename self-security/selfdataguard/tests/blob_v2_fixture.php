<?php

declare(strict_types=1);

/**
 * A blob exactly as encrypt() wrote it before 0.7.0 — format v2, under a flat
 * string AAD.
 *
 * The suites that check backward compatibility need a blob that predates the
 * format change. encrypt() cannot produce one, by design; and a test of
 * the legacy read path fed a v3 blob would exercise a blob that never existed
 * on anyone's disk. This file is the one definition of "a v2 blob", so that the
 * suites cannot drift apart on what they call legacy.
 *
 * Load it after src/autoload.php.
 */

use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;

function blobV2(
    #[\SensitiveParameter] string $plaintext,
    #[\SensitiveParameter] string $key,
    string $aad
): EncryptedBlob {
    $nonce = random_bytes(EncryptedBlob::NONCE_LEN_V2);

    return new EncryptedBlob(
        ciphertext: sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key),
        nonce: $nonce,
        format: EncryptedBlob::FORMAT_V2
    );
}
