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
require_once __DIR__ . '/pepper_fixture.php';
require __DIR__ . '/key_fixture.php';
require __DIR__ . '/blob_v2_fixture.php';
require __DIR__ . '/storage_doubles.php';
require __DIR__ . '/ceremony_fixture.php';
require __DIR__ . '/keeper_fixture.php';

use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Escrow\EscrowAnnouncedException;
use Pierroons\SelfDataGuard\Escrow\EscrowEnvelopeMismatchException;
use Pierroons\SelfDataGuard\Fields\AmbiguousLookupException;
use Pierroons\SelfDataGuard\Fields\BlindIndex;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\DuplicateIndexException;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Storage\TicketAlreadyAppliedException;
use Pierroons\SelfDataGuard\Storage\UnreadableEscrowException;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\ProfileSeal;
use Pierroons\SelfDataGuard\Vault\StaleVaultException;
use Pierroons\SelfDataGuard\Vault\UserVault;
use Pierroons\SelfDataGuard\Vault\VaultExistsException;
use Pierroons\SelfDataGuard\Vault\VaultFrozenException;
use Pierroons\SelfDataGuard\Vault\VaultGeneration;
use Pierroons\SelfDataGuard\Vault\VaultNotFoundException;
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
$dg       = new SelfDataGuard($storage, $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());

ok('SelfDataGuard façade constructed');

// blindKey too short
try {
    new SelfDataGuard($storage, 'short', pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
    ko('façade accepts short blindKey');
} catch (InvalidArgumentException) {
    ok('façade rejects short blindKey');
}

// -----------------------------------------------------------------------------

section('Register + initial fields');

$session = $dg->register('user-cosmo', 'correct horse battery staple', hash('sha256', 'sunset-river-marble'));
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

$sessionRec = $dg->loginWithMemorized('user-cosmo', hash('sha256', 'sunset-river-marble'));
$recPlain = $dg->getFields($sessionRec);
$recPlain['email'] === 'alice@example.com'
    ? ok('memorized recovery unlocks SAME data')
    : ko('memorized recovery returned different data');

// Wrong memorized
try {
    $dg->loginWithMemorized('user-cosmo', hash('sha256', 'wrong-secret'));
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

$dg->setLock($sessionNew, Lock::Memorized, hash('sha256', 'autumn-leaves-quiet'));

$sessionMem = $dg->loginWithMemorized('user-cosmo', hash('sha256', 'autumn-leaves-quiet'));
$plain3 = $dg->getFields($sessionMem);
$plain3['email'] === 'alice@example.com'
    ? ok('after changeMemorized, recovery flow still works')
    : ko('memorized rotation broke recovery');

// Remove memorized recovery
$dg->removeLock($sessionMem, Lock::Memorized);
try {
    $dg->loginWithMemorized('user-cosmo', hash('sha256', 'autumn-leaves-quiet'));
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
$s = $dg->register('user-rec', 'rec-password-0001', hash('sha256', 'rec-memorized'), $PH1);
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
$dg->recover('user-rec', Lock::Memorized, hash('sha256', 'rec-memorized'), 'rec-password-0003', $PH3);
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
benchAdminRecorded($storage, $admin['publicKey']);
$stale = $dg->register('user-gen', 'gen-password-0001', hash('sha256', 'gen-memorized'));
$dg->setFields($stale, ['a' => '1']);
$dg->delete($stale->generation());
$fresh = $dg->register('user-gen', 'gen-password-0002', hash('sha256', 'gen-memorized'));

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
$l3old = $dg->register('user-l3', 'l3-password-0001', hash('sha256', 'l3-memorized'), $PH1);
$dg->setFields($l3old, ['email' => $EMAIL, 'note' => 'ancienne note'], indexed: ['email']);
$dg->setEscrowFields($l3old, $admin['publicKey'], ['contact' => 'contact de secours']);

$replaced = $dg->generationOf('user-l3');
$l3 = $dg->reEnroll($replaced, 'l3-password-0002', hash('sha256', 'l3-memorized-new'), $PH2);
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
try {
    ($dg->readArchive($dg->openArchive($current, $archiveId, Lock::Passphrase, $PH1))['private'] ?? []) !== []
        ? ok('a profile changed in the archive package: the archive opens under the profile its seal vouches for')
        : ko('the archive opened under the vouched profile, and read nothing');
} catch (Throwable $e) {
    ko('a profile changed in the archive package closed the archive, though its seal vouches for the true one', $e->getMessage());
}

// An archive written by this version is sealed: one without its seal had it removed.
$archiveSeal = (string) $pdoForProfile->query(
    "SELECT json_extract(package, '$.vault.profile_seal') FROM selfdataguard_archives WHERE archive_id = '{$archiveId}'"
)->fetchColumn();
$setArchiveSeal = static fn (?string $seal) => $pdoForProfile->prepare(
    'UPDATE selfdataguard_archives SET package = json_set(package, \'$.vault.profile_seal\', :s) WHERE archive_id = :id'
)->execute([':s' => $seal, ':id' => $archiveId]);
$setArchiveSeal(null);
try {
    $dg->openArchive($current, $archiveId, Lock::Passphrase, $PH1);
    ko('an archive of this version opened without its seal, as one from before 0.7.0 would');
} catch (VaultTamperedException $e) {
    str_contains($e->getMessage(), 'removed')
        ? ok('an archive of this version whose seal was removed is tampering, not an archive from before 0.7.0')
        : ko('refused, but not as a removed seal', $e->getMessage());
} catch (Throwable $e) {
    ko('an archive of this version opened without its seal, as one from before 0.7.0 would', get_class($e));
}
$setArchiveSeal($archiveSeal);
$setOps(Primitives::ARGON2_OPSLIMIT);
$dg->readArchive($dg->openArchive($current, $archiveId, Lock::Passphrase, $PH1))['private'] !== []
    ? ok('the profile and the seal put back, the archive opens again')
    : ko('the archive did not reopen once its profile and seal were put back');

$archivedRecov = (string) $pdoForProfile->query(
    "SELECT json_extract(package, '$.vault.wrap_recov') FROM selfdataguard_archives WHERE archive_id = '{$archiveId}'"
)->fetchColumn();
$setArchiveRecov = static fn (?string $wrap) => $pdoForProfile->prepare(
    'UPDATE selfdataguard_archives SET package = json_set(package, \'$.vault.wrap_recov\', :w) WHERE archive_id = :id'
)->execute([':w' => $wrap, ':id' => $archiveId]);
$listed = static fn (): array => array_column($dg->listArchives($current), 'locks', 'id')[$archiveId] ?? [];
$archivedRecov !== '' && in_array(Lock::Memorized, $listed(), true)
    ? ok('fixture: the archive holds a memorized lock, and the listing says so')
    : ko('fixture: no memorized lock in the archive — the checks below would prove nothing');
$setArchiveRecov(null);
!in_array(Lock::Memorized, $listed(), true)
    ? ok('the listing reads the locks from the archive itself: an envelope removed no longer shows')
    : ko('the listing still announces an envelope the archive no longer holds');
try {
    $dg->openArchive($current, $archiveId, Lock::Passphrase, $PH1);
    ko('an archive opened with an envelope removed from it');
} catch (VaultTamperedException) {
    ok('an envelope removed from an archive is tampering: the archive does not open by another lock around it');
}
$setArchiveRecov($archivedRecov);

// -----------------------------------------------------------------------------

section('The admin reaches the archived escrow; deletion is explicit');

benchCeremony($storage)->readArchiveEscrow(
    'user-l3', $archiveId, 'case-l3', $admin['sealedSecret'], 'admin passphrase for the facade bench', $admin['publicKey']
, BENCH_ESCROW_FIELDS) === ['contact' => 'contact de secours']
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
    public function replaceWithArchive(VaultGeneration $g, VaultRecord $r, ?string $t = null): string { return $this->inner->replaceWithArchive($g, $r->withWrapPhrase(null, $r->updatedAt), $t); }
}

$forgetful = new SelfDataGuard(new ForgetfulStorage($storage), $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
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
            kdfOpslimit: $r->kdfOpslimit + 1, kdfMemlimit: $r->kdfMemlimit, profileSeal: $r->profileSeal
        );
    }
}

try {
    (new SelfDataGuard(new ProfileLosingStorage($storage), $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle()))
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
            wrapPhrase: $r->wrapPhrase, revision: $r->revision, profileSeal: $r->profileSeal
        );
    }
}

try {
    (new SelfDataGuard(new ProfileBlindStorage($storage), $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle()))
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
$sealed  = $dg->register('user-seal', $PW_SEAL, hash('sha256', 'seal-memorized'), $PH1);
$dg->setFields($sealed, ['note' => 'sous sceau']);
$pdoSeal = new PDO("sqlite:{$dbPath}");
$setRow  = static fn (string $set) => $pdoSeal->exec("UPDATE selfdataguard_vaults SET {$set} WHERE user_id = 'user-seal'");
$sealBefore = (string) $pdoSeal->query("SELECT profile_seal FROM selfdataguard_vaults WHERE user_id = 'user-seal'")->fetchColumn();
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
    $refusal(static fn () => $dg->loginWithMemorized('user-seal', hash('sha256', 'seal-memorized'))),
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
try {
    $dg->sealProfile('user-seal');
    ko('sealProfile() sealed a vault of this version whose seal was removed: it would vouch for whatever the row now says');
} catch (VaultTamperedException $e) {
    $refusal(static fn () => $dg->loginWithPassword('user-seal', $PW_SEAL)) === 'tampered' && str_contains($e->getMessage(), 'removed')
        ? ok('a vault of this version without its seal opens to no one, and sealProfile() refuses it: the seal was removed, not never set')
        : ko('a removed seal was not reported as such', $e->getMessage());
}
$setRow("profile_seal = '{$sealBefore}'");
($dg->getFields($dg->loginWithPassword('user-seal', $PW_SEAL))['note'] ?? null) === 'sous sceau'
    ? ok('the seal put back from a backup, the vault opens as before')
    : ko('the vault did not reopen with its seal put back');

$phraseBefore = (string) $pdoSeal->query("SELECT wrap_phrase FROM selfdataguard_vaults WHERE user_id = 'user-seal'")->fetchColumn();
$pdoSeal->exec("UPDATE selfdataguard_vaults SET wrap_phrase = NULL WHERE user_id = 'user-seal'");
$refusal(static fn () => $dg->loginWithPassword('user-seal', $PW_SEAL)) === 'tampered'
    ? ok('an envelope removed in the database is tampering: the password, untouched, does not open around it')
    : ko('an envelope was removed and the vault still opened, or failed as another error');
$otherWrap = (string) $pdoSeal->query("SELECT wrap_pwd FROM selfdataguard_vaults WHERE user_id = 'user-seal-other'")->fetchColumn();
$pdoSeal->prepare("UPDATE selfdataguard_vaults SET wrap_phrase = ? WHERE user_id = 'user-seal'")->execute([$otherWrap]);
$refusal(static fn () => $dg->loginWithPassword('user-seal', $PW_SEAL)) === 'tampered'
    ? ok('an envelope brought from another account is tampering, before any derivation')
    : ko('an envelope from another account went unnoticed by the seal');
$pdoSeal->prepare("UPDATE selfdataguard_vaults SET wrap_phrase = ? WHERE user_id = 'user-seal'")->execute([$phraseBefore]);

$open = $dg->loginWithPassword('user-seal', $PW_SEAL);
$setRow('kdf_opslimit = ' . (Primitives::ARGON2_OPSLIMIT + 1));
try {
    $dg->setLock($open, Lock::Password, 'seal-password-0003');
    ko('a re-seal went ahead on a profile changed under an open session');
} catch (VaultTamperedException) {
    ok('a profile changed under an open session stops the re-seal: nothing is sealed under a profile the session never proved');
}
$dg->repairProfile('user-seal');

$setRow('kdf_opslimit = ' . (Primitives::ARGON2_OPSLIMIT + 1));
$archivesBefore = count($storage->listArchives('user-seal'));
try {
    $dg->reEnroll($dg->generationOf('user-seal'), 'seal-password-l3-01');
    ko('a level 3 archived a vault whose seal does not hold: nothing could repair that archive');
} catch (VaultTamperedException) {
    count($storage->listArchives('user-seal')) === $archivesBefore
        ? ok('a level 3 on a vault whose seal does not hold is refused before anything is archived: repairProfile() first')
        : ko('the refused level 3 archived the vault anyway');
}
$dg->repairProfile('user-seal');
$setRow('profile_seal = NULL');
try {
    $dg->reEnroll($dg->generationOf('user-seal'), 'seal-password-l3-01');
    ko('a level 3 archived a vault without a seal');
} catch (VaultTamperedException $e) {
    str_contains($e->getMessage(), 'removed')
        ? ok('a level 3 on a vault whose seal was removed is refused the same way')
        : ko('refused, without naming the removed seal', $e->getMessage());
}
$setRow("profile_seal = '{$sealBefore}'");
$l3 = $dg->reEnroll($dg->generationOf('user-seal'), 'seal-password-l3-01');
($dg->readArchive($dg->openArchive($l3['unlocked'], $l3['archiveId'], Lock::Password, $PW_SEAL))['private']['note'] ?? null) === 'sous sceau'
    ? ok('sealed again, the level 3 goes through, and its archive opens under its seal')
    : ko('the archive of a sealed vault did not open');

$sealRecord = $storage->loadVault('user-seal');
$sealed     = (new ReflectionMethod(ProfileSeal::class, 'message'))->invoke(null, $sealRecord, 4, 1 << 28);
base64_decode(BlindIndex::compute($sealed, $blindKey, 'selfdataguard/profile-seal'))
    !== ProfileSeal::of(ProfileSeal::key($blindKey), $sealRecord, 4, 1 << 28)
    ? ok('the blind index of a field named after the seal domain is not a seal: the application cannot be made to compute one')
    : ko('a blind index the application computes is a valid profile seal');
BlindIndex::keyCheck($blindKey) !== base64_encode(hash_hmac('sha256', 'selfdataguard/blind-key-check', $blindKey, true))
    ? ok('the key check is not the key BlindIndex derives for a field of its name: the database holds no field key')
    : ko('the key check stored in the database is the field key of a field named after it');

// -----------------------------------------------------------------------------

section('A deleted account keeps its archives, and its name until they are erased');

$reused = $dg->register('user-reuse', 'reuse-password-0001', null, $PH1);
$dg->setFields($reused, ['iban' => 'FR76 PREMIER']);
$l3r = $dg->reEnroll($dg->generationOf('user-reuse'), 'reuse-password-0002');
$dg->delete($l3r['unlocked']->generation());
try {
    $dg->register('user-reuse', 'reuse-password-tiers');
    ko('a name that still holds archives was registered again: they would open to the new holder with an old secret');
} catch (VaultExistsException $e) {
    str_contains($e->getMessage(), 'eraseAccount()')
        ? ok('a name that still holds archives is not registered again, and the refusal names the operator\'s act')
        : ko('refused, without naming the remedy', $e->getMessage());
}
$dg->eraseAccount('user-reuse') === 1
    && $dg->listArchives($dg->register('user-reuse', 'reuse-password-tiers')) === []
    ? ok('once the operator erases them, the name registers again, with no archive behind it')
    : ko('the name did not register again after eraseAccount(), or archives were left');

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

// A field is shared or not for the whole database: the façade that shares
// 'phone' opens a database of its own.
$sharedStorage = new SqliteAdapter('sqlite::memory:');
$dgShared = new SelfDataGuard($sharedStorage, $blindKey, sharedIndexes: ['phone'], pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
$sharedOwner = $dgShared->register('user-owner', 'owner-password-0001');
$sharedOther = $dgShared->register('user-squat', 'squat-password-0001');
$dgShared->setFields($sharedOwner, ['phone' => '+33 6 00 00 00 01'], indexed: ['phone']);
$dgShared->setFields($sharedOther, ['phone' => '+33 6 00 00 00 01'], indexed: ['phone']);
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

try {
    new SelfDataGuard($storage, $blindKey, sharedIndexes: ['phone'], pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
    ko('a façade that shares a field opened a database where that field names one account');
} catch (InvalidArgumentException $e) {
    str_contains($e->getMessage(), 'sharedIndexes')
        ? ok('a façade given other shared fields than the database records is refused: one field, one rule')
        : ko('refused for another reason', $e->getMessage());
}
$twoShared = new SqliteAdapter('sqlite::memory:');
new SelfDataGuard($twoShared, $blindKey, sharedIndexes: ['phone', 'address'], pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
new SelfDataGuard($twoShared, $blindKey, sharedIndexes: ['address', 'phone'], pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle()) instanceof SelfDataGuard
    ? ok('the same shared fields in another order are the same list')
    : ko('the order of the shared fields was taken for another list');
try {
    new SelfDataGuard($storage, Primitives::randomBytes(32), pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
    ko('a façade opened a database under another blind-index key: every vault would report tampering');
} catch (InvalidArgumentException $e) {
    str_contains($e->getMessage(), 'not the one this database recorded')
        ? ok('another blind-index key is refused when the façade is built, named as such — not as tampering, vault after vault')
        : ko('another key refused for another reason', $e->getMessage());
}

/** A storage holding a value under two accounts, as a database written before 0.7.0 can. */
final class DoubledIndexStorage extends DelegatingStorage
{
    public function findUserIdsByIndex(string $f, string $i): array { return ['user-a', 'user-b']; }
}

try {
    (new SelfDataGuard(new DoubledIndexStorage($storage), $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle()))->findUserByField('email', 'x@example.org');
    ko('the lookup picked one of two accounts');
} catch (AmbiguousLookupException) {
    ok('findUserByField() refuses to pick when two accounts hold the value');
}

$ownerL3 = $dg->reEnroll($dg->generationOf('user-owner'), 'owner-password-0002');
try {
    $dg->setFields($squat, ['email' => 'owner@example.org'], indexed: ['email']);
    ko('another account took a value the owner\'s archive keeps');
} catch (DuplicateIndexException $e) {
    $e->fields === ['email']
        ? ok('during the level 3, the archive keeps the owner\'s value: another account indexing it is refused, as for a value held live')
        : ko('refused, naming another field', implode(',', $e->fields));
}
$restored = $dg->readArchive($dg->openArchive($ownerL3['unlocked'], $ownerL3['archiveId'], Lock::Password, 'owner-password-0001'));
try {
    $dg->setFields($ownerL3['unlocked'], $restored['private'], indexed: ['email']);
    $dg->findUserByField('email', 'owner@example.org') === 'user-owner'
        ? ok('a restore through readArchive() indexes the value again, under the owner\'s account')
        : ko('the restore did not index the value under the owner\'s account');
} catch (DuplicateIndexException $e) {
    ko('the restore through readArchive() was refused the value its own archive keeps', implode(',', $e->fields));
}

// -----------------------------------------------------------------------------

section('A database that holds its settings is read, not written, when a façade opens it');

$roPath = sys_get_temp_dir() . '/sdg-readonly-' . bin2hex(random_bytes(4)) . '.sqlite';
copy($dbPath, $roPath);
chmod($roPath, 0444);
register_shutdown_function(static function () use ($roPath): void {
    @chmod($roPath, 0644);
    @unlink($roPath);
});
$roStore = new SqliteAdapter("sqlite:{$roPath}");
try {
    (new SelfDataGuard($roStore, $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle()))->userExists('user-owner')
        ? ok('a read-only copy of a database that recorded its key opens, and reads')
        : ko('the read-only copy opened and lost an account');
} catch (Throwable $e) {
    ko('a read-only copy of a database that recorded its key does not open', $e->getMessage());
}
try {
    $roStore->claimSetting(SelfDataGuard::SETTING_BLIND_KEY, 'another value') === BlindIndex::keyCheck($blindKey)
        ? ok('claimSetting() on a database that holds the value reads it, and writes nothing')
        : ko('claimSetting() returned another value than the one held');
} catch (Throwable $e) {
    ko('claimSetting() wrote to a database that already held the value', $e->getMessage());
}
$noClaim = new class ($storage) extends DelegatingStorage {
    public function claimSetting(string $n, string $v): string
    {
        throw new RuntimeException("claimed {$n}");
    }
};
try {
    new SelfDataGuard($noClaim, $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
    ok('a façade on a database that holds its settings claims nothing: it reads them');
} catch (RuntimeException $e) {
    ko('a façade claimed a setting the database already holds', $e->getMessage());
}
$writer = new PDO("sqlite:{$dbPath}");
$writer->exec('BEGIN IMMEDIATE');
$host = new PDO("sqlite:{$dbPath}");
$host->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$host->beginTransaction();
$host->query('SELECT 1 FROM selfdataguard_vaults LIMIT 1')->fetchAll();
try {
    (new SelfDataGuard(new SqliteAdapter($host), $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle()))->userExists('user-owner')
        ? ok('a façade built inside a host transaction, while another connection writes, opens at once')
        : ko('the façade built inside a host transaction lost an account');
} catch (Throwable $e) {
    ko('a façade built inside a host transaction fell under another writer', $e->getMessage());
} finally {
    $host->rollBack();
    $writer->exec('ROLLBACK');
}

section('The read-back compares the salt, the revision and every wrap; the userId is echoed');

/** A storage with a case-insensitive user_id: asked for 'USER-CI', it answers with 'user-ci'. */
final class CaseFoldingStorage extends DelegatingStorage
{
    public function loadVault(string $u): VaultRecord { return $this->inner->loadVault(strtolower($u)); }
}

$folding = new SelfDataGuard(new CaseFoldingStorage($storage), $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
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
    (new SelfDataGuard($overwritten, $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle()))->register('user-upsert', 'first-insert-0001');
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

$dropping = new SelfDataGuard(new UpdateDroppingStorage($storage), $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
$droppedSession = $dropping->register('user-dropped', 'dropped-password-01');
try {
    $dropping->setLock($droppedSession, Lock::Password, 'dropped-password-02');
    ko('a password change the storage never wrote was reported as stored');
} catch (RuntimeException $e) {
    str_contains($e->getMessage(), 'did not persist revision 1')
        ? ok('setLock(): an update reported but never written is detected by its revision')
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
$dgRace = new SelfDataGuard($racing, $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
$oldSession = $dgRace->register('user-race', 'race-password-0001', hash('sha256', 'race-memorized'));
$newSession = null;
$racing->before = static function () use ($dgRace, &$newSession): void {
    $newSession = $dgRace->reEnroll($dgRace->generationOf('user-race'), 'race-password-0002', hash('sha256', 'race-memorized-2'))['unlocked'];
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
$args = traceArgs(static fn () => new SelfDataGuard($storage, $tooShort, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle()), '__construct');
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

section('reinstateArchive() — the holder comes back, the requester\'s vault waits as an archive');

$holderSession = $dg->register('user-back', 'back-password-0001', hash('sha256', 'back-memorized'), 'pivert lagune ardoise tambour');
$dg->setFields($holderSession, ['email' => 'back@example.org', 'note' => 'du titulaire'], indexed: ['email']);
$taken = $dg->reEnroll($holderSession->generation(), 'back-password-0002');
$dg->setFields($taken['unlocked'], ['note' => 'du demandeur']);
$noneLost = ['untouched'];
$setAside = $dg->reinstateArchive($taken['unlocked']->generation(), $taken['archiveId'], null, $noneLost);
$back = $dg->loginWithPassword('user-back', 'back-password-0001');
$dg->getFields($back) === ['email' => 'back@example.org', 'note' => 'du titulaire']
    && $dg->findUserByField('email', 'back@example.org') === 'user-back'
    ? ok('the holder\'s old password opens their vault again, fields and index back')
    : ko('the reinstated vault does not read as the holder left it');
$noneLost === []
    ? ok('$restoredWithoutIndex is empty: every index came back')
    : ko('$restoredWithoutIndex is not an empty list when every index came back', var_export($noneLost, true));
try {
    $dg->setFields($taken['unlocked'], ['note' => 'encore du demandeur']);
    ko('a session on the requester\'s vault still writes after the swap');
} catch (StaleVaultException) {
    ok('the requester\'s session is stale: it writes nowhere');
}
array_column($dg->listArchives($back), 'id') === [$setAside]
    ? ok('the requester\'s vault is the account\'s only archive')
    : ko('the archives after reinstateArchive() are wrong');

section('An archive whose seal does not hold is not brought back');

$sealed = $dg->register('user-badseal', 'badseal-password-01');
$re = $dg->reEnroll($sealed->generation(), 'badseal-password-02');
$raw = new PDO("sqlite:{$dbPath}");
$raw->prepare("UPDATE selfdataguard_archives SET package = json_set(package, '$.vault.kdf_opslimit', 5) WHERE archive_id = :id")
    ->execute([':id' => $re['archiveId']]);
try {
    $dg->reinstateArchive($re['unlocked']->generation(), $re['archiveId']);
    ko('an archive whose profile was changed came back live');
} catch (VaultTamperedException) {
    hash_equals($re['unlocked']->vaultSalt, $storage->loadVault('user-badseal')->userSalt)
        && $storage->loadArchive('user-badseal', $re['archiveId']) !== null
        ? ok('VaultTamperedException, and nothing swapped')
        : ko('a refused reinstatement changed something');
}

section('freeze() — the vault reads, every write refuses, until unfreeze()');

$g = $back->generation();
$dg->freeze($g, 'arbitre') ? ok('freeze() freezes') : ko('freeze() did not freeze');
($dg->frozenState('user-back')['by'] ?? null) === 'arbitre'
    ? ok('frozenState() says who froze it')
    : ko('frozenState() does not report the freeze');
$reading = $dg->loginWithPassword('user-back', 'back-password-0001');
$dg->getFields($reading)['note'] === 'du titulaire'
    ? ok('a frozen vault opens and reads')
    : ko('a frozen vault no longer reads');
foreach ([
    'setFields'  => static fn () => $dg->setFields($reading, ['note' => 'pendant le gel']),
    'setLock'    => static fn () => $dg->setLock($reading, Lock::Password, 'back-password-0003'),
    'removeLock' => static fn () => $dg->removeLock($reading, Lock::Passphrase),
    'reEnroll'   => static fn () => $dg->reEnroll($g, 'back-password-0004'),
    'reinstateArchive' => static fn () => $dg->reinstateArchive($g, $setAside),
    'delete'     => static fn () => $dg->delete($g),
] as $write => $call) {
    try {
        $call();
        ko("{$write}() wrote into a frozen vault");
    } catch (VaultFrozenException) {
        ok("{$write}() → VaultFrozenException");
    } catch (Throwable $e) {
        ko("{$write}() refused otherwise than as frozen", get_class($e) . ': ' . $e->getMessage());
    }
}
$dg->getFields($dg->loginWithPassword('user-back', 'back-password-0001'))['note'] === 'du titulaire'
    && count($dg->listArchives($reading)) === 1
    ? ok('nothing changed: the old password still opens it, the archive is there')
    : ko('a refused write changed the frozen vault');

section('Under a freeze, a lock is re-sealed only from an opening by the memorized secret');

// Whoever held the account before the holder came back holds its password and
// its passphrase, never the holder's memorized secret.
$backMot   = hash('sha256', 'back-memorized');
$frozenAt  = $dg->frozenState('user-back');
$untouched = static fn (): array => [$storage->loadFields('user-back'), $storage->loadEscrowFields('user-back')];
$fieldsBefore = $untouched();
$revBefore    = $storage->loadVault('user-back')->revision;
foreach (['the password' => 'back-password-0001', 'the passphrase' => 'pivert lagune ardoise tambour'] as $what => $stolen) {
    try {
        $dg->setLockWhileFrozen('user-back', $stolen, Lock::Memorized, hash('sha256', 'stolen-memorized'));
        ko("{$what}, given as the memorized secret, re-sealed a lock under the freeze");
    } catch (WrongSecretException) {
        $storage->loadVault('user-back')->revision === $revBefore
            ? ok("{$what}, given as the memorized secret: WrongSecretException, nothing written")
            : ko("{$what} was refused, but the vault changed");
    }
}
$opened = $dg->setLockWhileFrozen('user-back', $backMot, Lock::Password, 'back-password-0005');
try {
    $dg->loginWithPassword('user-back', 'back-password-0001');
    $oldRefused = false;
} catch (WrongSecretException) {
    $oldRefused = true;
}
$dg->getFields($dg->loginWithPassword('user-back', 'back-password-0005'))['note'] === 'du titulaire'
    && $oldRefused && $opened->userId === 'user-back'
    && $storage->loadVault('user-back')->revision === $revBefore + 1
    ? ok('by the memorized secret, the password is re-sealed: the new one opens and reads, the old one no longer opens')
    : ko('the password was not re-sealed under the freeze as asked');
$untouched() === $fieldsBefore && $dg->frozenState('user-back') == $frozenAt
    ? ok('only the locks were written: fields, escrow and the freeze — who, when — as they were')
    : ko('re-sealing a lock under the freeze wrote something else');
$dg->setLockWhileFrozen('user-back', $backMot, Lock::Memorized, hash('sha256', 'back-memorized-2'));
try {
    $dg->loginWithMemorized('user-back', $backMot);
    $refusedOld = false;
} catch (WrongSecretException) {
    $refusedOld = true;
}
$refusedOld && $dg->loginWithMemorized('user-back', hash('sha256', 'back-memorized-2'))->userId === 'user-back'
    ? ok('the memorized secret re-seals itself: the new word opens, the old one no longer does')
    : ko('the memorized lock was not re-sealed under the freeze');
$backMot = hash('sha256', 'back-memorized-2');
$revBefore = $storage->loadVault('user-back')->revision;
try {
    $dg->setLockWhileFrozen('user-back', $backMot, Lock::Memorized, 'the word itself');
    ko('a memorized secret that is not a fingerprint was sealed under the freeze');
} catch (InvalidArgumentException) {
    $storage->loadVault('user-back')->revision === $revBefore
        ? ok('the fingerprint rule holds under the freeze: the word itself is refused, nothing written')
        : ko('the word itself was refused, but the vault changed');
}
$dg->setLockWhileFrozen('user-back', $backMot, Lock::Passphrase, 'merle falaise couvreur ardoise');
(new UserVault())->unlockWithPassphrase($storage->loadVault('user-back'), 'merle falaise couvreur ardoise')->userId === 'user-back'
    ? ok('the passphrase is re-sealed the same way')
    : ko('the passphrase was not re-sealed under the freeze');
try {
    $dg->setLock($dg->loginWithMemorized('user-back', $backMot), Lock::Password, 'back-password-0006');
    ko('a session opened by the memorized secret re-sealed through setLock() under the freeze');
} catch (VaultFrozenException) {
    ok('setLock() stays refused under the freeze, whatever lock opened the session');
}
$dg->unfreeze($g) && $dg->frozenState('user-back') === null
    ? ok('unfreeze() lifts it')
    : ko('unfreeze() did not lift the freeze');
$revBefore = $storage->loadVault('user-back')->revision;
try {
    $dg->setLockWhileFrozen('user-back', $backMot, Lock::Password, 'back-password-0007');
    ko('setLockWhileFrozen() wrote into a vault that is not frozen');
} catch (LogicException $e) {
    str_contains($e->getMessage(), 'setLock()') && $storage->loadVault('user-back')->revision === $revBefore
        ? ok('not frozen: refused, nothing written, and the refusal points to setLock()')
        : ko('the refusal of a vault not frozen does not point to setLock(), or something was written', $e->getMessage());
}
$e = null;
try {
    $dg->setLockWhileFrozen('user-back', 'back-password-0005', Lock::Password, 'back-password-0007');
} catch (Throwable $e) {
}
$e instanceof WrongSecretException
    ? ok('without the memorized secret, a vault not frozen answers as a frozen one: a wrong secret — the freeze is not told')
    : ko('the gesture tells whoever lacks the memorized secret whether the vault is frozen', $e === null ? 'nothing thrown' : get_class($e));
$dg->setFields($reading, ['note' => 'après le gel']);
$dg->getFields($reading)['note'] === 'après le gel'
    ? ok('unfrozen, the vault takes writes again')
    : ko('the vault still refuses writes after unfreeze()');

section('restoreArchive() — an account left with archives only gets one back');

$dg->delete($reading->generation());
$dg->restoreArchive('user-back', $setAside);
try {
    $dg->loginWithPassword('user-back', 'back-password-0002');
    ok('the archive is live again: the requester\'s password opens it');
} catch (Throwable $e) {
    ko('restoreArchive() did not bring the archive back', $e->getMessage());
}
try {
    $dg->restoreArchive('user-back', $setAside);
    ko('restoreArchive() went through twice');
} catch (VaultNotFoundException | VaultExistsException) {
    ok('a second restoration is refused');
}

// -----------------------------------------------------------------------------

section('An archive whose escrow does not parse opens, reads and comes back; the holder sees a row rewritten, the ceremony refuses it');

/** Archive $archiveId, in the database $pdo, with its escrow rewritten at $path so that it no longer parses. */
function unparseArchiveEscrow(PDO $pdo, string $archiveId, string $path = '$.escrow.wrap_admin', string $value = "'%%%'"): void
{
    $pdo->prepare("UPDATE selfdataguard_archives SET package = json_set(package, '{$path}', {$value}) WHERE archive_id = :id")
        ->execute([':id' => $archiveId]);
}

$rawDb = new PDO("sqlite:{$dbPath}");
$escBad = $dg->register('user-escbad', 'escbad-password-01', hash('sha256', 'escbad-memorized'), $PH1);
$dg->setFields($escBad, ['note' => 'note du titulaire']);
$dg->setEscrowFields($escBad, $admin['publicKey'], ['contact' => 'contact du titulaire']);
$escBadL3 = $dg->reEnroll($escBad->generation(), 'escbad-password-02');
unparseArchiveEscrow($rawDb, $escBadL3['archiveId']);
$escBadOpened = null;
try {
    $escBadOpened = $dg->openArchive($escBadL3['unlocked'], $escBadL3['archiveId'], Lock::Password, 'escbad-password-01');
    ok('openArchive() opens it: an escrow that does not parse does not close the archive');
} catch (Throwable $e) {
    ko('openArchive() refused the archive for its escrow', get_class($e) . ': ' . $e->getMessage());
}
if ($escBadOpened === null) {
    ko('readArchive() and archiveEscrowReads() on that archive: not reached — it did not open');
} else {
    try {
        $read = $dg->readArchive($escBadOpened);
        $read['private'] === ['note' => 'note du titulaire'] && $read['escrow'] === [] && ($read['escrowUnreadable'] ?? null) === true
            ? ok('readArchive() hands back the private fields, no escrow, and marks the escrow unreadable')
            : ko('readArchive() does not hand back the fields with the escrow marked unreadable', json_encode($read));
    } catch (Throwable $e) {
        ko('readArchive() refused the private fields for the escrow beside them', get_class($e) . ': ' . $e->getMessage());
    }
    try {
        $view = $dg->archiveEscrowReads($escBadOpened);
        !$view['established'] && $view['reason'] === 'escrow-replaced' && !$view['reseal']
            ? ok('archiveEscrowReads() says escrow-replaced, as escrowReads() says of a live row that does not parse')
            : ko('archiveEscrowReads() does not report a row rewritten', json_encode($view));
    } catch (Throwable $e) {
        ko('archiveEscrowReads() refused the view', get_class($e) . ': ' . $e->getMessage());
    }
}

// Under a keeper that holds a read announced for the archive's escrow: the
// administrator writes the database, and makes that escrow unreadable.
$kPdo      = new PDO('sqlite::memory:');
$kStorage  = new SqliteAdapter($kPdo);
benchAdminRecorded($kStorage, $admin['publicKey']);
$kKeeper   = new BenchKeeper(delay: 3600);
$kDg       = new SelfDataGuard($kStorage, $blindKey, keeper: $kKeeper, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
$carol     = $kDg->register('user-carol', 'carol-password-01');
$kDg->setEscrowFields($carol, $admin['publicKey'], ['contact' => 'contact de carol']);
$carolL3   = $kDg->reEnroll($carol->generation(), 'carol-password-02');
try {
    benchCeremony($kStorage, keeper: $kKeeper)->readArchiveEscrow(
        'user-carol', $carolL3['archiveId'], 'case-carol', $admin['sealedSecret'], 'admin passphrase for the facade bench', $admin['publicKey']
    , BENCH_ESCROW_FIELDS);
} catch (EscrowAnnouncedException) {
}
unparseArchiveEscrow($kPdo, $carolL3['archiveId'], '$.escrow.created_at', "'pas une date'");
try {
    $carolOpened = $kDg->openArchive($carolL3['unlocked'], $carolL3['archiveId'], Lock::Password, 'carol-password-01');
    $view = $kDg->archiveEscrowReads($carolOpened);
    $view['reason'] === 'escrow-replaced' && in_array('case-carol', array_column($view['pending'], 'case'), true)
        ? ok('under a keeper, the holder sees the read announced on that escrow, the row named rewritten')
        : ko('the read announced on the archive\'s escrow is hidden from the holder', json_encode($view));
    $kDg->objectToArchiveEscrowRead($carolOpened, 'case-carol');
    array_values(array_filter($kKeeper->pending, static fn (array $p): bool => $p['case'] === 'case-carol'))[0]['objected'] ?? false
        ? ok('objectToArchiveEscrowRead() goes to the keeper, which records the objection')
        : ko('the objection did not reach the keeper');
} catch (Throwable $e) {
    ko('the holder could not see, or object to, the read of an archive whose escrow does not parse', get_class($e) . ': ' . $e->getMessage());
}

// A row that reads but does not open — written over before the level 3, or in
// the package after it — and a field that does not decrypt: the private fields
// come all the same.
$other = $dg->register('user-r1-other', 'r1-other-password-1');
$dg->setEscrowFields($other, $admin['publicKey'], ['contact' => 'contact d\'un autre']);
$otherRow = $rawDb->query("SELECT wrap_user FROM selfdataguard_escrow WHERE user_id = 'user-r1-other'")->fetchColumn();
$otherCt  = $rawDb->query("SELECT ciphertext FROM selfdataguard_escrow_fields WHERE user_id = 'user-r1-other'")->fetchColumn();
foreach ([
    'the live wrap_user written over before the level 3' => 'live',
    'escrow.wrap_user written over in the package'       => ['$.escrow.wrap_user', $rawDb->quote((string) $otherRow)],
    'an escrow field written over in the package'        => ['$.fields.escrow.contact', $rawDb->quote((string) $otherCt)],
] as $form => $edit) {
    $uid = 'user-r1-' . substr(md5($form), 0, 8);
    $r1 = $dg->register($uid, 'r1-password-0001');
    $dg->setFields($r1, ['note' => "note de {$uid}"]);
    $dg->setEscrowFields($r1, $admin['publicKey'], ['contact' => "contact de {$uid}"]);
    if ($edit === 'live') {
        $rawDb->prepare('UPDATE selfdataguard_escrow SET wrap_user = ? WHERE user_id = ?')->execute([$otherRow, $uid]);
    }
    $r1L3 = $dg->reEnroll($dg->generationOf($uid), 'r1-password-0002');
    if (is_array($edit)) {
        unparseArchiveEscrow($rawDb, $r1L3['archiveId'], $edit[0], $edit[1]);
    }
    try {
        $read = $dg->readArchive($dg->openArchive($r1L3['unlocked'], $r1L3['archiveId'], Lock::Password, 'r1-password-0001'));
        $read['private'] === ['note' => "note de {$uid}"] && $read['escrow'] === [] && $read['escrowUnreadable'] === true
            ? ok("{$form}: readArchive() hands back the private fields, no escrow, the escrow marked unreadable")
            : ko("{$form}: readArchive() did not mark the escrow unreadable", json_encode($read));
    } catch (Throwable $e) {
        ko("{$form}: readArchive() refused the private fields for the escrow beside them", get_class($e) . ': ' . $e->getMessage());
    }
}

// The pass of a ceremony records its keeper in the database it seals: it runs on one of its own.
$keeperPdo = new PDO('sqlite::memory:');
$keeperDb  = new SqliteAdapter($keeperPdo);
benchAdminRecorded($keeperDb, $admin['publicKey']);
$keeperDg  = new SelfDataGuard($keeperDb, $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
$escKeeper = $keeperDg->register('user-escbad', 'escbad-password-01');
$keeperDg->setEscrowFields($escKeeper, $admin['publicKey'], ['contact' => 'contact du titulaire']);
$escKeeperL3 = $keeperDg->reEnroll($escKeeper->generation(), 'escbad-password-02');
unparseArchiveEscrow($keeperPdo, $escKeeperL3['archiveId']);

foreach ([
    'the ceremony\'s readArchiveEscrow()' => static fn () => benchCeremony($storage)->readArchiveEscrow(
        'user-escbad', $escBadL3['archiveId'], 'case-escbad', $admin['sealedSecret'], 'admin passphrase for the facade bench', $admin['publicKey']
    , BENCH_ESCROW_FIELDS),
    'the ceremony\'s sealToKeeper()'  => static fn () => benchCeremony($keeperDb, keeper: new BenchKeeper())->sealToKeeper('user-escbad'),
] as $reader => $call) {
    try {
        $call();
        ko("{$reader} read an escrow that does not parse as no escrow");
    } catch (UnreadableEscrowException) {
        ok("{$reader} → UnreadableEscrowException: the administrator reads and seals nothing of it");
    } catch (Throwable $e) {
        ko("{$reader} refused otherwise than as an escrow that does not parse", get_class($e) . ': ' . $e->getMessage());
    }
}

$escBack = null;
try {
    $dg->reinstateArchive($escBadL3['unlocked']->generation(), $escBadL3['archiveId']);
    $escBack = $dg->loginWithPassword('user-escbad', 'escbad-password-01');
    $escReads = $dg->escrowReads($escBack);
    $dg->getFields($escBack) === ['note' => 'note du titulaire'] && $dg->hasEscrow($escBack)
        && !$escReads['established'] && $escReads['reason'] === 'escrow-replaced'
        ? ok('reinstateArchive() brings it back: the holder\'s vault is live, its escrow row read as one rewritten (escrow-replaced)')
        : ko('the vault came back, but its escrow is not reported as rewritten', json_encode($escReads));
    try {
        $dg->getEscrowFieldsAsUser($escBack);
        ko('the escrow fields read under a row that does not parse');
    } catch (EscrowEnvelopeMismatchException) {
        ok('reading its escrow fields raises EscrowEnvelopeMismatchException, as for any row rewritten');
    }
} catch (Throwable $e) {
    ko('an escrow that does not parse kept the holder\'s vault from coming back', get_class($e) . ': ' . $e->getMessage());
}
if ($escBack === null) {
    ko('a deposit on the escrow that came back: not reached — the vault did not come back');
} else {
    $rowBefore = $rawDb->query("SELECT wrap_admin FROM selfdataguard_escrow WHERE user_id = 'user-escbad'")->fetchColumn();
    foreach ([
        'setEscrowFields()' => static fn () => $dg->setEscrowFields($escBack, $admin['publicKey'], ['contact' => 'nouveau contact']),
        'rotateEscrow()'    => static fn () => $dg->rotateEscrow($escBack, $admin['publicKey']),
    ] as $deposit => $call) {
        try {
            $call();
            ko("{$deposit} wrote over an escrow row that does not parse");
        } catch (EscrowEnvelopeMismatchException $e) {
            $rawDb->query("SELECT wrap_admin FROM selfdataguard_escrow WHERE user_id = 'user-escbad'")->fetchColumn() === $rowBefore
                && str_contains($e->getMessage(), 'putting back the row that wrote them')
                ? ok("{$deposit} is refused, nothing written, and the refusal names the way out: the row put back from a backup")
                : ko("{$deposit} refused, but wrote, or does not name the way out", $e->getMessage());
        }
    }
}

$escRest = $dg->register('user-escrest', 'escrest-password-01');
$dg->setEscrowFields($escRest, $admin['publicKey'], ['contact' => 'contact du titulaire']);
$escRestL3 = $dg->reEnroll($escRest->generation(), 'escrest-password-02');
unparseArchiveEscrow($rawDb, $escRestL3['archiveId'], '$.escrow', "'pas une ligne'");
$dg->delete($escRestL3['unlocked']->generation());
try {
    $dg->restoreArchive('user-escrest', $escRestL3['archiveId']);
    $dg->hasEscrow($dg->loginWithPassword('user-escrest', 'escrest-password-01'))
        ? ok('restoreArchive() brings back too an archive whose escrow is not even a row, with a row that does not parse')
        : ko('restoreArchive() brought the vault back without an escrow row');
} catch (Throwable $e) {
    ko('an escrow that is not a row kept the archive from being restored', get_class($e) . ': ' . $e->getMessage());
}

// -----------------------------------------------------------------------------

section('An archive keeps the holder\'s indexed values from other accounts; one indexed by both before 0.9.1 comes back named and traced');

$vic = $dg->register('user-vic', 'vic-password-0001');
$dg->setFields($vic, ['email' => 'vic@example.org', 'alias' => 'vic-alias', 'note' => 'de vic'], indexed: ['email', 'alias']);
$vicL3 = $dg->reEnroll($vic->generation(), 'vic-password-0002');
try {
    $dg->setFields($dg->register('user-vic-tiers', 'vic-tiers-password-1'), ['email' => 'vic@example.org'], indexed: ['email']);
    ko('another account indexed the holder\'s value during the window');
} catch (DuplicateIndexException $e) {
    $e->fields === ['email']
        ? ok('another account indexing the holder\'s value during the window: DuplicateIndexException, as for a value held live')
        : ko('refused, naming another field', implode(',', $e->fields));
}
$vicLost = ['untouched'];
try {
    $dg->reinstateArchive($vicL3['unlocked']->generation(), $vicL3['archiveId'], null, $vicLost);
    $vicLost === [] && $dg->findUserByField('email', 'vic@example.org') === 'user-vic' && $dg->findUserByField('alias', 'vic-alias') === 'user-vic'
        ? ok('the holder comes back with every index: $restoredWithoutIndex is empty, the values name the holder')
        : ko('the holder came back without an index', var_export($vicLost, true));
} catch (Throwable $e) {
    ko('the holder could not come back', get_class($e) . ': ' . $e->getMessage());
}

/** A value $userId indexes live while an archive keeps it — what a database written before 0.9.1 may hold. */
$heldBefore091 = static function (string $userId, string $field, string $value) use ($rawDb, $blindKey): void {
    $rawDb->prepare('INSERT INTO selfdataguard_fields (user_id, field_name, ciphertext, blind_index, updated_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $field, 'c-before-0.9.1', BlindIndex::compute($value, $blindKey, $field), gmdate('c')]);
};
$ole = $dg->register('user-ole', 'ole-password-0001');
$dg->setFields($ole, ['email' => 'ole@example.org', 'note' => 'de ole'], indexed: ['email']);
$oleL3 = $dg->reEnroll($ole->generation(), 'ole-password-0002');
$dg->register('user-ole-tiers', 'ole-tiers-password-1');
$heldBefore091('user-ole-tiers', 'email', 'ole@example.org');
try {
    $dg->reinstateArchive($oleL3['unlocked']->generation(), $oleL3['archiveId'], null);
    ok('reinstateArchive() called with three arguments goes through over a value the other account holds');
    $oleBack = $dg->loginWithPassword('user-ole', 'ole-password-0001');
    $dg->getFields($oleBack) === ['email' => 'ole@example.org', 'note' => 'de ole'] && $dg->findUserByField('email', 'ole@example.org') === 'user-ole-tiers'
        ? ok('the holder reads every field; the value stays with the other account until the operator settles it')
        : ko('the fields or the index after the return are wrong');
    $dg->lostIndexes($oleBack) === ['email'] && ($dg->allLostIndexes()['user-ole'] ?? null) === ['email']
        ? ok('the call did not ask, and the trace stays: lostIndexes() names the field to the holder, allLostIndexes() to the operator')
        : ko('no trace names the field that came back without its index');
} catch (Throwable $e) {
    ko('a value the other account holds refused the return, or left no trace', get_class($e) . ': ' . $e->getMessage());
}

$vic2 = $dg->register('user-vic2', 'vic2-password-0001');
$dg->setFields($vic2, ['email' => 'vic2@example.org'], indexed: ['email']);
$vic2L3 = $dg->reEnroll($vic2->generation(), 'vic2-password-0002');
$dg->register('user-vic2-tiers', 'vic2-tiers-password-1');
$heldBefore091('user-vic2-tiers', 'email', 'vic2@example.org');
$dg->delete($vic2L3['unlocked']->generation());
$vic2Lost = ['untouched'];
try {
    $dg->restoreArchive('user-vic2', $vic2L3['archiveId'], $vic2Lost);
    $vic2Lost === ['email']
        && ($dg->getFields($dg->loginWithPassword('user-vic2', 'vic2-password-0001'))['email'] ?? null) === 'vic2@example.org'
        ? ok('restoreArchive(): the field comes back without its index, and $restoredWithoutIndex names it')
        : ko('restoreArchive() did not name the field whose index the other account holds', var_export($vic2Lost, true));
} catch (Throwable $e) {
    ko('a value the other account holds refused the restoration', get_class($e) . ': ' . $e->getMessage());
}

$pre = $dg->register('user-pre08f', 'pre08f-password-01');
$dg->setFields($pre, ['email' => 'pre08f@example.org', 'note' => 'de pre'], indexed: ['email']);
$preL3 = $dg->reEnroll($pre->generation(), 'pre08f-password-02');
$rawDb->prepare("UPDATE selfdataguard_archives SET package = json_remove(package, '$.fields.private.email.blind_index',"
    . " '$.fields.private.email.shared_index') WHERE archive_id = :id")->execute([':id' => $preL3['archiveId']]);
$preLost = ['untouched'];
$dg->reinstateArchive($preL3['unlocked']->generation(), $preL3['archiveId'], null, $preLost);
$preLost === ['email']
    ? ok('an archive written before 0.8.0 kept no index: $restoredWithoutIndex names the field it had indexed, and no other')
    : ko('a field an archive before 0.8.0 indexed came back unnamed', var_export($preLost, true));

$shPdo = new PDO('sqlite::memory:');
$shDg  = new SelfDataGuard(new SqliteAdapter($shPdo), $blindKey, sharedIndexes: ['phone'], pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
$sh = $shDg->register('user-shared', 'shared-password-01');
$shDg->setFields($sh, ['phone' => '+33 6 00 00 00 00', 'email' => 'shared@example.org'], indexed: ['phone', 'email']);
$shL3 = $shDg->reEnroll($sh->generation(), 'shared-password-02');
$shLost = ['untouched'];
$shDg->reinstateArchive($shL3['unlocked']->generation(), $shL3['archiveId'], null, $shLost);
$shLost === [] && $shDg->findUsersByField('phone', '+33 6 00 00 00 00') === ['user-shared']
    ? ok('a field with a shared index comes back with it, unnamed')
    : ko('a field with a shared index was named, or came back without it', var_export($shLost, true));

$flaky = new class ($storage) extends DelegatingStorage {
    public bool $down = false;

    public function findUserIdsByIndex(string $f, string $i): array
    {
        if ($this->down) {
            throw new RuntimeException('the storage does not answer');
        }
        return parent::findUserIdsByIndex($f, $i);
    }
};
$flakyDg = new SelfDataGuard($flaky, $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
$fla = $flakyDg->register('user-flaky', 'flaky-password-01');
$flakyDg->setFields($fla, ['email' => 'flaky@example.org'], indexed: ['email']);
$flaL3 = $flakyDg->reEnroll($fla->generation(), 'flaky-password-02');
$flaky->down = true;
$flaLost = ['untouched'];
try {
    $flaAside = $flakyDg->reinstateArchive($flaL3['unlocked']->generation(), $flaL3['archiveId'], null, $flaLost);
    $flaLost === null && $storage->loadArchive('user-flaky', $flaAside) !== null && hash_equals($fla->vaultSalt, $storage->loadVault('user-flaky')->userSalt)
        ? ok('the storage failing after the swap: the archive is live, the caller keeps the id of the vault set aside, $restoredWithoutIndex is null')
        : ko('after the swap, the lookup that failed was taken for an answer', var_export($flaLost, true));
} catch (Throwable $e) {
    ko('a lookup failing after the swap cost the caller the id of the vault set aside', get_class($e) . ': ' . $e->getMessage());
}
$flaky->down = false;

$typ = $dg->register('user-typed', 'typed-password-01');
$typL3 = $dg->reEnroll($typ->generation(), 'typed-password-02');
$notAList = 'pas une liste';
try {
    $dg->reinstateArchive($typL3['unlocked']->generation(), $typL3['archiveId'], null, $notAList);
    ko('a variable holding a string went through as $restoredWithoutIndex');
} catch (TypeError) {
    hash_equals($typL3['unlocked']->vaultSalt, $storage->loadVault('user-typed')->userSalt) && count($storage->listArchives('user-typed')) === 1
        ? ok('a variable holding a string as $restoredWithoutIndex: TypeError before anything is done')
        : ko('the TypeError came after the swap');
}

// -----------------------------------------------------------------------------

section('Given RecoveryTickets, the gestures without a secret need an accepted recovery');

final class TicketsOfTheBench implements Pierroons\SelfDataGuard\RecoveryTickets
{
    /** @var array<string, array{userId: string, kind: string}> */
    public array $issued = [];

    public function kindOf(string $ticket, string $userId): ?string
    {
        $t = $this->issued[$ticket] ?? null;
        return $t !== null && $t['userId'] === $userId ? $t['kind'] : null;
    }
}

$tickets = new TicketsOfTheBench();
$dgT = new SelfDataGuard($storage, $blindKey, tickets: $tickets, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
$sT = $dgT->register('user-ticket', 'ticket-password-01', hash('sha256', 'ticket-memorized'), 'girafe orage piment velours tapis lundi');
$gT = $sT->generation();
$refused = static function (callable $call): bool {
    try {
        $call();
        return false;
    } catch (Pierroons\SelfDataGuard\Vault\RecoveryTicketException) {
        return true;
    }
};
$tickets->issued['t-other-kind'] = ['userId' => 'user-ticket', 'kind' => Pierroons\SelfDataGuard\RecoveryTickets::CLOSURE];
$tickets->issued['t-other-user'] = ['userId' => 'user-someone-else', 'kind' => Pierroons\SelfDataGuard\RecoveryTickets::LEVEL_3];
$t0 = hrtime(true);
$withoutTicket = $refused(static fn () => $dgT->reEnroll($gT, 'ticket-password-02'));
$refusedMs = (hrtime(true) - $t0) / 1e6;
$withoutTicket && $refused(static fn () => $dgT->reEnroll($gT, 'ticket-password-02', ticket: 't-other-kind'))
    && $refused(static fn () => $dgT->reEnroll($gT, 'ticket-password-02', ticket: 't-other-user'))
    && hash_equals($sT->vaultSalt, $storage->loadVault('user-ticket')->userSalt)
    ? ok('reEnroll() refuses without a ticket, with one of another kind, and with one for another account')
    : ko('reEnroll() went through without an accepted level 3');
$refusedMs < 30
    ? ok(sprintf('the refusal comes before any Argon2id (%.1f ms)', $refusedMs))
    : ko('a refused reEnroll() still paid for its Argon2id', sprintf('%.1f ms', $refusedMs));
$refused(static fn () => $dgT->freeze($gT, 'arbitre')) && $refused(static fn () => $dgT->unfreeze($gT))
    && $refused(static fn () => $dgT->reinstateArchive($gT, 'nope'))
    ? ok('freeze(), unfreeze() and reinstateArchive() refuse without a ticket too')
    : ko('a gesture without a secret went through without a ticket');

$tickets->issued['t-l3'] = ['userId' => 'user-ticket', 'kind' => Pierroons\SelfDataGuard\RecoveryTickets::LEVEL_3];
$reT = $dgT->reEnroll($gT, 'ticket-password-02', ticket: 't-l3');
$dgT->archiveOfTicket('t-l3') === $reT['archiveId']
    ? ok('with its level-3 ticket the re-enrolment goes through, and its archive is found by the ticket')
    : ko('the archive of the level-3 ticket is not found');
$tickets->issued['t-return'] = ['userId' => 'user-ticket', 'kind' => Pierroons\SelfDataGuard\RecoveryTickets::HOLDER_RETURN];
$tickets->issued['t-close']  = ['userId' => 'user-ticket', 'kind' => Pierroons\SelfDataGuard\RecoveryTickets::CLOSURE];
$dgT->reinstateArchive($reT['unlocked']->generation(), (string) $dgT->archiveOfTicket('t-l3'), 't-return');
$gBack = $dgT->generationOf('user-ticket');
$dgT->freeze($gBack, 'arbitre', 't-return') && hash_equals($sT->vaultSalt, $gBack->userSalt)
    && $dgT->unfreeze($gBack, 't-close') && $dgT->frozenState('user-ticket') === null
    ? ok('the return ticket reinstates and freezes, the closure ticket unfreezes')
    : ko('the return or the closure did not go through under their tickets');

// -----------------------------------------------------------------------------

section('eraseAccount() keeps every archive of an account under a level 3 or a freeze; the operator lists them, and erases each with eraseArchive()');

/** $call's result, or the class of what it raised. */
$attempt = static function (callable $call): mixed {
    try {
        return $call();
    } catch (Throwable $e) {
        return get_class($e);
    }
};

$tickets->issued['t-eve-l3'] = ['userId' => 'user-eve', 'kind' => Pierroons\SelfDataGuard\RecoveryTickets::LEVEL_3];
$eve = $dgT->register('user-eve', 'eve-password-0001');
$dgT->setFields($eve, ['note' => 'écrite par le titulaire']);
$eveL3 = $dgT->reEnroll($eve->generation(), 'eve-password-0002', ticket: 't-eve-l3');
$eveKept = ['untouched'];
$erased = $dgT->eraseAccount('user-eve', $eveKept);
$erased === 1 && !$dgT->userExists('user-eve')
    ? ok('eraseAccount() erases the live vault, without a session or a ticket, and counts it alone')
    : ko('eraseAccount() did not erase the live vault alone', "erased {$erased}");
is_array($eveKept) && array_column($eveKept, 'id') === [$eveL3['archiveId']] && array_keys($eveKept[0] ?? []) === ['id', 'archivedAt']
    ? ok('$kept lists the archive the level-3 ticket set aside, by its id and when it was archived')
    : ko('$kept does not list the archive kept', var_export($eveKept, true));
try {
    $dgT->register('user-eve', 'eve-newcomer-0001');
    ko('the name registered again over the archive kept for its holder');
} catch (VaultExistsException $e) {
    str_contains($e->getMessage(), 'eraseArchive()')
        ? ok('the name does not register again while the archive is kept, and the refusal names eraseArchive()')
        : ko('refused without naming eraseArchive()', $e->getMessage());
}
$listed = $attempt(static fn () => array_values(array_filter($dgT->keptArchives(), static fn (array $a): bool => $a['userId'] === 'user-eve')));
is_array($listed) && array_column($listed, 'id') === [$eveL3['archiveId']]
    ? ok('keptArchives() lists it for the operator, with its account: the erasure is not finished')
    : ko('keptArchives() does not list the archive eraseAccount() kept', var_export($listed, true));
try {
    $dgT->eraseArchive('user-eve', $eveL3['archiveId']) && !$dgT->eraseArchive('user-eve', $eveL3['archiveId'])
        && $storage->listArchives('user-eve') === []
        ? ok('eraseArchive() erases the archive kept, then finds nothing more to erase')
        : ko('eraseArchive() did not erase the archive kept, or erased it twice');
    $dgT->archiveOfTicket('t-eve-l3') === null && !in_array('user-eve', array_column($dgT->keptArchives(), 'userId'), true)
        ? ok('the account holds nothing: no archive listed, and its level-3 ticket names no archive any more')
        : ko('the erased account is still listed, or its ticket still names the archive');
} catch (Throwable $e) {
    ko('eraseArchive() failed', get_class($e) . ': ' . $e->getMessage());
}
$eveReplay = null;
try {
    $newEve = $dgT->register('user-eve', 'eve-newcomer-0001');
    $dgT->reEnroll($newEve->generation(), 'eve-password-0002', ticket: 't-eve-l3');
} catch (TicketAlreadyAppliedException $e) {
    $eveReplay = $e;
} catch (Throwable $e) {
    ko('the newcomer could not register, or the replay failed otherwise', get_class($e) . ': ' . $e->getMessage());
}
$newcomerHolds = static function () use ($dgT): bool {
    try {
        return $dgT->loginWithPassword('user-eve', 'eve-newcomer-0001')->userId === 'user-eve';
    } catch (Throwable) {
        return false;
    }
};
$eveReplay !== null && $storage->listArchives('user-eve') === [] && $newcomerHolds()
    ? ok('the old level-3 ticket, replayed once the account is erased and registered again, is refused: the newcomer keeps the vault')
    : ko('a level-3 ticket replayed after the erasure archived the vault of whoever registered the name next');

foreach (['t-yan-l3' => 'LEVEL_3', 't-yan-return' => 'HOLDER_RETURN', 't-yan-close' => 'CLOSURE'] as $ticket => $kind) {
    $tickets->issued[$ticket] = ['userId' => 'user-yan', 'kind' => constant(Pierroons\SelfDataGuard\RecoveryTickets::class . "::{$kind}")];
}
$yan = $dgT->register('user-yan', 'yan-password-0001');
$yanL3 = $dgT->reEnroll($yan->generation(), 'yan-password-0002', ticket: 't-yan-l3');
$yanAside = $dgT->reinstateArchive($yanL3['unlocked']->generation(), $yanL3['archiveId'], 't-yan-return');
$dgT->freeze($dgT->generationOf('user-yan'), 'arbitre', 't-yan-return');
/** What the account holds: its live vault, its archives, its freeze and the archive its level-3 ticket names. */
$heldBy = static function (string $userId, string $level3) use ($dgT, $storage): array {
    $frozen = $dgT->frozenState($userId);
    return [$storage->findVault($userId)?->userSalt, array_column($storage->listArchives($userId), 'id'), $frozen === null ? null : [$frozen['by'], $frozen['at']->format('c')], $dgT->archiveOfTicket($level3)];
};
$yanBefore = $heldBy('user-yan', 't-yan-l3');
$yanKept = ['untouched'];
try {
    $erased = $dgT->eraseAccount('user-yan', $yanKept);
    ko('eraseAccount() of a frozen account went through: the arbiter has not looked at it yet', "erased {$erased}");
} catch (VaultFrozenException) {
    $heldBy('user-yan', 't-yan-l3') === $yanBefore && $yanBefore[0] !== null && $yanBefore[1] === [$yanAside] && $yanKept === ['untouched']
        ? ok('the holder came back, the vault frozen: eraseAccount() → VaultFrozenException, and nothing went — vault, archive, freeze, ticket, $kept')
        : ko('eraseAccount() refused the frozen account, but erased part of it');
}
try {
    $dgT->unfreeze($dgT->generationOf('user-yan'), 't-yan-close');
    $yanKept = ['untouched'];
    $erased = $dgT->eraseAccount('user-yan', $yanKept);
    $erased === 1 && array_column($yanKept, 'id') === [$yanAside]
        ? ok('once the window is closed, the request runs: the vault goes, the requester\'s archive stays under the level-3 ticket')
        : ko('the erasure after the closure did not run as the level 3 asks', "erased {$erased}, kept " . var_export($yanKept, true));
} catch (Throwable $e) {
    ko('the erasure after the closure could not run: the frozen vault was already gone', get_class($e) . ': ' . $e->getMessage());
}

foreach (['t-zoe-l3' => 'LEVEL_3', 't-zoe-return' => 'HOLDER_RETURN', 't-zoe-close' => 'CLOSURE', 't-zoe-return-2' => 'HOLDER_RETURN'] as $ticket => $kind) {
    $tickets->issued[$ticket] = ['userId' => 'user-zoe', 'kind' => constant(Pierroons\SelfDataGuard\RecoveryTickets::class . "::{$kind}")];
}
$zoe = $dgT->register('user-zoe', 'zoe-password-0001');
$zoeL3 = $dgT->reEnroll($zoe->generation(), 'zoe-password-0002', ticket: 't-zoe-l3');
$zoeAside = $dgT->reinstateArchive($zoeL3['unlocked']->generation(), $zoeL3['archiveId'], 't-zoe-return');
$dgT->freeze($dgT->generationOf('user-zoe'), 'arbitre', 't-zoe-return');
$dgT->unfreeze($dgT->generationOf('user-zoe'), 't-zoe-close');
$zoeSecond = $dgT->reinstateArchive($dgT->generationOf('user-zoe'), $zoeAside, 't-zoe-return-2');
$zoeKept = ['untouched'];
$erased = $dgT->eraseAccount('user-zoe', $zoeKept);
$erased === 1 && is_array($zoeKept) && array_column($zoeKept, 'id') === [$zoeSecond]
    && hash_equals($zoe->vaultSalt, (string) $storage->loadArchive('user-zoe', $zoeSecond)?->record->userSalt)
    ? ok('after a second exchange, the holder\'s vault — set aside by a swap, named by no level-3 ticket — stays')
    : ko('eraseAccount() erased the holder\'s vault a second exchange set aside', "erased {$erased}, kept " . var_export($zoeKept, true));

$una = $dg->register('user-una', 'una-password-0001');
$unaL3 = $dg->reEnroll($una->generation(), 'una-password-0002');
$unaAside = $dg->reinstateArchive($unaL3['unlocked']->generation(), $unaL3['archiveId']);
$dg->freeze($dg->generationOf('user-una'), 'arbitre');
$unaKept = ['untouched'];
try {
    $erased = $dg->eraseAccount('user-una', $unaKept);
    ko('without a ticket source, eraseAccount() of a frozen account went through', "erased {$erased}");
} catch (VaultFrozenException) {
    $dg->userExists('user-una') && array_column($storage->listArchives('user-una'), 'id') === [$unaAside] && $unaKept === ['untouched']
        ? ok('without a ticket source, eraseAccount() of a frozen account is refused the same, and nothing went')
        : ko('without a ticket source, the refused erasure erased part of the account');
}

foreach (['t-wes-l3' => 'LEVEL_3', 't-wes-return' => 'HOLDER_RETURN', 't-wes-close' => 'CLOSURE'] as $ticket => $kind) {
    $tickets->issued[$ticket] = ['userId' => 'user-wes', 'kind' => constant(Pierroons\SelfDataGuard\RecoveryTickets::class . "::{$kind}")];
}
$wes = $dgT->register('user-wes', 'wes-password-0001');
$wesL3 = $dgT->reEnroll($wes->generation(), 'wes-password-0002', ticket: 't-wes-l3');
$wesAside = $dgT->reinstateArchive($wesL3['unlocked']->generation(), $wesL3['archiveId'], 't-wes-return');
$dgT->freeze($dgT->generationOf('user-wes'), 'arbitre', 't-wes-return');
$wesBefore = $heldBy('user-wes', 't-wes-l3');
try {
    $dgT->eraseArchive('user-wes', $wesAside);
    ko('eraseArchive() erased an archive of a frozen account: during the case, nothing is erased');
} catch (VaultFrozenException) {
    $heldBy('user-wes', 't-wes-l3') === $wesBefore && $wesBefore[1] === [$wesAside]
        ? ok('the vault frozen, the operator\'s eraseArchive() → VaultFrozenException, and nothing went — vault, archive, freeze, ticket')
        : ko('eraseArchive() refused the frozen account, but erased part of it');
} catch (Throwable $e) {
    ko('eraseArchive() of a frozen account refused otherwise than as frozen', get_class($e) . ': ' . $e->getMessage());
}
try {
    $dgT->unfreeze($dgT->generationOf('user-wes'), 't-wes-close');
    $dgT->eraseArchive('user-wes', $wesAside);
    $storage->listArchives('user-wes') === []
        ? ok('once the window is closed, the operator erases it')
        : ko('the archive is still there after the closure and eraseArchive()');
} catch (Throwable $e) {
    ko('eraseArchive() after the closure failed', get_class($e) . ': ' . $e->getMessage());
}

$tickets->issued['t-ivy-l3']     = ['userId' => 'user-ivy', 'kind' => Pierroons\SelfDataGuard\RecoveryTickets::LEVEL_3];
$tickets->issued['t-ivy-return'] = ['userId' => 'user-ivy', 'kind' => Pierroons\SelfDataGuard\RecoveryTickets::HOLDER_RETURN];
$ivy = $dgT->register('user-ivy', 'ivy-password-0001');
$dgT->setFields($ivy, ['note' => 'note d\'ivy']);
$ivyL3 = $dgT->reEnroll($ivy->generation(), 'ivy-password-0002', ticket: 't-ivy-l3');
$dgT->eraseAccount('user-ivy');
try {
    $dgT->generationOf('user-ivy') === null
        ? ok('the requester erased the account: no live vault, generationOf() is null')
        : ko('the account still holds a live vault after eraseAccount()');
    $dgT->restoreArchive('user-ivy', $ivyL3['archiveId']);
    $dgT->freeze($dgT->generationOf('user-ivy'), 'arbitre', 't-ivy-return');
    $dgT->getFields($dgT->loginWithPassword('user-ivy', 'ivy-password-0001')) === ['note' => 'note d\'ivy'] && $dgT->frozenState('user-ivy') !== null
        ? ok('the holder returns after the erasure: restoreArchive() brings their vault back, freeze() takes the return ticket')
        : ko('the holder\'s vault did not come back frozen after the erasure');
} catch (Throwable $e) {
    ko('the holder could not return after the requester erased the account', get_class($e) . ': ' . $e->getMessage());
}

$tickets->issued['t-ben-l3'] = ['userId' => 'user-ben', 'kind' => Pierroons\SelfDataGuard\RecoveryTickets::LEVEL_3];
$ben = $dgT->register('user-ben', 'ben-password-0001');
$benL3 = $dgT->reEnroll($ben->generation(), 'ben-password-0002', ticket: 't-ben-l3');
$rawDb->prepare("UPDATE selfdataguard_archives SET package = 'pas du json' WHERE archive_id = ?")->execute([$benL3['archiveId']]);
$notAList = 'pas une liste';
try {
    $dgT->eraseAccount('user-ben', $notAList);
    ko('a variable holding a string went through as $kept');
} catch (TypeError) {
    $dgT->userExists('user-ben')
        ? ok('a variable holding a string as $kept: TypeError before anything is erased')
        : ko('the TypeError came after the erasure');
}
$benKept = ['untouched'];
$benFirst = $attempt(static function () use ($dgT, &$benKept) {
    return $dgT->eraseAccount('user-ben', $benKept);
});
$benAgain = $attempt(static fn () => $dgT->eraseAccount('user-ben'));
$benFirst === 1 && $benAgain === 0 && is_array($benKept) && array_column($benKept, 'id') === [$benL3['archiveId']]
    ? ok('a kept archive whose package no longer reads: eraseAccount() returns, $kept lists it, and a second call erases nothing')
    : ko('eraseAccount() raised after erasing, over a kept archive that no longer reads', var_export([$benFirst, $benAgain, $benKept], true));
$attempt(static fn () => $dgT->eraseArchive('user-ben', $benL3['archiveId']));

// A storage written for 0.9.0 erases a frozen account; the façade asks for the
// freeze first, and refuses in its place.
$r5Pdo = new PDO("sqlite:{$dbPath}");
foreach ([
    'eraseAccount()' => ['FrozenErasingStorage', static fn (SelfDataGuard $f, string $u, string $a) => $f->eraseAccount($u)],
    'eraseArchive()' => ['FrozenArchiveDeletingStorage', static fn (SelfDataGuard $f, string $u, string $a) => $f->eraseArchive($u, $a)],
] as $call => [$class, $run]) {
    $uid = 'user-r5-' . substr($call, 0, 10);
    $r5 = $dg->register($uid, 'r5-password-00001');
    $r5L3 = $dg->reEnroll($r5->generation(), 'r5-password-00002');
    $dg->freeze($dg->generationOf($uid), 'arbitre');
    if (!class_exists($class)) {
        ko("{$call} on a storage that erases under a freeze: {$class} is not declared in this version");
        continue;
    }
    $loose = new SelfDataGuard(new $class($storage, $r5Pdo), $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
    try {
        $run($loose, $uid, $r5L3['archiveId']);
        ko("{$call} through a storage that erases under a freeze went through");
    } catch (VaultFrozenException) {
        $dg->userExists($uid) && array_column($storage->listArchives($uid), 'id') === [$r5L3['archiveId']]
            ? ok("{$call} on a storage that erases under a freeze: the façade refuses first, and nothing went")
            : ko("{$call} raised, but the storage had erased part of the account");
    } catch (Throwable $e) {
        ko("{$call} through a storage that erases under a freeze failed otherwise", get_class($e) . ': ' . $e->getMessage());
    }
}

$noErasing = new class ($storage) extends DelegatingStorage {
};
$bare = new SelfDataGuard($noErasing, $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
foreach ([
    'eraseArchive()'    => [static fn () => $bare->eraseArchive('user-yan', str_repeat('0', 32)), 'ErasesArchives'],
    'keptArchives()'    => [static fn () => $bare->keptArchives(), 'ErasesArchives'],
    'allLostIndexes()'  => [static fn () => $bare->allLostIndexes(), 'TracesLostIndexes'],
    'reservedIndexes()' => [static fn () => $bare->reservedIndexes(), 'TracesLostIndexes'],
] as $call => [$run, $interface]) {
    try {
        $run();
        ko("{$call} went through a storage that does not implement {$interface}");
    } catch (LogicException $e) {
        str_contains($e->getMessage(), $interface)
            ? ok("on a storage without {$interface}, {$call} refuses, naming the interface")
            : ko("{$call} refused without naming the interface", $e->getMessage());
    } catch (Throwable $e) {
        ko("{$call} on a storage without {$interface} failed otherwise", get_class($e) . ': ' . $e->getMessage());
    }
}

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Phase 5 Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
