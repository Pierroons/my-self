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
require_once __DIR__ . '/pepper_fixture.php';

use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Fields\BlindIndex;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\DuplicateIndexException;
use Pierroons\SelfDataGuard\Storage\ErasesArchives;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Storage\TicketAlreadyAppliedException;
use Pierroons\SelfDataGuard\Storage\UnreadableEscrowException;
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
$dg       = new SelfDataGuard($storage, $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
$uv       = new UserVault();
$admin    = SelfDataGuard::generateAdminRecoveryKey('admin passphrase for the archive bench');

const EMAIL  = 'ancien@example.org';
const PHRASE = 'cheval agrafe batterie correct moulin ivoire';

$old = $dg->register('user-arch', 'arch-password-0001', hash('sha256', 'arch-memorized'), PHRASE);
$dg->setFields($old, ['email' => EMAIL, 'note' => 'ancienne note'], indexed: ['email']);
$dg->setEscrowFields($old, $admin['publicKey'], ['contact' => 'contact de secours']);
$oldRecord = $storage->loadVault('user-arch');
$emailIndex = BlindIndex::compute(EMAIL, $blindKey, 'email');

// -----------------------------------------------------------------------------

section('replaceWithArchive — the live vault is set aside, the new one takes its place');

$new = $uv->register('user-arch', 'arch-password-0002', hash('sha256', 'arch-memorized-2'), 'tapis girafe lundi orage piment velours');
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
$wdg = new SelfDataGuard(new SqliteAdapter("sqlite:{$wfile}"), $blindKey, pepper: benchPepper(), forms: \Pierroons\SelfDataGuard\Vault\PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
$wdg->register('user-wait', 'wait-password-0001', hash('sha256', 'wait-memorized'));
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

$boomOld = $dg->register('user-boom', 'boom-password-001', hash('sha256', 'boom-memorized'));
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
    $s = $dg->register($userId, "{$userId}-password-1", hash('sha256', "{$userId}-memorized"), PHRASE);
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

section('The escrow row comes back whole: a column the package carries is kept, one the database lacks refuses');

$col = $dg->register('user-col', 'user-col-password-1', hash('sha256', 'user-col-memorized'), PHRASE);
$dg->setEscrowFields($col, $admin['publicKey'], ['contact' => 'contact col']);
$pdo->exec('ALTER TABLE selfdataguard_escrow ADD COLUMN bench_extra TEXT');
$pdo->exec("UPDATE selfdataguard_escrow SET bench_extra = 'kept' WHERE user_id = 'user-col'");
$colRe = $dg->reEnroll($storage->loadVault('user-col')->generation(), 'user-col-password-2');
$storage->swapWithArchive($storage->loadVault('user-col')->generation(), $colRe['archiveId']);
$pdo->query("SELECT bench_extra FROM selfdataguard_escrow WHERE user_id = 'user-col'")->fetchColumn() === 'kept'
    ? ok('a column of the escrow row comes back with it, as the package kept it')
    : ko('a column of the escrow row was lost on its way back: the restoration names its columns');
$colRe2 = $dg->reEnroll($storage->loadVault('user-col')->generation(), 'user-col-password-3');
$pdo->prepare('UPDATE selfdataguard_archives SET package = json_set(package, \'$.escrow.from_a_newer_version\', \'x\') WHERE archive_id = :id')
    ->execute([':id' => $colRe2['archiveId']]);
try {
    $storage->swapWithArchive($storage->loadVault('user-col')->generation(), $colRe2['archiveId']);
    ko('an escrow column the database does not have was dropped on the way back');
} catch (RuntimeException $e) {
    str_contains($e->getMessage(), 'from_a_newer_version') && count($storage->listArchives('user-col')) === 2
        ? ok('an escrow column the database lacks refuses the swap, naming it — nothing changed')
        : ko('refused, but without naming the column, or something changed', $e->getMessage());
}

section('An archive reserves the values it indexed: no other account takes them while it waits');

/** The blind index the live field of $userId carries; false when the field is not live. */
$blindOf = static function (string $userId, string $field) use ($pdo): mixed {
    $stmt = $pdo->prepare('SELECT blind_index FROM selfdataguard_fields WHERE user_id = ? AND field_name = ?');
    $stmt->execute([$userId, $field]);
    return $stmt->fetchColumn();
};

$dup = reEnrolled($dg, $storage, 'user-dup', 'dup@example.org', $admin);
$dupIndex = BlindIndex::compute('dup@example.org', $blindKey, 'email');
($storage->loadArchive('user-dup', $dup['archiveId'])?->blindIndexes ?? null) === ['email' => $dupIndex]
    ? ok('loadArchive() hands out the blind index each field held when it was archived')
    : ko('loadArchive() does not hand out the blind indexes the archive kept');
try {
    $dg->setFields($dg->register('user-taker', 'taker-password-01'), ['note' => 'du tiers', 'email' => 'dup@example.org'], indexed: ['email']);
    ko('another account indexed a value the holder\'s archive keeps: the holder would come back without it');
} catch (DuplicateIndexException $e) {
    $e->fields === ['email'] && $storage->loadFields('user-taker') === [] && $storage->findUserIdsByIndex('email', $dupIndex) === []
        ? ok('another account indexing it: DuplicateIndexException naming the field, and nothing of its batch written')
        : ko('refused, but naming another field, or with part of the batch written', implode(',', $e->fields));
}
try {
    $storage->swapWithArchive($dup['requester']->generation(), $dup['archiveId']);
    hash_equals($dup['holder']->userSalt, $storage->loadVault('user-dup')->userSalt)
        && $storage->findUserIdsByIndex('email', $dupIndex) === ['user-dup'] && $blindOf('user-dup', 'email') === $dupIndex
        ? ok('the holder comes back, the value indexed under their account again')
        : ko('the holder came back without the index of their value');
} catch (Throwable $e) {
    ko('the holder could not come back', get_class($e) . ': ' . $e->getMessage());
}

$self = reEnrolled($dg, $storage, 'user-self', 'self@example.org', $admin);
$selfIndex = BlindIndex::compute('self@example.org', $blindKey, 'email');
try {
    $dg->setFields($dg->loginWithPassword('user-self', 'user-self-password-2'), ['email' => 'self@example.org'], indexed: ['email']);
    $storage->findUserIdsByIndex('email', $selfIndex) === ['user-self']
        ? ok('the account the archive belongs to indexes the value again, from its new vault')
        : ko('the account the archive belongs to did not get the index');
} catch (Throwable $e) {
    ko('the archive held its value against its own account', get_class($e) . ': ' . $e->getMessage());
}
$storage->deleteArchive($self['requester']->generation(), $self['archiveId']);
$dg->setFields($dg->loginWithPassword('user-self', 'user-self-password-2'), ['email' => 'nouveau@example.org'], indexed: ['email']);
try {
    $dg->setFields($dg->register('user-self-next', 'self-next-password-1'), ['email' => 'self@example.org'], indexed: ['email']);
    $storage->findUserIdsByIndex('email', $selfIndex) === ['user-self-next']
        ? ok('once no archive and no live field holds it, another account takes the value')
        : ko('the value is not indexed under the account that took it');
} catch (Throwable $e) {
    ko('a value no archive keeps any more stays held', get_class($e) . ': ' . $e->getMessage());
}

section('The reservation lasts while the archive can come back: RETURN_WINDOW after it was made, or while its account is frozen');

/** Archive $archiveId, made $days days ago as far as the database tells: the time a reservation is judged by. */
$age = static function (string $archiveId, float $days) use ($pdo): void {
    $at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . (int) round($days * 86400) . ' seconds');
    $pdo->prepare('UPDATE selfdataguard_archives SET archived_at = ? WHERE archive_id = ?')->execute([$at->format('c'), $archiveId]);
};
/** Whether $userId indexes $value under 'email': true, or the class of what was raised. */
$takes = static function (Pierroons\SelfDataGuard\Vault\UnlockedVault $session, string $value) use ($dg): mixed {
    try {
        $dg->setFields($session, ['email' => $value], indexed: ['email']);
        return true;
    } catch (Throwable $e) {
        return get_class($e);
    }
};
/** reservedIndexes(), or the class of what it raised. */
$reservations = static function () use ($storage): mixed {
    try {
        return $storage->reservedIndexes();
    } catch (Throwable $e) {
        return get_class($e);
    }
};

$xav = reEnrolled($dg, $storage, 'user-xavier', 'recycle@example.org', $admin);
$dg->setFields($dg->loginWithPassword('user-xavier', 'user-xavier-password-2'), ['email' => 'nouveau-xavier@example.org'], indexed: ['email']);
$bea = $dg->register('user-bea', 'bea-password-0001');
$takes($bea, 'recycle@example.org') === DuplicateIndexException::class
    ? ok('the day it is made, the archive keeps the value from another account')
    : ko('a fresh archive did not keep its value from another account');
$listed = $reservations();
is_array($listed) && in_array(['user-xavier', $xav['archiveId'], 'email', false], array_map(
    static fn (array $r): array => [$r['userId'], $r['archiveId'], $r['field'], $r['frozen']], $listed), true)
    ? ok('reservedIndexes() lists it for the operator — account, archive, field, until when —, and no index')
    : ko('reservedIndexes() does not list the reservation', var_export($listed, true));
is_array($listed) && array_filter($listed, static fn (array $r): bool => array_diff(array_keys($r), ['userId', 'archiveId', 'field', 'until', 'frozen']) !== []) === []
    && (($listed[0]['until'] ?? null) instanceof DateTimeImmutable)
    ? ok('each entry holds names and a date, nothing more')
    : ko('a reservation entry carries something else than names and dates', var_export($listed, true));
$age($xav['archiveId'], 29);
$takes($bea, 'recycle@example.org') === DuplicateIndexException::class
    ? ok('at day 29 the reservation holds: the holder may still come back')
    : ko('the reservation ended before the window did');
$age($xav['archiveId'], 31);
$takes($bea, 'recycle@example.org') === true && $storage->findUserIdsByIndex('email', BlindIndex::compute('recycle@example.org', $blindKey, 'email')) === ['user-bea']
    ? ok('at day 31 it has ended: the recycled value goes to the account that holds it now')
    : ko('the reservation outlived the window: the recycled value stays refused');
$listed = $reservations();
is_array($listed) && !in_array($xav['archiveId'], array_column($listed, 'archiveId'), true)
    ? ok('an ended reservation is no longer listed')
    : ko('reservedIndexes() still lists an ended reservation');

$yara = reEnrolled($dg, $storage, 'user-yara', 'gel-yara@example.org', $admin);
$storage->freezeVault($yara['requester']->generation(), 'arbitre', new DateTimeImmutable());
$age($yara['archiveId'], 40);
$zed = $dg->register('user-zed', 'zed-password-0001');
$listed = $reservations();
$takes($zed, 'gel-yara@example.org') === DuplicateIndexException::class
    && is_array($listed) && in_array([$yara['archiveId'], true], array_map(static fn (array $r): array => [$r['archiveId'], $r['frozen']], $listed), true)
    ? ok('past the window, a frozen account still keeps the value — listed as held by the freeze')
    : ko('a frozen account lost its reservation once the window was over', var_export($listed, true));
$storage->unfreezeVault($yara['requester']->generation());
$takes($zed, 'gel-yara@example.org') === true
    ? ok('the window closed by the arbiter, the reservation ends with it')
    : ko('the reservation outlived both the window and the freeze');

$pdo->exec('DROP INDEX IF EXISTS selfdataguard_archives_archived_at');
new SqliteAdapter($pdo);
new SqliteAdapter($pdo);
(bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = 'selfdataguard_archives_archived_at'")->fetchColumn()
    ? ok('a database without the index on archived_at gains it as it opens, and opening it again changes nothing')
    : ko('the index that picks the reserving archives by date is missing');

$ex = reEnrolled($dg, $storage, 'user-exempt', 'exempt@example.org', $admin);
$exHolder = $dg->register('user-exempt-other', 'exempt-other-password-1');
$pdo->prepare('INSERT INTO selfdataguard_fields (user_id, field_name, ciphertext, blind_index, updated_at) VALUES (?, ?, ?, ?, ?)')
    ->execute(['user-exempt-other', 'email', 'c-before-0.9.1', BlindIndex::compute('exempt@example.org', $blindKey, 'email'), gmdate('c')]);
$takes($exHolder, 'exempt@example.org') === true
    ? ok('an account that already holds the value live writes it again, while an archive of another account keeps it')
    : ko('an account that held the value before 0.9.1 can no longer write its own field');

section('A value two accounts indexed before 0.9.1: the field comes back without its index, and keeps a trace');

/** A value another account indexes live while an archive keeps it — what a database written before 0.9.1 may hold. */
$holdLive = static function (string $userId, string $field, string $index) use ($pdo): void {
    $pdo->prepare('INSERT INTO selfdataguard_fields (user_id, field_name, ciphertext, blind_index, updated_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $field, 'c-before-0.9.1', $index, gmdate('c')]);
};
/** lostIndexes(), or the class of what it raised. */
$traceOf = static function (?string $userId) use ($storage): mixed {
    try {
        return $storage->lostIndexes($userId);
    } catch (Throwable $e) {
        return get_class($e);
    }
};

$both = reEnrolled($dg, $storage, 'user-both', 'both@example.org', $admin);
$bothIndex = BlindIndex::compute('both@example.org', $blindKey, 'email');
$dg->register('user-both-taker', 'col-taker-password-1');
$holdLive('user-both-taker', 'email', $bothIndex);
$bothBack = false;
try {
    $storage->swapWithArchive($both['requester']->generation(), $both['archiveId']);
    $bothBack = hash_equals($both['holder']->userSalt, $storage->loadVault('user-both')->userSalt)
        && array_keys($storage->loadFields('user-both')) == ['email', 'note'];
    $bothBack ? ok('swapWithArchive() goes through: the holder\'s vault is live, every field back') : ko('the holder\'s vault is not live with all its fields');
} catch (Throwable $e) {
    ko('a value held by both accounts refused the swap: the holder\'s vault stays out', get_class($e) . ': ' . $e->getMessage());
}
if (!$bothBack) {
    ko('the trace of the lost index: not reached — the archive did not come back');
} else {
    $storage->findUserIdsByIndex('email', $bothIndex) === ['user-both-taker'] && str_starts_with((string) $blindOf('user-both', 'email'), 'lost:')
        ? ok('the field comes back without its index — the value stays with the other account —, the trace of the lost one in its place')
        : ko('the taken index moved, or the field came back without a trace', var_export($blindOf('user-both', 'email'), true));
    $traceOf('user-both') === ['user-both' => ['email']] && ($traceOf(null)['user-both'] ?? null) === ['email']
        ? ok('lostIndexes() names the field, for the account and in the listing of every account')
        : ko('lostIndexes() does not name the field', var_export($traceOf('user-both'), true));
    $bothSession = $dg->loginWithPassword('user-both', 'user-both-password-1');
    $dg->setFields($bothSession, ['email' => 'both@example.org']);
    $traceOf('user-both') === ['user-both' => ['email']]
        ? ok('written again without an index, the field keeps its trace')
        : ko('a write without an index took the trace away');
    $bothAgain = $dg->reEnroll($bothSession->generation(), 'user-both-password-3');
    $kept = $storage->loadArchive('user-both', $bothAgain['archiveId']);
    !isset($kept?->blindIndexes['email']) && ($kept?->privateFields['email']['wasIndexed'] ?? null) === true
        ? ok('archived again, the field keeps its trace and hands out no index: it reads as indexed, without one kept')
        : ko('archived again, the trace was handed out as a blind index', var_export($kept?->blindIndexes, true));
    $storage->swapWithArchive($bothAgain['unlocked']->generation(), $bothAgain['archiveId']);
    $traceOf('user-both') === ['user-both' => ['email']]
        ? ok('brought back again, the trace comes back with it')
        : ko('the trace did not survive a second archive');
    try {
        $dg->setFields($bothSession, ['email' => 'both@example.org'], indexed: ['email']);
        ko('the holder indexed a value another account holds live');
    } catch (DuplicateIndexException) {
        ok('indexed while the other account holds the value: DuplicateIndexException');
    }
    $storage->eraseAccount('user-both-taker');
    $dg->setFields($bothSession, ['email' => 'both@example.org'], indexed: ['email']);
    $storage->findUserIdsByIndex('email', $bothIndex) === ['user-both'] && $traceOf('user-both') === []
        ? ok('once the other account let it go, the holder indexes it again, and the trace goes')
        : ko('indexed again, the field still carries its trace', var_export($traceOf('user-both'), true));
}

$both2 = reEnrolled($dg, $storage, 'user-both2', 'both2@example.org', $admin);
$dg->register('user-both2-taker', 'col2-taker-password-1');
$holdLive('user-both2-taker', 'email', BlindIndex::compute('both2@example.org', $blindKey, 'email'));
$storage->deleteVault($both2['requester']->generation());
try {
    $storage->restoreArchive('user-both2', $both2['archiveId']);
    hash_equals($both2['holder']->userSalt, $storage->loadVault('user-both2')->userSalt)
        && $storage->findUserIdsByIndex('email', BlindIndex::compute('both2@example.org', $blindKey, 'email')) === ['user-both2-taker']
        && $traceOf('user-both2') === ['user-both2' => ['email']]
        ? ok('restoreArchive(): the same, for an account left without a live vault')
        : ko('restoreArchive() did not bring the field back without the taken index, with its trace');
} catch (Throwable $e) {
    ko('a value held by both accounts refused the restoration', get_class($e) . ': ' . $e->getMessage());
}

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

section('An archive whose escrow does not parse is read, its escrow named unreadable, and comes back as it was kept');

/** Archive $archiveId with its escrow's wrap_admin rewritten so that it no longer parses. */
$unparse = static fn (string $archiveId) => $pdo->prepare(
    "UPDATE selfdataguard_archives SET package = json_set(package, '$.escrow.wrap_admin', '%%%') WHERE archive_id = :id"
)->execute([':id' => $archiveId]);

$esc = reEnrolled($dg, $storage, 'user-badesc', 'badesc@example.org', $admin);
$readable = $storage->loadArchive('user-badesc', $esc['archiveId']);
$readable?->escrow !== null && ($readable->escrowUnreadable ?? null) === false
    && ($storage->loadArchive('user-swap', $setAside)?->escrowUnreadable ?? null) === false
    ? ok('an archive whose escrow parses, and one without escrow, are not named unreadable')
    : ko('escrowUnreadable is not false on an archive whose escrow parses, or that holds none');
$unparse($esc['archiveId']);
$unreadable = null;
try {
    $unreadable = $storage->loadArchive('user-badesc', $esc['archiveId']);
} catch (Throwable $e) {
    ko('loadArchive() refused the whole archive for its escrow', get_class($e) . ': ' . $e->getMessage());
}
$unreadable !== null && $unreadable->escrow === null && ($unreadable->escrowUnreadable ?? null) === true
    && array_keys($unreadable->privateFields) == ['email', 'note'] && array_keys($unreadable->escrowFields) === ['contact']
    && hash_equals($esc['holder']->userSalt, $unreadable->record->userSalt)
    ? ok('loadArchive(): the archive, its escrow null and named unreadable, its vault, fields and escrow fields kept')
    : ko('loadArchive() does not name the escrow unreadable, or lost what the archive keeps');
try {
    $storage->swapWithArchive($esc['requester']->generation(), $esc['archiveId']);
    ok('swapWithArchive() brings it back');
} catch (Throwable $e) {
    ko('an escrow that does not parse refused the swap: the holder\'s vault stays out', get_class($e) . ': ' . $e->getMessage());
}
try {
    $storage->loadEscrow('user-badesc');
    ko('the escrow row did not come back as it was kept');
} catch (UnreadableEscrowException) {
    hash_equals($esc['holder']->userSalt, $storage->loadVault('user-badesc')->userSalt)
        && $storage->loadEscrowFields('user-badesc') !== []
        ? ok('the escrow row is live again as it was kept — it reads as a row that does not parse —, its fields with it')
        : ko('the escrow fields did not come back with the row');
}

$esc2 = reEnrolled($dg, $storage, 'user-badesc2', 'badesc2@example.org', $admin);
$unparse($esc2['archiveId']);
$storage->deleteVault($esc2['requester']->generation());
try {
    $storage->restoreArchive('user-badesc2', $esc2['archiveId']);
    try {
        $storage->loadEscrow('user-badesc2');
        ko('restoreArchive(): the escrow row did not come back as it was kept');
    } catch (UnreadableEscrowException) {
        ok('restoreArchive() brings it back too, its escrow row as it was kept');
    }
} catch (Throwable $e) {
    ko('an escrow that does not parse refused the restoration', get_class($e) . ': ' . $e->getMessage());
}

section('An escrow kept in a form that is not a row reads as one that does not parse — no form of it closes the archive');

$forms = [
    'wrap_user missing'         => "json_remove(package, '$.escrow.wrap_user')",
    'created_at holding null'   => "json_set(package, '$.escrow.created_at', json('null'))",
    'revision null, wrap_admin unreadable' => "json_set(package, '$.escrow.revision', json('null'), '$.escrow.wrap_admin', '%%%')",
    'wrap_admin an object'      => "json_set(package, '$.escrow.wrap_admin', json('{\"a\": 1}'))",
    'a string, not a row'       => "json_set(package, '$.escrow', 'pas une ligne')",
    'a list, not a row'         => "json_set(package, '$.escrow', json('[1, 2]'))",
    'an empty row'              => "json_set(package, '$.escrow', json('{}'))",
];
$n = 0;
foreach ($forms as $form => $rewrite) {
    $n++;
    $f = reEnrolled($dg, $storage, "user-form{$n}", "form{$n}@example.org", $admin);
    $pdo->prepare("UPDATE selfdataguard_archives SET package = {$rewrite} WHERE archive_id = :id")->execute([':id' => $f['archiveId']]);
    $warnings = [];
    set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
        $warnings[] = $message;
        return true;
    });
    try {
        $read = $storage->loadArchive("user-form{$n}", $f['archiveId']);
        if ($n % 2 === 0) {
            $storage->swapWithArchive($f['requester']->generation(), $f['archiveId']);
        } else {
            $storage->deleteVault($f['requester']->generation());
            $storage->restoreArchive("user-form{$n}", $f['archiveId']);
        }
        try {
            $storage->loadEscrow("user-form{$n}");
            $row = 'parses';
        } catch (UnreadableEscrowException) {
            $row = 'does not parse';
        }
        $read?->escrow === null && ($read->escrowUnreadable ?? null) === true && $warnings === []
            && hash_equals($f['holder']->userSalt, $storage->loadVault("user-form{$n}")->userSalt)
            && $row === 'does not parse' && $storage->loadEscrowFields("user-form{$n}") !== []
            ? ok("{$form}: the archive reads, its escrow named unreadable, and comes back with a row that does not parse")
            : ko("{$form}: not read and brought back as an escrow that does not parse", "row {$row}; " . implode(' | ', $warnings));
    } catch (Throwable $e) {
        ko("{$form}: the archive was refused, or could not come back", get_class($e) . ': ' . $e->getMessage());
    } finally {
        restore_error_handler();
    }
}

section('Escrow fields kept in a form that is not a map of ciphertexts read as an escrow that does not parse');

$fieldForms = [
    'fields.escrow a string'   => ["'$.fields.escrow'", "'pas des champs'", 0],
    'fields.escrow null'       => ["'$.fields.escrow'", "json('null')", 0],
    'a ciphertext an object'   => ["'$.fields.escrow.contact'", "json('{\"a\": 1}')", 1],
];
$n = 0;
foreach ($fieldForms as $form => [$path, $value, $back]) {
    $n++;
    $f = reEnrolled($dg, $storage, "user-ffield{$n}", "ffield{$n}@example.org", $admin);
    $pdo->prepare("UPDATE selfdataguard_archives SET package = json_set(package, {$path}, {$value}) WHERE archive_id = :id")->execute([':id' => $f['archiveId']]);
    $warnings = [];
    set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
        $warnings[] = $message;
        return true;
    });
    try {
        $read = $storage->loadArchive("user-ffield{$n}", $f['archiveId']);
        $storage->swapWithArchive($f['requester']->generation(), $f['archiveId']);
        ($read->escrowUnreadable ?? null) === true && $warnings === []
            && hash_equals($f['holder']->userSalt, $storage->loadVault("user-ffield{$n}")->userSalt)
            && count($storage->loadEscrowFields("user-ffield{$n}")) === $back
            ? ok("{$form}: the archive reads, its escrow named unreadable, and comes back — no TypeError, no PHP warning")
            : ko("{$form}: not read and brought back as an escrow that does not parse", implode(' | ', $warnings));
    } catch (Throwable $e) {
        ko("{$form}: the archive was refused, or could not come back", get_class($e) . ': ' . $e->getMessage());
    } finally {
        restore_error_handler();
    }
}

section('delete() unties the tickets of an account left with nothing, as eraseAccount() does');

$dg->register('user-del', 'del-password-0001');
$delGen = $storage->loadVault('user-del')->generation();
$storage->freezeVault($delGen, 'arbitre', new DateTimeImmutable(), 'ticket-del-gel');
$storage->unfreezeVault($delGen, 'ticket-del-cloture');
$storage->deleteVault($delGen);
$untied = (int) $pdo->query("SELECT COUNT(*) FROM selfdataguard_tickets WHERE ticket IN ('ticket-del-gel', 'ticket-del-cloture') AND user_id = ''")->fetchColumn();
$untied === 2
    ? ok('delete() of an account without archives: its tickets stay, naming no account')
    : ko('after delete(), the tickets still name the account: whoever registers the name next inherits them', "untied {$untied}");

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

$storage->freezeVault($fg, 'arbitre', new DateTimeImmutable(), 'ticket-frz-gel');
/** Everything the database holds of $userId, row by row. */
$rowsOf = static function (string $userId) use ($pdo): array {
    $rows = [];
    foreach (['selfdataguard_vaults', 'selfdataguard_fields', 'selfdataguard_escrow', 'selfdataguard_escrow_fields', 'selfdataguard_archives', 'selfdataguard_tickets'] as $table) {
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE user_id = ? ORDER BY rowid");
        $stmt->execute([$userId]);
        $rows[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $rows;
};
$frzBefore = $rowsOf('user-frz');
try {
    $frzErased = $storage->eraseAccount('user-frz');
    ko('eraseAccount() of a frozen account went through: the arbiter has not looked at it yet', "erased {$frzErased}");
} catch (VaultFrozenException) {
    ok('eraseAccount() of a frozen account → VaultFrozenException');
}
$rowsOf('user-frz') === $frzBefore && $frzBefore['selfdataguard_archives'] !== [] && $frzBefore['selfdataguard_tickets'] !== []
    ? ok('nothing of it went: live vault, fields, escrow, archive and tickets, row for row')
    : ko('a refused erasure of a frozen account erased part of it');
try {
    $storage->unfreezeVault($fg);
    $frzErased = $storage->eraseAccount('user-frz');
    $frzErased === 2 && !$storage->vaultExists('user-frz') && $storage->listArchives('user-frz') === []
        ? ok('once unfrozen — the closure —, the request goes through: the vault and its archive are erased')
        : ko('the erasure after the closure did not go through', "erased {$frzErased}");
} catch (Throwable $e) {
    ko('the erasure after the closure could not run: the frozen vault was already gone', get_class($e) . ': ' . $e->getMessage());
}

// -----------------------------------------------------------------------------

section('A recovery ticket is recorded with what it applied, once');

$dg->register('user-tk', 'tk-password-0001');
$tkFirst = $storage->loadVault('user-tk');
$tkNew = $uv->register('user-tk', 'tk-password-0002')['record'];
$tkArchive = $storage->replaceWithArchive($tkFirst->generation(), $tkNew, 'ticket-niveau-3');
$storage->archiveOfTicket('ticket-niveau-3') === $tkArchive && $storage->archiveOfTicket('ticket-inconnu') === null
    ? ok('archiveOfTicket() names the archive the re-enrolment under that ticket set aside, and nothing for another')
    : ko('archiveOfTicket() did not name the archive of its ticket');
$replayed = null;
try {
    $storage->replaceWithArchive($tkNew->generation(), $uv->register('user-tk', 'tk-password-0003')['record'], 'ticket-niveau-3');
} catch (Pierroons\SelfDataGuard\Storage\TicketAlreadyAppliedException $e) {
    $replayed = $e;
}
$replayed !== null && hash_equals($tkNew->userSalt, $storage->loadVault('user-tk')->userSalt)
    && count($storage->listArchives('user-tk')) === 1
    ? ok('a ticket applied twice: TicketAlreadyAppliedException, and nothing re-enrolled')
    : ko('a replayed ticket re-enrolled the vault again');
$tkGen = $storage->loadVault('user-tk')->generation();
$storage->freezeVault($tkGen, 'arbitre', new DateTimeImmutable(), 'ticket-retour');
$storage->unfreezeVault($tkGen, 'ticket-cloture');
$replayed = null;
try {
    $storage->freezeVault($tkGen, 'arbitre', new DateTimeImmutable(), 'ticket-retour');
} catch (Pierroons\SelfDataGuard\Storage\TicketAlreadyAppliedException $e) {
    $replayed = $e;
}
$replayed !== null && $storage->frozenState('user-tk') === null
    ? ok('a freeze replayed under its ticket is refused, and the vault stays unfrozen')
    : ko('a replayed freeze froze the vault again');
/** The operations of the tickets the database holds for $userId, sorted. */
$ticketsOf = static function (string $userId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT operation FROM selfdataguard_tickets WHERE user_id = ? ORDER BY operation');
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
};

$storage->eraseAccount('user-tk');
$ticketsOf('user-tk') === ['freeze', 'replace-with-archive', 'unfreeze'] && array_column($storage->listArchives('user-tk'), 'id') === [$tkArchive]
    ? ok('eraseAccount() erases no ticket of the account, and keeps the archive of its level 3')
    : ko('eraseAccount() erased a ticket or the archive of the level 3', json_encode($ticketsOf('user-tk')));

// -----------------------------------------------------------------------------

section('eraseAccount() keeps every archive of an account a level-3 ticket was applied to, whichever write set it aside');

$dg->register('user-keep', 'keep-password-0001');
$keepHolder    = $storage->loadVault('user-keep');
$keepRequester = $uv->register('user-keep', 'keep-password-0002')['record'];
$keepArchive   = $storage->replaceWithArchive($keepHolder->generation(), $keepRequester, 'ticket-keep-l3');
$keepThird     = $uv->register('user-keep', 'keep-password-0003')['record'];
$keepSecond    = $storage->replaceWithArchive($keepRequester->generation(), $keepThird);
$storage->freezeVault($keepThird->generation(), 'arbitre', new DateTimeImmutable(), 'ticket-keep-retour');
$storage->unfreezeVault($keepThird->generation(), 'ticket-keep-cloture');
$erased = $storage->eraseAccount('user-keep');
$erased === 1 && !$storage->vaultExists('user-keep')
    ? ok('eraseAccount() erases the live vault, and counts it alone')
    : ko('eraseAccount() erased more than the live vault', "erased {$erased}");
$keptIds = array_column($storage->listArchives('user-keep'), 'id');
$keptIds === [$keepArchive, $keepSecond]
    && hash_equals($keepHolder->userSalt, (string) $storage->loadArchive('user-keep', $keepArchive)?->record->userSalt)
    ? ok('both archives stay — the holder\'s vault, and the one set aside without a ticket')
    : ko('eraseAccount() erased an archive of an account under a level 3', json_encode($keptIds));
$storage->archiveOfTicket('ticket-keep-l3') === $keepArchive && $ticketsOf('user-keep') === ['freeze', 'replace-with-archive', 'unfreeze']
    ? ok('every ticket stays, the level-3 one still naming its archive')
    : ko('a ticket did not stay', json_encode($ticketsOf('user-keep')));

$dg->register('user-back3', 'back3-password-0001');
$b3Holder    = $storage->loadVault('user-back3');
$b3Requester = $uv->register('user-back3', 'back3-password-0002')['record'];
$b3Archive   = $storage->replaceWithArchive($b3Holder->generation(), $b3Requester, 'ticket-back3-l3');
$b3Swapped   = $storage->swapWithArchive($b3Requester->generation(), $b3Archive, 'ticket-back3-retour');
$b3Second    = $storage->swapWithArchive($b3Holder->generation(), $b3Swapped, 'ticket-back3-retour-2');
$erased = $storage->eraseAccount('user-back3');
$erased === 1 && array_column($storage->listArchives('user-back3'), 'id') === [$b3Second]
    && hash_equals($b3Holder->userSalt, (string) $storage->loadArchive('user-back3', $b3Second)?->record->userSalt)
    ? ok('after a second exchange, the holder\'s vault — set aside by a swap, named by no level-3 ticket — stays')
    : ko('eraseAccount() erased the vault a swap set aside', "erased {$erased}");
$ticketsOf('user-back3') === ['replace-with-archive', 'swap-with-archive', 'swap-with-archive']
    ? ok('the level-3 ticket and the two swap tickets stay after the erasure')
    : ko('the tickets after the erasure are wrong', json_encode($ticketsOf('user-back3')));

$dg->register('user-plain', 'plain-password-0001');
$plainLive = $uv->register('user-plain', 'plain-password-0002')['record'];
$storage->replaceWithArchive($storage->loadVault('user-plain')->generation(), $plainLive);
$storage->freezeVault($plainLive->generation(), 'arbitre', new DateTimeImmutable());
$storage->unfreezeVault($plainLive->generation());
$erased = $storage->eraseAccount('user-plain');
$erased === 2 && $storage->listArchives('user-plain') === []
    ? ok('no level-3 ticket, no freeze: eraseAccount() erases the live vault and every archive, as before')
    : ko('eraseAccount() kept an archive no case asks for', "erased {$erased}");

section('eraseArchive() and keptArchives() — the operator lists, then erases, what eraseAccount() kept');

$storage instanceof ErasesArchives
    ? ok('SqliteAdapter implements ErasesArchives')
    : ko('SqliteAdapter does not implement ErasesArchives');
/** The ids keptArchives() lists for $userId, or the class of what it raised. */
$keptOf = static function (?string $userId) use ($storage): mixed {
    try {
        return array_column(array_filter($storage->keptArchives(), static fn (array $a): bool => $userId === null || $a['userId'] === $userId), 'id');
    } catch (Throwable $e) {
        return get_class($e);
    }
};
$keptOf('user-keep') === [$keepArchive, $keepSecond] && $keptOf('user-swap') === []
    ? ok('keptArchives() lists the archives of an account left without a live vault, and none of an account that holds one')
    : ko('keptArchives() does not list what eraseAccount() kept', var_export($keptOf('user-keep'), true));
$pdo->prepare("UPDATE selfdataguard_archives SET package = 'pas du json' WHERE archive_id = ?")->execute([$b3Second]);
in_array($b3Second, (array) $keptOf('user-back3'), true)
    ? ok('it lists an archive whose package no longer reads, without opening it')
    : ko('an archive whose package no longer reads kept the listing from answering', var_export($keptOf('user-back3'), true));
try {
    !$storage->eraseArchive('user-other', $keepArchive) && $storage->loadArchive('user-keep', $keepArchive) !== null
        ? ok('eraseArchive() under another userId erases nothing')
        : ko('eraseArchive() erased an archive through another account');
    $storage->eraseArchive('user-keep', $keepArchive) && $storage->eraseArchive('user-keep', $keepSecond)
        && $storage->listArchives('user-keep') === [] && !$storage->eraseArchive('user-keep', $keepArchive)
        ? ok('eraseArchive() erases each archive kept, without a live vault, then finds nothing more')
        : ko('eraseArchive() did not erase the archives kept, or erased one twice');
    $held = (int) $pdo->query("SELECT COUNT(*) FROM selfdataguard_tickets WHERE ticket IN ('ticket-keep-l3', 'ticket-keep-retour', 'ticket-keep-cloture') AND user_id = '' AND archive_id IS NULL")->fetchColumn();
    $ticketsOf('user-keep') === [] && $held === 3 && $storage->archiveOfTicket('ticket-keep-l3') === null
        ? ok('the account holds nothing any more: its tickets stay, naming no account and no archive')
        : ko('the tickets of an account that holds nothing still name it, or were erased', json_encode($ticketsOf('user-keep')) . " held {$held}");
    $storage->eraseArchive('user-back3', $b3Second);
} catch (Throwable $e) {
    ko('eraseArchive() failed', get_class($e) . ': ' . $e->getMessage());
}

$frz2 = reEnrolled($dg, $storage, 'user-frz2', 'frz2@example.org', $admin);
$frz2Live = $frz2['requester']->generation();
$storage->freezeVault($frz2Live, 'arbitre', new DateTimeImmutable(), 'ticket-frz2-gel');
$frz2Before = $rowsOf('user-frz2');
try {
    $storage->eraseArchive('user-frz2', $frz2['archiveId']);
    ko('eraseArchive() erased an archive of a frozen account: during the case, nothing is erased');
} catch (VaultFrozenException) {
    $rowsOf('user-frz2') === $frz2Before && $frz2Before['selfdataguard_archives'] !== []
        ? ok('eraseArchive() of a frozen account → VaultFrozenException, and nothing went, row for row')
        : ko('eraseArchive() refused the frozen account, but erased part of it');
} catch (Throwable $e) {
    ko('eraseArchive() of a frozen account refused otherwise than as frozen', get_class($e) . ': ' . $e->getMessage());
}
try {
    $storage->unfreezeVault($frz2Live);
    $storage->eraseArchive('user-frz2', $frz2['archiveId']);
    $storage->listArchives('user-frz2') === []
        ? ok('once unfrozen, the operator erases the archive')
        : ko('the archive is still there after the closure and eraseArchive()');
} catch (Throwable $e) {
    ko('eraseArchive() after the closure failed', get_class($e) . ': ' . $e->getMessage());
}

$newcomer = $uv->register('user-keep', 'keep-newcomer-0001')['record'];
$storage->saveVault($newcomer);
$replayed = null;
try {
    $storage->replaceWithArchive($newcomer->generation(), $uv->register('user-keep', 'keep-password-0009')['record'], 'ticket-keep-l3');
} catch (TicketAlreadyAppliedException $e) {
    $replayed = $e;
}
$replayed !== null && hash_equals($newcomer->userSalt, $storage->loadVault('user-keep')->userSalt) && $storage->listArchives('user-keep') === []
    ? ok('the old level-3 ticket, replayed under whoever registers the name next, is refused: the newcomer\'s vault stays live')
    : ko('a level-3 ticket replayed after the erasure archived the vault of whoever registered the name next');
try {
    $storage->replaceWithArchive($storage->loadVault('user-keep')->generation(), $uv->register('user-keep', 'keep-newcomer-0002')['record']);
    $storage->eraseAccount('user-keep') === 2 && $storage->listArchives('user-keep') === []
        ? ok('the newcomer does not inherit the tickets: its archive set aside without one goes with eraseAccount()')
        : ko('the newcomer inherited the tickets of the account erased before it');
} catch (Throwable $e) {
    ko('the newcomer\'s archive could not be made, or erased', get_class($e) . ': ' . $e->getMessage());
}

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Archive Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
