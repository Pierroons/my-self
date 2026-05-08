<?php

declare(strict_types=1);

/**
 * Sanity smoke test for Phase 2 — UserVault key-wrapping flow.
 *
 * Run:  php tests/sanity_vault.php
 * Exit: 0 on success, non-zero on first failure.
 *
 * Validates the full register → unlock → re-seal pipeline against the
 * whitepaper §2.2 architecture.
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Vault\UserVault;
use Pierroons\SelfDataGuard\Vault\VaultRecord;
use Pierroons\SelfDataGuard\Vault\UnlockedVault;

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

section('Register (with memorized)');

$vault = new UserVault();
$result = $vault->register(
    userId: 'user-42',
    password: 'correct horse battery staple',
    memorized: 'sunset-river-marble'
);

$result['record'] instanceof VaultRecord ? ok('register returns VaultRecord') : ko('register no record');
$result['unlocked'] instanceof UnlockedVault ? ok('register returns UnlockedVault') : ko('register no unlocked');

$record = $result['record'];
$unlocked = $result['unlocked'];

$record->userId === 'user-42' ? ok('record.userId correct') : ko('record.userId wrong');
strlen($record->userSalt) === 16 ? ok('record.userSalt is 16 bytes') : ko('record.userSalt wrong length');
$record->wrapPwd !== null ? ok('record.wrapPwd present') : ko('record.wrapPwd missing');
$record->wrapRecov !== null ? ok('record.wrapRecov present') : ko('record.wrapRecov missing');
$record->wrapAdmin === null ? ok('record.wrapAdmin null (Hybrid mode v0.2.0+)') : ko('wrapAdmin should be null');
$record->hasMemorizedRecovery() ? ok('hasMemorizedRecovery() true') : ko('hasMemorizedRecovery should be true');

$masterKey1 = $unlocked->getMasterKey();
strlen($masterKey1) === 32 ? ok('UnlockedVault master key is 32 bytes') : ko('master key wrong length');
!$unlocked->isLocked() ? ok('UnlockedVault initially unlocked') : ko('UnlockedVault locked at creation');

// -----------------------------------------------------------------------------

section('Unlock with password');

$unlocked2 = $vault->unlockWithPassword($record, 'correct horse battery staple');
$masterKey2 = $unlocked2->getMasterKey();
hash_equals($masterKey1, $masterKey2)
    ? ok('unlockWithPassword recovers SAME master key')
    : ko('unlockWithPassword key mismatch (CRITICAL)');

// Wrong password
try {
    $vault->unlockWithPassword($record, 'wrong password');
    ko('unlockWithPassword wrong → should throw');
} catch (RuntimeException) {
    ok('unlockWithPassword wrong → throws RuntimeException');
}

// Empty password
try {
    $vault->unlockWithPassword($record, '');
    ko('unlockWithPassword empty → should throw');
} catch (InvalidArgumentException) {
    ok('unlockWithPassword empty → throws InvalidArgumentException');
}

// -----------------------------------------------------------------------------

section('Unlock with memorized');

$unlocked3 = $vault->unlockWithMemorized($record, 'sunset-river-marble');
$masterKey3 = $unlocked3->getMasterKey();
hash_equals($masterKey1, $masterKey3)
    ? ok('unlockWithMemorized recovers SAME master key (cross-factor)')
    : ko('unlockWithMemorized key mismatch (CRITICAL)');

// Wrong memorized
try {
    $vault->unlockWithMemorized($record, 'wrong-secret');
    ko('unlockWithMemorized wrong → should throw');
} catch (RuntimeException) {
    ok('unlockWithMemorized wrong → throws');
}

// -----------------------------------------------------------------------------

section('Register WITHOUT memorized (single-factor)');

$soloResult = $vault->register(userId: 'user-solo', password: 'pwd-only-no-memorized');
$soloRecord = $soloResult['record'];

$soloRecord->wrapRecov === null ? ok('solo wrapRecov null') : ko('solo wrapRecov should be null');
!$soloRecord->hasMemorizedRecovery() ? ok('solo hasMemorizedRecovery() false') : ko('solo recovery flag wrong');

try {
    $vault->unlockWithMemorized($soloRecord, 'whatever');
    ko('unlockWithMemorized on solo vault → should throw');
} catch (RuntimeException) {
    ok('unlockWithMemorized on solo vault → throws');
}

// -----------------------------------------------------------------------------

section('AAD binding (cross-user wrap immunity)');

// Tamper with the userId AAD — try to use Alice's wrap as if it were Bob's
$alice = $vault->register(userId: 'alice', password: 'alice-pwd-1234');
$bobFake = new VaultRecord(
    userId:    'bob',                       // ← changed AAD
    userSalt:  $alice['record']->userSalt,
    wrapPwd:   $alice['record']->wrapPwd,
    wrapRecov: null,
    wrapAdmin: null,
    createdAt: $alice['record']->createdAt,
    updatedAt: $alice['record']->updatedAt
);

try {
    $vault->unlockWithPassword($bobFake, 'alice-pwd-1234');
    ko('AAD binding broken — wrap is portable across userIds (CRITICAL)');
} catch (RuntimeException) {
    ok('AAD binding holds — wrap is bound to original userId');
}

// -----------------------------------------------------------------------------

section('changePassword (password rotation, no data re-encryption)');

$rotated = $vault->changePassword($record, $unlocked2, 'new-much-stronger-passphrase');

$rotated->userId === $record->userId ? ok('rotated record keeps userId') : ko('userId changed?!');
hash_equals($rotated->userSalt, $record->userSalt) ? ok('rotated record keeps userSalt') : ko('salt should not change');
$rotated->wrapPwd->ciphertext !== $record->wrapPwd->ciphertext
    ? ok('rotated wrapPwd is different ciphertext')
    : ko('wrapPwd unchanged after rotation');
$rotated->wrapRecov === $record->wrapRecov ? ok('rotated wrapRecov unchanged') : ko('wrapRecov should not change');

// New password works
$unlockedAfter = $vault->unlockWithPassword($rotated, 'new-much-stronger-passphrase');
hash_equals($unlockedAfter->getMasterKey(), $masterKey1)
    ? ok('new password unlocks SAME master key (data preserved)')
    : ko('rotation broke master key (CRITICAL — data loss)');

// Old password fails
try {
    $vault->unlockWithPassword($rotated, 'correct horse battery staple');
    ko('old password should no longer work');
} catch (RuntimeException) {
    ok('old password rejected after rotation');
}

// -----------------------------------------------------------------------------

section('changeMemorized (rotation + removal)');

$rotatedMem = $vault->changeMemorized($record, $unlocked2, 'autumn-leaves-quiet');
$unlockedNewMem = $vault->unlockWithMemorized($rotatedMem, 'autumn-leaves-quiet');
hash_equals($unlockedNewMem->getMasterKey(), $masterKey1)
    ? ok('new memorized unlocks SAME master key')
    : ko('memorized rotation broke master key');

// Remove memorized recovery entirely
$noMem = $vault->changeMemorized($record, $unlocked2, null);
$noMem->wrapRecov === null ? ok('changeMemorized(null) clears recovery wrap') : ko('null did not clear');

// -----------------------------------------------------------------------------

section('UnlockedVault lifecycle');

$ephemeral = $vault->unlockWithPassword($rotated, 'new-much-stronger-passphrase');
!$ephemeral->isLocked() ? ok('ephemeral vault unlocked') : ko('ephemeral wrong state');

$ephemeral->lock();
$ephemeral->isLocked() ? ok('after lock(), isLocked() true') : ko('lock() did not set flag');

try {
    $ephemeral->getMasterKey();
    ko('getMasterKey on locked → should throw');
} catch (RuntimeException) {
    ok('getMasterKey on locked → throws');
}

// Idempotent lock
$ephemeral->lock();
ok('lock() is idempotent (no exception on second call)');

// Cannot serialize
try {
    serialize($ephemeral);
    ko('serialize(UnlockedVault) → should throw');
} catch (RuntimeException) {
    ok('serialize(UnlockedVault) → throws');
}

// -----------------------------------------------------------------------------

section('userId mismatch protection');

$otherUnlocked = $vault->unlockWithPassword($rotated, 'new-much-stronger-passphrase');
$bobRecord = $vault->register(userId: 'bob', password: 'bobs-password')['record'];

try {
    $vault->changePassword($bobRecord, $otherUnlocked, 'pwd');
    ko('changePassword with mismatched userId → should throw');
} catch (InvalidArgumentException) {
    ok('changePassword with mismatched userId → throws');
}

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Phase 2 Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
