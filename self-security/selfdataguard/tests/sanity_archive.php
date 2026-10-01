<?php

declare(strict_types=1);

/**
 * Sanity test — archive storage: a vault set aside by a re-enrolment.
 *
 * Run:  php tests/sanity_archive.php
 * Exit: 0 on success, non-zero if any check failed.
 *
 * Storage level only: what is kept, what is not, who can reach it, and that
 * the replacement is all-or-nothing. Opening an archive is the façade's job
 * (sanity_facade.php).
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Fields\BlindIndex;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Vault\Lock;
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

$dbPath = sys_get_temp_dir() . '/selfdataguard-archive-' . bin2hex(random_bytes(4)) . '.sqlite';
register_shutdown_function(static fn () => @unlink($dbPath));
$pdo      = new PDO("sqlite:{$dbPath}");
$storage  = new SqliteAdapter($pdo);
$blindKey = Primitives::randomBytes(32);
$dg       = new SelfDataGuard($storage, $blindKey);
$uv       = new UserVault();
$admin    = SelfDataGuard::generateAdminRecoveryKey('admin passphrase for the archive bench');

const EMAIL  = 'ancien@example.org';
const PHRASE = 'cheval agrafe batterie correct moulin ivoire';

$old = $dg->register('user-arch', 'arch-password-0001', 'arch-memorized', PHRASE);
$dg->setFields($old, ['email' => EMAIL, 'note' => 'ancienne note'], indexed: ['email']);
$dg->setEscrowFields($old, $admin['publicKey'], ['contact' => 'contact de secours']);
$oldRecord = $storage->loadVault('user-arch');
$emailIndex = BlindIndex::compute(EMAIL, $blindKey, 'email');

// -----------------------------------------------------------------------------

section('replaceWithArchive — the live vault is set aside, the new one takes its place');

$new = $uv->register('user-arch', 'arch-password-0002', 'arch-memorized-2', 'tapis girafe lundi orage piment velours');
$id1 = $storage->replaceWithArchive($new['record']);

is_string($id1) && preg_match('/^[0-9a-f]{32}$/', $id1) === 1
    ? ok('returns a random 128-bit archive id')
    : ko('unexpected archive id', var_export($id1, true));
hash_equals($new['record']->userSalt, $storage->loadVault('user-arch')->userSalt)
    ? ok('the live vault is the new one')
    : ko('the live vault was not replaced');
$storage->loadFields('user-arch') === [] && $storage->loadEscrow('user-arch') === null
    && $storage->loadEscrowFields('user-arch') === []
    ? ok('the old fields and escrow left the live tables')
    : ko('old live rows remain under the new vault');

$list = $storage->listArchives('user-arch');
count($list) === 1 && $list[0]['id'] === $id1
    && $list[0]['locks'] === [Lock::Password, Lock::Memorized, Lock::Passphrase]
    ? ok('listArchives(): one archive, with the three locks that still open it')
    : ko('unexpected archive list', json_encode($list));

// -----------------------------------------------------------------------------

section('What the archive keeps — still encrypted, as stored');

$archive = $storage->loadArchive('user-arch', $id1);
$archive !== null
    && hash_equals($oldRecord->userSalt, $archive->record->userSalt)
    && $archive->record->wrapPwd->toBase64() === $oldRecord->wrapPwd->toBase64()
    && $archive->record->wrapRecov?->toBase64() === $oldRecord->wrapRecov?->toBase64()
    && $archive->record->wrapPhrase?->toBase64() === $oldRecord->wrapPhrase?->toBase64()
    ? ok('salt and the three envelopes, byte for byte')
    : ko('the archived envelopes differ from the live ones');
$archive?->kdfOpslimit === Primitives::ARGON2_OPSLIMIT && $archive?->kdfMemlimit === Primitives::ARGON2_MEMLIMIT
    ? ok('the Argon2id profile in force is recorded with it')
    : ko('the archive does not carry its Argon2id profile');
array_keys($archive?->privateFields ?? []) == ['email', 'note']
    && ($archive->privateFields['email']['wasIndexed'] ?? null) === true
    && ($archive->privateFields['note']['wasIndexed'] ?? null) === false
    ? ok('private fields kept, each with whether it was indexed')
    : ko('private fields or their was_indexed flag are wrong', json_encode($archive?->privateFields));
$archive?->escrow !== null && array_keys($archive->escrowFields) === ['contact']
    ? ok('the escrow envelope and its field go with the archive')
    : ko('the escrow was not archived');

try {
    $uv->unlockWithPassphrase($archive->record, PHRASE);
    ok('an archived envelope still opens with its old secret (AAD = userId, unchanged)');
} catch (Throwable $e) {
    ko('the archived envelope no longer opens', $e->getMessage());
}

// -----------------------------------------------------------------------------

section('What the archive does not keep — the blind index');

$storage->findUserIdByBlindIndex('email', $emailIndex) === null
    ? ok('the old email no longer finds the account')
    : ko('a lookup still finds data that is only archived');
$pkg = (string) $pdo->query("SELECT package FROM selfdataguard_archives WHERE archive_id = '{$id1}'")->fetchColumn();
!str_contains($pkg, $emailIndex)
    ? ok('the blind index value is not in the archive package')
    : ko('the archive carries the blind index');

// -----------------------------------------------------------------------------

section('Several archives, and another account cannot reach them');

$third = $uv->register('user-arch', 'arch-password-0003');
$id2 = $storage->replaceWithArchive($third['record']);
$ids = array_column($storage->listArchives('user-arch'), 'id');
$ids === [$id1, $id2] && $storage->loadArchive('user-arch', $id1) !== null
    ? ok('a second re-enrolment adds an archive and leaves the first intact')
    : ko('the second re-enrolment lost or reordered an archive', implode(',', $ids));
$storage->listArchives('user-arch')[1]['locks'] === [Lock::Password, Lock::Memorized, Lock::Passphrase]
    ? ok('each archive lists its own locks')
    : ko('wrong locks on the second archive');

$dg->register('user-other', 'other-password-01');
$storage->loadArchive('user-other', $id1) === null
    ? ok('loadArchive() with another userId finds nothing')
    : ko('an archive was read through another account');
!$storage->deleteArchive('user-other', $id1) && $storage->loadArchive('user-arch', $id1) !== null
    ? ok('deleteArchive() with another userId deletes nothing')
    : ko('an archive was deleted through another account');

$storage->replaceWithArchive($uv->register('user-fresh', 'fresh-password-01')['record']) === null
    && $storage->vaultExists('user-fresh')
    ? ok('no live vault: nothing archived, the new vault is inserted, null returned')
    : ko('replaceWithArchive() on a userId without vault misbehaved');

// -----------------------------------------------------------------------------

section('All or nothing — a failure while inserting the new vault changes nothing');

$boomOld = $dg->register('user-boom', 'boom-password-001', 'boom-memorized');
$dg->setFields($boomOld, ['keep' => 'me']);
$pdo->exec(
    "CREATE TRIGGER boom BEFORE INSERT ON selfdataguard_vaults
     WHEN NEW.user_id = 'user-boom' BEGIN SELECT RAISE(ABORT, 'insert refused by the test'); END"
);
$thrown = false;
try {
    $storage->replaceWithArchive($uv->register('user-boom', 'boom-password-002')['record']);
} catch (Throwable) {
    $thrown = true;
}
$pdo->exec('DROP TRIGGER boom');
$thrown ? ok('the failing insert surfaces as an exception') : ko('the injected failure did not fire');
$storage->listArchives('user-boom') === []
    && ($dg->getFields($dg->loginWithPassword('user-boom', 'boom-password-001'))['keep'] ?? null) === 'me'
    && !$pdo->inTransaction()
    ? ok('no archive written, the old vault and its fields still live, no transaction left open')
    : ko('a half-done replacement persisted');

// -----------------------------------------------------------------------------

section('Deleting the account leaves the archives; purging them is explicit');

$dg->delete('user-arch');
count($storage->listArchives('user-arch')) === 2
    ? ok('deleteVault() does not touch the archives')
    : ko('deleting the live vault erased archives');
$storage->deleteArchive('user-arch', $id1) && $storage->loadArchive('user-arch', $id1) === null
    ? ok('deleteArchive() removes one archive')
    : ko('deleteArchive() did not remove it');
$storage->purgeArchives('user-arch') === 1 && $storage->listArchives('user-arch') === []
    ? ok('purgeArchives() removes the rest and says how many')
    : ko('purgeArchives() left archives behind');

// -----------------------------------------------------------------------------

section('A package from a newer version is refused, not misread');

$id3 = $storage->replaceWithArchive($uv->register('user-boom', 'boom-password-003')['record']);
$pdo->prepare('UPDATE selfdataguard_archives SET package = json_set(package, \'$.format\', 99) WHERE archive_id = :id')
    ->execute([':id' => $id3]);
try {
    $storage->loadArchive('user-boom', $id3);
    ko('a format-99 package was read as if it were format 1');
} catch (RuntimeException $e) {
    str_contains($e->getMessage(), 'newer SelfDataGuard')
        ? ok('format 99 → "written by a newer SelfDataGuard"')
        : ko('wrong refusal', $e->getMessage());
}

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Archive Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
