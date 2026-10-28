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
use Pierroons\SelfDataGuard\Storage\DuplicateIndexException;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\StaleVaultException;
use Pierroons\SelfDataGuard\Vault\UserVault;
use Pierroons\SelfDataGuard\Vault\VaultExistsException;
use Pierroons\SelfDataGuard\Vault\VaultFrozenException;
use Pierroons\SelfDataGuard\Vault\VaultGeneration;
use Pierroons\SelfDataGuard\Vault\VaultNotFoundException;
use Pierroons\SelfDataGuard\Vault\VaultTamperedException;

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
$id1 = $storage->replaceWithArchive($oldRecord->generation(), $new['record']);

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

section('The blind index goes into the archive, inert');

$storage->findUserIdsByIndex('email', $emailIndex) === []
    ? ok('the old email no longer finds the account')
    : ko('a lookup still finds data that is only archived');
$pkg = json_decode((string) $pdo->query("SELECT package FROM selfdataguard_archives WHERE archive_id = '{$id1}'")->fetchColumn(), true);
($pkg['fields']['private']['email']['blind_index'] ?? null) === $emailIndex
    && array_key_exists('blind_index', $pkg['fields']['private']['note'] ?? []) && $pkg['fields']['private']['note']['blind_index'] === null
    ? ok('the package keeps the index of the indexed field, and none for the other')
    : ko('the archive did not keep the blind index', json_encode($pkg['fields']['private'] ?? null));

// -----------------------------------------------------------------------------

section('Several archives, and another account cannot reach them');

$third = $uv->register('user-arch', 'arch-password-0003');
$id2 = $storage->replaceWithArchive($new['record']->generation(), $third['record']);
$ids = array_column($storage->listArchives('user-arch'), 'id');
$ids === [$id1, $id2] && $storage->loadArchive('user-arch', $id1) !== null
    ? ok('a second re-enrolment adds an archive and leaves the first intact')
    : ko('the second re-enrolment lost or reordered an archive', implode(',', $ids));
$storage->listArchives('user-arch')[1]['locks'] === [Lock::Password, Lock::Memorized, Lock::Passphrase]
    ? ok('each archive lists its own locks')
    : ko('wrong locks on the second archive');

$otherSession = $dg->register('user-other', 'other-password-01');
$storage->loadArchive('user-other', $id1) === null
    ? ok('loadArchive() with another userId finds nothing')
    : ko('an archive was read through another account');
!$storage->deleteArchive($otherSession->generation(), $id1) && $storage->loadArchive('user-arch', $id1) !== null
    ? ok('deleteArchive() with another userId deletes nothing')
    : ko('an archive was deleted through another account');

$thrown = null;
try {
    $storage->replaceWithArchive(
        new VaultGeneration('user-fresh', random_bytes(16)),
        $uv->register('user-fresh', 'fresh-password-01')['record']
    );
} catch (VaultNotFoundException $e) {
    $thrown = $e;
}
$thrown !== null && !$storage->vaultExists('user-fresh')
    ? ok('no live vault: VaultNotFoundException, nothing inserted — a first vault goes through saveVault()')
    : ko('replaceWithArchive() on a userId without vault misbehaved');

// -----------------------------------------------------------------------------

section('A concurrent writer is waited for, not failed on');

// This process holds the write lock, as another request writing would. A
// child process re-enrols meanwhile: it must wait for the lock and succeed —
// not fail at once on "database is locked", as a transaction that reads
// before it writes does under SQLite.
$wfile = sys_get_temp_dir() . '/selfdataguard-wait-' . bin2hex(random_bytes(4)) . '.sqlite';
register_shutdown_function(static fn () => @unlink($wfile));
$wdg = new SelfDataGuard(new SqliteAdapter("sqlite:{$wfile}"), $blindKey);
$wdg->register('user-wait', 'wait-password-0001', 'wait-memorized');
$holder = new PDO("sqlite:{$wfile}");
$holder->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$holder->exec('BEGIN IMMEDIATE');
$holder->exec("UPDATE selfdataguard_vaults SET updated_at = updated_at WHERE user_id = 'user-wait'");
$child = proc_open([PHP_BINARY, '-r', sprintf(
    'require %s; $t = microtime(true);'
    . ' try { $s = new Pierroons\SelfDataGuard\Storage\SqliteAdapter(%s);'
    . ' $s->replaceWithArchive($s->loadVault("user-wait")->generation(),'
    . ' (new Pierroons\SelfDataGuard\Vault\UserVault())->register("user-wait", "wait-password-0002")["record"]);'
    . ' printf("ok %%d", (microtime(true) - $t) * 1000); }'
    . ' catch (Throwable $e) { echo $e->getMessage(); exit(1); }',
    var_export(__DIR__ . '/../src/autoload.php', true),
    var_export("sqlite:{$wfile}", true)
)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
usleep(1_500_000);
$holder->exec('COMMIT');
$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
$rc = proc_close($child);
$waited = preg_match('/^ok (\d+)$/', $out, $m) === 1 ? (int) $m[1] : -1;
$rc === 0 && $waited >= 0
    ? ok("the re-enrolment waited for the other writer and succeeded ({$waited} ms)")
    : ko('the re-enrolment failed while another writer held the lock', $out);
$waited >= 300
    ? ok('it did wait — the lock was really contended')
    : ko('the child never waited: the race was not exercised', $out);
count((new SqliteAdapter("sqlite:{$wfile}"))->listArchives('user-wait')) === 1
    ? ok('the archive is there')
    : ko('no archive after the contended re-enrolment');

$at = (string) $pdo->query("SELECT archived_at FROM selfdataguard_archives LIMIT 1")->fetchColumn();
str_ends_with($at, '+00:00')
    ? ok('archived_at is stored in UTC, so the order survives a DST change')
    : ko('archived_at carries a local offset', $at);

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
    $storage->replaceWithArchive($boomOld->generation(), $uv->register('user-boom', 'boom-password-002')['record']);
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

section('Deleting the live vault leaves the archives; erasing the account is explicit');

$storage->deleteArchive($third['record']->generation(), $id1) && $storage->loadArchive('user-arch', $id1) === null
    ? ok('deleteArchive() removes one archive, under the live generation')
    : ko('deleteArchive() did not remove it');
$dg->delete($dg->generationOf('user-arch'));
count($storage->listArchives('user-arch')) === 1
    ? ok('deleteVault() does not touch the archives')
    : ko('deleting the live vault erased archives');
$storage->eraseAccount('user-arch') === 1 && $storage->listArchives('user-arch') === []
    ? ok('eraseAccount() removes the rest and says how many')
    : ko('eraseAccount() left archives behind');

// -----------------------------------------------------------------------------

section('A package from a newer version is refused, not misread');

$id3 = $storage->replaceWithArchive($boomOld->generation(), $uv->register('user-boom', 'boom-password-003')['record']);
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

section('A package that no longer reads is tampering, named — not a JsonException or a TypeError');

foreach (['pas du json' => 'a package that is not JSON', '{"vault":null}' => 'a package without its vault row'] as $package => $what) {
    $pdo->prepare('UPDATE selfdataguard_archives SET package = :p WHERE archive_id = :id')->execute([':p' => $package, ':id' => $id3]);
    foreach (['listArchives' => static fn () => $storage->listArchives('user-boom'), 'loadArchive' => static fn () => $storage->loadArchive('user-boom', $id3)] as $how => $call) {
        try {
            $call();
            ko("{$what}: {$how}() read it");
        } catch (VaultTamperedException $e) {
            str_contains($e->getMessage(), $id3)
                ? ok("{$what}: {$how}() refuses it as tampering, naming the archive")
                : ko("{$what}: {$how}() refused without naming the archive", $e->getMessage());
        } catch (Throwable $e) {
            ko("{$what}: {$how}() threw " . get_class($e) . ', which no caller expects', $e->getMessage());
        }
    }
}

// -----------------------------------------------------------------------------

section('swapWithArchive — the archive comes back live, the live vault goes aside');

/** A vault filled, then re-enrolled: the archive id and the record of each side. */
function reEnrolled(SelfDataGuard $dg, SqliteAdapter $storage, string $userId, string $email, array $admin): array
{
    $s = $dg->register($userId, "{$userId}-password-1", "{$userId}-memorized", PHRASE);
    $dg->setFields($s, ['email' => $email, 'note' => 'note du titulaire'], indexed: ['email']);
    $dg->setEscrowFields($s, $admin['publicKey'], ['contact' => 'contact du titulaire']);
    $holder = $storage->loadVault($userId);
    $re = $dg->reEnroll($holder->generation(), "{$userId}-password-2");
    $dg->setFields($re['unlocked'], ['note' => 'note du demandeur']);
    return ['archiveId' => $re['archiveId'], 'holder' => $holder, 'requester' => $storage->loadVault($userId)];
}

$sw = reEnrolled($dg, $storage, 'user-swap', 'swap@example.org', $admin);
$swIndex = BlindIndex::compute('swap@example.org', $blindKey, 'email');
$setAside = $storage->swapWithArchive($sw['requester']->generation(), $sw['archiveId']);
$live = $storage->loadVault('user-swap');
hash_equals($sw['holder']->userSalt, $live->userSalt) && $live->wrapPhrase?->toBase64() === $sw['holder']->wrapPhrase?->toBase64()
    ? ok('the live vault is the archived one, envelopes as they were')
    : ko('the live vault is not the archived one');
$holderSession = $dg->loginWithPassword('user-swap', 'user-swap-password-1');
$dg->getFields($holderSession) === ['email' => 'swap@example.org', 'note' => 'note du titulaire']
    && $dg->getEscrowFieldsAsUser($holderSession) === ['contact' => 'contact du titulaire']
    ? ok('the holder opens it with their old password, fields and escrow back')
    : ko('the reinstated vault does not read as it was archived');
$storage->findUserIdsByIndex('email', $swIndex) === ['user-swap']
    ? ok('its blind index answers lookups again')
    : ko('the blind index did not come back');
$ids = array_column($storage->listArchives('user-swap'), 'id');
$ids === [$setAside] && $setAside !== $sw['archiveId']
    ? ok('the archive brought back is gone; the requester\'s vault is the only archive')
    : ko('the archives after a swap are wrong', implode(',', $ids));
$requesterArchive = $storage->loadArchive('user-swap', $setAside);
hash_equals($sw['requester']->userSalt, $requesterArchive->record->userSalt)
    && isset($requesterArchive->privateFields['note'])
    ? ok('the requester\'s vault waits as an archive, with what was written into it')
    : ko('the requester\'s vault was not archived as it stood');

foreach ([
    'the generation already replaced' => [$sw['requester']->generation(), $setAside, StaleVaultException::class],
    'an archive that does not exist'  => [$live->generation(), str_repeat('0', 32), VaultNotFoundException::class],
    'an archive of another account'   => [$live->generation(), $id2, VaultNotFoundException::class],
] as $what => [$generation, $archiveId, $expected]) {
    try {
        $storage->swapWithArchive($generation, $archiveId);
        ko("a swap naming {$what} went through");
    } catch (Throwable $e) {
        $e instanceof $expected && hash_equals($sw['holder']->userSalt, $storage->loadVault('user-swap')->userSalt)
            && count($storage->listArchives('user-swap')) === 1
            ? ok("a swap naming {$what}: " . (new ReflectionClass($e))->getShortName() . ', nothing changed')
            : ko("a swap naming {$what} misbehaved", get_class($e) . ': ' . $e->getMessage());
    }
}

section('A value another account took since the archive was made refuses the swap');

$dup = reEnrolled($dg, $storage, 'user-dup', 'dup@example.org', $admin);
$dg->setFields($dg->register('user-taker', 'taker-password-01'), ['email' => 'dup@example.org'], indexed: ['email']);
try {
    $storage->swapWithArchive($dup['requester']->generation(), $dup['archiveId']);
    ko('the swap put back an index another account holds');
} catch (DuplicateIndexException $e) {
    $e->fields === ['email'] ? ok('DuplicateIndexException names the field') : ko('the refusal names the wrong fields', implode(',', $e->fields));
}
hash_equals($dup['requester']->userSalt, $storage->loadVault('user-dup')->userSalt)
    && $storage->loadArchive('user-dup', $dup['archiveId']) !== null
    && $storage->findUserIdsByIndex('email', BlindIndex::compute('dup@example.org', $blindKey, 'email')) === ['user-taker']
    ? ok('nothing was swapped: the requester\'s vault is live, the archive is there, the value stays with its holder')
    : ko('a refused swap changed something');

section('An archive written before 0.8.0 comes back without its indexes');

$old8 = reEnrolled($dg, $storage, 'user-pre08', 'pre08@example.org', $admin);
$pdo->prepare("UPDATE selfdataguard_archives SET package = json_remove(package, '$.fields.private.email.blind_index',"
    . " '$.fields.private.email.shared_index', '$.fields.private.note.blind_index', '$.fields.private.note.shared_index')"
    . ' WHERE archive_id = :id')->execute([':id' => $old8['archiveId']]);
$storage->swapWithArchive($old8['requester']->generation(), $old8['archiveId']);
$dg->getFields($dg->loginWithPassword('user-pre08', 'user-pre08-password-1'))['email'] === 'pre08@example.org'
    && $storage->findUserIdsByIndex('email', BlindIndex::compute('pre08@example.org', $blindKey, 'email')) === []
    ? ok('the field is back, without an index: the application indexes it again')
    : ko('an archive without indexes did not come back as declared');

section('restoreArchive — only where no live vault is left');

$rest = reEnrolled($dg, $storage, 'user-rest', 'rest@example.org', $admin);
try {
    $storage->restoreArchive('user-rest', $rest['archiveId']);
    ko('restoreArchive() replaced a live vault');
} catch (VaultExistsException) {
    hash_equals($rest['requester']->userSalt, $storage->loadVault('user-rest')->userSalt)
        ? ok('a live vault: VaultExistsException, nothing changed')
        : ko('a refused restoration changed the live vault');
}
$storage->deleteVault($rest['requester']->generation());
$storage->restoreArchive('user-rest', $rest['archiveId']);
hash_equals($rest['holder']->userSalt, $storage->loadVault('user-rest')->userSalt)
    && $storage->listArchives('user-rest') === []
    && $dg->getFields($dg->loginWithPassword('user-rest', 'user-rest-password-1'))['note'] === 'note du titulaire'
    ? ok('no live vault: the archive is live again, and no longer an archive')
    : ko('restoreArchive() did not bring the archive back');

// -----------------------------------------------------------------------------

section('A frozen vault reads, and refuses every write');

$frz = reEnrolled($dg, $storage, 'user-frz', 'frz@example.org', $admin);
$fg  = $frz['requester']->generation();
$storage->freezeVault($fg, 'arbitre', new DateTimeImmutable('2026-10-28T08:00:00+01:00'))
    ? ok('freezeVault() freezes')
    : ko('freezeVault() said it did not freeze');
!$storage->freezeVault($fg, 'quelqu\'un d\'autre', new DateTimeImmutable())
    && ($storage->frozenState('user-frz')['by'] ?? null) === 'arbitre'
    && ($storage->frozenState('user-frz')['at'] ?? null)?->format('c') === '2026-10-28T07:00:00+00:00'
    ? ok('a second freeze changes nothing: who froze it and when stay the first, in UTC')
    : ko('a second freeze replaced the first', json_encode($storage->frozenState('user-frz')));
$storage->frozenState('user-swap') === null && $storage->frozenState('user-never') === null
    ? ok('frozenState() is null for a vault not frozen, and for a userId without one')
    : ko('frozenState() reports a freeze that is not there');

$before = [$storage->loadVault('user-frz'), $storage->loadFields('user-frz'), $storage->loadEscrowFields('user-frz')];
$frozenRecord = $before[0];
$now = new DateTimeImmutable();
foreach ([
    'updateVault'        => static fn () => $storage->updateVault($frozenRecord->withWrapPwd($frozenRecord->wrapPwd, $now)),
    'saveFields'         => static fn () => $storage->saveFields($fg, ['note' => ['ciphertext' => 'c']]),
    'saveEscrowFields'   => static fn () => $storage->saveEscrowFields($fg, ['contact' => 'c']),
    'createEscrow'       => static fn () => $storage->createEscrow($fg, new Pierroons\SelfDataGuard\Escrow\EscrowRecord('user-frz', $frozenRecord->wrapPwd, 'x', $now, $now)),
    'deleteVault'        => static fn () => $storage->deleteVault($fg),
    'deleteArchive'      => static fn () => $storage->deleteArchive($fg, $frz['archiveId']),
    'replaceWithArchive' => static fn () => $storage->replaceWithArchive($fg, $uv->register('user-frz', 'frz-password-9')['record']),
    'swapWithArchive'    => static fn () => $storage->swapWithArchive($fg, $frz['archiveId']),
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
[$storage->loadVault('user-frz')->revision, $storage->loadFields('user-frz'), $storage->loadEscrowFields('user-frz'), count($storage->listArchives('user-frz'))]
    === [$before[0]->revision, $before[1], $before[2], 1]
    ? ok('nothing changed, and it still reads')
    : ko('a refused write changed the frozen vault');

try {
    $storage->unfreezeVault($frz['holder']->generation());
    ko('unfreezeVault() of another generation lifted the freeze');
} catch (StaleVaultException) {
    $storage->frozenState('user-frz') !== null
        ? ok('unfreezeVault() names its generation: another one is stale, the freeze stays')
        : ko('a refused unfreeze lifted the freeze');
}
$storage->unfreezeVault($fg) && !$storage->unfreezeVault($fg) && $storage->frozenState('user-frz') === null
    ? ok('unfreezeVault() lifts it once, then says there was nothing to lift')
    : ko('unfreezeVault() did not lift the freeze as it says');
$storage->saveFields($fg, ['note' => ['ciphertext' => 'après le gel']]);
$storage->loadFields('user-frz', ['note']) === ['note' => 'après le gel']
    ? ok('unfrozen, it takes writes again')
    : ko('the vault still refuses writes after unfreezeVault()');

$storage->freezeVault($fg, 'arbitre', new DateTimeImmutable());
$storage->eraseAccount('user-frz') === 2 && !$storage->vaultExists('user-frz')
    ? ok('eraseAccount() erases a frozen account, its archive with it')
    : ko('a freeze kept the account from being erased');

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Archive Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
