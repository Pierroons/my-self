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

use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\MissingEnvelopeException;
use Pierroons\SelfDataGuard\Vault\StaleVaultException;
use Pierroons\SelfDataGuard\Vault\UserVault;
use Pierroons\SelfDataGuard\Vault\VaultRecord;
use Pierroons\SelfDataGuard\Vault\UnlockedVault;
use Pierroons\SelfDataGuard\Vault\WrongSecretException;
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
$bobRecord = $vault->register(userId: 'bob', password: 'bobs-long-password')['record'];

try {
    $vault->changePassword($bobRecord, $otherUnlocked, 'un-mot-de-passe-assez-long');
    ko('changePassword with mismatched userId → should throw');
} catch (InvalidArgumentException) {
    ok('changePassword with mismatched userId → throws');
}

// -----------------------------------------------------------------------------

section('What the library refuses, and what it deliberately does not')
;

// 🔑 Ce qui n'est PAS contrôlé, et c'est un choix : la lib n'impose AUCUN plancher
// d'entropie au mot mémorisé. Argon2id multiplie le coût par essai, il n'ajoute pas
// d'entropie — un mot faible reste un mot faible, 13 bits + le coût. La question de
// savoir si ce plancher doit exister est ouverte et écrite comme telle dans le
// whitepaper §7. Aucune sonde ne peut la trancher à la place de qui décide.

// The promise that lived only in the whitepaper until 0.3.0.
try {
    $vault->register(userId: 'user-short', password: 'onze-caract');
    ko('an 11-byte password was accepted');
} catch (InvalidArgumentException $e) {
    str_contains($e->getMessage(), 'Length is not entropy')
        ? ok('password under 12 bytes refused, and the message does not oversell it')
        : ko('short password refused without the caveat', $e->getMessage());
}

// A vault sealed by the pre-0.3.0 derivation must be NAMED, not reported as a
// wrong secret — otherwise someone hunts for a typo in a correct secret.
$legacyMem   = 'un-secret-parfaitement-correct';
$legacyBuild = $vault->register(userId: 'user-legacy', password: 'correct horse battery staple');
$legacyRec   = $legacyBuild['record'];
$legacyKey   = Primitives::deriveFromMemorizedLegacyV1(
    $legacyMem,
    $legacyRec->userSalt . UserVault::HMAC_CONTEXT_SUFFIX
);
$legacyWrap  = Primitives::encrypt(
    $legacyBuild['unlocked']->getMasterKey(),
    $legacyKey,
    aad: $legacyRec->userId
);
$legacyRec = $legacyRec->withWrapRecov($legacyWrap, new DateTimeImmutable());
try {
    $vault->unlockWithMemorized($legacyRec, $legacyMem);
    ko('a legacy HMAC wrap was OPENED — the weak derivation still grants access');
} catch (RuntimeException $e) {
    str_contains($e->getMessage(), 'predates the Argon2id')
        ? ok('legacy wrap refused AND named — not passed off as a wrong secret')
        : ko('legacy wrap refused as a wrong secret — the zone is silent', $e->getMessage());
}

// A vault written by 0.3.0 wraps its key in AES-256-GCM. Built here by OpenSSL, so
// that the writer is not the library that reads it back.
if (!function_exists('openssl_encrypt')) {
    echo "     (skipped: ext-openssl is needed to build a legacy AES wrap)\n";
} else {
    $aesBuild = $vault->register(userId: 'user-aes', password: 'correct horse battery staple');
    $aesRec   = $aesBuild['record'];
    $aesMaster = $aesBuild['unlocked']->getMasterKey();
    $aesKey   = Primitives::deriveFromPassword('correct horse battery staple', $aesRec->userSalt);
    $aesNonce = random_bytes(EncryptedBlob::NONCE_LEN_V1);
    $aesCt    = openssl_encrypt($aesMaster, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $aesNonce, $aesTag, $aesRec->userId, 16);
    $aesRec   = $aesRec->withWrapPwd(new EncryptedBlob(ciphertext: $aesCt . $aesTag, nonce: $aesNonce), new DateTimeImmutable());
    try {
        $reopened = $vault->unlockWithPassword($aesRec, 'correct horse battery staple');
        hash_equals($aesMaster, $reopened->getMasterKey())
            ? ok('a vault whose wrap is legacy AES-256-GCM still unlocks by password')
            : ko('a legacy AES wrap unlocked to a different master key');
    } catch (RuntimeException $e) {
        ko('a vault written before 0.4.0 no longer unlocks', $e->getMessage());
    }
}

// -----------------------------------------------------------------------------

section('Passphrase — a third envelope, opened by the SelfRecover passphrase');

$PHRASE = 'cheval agrafe batterie correct moulin ivoire';
$three  = $vault->register(
    userId: 'user-phrase',
    password: 'phrase-password-0001',
    memorized: 'sunset-river-marble',
    passphrase: $PHRASE
);
$rec3 = $three['record'];
$rec3->hasPassphrase() ? ok('register(…, passphrase) seals wrap_phrase') : ko('no wrap_phrase after register');

$byPhrase = $vault->unlockWithPassphrase($rec3, $PHRASE);
hash_equals($three['unlocked']->getMasterKey(), $byPhrase->getMasterKey())
    ? ok('the passphrase opens the same master key as the password')
    : ko('the passphrase opened a different key');

try {
    $vault->unlockWithPassphrase($rec3, "  cheval\tagrafe  batterie\ncorrect moulin   ivoire ");
    ok('whitespace variants of the passphrase open it (same normalisation as SelfRecover)');
} catch (RuntimeException $e) {
    ko('a copied passphrase with extra spaces is refused', $e->getMessage());
}

try {
    $vault->unlockWithPassphrase($rec3, 'cheval agrafe batterie correct moulin ivoiree');
    ko('a wrong passphrase opened the vault');
} catch (WrongSecretException) {
    ok('a wrong passphrase raises WrongSecretException');
}

try {
    $vault->unlockWithPassphrase($result['record'], $PHRASE);
    ko('a vault without passphrase wrap opened by passphrase');
} catch (MissingEnvelopeException) {
    ok('no passphrase wrap raises MissingEnvelopeException, not a wrong-secret error');
}

try {
    $vault->unlockWithPassphrase($rec3, "  \t ");
    ko('a passphrase made of whitespace reached the derivation');
} catch (InvalidArgumentException $e) {
    str_contains($e->getMessage(), 'passphrase must not be empty')
        ? ok('a passphrase made of whitespace is refused as empty, under its own name')
        : ko('wrong message for a whitespace passphrase', $e->getMessage());
}

Lock::from('passphrase') === Lock::Passphrase && hash_equals(
    $three['unlocked']->getMasterKey(),
    $vault->unlock($rec3, Lock::from('memorized'), 'sunset-river-marble')->getMasterKey()
)
    ? ok('unlock(record, Lock, secret) dispatches on the lock name')
    : ko('unlock() by Lock did not open the vault');

// -----------------------------------------------------------------------------

section('Passphrase — domain separation from the memorized secret');

// The memorized envelope moved into the passphrase slot, opened with the same
// string: the derivations differ, so it must not open.
$swapped = $rec3->withWrapPhrase($rec3->wrapRecov, new DateTimeImmutable());
try {
    $vault->unlockWithPassphrase($swapped, 'sunset-river-marble');
    ko('the memorized envelope opens as a passphrase — the two derivations coincide');
} catch (WrongSecretException) {
    ok('the same string sealed as memorized does not open as passphrase');
}

// -----------------------------------------------------------------------------

section('Passphrase — rotation, removal, and the other wraps keep it');

$afterPwd = $vault->changePassword($rec3, $byPhrase, 'phrase-password-0002');
$afterMem = $vault->changeMemorized($afterPwd, $byPhrase, 'dawn-lake-copper');
$afterMem->wrapPhrase?->toBase64() === $rec3->wrapPhrase->toBase64()
    ? ok('changePassword() and changeMemorized() keep wrap_phrase')
    : ko('a rotation of another wrap dropped or altered wrap_phrase');

$NEW_PHRASE = 'tapis girafe lundi orage piment velours';
$rotated = $vault->changePassphrase($afterMem, $byPhrase, $NEW_PHRASE);
try {
    $vault->unlockWithPassphrase($rotated, $PHRASE);
    ko('the consumed passphrase still opens the vault');
} catch (WrongSecretException) {
    ok('after changePassphrase(), the old passphrase no longer opens');
}
hash_equals($three['unlocked']->getMasterKey(), $vault->unlockWithPassphrase($rotated, $NEW_PHRASE)->getMasterKey())
    ? ok('the new passphrase opens the same master key')
    : ko('the new passphrase opened a different key');

$removed = $vault->removePassphrase($rotated, $byPhrase);
!$removed->hasPassphrase() && $removed->wrapRecov !== null
    ? ok('removePassphrase() drops wrap_phrase only')
    : ko('removePassphrase() touched another wrap');

try {
    $vault->changePassphrase($rotated, $byPhrase, ' trop  court ');
    ko('a passphrase under the floor was sealed');
} catch (InvalidArgumentException) {
    ok('sealing a passphrase under PASSWORD_MIN_LEN (after normalisation) is refused');
}

// -----------------------------------------------------------------------------

section('Generation — a session from a replaced vault cannot write into the new one');

$old = $vault->register('user-gen', 'gen-password-0001', 'old-memorized');
$new = $vault->register('user-gen', 'gen-password-0002', 'new-memorized');
try {
    $vault->changePassword($new['record'], $old['unlocked'], 'gen-password-0003');
    ko('a session from the old vault re-sealed the new one');
} catch (StaleVaultException) {
    ok('changePassword() with a session from another vault of the same userId raises StaleVaultException');
}
try {
    $vault->changePassphrase($new['record'], $old['unlocked'], $NEW_PHRASE);
    ko('a session from the old vault sealed a passphrase on the new one');
} catch (StaleVaultException) {
    ok('changePassphrase() is guarded the same way');
}

// -----------------------------------------------------------------------------

section('Profile — a vault opens under the Argon2id profile its record carries');

// A profile other than the current constants: what a vault sealed before a
// future change of ARGON2_* looks like.
$OPS = 2;
$MEM = 32 * 1024 * 1024;
($OPS !== Primitives::ARGON2_OPSLIMIT || $MEM !== Primitives::ARGON2_MEMLIMIT)
    ? ok('fixture: the other profile differs from the current constants')
    : ko('fixture: the other profile equals the current constants — the section proves nothing');

$base = $vault->register('user-profile', 'profile-password-01', 'profile-memorized');
$mk   = $base['unlocked']->getMasterKey();
$seal = new ReflectionMethod(UserVault::class, 'seal');
$sous = static fn (Lock $l, string $s) => $seal->invoke(
    null, $l, $s, $base['record']->userSalt, 'user-profile', $mk, $OPS, $MEM);
$autre = new VaultRecord(
    userId: 'user-profile', userSalt: $base['record']->userSalt,
    wrapPwd: $sous(Lock::Password, 'profile-password-01'),
    wrapRecov: $sous(Lock::Memorized, 'profile-memorized'),
    wrapAdmin: null, createdAt: $base['record']->createdAt, updatedAt: $base['record']->updatedAt,
    kdfOpslimit: $OPS, kdfMemlimit: $MEM
);

try {
    $vault->unlockWithPassword($autre, 'profile-password-01')->getMasterKey() === $mk
        && $vault->unlockWithMemorized($autre, 'profile-memorized')->getMasterKey() === $mk
        ? ok('both locks open under the profile of the record, not the current constants')
        : ko('the record opened to another master key');
} catch (Throwable $e) {
    ko('a vault sealed under another profile no longer opens', $e->getMessage());
}

$menteur = new VaultRecord(
    userId: $autre->userId, userSalt: $autre->userSalt, wrapPwd: $autre->wrapPwd, wrapRecov: $autre->wrapRecov,
    wrapAdmin: null, createdAt: $autre->createdAt, updatedAt: $autre->updatedAt
);
try {
    $vault->unlockWithPassword($menteur, 'profile-password-01');
    ko('the profile is not read: a record claiming the current constants opened too');
} catch (WrongSecretException) {
    ok('the same envelopes under the current constants do not open — the profile is what decides');
}

try {
    $rot = $vault->changePassword($autre, $vault->unlockWithPassword($autre, 'profile-password-01'), 'profile-password-02');
    $rot->kdfOpslimit === $OPS && $rot->kdfMemlimit === $MEM
        && $vault->unlockWithPassword($rot, 'profile-password-02')->getMasterKey() === $mk
        && $vault->unlockWithMemorized($rot, 'profile-memorized')->getMasterKey() === $mk
        ? ok('a re-seal keeps the vault\'s profile: the new password and the old memorized wrap both open')
        : ko('the re-seal changed the profile or broke a lock');
} catch (Throwable $e) {
    ko('re-sealing a vault under another profile failed', $e->getMessage());
}

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Phase 2 Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
