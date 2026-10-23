<?php

declare(strict_types=1);

/**
 * Sanity smoke test for Phase 5 — Façade SelfDataGuard end-to-end.
 *
 * Run:  php tests/sanity_facade.php
 * Exit: 0 on success, non-zero on first failure.
 *
 * Validates the full developer-facing API: a typical app integration with
 * register → set fields → login → get fields → lookup → rotate → delete.
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/key_fixture.php';
require __DIR__ . '/blob_v2_fixture.php';
require __DIR__ . '/storage_doubles.php';
require __DIR__ . '/ceremony_fixture.php';

use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Fields\AmbiguousLookupException;
use Pierroons\SelfDataGuard\Fields\BlindIndex;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\DuplicateIndexException;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\StaleVaultException;
use Pierroons\SelfDataGuard\Vault\UserVault;
use Pierroons\SelfDataGuard\Vault\VaultGeneration;
use Pierroons\SelfDataGuard\Vault\VaultRecord;
use Pierroons\SelfDataGuard\Vault\VaultTamperedException;
use Pierroons\SelfDataGuard\Vault\WrongSecretException;

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

section('Setup — façade with fresh SQLite + random blindKey');

$dbPath = sys_get_temp_dir() . '/selfdataguard-facade-' . bin2hex(random_bytes(4)) . '.sqlite';
@unlink($dbPath);
register_shutdown_function(static fn () => @unlink($dbPath));

$storage  = new SqliteAdapter("sqlite:{$dbPath}");
$blindKey = Primitives::randomBytes(32);
$dg       = new SelfDataGuard($storage, $blindKey);

ok('SelfDataGuard façade constructed');

// blindKey too short
try {
    new SelfDataGuard($storage, 'short');
    ko('façade accepts short blindKey');
} catch (InvalidArgumentException) {
    ok('façade rejects short blindKey');
}

// -----------------------------------------------------------------------------

section('Register + initial fields');

$session = $dg->register('user-cosmo', 'correct horse battery staple', 'sunset-river-marble');
ok('register returns UnlockedVault');

!$session->isLocked() ? ok('session is unlocked') : ko('session locked at register');

$dg->userExists('user-cosmo') ? ok('userExists → true after register') : ko('userExists wrong');
!$dg->userExists('nobody') ? ok('userExists → false for unknown user') : ko('userExists false positive');

$dg->setFields($session, [
    'email'   => 'alice@example.com',
    'phone'   => '+33612345678',
    'address' => '12 rue des Champs, 33220 Sainte-Foy',
    'iban'    => 'FR7612345678901234567890123',
], indexed: ['email', 'phone']);
ok('setFields with indexing on email + phone');

// -----------------------------------------------------------------------------

section('Duplicate registration');

try {
    $dg->register('user-cosmo', 'another-password');
    ko('duplicate register should throw');
} catch (RuntimeException) {
    ok('duplicate register throws');
}

// -----------------------------------------------------------------------------

section('Login + read fields');

$session2 = $dg->loginWithPassword('user-cosmo', 'correct horse battery staple');
ok('loginWithPassword OK');

$plain = $dg->getFields($session2);
count($plain) === 4 ? ok('getFields returns all 4 stored fields') : ko('field count: got ' . count($plain));
$plain['email'] === 'alice@example.com' ? ok('email decrypted') : ko('email wrong: ' . ($plain['email'] ?? 'null'));
$plain['iban']  === 'FR7612345678901234567890123' ? ok('iban decrypted') : ko('iban wrong');

// Subset
$subset = $dg->getFields($session2, ['email', 'phone']);
count($subset) === 2 && isset($subset['email'], $subset['phone'])
    ? ok('getFields filtered returns subset')
    : ko('subset wrong');

// Wrong password
try {
    $dg->loginWithPassword('user-cosmo', 'wrong-password');
    ko('login wrong password should throw');
} catch (RuntimeException) {
    ok('login wrong password throws');
}

// -----------------------------------------------------------------------------

section('Login by memorized (recovery flow)');

$sessionRec = $dg->loginWithMemorized('user-cosmo', 'sunset-river-marble');
$recPlain = $dg->getFields($sessionRec);
$recPlain['email'] === 'alice@example.com'
    ? ok('memorized recovery unlocks SAME data')
    : ko('memorized recovery returned different data');

// Wrong memorized
try {
    $dg->loginWithMemorized('user-cosmo', 'wrong-secret');
    ko('login wrong memorized should throw');
} catch (RuntimeException) {
    ok('login wrong memorized throws');
}

// -----------------------------------------------------------------------------

section('Find user by indexed field');

$found = $dg->findUserByField('email', 'alice@example.com');
$found === 'user-cosmo' ? ok('findUserByField → user-cosmo by email') : ko("got: " . var_export($found, true));

$foundPhone = $dg->findUserByField('phone', '+33612345678');
$foundPhone === 'user-cosmo' ? ok('findUserByField → user-cosmo by phone') : ko('phone lookup failed');

// Miss
$miss = $dg->findUserByField('email', 'unknown@example.com');
$miss === null ? ok('findUserByField returns null on miss') : ko('false hit');

// Non-indexed field returns null even if value is correct
$nonIndexed = $dg->findUserByField('iban', 'FR7612345678901234567890123');
$nonIndexed === null ? ok('non-indexed field lookup returns null (no index stored)') : ko('non-indexed leaked a hit');

// -----------------------------------------------------------------------------

section('Multi-user isolation');

$bobSession = $dg->register('user-bob', 'bob-pwd-1234');
$dg->setFields($bobSession, [
    'email' => 'bob@example.com',
], indexed: ['email']);

$bobFound = $dg->findUserByField('email', 'bob@example.com');
$bobFound === 'user-bob' ? ok('blind index isolates users (bob found)') : ko('multi-user lookup wrong');

$cosmoStill = $dg->findUserByField('email', 'alice@example.com');
$cosmoStill === 'user-cosmo' ? ok('cosmo still findable after bob registered') : ko('cosmo lost from index');

// -----------------------------------------------------------------------------

section('Password rotation via façade');

$dg->setLock($session2, Lock::Password, 'new-much-stronger-passphrase');

$sessionNew = $dg->loginWithPassword('user-cosmo', 'new-much-stronger-passphrase');
$plain2 = $dg->getFields($sessionNew);
$plain2['email'] === 'alice@example.com'
    ? ok('after changePassword, data still readable')
    : ko('rotation broke data');

try {
    $dg->loginWithPassword('user-cosmo', 'correct horse battery staple');
    ko('old password should be rejected');
} catch (RuntimeException) {
    ok('old password rejected after rotation');
}

// -----------------------------------------------------------------------------

section('Memorized rotation via façade');

$dg->setLock($sessionNew, Lock::Memorized, 'autumn-leaves-quiet');

$sessionMem = $dg->loginWithMemorized('user-cosmo', 'autumn-leaves-quiet');
$plain3 = $dg->getFields($sessionMem);
$plain3['email'] === 'alice@example.com'
    ? ok('after changeMemorized, recovery flow still works')
    : ko('memorized rotation broke recovery');

// Remove memorized recovery
$dg->removeLock($sessionMem, Lock::Memorized);
try {
    $dg->loginWithMemorized('user-cosmo', 'autumn-leaves-quiet');
    ko('login by memorized should fail after removal');
} catch (RuntimeException) {
    ok('memorized recovery disabled cleanly');
}

// Password still works
$sessionPwdAfter = $dg->loginWithPassword('user-cosmo', 'new-much-stronger-passphrase');
ok('password still works after memorized removed');

// -----------------------------------------------------------------------------

section('Field upsert + add new fields later');

$dg->setFields($sessionPwdAfter, ['email' => 'alice.new@example.com'], indexed: ['email']);
$updated = $dg->getFields($sessionPwdAfter, ['email']);
$updated['email'] === 'alice.new@example.com' ? ok('field upserted via setFields') : ko('upsert via façade failed');

// Old index should miss now
$missOld = $dg->findUserByField('email', 'alice@example.com');
$missOld === null ? ok('old indexed value no longer resolves') : ko('stale index still hits');

$hitNew = $dg->findUserByField('email', 'alice.new@example.com');
$hitNew === 'user-cosmo' ? ok('new indexed value resolves') : ko('new index broken');

// Add a brand-new field
$dg->setFields($sessionPwdAfter, ['birth_year' => '1990']);
$plain4 = $dg->getFields($sessionPwdAfter);
($plain4['birth_year'] ?? null) === '1990' ? ok('new field added later is readable') : ko('new field lost');

// -----------------------------------------------------------------------------

section('Delete user');

$dg->delete($sessionPwdAfter->generation());
!$dg->userExists('user-cosmo') ? ok('delete removes vault') : ko('delete failed');

try {
    $dg->loginWithPassword('user-cosmo', 'new-much-stronger-passphrase');
    ko('login on deleted user should throw');
} catch (RuntimeException) {
    ok('login on deleted user throws');
}

// Bob untouched
$dg->userExists('user-bob') ? ok('other users untouched by delete') : ko('cascade too wide');

// -----------------------------------------------------------------------------

section('recover() — every SelfRecover path that keeps the data');

$PH1 = 'cheval agrafe batterie correct moulin ivoire';
$PH2 = 'tapis girafe lundi orage piment velours';
$PH3 = 'sable comete nuage farine bouton cerise';
$s = $dg->register('user-rec', 'rec-password-0001', 'rec-memorized', $PH1);
$dg->setFields($s, ['note' => 'garde-moi']);

// Level 1: the server holds the old passphrase, SelfRecover issued a new password and passphrase.
$dg->recover('user-rec', Lock::Passphrase, $PH1, 'rec-password-0002', $PH2);
($dg->getFields($dg->loginWithPassword('user-rec', 'rec-password-0002'))['note'] ?? null) === 'garde-moi'
    ? ok('level 1: the new password reads a field written before the recovery')
    : ko('level 1: the data did not survive the recovery');
try {
    $dg->recover('user-rec', Lock::Passphrase, $PH1, 'rec-password-0009');
    ko('level 1: the consumed passphrase still opens the vault');
} catch (WrongSecretException) {
    ok('level 1: the consumed passphrase no longer opens anything');
}

// Level 2 by code: the server holds the memorized digest.
$dg->recover('user-rec', Lock::Memorized, 'rec-memorized', 'rec-password-0003', $PH3);
($dg->getFields($dg->loginWithPassword('user-rec', 'rec-password-0003'))['note'] ?? null) === 'garde-moi'
    ? ok('level 2 by code: re-sealed by the memorized secret, data intact')
    : ko('level 2 by code: data lost');

// No new passphrase: the passphrase wrap stays as it is.
$dg->recover('user-rec', Lock::Passphrase, $PH3, 'rec-password-0004');
try {
    $dg->recover('user-rec', Lock::Passphrase, $PH3, 'rec-password-0005');
    ok('$newPassphrase null keeps the passphrase wrap');
} catch (Throwable $e) {
    ko('a recovery without new passphrase dropped the passphrase wrap', $e->getMessage());
}

// Level 2 by device: SelfRecover replaced the password and the server could
// open nothing. The password wrap fell behind; any other lock catches it up.
try {
    $dg->loginWithPassword('user-rec', 'rec-password-0006');
    ko('fixture: the new password already opens the vault');
} catch (WrongSecretException) {
    ok('fixture: after a recovery the vault did not follow, the new password is refused');
}
$dg->recover('user-rec', Lock::Passphrase, $PH3, 'rec-password-0006');
($dg->getFields($dg->loginWithPassword('user-rec', 'rec-password-0006'))['note'] ?? null) === 'garde-moi'
    ? ok('catch-up: opened by passphrase, re-sealed on the current password')
    : ko('catch-up failed');

// -----------------------------------------------------------------------------

section('Generation — a session from a replaced vault is refused everywhere');

$admin = SelfDataGuard::generateAdminRecoveryKey('admin passphrase for the facade bench');
$stale = $dg->register('user-gen', 'gen-password-0001', 'gen-memorized');
$dg->setFields($stale, ['a' => '1']);
$dg->delete($stale->generation());
$fresh = $dg->register('user-gen', 'gen-password-0002', 'gen-memorized');

$guarded = [
    'setFields'              => static fn () => $dg->setFields($stale, ['a' => '2']),
    'getFields'              => static fn () => $dg->getFields($stale),
    'setEscrowFields'        => static fn () => $dg->setEscrowFields($stale, $admin['publicKey'], ['c' => 'd']),
    'getEscrowFieldsAsUser'  => static fn () => $dg->getEscrowFieldsAsUser($stale),
    'changePassword'         => static fn () => $dg->setLock($stale, Lock::Password, 'gen-password-0003'),
    'changePassphrase'       => static fn () => $dg->setLock($stale, Lock::Passphrase, $PH1),
    'hasEscrow'              => static fn () => $dg->hasEscrow($stale),
    'listArchives'           => static fn () => $dg->listArchives($stale),
];
$refused = [];
foreach ($guarded as $name => $call) {
    try {
        $call();
    } catch (StaleVaultException) {
        $refused[] = $name;
    }
}
$refused === array_keys($guarded)
    ? ok('StaleVaultException on ' . implode(', ', $refused))
    : ko('a stale session went through', implode(', ', array_diff(array_keys($guarded), $refused)));
!$dg->hasEscrow($fresh)
    ? ok('no escrow was created under the old master key')
    : ko('an escrow was sealed with a stale session');

// -----------------------------------------------------------------------------

section('reEnroll() — level 3: the old vault is archived, a new one created');

$EMAIL = 'ancien@example.org';
$l3old = $dg->register('user-l3', 'l3-password-0001', 'l3-memorized', $PH1);
$dg->setFields($l3old, ['email' => $EMAIL, 'note' => 'ancienne note'], indexed: ['email']);
$dg->setEscrowFields($l3old, $admin['publicKey'], ['contact' => 'contact de secours']);

$replaced = $dg->generationOf('user-l3');
$l3 = $dg->reEnroll($replaced, 'l3-password-0002', 'l3-memorized-new', $PH2);
$current = $l3['unlocked'];
$archiveId = $l3['archiveId'];
is_string($archiveId) && $dg->getFields($current) === []
    ? ok('reEnroll() returns the archive id and an empty new vault')
    : ko('reEnroll() did not set the old vault aside');
try {
    $dg->reEnroll($replaced, 'l3-password-0009');
    ko('a second reEnroll() naming the replaced vault archived the new one');
} catch (StaleVaultException) {
    ok('a second reEnroll() naming the same generation is refused — no second archive, the new vault stays');
}
array_column($dg->listArchives($current), 'id') === [$archiveId]
    ? ok('listArchives() lists it, from a session on the current vault')
    : ko('the archive is not listed, or a second one was made');

// -----------------------------------------------------------------------------

section('openArchive() — an old lock AND a session on the current vault');

try {
    $dg->openArchive($l3old, $archiveId, Lock::Passphrase, $PH1);
    ko('the archive opened from a session on the replaced vault');
} catch (StaleVaultException) {
    ok('a session on the replaced vault cannot open the archive');
}
try {
    $dg->openArchive($current, $archiveId, Lock::Passphrase, $PH2);
    ko('the archive opened with the NEW passphrase');
} catch (WrongSecretException) {
    ok('the archive does not open with the new secrets');
}

$opened = $dg->openArchive($current, $archiveId, Lock::Passphrase, $PH1);
$read = $dg->readArchive($opened);
($read['private'] ?? null) == ['email' => $EMAIL, 'note' => 'ancienne note']
    && ($read['escrow'] ?? null) === ['contact' => 'contact de secours']
    && !isset($read['indexed'])
    ? ok('the old passphrase opens it: private fields and escrow — no index list an SQL writer could extend')
    : ko('readArchive() returned something else', json_encode($read));

!method_exists($opened, 'session')
    ? ok('an opened archive hands out no session to write with')
    : ko('an opened archive still hands out its session');

$dg->setFields($current, $read['private'], indexed: ['email']);
$dg->findUserByField('email', $EMAIL) === 'user-l3'
    && ($dg->getFields($current)['note'] ?? null) === 'ancienne note'
    ? ok('restored into the new vault, re-indexed: the old email finds the account again')
    : ko('restoration failed');

// The archive keeps each field's blob as it was, under the same AAD the live
// vault uses. So the only thing that keeps an archived ciphertext from being
// replanted as the current value is that reEnroll() draws a NEW master key —
// a property nothing else guarantees, and that this check pins.
$pdoL3 = new PDO("sqlite:{$dbPath}");
$package = json_decode((string) $pdoL3->query(
    "SELECT package FROM selfdataguard_archives WHERE user_id = 'user-l3'"
)->fetchColumn(), true);
$archivedNote = $package['fields']['private']['note']['ciphertext'] ?? null;
$liveNote = $pdoL3->query(
    "SELECT ciphertext FROM selfdataguard_fields WHERE user_id = 'user-l3' AND field_name = 'note'"
)->fetchColumn();
is_string($archivedNote) && $archivedNote !== $liveNote
    ? ok('witness: the archive still holds the old ciphertext of "note"')
    : ko('witness failed — no archived "note" to replant, the next check would prove nothing');
$pdoL3->prepare(
    "UPDATE selfdataguard_fields SET ciphertext = ? WHERE user_id = 'user-l3' AND field_name = 'note'"
)->execute([$archivedNote]);
try {
    $dg->getFields($current, ['note']);
    ko('an archived ciphertext replanted in the live vault was read as the current value');
} catch (RuntimeException) {
    ok('an archived ciphertext replanted in the live vault fails: re-enrolment drew a new master key');
}
$pdoL3->prepare(
    "UPDATE selfdataguard_fields SET ciphertext = ? WHERE user_id = 'user-l3' AND field_name = 'note'"
)->execute([$liveNote]);

// -----------------------------------------------------------------------------

section('The archive opens under the Argon2id profile it was sealed with');

$pdoForProfile = new PDO("sqlite:{$dbPath}");
$setOps = static fn (int $ops) => $pdoForProfile->prepare(
    'UPDATE selfdataguard_archives SET package = json_set(package, \'$.kdf.opslimit\', :ops) WHERE archive_id = :id'
)->execute([':ops' => $ops, ':id' => $archiveId]);
$setOps(Primitives::ARGON2_OPSLIMIT + 1);
$t0 = microtime(true);
try {
    $dg->openArchive($current, $archiveId, Lock::Passphrase, $PH1);
    ko('a profile changed in the archive package went unnoticed');
} catch (VaultTamperedException) {
    microtime(true) - $t0 < 0.05
        ? ok('a profile changed in the archive package fails its seal, before any derivation')
        : ko('the tampered profile was caught only after an Argon2id ran');
}

// An archive taken before 0.7.0 carries no seal: its profile is still the one used.
$pdoForProfile->prepare(
    'UPDATE selfdataguard_archives SET package = json_set(package, \'$.vault.profile_seal\', NULL) WHERE archive_id = :id'
)->execute([':id' => $archiveId]);
try {
    $dg->openArchive($current, $archiveId, Lock::Passphrase, $PH1);
    ko('the stored profile is ignored — the archive opened under the current one');
} catch (WrongSecretException) {
    ok('an archive without a seal, with another profile recorded, no longer opens: the stored profile is the one used');
}
$setOps(Primitives::ARGON2_OPSLIMIT);
$dg->readArchive($dg->openArchive($current, $archiveId, Lock::Passphrase, $PH1))['private'] !== []
    ? ok('the profile put back, the unsealed archive opens again')
    : ko('the archive did not reopen once its profile was put back');

// -----------------------------------------------------------------------------

section('The admin reaches the archived escrow; deletion is explicit');

benchCeremony($storage)->readArchiveEscrow(
    'user-l3', $archiveId, 'case-l3', $admin['sealedSecret'], 'admin passphrase for the facade bench', $admin['publicKey']
) === ['contact' => 'contact de secours']
    ? ok('the ceremony reads the backup memo of the archive')
    : ko('the admin cannot reach the archived escrow');

$openedBefore = $dg->openArchive($current, $archiveId, Lock::Passphrase, $PH1);
$again = $dg->reEnroll($current->generation(), 'l3-password-0003');
try {
    $dg->deleteArchive($openedBefore);
    ko('an archive opened under a vault replaced since was deleted');
} catch (StaleVaultException) {
    ok('deleteArchive() refuses an archive opened under a vault replaced since');
}
$reopened = $dg->openArchive($again['unlocked'], $archiveId, Lock::Passphrase, $PH1);
$dg->deleteArchive($reopened) && array_column($storage->listArchives('user-l3'), 'id') === [$again['archiveId']]
    ? ok('deleteArchive() deletes the archive it was given, opened with one of its old locks')
    : ko('deleteArchive() did not delete that archive, or deleted another');
$dg->delete($again['unlocked']->generation());
count($storage->listArchives('user-l3')) === 1
    ? ok('delete() leaves the archive')
    : ko('delete() took the archive with it');
$dg->eraseAccount('user-l3') === 1 && $storage->listArchives('user-l3') === []
    ? ok('eraseAccount() is the explicit erasure, with no live vault left')
    : ko('eraseAccount() did not erase it');

// -----------------------------------------------------------------------------

section('A storage that drops wrap_phrase is caught, not trusted');

/** A StorageInterface implementation that drops wrap_phrase on every write. */
final class ForgetfulStorage extends DelegatingStorage
{
    public function saveVault(VaultRecord $r): void { $this->inner->saveVault($r->withWrapPhrase(null, $r->updatedAt)); }
    public function updateVault(VaultRecord $r): int { return $this->inner->updateVault($r->withWrapPhrase(null, $r->updatedAt)); }
    public function replaceWithArchive(VaultGeneration $g, VaultRecord $r): string { return $this->inner->replaceWithArchive($g, $r->withWrapPhrase(null, $r->updatedAt)); }
}

$forgetful = new SelfDataGuard(new ForgetfulStorage($storage), $blindKey);
try {
    $forgetful->register('user-forget', 'forget-password-01', null, $PH1);
    ko('a passphrase that was never stored was reported as sealed');
} catch (RuntimeException $e) {
    str_contains($e->getMessage(), 'did not persist wrap_phrase')
        ? ok('register(): the missing wrap_phrase is detected on read-back')
        : ko('wrong failure', $e->getMessage());
}

/** A StorageInterface implementation that reads every vault back under another Argon2id profile. */
final class ProfileLosingStorage extends DelegatingStorage
{
    public function loadVault(string $u): VaultRecord
    {
        $r = $this->inner->loadVault($u);
        return new VaultRecord(
            userId: $r->userId, userSalt: $r->userSalt, wrapPwd: $r->wrapPwd, wrapRecov: $r->wrapRecov,
            wrapAdmin: $r->wrapAdmin, createdAt: $r->createdAt, updatedAt: $r->updatedAt,
            wrapPhrase: $r->wrapPhrase, revision: $r->revision,
            kdfOpslimit: $r->kdfOpslimit + 1, kdfMemlimit: $r->kdfMemlimit
        );
    }
}

try {
    (new SelfDataGuard(new ProfileLosingStorage($storage), $blindKey))
        ->register('user-profile-lost', 'profile-password-01', null, $PH1);
    ko('a vault read back under another Argon2id profile was reported as stored');
} catch (RuntimeException $e) {
    str_contains($e->getMessage(), 'did not persist the Argon2id profile')
        ? ok('register(): a profile the storage did not keep is detected on read-back')
        : ko('wrong failure', $e->getMessage());
}

/** A StorageInterface implementation written before 0.6.0: it rebuilds records without the profile columns. */
final class ProfileBlindStorage extends DelegatingStorage
{
    public function loadVault(string $u): VaultRecord
    {
        $r = $this->inner->loadVault($u);
        return new VaultRecord(
            userId: $r->userId, userSalt: $r->userSalt, wrapPwd: $r->wrapPwd, wrapRecov: $r->wrapRecov,
            wrapAdmin: $r->wrapAdmin, createdAt: $r->createdAt, updatedAt: $r->updatedAt,
            wrapPhrase: $r->wrapPhrase, revision: $r->revision
        );
    }
}

try {
    (new SelfDataGuard(new ProfileBlindStorage($storage), $blindKey))
        ->register('user-profile-blind', 'profile-password-02', null, $PH1);
    ko('a storage that never reads the profile back was trusted: its vaults would open under the current constants');
} catch (InvalidArgumentException $e) {
    str_contains($e->getMessage(), 'kdfOpslimit and kdfMemlimit are required')
        ? ok('a storage that never reads the profile back fails at its first read')
        : ko('wrong failure', $e->getMessage());
}

// -----------------------------------------------------------------------------

section('Two gestures on a lock: setLock() seals or replaces it, removeLock() takes it off');

$locks = $dg->register('user-locks', 'locks-password-0001');
$revision = static fn (): int => $storage->loadVault('user-locks')->revision;
$dg->setLock($locks, Lock::Passphrase, $PH2);
$dg->recover('user-locks', Lock::Passphrase, $PH2, 'locks-password-0002')->userId === 'user-locks'
    ? ok('setLock() adds a lock the vault did not have, and it opens')
    : ko('setLock() did not add the passphrase lock');
$before = $revision();
$dg->removeLock($locks, Lock::Memorized) === false && $revision() === $before
    ? ok('removeLock() of a lock the vault does not have: false, and nothing written')
    : ko('removing an absent lock wrote, or said it removed something');
$dg->removeLock($locks, Lock::Passphrase) === true && $revision() === $before + 1
    ? ok('removeLock() of a lock the vault has: true, one write')
    : ko('removeLock() did not remove the passphrase lock');
try {
    $dg->recover('user-locks', Lock::Passphrase, $PH2, 'locks-password-0003');
    ko('a removed passphrase still opens the vault');
} catch (WrongSecretException) {
    ok('the removed passphrase no longer opens the vault');
}
try {
    $dg->removeLock($locks, Lock::Password);
    ko('the password lock was removed');
} catch (InvalidArgumentException) {
    ok('the password lock is never removed — it is replaced with setLock()');
}

section('A profile changed in the database is tampering, not a wrong secret — and the operator repairs it');

$PW_SEAL = 'seal-password-0001';
$sealed  = $dg->register('user-seal', $PW_SEAL, 'seal-memorized', $PH1);
$dg->setFields($sealed, ['note' => 'sous sceau']);
$pdoSeal = new PDO("sqlite:{$dbPath}");
$setRow  = static fn (string $set) => $pdoSeal->exec("UPDATE selfdataguard_vaults SET {$set} WHERE user_id = 'user-seal'");
$refusal = static function (callable $call) {
    $t0 = microtime(true);
    try {
        $call();
        return 'opened';
    } catch (VaultTamperedException $e) {
        return microtime(true) - $t0 < 0.05 ? 'tampered' : 'tampered after a derivation';
    } catch (Throwable $e) {
        return get_class($e);
    }
};
$threeLocks = static fn () => [
    $refusal(static fn () => $dg->loginWithPassword('user-seal', $PW_SEAL)),
    $refusal(static fn () => $dg->loginWithMemorized('user-seal', 'seal-memorized')),
    $refusal(static fn () => $dg->recover('user-seal', Lock::Passphrase, $PH1, 'seal-password-0002')),
];

$setRow('kdf_opslimit = ' . (Primitives::ARGON2_OPSLIMIT + 1));
$threeLocks() === ['tampered', 'tampered', 'tampered']
    ? ok('a profile changed within the bounds: password, memorized word and passphrase each say tampered, before any derivation')
    : ko('a changed profile was taken for something else', implode(', ', $threeLocks()));
$dg->repairProfile('user-seal') && !$dg->repairProfile('user-seal')
    && ($dg->getFields($dg->loginWithPassword('user-seal', $PW_SEAL))['note'] ?? null) === 'sous sceau'
    ? ok('repairProfile() finds the profile the seal vouches for, without the holder\'s secret: the vault opens as before')
    : ko('the repair did not bring the vault back');

$setRow('kdf_opslimit = 1');
$refusal(static fn () => $dg->loginWithPassword('user-seal', $PW_SEAL)) === 'tampered' && $dg->repairProfile('user-seal')
    ? ok('a profile out of bounds is refused before any derivation, and repaired the same way')
    : ko('a profile out of bounds was not refused, or not repaired');

$dg->register('user-seal-other', 'seal-other-password-01');
$otherSeal = (string) $pdoSeal->query("SELECT profile_seal FROM selfdataguard_vaults WHERE user_id = 'user-seal-other'")->fetchColumn();
$otherSeal !== ''
    ? ok('fixture: the other account carries a seal to copy')
    : ko('fixture: no seal to copy — the next check would prove nothing');
$setRow("profile_seal = '{$otherSeal}'");
$refusal(static fn () => $dg->loginWithPassword('user-seal', $PW_SEAL)) === 'tampered'
    ? ok('a seal copied from another account does not vouch for this one')
    : ko('a seal copied from another account was accepted');
$setRow("profile_seal = '" . base64_encode(random_bytes(32)) . "'");
try {
    $dg->repairProfile('user-seal');
    ko('a vault whose seal was changed too was repaired against nothing');
} catch (VaultTamperedException) {
    ok('a changed seal matches no published profile: repairProfile() says so instead of guessing');
}

$setRow('profile_seal = NULL');
$refusal(static fn () => $dg->loginWithPassword('user-seal', $PW_SEAL)) === 'tampered'
    && $dg->sealProfile('user-seal') && !$dg->sealProfile('user-seal')
    && ($dg->getFields($dg->loginWithPassword('user-seal', $PW_SEAL))['note'] ?? null) === 'sous sceau'
    ? ok('a vault without a seal opens to no one until sealProfile(); sealed once, it opens, and a second call writes nothing')
    : ko('an unsealed vault was opened, or sealProfile() did not seal it');

$open = $dg->loginWithPassword('user-seal', $PW_SEAL);
$setRow('kdf_opslimit = ' . (Primitives::ARGON2_OPSLIMIT + 1));
try {
    $dg->setLock($open, Lock::Password, 'seal-password-0003');
    ko('a re-seal went ahead on a profile changed under an open session');
} catch (VaultTamperedException) {
    ok('a profile changed under an open session stops the re-seal: nothing is sealed under a profile the session never proved');
}
$dg->repairProfile('user-seal');

section('An indexed value names one account; a field declared shared names none');

$owner = $dg->register('user-owner', 'owner-password-0001');
$dg->setFields($owner, ['email' => 'owner@example.org'], indexed: ['email']);
$squat = $dg->register('user-squat', 'squat-password-0001');
try {
    $dg->setFields($squat, ['email' => 'owner@example.org', 'note' => 'n'], indexed: ['email']);
    ko('a second account indexed a value another account holds');
} catch (DuplicateIndexException $e) {
    $e->fields === ['email'] && $dg->getFields($squat) === []
        ? ok('setFields(): a value another account holds is refused, the field named, nothing of the batch written')
        : ko('wrong refusal', implode(',', $e->fields));
}
$dg->findUserByField('email', 'owner@example.org') === 'user-owner'
    ? ok('the value still names its holder')
    : ko('the refused write moved the value');

$dgShared = new SelfDataGuard($storage, $blindKey, sharedIndexes: ['phone']);
$dgShared->setFields($owner, ['phone' => '+33 6 00 00 00 01'], indexed: ['phone']);
$dgShared->setFields($squat, ['phone' => '+33 6 00 00 00 01'], indexed: ['phone']);
$holders = $dgShared->findUsersByField('phone', '+33 6 00 00 00 01');
sort($holders);
$holders === ['user-owner', 'user-squat']
    ? ok('a field declared shared takes the same value from two accounts, and findUsersByField() lists both')
    : ko('the shared field did not list both holders', implode(',', $holders));
try {
    $dgShared->findUserByField('phone', '+33 6 00 00 00 01');
    ko('a field declared shared was used to name one account');
} catch (InvalidArgumentException) {
    ok('findUserByField() refuses a field declared shared: it names no single account');
}

/** A storage holding a value under two accounts, as a database written before 0.7.0 can. */
final class DoubledIndexStorage extends DelegatingStorage
{
    public function findUserIdsByIndex(string $f, string $i): array { return ['user-a', 'user-b']; }
}

try {
    (new SelfDataGuard(new DoubledIndexStorage($storage), $blindKey))->findUserByField('email', 'x@example.org');
    ko('the lookup picked one of two accounts');
} catch (AmbiguousLookupException) {
    ok('findUserByField() refuses to pick when two accounts hold the value');
}

$ownerL3 = $dg->reEnroll($dg->generationOf('user-owner'), 'owner-password-0002');
$dg->setFields($squat, ['email' => 'owner@example.org'], indexed: ['email']);
$restored = $dg->readArchive($dg->openArchive($ownerL3['unlocked'], $ownerL3['archiveId'], Lock::Password, 'owner-password-0001'));
try {
    $dg->setFields($ownerL3['unlocked'], $restored['private'], indexed: ['email']);
    ko('a restore re-indexed a value another account took meanwhile');
} catch (DuplicateIndexException $e) {
    $dg->setFields($ownerL3['unlocked'], $restored['private'], indexed: array_diff(['email'], $e->fields));
    ($dg->getFields($ownerL3['unlocked'])['email'] ?? null) === 'owner@example.org'
        ? ok('a restore whose value was taken during the level 3 names the field, then goes through without that index')
        : ko('the restore without the taken index lost the field');
}

// -----------------------------------------------------------------------------

section('The read-back compares the salt, the revision and every wrap; the userId is echoed');

/** A storage with a case-insensitive user_id: asked for 'USER-CI', it answers with 'user-ci'. */
final class CaseFoldingStorage extends DelegatingStorage
{
    public function loadVault(string $u): VaultRecord { return $this->inner->loadVault(strtolower($u)); }
}

$folding = new SelfDataGuard(new CaseFoldingStorage($storage), $blindKey);
$folding->register('user-ci', 'case-password-0001');
try {
    $folding->loginWithPassword('USER-CI', 'case-password-0001');
    ko('another spelling of the userId opened its vault');
} catch (RuntimeException $e) {
    str_contains($e->getMessage(), 'another userId than the one asked for')
        ? ok('a vault returned for another userId than the one asked for is refused')
        : ko('wrong failure', $e->getMessage());
}

/** An insert that another insert overwrites before the first one is read back — an upsert under a race. */
final class OverwrittenInsertStorage extends DelegatingStorage
{
    public ?VaultRecord $overwriteWith = null;
    public function saveVault(VaultRecord $r): void
    {
        $this->inner->saveVault($r);
        if ($this->overwriteWith !== null) {
            $this->inner->deleteVault($r->generation());
            $this->inner->saveVault($this->overwriteWith);
            $this->overwriteWith = null;
        }
    }
}

$overwritten = new OverwrittenInsertStorage($storage);
$overwritten->overwriteWith = (new UserVault())->register('user-upsert', 'other-insert-0001')['record'];
try {
    (new SelfDataGuard($overwritten, $blindKey))->register('user-upsert', 'first-insert-0001');
    ko('register() reported a vault the storage no longer holds');
} catch (RuntimeException $e) {
    str_contains($e->getMessage(), 'holds another vault than the one written')
        ? ok('register(): an insert overwritten before its read-back is detected by its salt')
        : ko('wrong failure', $e->getMessage());
}

/** An update that reports the next revision and writes nothing. */
final class UpdateDroppingStorage extends DelegatingStorage
{
    public function updateVault(VaultRecord $r): int { return $r->revision + 1; }
}

$dropping = new SelfDataGuard(new UpdateDroppingStorage($storage), $blindKey);
$droppedSession = $dropping->register('user-dropped', 'dropped-password-01');
try {
    $dropping->setLock($droppedSession, Lock::Password, 'dropped-password-02');
    ko('a password change the storage never wrote was reported as stored');
} catch (RuntimeException $e) {
    str_contains($e->getMessage(), 'did not persist revision 1')
        ? ok('changePassword(): an update reported but never written is detected by its revision')
        : ko('wrong failure', $e->getMessage());
}

// -----------------------------------------------------------------------------

section('A re-enrolment landing between the check and the write');

/** Runs one re-enrolment of the userId right before the next field write reaches the storage. */
final class RacingStorage extends DelegatingStorage
{
    public ?Closure $before = null;
    public function saveFields(VaultGeneration $g, array $f): void
    {
        if ($this->before !== null) {
            ($this->before)();
            $this->before = null;
        }
        $this->inner->saveFields($g, $f);
    }
}

$racing = new RacingStorage($storage);
$dgRace = new SelfDataGuard($racing, $blindKey);
$oldSession = $dgRace->register('user-race', 'race-password-0001', 'race-memorized');
$newSession = null;
$racing->before = static function () use ($dgRace, &$newSession): void {
    $newSession = $dgRace->reEnroll($dgRace->generationOf('user-race'), 'race-password-0002', 'race-memorized-2')['unlocked'];
};
try {
    $dgRace->setFields($oldSession, ['note' => 'written with the old key']);
    ko('a field encrypted under the old key was written into the new vault');
} catch (StaleVaultException) {
    ok('the write itself refuses the old session once the vault was replaced in between');
}
try {
    $dgRace->getFields($newSession) === []
        ? ok('the new vault stays readable and empty')
        : ko('the new vault holds a field it did not write');
} catch (RuntimeException $e) {
    ko('the new vault is corrupted', $e->getMessage());
}

// -----------------------------------------------------------------------------

section('The escrow wrap context is not a field name');

// Private fields and the escrow user-wrap are both sealed under the master key;
// a field named after the wrap's tag would share its AAD.
$reserve = $dg->register('reserved-name', 'mot de passe réservé', str_repeat('e1', 32));
try {
    $dg->setFields($reserve, ['escrow' => 'anything']);
    ko('a field named "escrow" was written');
} catch (InvalidArgumentException) {
    ok('writing a field named "escrow" is refused');
}

// What someone with write access to the database would plant: a ciphertext
// sealed under the master key in the escrow wrap's context, as a field row.
// A v2 blob under the flat AAD "<user>|escrow", which a v2 escrow user-wrap
// still carries: a field row named "escrow" would share it.
$planted = blobV2(
    'escrow key bytes',
    heldKey($reserve),
    'reserved-name' . \Pierroons\SelfDataGuard\Escrow\EscrowVault::WRAP_AAD_SUFFIX
)->toBase64();
(new PDO("sqlite:{$dbPath}"))->prepare(
    'INSERT OR REPLACE INTO selfdataguard_fields (user_id, field_name, ciphertext, blind_index, updated_at)
     VALUES (?, ?, ?, NULL, ?)'
)->execute(['reserved-name', 'escrow', $planted, gmdate('c')]);
try {
    $leaked = $dg->getFields($reserve, ['escrow']);
    ko('a planted "escrow" row was decrypted and returned', 'fields: ' . implode(',', array_keys($leaked)));
} catch (InvalidArgumentException) {
    ok('a planted "escrow" row asked for by name is refused, not decrypted');
}
// The same plant in the 0.7.0 format, under the wrap's own v3 binding: the name
// is refused before any AAD is built, so the format of the planted row is moot.
$plantedV3 = Primitives::encrypt(
    'escrow key bytes',
    heldKey($reserve),
    \Pierroons\SelfDataGuard\Escrow\EscrowVault::wrapAad('reserved-name')
)->toBase64();
(new PDO("sqlite:{$dbPath}"))->prepare(
    'INSERT OR REPLACE INTO selfdataguard_fields (user_id, field_name, ciphertext, blind_index, updated_at)
     VALUES (?, ?, ?, NULL, ?)'
)->execute(['reserved-name', 'escrow', $plantedV3, gmdate('c')]);
try {
    $dg->getFields($reserve, ['escrow']);
    ko('a planted v3 "escrow" row was decrypted and returned');
} catch (InvalidArgumentException) {
    ok('a planted v3 "escrow" row asked for by name is refused too');
}
$dg->setFields($reserve, ['note' => 'une note']);
try {
    $tous = $dg->getFields($reserve);
    $tous === ['note' => 'une note']
        ? ok('a read of every field leaves the reserved row out, undecrypted, and returns the others')
        : ko('a read of every field returned the reserved row', implode(',', array_keys($tous)));
} catch (InvalidArgumentException $e) {
    ko('getFields() refused the whole account for one reserved row', $e->getMessage());
}
try {
    ['unlocked' => $neuf, 'archiveId' => $idReserve] = $dg->reEnroll($dg->generationOf('reserved-name'), 'mot de passe réservé 2');
    $lu = $dg->readArchive($dg->openArchive($neuf, $idReserve, Lock::Password, 'mot de passe réservé'));
    $lu['private'] === ['note' => 'une note']
        ? ok('an archive holding the reserved row still restores the other fields')
        : ko('the archive returned the reserved row', implode(',', array_keys($lu['private'])));
} catch (Throwable $e) {
    ko('one reserved row blocks the restore of the whole archive', $e->getMessage());
}

section('The blind-index key does not ride an exception trace');

/**
 * The arguments a stack trace keeps for the frame that called `$function`, or
 * null when the runtime drops them. `zend.exception_ignore_args` is On in a
 * production php.ini: left alone, the check below would read an empty frame and
 * pass on nothing, so the setting is forced off and the frame must carry the
 * public argument before the secret one is looked for.
 */
function traceArgs(callable $call, string $function): ?array
{
    ini_set('zend.exception_ignore_args', '0');
    try {
        $call();
    } catch (Throwable $e) {
        foreach ($e->getTrace() as $frame) {
            if (($frame['function'] ?? '') === $function) {
                return $frame['args'] ?? null;
            }
        }
    }

    return null;
}

$tooShort = 'short-blind-key-in-a-trace';
$args = traceArgs(static fn () => new SelfDataGuard($storage, $tooShort), '__construct');
if ($args === null || !in_array($storage, $args, true)) {
    ko('precondition: the trace does not show the public argument — nothing below would be measured');
} elseif (PHP_VERSION_ID < 80200) {
    // #[\SensitiveParameter] only takes effect from PHP 8.2; the library runs on 8.1.
    echo '  ↷ PHP ' . PHP_VERSION . ': #[\SensitiveParameter] only takes effect from 8.2 — '
        . "on this runtime the trace still carries blindKey (declared limit, not counted)\n";
} else {
    !in_array($tooShort, $args, true)
        ? ok('the facade constructor hides blindKey from the trace')
        : ko('blindKey is readable in the trace of a refused constructor');
}
$dumpedFacade = print_r($dg, true);
str_contains($dumpedFacade, 'blindKey')
    ? ok('precondition: the facade dump shows its masked field')
    : ko('precondition: the facade dump does not show blindKey at all — the check below would measure nothing');
!str_contains($dumpedFacade, $blindKey)
    ? ok('print_r() of the facade does not carry blindKey')
    : ko('blindKey is readable in a dump of the facade');
$args = traceArgs(static fn () => BlindIndex::compute('a value', $tooShort, 'email'), 'compute');
if ($args === null || !in_array('email', $args, true)) {
    ko('precondition: the trace does not show the field name — nothing below would be measured');
} elseif (PHP_VERSION_ID < 80200) {
    // #[\SensitiveParameter] only takes effect from PHP 8.2; the library runs on 8.1.
    echo '  ↷ PHP ' . PHP_VERSION . ': #[\SensitiveParameter] only takes effect from 8.2 — '
        . "on this runtime the trace still carries blindKey (declared limit, not counted)\n";
} else {
    !in_array($tooShort, $args, true)
        ? ok('BlindIndex::compute() hides blindKey from the trace')
        : ko('blindKey is readable in the trace of BlindIndex::compute()');
}

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Phase 5 Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
