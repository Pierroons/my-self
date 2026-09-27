<?php

declare(strict_types=1);

/**
 * Sanity smoke test for the Phase 1 cryptographic primitives.
 *
 * Run:  php tests/sanity_primitives.php
 * Exit: 0 on success, non-zero on first failure.
 *
 * Not a replacement for proper PHPUnit tests — this is a fast bring-up check
 * to validate that sodium works as expected on the target machine, and that
 * this machine can still read every blob format the library has ever written.
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

$sodiumAes  = sodium_crypto_aead_aes256gcm_is_available();
$opensslAes = function_exists('openssl_get_cipher_methods')
    && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
echo '     legacy AES-256-GCM routes on this machine: libsodium '
    . ($sodiumAes ? 'yes' : 'no') . ', OpenSSL ' . ($opensslAes ? 'yes' : 'no') . "\n";
$sodiumAes || $opensslAes
    ? ok('blobs written before 0.4.0 (AES-256-GCM) are readable here')
    : ko('no route to read AES-256-GCM blobs', 'libsodium does not serve AES here and ext-openssl is missing');

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

section('deriveFromMemorized (Argon2id since 0.3.0) — shape and domain separation');

$mem = 'sunset-river-marble';
$ctx1 = bin2hex($salt) . '/dataguard';
$ctx2 = 'example.com/recover';

$hk1 = Primitives::deriveFromMemorized($mem, $ctx1);
$hk2 = Primitives::deriveFromMemorized($mem, $ctx1);
$hk3 = Primitives::deriveFromMemorized($mem, $ctx2);

strlen($hk1) === 32 ? ok('derived key is 32 bytes') : ko('derived key wrong length');
hash_equals($hk1, $hk2) ? ok('deterministic with same input') : ko('non-deterministic');
!hash_equals($hk1, $hk3) ? ok('contextual separation works') : ko('collision across contexts (CRITICAL)');

// -----------------------------------------------------------------------------

section('XChaCha20-Poly1305 (round trip + tamper detection)');

$plaintext = 'alice@example.com';
$key = Primitives::randomBytes(Primitives::KEY_LEN);
$aad  = 'user_id:42';

$blob = Primitives::encrypt($plaintext, $key, $aad);

$blob instanceof EncryptedBlob ? ok('encrypt returns EncryptedBlob') : ko('encrypt return type wrong');
strlen($blob->nonce) === 24 ? ok('nonce is 24 bytes (192 bits)') : ko('nonce wrong length', (string) strlen($blob->nonce));
!$blob->isLegacy() ? ok('encrypt writes the v2 format') : ko('encrypt wrote a legacy AES blob');

$decrypted = Primitives::decrypt($blob, $key, $aad);
$decrypted === $plaintext ? ok('round-trip identity') : ko('round-trip lost data', "got: {$decrypted}");

// Wrong key
try {
    $wrongKey = Primitives::randomBytes(32);
    Primitives::decrypt($blob, $wrongKey, $aad);
    ko('decrypt with wrong key should fail');
} catch (RuntimeException) {
    ok('decrypt with wrong key throws');
}

// Wrong AAD
try {
    Primitives::decrypt($blob, $key, 'user_id:43');
    ko('decrypt with tampered AAD should fail');
} catch (RuntimeException) {
    ok('decrypt with tampered AAD throws');
}

// Tampered ciphertext
try {
    $tamperedCiphertext = $blob->ciphertext;
    $tamperedCiphertext[0] = chr(ord($tamperedCiphertext[0]) ^ 0x01);
    $tampered = new EncryptedBlob(ciphertext: $tamperedCiphertext, nonce: $blob->nonce);
    Primitives::decrypt($tampered, $key, $aad);
    ko('decrypt with tampered ciphertext should fail');
} catch (RuntimeException) {
    ok('decrypt with tampered ciphertext throws');
}

// Published test vector: draft-irtf-cfrg-xchacha-03, appendix A.3.1. It pins the
// IETF variant with AAD — a wrong variant or a dropped AAD fails here.
$vKey   = hex2bin('808182838485868788898a8b8c8d8e8f909192939495969798999a9b9c9d9e9f');
$vNonce = hex2bin('404142434445464748494a4b4c4d4e4f5051525354555657');
$vAad   = hex2bin('50515253c0c1c2c3c4c5c6c7');
$vPlain = hex2bin(
    '4c616469657320616e642047656e746c656d656e206f662074686520636c6173'
    . '73206f66202739393a204966204920636f756c64206f6666657220796f75206f'
    . '6e6c79206f6e652074697020666f7220746865206675747572652c2073756e73'
    . '637265656e20776f756c642062652069742e'
);
$vCipher = hex2bin(
    'bd6d179d3e83d43b9576579493c0e939572a1700252bfaccbed2902c21396cbb'
    . '731c7f1b0b4aa6440bf3a82f4eda7e39ae64c6708c54c216cb96b72e1213b452'
    . '2f8c9ba40db5d945b11b69b982c1bb9e3f3fac2bc369488f76b2383565d3fff9'
    . '21f9664c97637da9768812f615c68b13b52e'
    . 'c0875924c1c7987947deafd8780acf49'
);
try {
    $vOut = Primitives::decrypt(new EncryptedBlob(ciphertext: $vCipher, nonce: $vNonce), $vKey, $vAad);
    $vOut === $vPlain
        ? ok('decrypt matches the IETF XChaCha20-Poly1305 test vector (A.3.1)')
        : ko('decrypt disagrees with the IETF test vector');
} catch (RuntimeException $e) {
    ko('decrypt rejects the IETF XChaCha20-Poly1305 test vector (A.3.1)', $e->getMessage());
}

// -----------------------------------------------------------------------------

section('EncryptedBlob serialization');

$b64 = $blob->toBase64();
str_starts_with($b64, EncryptedBlob::PREFIX_V2) ? ok('v2 blob serializes with the SDG2. prefix') : ko('v2 blob lost its prefix', substr($b64, 0, 8));
$restored = EncryptedBlob::fromBase64($b64);
$restored->ciphertext === $blob->ciphertext ? ok('base64 round-trip preserves ciphertext') : ko('base64 corrupts ciphertext');
$restored->nonce      === $blob->nonce      ? ok('base64 round-trip preserves nonce')      : ko('base64 corrupts nonce');

$decAfterB64 = Primitives::decrypt($restored, $key, $aad);
$decAfterB64 === $plaintext ? ok('decrypt after base64 round-trip works') : ko('decrypt after b64 broken');

try {
    EncryptedBlob::fromBase64('not-valid!!!---');
    ko('fromBase64 rejects malformed input');
} catch (InvalidArgumentException) {
    ok('fromBase64 rejects malformed input');
}

try {
    EncryptedBlob::fromBase64('SDG9.' . base64_encode(str_repeat("\0", 64)));
    ko('fromBase64 accepted an unknown format (SDG9.)');
} catch (InvalidArgumentException $e) {
    str_contains($e->getMessage(), 'newer')
        ? ok('an unknown SDG<n>. format is refused and named as written by a newer version')
        : ko('unknown format refused, but not named', $e->getMessage());
}

try {
    EncryptedBlob::fromBase64(EncryptedBlob::PREFIX_V2 . base64_encode(str_repeat("\0", 30)));
    ko('fromBase64 accepted a truncated v2 blob');
} catch (InvalidArgumentException) {
    ok('fromBase64 rejects a truncated v2 blob');
}

try {
    new EncryptedBlob(ciphertext: str_repeat("\0", 32), nonce: str_repeat("\0", 16));
    ko('EncryptedBlob accepted a 16-byte nonce');
} catch (InvalidArgumentException) {
    ok('EncryptedBlob refuses a nonce of neither format');
}

// -----------------------------------------------------------------------------

section('Legacy AES-256-GCM blobs (written before 0.4.0) stay readable');

// Frozen v1 blob: key 0x40..0x5f, nonce 0xa0..0xab, produced identically by
// libsodium and OpenSSL. If this line goes red, data already on disk is lost.
$fKey   = hex2bin('404142434445464748494a4b4c4d4e4f505152535455565758595a5b5c5d5e5f');
$frozen = 'oKGio6SlpqeoqaqrtuNvXIer+VUYAjGS7JJTmYIz6iLINLv0++1xrU3R5fr3';
try {
    $fBlob = EncryptedBlob::fromBase64($frozen);
    $fBlob->isLegacy() ? ok('an unprefixed blob is read as legacy AES') : ko('an unprefixed blob was not recognised as legacy');
    Primitives::decrypt($fBlob, $fKey, 'user_id:42') === 'alice@example.com'
        ? ok('the frozen AES blob still decrypts')
        : ko('the frozen AES blob decrypts to something else');
    $fBlob->toBase64() === $frozen
        ? ok('a legacy blob re-serializes byte for byte, without a prefix')
        : ko('re-serializing a legacy blob changed it');
} catch (Throwable $e) {
    ko('the frozen AES blob no longer decrypts', $e->getMessage());
}

// Two implementations check each other: one writes, the other reads.
$lNonce = random_bytes(12);
if ($sodiumAes && $opensslAes) {
    $bySodium = new EncryptedBlob(
        ciphertext: sodium_crypto_aead_aes256gcm_encrypt($plaintext, $aad, $lNonce, $key),
        nonce: $lNonce
    );
    Primitives::legacyDecryptOpenssl($bySodium, $key, $aad) === $plaintext
        ? ok('OpenSSL reads a blob written by libsodium')
        : ko('OpenSSL cannot read a libsodium blob');
    $ossl = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $lNonce, $tag, $aad, 16);
    Primitives::legacyDecryptSodium(new EncryptedBlob(ciphertext: $ossl . $tag, nonce: $lNonce), $key, $aad) === $plaintext
        ? ok('libsodium reads a blob written by OpenSSL')
        : ko('libsodium cannot read an OpenSSL blob');
} else {
    echo "     (cross-check skipped: it needs both AES routes on this machine)\n";
}

foreach (['sodium' => $sodiumAes, 'openssl' => $opensslAes] as $route => $available) {
    if (!$available) {
        continue;
    }
    $read = $route === 'sodium' ? 'legacyDecryptSodium' : 'legacyDecryptOpenssl';
    try {
        Primitives::$read($fBlob, $fKey, 'user_id:43');
        ko("legacy route {$route} accepted a tampered AAD");
    } catch (RuntimeException) {
        ok("legacy route {$route} refuses a tampered AAD");
    }
}

// -----------------------------------------------------------------------------

section('Secrets stay out of stack traces');

if (PHP_VERSION_ID < 80200) {
    echo "     (skipped: #[\\SensitiveParameter] takes effect from PHP 8.2)\n";
} else {
    // A production php.ini strips every argument already; the check needs them kept,
    // so that only the attribute stands between a secret and the trace.
    ini_set('zend.exception_ignore_args', '0');
    try {
        Primitives::encrypt('the-plaintext-that-must-not-leak', str_repeat('k', 31));
        ko('encrypt accepted a 31-byte key');
    } catch (InvalidArgumentException $e) {
        $trace = print_r($e->getTrace(), true);
        !str_contains($trace, 'the-plaintext-that-must-not-leak') && !str_contains($trace, str_repeat('k', 31))
            ? ok('neither the plaintext nor the key appears in the trace')
            : ko('a secret argument appears in the stack trace');
    }
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

section('Memorized derivation is memory-hard (the whole point of 0.3.0)');

// Exact witness: the hardened derivation and the legacy one must not agree. If
// someone puts the HMAC back, these two become equal and this line goes red.
$ctx  = str_repeat("\x11", 16) . '/dataguard';
$hard = Primitives::deriveFromMemorized('a-drawn-passphrase-of-some-length', $ctx);
$weak = Primitives::deriveFromMemorizedLegacyV1('a-drawn-passphrase-of-some-length', $ctx);
$hard !== $weak
    ? ok('deriveFromMemorized no longer equals the pre-0.3.0 HMAC')
    : ko('deriveFromMemorized IS the legacy HMAC — the hardening is gone');
strlen($hard) === Primitives::KEY_LEN
    ? ok('hardened derivation still returns ' . Primitives::KEY_LEN . ' bytes')
    : ko('hardened derivation wrong length', (string) strlen($hard));

// Second witness, on cost rather than on value. The bound is deliberately loose
// — a hundredth of what Argon2id costs on the slowest machine we deploy on, and
// still four orders of magnitude above a bare HMAC. It measures the presence of
// a cost, not its exact size.
$t0 = hrtime(true);
Primitives::deriveFromMemorized('another-drawn-passphrase', $ctx);
$ms = (hrtime(true) - $t0) / 1e6;
$ms > 2.0
    ? ok(sprintf('one attempt costs %.1f ms — a cost exists', $ms))
    : ko(sprintf('one attempt costs %.4f ms — that is a bare hash, not a KDF', $ms));

// Same secret, different context → different key. Domain separation survived the
// move from HMAC to Argon2id, where the context became the salt.
Primitives::deriveFromMemorized('same-secret-both-times', $ctx)
  !== Primitives::deriveFromMemorized('same-secret-both-times', $ctx . '-other')
    ? ok('context still separates domains')
    : ko('two contexts give the same key — domain separation is gone');

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Phase 1 Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
