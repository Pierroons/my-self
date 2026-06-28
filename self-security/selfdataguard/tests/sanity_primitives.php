<?php

declare(strict_types=1);

/**
 * Sanity smoke test for the Phase 1 cryptographic primitives.
 *
 * Run:  php tests/sanity_primitives.php
 * Exit: 0 on success, non-zero on first failure.
 *
 * Not a replacement for proper PHPUnit tests — this is a fast bring-up check
 * to validate that sodium + AES-NI work as expected on the target machine
 * before we build higher layers on top of these primitives.
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\Primitives;

$failures = 0;
$passes = 0;

function ok(string $label): void
{
    global $passes;
    $passes++;
    echo "  ✅ {$label}\n";
}

function ko(string $label, string $detail = ''): void
{
    global $failures;
    $failures++;
    echo "  ❌ {$label}";
    if ($detail !== '') {
        echo " — {$detail}";
    }
    echo "\n";
}

function section(string $title): void
{
    echo "\n→ {$title}\n";
}

// -----------------------------------------------------------------------------

section('Environment');

if (!extension_loaded('sodium')) {
    ko('sodium loaded'); exit(1);
}
ok('sodium loaded');

if (!sodium_crypto_aead_aes256gcm_is_available()) {
    ko('AES-256-GCM available (AES-NI)'); exit(1);
}
ok('AES-256-GCM available (AES-NI)');

ok('PHP version: ' . PHP_VERSION);

// -----------------------------------------------------------------------------

section('Random');

$r1 = Primitives::randomBytes(32);
$r2 = Primitives::randomBytes(32);
strlen($r1) === 32 ? ok('randomBytes returns requested length') : ko('randomBytes length mismatch');
$r1 !== $r2 ? ok('randomBytes produces different output each call') : ko('randomBytes returned identical values');

// -----------------------------------------------------------------------------

section('Argon2id (deriveFromPassword)');

$salt = Primitives::randomBytes(Primitives::SALT_LEN);
$key1 = Primitives::deriveFromPassword('correct horse battery staple', $salt);
$key2 = Primitives::deriveFromPassword('correct horse battery staple', $salt);
strlen($key1) === 32 ? ok('Argon2id output is 32 bytes') : ko('Argon2id wrong length');
hash_equals($key1, $key2) ? ok('Argon2id deterministic with same input') : ko('Argon2id non-deterministic with same input');

$key3 = Primitives::deriveFromPassword('wrong password', $salt);
!hash_equals($key1, $key3) ? ok('Argon2id different output with different password') : ko('Argon2id collision on different passwords');

$salt2 = Primitives::randomBytes(Primitives::SALT_LEN);
$key4 = Primitives::deriveFromPassword('correct horse battery staple', $salt2);
!hash_equals($key1, $key4) ? ok('Argon2id different output with different salt') : ko('Argon2id collision on different salts');

try {
    Primitives::deriveFromPassword('', $salt);
    ko('Argon2id rejects empty password');
} catch (InvalidArgumentException) {
    ok('Argon2id rejects empty password');
}

try {
    Primitives::deriveFromPassword('pwd', 'short');
    ko('Argon2id rejects short salt');
} catch (InvalidArgumentException) {
    ok('Argon2id rejects short salt');
}

// -----------------------------------------------------------------------------

section('HMAC-SHA256 (deriveFromMemorized)');

$mem = 'sunset-river-marble';
$ctx1 = bin2hex($salt) . '/dataguard';
$ctx2 = 'example.com/recover';

$hk1 = Primitives::deriveFromMemorized($mem, $ctx1);
$hk2 = Primitives::deriveFromMemorized($mem, $ctx1);
$hk3 = Primitives::deriveFromMemorized($mem, $ctx2);

strlen($hk1) === 32 ? ok('HMAC output is 32 bytes') : ko('HMAC wrong length');
hash_equals($hk1, $hk2) ? ok('HMAC deterministic with same input') : ko('HMAC non-deterministic');
!hash_equals($hk1, $hk3) ? ok('HMAC contextual separation works') : ko('HMAC collision across contexts (CRITICAL)');

// -----------------------------------------------------------------------------

section('AES-256-GCM (round trip + tamper detection)');

$plaintext = 'pierre@example.com';
$key = Primitives::randomBytes(Primitives::KEY_LEN);
$aad  = 'user_id:42';

$blob = Primitives::aesGcmEncrypt($plaintext, $key, $aad);

$blob instanceof EncryptedBlob ? ok('encrypt returns EncryptedBlob') : ko('encrypt return type wrong');
strlen($blob->nonce) === 12 ? ok('nonce is 12 bytes (96 bits)') : ko('nonce wrong length');

$decrypted = Primitives::aesGcmDecrypt($blob, $key, $aad);
$decrypted === $plaintext ? ok('round-trip identity') : ko('round-trip lost data', "got: {$decrypted}");

// Wrong key
try {
    $wrongKey = Primitives::randomBytes(32);
    Primitives::aesGcmDecrypt($blob, $wrongKey, $aad);
    ko('decrypt with wrong key should fail');
} catch (RuntimeException) {
    ok('decrypt with wrong key throws');
}

// Wrong AAD
try {
    Primitives::aesGcmDecrypt($blob, $key, 'user_id:43');
    ko('decrypt with tampered AAD should fail');
} catch (RuntimeException) {
    ok('decrypt with tampered AAD throws');
}

// Tampered ciphertext
try {
    $tamperedCiphertext = $blob->ciphertext;
    $tamperedCiphertext[0] = chr(ord($tamperedCiphertext[0]) ^ 0x01);
    $tampered = new EncryptedBlob(ciphertext: $tamperedCiphertext, nonce: $blob->nonce);
    Primitives::aesGcmDecrypt($tampered, $key, $aad);
    ko('decrypt with tampered ciphertext should fail');
} catch (RuntimeException) {
    ok('decrypt with tampered ciphertext throws');
}

// -----------------------------------------------------------------------------

section('EncryptedBlob serialization');

$b64 = $blob->toBase64();
$restored = EncryptedBlob::fromBase64($b64);
$restored->ciphertext === $blob->ciphertext ? ok('base64 round-trip preserves ciphertext') : ko('base64 corrupts ciphertext');
$restored->nonce      === $blob->nonce      ? ok('base64 round-trip preserves nonce')      : ko('base64 corrupts nonce');

$decAfterB64 = Primitives::aesGcmDecrypt($restored, $key, $aad);
$decAfterB64 === $plaintext ? ok('decrypt after base64 round-trip works') : ko('decrypt after b64 broken');

try {
    EncryptedBlob::fromBase64('not-valid!!!---');
    ko('fromBase64 rejects malformed input');
} catch (InvalidArgumentException) {
    ok('fromBase64 rejects malformed input');
}

// -----------------------------------------------------------------------------

section('Constant-time compare');

$a = Primitives::randomBytes(32);
$b = $a;
$c = Primitives::randomBytes(32);

Primitives::secureCompare($a, $b) ? ok('secureCompare equal strings → true') : ko('secureCompare equal → false');
!Primitives::secureCompare($a, $c) ? ok('secureCompare different strings → false') : ko('secureCompare different → true');

// -----------------------------------------------------------------------------

section('Memory hygiene (zeroize)');

$secret = 'super-secret-master-key';
Primitives::zeroize($secret);
$secret === '' ? ok('zeroize wipes string variable') : ko('zeroize did not wipe', "got: '{$secret}'");

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Phase 1 Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
