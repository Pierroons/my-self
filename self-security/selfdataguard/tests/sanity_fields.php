<?php

declare(strict_types=1);

/**
 * Sanity smoke test for Phase 3 — FieldCrypter + BlindIndex.
 *
 * Run:  php tests/sanity_fields.php
 * Exit: 0 on success, non-zero on first failure.
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Fields\BlindIndex;
use Pierroons\SelfDataGuard\Fields\FieldCrypter;
use Pierroons\SelfDataGuard\Vault\UserVault;

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

section('Setup — register a user and unlock');

$vault = new UserVault();
$result = $vault->register(
    userId: 'user-cosmo',
    password: 'correct horse battery staple',
    memorized: 'sunset-river-marble'
);
$record = $result['record'];
$unlocked = $result['unlocked'];

ok('user registered and unlocked');

// -----------------------------------------------------------------------------

section('FieldCrypter — round trip on individual fields');

$emailCipher = FieldCrypter::encrypt($unlocked, 'email', 'alice@example.com');
$phoneCipher = FieldCrypter::encrypt($unlocked, 'phone', '+33612345678');

is_string($emailCipher) ? ok('encrypt returns string') : ko('encrypt return type');
$emailCipher !== 'alice@example.com' ? ok('ciphertext is not plaintext') : ko('encrypt = plaintext (CRITICAL)');

$emailPlain = FieldCrypter::decrypt($unlocked, 'email', $emailCipher);
$emailPlain === 'alice@example.com' ? ok('email round-trip identity') : ko('email round-trip failed');

$phonePlain = FieldCrypter::decrypt($unlocked, 'phone', $phoneCipher);
$phonePlain === '+33612345678' ? ok('phone round-trip identity') : ko('phone round-trip failed');

// Two encryptions of the same value produce different ciphertexts (random nonce)
$emailCipher2 = FieldCrypter::encrypt($unlocked, 'email', 'alice@example.com');
$emailCipher !== $emailCipher2 ? ok('same plaintext → different ciphertext (nonce randomness)') : ko('nonce reuse detected (CRITICAL)');

// -----------------------------------------------------------------------------

section('FieldCrypter — AAD binding (anti cross-field swap)');

// Try to decrypt the email blob as if it were the phone field
try {
    FieldCrypter::decrypt($unlocked, 'phone', $emailCipher);
    ko('cross-field swap accepted (CRITICAL)');
} catch (RuntimeException) {
    ok('cross-field swap rejected (email blob ≠ phone field)');
}

// -----------------------------------------------------------------------------

section('FieldCrypter — anti cross-user swap');

$bobResult = $vault->register(userId: 'user-bob', password: 'bob-password');
$bobUnlocked = $bobResult['unlocked'];

// Bob tries to read Cosmo's email blob using his own vault
try {
    FieldCrypter::decrypt($bobUnlocked, 'email', $emailCipher);
    ko('cross-user swap accepted (CRITICAL — wrong user can read another user\'s blob)');
} catch (RuntimeException) {
    ok('cross-user swap rejected (different master key + different AAD userId)');
}

// -----------------------------------------------------------------------------

section('FieldCrypter — batch operations');

$plaintextFields = [
    'email'   => 'alice@example.com',
    'phone'   => '+33612345678',
    'address' => '12 rue des Champs, 33220 Sainte-Foy',
    'iban'    => 'FR7612345678901234567890123',
];

$encrypted = FieldCrypter::encryptBatch($unlocked, $plaintextFields);

count($encrypted) === count($plaintextFields) ? ok('encryptBatch preserves cardinality') : ko('encryptBatch lost fields');
array_keys($encrypted) === array_keys($plaintextFields) ? ok('encryptBatch preserves keys') : ko('encryptBatch reordered keys');

$allDifferent = true;
foreach ($plaintextFields as $name => $value) {
    if ($encrypted[$name] === $value) {
        $allDifferent = false;
        break;
    }
}
$allDifferent ? ok('all batch ciphertexts differ from plaintexts') : ko('a batch ciphertext = plaintext');

$decrypted = FieldCrypter::decryptBatch($unlocked, $encrypted);
$decrypted === $plaintextFields ? ok('batch round-trip preserves all fields') : ko('batch round-trip lost data');

// -----------------------------------------------------------------------------

section('FieldCrypter — tamper detection on stored blob');

// Flip a bit in the stored ciphertext and verify decryption fails
$tamperedRaw = base64_decode($emailCipher);
$tamperedRaw[20] = chr(ord($tamperedRaw[20]) ^ 0x01);
$tamperedB64 = base64_encode($tamperedRaw);

try {
    FieldCrypter::decrypt($unlocked, 'email', $tamperedB64);
    ko('tampered ciphertext accepted (CRITICAL)');
} catch (RuntimeException) {
    ok('tampered ciphertext rejected (auth tag mismatch)');
}

// -----------------------------------------------------------------------------

section('BlindIndex — deterministic equality lookup');

$blindKey = Primitives::randomBytes(32);

$idx1 = BlindIndex::compute('alice@example.com', $blindKey, 'email');
$idx2 = BlindIndex::compute('alice@example.com', $blindKey, 'email');
$idx3 = BlindIndex::compute('different@example.com', $blindKey, 'email');

is_string($idx1) ? ok('compute returns string') : ko('compute wrong return type');
$idx1 === $idx2 ? ok('same value → same index (deterministic)') : ko('blind index non-deterministic (BREAKS lookup)');
$idx1 !== $idx3 ? ok('different value → different index') : ko('blind index collision');

BlindIndex::equals($idx1, $idx2) ? ok('equals matches identical indexes') : ko('equals false negative');
!BlindIndex::equals($idx1, $idx3) ? ok('equals rejects different indexes') : ko('equals false positive');

// -----------------------------------------------------------------------------

section('BlindIndex — per-field key separation');

$emailIdx = BlindIndex::compute('alice@example.com', $blindKey, 'email');
$phoneIdx = BlindIndex::compute('alice@example.com', $blindKey, 'phone');

$emailIdx !== $phoneIdx
    ? ok('same value, different field → different index (key separation works)')
    : ko('field-name separation broken (CRITICAL — rainbow table cross-fields)');

// -----------------------------------------------------------------------------

section('BlindIndex — input validation');

try {
    BlindIndex::compute('value', 'short', 'email');
    ko('compute accepts short blindKey');
} catch (InvalidArgumentException) {
    ok('compute rejects short blindKey');
}

try {
    BlindIndex::compute('value', $blindKey, '');
    ko('compute accepts empty fieldName');
} catch (InvalidArgumentException) {
    ok('compute rejects empty fieldName');
}

// -----------------------------------------------------------------------------

section('BlindIndex — different blindKeys produce different indexes');

$blindKey2 = Primitives::randomBytes(32);
$idxA = BlindIndex::compute('alice@example.com', $blindKey, 'email');
$idxB = BlindIndex::compute('alice@example.com', $blindKey2, 'email');

$idxA !== $idxB
    ? ok('different blindKey → different index (key rotation safe)')
    : ko('blindKey has no effect (CRITICAL)');

// -----------------------------------------------------------------------------

section('Realistic e-commerce scenario — encrypted DB row + blind index lookup');

// Simulate a `users` table row written for cosmo, then a "find by email" query
$row = [
    'user_id'       => 'user-cosmo',
    'email_cipher'  => FieldCrypter::encrypt($unlocked, 'email', 'alice@example.com'),
    'email_index'   => BlindIndex::compute('alice@example.com', $blindKey, 'email'),
    'phone_cipher'  => FieldCrypter::encrypt($unlocked, 'phone', '+33612345678'),
    'iban_cipher'   => FieldCrypter::encrypt($unlocked, 'iban', 'FR7612345678901234567890123'),
];

// Simulated lookup: does this email exist in the DB without decrypting any row?
$lookupIndex = BlindIndex::compute('alice@example.com', $blindKey, 'email');
$lookupIndex === $row['email_index']
    ? ok('blind-index lookup finds the row (without decrypting it)')
    : ko('blind-index lookup failed');

// And the negative case
$missIndex = BlindIndex::compute('not.in.db@example.com', $blindKey, 'email');
$missIndex !== $row['email_index']
    ? ok('blind-index lookup misses non-existing email')
    : ko('blind-index false hit (CRITICAL)');

// Once we've found the row, we can decrypt it
$cosmoEmail = FieldCrypter::decrypt($unlocked, 'email', $row['email_cipher']);
$cosmoEmail === 'alice@example.com' ? ok('after lookup, decrypt the row contents') : ko('decrypt lookup row');

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Phase 3 Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
