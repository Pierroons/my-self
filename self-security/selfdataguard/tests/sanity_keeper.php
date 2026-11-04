<?php

declare(strict_types=1);

/**
 * Sanity test — the escrow keeper.
 *
 * Run:  php tests/sanity_keeper.php
 * Exit: 0 on success, non-zero if any check failed.
 *
 * Sealed to a keeper, an escrow opens for the administrator only through it:
 * the keeper records each release and signs, for the holder, what it
 * released. Each deposit draws a new escrow key, so that a key once obtained
 * opens nothing deposited after it. These checks play the façade, the
 * ceremony and the bench keeper together.
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/key_fixture.php';
require __DIR__ . '/ceremony_fixture.php';
require __DIR__ . '/keeper_fixture.php';
require __DIR__ . '/keeper_doubles.php';
require __DIR__ . '/storage_doubles.php';

use Pierroons\SelfDataGuard\Escrow\EscrowAnnouncedException;
use Pierroons\SelfDataGuard\Escrow\EscrowEnvelopeMismatchException;
use Pierroons\SelfDataGuard\Escrow\EscrowKeeperException;
use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\Escrow\EscrowVault;
use Pierroons\SelfDataGuard\Escrow\KeeperConformance;
use Pierroons\SelfDataGuard\Escrow\KeeperSeal;
use Pierroons\SelfDataGuard\Escrow\NoKeeper;
use Pierroons\SelfDataGuard\Escrow\EscrowExistsException;
use Pierroons\SelfDataGuard\Escrow\StaleEscrowException;
use Pierroons\SelfDataGuard\Escrow\UnlockedEscrow;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Storage\UnreadableEscrowException;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\UnlockedVault;
use Pierroons\SelfDataGuard\Vault\VaultFrozenException;
use Pierroons\SelfDataGuard\Vault\VaultGeneration;

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

/** The audit log entries of $path, oldest first. */
function logEntries(string $path): array
{
    return array_map(static fn (string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", (string) @file_get_contents($path)))));
}

const ADMIN_PASS = 'gardien-admin-recuperation-tres-longue-2026';

$admin    = SelfDataGuard::generateAdminRecoveryKey(ADMIN_PASS);
$adminSk  = unsealAdminKey($admin['sealedSecret'], ADMIN_PASS);
$storage  = new SqliteAdapter('sqlite::memory:');
$blindKey = random_bytes(32);
$keeper   = new BenchKeeper();
$dg       = new SelfDataGuard($storage, $blindKey, keeper: $keeper);
$logPath  = sys_get_temp_dir() . '/sdg-keeper-' . bin2hex(random_bytes(4)) . '.log';
$ceremony = benchCeremony($storage, logPath: $logPath, keeper: $keeper);

// -----------------------------------------------------------------------------

section('A deposit under a keeper seals the escrow to it');

$alice = $dg->register('alice', 'motdepasse-alice-01', 'alice-memorized');
$dg->setEscrowFields($alice, $admin['publicKey'], ['contact' => 'alice-secours@example.org']);
$record = $storage->loadEscrow('alice');
EscrowVault::isKeeperSealed($record) && EscrowVault::isAccountBound($record)
    ? ok('wrap_admin is sealed to the keeper, and still names its account')
    : ko('wrap_admin is not in the keeper form');
$escrowId = KeeperSeal::escrowIdOf($record->wrapAdmin);
$opened = $alice->openEscrow($record);
$opened->keeper !== null && hash_equals($opened->escrowId ?? '', (string) $escrowId)
    && KeeperSeal::fingerprint($opened->keeper) === KeeperSeal::fingerprint($keeper->publicKeys())
    ? ok("wrap_user names the escrowId and the keeper, under the holder's master key")
    : ko('wrap_user does not carry the keeper binding');
$opened->lock();
try {
    $mismatched = new UnlockedEscrow('alice', random_bytes(32), KeeperSeal::newEscrowId(), $keeper->publicKeys(), random_bytes(32));
    ko('an escrow whose escrowId is not the fingerprint of its objection key was taken');
} catch (RuntimeException) {
    ok('an escrowId that is not the fingerprint of the objection key is refused');
}
sodium_crypto_box_seal_open(KeeperSeal::outerOf($record->wrapAdmin), sodium_crypto_box_keypair_from_secretkey_and_publickey(
    $adminSk, base64_decode($admin['publicKey'])
)) === false
    ? ok('the administrator alone does not open the outer box')
    : ko('the admin key opens the outer box: the keeper is not needed');
$dg->getEscrowFieldsAsUser($alice) === ['contact' => 'alice-secours@example.org']
    ? ok('the holder reads the escrow as before')
    : ko('the holder no longer reads the escrow');

// -----------------------------------------------------------------------------

section('Each deposit draws a new escrow key; the compartment keeps its escrowId');

$before = $storage->loadEscrow('alice');
$keyBefore = heldKey($alice->openEscrow($before));
$dg->setEscrowFields($alice, $admin['publicKey'], ['indice' => 'le nom du premier chat']);
$after = $storage->loadEscrow('alice');
$keyAfter = heldKey($alice->openEscrow($after));
!hash_equals($keyBefore, $keyAfter) && $after->revision === $before->revision + 1
    ? ok('a deposit replaces the escrow key, at the next revision')
    : ko('the escrow key did not change at a deposit');
hash_equals((string) KeeperSeal::escrowIdOf($after->wrapAdmin), (string) $escrowId)
    ? ok('the escrowId stays, so that the keeper\'s record follows the compartment')
    : ko('a deposit changed the escrowId');
$fields = $dg->getEscrowFieldsAsUser($alice);
ksort($fields);
$fields === ['contact' => 'alice-secours@example.org', 'indice' => 'le nom du premier chat']
    ? ok('the fields already there are re-encrypted under the new key, with the new one')
    : ko('a deposit lost or garbled a field', json_encode($fields));
$old = new UnlockedEscrow('alice', $keyBefore);
try {
    $old->decryptField('indice', Pierroons\SelfDataGuard\Crypto\EncryptedBlob::fromBase64($storage->loadEscrowFields('alice', ['indice'])['indice']));
    ko('the key of before the deposit opens a field deposited after it');
} catch (RuntimeException) {
    ok('the key of before the deposit opens nothing deposited after it');
}
$old->lock();

// -----------------------------------------------------------------------------

section('The ceremony reads through the keeper, which records the release');

$read = $ceremony->readEscrow('alice', 'LIT-1', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
ksort($read);
$read === $fields
    ? ok('the administrator reads the escrow, the keeper having handed back the inner box')
    : ko('the ceremony did not read the escrow through the keeper', json_encode($read));
$released = $keeper->releases[bin2hex((string) $escrowId)] ?? [];
count($released) === 1 && $released[0]['case'] === 'LIT-1'
    ? ok('the keeper recorded the release, with its case')
    : ko('the keeper did not record the release');
$intent = array_values(array_filter(logEntries($logPath), static fn (array $e): bool => ($e['event']['action'] ?? '') === 'escrow-unlock-intent'));
($intent[0]['event']['keeper'] ?? null) === KeeperSeal::fingerprint($keeper->publicKeys()) && ($intent[0]['seq'] ?? null) === $released[0]['seq']
    ? ok('the intention names the keeper by its fingerprint, and the keeper holds the head of that intention')
    : ko('the intention does not name the keeper, or the keeper holds another head', json_encode($intent[0] ?? null));

// -----------------------------------------------------------------------------

section('The holder sees, signed by the keeper, every read');

$reads = $dg->escrowReads($alice);
$reads['established'] && $reads['pinned'] && !$reads['reseal'] && count($reads['reads']) === 1 && $reads['reads'][0]['case'] === 'LIT-1'
    && $reads['keeper'] === KeeperSeal::fingerprint($keeper->publicKeys())
    ? ok('escrowReads(): established, the keeper pinned by the deposit, one read under LIT-1')
    : ko('escrowReads() did not return the read the keeper signed', json_encode($reads));
$keeper->down = true;
$reads = $dg->escrowReads($alice);
!$reads['established'] && $reads['reason'] === 'keeper-unreachable' && $reads['reads'] === []
    ? ok('a keeper that does not answer gives "not established", with its reason — never zero reads')
    : ko('a silent keeper read as no read', json_encode($reads));
$keeper->down = false;

$impostor = new class extends BenchKeeper {};
$wrongSigner = new class ($keeper, $impostor) extends BenchKeeper {
    public function __construct(private BenchKeeper $real, private BenchKeeper $signer) { parent::__construct(); }
    public function publicKeys(): array { return $this->real->publicKeys(); }
    public function attest(string $escrowId, string $nonce): string { return $this->signer->attest($escrowId, $nonce); }
};
$reads = (new SelfDataGuard($storage, $blindKey, keeper: $wrongSigner))->escrowReads($alice);
!$reads['established'] && $reads['reason'] === 'signature'
    ? ok('an attestation signed by another key than the keeper the escrow names is refused: "signature"')
    : ko('an attestation under another key was taken', json_encode($reads));
$replayed = new class ($keeper) extends BenchKeeper {
    public ?string $kept = null;
    public function __construct(private BenchKeeper $real) { parent::__construct(); }
    public function publicKeys(): array { return $this->real->publicKeys(); }
    public function attest(string $escrowId, string $nonce): string { return $this->kept ??= $this->real->attest($escrowId, $nonce); }
};
$replayDg = new SelfDataGuard($storage, $blindKey, keeper: $replayed);
$replayDg->escrowReads($alice);
$reads = $replayDg->escrowReads($alice);
!$reads['established'] && $reads['reason'] === 'nonce'
    ? ok('an old attestation replayed for a new request is refused: "nonce"')
    : ko('a replayed attestation was taken', json_encode($reads));

$typoLog = sys_get_temp_dir() . '/sdg-keeper-typo-' . bin2hex(random_bytes(4)) . '.log';
$releasesBefore = count($keeper->releases[bin2hex((string) $escrowId)] ?? []);
try {
    benchCeremony($storage, logPath: $typoLog, keeper: $keeper)->readEscrow('alice', 'LIT-TYPO', $admin['sealedSecret'], 'une passphrase mal tapée', $admin['publicKey']);
    ko('a wrong passphrase read the escrow');
} catch (Pierroons\SelfDataGuard\Vault\WrongSecretException) {
    $last = logEntries($typoLog);
    !isset($keeper->pending[bin2hex((string) $escrowId) . '|LIT-TYPO'])
        && count($keeper->releases[bin2hex((string) $escrowId)] ?? []) === $releasesBefore
        && (end($last)['event']['reason'] ?? null) === 'bad-passphrase'
        ? ok('a wrong passphrase is refused before the keeper is asked: no announcement, no release, bad-passphrase in the log')
        : ko('a wrong passphrase reached the keeper before it was refused');
}
@unlink($typoLog);

// -----------------------------------------------------------------------------

section('Without its keeper, a compartment sealed to one does not open');

$noKeeperLog = sys_get_temp_dir() . '/sdg-keeper-none-' . bin2hex(random_bytes(4)) . '.log';
try {
    benchCeremony($storage, logPath: $noKeeperLog)->readEscrow('alice', 'LIT-2', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
    ko('a ceremony with NoKeeper opened an escrow sealed to a keeper');
} catch (EscrowKeeperException $e) {
    $last = logEntries($noKeeperLog);
    $e->reason === 'keeper-missing' && (end($last)['event']['reason'] ?? null) === 'keeper-missing'
        ? ok('NoKeeper: refused, and the log says keeper-missing')
        : ko('refused, but not as keeper-missing', $e->reason);
}
$keeper->down = true;
try {
    $ceremony->readEscrow('alice', 'LIT-3', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
    ko('a keeper that does not answer let the escrow open');
} catch (EscrowKeeperException $e) {
    $e->reason === 'keeper-failed'
        ? ok('a keeper that does not answer: refused, keeper-failed, nothing unsealed')
        : ko('refused for another reason', $e->reason);
}
$keeper->down = false;

$bob = $dg->register('bob', 'motdepasse-bob-01');
$dg->setEscrowFields($bob, $admin['publicKey'], ['contact' => 'bob-secours@example.org']);
$bobRecord = $storage->loadEscrow('bob');
$moved = new EscrowRecord('bob', $bobRecord->wrapUser, $storage->loadEscrow('alice')->wrapAdmin, $bobRecord->createdAt, new DateTimeImmutable(), $bobRecord->revision);
$storage->replaceEscrow($dg->generationOf('bob'), $moved);
try {
    $ceremony->readEscrow('bob', 'LIT-4', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
    ko("alice's keeper box, moved into bob's record, was released as bob's");
} catch (EscrowKeeperException $e) {
    $e->reason === 'keeper-failed'
        ? ok("alice's keeper box moved into bob's record: the keeper refuses, the box names another account")
        : ko('refused for another reason', $e->reason);
}
$otherLog = sys_get_temp_dir() . '/sdg-keeper-other-' . bin2hex(random_bytes(4)) . '.log';
try {
    benchCeremony($storage, logPath: $otherLog, keeper: new BenchKeeper())->readEscrow('alice', 'LIT-OTHER', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
    ko('a ceremony given another keeper than the database recorded read the escrow');
} catch (EscrowKeeperException $e) {
    $last = logEntries($otherLog);
    $e->reason === 'keeper-mismatch' && (end($last)['event']['reason'] ?? null) === 'keeper-mismatch'
        ? ok('a ceremony given another keeper than the database recorded: refused, keeper-mismatch, in the log')
        : ko('refused, but not as keeper-mismatch', $e->reason);
}
@unlink($otherLog);

// -----------------------------------------------------------------------------

section('The keeper records the log head it is given, and does not judge it');

$aliceRecord = $storage->loadEscrow('alice');
$aliceOuter  = KeeperSeal::outerOf($aliceRecord->wrapAdmin);
$keeper->announce((string) $escrowId, $aliceOuter, 'alice', 'LIT-MAX', PHP_INT_MAX, 'h');
$keeper->release((string) $escrowId, $aliceOuter, 'LIT-MAX', PHP_INT_MAX, 'h');
try {
    $ceremony->readEscrow('alice', 'LIT-10', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']) === $fields
        ? ok('a release at the highest head, by whoever reaches the keeper with what the database holds, does not close it to the next ceremony')
        : ko('the ceremony after a release at the highest head did not read');
} catch (Throwable $e) {
    ko('a release at the highest head closed the keeper to every ceremony after it', $e->getMessage());
}
$freshLog = sys_get_temp_dir() . '/sdg-keeper-fresh-' . bin2hex(random_bytes(4)) . '.log';
try {
    benchCeremony($storage, logPath: $freshLog, keeper: $keeper)->readEscrow('alice', 'LIT-11', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']) === $fields
        ? ok('a new audit log, whose heads start again from 0, reads through the keeper')
        : ko('the ceremony on a new audit log did not read');
} catch (Throwable $e) {
    ko('a new audit log is refused by the keeper', $e->getMessage());
}
@unlink($freshLog);

// -----------------------------------------------------------------------------

section('A delay: the read is announced, the holder sees it and may object');

$slow   = new BenchKeeper(delay: 3600);
$slowDb = new SqliteAdapter('sqlite::memory:');
$slowDg = new SelfDataGuard($slowDb, $blindKey, keeper: $slow);
$slowLog = sys_get_temp_dir() . '/sdg-keeper-slow-' . bin2hex(random_bytes(4)) . '.log';
$slowCeremony = benchCeremony($slowDb, logPath: $slowLog, keeper: $slow);
$carol = $slowDg->register('carol', 'motdepasse-carol-01');
$slowDg->setEscrowFields($carol, $admin['publicKey'], ['contact' => 'carol-secours@example.org']);
try {
    $slowCeremony->readEscrow('carol', 'LIT-5', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
    ko('a read under a delay was unsealed at once');
} catch (EscrowAnnouncedException $e) {
    $last = logEntries($slowLog);
    $e->due > time() && (end($last)['event']['action'] ?? null) === 'escrow-unlock-announced'
        ? ok('a read under a delay is announced: nothing unsealed, the log says escrow-unlock-announced')
        : ko('announced, but not logged as such');
}
$reads = $slowDg->escrowReads($carol);
$reads['established'] && count($reads['pending']) === 1 && $reads['pending'][0]['case'] === 'LIT-5' && !$reads['pending'][0]['objected']
    ? ok('the holder sees the announced read, its case and its date, before it happens')
    : ko('the announced read is not shown to the holder', json_encode($reads));
$slowDg->objectToEscrowRead($carol, 'LIT-5');
$heldKey = array_key_first($slow->pending);
$slow->pending[$heldKey]['due'] = time() - 1;
try {
    $slowCeremony->readEscrow('carol', 'LIT-5', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
    ko('a read the holder objected to went through once due');
} catch (EscrowKeeperException $e) {
    ok('a read the holder objected to does not go through, even once due');
}
$carolEscrowId = (string) KeeperSeal::escrowIdOf($slowDb->loadEscrow('carol')->wrapAdmin);
try {
    $slowCeremony->readEscrow('carol', 'LIT-8', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
}
$forged = KeeperSeal::token(KeeperSeal::objectionMessage($carolEscrowId, 'LIT-8'), sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
try {
    $slow->object($carolEscrowId, 'LIT-8', $forged);
    ko('the keeper took an objection signed by a key the escrow does not carry');
} catch (RuntimeException $e) {
    str_contains($e->getMessage(), 'not signed by the key this escrow is named after')
        && $slow->pending[bin2hex($carolEscrowId) . '|LIT-8']['objected'] === false
        ? ok('an objection under another key is refused: the administrator cannot object in the holder\'s place')
        : ko('refused for another reason', $e->getMessage());
}
unset($slow->pending[bin2hex($carolEscrowId) . '|LIT-8']);
try {
    $slowCeremony->readEscrow('carol', 'LIT-6', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
}
$slow->pending[(string) array_key_last($slow->pending)]['due'] = time() - 1;
$slowCeremony->readEscrow('carol', 'LIT-6', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']) === ['contact' => 'carol-secours@example.org']
    ? ok('without an objection, the same ceremony run again once due reads')
    : ko('a due read without objection did not go through');
$boxBefore = KeeperSeal::outerOf($slowDb->loadEscrow('carol')->wrapAdmin);
try {
    $slowCeremony->readEscrow('carol', 'LIT-9', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
}
$slowDg->setEscrowFields($carol, $admin['publicKey'], ['contact' => 'carol-secours@example.org']);
$carolEscrowId === (string) KeeperSeal::escrowIdOf($slowDb->loadEscrow('carol')->wrapAdmin)
    ? ok('a deposit keeps the escrowId: it is the fingerprint of an objection key derived from the master key')
    : ko('a deposit changed the escrowId');
try {
    $slowDg->objectToEscrowRead($carol, 'LIT-9');
    ok('after a deposit, the holder still objects to a read announced before it');
} catch (Throwable $e) {
    ko('the holder cannot object to a read announced before a deposit', $e->getMessage());
}
$slow->pending[bin2hex($carolEscrowId) . '|LIT-9']['due'] = time() - 1;
try {
    $slow->release($carolEscrowId, $boxBefore, 'LIT-9', 900, 'h');
    ko('the box of before the deposit was released, over the holder\'s objection');
} catch (RuntimeException) {
    ok('the box of before the deposit, kept from a copy, is not released over the holder\'s objection');
}
try {
    $slowCeremony->readEscrow('carol', 'LIT-10', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
}
$slowDg->setEscrowFields($carol, $admin['publicKey'], ['indice' => 'deposé pendant l\'annonce']);
$slow->pending[bin2hex($carolEscrowId) . '|LIT-10']['due'] = time() - 1;
try {
    ($slowCeremony->readEscrow('carol', 'LIT-10', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey'])['indice'] ?? null) === 'deposé pendant l\'annonce'
        ? ok('the announcement holds the case: a deposit during it is read at its due, without a new delay')
        : ko('the read at the due did not open the escrow as it stands');
} catch (Throwable $e) {
    ko('a deposit during an announcement put the read off', get_class($e) . ': ' . $e->getMessage());
}

// -----------------------------------------------------------------------------

section('An archive keeps its keeper, and its reads wait the delay like any other');

$l3 = $slowDg->reEnroll($slowDg->generationOf('carol'), 'motdepasse-carol-02');
try {
    $slowCeremony->readArchiveEscrow('carol', $l3['archiveId'], 'LIT-7', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
    ko('the read of an archive skipped the delay');
} catch (EscrowAnnouncedException) {
    ok('the read of an archive is announced like any other: a live read cannot pass for one to skip the delay');
}
$slow->pending[bin2hex($carolEscrowId) . '|LIT-7']['due'] = time() - 1;
($slowCeremony->readArchiveEscrow('carol', $l3['archiveId'], 'LIT-7', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey'])['contact'] ?? null)
    === 'carol-secours@example.org'
    ? ok('once due, the escrow of the archive is read through the keeper')
    : ko('the archive escrow did not read through the keeper');
$archive = $slowDg->openArchive($l3['unlocked'], $l3['archiveId'], Lock::Password, 'motdepasse-carol-01');
$archiveReads = $slowDg->archiveEscrowReads($archive);
$archiveReads['established'] && in_array('LIT-7', array_column($archiveReads['reads'], 'case'), true) && !$archiveReads['reseal']
    ? ok('the holder who reopens the archive sees its reads, LIT-7 among them — and no reseal is asked of an archive')
    : ko('archiveEscrowReads() did not show the archive read', json_encode($archiveReads));
try {
    $slowCeremony->readArchiveEscrow('carol', $l3['archiveId'], 'LIT-12', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
}
try {
    $slowCeremony->readArchiveEscrow('carol', $l3['archiveId'], 'LIT-TYPO-A', $admin['sealedSecret'], 'une passphrase mal tapée', $admin['publicKey']);
    ko('a wrong passphrase read an archive escrow');
} catch (Pierroons\SelfDataGuard\Vault\WrongSecretException) {
    !isset($slow->pending[bin2hex($carolEscrowId) . '|LIT-TYPO-A'])
        ? ok('the read of an archive under a wrong passphrase stops before the keeper: nothing announced')
        : ko('a wrong passphrase announced the read of an archive before it was refused');
} catch (EscrowAnnouncedException) {
    ko('a wrong passphrase announced the read of an archive before it was refused');
}
$slowDg->objectToArchiveEscrowRead($archive, 'LIT-12');
$slow->pending[bin2hex($carolEscrowId) . '|LIT-12']['due'] = time() - 1;
try {
    $slowCeremony->readArchiveEscrow('carol', $l3['archiveId'], 'LIT-12', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
    ko('a read of an archive its holder objected to went through once due');
} catch (EscrowKeeperException) {
    ok('the holder objects to a read of an archive under the archive\'s own key, and it does not go through');
}

// -----------------------------------------------------------------------------

section('A database that recorded a keeper refuses a façade without it, or with another');

foreach (['no keeper' => null, 'NoKeeper' => new NoKeeper(), 'another keeper' => new BenchKeeper()] as $label => $other) {
    try {
        new SelfDataGuard($storage, $blindKey, keeper: $other);
        ko("a façade with {$label} opened a database that recorded a keeper");
    } catch (InvalidArgumentException $e) {
        str_contains($e->getMessage(), KeeperSeal::fingerprint($keeper->publicKeys()))
            ? ok("{$label}: refused, naming the keeper the database recorded")
            : ko("{$label}: refused without naming the recorded keeper", $e->getMessage());
    }
}

// -----------------------------------------------------------------------------

section('An escrow sealed before the keeper is sealed to it from the holder\'s session');

$plainDb = new SqliteAdapter('sqlite::memory:');
$before0 = new SelfDataGuard($plainDb, $blindKey);
$dave = $before0->register('dave', 'motdepasse-dave-01');
$before0->setEscrowFields($dave, $admin['publicKey'], ['contact' => 'dave-secours@example.org']);
$withKeeper = new SelfDataGuard($plainDb, $blindKey, keeper: $keeper);
$reads = $withKeeper->escrowReads($dave);
!$reads['established'] && $reads['reason'] === 'not-keeper-sealed' && $reads['reseal']
    ? ok('before: "not established — not-keeper-sealed", and reseal asked')
    : ko('an escrow sealed to the administrator alone was not reported as such', json_encode($reads));
$emma = $before0->register('emma', 'motdepasse-emma-01');
$before0->setEscrowFields($emma, $admin['publicKey'], ['contact' => 'emma-secours@example.org']);
$passLog = sys_get_temp_dir() . '/sdg-keeper-pass-' . bin2hex(random_bytes(4)) . '.log';
try {
    benchCeremony($plainDb, logPath: $passLog, keeper: new BenchKeeper())->sealToKeeper('emma');
    ko('a ceremony given another keeper than the database recorded sealed to it');
} catch (InvalidArgumentException $e) {
    $last = logEntries($passLog);
    !EscrowVault::isKeeperSealed($plainDb->loadEscrow('emma')) && (end($last)['event']['action'] ?? null) === 'seal-to-keeper-failed'
        ? ok('the pass with another keeper than the database recorded: refused before any write, and logged')
        : ko('the pass was refused, but sealed something or logged nothing');
}
benchCeremony($plainDb, logPath: $passLog, keeper: $keeper)->sealToKeeper('dave');
$reads = $withKeeper->escrowReads($dave);
!$reads['established'] && $reads['reason'] === 'not-pinned' && $reads['reseal'] && !$reads['pinned']
    ? ok('wrapped by the operator\'s pass: "not established — not-pinned", reseal asked — the escrowId in clear is not trusted')
    : ko('an escrow the pass wrapped reads as established', json_encode($reads));
@unlink($passLog);
$freshDb = new SqliteAdapter('sqlite::memory:');
$noKeeperYet = new SelfDataGuard($freshDb, $blindKey);
$finn = $noKeeperYet->register('finn', 'motdepasse-finn-01');
$noKeeperYet->setEscrowFields($finn, $admin['publicKey'], ['contact' => 'finn-secours@example.org']);
benchCeremony($freshDb, keeper: $keeper)->sealToKeeper('finn');
try {
    new SelfDataGuard($freshDb, $blindKey);
    ko('the pass did not record its keeper: a façade without it opened the database, and its next deposit would undo the pass');
} catch (InvalidArgumentException) {
    ok('the pass records its keeper on a database that recorded none: a façade without it is refused after');
}
$wrapped = $plainDb->loadEscrow('dave');
$foreignId = KeeperSeal::newEscrowId();
$plainDb->replaceEscrow($before0->generationOf('dave'), new EscrowRecord(
    'dave', $wrapped->wrapUser, 'k1:' . bin2hex($foreignId) . ':' . KeeperSeal::outerOf($wrapped->wrapAdmin),
    $wrapped->createdAt, new DateTimeImmutable(), $wrapped->revision
));
$withKeeper->sealEscrowToKeeper($dave, $admin['publicKey'])
    && EscrowVault::isKeeperSealed($plainDb->loadEscrow('dave'))
    && !$withKeeper->sealEscrowToKeeper($dave, $admin['publicKey'])
    ? ok('sealEscrowToKeeper() seals it once, then has nothing to do')
    : ko('sealEscrowToKeeper() did not seal the escrow to the keeper, or sealed it twice');
$pinned = $dave->openEscrow($plainDb->loadEscrow('dave'));
$pinnedId = (string) $pinned->escrowId;
$pinned->lock();
!hash_equals($pinnedId, $foreignId) && hash_equals($pinnedId, (string) KeeperSeal::escrowIdOf($plainDb->loadEscrow('dave')->wrapAdmin))
    ? ok('the escrowId of the pinned escrow is computed from its objection key: the prefix rewritten before the pinning is not taken')
    : ko('the pinning took the escrowId from the prefix in clear');
$withKeeper->getEscrowFieldsAsUser($dave) === ['contact' => 'dave-secours@example.org']
    ? ok('its fields come through')
    : ko('sealing to the keeper lost the escrow fields');

// -----------------------------------------------------------------------------

section('rebindEscrowAdmin() under a keeper seals to the keeper, never to the administrator alone');

$gina = $dg->register('gina', 'motdepasse-gina-01');
$dg->setEscrowFields($gina, $admin['publicKey'], ['contact' => 'gina-secours@example.org']);
$ginaRecord = $storage->loadEscrow('gina');
$storage->replaceEscrow($dg->generationOf('gina'), new EscrowRecord(
    'gina', $ginaRecord->wrapUser, random_bytes(80), $ginaRecord->createdAt, new DateTimeImmutable(), $ginaRecord->revision
));
$dg->rebindEscrowAdmin($gina, $admin['publicKey']);
$rebound = $storage->loadEscrow('gina');
EscrowVault::isKeeperSealed($rebound) && $dg->getEscrowFieldsAsUser($gina) === ['contact' => 'gina-secours@example.org']
    ? ok('a wrap_admin written over the keeper\'s box is re-sealed to the keeper, not to the administrator alone')
    : ko('rebindEscrowAdmin() sealed the escrow to the administrator alone under a keeper');

// -----------------------------------------------------------------------------

section('A keeper that does not answer leaves the vaults open, and takes no deposit');

$remote = new class extends BenchKeeper {
    public bool $keysDown = false;
    public function publicKeys(): array
    {
        if ($this->keysDown) {
            throw new RuntimeException('The keeper service does not answer');
        }
        return parent::publicKeys();
    }
};
$remoteDb = new SqliteAdapter('sqlite::memory:');
$hana = (new SelfDataGuard($remoteDb, $blindKey, keeper: $remote))->register('hana', 'motdepasse-hana-01');
(new SelfDataGuard($remoteDb, $blindKey, keeper: $remote))->setEscrowFields($hana, $admin['publicKey'], ['contact' => 'hana-secours@example.org']);
$remote->keysDown = true;
try {
    $downDg = new SelfDataGuard($remoteDb, $blindKey, keeper: $remote);
    $hanaAgain = $downDg->loginWithPassword('hana', 'motdepasse-hana-01');
    ok('the façade opens, and the holder logs in, while the keeper does not answer');
    $reads = $downDg->escrowReads($hanaAgain);
    !$reads['established'] && $reads['reason'] === 'keeper-unreachable'
        ? ok('escrowReads(): not established — keeper-unreachable')
        : ko('a silent keeper read as no read', json_encode($reads));
    $before = $remoteDb->loadEscrow('hana');
    try {
        $downDg->setEscrowFields($hanaAgain, $admin['publicKey'], ['contact' => 'autre@example.org']);
        ko('a deposit went through while the keeper gave no public keys');
    } catch (EscrowKeeperException $e) {
        $e->reason === 'keeper-unreachable' && $remoteDb->loadEscrow('hana')->revision === $before->revision
            ? ok('a deposit is refused, keeper-unreachable — never sealed to the administrator alone')
            : ko('refused, but not as keeper-unreachable, or the escrow moved', $e->reason);
    }
} catch (Throwable $e) {
    ko('a façade did not open while the keeper was down', $e->getMessage());
}
$remote->keysDown = false;
$otherRemote = new class extends BenchKeeper {
    public bool $keysDown = true;
    public function publicKeys(): array
    {
        if ($this->keysDown) {
            throw new RuntimeException('The keeper service does not answer');
        }
        return parent::publicKeys();
    }
};
$lateDg = new SelfDataGuard($remoteDb, $blindKey, keeper: $otherRemote);
$hanaLate = $lateDg->loginWithPassword('hana', 'motdepasse-hana-01');
$otherRemote->keysDown = false;
try {
    $reads = $lateDg->escrowReads($hanaLate);
    !$reads['established'] && $reads['reason'] === 'keeper-mismatch'
        ? ok('a keeper whose keys came after the façade, not the one the database recorded: "keeper-mismatch", a reason — not an exception')
        : ko('another keeper than the database recorded answered for the holder', json_encode($reads));
} catch (Throwable $e) {
    ko('escrowReads() threw on a keeper the database did not record', get_class($e));
}

// -----------------------------------------------------------------------------

section('What the holder sees under a keeper that changed, and an objection to a keeper that does not answer');

$keeperA = new BenchKeeper(delay: 3600);
$keeperB = new BenchKeeper(delay: 3600);
$changeDb = new SqliteAdapter('sqlite::memory:');
$dgA  = new SelfDataGuard($changeDb, $blindKey, keeper: $keeperA);
$ivan = $dgA->register('ivan', 'motdepasse-ivan-01');
$dgA->setEscrowFields($ivan, $admin['publicKey'], ['contact' => 'ivan-secours@example.org']);
$changeLog = sys_get_temp_dir() . '/sdg-keeper-change-' . bin2hex(random_bytes(4)) . '.log';
try {
    benchCeremony($changeDb, logPath: $changeLog, keeper: $keeperA)->readEscrow('ivan', 'LIT-I1', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
}
$keeperA->down = true;
try {
    $dgA->objectToEscrowRead($ivan, 'LIT-I1');
    ko('an objection to a keeper that does not answer went through');
} catch (EscrowKeeperException $e) {
    $e->reason === 'keeper-unreachable'
        ? ok('an objection to a keeper that does not answer: EscrowKeeperException, keeper-unreachable — not a bare error')
        : ko('an objection to a silent keeper refused, but not as keeper-unreachable', $e->reason);
} catch (Throwable $e) {
    ko('an objection to a silent keeper raised a bare error', get_class($e));
}
$keeperA->down = false;
$deafKeeper = new class (3600) extends BenchKeeper {
    public function object(string $escrowId, string $caseRef, string $token): void
    {
        throw new RuntimeException('The keeper lost the objection');
    }
};
$deafDb = new SqliteAdapter('sqlite::memory:');
$deafDg = new SelfDataGuard($deafDb, $blindKey, keeper: $deafKeeper);
$ines   = $deafDg->register('ines', 'motdepasse-ines-01');
$deafDg->setEscrowFields($ines, $admin['publicKey'], ['contact' => 'ines@example.org']);
try {
    benchCeremony($deafDb, keeper: $deafKeeper)->readEscrow('ines', 'LIT-N1', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
}
foreach (['LIT-N1' => 'a keeper that holds the read announced and does not record the objection', 'LIT-N2' => 'an objection to a case no keeper holds announced'] as $case => $what) {
    try {
        $deafDg->objectToEscrowRead($ines, $case);
        ko("{$what}: the objection went through");
    } catch (EscrowKeeperException $e) {
        $e->reason === 'keeper-failed'
            ? ok("{$what}: EscrowKeeperException, keeper-failed")
            : ko("{$what}: refused, but not as keeper-failed", $e->reason);
    } catch (Throwable $e) {
        ko("{$what}: a bare error", get_class($e));
    }
}
$changeDb->replaceSetting(SelfDataGuard::SETTING_KEEPER, KeeperSeal::fingerprint($keeperA->publicKeys()), KeeperSeal::fingerprint($keeperB->publicKeys()));
$reads = (new SelfDataGuard($changeDb, $blindKey, keeper: $keeperB))->escrowReads($ivan);
!$reads['established'] && $reads['reason'] === 'keeper-changed' && $reads['reseal']
    && $reads['keeper'] === KeeperSeal::fingerprint($keeperA->publicKeys())
    ? ok('the deposit named another keeper than the façade\'s: "keeper-changed", the pinned keeper named, reseal asked')
    : ko('a keeper other than the one the deposit pinned answered for it', json_encode($reads));
$reads = (new SelfDataGuard($changeDb, $blindKey, keeper: $keeperB, formerKeepers: [$keeperA]))->escrowReads($ivan);
$reads['established'] && $reads['reseal'] && in_array('LIT-I1', array_column($reads['pending'], 'case'), true)
    ? ok('a former keeper given to the façade, the database not recording it — changed by hand —, is asked all the same: what it holds stays in view')
    : ko('a former keeper the façade was given is ignored when the database does not record it', json_encode($reads));
@unlink($changeLog);

// -----------------------------------------------------------------------------

section('A keeper the deployment replaced: the holder sees and objects to what it holds, retires the escrow there, and the ceremony reads its archives');

$oldKeeper = new BenchKeeper(delay: 3600);
$newKeeper = new BenchKeeper(delay: 3600);
$swapDb = new SqliteAdapter('sqlite::memory:');
$dgOld  = new SelfDataGuard($swapDb, $blindKey, keeper: $oldKeeper);
$kim = $dgOld->register('kim', 'motdepasse-kim-01');
$dgOld->setEscrowFields($kim, $admin['publicKey'], ['contact' => 'kim@example.org']);
$max = $dgOld->register('max', 'motdepasse-max-01');
$dgOld->setEscrowFields($max, $admin['publicKey'], ['contact' => 'max@example.org']);
$lea = $dgOld->register('lea', 'motdepasse-lea-01');
$dgOld->setEscrowFields($lea, $admin['publicKey'], ['contact' => 'lea@example.org']);
$leaL3 = $dgOld->reEnroll($dgOld->generationOf('lea'), 'motdepasse-lea-02');
try {
    SelfDataGuard::changeKeeper($swapDb, $newKeeper, $oldKeeper);
    ko('changeKeeper() took a keeper the database did not record as the one it replaces');
} catch (InvalidArgumentException) {
    $swapDb->loadSetting(SelfDataGuard::SETTING_FORMER_KEEPERS) === null
        ? ok('changeKeeper() refuses to replace a keeper the database did not record, and records nothing')
        : ko('changeKeeper() took a keeper the database did not record: it wrote the record of former keepers');
}
SelfDataGuard::changeKeeper($swapDb, $oldKeeper, $newKeeper);
$swapLog = sys_get_temp_dir() . '/sdg-keeper-swap-' . bin2hex(random_bytes(4)) . '.log';
try {
    benchCeremony($swapDb, logPath: $swapLog, keeper: $newKeeper)->readArchiveEscrow('lea', $leaL3['archiveId'], 'LIT-A1', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
    ko('an archive sealed to the replaced keeper read through the new one');
} catch (EscrowKeeperException) {
    ok('without the replaced keeper, an archive sealed to it does not read');
}
$bothCeremony = benchCeremony($swapDb, logPath: $swapLog, keeper: $newKeeper, formerKeepers: [$oldKeeper]);
$leaEscrowId = (string) KeeperSeal::escrowIdOf($swapDb->loadArchive('lea', $leaL3['archiveId'])->escrow->wrapAdmin);
try {
    $bothCeremony->readArchiveEscrow('lea', $leaL3['archiveId'], 'LIT-A1', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
    $oldKeeper->pending[bin2hex($leaEscrowId) . '|LIT-A1']['due'] = time() - 1;
} catch (Throwable) {
}
try {
    ($bothCeremony->readArchiveEscrow('lea', $leaL3['archiveId'], 'LIT-A1', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey'])['contact'] ?? null) === 'lea@example.org'
        ? ok('given the replaced keeper the database recorded, the ceremony reads an archive sealed to it — the façades stay up')
        : ko('the archive did not read through the replaced keeper');
} catch (Throwable $e) {
    ko('the ceremony given the replaced keeper did not read its archive', $e->getMessage());
}
try {
    benchCeremony($swapDb, logPath: $swapLog, keeper: $newKeeper, formerKeepers: [new BenchKeeper()])
        ->readArchiveEscrow('lea', $leaL3['archiveId'], 'LIT-A2', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
    ko('a former keeper the database never recorded was taken');
} catch (EscrowKeeperException $e) {
    $e->reason === 'keeper-mismatch'
        ? ok('a former keeper the database never recorded: refused, keeper-mismatch')
        : ko('a former keeper the database never recorded was refused, but not as keeper-mismatch', $e->reason);
}
$kimEscrowId = (string) KeeperSeal::escrowIdOf($swapDb->loadEscrow('kim')->wrapAdmin);
$kimOldBox   = KeeperSeal::outerOf($swapDb->loadEscrow('kim')->wrapAdmin);
try {
    $bothCeremony->readEscrow('kim', 'LIT-K', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
}
$dgNew = new SelfDataGuard($swapDb, $blindKey, keeper: $newKeeper, formerKeepers: [$oldKeeper]);
$reads = $dgNew->escrowReads($kim);
$reads['established'] && $reads['reseal'] && $reads['keeper'] === KeeperSeal::fingerprint($oldKeeper->publicKeys())
    && in_array('LIT-K', array_column($reads['pending'], 'case'), true)
    ? ok('before the re-sealing, the holder sees what the replaced keeper holds announced, named by its fingerprint')
    : ko('the read announced at the replaced keeper is not shown to the holder', json_encode($reads));
try {
    $dgNew->objectToEscrowRead($kim, 'LIT-K');
    $oldKeeper->pending[bin2hex($kimEscrowId) . '|LIT-K']['objected']
        ? ok('the holder objects at the replaced keeper, which holds the announcement')
        : ko('the objection did not reach the replaced keeper');
} catch (Throwable $e) {
    ko('the holder cannot object at the replaced keeper', $e->getMessage());
}
$dgNew->sealEscrowToKeeper($kim, $admin['publicKey']);
$kimNow = $swapDb->loadEscrow('kim');
isset($oldKeeper->retired[bin2hex($kimEscrowId)]) && hash_equals((string) KeeperSeal::escrowIdOf($kimNow->wrapAdmin), $kimEscrowId)
    ? ok('the re-sealing retires the escrow at the replaced keeper first, and keeps its escrowId')
    : ko('the re-sealing did not retire the escrow at the replaced keeper');
try {
    $oldKeeper->announce($kimEscrowId, $kimOldBox, 'kim', 'LIT-K2', 0, 'h');
    ko('the replaced keeper still announces a read of an escrow retired there');
} catch (RuntimeException) {
    ok('the replaced keeper refuses any read of an escrow retired there, from the box of before');
}
$oldKeeper->down = true;
try {
    $dgNew->sealEscrowToKeeper($max, $admin['publicKey']);
    ko('an escrow was re-sealed while the replaced keeper did not take its retirement');
} catch (EscrowKeeperException $e) {
    $e->reason === 'keeper-failed' && EscrowVault::isKeeperSealed($swapDb->loadEscrow('max'))
        && $dgNew->escrowReads($max)['keeper'] !== KeeperSeal::fingerprint($newKeeper->publicKeys())
        ? ok('a replaced keeper that does not take the retirement: nothing is re-sealed, keeper-failed')
        : ko('refused, but not as expected', $e->reason);
}
$oldKeeper->down = false;
@unlink($swapLog);

// -----------------------------------------------------------------------------

section('The retirement names its keeper, every former keeper takes it at each deposit, and the holder\'s view follows the escrowId the master key computes');

$printOf = static fn (BenchKeeper $k): string => KeeperSeal::fingerprint($k->publicKeys());
$rowOf   = static fn (PDO $pdo, string $user): string|false => $pdo->query(
    'SELECT wrap_user FROM selfdataguard_escrow WHERE user_id = ' . $pdo->quote($user)
)->fetchColumn();
$kA = new BenchKeeper(delay: 3600);
$kB = new BenchKeeper(delay: 3600);
$retDb = new SqliteAdapter('sqlite::memory:');
$dgRA  = new SelfDataGuard($retDb, $blindKey, keeper: $kA);
$nora  = $dgRA->register('nora', 'motdepasse-nora-01');
$dgRA->setEscrowFields($nora, $admin['publicKey'], ['contact' => 'nora@example.org']);
$noraHex = bin2hex($nora->escrowId());
try {
    SelfDataGuard::changeKeeper($retDb, $kA, $kA);
    ko('changeKeeper() replaced a keeper by itself');
} catch (InvalidArgumentException) {
    $retDb->loadSetting(SelfDataGuard::SETTING_FORMER_KEEPERS) === null
        ? ok('changeKeeper(k, k): refused, nothing recorded — the keeper would be retired from its own escrows')
        : ko('changeKeeper(k, k) was refused, but recorded the keeper as former');
}
SelfDataGuard::changeKeeper($retDb, $kA, $kB);
try {
    SelfDataGuard::changeKeeper($retDb, $kB, $kA);
    ko('changeKeeper() took back a keeper the database recorded as former');
} catch (InvalidArgumentException) {
    $retDb->loadSetting(SelfDataGuard::SETTING_KEEPER) === $printOf($kB)
        ? ok('A → B → A: refused — the escrows retired from A would no longer read anywhere')
        : ko('A → B → A was refused, but the database changed its keeper');
}
$dgRB = new SelfDataGuard($retDb, $blindKey, keeper: $kB);
$noraBefore = $retDb->loadEscrow('nora')->wrapAdmin;
try {
    $dgRB->setEscrowFields($nora, $admin['publicKey'], ['contact' => 'nora-2@example.org']);
    ko('a deposit went through without the former keeper the database recorded');
} catch (EscrowKeeperException $e) {
    $e->reason === 'keeper-mismatch' && $retDb->loadEscrow('nora')->wrapAdmin === $noraBefore && !isset($kA->retired[$noraHex])
        ? ok('a former keeper the database recorded and the façade was not given: the deposit is refused, keeper-mismatch, nothing written')
        : ko('a deposit without the recorded former keeper was refused, but not as expected', $e->reason);
}
$k1 = new BenchKeeper();
$k2 = new BenchKeeper();
$k3 = new BenchKeeper();
$twoDb = new SqliteAdapter('sqlite::memory:');
$dg1   = new SelfDataGuard($twoDb, $blindKey, keeper: $k1);
$rita  = $dg1->register('rita', 'motdepasse-rita-01');
$dg1->setEscrowFields($rita, $admin['publicKey'], ['contact' => 'rita@example.org']);
SelfDataGuard::changeKeeper($twoDb, $k1, $k2);
SelfDataGuard::changeKeeper($twoDb, $k2, $k3);
try {
    (new SelfDataGuard($twoDb, $blindKey, keeper: $k3, formerKeepers: [$k1]))->setEscrowFields($rita, $admin['publicKey'], ['contact' => 'rita-2@example.org']);
    ko('a deposit went through with one of two former keepers missing');
} catch (EscrowKeeperException $e) {
    $e->reason === 'keeper-mismatch' && !isset($k1->retired[bin2hex($rita->escrowId())])
        ? ok('two former keepers recorded, one not given: the deposit is refused before any retirement — the one given still serves')
        : ko('one of two former keepers missing: the deposit was refused after retiring the escrow from the other', $e->reason);
}
$dgRAB = new SelfDataGuard($retDb, $blindKey, keeper: $kB, formerKeepers: [$kA]);
$retDb->freezeVault($dgRAB->generationOf('nora'), 'bench', new DateTimeImmutable());
try {
    $dgRAB->setEscrowFields($nora, $admin['publicKey'], ['contact' => 'nora-2@example.org']);
    ko('a frozen vault took a deposit');
} catch (VaultFrozenException) {
    !isset($kA->retired[$noraHex])
        ? ok('a frozen vault: the deposit is refused before any retirement — the escrow still reads at the former keeper')
        : ko('a frozen vault: the deposit was refused after the escrow was retired from the former keeper');
}
$retDb->unfreezeVault($dgRAB->generationOf('nora'));
$dgRAB->setEscrowFields($nora, $admin['publicKey'], ['contact' => 'nora-2@example.org']);
isset($kA->retired[$noraHex])
    ? ok('the deposit retires the escrow from the former keeper the database recorded')
    : ko('the deposit did not retire the escrow from the former keeper');
try {
    $kB->retire($nora->escrowId(), $nora->signEscrowRetirement($printOf($kA)));
    ko('a retirement signed for the former keeper, replayed at the new one, retired the escrow there');
} catch (RuntimeException) {
    ok('the retirement names the keeper it is for: the former keeper cannot replay it at the new one');
}
try {
    $dgRAB->setEscrowFields($nora, $admin['publicKey'], ['indice' => 'nora-3']);
    $dgRAB->escrowReads($nora)['established']
        ? ok('a deposit after the retirement: the former keeper takes it again, the deposit goes through, the view is established')
        : ko('the view is not established after a second deposit', json_encode($dgRAB->escrowReads($nora)));
} catch (Throwable $e) {
    ko('a second deposit was refused by a retirement taken again', $e->getMessage());
}
$noraFormer = (string) $retDb->loadSetting(SelfDataGuard::SETTING_FORMER_KEEPERS);
$retDb->replaceSetting(SelfDataGuard::SETTING_FORMER_KEEPERS, $noraFormer, json_encode([$printOf($kA), $printOf($kB)]));
try {
    (new SelfDataGuard($retDb, $blindKey, keeper: $kB, formerKeepers: [$kA, $kB]))->setEscrowFields($nora, $admin['publicKey'], ['indice' => 'nora-4']);
    ko('a deposit went through with the façade\'s own keeper listed as former');
} catch (EscrowKeeperException $e) {
    $e->reason === 'keeper-mismatch' && !isset($kB->retired[$noraHex])
        ? ok('a record of former keepers that lists the façade\'s own: the deposit is refused, keeper-mismatch, nothing retired from it')
        : ko('a record listing the façade\'s own keeper was refused, but not as expected', $e->reason);
}
$retDb->replaceSetting(SelfDataGuard::SETTING_FORMER_KEEPERS, json_encode([$printOf($kA), $printOf($kB)]), $noraFormer);
try {
    (new SelfDataGuard($retDb, $blindKey, keeper: $kB, formerKeepers: [$kA, $kB]))->setEscrowFields($nora, $admin['publicKey'], ['indice' => 'nora-5']);
    ko('a deposit went through with the façade given its own keeper as former');
} catch (EscrowKeeperException $e) {
    $e->reason === 'keeper-mismatch' && $retDb->loadSetting(SelfDataGuard::SETTING_FORMER_KEEPERS) === $noraFormer
        ? ok('the façade given its own keeper as former: refused before anything is written — the record of former keepers untouched')
        : ko('the façade given its own keeper as former wrote it into the record of former keepers before refusing', (string) $retDb->loadSetting(SelfDataGuard::SETTING_FORMER_KEEPERS));
}

$kA2 = new BenchKeeper(delay: 3600);
$kB2 = new BenchKeeper(delay: 3600);
$pdoV = new PDO('sqlite::memory:');
$viewDb = new SqliteAdapter($pdoV);
$dgVA = new SelfDataGuard($viewDb, $blindKey, keeper: $kA2);
$olga = $dgVA->register('olga', 'motdepasse-olga-01');
$dgVA->setEscrowFields($olga, $admin['publicKey'], ['contact' => 'olga@example.org']);
$quinn = $dgVA->register('quinn', 'motdepasse-quinn-01');
$dgVA->setEscrowFields($quinn, $admin['publicKey'], ['contact' => 'quinn@example.org']);
try {
    benchCeremony($viewDb, keeper: $kA2)->readEscrow('quinn', 'LIT-Q1', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
}
SelfDataGuard::changeKeeper($viewDb, $kA2, $kB2);
$dgVAB = new SelfDataGuard($viewDb, $blindKey, keeper: $kB2, formerKeepers: [$kA2]);
$kA2->retire($olga->escrowId(), $olga->signEscrowRetirement($printOf($kA2)));
$reads = $dgVAB->escrowReads($olga);
!$reads['established'] && $reads['reason'] === 'retired' && $reads['reseal']
    ? ok('an escrow retired at the keeper its row pins, the deposit after not written: "retired", reseal asked — not "established"')
    : ko('an escrow retired at the keeper its row pins shows as readable', json_encode($reads));
$pdoV->exec("DELETE FROM selfdataguard_escrow WHERE user_id = 'quinn'");
$reads = $dgVAB->escrowReads($quinn);
!$reads['established'] && $reads['reason'] === 'escrow-replaced' && in_array('LIT-Q1', array_column($reads['pending'], 'case'), true)
    ? ok('the row deleted after a keeper change: the former keeper is asked about the computed escrowId, and its announcement shown')
    : ko('the row deleted after a keeper change hides what the former keeper holds announced', json_encode($reads));
try {
    $dgVAB->objectToEscrowRead($quinn, 'LIT-Q1');
    $kA2->pending[bin2hex($quinn->escrowId()) . '|LIT-Q1']['objected']
        ? ok('the row deleted: the objection, signed from the session, reaches the former keeper that holds the announcement')
        : ko('the objection did not reach the former keeper');
} catch (Throwable $e) {
    ko('the row deleted: the holder cannot object', $e->getMessage());
}

$kP = new BenchKeeper(delay: 3600);
$pdoP = new PDO('sqlite::memory:');
$restDb = new SqliteAdapter($pdoP);
$plainP = new SelfDataGuard($restDb, $blindKey);
$paul = $plainP->register('paul', 'motdepasse-paul-01');
$plainP->setEscrowFields($paul, $admin['publicKey'], ['contact' => 'paul@example.org']);
$paulOld = $rowOf($pdoP, 'paul');
$dgP = new SelfDataGuard($restDb, $blindKey, keeper: $kP);
$dgP->setEscrowFields($paul, $admin['publicKey'], ['contact' => 'paul@example.org']);
try {
    benchCeremony($restDb, keeper: $kP)->readEscrow('paul', 'LIT-P1', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
}
$pdoP->prepare("UPDATE selfdataguard_escrow SET wrap_user = ? WHERE user_id = 'paul'")->execute([$paulOld]);
$reads = $dgP->escrowReads($paul);
!$reads['established'] && $reads['reason'] === 'escrow-replaced' && $reads['reseal']
    && in_array('LIT-P1', array_column($reads['pending'], 'case'), true)
    ? ok('wrap_user restored alone from before the keeper: "escrow-replaced", the announcement the keeper holds is shown')
    : ko('wrap_user restored alone hides what the keeper holds announced', json_encode($reads));
try {
    $dgP->objectToEscrowRead($paul, 'LIT-P1');
    $kP->pending[bin2hex($paul->escrowId()) . '|LIT-P1']['objected']
        ? ok('wrap_user restored alone: the holder objects all the same — the key comes from the master key, not from the row')
        : ko('the objection did not reach the keeper');
} catch (Throwable $e) {
    ko('wrap_user restored alone: the holder cannot object', $e->getMessage());
}
$pdoP->exec("DELETE FROM selfdataguard_escrow WHERE user_id = 'paul'");
$reads = $dgP->escrowReads($paul);
!$reads['established'] && $reads['reason'] === 'escrow-replaced'
    && (array_column($reads['pending'], 'objected', 'case')['LIT-P1'] ?? false) === true
    ? ok('the row deleted: the keeper is asked about the computed escrowId, and the objection shown')
    : ko('the row deleted hides what the keeper holds', json_encode($reads));
!$reads['reseal']
    ? ok('the row deleted: no re-sealing asked — there is no row to re-seal, a deposit writes one')
    : ko('the row deleted asks a re-sealing that sealEscrowToKeeper() cannot do');

$kU1 = new BenchKeeper(delay: 3600);
$kU2 = new BenchKeeper(delay: 3600);
$pdoU = new PDO('sqlite::memory:');
$backDb = new SqliteAdapter($pdoU);
$ursula = (new SelfDataGuard($backDb, $blindKey, keeper: $kU1))->register('ursula', 'motdepasse-ursula-01');
(new SelfDataGuard($backDb, $blindKey, keeper: $kU1))->setEscrowFields($ursula, $admin['publicKey'], ['contact' => 'ursula@example.org']);
$ursulaBackup = $pdoU->query("SELECT * FROM selfdataguard_escrow WHERE user_id = 'ursula'")->fetch(PDO::FETCH_ASSOC);
SelfDataGuard::changeKeeper($backDb, $kU1, $kU2);
$dgU = new SelfDataGuard($backDb, $blindKey, keeper: $kU2, formerKeepers: [$kU1]);
$dgU->setEscrowFields($ursula, $admin['publicKey'], ['contact' => 'ursula-2@example.org']);
try {
    benchCeremony($backDb, keeper: $kU2, formerKeepers: [$kU1])->readEscrow('ursula', 'LIT-U1', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
}
$pdoU->prepare('UPDATE selfdataguard_escrow SET wrap_user = ?, wrap_admin = ? WHERE user_id = ?')
    ->execute([$ursulaBackup['wrap_user'], $ursulaBackup['wrap_admin'], 'ursula']);
$reads = $dgU->escrowReads($ursula);
!$reads['established'] && $reads['reason'] === 'retired' && $reads['reseal']
    && in_array('LIT-U1', array_column($reads['pending'], 'case'), true)
    ? ok('a row put back from before the keeper change: "retired", and what the current keeper holds announced is shown')
    : ko('a backup row pinning the former keeper hides what the current keeper holds announced', json_encode($reads));
try {
    $dgU->objectToEscrowRead($ursula, 'LIT-U1');
    $kU2->pending[bin2hex($ursula->escrowId()) . '|LIT-U1']['objected']
        ? ok('a row put back from before the keeper change: the holder objects at the current keeper')
        : ko('a backup row pinning the former keeper: the objection did not reach the current keeper');
} catch (Throwable $e) {
    ko('a backup row pinning the former keeper: the holder cannot object at the current keeper', $e->getMessage());
}
try {
    $dgU->setEscrowFields($ursula, $admin['publicKey'], ['contact' => 'ursula-3@example.org']);
    ko('a deposit went through over an envelope put back without its fields');
} catch (EscrowEnvelopeMismatchException) {
    ok('the envelope put back without its fields: the deposit is refused, EscrowEnvelopeMismatchException, nothing written');
} catch (Throwable $e) {
    ko('the envelope put back without its fields: the deposit raised a bare error', get_class($e) . ': ' . $e->getMessage());
}

$formerHeld = (string) $viewDb->loadSetting(SelfDataGuard::SETTING_FORMER_KEEPERS);
$viewDb->replaceSetting(SelfDataGuard::SETTING_FORMER_KEEPERS, $formerHeld, '["unterminated');
try {
    $reads = $dgVAB->escrowReads($quinn);
    $reads['reason'] === 'keeper-mismatch'
        ? ok('an unreadable record of former keepers: escrowReads() says keeper-mismatch — a reason, not an exception')
        : ko('an unreadable record of former keepers was not reported', json_encode($reads));
} catch (Throwable $e) {
    ko('escrowReads() threw on an unreadable record of former keepers', get_class($e));
}
try {
    $dgVAB->setEscrowFields($olga, $admin['publicKey'], ['contact' => 'olga-2@example.org']);
    ko('a deposit went through under an unreadable record of former keepers');
} catch (EscrowKeeperException $e) {
    $e->reason === 'keeper-mismatch'
        ? ok('an unreadable record of former keepers: the deposit is refused, keeper-mismatch — as the view and the ceremony say it')
        : ko('a deposit under an unreadable record of former keepers was refused, but not as keeper-mismatch', $e->reason);
} catch (Throwable $e) {
    ko('a deposit under an unreadable record of former keepers raised a bare error', get_class($e));
}
try {
    benchCeremony($viewDb, keeper: $kB2, formerKeepers: [$kA2])->readEscrow('olga', 'LIT-O1', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
    ko('the ceremony read under an unreadable record of former keepers');
} catch (EscrowKeeperException $e) {
    $e->reason === 'keeper-mismatch'
        ? ok('an unreadable record of former keepers: the ceremony refuses, keeper-mismatch')
        : ko('the ceremony refused an unreadable record of former keepers, but not as keeper-mismatch', $e->reason);
} catch (Throwable $e) {
    ko('the ceremony raised a bare error on an unreadable record of former keepers', get_class($e));
}
try {
    SelfDataGuard::changeKeeper($viewDb, $kB2, new BenchKeeper());
    ko('changeKeeper() went on over an unreadable record of former keepers');
} catch (InvalidArgumentException) {
    ok('changeKeeper() refuses an unreadable record of former keepers');
}
$viewDb->replaceSetting(SelfDataGuard::SETTING_FORMER_KEEPERS, '["unterminated', '[""]');
$reads = $dgVAB->escrowReads($quinn);
$reads['reason'] === 'keeper-mismatch'
    ? ok('a record of former keepers that lists something else than a fingerprint: unreadable, keeper-mismatch')
    : ko('a record of former keepers listing an empty name was read as a keeper', json_encode($reads));
$viewDb->replaceSetting(SelfDataGuard::SETTING_FORMER_KEEPERS, '[""]', $formerHeld);
$kB2->down = true;
try {
    benchCeremony($viewDb, keeper: $kB2, formerKeepers: [$kA2])->readEscrow('olga', 'LIT-O2', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
    ko('the ceremony read with its keeper down');
} catch (EscrowKeeperException $e) {
    str_contains($e->getMessage(), 'the keeper: The keeper does not answer') && str_contains($e->getMessage(), 'former keeper 1:')
        ? ok('no keeper recorded the read: the ceremony names the failure of each keeper it tried, the current one first')
        : ko('the ceremony hides the failure of the current keeper', $e->getMessage());
} catch (Throwable $e) {
    ko('the ceremony with its keeper down raised a bare error', get_class($e) . ': ' . $e->getMessage());
}
$kB2->down = false;

// -----------------------------------------------------------------------------

section('The façade knows its former keepers, a row that does not open hides nothing, and a silent keeper hides only itself');

final class CountingKeeper extends BenchKeeper
{
    public int $keyCalls = 0;

    public function publicKeys(): array
    {
        $this->keyCalls++;
        return parent::publicKeys();
    }

    /** The bench keeper reads its own keys to check a retirement: not a call from the façade. */
    public function retire(string $escrowId, string $token): void
    {
        $calls = $this->keyCalls;
        try {
            parent::retire($escrowId, $token);
        } finally {
            $this->keyCalls = $calls;
        }
    }
}
$hexOf = static fn (UnlockedVault $s): string => bin2hex($s->escrowId());
$casesOf = static fn (array $list): array => array_column($list, 'case');

$kY1a = new BenchKeeper(delay: 3600);
$kY1b = new BenchKeeper(delay: 3600);
$y1Db = new SqliteAdapter('sqlite::memory:');
$yuna = (new SelfDataGuard($y1Db, $blindKey, keeper: $kY1a))->register('yuna', 'motdepasse-yuna-01');
(new SelfDataGuard($y1Db, $blindKey, keeper: $kY1a))->setEscrowFields($yuna, $admin['publicKey'], ['contact' => 'yuna@example.org']);
$yunaOld = $y1Db->loadEscrow('yuna');
SelfDataGuard::changeKeeper($y1Db, $kY1a, $kY1b);
$y1Db->replaceSetting(SelfDataGuard::SETTING_FORMER_KEEPERS, (string) $y1Db->loadSetting(SelfDataGuard::SETTING_FORMER_KEEPERS), '[]');
$kY1a->announce($yuna->escrowId(), KeeperSeal::outerOf($yunaOld->wrapAdmin), 'yuna', 'LIT-Y1', 0, 'h');
$dgY1 = new SelfDataGuard($y1Db, $blindKey, keeper: $kY1b, formerKeepers: [$kY1a]);
$reads = $dgY1->escrowReads($yuna);
in_array('LIT-Y1', $casesOf($reads['pending']), true)
    ? ok('the record of former keepers emptied in the database: the former keeper the façade was given is asked all the same')
    : ko('the record emptied hides what the former keeper the façade was given holds announced', json_encode($reads));
try {
    $dgY1->objectToEscrowRead($yuna, 'LIT-Y1');
    $kY1a->pending[$hexOf($yuna) . '|LIT-Y1']['objected']
        ? ok('the record emptied: the holder objects at the former keeper the façade was given')
        : ko('the record emptied: the objection did not reach the former keeper the façade was given');
} catch (Throwable $e) {
    ko('the record emptied: the holder cannot object at the former keeper the façade was given', $e->getMessage());
}
$dgY1->setEscrowFields($yuna, $admin['publicKey'], ['contact' => 'yuna-2@example.org']);
isset($kY1a->retired[$hexOf($yuna)])
    ? ok('the record emptied: the deposit retires the escrow from the former keeper the façade was given')
    : ko('the record emptied: the deposit retired nothing at the former keeper the façade was given');

$kY2 = new BenchKeeper(delay: 3600);
$pdoY2 = new PDO('sqlite::memory:');
$y2Db = new SqliteAdapter($pdoY2);
$dgY2 = new SelfDataGuard($y2Db, $blindKey, keeper: $kY2);
$zoe = $dgY2->register('zoe', 'motdepasse-zoe-01');
$dgY2->setEscrowFields($zoe, $admin['publicKey'], ['contact' => 'zoe@example.org']);
try {
    benchCeremony($y2Db, keeper: $kY2)->readEscrow('zoe', 'LIT-Z1', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
}
foreach (['not even base64' => 'n0t-base64!!', 'random base64' => base64_encode(random_bytes(150))] as $what => $garbage) {
    $pdoY2->prepare("UPDATE selfdataguard_escrow SET wrap_user = ? WHERE user_id = 'zoe'")->execute([$garbage]);
    try {
        $reads = $dgY2->escrowReads($zoe);
        !$reads['established'] && $reads['reason'] === 'escrow-replaced' && in_array('LIT-Z1', $casesOf($reads['pending']), true)
            ? ok("wrap_user rewritten ({$what}): \"escrow-replaced\", and the announcement the keeper holds is shown")
            : ko("wrap_user rewritten ({$what}) hides what the keeper holds announced", json_encode($reads));
    } catch (Throwable $e) {
        ko("wrap_user rewritten ({$what}): escrowReads() threw", get_class($e));
    }
}
try {
    $dgY2->objectToEscrowRead($zoe, 'LIT-Z1');
    $kY2->pending[$hexOf($zoe) . '|LIT-Z1']['objected']
        ? ok('wrap_user rewritten: the holder objects all the same')
        : ko('wrap_user rewritten: the objection did not reach the keeper');
} catch (Throwable $e) {
    ko('wrap_user rewritten: the holder cannot object', get_class($e) . ': ' . $e->getMessage());
}
foreach (['deposit' => static fn () => $dgY2->setEscrowFields($zoe, $admin['publicKey'], ['contact' => 'zoe-2@example.org']),
          'read'    => static fn () => $dgY2->getEscrowFieldsAsUser($zoe)] as $what => $call) {
    try {
        $call();
        ko("wrap_user rewritten: the {$what} went through");
    } catch (EscrowEnvelopeMismatchException) {
        ok("wrap_user rewritten: the {$what} is refused with EscrowEnvelopeMismatchException, nothing written");
    } catch (Throwable $e) {
        ko("wrap_user rewritten: the {$what} raised a bare error", get_class($e));
    }
}
$pdoY2->prepare("UPDATE selfdataguard_escrow SET wrap_user = ? WHERE user_id = 'zoe'")->execute(['n0t-base64!!']);
try {
    $dgY2->setEscrowFields($zoe, $admin['publicKey'], ['contact' => 'zoe-3@example.org']);
    ko('wrap_user not even base64: the deposit went through');
} catch (EscrowEnvelopeMismatchException) {
    ok('wrap_user not even base64: the deposit is refused with EscrowEnvelopeMismatchException');
} catch (Throwable $e) {
    ko('wrap_user not even base64: the deposit raised a bare error', get_class($e));
}

$kY3a = new BenchKeeper(delay: 3600);
$kY3b = new BenchKeeper(delay: 3600);
$pdoY3 = new PDO('sqlite::memory:');
$y3Db = new SqliteAdapter($pdoY3);
$yann = (new SelfDataGuard($y3Db, $blindKey, keeper: $kY3a))->register('yann', 'motdepasse-yann-01');
(new SelfDataGuard($y3Db, $blindKey, keeper: $kY3a))->setEscrowFields($yann, $admin['publicKey'], ['contact' => 'yann@example.org']);
$yannBackup = $pdoY3->query("SELECT wrap_user, wrap_admin FROM selfdataguard_escrow WHERE user_id = 'yann'")->fetch(PDO::FETCH_ASSOC);
SelfDataGuard::changeKeeper($y3Db, $kY3a, $kY3b);
$dgY3 = new SelfDataGuard($y3Db, $blindKey, keeper: $kY3b, formerKeepers: [$kY3a]);
$dgY3->setEscrowFields($yann, $admin['publicKey'], ['contact' => 'yann-2@example.org']);
try {
    benchCeremony($y3Db, keeper: $kY3b, formerKeepers: [$kY3a])->readEscrow('yann', 'LIT-Y3', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
}
$pdoY3->prepare("UPDATE selfdataguard_escrow SET wrap_user = ?, wrap_admin = ? WHERE user_id = 'yann'")->execute([$yannBackup['wrap_user'], $yannBackup['wrap_admin']]);
$kY3a->down = true;
$reads = $dgY3->escrowReads($yann);
!$reads['established'] && $reads['reason'] === 'keeper-unreachable' && $reads['keeper'] === KeeperSeal::fingerprint($kY3a->publicKeys())
    && in_array('LIT-Y3', $casesOf($reads['pending']), true)
    ? ok('a former keeper that does not answer: the view names it, and still lists what the current keeper holds announced')
    : ko('a silent former keeper hides what the current keeper holds announced', json_encode($reads));
try {
    $dgY3->objectToEscrowRead($yann, 'LIT-Y3');
    ko('an objection with a silent former keeper reported no failure');
} catch (EscrowKeeperException $e) {
    $kY3b->pending[$hexOf($yann) . '|LIT-Y3']['objected'] && str_contains($e->getMessage(), KeeperSeal::fingerprint($kY3a->publicKeys()))
        ? ok('a former keeper that does not answer: the current keeper records the objection, and the call names the silent one')
        : ko('a silent former keeper kept the objection from the current keeper', $e->getMessage());
}
$kY3a->down = false;

$kY4a = new BenchKeeper(delay: 0);
$kY4b = new BenchKeeper(delay: 0);
$y4Db = new SqliteAdapter('sqlite::memory:');
$yves = (new SelfDataGuard($y4Db, $blindKey, keeper: $kY4a))->register('yves', 'motdepasse-yves-01');
(new SelfDataGuard($y4Db, $blindKey, keeper: $kY4a))->setEscrowFields($yves, $admin['publicKey'], ['contact' => 'yves@example.org']);
SelfDataGuard::changeKeeper($y4Db, $kY4a, $kY4b);
benchCeremony($y4Db, keeper: $kY4b, formerKeepers: [$kY4a])->readEscrow('yves', 'LIT-Y4', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
$dgY4 = new SelfDataGuard($y4Db, $blindKey, keeper: $kY4b, formerKeepers: [$kY4a]);
$dgY4->setEscrowFields($yves, $admin['publicKey'], ['contact' => 'yves-2@example.org']);
$reads = $dgY4->escrowReads($yves);
$fromA = array_values(array_filter($reads['reads'], static fn (array $r): bool => $r['case'] === 'LIT-Y4'));
$reads['established'] && count($fromA) === 1
    ? ok('a release by the former keeper stays in view after the deposit that retired the escrow there')
    : ko('a release by the former keeper left the view at the next deposit', json_encode($reads));
($fromA[0]['keeper'] ?? null) === KeeperSeal::fingerprint($kY4a->publicKeys())
    ? ok('each release names, by its fingerprint, the keeper that attests it')
    : ko('a release does not name the keeper that attests it', json_encode($fromA));

$kY6 = new BenchKeeper(delay: 3600);
$y6Db = new SqliteAdapter('sqlite::memory:');
$dgY6 = new SelfDataGuard($y6Db, $blindKey, keeper: $kY6);
$yoko = $dgY6->register('yoko', 'motdepasse-yoko-01');
$dgY6->setEscrowFields($yoko, $admin['publicKey'], ['contact' => 'yoko@example.org']);
$kY6->retire($yoko->escrowId(), $yoko->signEscrowRetirement(KeeperSeal::fingerprint($kY6->publicKeys())));
$reads = $dgY6->escrowReads($yoko);
!$reads['established'] && $reads['reason'] === 'retired' && !$reads['reseal']
    ? ok('the façade\'s own keeper retired the escrow: "retired", and no re-sealing asked — none would bring it back')
    : ko('the façade\'s own keeper retired the escrow, and the view asks a re-sealing that changes nothing', json_encode($reads));

$kY8a = new BenchKeeper();
$kY8b = new BenchKeeper();
$kY8c = new BenchKeeper();
$y8Db = new SqliteAdapter('sqlite::memory:');
$dgY8a = new SelfDataGuard($y8Db, $blindKey, keeper: $kY8a);
$yara = $dgY8a->register('yara', 'motdepasse-yara-01');
SelfDataGuard::changeKeeper($y8Db, $kY8a, $kY8b);
$reads = (new SelfDataGuard($y8Db, $blindKey, keeper: $kY8b))->escrowReads($yara);
$reads['reason'] === 'keeper-changed' && !$reads['reseal']
    ? ok('no escrow row, a recorded former keeper not given: "keeper-changed", and no re-sealing asked for a row that is not there')
    : ko('an absent row asks a re-sealing', json_encode($reads));
$printY8a = KeeperSeal::fingerprint($kY8a->publicKeys());
try {
    SelfDataGuard::forgetFormerKeeper($y8Db, KeeperSeal::fingerprint($kY8c->publicKeys()));
    ko('forgetFormerKeeper() took a keeper the database never recorded');
} catch (InvalidArgumentException) {
    ok('forgetFormerKeeper() refuses a fingerprint the database does not record');
}
try {
    (new SelfDataGuard($y8Db, $blindKey, keeper: $kY8b))->setEscrowFields($yara, $admin['publicKey'], ['contact' => 'yara@example.org']);
    ko('a deposit went through without the recorded former keeper, before it was forgotten');
} catch (EscrowKeeperException) {
}
SelfDataGuard::forgetFormerKeeper($y8Db, $printY8a);
try {
    (new SelfDataGuard($y8Db, $blindKey, keeper: $kY8b))->setEscrowFields($yara, $admin['publicKey'], ['contact' => 'yara@example.org']);
    !in_array($printY8a, KeeperSeal::formerPrints($y8Db->loadSetting(SelfDataGuard::SETTING_FORMER_KEEPERS)), true)
        ? ok('a former keeper forgotten by the operator: the deposits go through without it again')
        : ko('forgetFormerKeeper() left the keeper on the record');
} catch (Throwable $e) {
    ko('a former keeper forgotten still blocks the deposits', $e->getMessage());
}
try {
    benchCeremony($y8Db, keeper: $kY8b, formerKeepers: [$kY8a])->readEscrow('yara', 'LIT-Y8', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
    ko('the ceremony read through a former keeper the operator forgot');
} catch (EscrowKeeperException $e) {
    $e->reason === 'keeper-mismatch'
        ? ok('a former keeper forgotten: the ceremony refuses it, keeper-mismatch')
        : ko('a forgotten former keeper was refused by the ceremony, but not as keeper-mismatch', $e->reason);
}

$counted = [new CountingKeeper(), new CountingKeeper(), new CountingKeeper(), new CountingKeeper()];
$y9Db = new SqliteAdapter('sqlite::memory:');
$yeti = (new SelfDataGuard($y9Db, $blindKey, keeper: $counted[0]))->register('yeti', 'motdepasse-yeti-01');
foreach ([[0, 1], [1, 2], [2, 3]] as [$from, $to]) {
    SelfDataGuard::changeKeeper($y9Db, $counted[$from], $counted[$to]);
}
foreach ($counted as $k) {
    $k->keyCalls = 0;
}
$dgY9 = new SelfDataGuard($y9Db, $blindKey, keeper: $counted[3], formerKeepers: [$counted[0], $counted[1], $counted[2]]);
$dgY9->setEscrowFields($yeti, $admin['publicKey'], ['contact' => 'yeti@example.org']);
$dgY9->setEscrowFields($yeti, $admin['publicKey'], ['indice' => 'yeti']);
$dgY9->escrowReads($yeti);
max(array_map(static fn (CountingKeeper $k): int => $k->keyCalls, $counted)) <= 1
    ? ok('two deposits and a view under three former keepers: each gives its public keys once to the façade')
    : ko('the façade asks the former keepers their public keys again and again', json_encode(array_map(static fn (CountingKeeper $k): int => $k->keyCalls, $counted)));

$pdoP->exec("DELETE FROM selfdataguard_escrow WHERE user_id = 'paul'");
try {
    $dgP->setEscrowFields($paul, $admin['publicKey'], ['contact' => 'paul-2@example.org']);
    ko('a deposit went through over fields its envelope was deleted from');
} catch (EscrowEnvelopeMismatchException) {
    $restDb->loadEscrow('paul') === null
        ? ok('the row deleted, its fields kept: the deposit is refused, EscrowEnvelopeMismatchException, and no row is written over the fields')
        : ko('the row deleted: the deposit was refused, but wrote a row its fields do not open');
} catch (Throwable $e) {
    ko('the row deleted: the deposit raised a bare error', get_class($e) . ': ' . $e->getMessage());
}

final class KeysDownKeeper extends BenchKeeper
{
    public bool $keysDown = false;

    public function publicKeys(): array
    {
        if ($this->keysDown) {
            throw new RuntimeException('The keeper gives no keys');
        }
        return parent::publicKeys();
    }
}
$kZa = new KeysDownKeeper(delay: 3600);
$kZb = new KeysDownKeeper(delay: 3600);
$pdoZ = new PDO('sqlite::memory:');
$zDb = new SqliteAdapter($pdoZ);
$zack = (new SelfDataGuard($zDb, $blindKey, keeper: $kZa))->register('zack', 'motdepasse-zack-01');
(new SelfDataGuard($zDb, $blindKey, keeper: $kZa))->setEscrowFields($zack, $admin['publicKey'], ['contact' => 'zack@example.org']);
$zackRow = $zDb->loadEscrow('zack');
SelfDataGuard::changeKeeper($zDb, $kZa, $kZb);
$kZa->announce($zack->escrowId(), KeeperSeal::outerOf($zackRow->wrapAdmin), 'zack', 'LIT-ZA', 0, 'h');
$kZb->keysDown = true;
$reads = (new SelfDataGuard($zDb, $blindKey, keeper: $kZb, formerKeepers: [$kZa]))->escrowReads($zack);
$kZb->keysDown = false;
!$reads['established'] && $reads['reason'] === 'keeper-unreachable' && $reads['keeper'] === KeeperSeal::fingerprint($kZb->publicKeys())
    && in_array('LIT-ZA', $casesOf($reads['pending']), true)
    ? ok('the façade\'s own keeper gives no keys: the view names it, and still lists what the former keeper holds announced')
    : ko('a silent façade keeper hides what the former keepers hold', json_encode($reads));
$kZa->keysDown = true;
$reads = (new SelfDataGuard($zDb, $blindKey, keeper: $kZb, formerKeepers: [$kZa]))->escrowReads($zack);
$kZa->keysDown = false;
$reads['reason'] === 'keeper-unreachable'
    ? ok('a recorded former keeper given to the façade, without keys: "keeper-unreachable" — not "keeper-changed"')
    : ko('a former keeper that gives no keys reads as a keeper changed by hand', json_encode($reads));
$pdoZ->exec("DELETE FROM selfdataguard_escrow WHERE user_id = 'zack'");
try {
    (new SelfDataGuard($zDb, $blindKey, keeper: $kZb, formerKeepers: [$kZa]))->setEscrowFields($zack, $admin['publicKey'], ['contact' => 'zack-2@example.org']);
    ko('a deposit went through over fields whose row was deleted');
} catch (EscrowEnvelopeMismatchException) {
    !isset($kZa->retired[$hexOf($zack)])
        ? ok('a deposit refused for a row that does not open its fields retires nothing at the former keepers')
        : ko('a deposit refused for a row that does not open its fields retired the escrow at the former keeper first');
}
foreach (['rotateEscrow' => static fn () => (new SelfDataGuard($zDb, $blindKey, keeper: $kZb, formerKeepers: [$kZa]))->rotateEscrow($zack, $admin['publicKey']),
          'sealEscrowToKeeper' => static fn () => (new SelfDataGuard($zDb, $blindKey, keeper: $kZb, formerKeepers: [$kZa]))->sealEscrowToKeeper($zack, $admin['publicKey'])] as $what => $call) {
    try {
        $done = $call();
        $what === 'sealEscrowToKeeper' && $done === false
            ? ok('sealEscrowToKeeper() on a deleted row: false, nothing to re-seal')
            : ko("{$what}() on a deleted row whose fields stayed did not say so", json_encode($done));
    } catch (EscrowEnvelopeMismatchException) {
        $what === 'rotateEscrow'
            ? ok('rotateEscrow() on a deleted row whose fields stayed: EscrowEnvelopeMismatchException, not "no escrow"')
            : ko('sealEscrowToKeeper() on a deleted row raised instead of returning false');
    }
}
SelfDataGuard::forgetFormerKeeper($zDb, KeeperSeal::fingerprint($kZa->publicKeys()));
try {
    SelfDataGuard::changeKeeper($zDb, $kZb, $kZa);
    ko('changeKeeper() took back a keeper the operator forgot — escrows were retired from it');
} catch (InvalidArgumentException) {
    ok('a forgotten keeper is never taken back: changeKeeper() refuses it as the new keeper');
}
$pdoY2->prepare("UPDATE selfdataguard_escrow SET wrap_user = ? WHERE user_id = 'zoe'")->execute([base64_encode(random_bytes(150))]);
$reads = $dgY2->escrowReads($zoe);
$reads['reason'] === 'escrow-replaced' && !$reads['reseal']
    ? ok('a row that does not open: no re-sealing asked — sealEscrowToKeeper() would be refused')
    : ko('a row that does not open asks a re-sealing that is refused', json_encode($reads));
try {
    $dgY2->sealEscrowToKeeper($zoe, $admin['publicKey']);
    ko('sealEscrowToKeeper() went through on a row that does not open');
} catch (EscrowEnvelopeMismatchException) {
    ok('sealEscrowToKeeper() on a row that does not open: EscrowEnvelopeMismatchException, nothing written');
} catch (Throwable $e) {
    ko('sealEscrowToKeeper() on a row that does not open raised a bare error', get_class($e));
}

$kQa = new BenchKeeper(delay: 3600);
$kQc = new BenchKeeper(delay: 3600);
$qDb = new SqliteAdapter('sqlite::memory:');
$dgQ = new SelfDataGuard($qDb, $blindKey, keeper: $kQa, formerKeepers: [$kQc]);
$quil = $dgQ->register('quil', 'motdepasse-quil-01');
$dgQ->setEscrowFields($quil, $admin['publicKey'], ['contact' => 'quil@example.org']);
in_array(KeeperSeal::fingerprint($kQc->publicKeys()), KeeperSeal::formerPrints($qDb->loadSetting(SelfDataGuard::SETTING_FORMER_KEEPERS)), true)
    ? ok('a keeper given as former that the deployment never replaced: the deposit writes it into the record before retiring there')
    : ko('a keeper given as former took the retirement without the record knowing it');
try {
    SelfDataGuard::changeKeeper($qDb, $kQa, $kQc);
    ko('changeKeeper() promoted a keeper that took retirements as a former one — the escrows retired there no longer read');
} catch (InvalidArgumentException) {
    ok('a keeper that took retirements as a former one is never promoted: changeKeeper() refuses it, loudly');
}
try {
    (new SelfDataGuard($y8Db, $blindKey, keeper: $kY8b, formerKeepers: [$kY8a]))->setEscrowFields($yara, $admin['publicKey'], ['contact' => 'yara-2@example.org']);
    ko('a keeper the operator forgot, given again as former, took a retirement');
} catch (EscrowKeeperException $e) {
    $e->reason === 'keeper-mismatch'
        ? ok('a keeper the operator forgot, given again as former: the deposit is refused, keeper-mismatch')
        : ko('a forgotten keeper given again was refused, but not as keeper-mismatch', $e->reason);
}

final class FailOnceSetting extends DelegatingStorage
{
    public ?string $failName = null;

    public function replaceSetting(string $name, string $expected, string $value): bool
    {
        if ($name === $this->failName) {
            $this->failName = null;
            return false;
        }
        return $this->inner->replaceSetting($name, $expected, $value);
    }
}
$kFa = new BenchKeeper();
$kFb = new BenchKeeper();
$fDb = new FailOnceSetting(new SqliteAdapter('sqlite::memory:'));
(new SelfDataGuard($fDb, $blindKey, keeper: $kFa))->register('fanny', 'motdepasse-fanny-01');
SelfDataGuard::changeKeeper($fDb, $kFa, $kFb);
$printFa = KeeperSeal::fingerprint($kFa->publicKeys());
$fDb->failName = SelfDataGuard::SETTING_FORMER_KEEPERS;
try {
    SelfDataGuard::forgetFormerKeeper($fDb, $printFa);
    ko('forgetFormerKeeper() said nothing when the record of former keepers changed under it');
} catch (InvalidArgumentException $e) {
    str_contains($e->getMessage(), 'call again')
        ? ok('forgetFormerKeeper() interrupted: its message says the forgotten record took the keeper, and to call again')
        : ko('forgetFormerKeeper() interrupted says "nothing was changed" when the forgotten record took the keeper', $e->getMessage());
}
try {
    SelfDataGuard::forgetFormerKeeper($fDb, $printFa);
    !in_array($printFa, KeeperSeal::formerPrints($fDb->loadSetting(SelfDataGuard::SETTING_FORMER_KEEPERS)), true)
        ? ok('forgetFormerKeeper() called again ends the act')
        : ko('forgetFormerKeeper() called again left the keeper among the former ones');
} catch (Throwable $e) {
    ko('forgetFormerKeeper() called again was refused', $e->getMessage());
}
$fDb->replaceSetting(SelfDataGuard::SETTING_FORGOTTEN_KEEPERS, (string) $fDb->loadSetting(SelfDataGuard::SETTING_FORGOTTEN_KEEPERS), '["unterminated');
try {
    SelfDataGuard::changeKeeper($fDb, $kFb, new BenchKeeper());
    ko('changeKeeper() went on over an unreadable record of forgotten keepers');
} catch (InvalidArgumentException $e) {
    str_contains($e->getMessage(), 'forgotten keepers')
        ? ok('an unreadable record of forgotten keepers is named as such')
        : ko('an unreadable record of forgotten keepers is named as the former one', $e->getMessage());
}

try {
    $dgP->getEscrowFieldsAsUser($paul);
    ko('the row deleted, its fields kept: the holder\'s read returned the fields as if there were none');
} catch (EscrowEnvelopeMismatchException) {
    ok('the row deleted, its fields kept: the holder\'s read raises EscrowEnvelopeMismatchException, not an empty list');
}
$dgP->hasEscrow($paul)
    ? ok('the row deleted, its fields kept: hasEscrow() still says there is an escrow')
    : ko('the row deleted, its fields kept: hasEscrow() says there is none');

$pdoF = new PDO('sqlite::memory:');
$fieldDb = new SqliteAdapter($pdoF);
$dgF = new SelfDataGuard($fieldDb, $blindKey, keeper: new BenchKeeper());
$fred = $dgF->register('fred', 'motdepasse-fred-01');
$dgF->setEscrowFields($fred, $admin['publicKey'], ['contact' => 'fred@example.org', 'indice' => 'fred']);
$pdoF->exec("UPDATE selfdataguard_escrow_fields SET ciphertext = (SELECT ciphertext FROM selfdataguard_escrow_fields WHERE field_name = 'indice') WHERE field_name = 'contact'");
try {
    $dgF->setEscrowFields($fred, $admin['publicKey'], ['indice' => 'fred-2']);
    ko('a deposit went through over a field rewritten in the database');
} catch (EscrowEnvelopeMismatchException) {
    ok('one field rewritten in the database: the deposit is refused, EscrowEnvelopeMismatchException');
} catch (Throwable $e) {
    ko('one field rewritten in the database: the deposit raised a bare error', get_class($e));
}

$pdoY2->prepare("UPDATE selfdataguard_escrow SET wrap_user = ? WHERE user_id = 'zoe'")->execute(['n0t-base64!!']);
foreach (['hasEscrow' => static fn () => $dgY2->hasEscrow($zoe),
          'rebindEscrowAdmin' => static fn () => $dgY2->rebindEscrowAdmin($zoe, $admin['publicKey'])] as $what => $call) {
    try {
        $answer = $call();
        $what === 'hasEscrow' && $answer === true
            ? ok('hasEscrow() on a row that does not parse: true — there is an escrow, unreadable')
            : ko("{$what}() on a row that does not parse answered", json_encode($answer));
    } catch (EscrowEnvelopeMismatchException) {
        $what === 'rebindEscrowAdmin'
            ? ok('rebindEscrowAdmin() on a row that does not parse: EscrowEnvelopeMismatchException')
            : ko('hasEscrow() on a row that does not parse raised');
    } catch (Throwable $e) {
        ko("{$what}() on a row that does not parse raised a bare error", get_class($e));
    }
}

$kWa = new BenchKeeper(delay: 3600);
$kWb = new BenchKeeper(delay: 3600);
$kWc = new BenchKeeper(delay: 3600);
$wDb = new SqliteAdapter('sqlite::memory:');
$wade = (new SelfDataGuard($wDb, $blindKey, keeper: $kWa))->register('wade', 'motdepasse-wade-01');
(new SelfDataGuard($wDb, $blindKey, keeper: $kWa))->setEscrowFields($wade, $admin['publicKey'], ['contact' => 'wade@example.org']);
SelfDataGuard::changeKeeper($wDb, $kWa, $kWb);
SelfDataGuard::changeKeeper($wDb, $kWb, $kWc);
$kWa->retire($wade->escrowId(), $wade->signEscrowRetirement(KeeperSeal::fingerprint($kWa->publicKeys())));
$kWb->down = true;
$kWc->down = true;
$reads = (new SelfDataGuard($wDb, $blindKey, keeper: $kWc, formerKeepers: [$kWa, $kWb]))->escrowReads($wade);
$kWb->down = false;
$kWc->down = false;
$silentPrints = array_column($reads['unanswered'], 'keeper');
$reads['reason'] === 'retired' && in_array(KeeperSeal::fingerprint($kWb->publicKeys()), $silentPrints, true)
    && in_array(KeeperSeal::fingerprint($kWc->publicKeys()), $silentPrints, true)
    ? ok('two keepers that do not answer: "unanswered" names both, and "retired" is kept for the keeper the row pins')
    : ko('a silent keeper hides "retired", or only one silent keeper is named', json_encode($reads));

$kOa = new BenchKeeper(delay: 3600);
$kOb = new BenchKeeper(delay: 3600);
$oDb = new SqliteAdapter('sqlite::memory:');
$olaf = (new SelfDataGuard($oDb, $blindKey, keeper: $kOa))->register('olaf', 'motdepasse-olaf-01');
(new SelfDataGuard($oDb, $blindKey, keeper: $kOa))->setEscrowFields($olaf, $admin['publicKey'], ['contact' => 'olaf@example.org']);
$olafRow = $oDb->loadEscrow('olaf');
SelfDataGuard::changeKeeper($oDb, $kOa, $kOb);
$kOa->announce($olaf->escrowId(), KeeperSeal::outerOf($olafRow->wrapAdmin), 'olaf', 'LIT-O', 0, 'h');
$dgO = new SelfDataGuard($oDb, $blindKey, keeper: $kOb, formerKeepers: [$kOa]);
$kOb->down = true;
try {
    $dgO->objectToEscrowRead($olaf, 'LIT-O');
    ko('an objection recorded by one keeper, the other silent, reported no failure');
} catch (EscrowKeeperException $e) {
    $e->reason === 'objection-partial' && $kOa->pending[$hexOf($olaf) . '|LIT-O']['objected']
        ? ok('an objection recorded by one keeper, another silent: reason "objection-partial", not a failure of the whole')
        : ko('a partial objection reads as a failure of the whole', $e->reason);
}
$kOb->down = false;

$kV = new BenchKeeper(delay: 3600);
$pdoV2 = new PDO('sqlite::memory:');
$vDb = new SqliteAdapter($pdoV2);
$dgV = new SelfDataGuard($vDb, $blindKey, keeper: $kV);
$vera = $dgV->register('vera', 'motdepasse-vera-01');
$dgV->setEscrowFields($vera, $admin['publicKey'], ['contact' => 'vera@example.org']);
try {
    benchCeremony($vDb, keeper: $kV)->readEscrow('vera', 'LIT-V1', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
}
$veraRow = $pdoV2->query("SELECT wrap_admin, created_at, revision FROM selfdataguard_escrow WHERE user_id = 'vera'")->fetch(PDO::FETCH_ASSOC);
foreach ([
    'wrap_admin not base64'                 => ['wrap_admin', '?', ['n0t-base64!!']],
    'a date that does not parse'            => ['created_at', '?', ['not a date']],
    'a revision that is not an integer'     => ['revision', '?', ['abc']],
    'a revision stored as a blob'           => ['revision', 'CAST(revision AS BLOB)', []],
    'a revision the next write cannot bump' => ['revision', '?', [PHP_INT_MAX]],
] as $what => [$column, $value, $params]) {
    $pdoV2->prepare("UPDATE selfdataguard_escrow SET {$column} = {$value} WHERE user_id = 'vera'")->execute($params);
    try {
        $reads = $dgV->escrowReads($vera);
        $reads['reason'] === 'escrow-replaced' && in_array('LIT-V1', $casesOf($reads['pending']), true)
            ? ok("a row with {$what}: \"escrow-replaced\", and the announcement the keeper holds is shown")
            : ko("a row with {$what} hides what the keeper holds announced", json_encode($reads));
    } catch (Throwable $e) {
        ko("a row with {$what}: escrowReads() threw before asking the keeper", get_class($e));
    }
    try {
        $dgV->setEscrowFields($vera, $admin['publicKey'], ['contact' => 'vera-2@example.org']);
        ko("a row with {$what}: the deposit went through");
    } catch (EscrowEnvelopeMismatchException $e) {
        $e->getPrevious() instanceof UnreadableEscrowException
            ? ok("a row with {$what}: the deposit is refused, EscrowEnvelopeMismatchException, the unreadable row as its cause")
            : ko("a row with {$what}: the deposit is refused without the unreadable row as its cause", get_debug_type($e->getPrevious()));
    } catch (Throwable $e) {
        ko("a row with {$what}: the deposit raised a bare error", get_class($e));
    }
    $pdoV2->prepare("UPDATE selfdataguard_escrow SET {$column} = ? WHERE user_id = 'vera'")->execute([$veraRow[$column]]);
}
$pdoV2->prepare("UPDATE selfdataguard_escrow SET wrap_admin = ? WHERE user_id = 'vera'")->execute(['n0t-base64!!']);
try {
    $dgV->objectToEscrowRead($vera, 'LIT-V1');
    $kV->pending[$hexOf($vera) . '|LIT-V1']['objected']
        ? ok('a row with wrap_admin not base64: the holder objects all the same')
        : ko('a row with wrap_admin not base64: the objection did not reach the keeper');
} catch (Throwable $e) {
    ko('a row with wrap_admin not base64: the holder cannot object', get_class($e));
}
$pdoV2->prepare("UPDATE selfdataguard_escrow SET wrap_admin = ? WHERE user_id = 'vera'")->execute([$veraRow['wrap_admin']]);
$pdoV2->prepare("UPDATE selfdataguard_escrow SET revision = ? WHERE user_id = 'vera'")->execute([PHP_INT_MAX - 1]);
$reads = $dgV->escrowReads($vera);
$reads['reason'] !== 'escrow-replaced'
    ? ok('a revision one below the maximum still reads: the bound refuses only what the next write cannot bump')
    : ko('a revision one below the maximum reads as a row rewritten', json_encode($reads));
$pdoV2->prepare("UPDATE selfdataguard_escrow SET revision = ? WHERE user_id = 'vera'")->execute([$veraRow['revision']]);

/** loadEscrow() raises $raise while it is set: an outage, or a storage that names an unreadable row. */
final class FailingEscrowRead extends DelegatingStorage
{
    public ?Throwable $raise = null;

    public function loadEscrow(string $u): ?EscrowRecord
    {
        return $this->raise === null ? $this->inner->loadEscrow($u) : throw $this->raise;
    }
}
$kT = new BenchKeeper(delay: 3600);
$tInner = new SqliteAdapter('sqlite::memory:');
$tDb = new FailingEscrowRead($tInner);
$dgT = new SelfDataGuard($tDb, $blindKey, keeper: $kT);
$tess = $dgT->register('tess', 'motdepasse-tess-01');
$dgT->setEscrowFields($tess, $admin['publicKey'], ['contact' => 'tess@example.org']);
try {
    benchCeremony($tInner, keeper: $kT)->readEscrow('tess', 'LIT-T1', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
}
$tDb->raise = new RuntimeException('the storage does not answer');
foreach ([
    'hasEscrow()'             => static fn () => $dgT->hasEscrow($tess),
    'escrowReads()'           => static fn () => $dgT->escrowReads($tess),
    'getEscrowFieldsAsUser()' => static fn () => $dgT->getEscrowFieldsAsUser($tess),
    'setEscrowFields()'       => static fn () => $dgT->setEscrowFields($tess, $admin['publicKey'], ['contact' => 'tess-2@example.org']),
    'rotateEscrow()'          => static fn () => $dgT->rotateEscrow($tess, $admin['publicKey']),
    'sealEscrowToKeeper()'    => static fn () => $dgT->sealEscrowToKeeper($tess, $admin['publicKey']),
    'rebindEscrowAdmin()'     => static fn () => $dgT->rebindEscrowAdmin($tess, $admin['publicKey']),
    'objectToEscrowRead()'    => static fn () => $dgT->objectToEscrowRead($tess, 'LIT-T1'),
] as $call => $run) {
    try {
        $got = $run();
        ko("a storage outage under {$call}: it returned as if the row did not parse", json_encode($got));
    } catch (EscrowEnvelopeMismatchException $e) {
        ko("a storage outage under {$call} reads as an unreadable row: EscrowEnvelopeMismatchException");
    } catch (RuntimeException $e) {
        $e === $tDb->raise
            ? ok("a storage outage under {$call}: the outage itself comes out")
            : ko("a storage outage under {$call} came out as another error", get_class($e) . ': ' . $e->getMessage());
    }
}
$tDb->raise = new UnreadableEscrowException('a column rewritten');
$reads = $dgT->escrowReads($tess);
$reads['reason'] === 'escrow-replaced' && in_array('LIT-T1', $casesOf($reads['pending']), true)
    ? ok('a storage that names an unreadable row: "escrow-replaced", and the announcement the keeper holds is shown')
    : ko('a storage that names an unreadable row: the view does not say it', json_encode($reads));
try {
    $dgT->objectToEscrowRead($tess, 'LIT-T1');
    $kT->pending[$hexOf($tess) . '|LIT-T1']['objected']
        ? ok('a storage that names an unreadable row: the holder objects all the same')
        : ko('a storage that names an unreadable row: the objection did not reach the keeper');
} catch (Throwable $e) {
    ko('a storage that names an unreadable row: the holder cannot object', get_class($e));
}
try {
    $dgT->setEscrowFields($tess, $admin['publicKey'], ['contact' => 'tess-2@example.org']);
    ko('a storage that names an unreadable row: the deposit went through');
} catch (EscrowEnvelopeMismatchException $e) {
    $e->getPrevious() === $tDb->raise
        ? ok('a storage that names an unreadable row: the deposit is refused, EscrowEnvelopeMismatchException, with it as its cause')
        : ko('a storage that names an unreadable row: the deposit is refused without it as its cause');
}
$tDb->raise = null;

$typed = static fn (int $revision): int => $revision;
try {
    EscrowRecord::parse('tess', static fn () => $typed('abc'));
    ko('EscrowRecord::parse(): a TypeError in the build came out as a record');
} catch (UnreadableEscrowException $e) {
    $e->getPrevious() instanceof TypeError
        ? ok('EscrowRecord::parse(): a TypeError in the build — a value of the wrong shape — comes out as UnreadableEscrowException')
        : ko('EscrowRecord::parse(): the TypeError is not kept as the cause', get_debug_type($e->getPrevious()));
} catch (TypeError) {
    ko('EscrowRecord::parse(): a TypeError in the build comes out bare: the library takes it for an outage');
}
$named = new UnreadableEscrowException('raised by the build itself');
try {
    EscrowRecord::parse('tess', static fn () => throw $named);
} catch (UnreadableEscrowException $e) {
    $e === $named
        ? ok('EscrowRecord::parse(): an UnreadableEscrowException the build raises comes out as it is')
        : ko('EscrowRecord::parse(): an UnreadableEscrowException the build raises is wrapped again');
}
try {
    EscrowRecord::parse('tess', static fn () => throw new ValueError('a value out of range'));
    ko('EscrowRecord::parse(): a ValueError in the build came out as a record');
} catch (UnreadableEscrowException $e) {
    $e->getPrevious() instanceof ValueError && str_contains($e->getMessage(), 'ValueError')
        ? ok('EscrowRecord::parse(): a ValueError in the build comes out as UnreadableEscrowException, its class named')
        : ko('EscrowRecord::parse(): a ValueError is not kept, or not named, as the cause', $e->getMessage());
} catch (ValueError) {
    ko('EscrowRecord::parse(): a ValueError in the build comes out bare');
}
try {
    EscrowRecord::parse('tess', static fn () => throw new ArgumentCountError('a call written wrong'));
    ko('EscrowRecord::parse(): an ArgumentCountError in the build came out as a record');
} catch (UnreadableEscrowException) {
    ko('EscrowRecord::parse(): an ArgumentCountError — a bug — reads as a row rewritten');
} catch (ArgumentCountError) {
    ok('EscrowRecord::parse(): an ArgumentCountError — a call written wrong, never a value — comes out as it is');
}

/** createEscrow() always finds an escrow there, as a trigger could make it; past a bound it fails, so that a deposit that does not stop fails the bench instead of hanging it. */
final class AlwaysExistsEscrow extends DelegatingStorage
{
    public int $creations = 0;

    public function createEscrow(VaultGeneration $generation, EscrowRecord $record): void
    {
        if (++$this->creations > 10) {
            throw new RuntimeException('createEscrow() called more than ten times: the deposit does not stop');
        }
        throw new EscrowExistsException("An escrow already exists for '{$record->userId}'");
    }
}
$xDb = new AlwaysExistsEscrow(new SqliteAdapter('sqlite::memory:'));
$dgX = new SelfDataGuard($xDb, $blindKey, keeper: $keeper);
$xena = $dgX->register('xena', 'motdepasse-xena-01');
try {
    $dgX->setEscrowFields($xena, $admin['publicKey'], ['contact' => 'xena@example.org']);
    ko('a first deposit whose escrow keeps appearing went through');
} catch (StaleEscrowException) {
    $xDb->creations === 3
        ? ok('a first deposit whose escrow keeps appearing stops after three attempts, StaleEscrowException')
        : ko('a first deposit whose escrow keeps appearing stopped after another number of attempts', (string) $xDb->creations);
} catch (RuntimeException $e) {
    ko('a first deposit whose escrow keeps appearing does not stop', $e->getMessage());
}

/** rotateEscrow() always finds the envelope replaced since it was read; past a bound it fails, so that a deposit that does not stop fails the bench instead of hanging it. */
final class AlwaysStaleRotation extends DelegatingStorage
{
    public int $rotations = 0;

    public function rotateEscrow(VaultGeneration $generation, EscrowRecord $record, array $fields): void
    {
        if (++$this->rotations > 10) {
            throw new RuntimeException('rotateEscrow() called more than ten times: the deposit does not stop');
        }
        throw new StaleEscrowException("The escrow of '{$record->userId}' was replaced since this record was read");
    }
}
$sInner = new SqliteAdapter('sqlite::memory:');
$sDb = new AlwaysStaleRotation($sInner);
$yara = (new SelfDataGuard($sInner, $blindKey, keeper: $keeper))->register('yara', 'motdepasse-yara-01');
(new SelfDataGuard($sInner, $blindKey, keeper: $keeper))->setEscrowFields($yara, $admin['publicKey'], ['contact' => 'yara@example.org']);
try {
    (new SelfDataGuard($sDb, $blindKey, keeper: $keeper))->setEscrowFields($yara, $admin['publicKey'], ['contact' => 'yara-2@example.org']);
    ko('a deposit whose envelope is always replaced meanwhile went through');
} catch (StaleEscrowException $e) {
    $sDb->rotations === 3 && str_contains($e->getMessage(), 'was replaced since this record was read')
        ? ok('a deposit whose envelope is always replaced meanwhile stops after three attempts, with the storage\'s StaleEscrowException')
        : ko('a deposit whose envelope is always replaced meanwhile does not stop on its own bound', $sDb->rotations . ': ' . $e->getMessage());
} catch (RuntimeException $e) {
    ko('a deposit whose envelope is always replaced meanwhile does not stop', $e->getMessage());
}

/** Another writer always wins the record of former keepers; past a bound it fails instead of hanging the bench. */
final class FormersKeepChanging extends DelegatingStorage
{
    public int $claims = 0;

    public function claimSetting(string $n, string $v): string
    {
        if ($n !== SelfDataGuard::SETTING_FORMER_KEEPERS) {
            return $this->inner->claimSetting($n, $v);
        }
        if (++$this->claims > 10) {
            throw new RuntimeException('the record of former keepers was claimed more than ten times: the deposit does not stop');
        }
        return '[]';
    }
}
$fDb = new FormersKeepChanging(new SqliteAdapter('sqlite::memory:'));
$fDg = new SelfDataGuard($fDb, $blindKey, keeper: $keeper, formerKeepers: [new BenchKeeper()]);
$zack = $fDg->register('zack', 'motdepasse-zack-01');
try {
    $fDg->setEscrowFields($zack, $admin['publicKey'], ['contact' => 'zack@example.org']);
    ko('a deposit whose record of former keepers keeps changing went through');
} catch (EscrowKeeperException $e) {
    $e->reason === 'keeper-failed' && $fDb->claims === 3
        ? ok('a record of former keepers that keeps changing: the deposit stops after three attempts, keeper-failed')
        : ko('a record of former keepers that keeps changing: refused otherwise', $e->reason . ' after ' . $fDb->claims);
} catch (RuntimeException $e) {
    ko('a deposit whose record of former keepers keeps changing does not stop', $e->getMessage());
}

$kVb = new BenchKeeper(delay: 3600);
$pdoVb = new PDO('sqlite::memory:');
$vbDb = new SqliteAdapter($pdoVb);
$dgVb = new SelfDataGuard($vbDb, $blindKey, keeper: $kVb);
$vince = $dgVb->register('vince', 'motdepasse-vince-01');
$dgVb->setEscrowFields($vince, $admin['publicKey'], ['contact' => 'vince@example.org']);
$pdoVb->exec("DELETE FROM selfdataguard_escrow WHERE user_id = 'vince'");
$reads = $dgVb->escrowReads($vince);
!$reads['established'] && $reads['reason'] === 'escrow-replaced' && !$reads['reseal']
    ? ok('the row deleted, its fields kept, no read held anywhere: "escrow-replaced", not "established"')
    : ko('the row deleted, its fields kept: the view says established while the fields refuse', json_encode($reads));

$kNa = new BenchKeeper(delay: 3600);
$kNb = new BenchKeeper(delay: 3600);
$nDb = new SqliteAdapter('sqlite::memory:');
$nils = (new SelfDataGuard($nDb, $blindKey))->register('nils', 'motdepasse-nils-01');
(new SelfDataGuard($nDb, $blindKey))->setEscrowFields($nils, $admin['publicKey'], ['contact' => 'nils@example.org']);
benchCeremony($nDb, keeper: $kNa)->sealToKeeper('nils');
SelfDataGuard::changeKeeper($nDb, $kNa, $kNb);
$kNa->down = true;
$reads = (new SelfDataGuard($nDb, $blindKey, keeper: $kNb, formerKeepers: [$kNa]))->escrowReads($nils);
$kNa->down = false;
$reads['reason'] === 'not-pinned' && in_array(KeeperSeal::fingerprint($kNa->publicKeys()), array_column($reads['unanswered'], 'keeper'), true)
    ? ok('a row the operator\'s pass sealed, a former keeper silent: "not-pinned" is kept, and "unanswered" names the silent one')
    : ko('a silent keeper hides that the row was sealed by the pass', json_encode($reads));

final class HookedSettings extends DelegatingStorage
{
    /** @var array<string, int> */
    public array $reads = [];

    /** @var array<string, array{0: int, 1: callable}> name => [the read it fires on, what it runs] */
    public array $onRead = [];

    /** @var (callable(): void)|null runs once, before the first replaceSetting() */
    public $beforeReplace = null;

    public function loadSetting(string $name): ?string
    {
        $this->reads[$name] = ($this->reads[$name] ?? 0) + 1;
        if (isset($this->onRead[$name]) && $this->onRead[$name][0] === $this->reads[$name]) {
            $run = $this->onRead[$name][1];
            unset($this->onRead[$name]);
            $run();
        }
        return $this->inner->loadSetting($name);
    }

    public function replaceSetting(string $name, string $expected, string $value): bool
    {
        if ($this->beforeReplace !== null) {
            $run = $this->beforeReplace;
            $this->beforeReplace = null;
            $run();
        }
        return $this->inner->replaceSetting($name, $expected, $value);
    }
}
$kHa = new BenchKeeper();
$kHb = new BenchKeeper();
$hInner = new SqliteAdapter('sqlite::memory:');
$hDb = new HookedSettings($hInner);
$hugo = (new SelfDataGuard($hInner, $blindKey, keeper: $kHa))->register('hugo', 'motdepasse-hugo-01');
SelfDataGuard::changeKeeper($hInner, $kHa, $kHb);
$printHa = KeeperSeal::fingerprint($kHa->publicKeys());
$hDb->reads = [];
$hDb->onRead[SelfDataGuard::SETTING_FORMER_KEEPERS] = [2, static fn () => SelfDataGuard::forgetFormerKeeper($hInner, $printHa)];
try {
    (new SelfDataGuard($hDb, $blindKey, keeper: $kHb, formerKeepers: [$kHa]))->setEscrowFields($hugo, $admin['publicKey'], ['contact' => 'hugo@example.org']);
    ko('a deposit under way when the operator forgot its former keeper went through');
} catch (EscrowKeeperException $e) {
    !in_array($printHa, KeeperSeal::formerPrints($hInner->loadSetting(SelfDataGuard::SETTING_FORMER_KEEPERS)), true)
        ? ok('a deposit under way when the operator forgets its former keeper is refused, and does not write the keeper back')
        : ko('a deposit under way wrote the forgotten keeper back among the former ones', $e->getMessage());
}

$kJa = new BenchKeeper();
$kJc = new BenchKeeper();
$jInner = new SqliteAdapter('sqlite::memory:');
$jDb = new HookedSettings($jInner);
(new SelfDataGuard($jInner, $blindKey, keeper: $kJa))->register('jade', 'motdepasse-jade-01');
$printJa = KeeperSeal::fingerprint($kJa->publicKeys());
$printJc = KeeperSeal::fingerprint($kJc->publicKeys());
$jInner->claimSetting(SelfDataGuard::SETTING_FORMER_KEEPERS, json_encode([$printJa]));
$jDb->beforeReplace = static function () use ($jInner, $printJa, $printJc): void {
    $jInner->replaceSetting(SelfDataGuard::SETTING_FORMER_KEEPERS, json_encode([$printJa]), json_encode([$printJa, $printJc]));
};
try {
    SelfDataGuard::changeKeeper($jDb, $kJa, $kJc);
    ko('changeKeeper() promoted a keeper a deposit wrote among the former ones while it checked');
} catch (InvalidArgumentException) {
    $jInner->loadSetting(SelfDataGuard::SETTING_KEEPER) === $printJa
        ? ok('a deposit that writes the coming keeper among the former ones while changeKeeper() checks: the change is refused')
        : ko('changeKeeper() refused, but changed the keeper');
}

$kKa = new BenchKeeper();
$kKc = new BenchKeeper();
$kInner = new SqliteAdapter('sqlite::memory:');
$kDb = new HookedSettings($kInner);
$karl = (new SelfDataGuard($kInner, $blindKey, keeper: $kKa))->register('karl', 'motdepasse-karl-01');
$printKa = KeeperSeal::fingerprint($kKa->publicKeys());
$printKc = KeeperSeal::fingerprint($kKc->publicKeys());
$dryInner = new SqliteAdapter('sqlite::memory:');
$dryDb    = new HookedSettings($dryInner);
$dryKarl  = (new SelfDataGuard($dryInner, $blindKey, keeper: $kKa))->register('karl', 'motdepasse-karl-01');
$dryDg    = new SelfDataGuard($dryDb, $blindKey, keeper: $kKa, formerKeepers: [new BenchKeeper()]);
$dryDb->reads = [];
$dryDg->setEscrowFields($dryKarl, $admin['publicKey'], ['contact' => 'karl@example.org']);
$lastKeeperRead = $dryDb->reads[SelfDataGuard::SETTING_KEEPER] ?? 0;
$dgK = new SelfDataGuard($kDb, $blindKey, keeper: $kKa, formerKeepers: [$kKc]);
$kDb->reads = [];
$kDb->onRead[SelfDataGuard::SETTING_KEEPER] = [$lastKeeperRead, static fn () => $kInner->replaceSetting(SelfDataGuard::SETTING_KEEPER, $printKa, $printKc)];
try {
    $dgK->setEscrowFields($karl, $admin['publicKey'], ['contact' => 'karl@example.org']);
    ko('a deposit retired the escrow at a keeper that became the database\'s keeper meanwhile');
} catch (EscrowKeeperException $e) {
    !isset($kKc->retired[$hexOf($karl)])
        ? ok('a keeper given as former that becomes the database\'s keeper during the deposit: refused before any retirement')
        : ko('the deposit retired the escrow at the new keeper before refusing', $e->getMessage());
}

$kLa = new BenchKeeper();
$kLc = new BenchKeeper();
$kLd = new BenchKeeper();
$lInner = new SqliteAdapter('sqlite::memory:');
$lise = (new SelfDataGuard($lInner, $blindKey, keeper: $kLa))->register('lise', 'motdepasse-lise-01');
(new SelfDataGuard($lInner, $blindKey, keeper: $kLa, formerKeepers: [$kLc]))->setEscrowFields($lise, $admin['publicKey'], ['contact' => 'lise@example.org']);
$printLa = KeeperSeal::fingerprint($kLa->publicKeys());
$printLc = KeeperSeal::fingerprint($kLc->publicKeys());
$lInner->replaceSetting(SelfDataGuard::SETTING_FORMER_KEEPERS, json_encode([$printLc]), json_encode([$printLc, $printLa]));
$lInner->replaceSetting(SelfDataGuard::SETTING_KEEPER, $printLa, $printLc);
$lFormers = static fn (): string => (string) $lInner->loadSetting(SelfDataGuard::SETTING_FORMER_KEEPERS);
$lHeld = $lFormers();
try {
    SelfDataGuard::forgetFormerKeeper($lInner, $printLc);
    ko('forgetFormerKeeper() forgot the database\'s keeper');
} catch (InvalidArgumentException) {
    $lFormers() === $lHeld && $lInner->loadSetting(SelfDataGuard::SETTING_FORGOTTEN_KEEPERS) === null
        ? ok('after the race, forgetFormerKeeper() on the database\'s keeper is refused, nothing changed')
        : ko('forgetFormerKeeper() refused the database\'s keeper, but changed a record');
}
try {
    SelfDataGuard::changeKeeper($lInner, $kLc, $kLd);
    $dgL = new SelfDataGuard($lInner, $blindKey, keeper: $kLd, formerKeepers: [$kLa, $kLc]);
    $dgL->setEscrowFields($lise, $admin['publicKey'], ['contact' => 'lise-2@example.org']);
    $reads = $dgL->escrowReads($lise);
    $reads['established'] && $reads['keeper'] === KeeperSeal::fingerprint($kLd->publicKeys())
        ? ok('after the race, changeKeeper() to a keeper the database never had: the deposit goes through, the escrow re-sealed to it')
        : ko('after the race and a change of keeper, the escrow is not re-sealed to the new keeper', json_encode($reads));
} catch (Throwable $e) {
    ko('after the race, changeKeeper() to a keeper the database never had does not get the deposits through', get_class($e) . ': ' . $e->getMessage());
}

// -----------------------------------------------------------------------------

section('A deposit landing between the reads of the envelope and of the fields');

final class DepositBetweenReads extends DelegatingStorage
{
    /** @var (callable(): void)|null */
    public $between = null;

    public function loadEscrowFields(string $u, array $n = []): array
    {
        if ($this->between !== null) {
            $act = $this->between;
            $this->between = null;
            $act();
        }
        return $this->inner->loadEscrowFields($u, $n);
    }
}
$jules = $dg->register('jules', 'motdepasse-jules-01');
$dg->setEscrowFields($jules, $admin['publicKey'], ['contact' => 'jules-secours@example.org']);
$racing = new DepositBetweenReads($storage);
$racingDg = new SelfDataGuard($racing, $blindKey, keeper: $keeper);
$depositMeanwhile = static fn (array $f) => static fn () => $dg->setEscrowFields($jules, $admin['publicKey'], $f);
try {
    $racing->between = $depositMeanwhile(['indice' => 'jules-1']);
    $got = $racingDg->getEscrowFieldsAsUser($jules);
    ksort($got);
    $got === ['contact' => 'jules-secours@example.org', 'indice' => 'jules-1']
        ? ok('getEscrowFieldsAsUser(): read again under one revision — the fields of after the deposit')
        : ko('getEscrowFieldsAsUser() returned fields of two revisions', json_encode($got));
    $racing->between = $depositMeanwhile(['indice' => 'jules-2']);
    $racingDg->setEscrowFields($jules, $admin['publicKey'], ['autre' => 'jules-3']);
    $got = $dg->getEscrowFieldsAsUser($jules);
    ksort($got);
    $got === ['autre' => 'jules-3', 'contact' => 'jules-secours@example.org', 'indice' => 'jules-2']
        ? ok('a deposit read again under one revision keeps the field the other deposit wrote')
        : ko('a deposit lost a field written meanwhile', json_encode($got));
    $racing->between = $depositMeanwhile(['indice' => 'jules-4']);
    $read = benchCeremony($racing, keeper: $keeper)->readEscrow('jules', 'LIT-J1', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
    ($read['indice'] ?? null) === 'jules-4'
        ? ok('the ceremony reads the envelope and the fields under one revision before the keeper')
        : ko('the ceremony read fields of another revision', json_encode($read));
} catch (Throwable $e) {
    ko('a deposit landing between the reads of the envelope and of the fields broke the read', $e->getMessage());
}
$lou = $dg->register('lou', 'motdepasse-lou-01');
$racing->between = static fn () => $dg->setEscrowFields($lou, $admin['publicKey'], ['contact' => 'lou@example.org']);
$reads = $racingDg->escrowReads($lou);
$reads['reason'] !== 'escrow-replaced'
    ? ok('a first deposit landing between the reads of the row and of the fields: the view reads the row again, no false alarm')
    : ko('a first deposit landing between the reads of the row and of the fields: the view says "escrow-replaced"', json_encode($reads));
$lia = $dg->register('lia', 'motdepasse-lia-01');
$racing->between = static fn () => $dg->setEscrowFields($lia, $admin['publicKey'], ['contact' => 'lia@example.org']);
try {
    $got = $racingDg->getEscrowFieldsAsUser($lia);
    $got === ['contact' => 'lia@example.org']
        ? ok('a first deposit landing between the reads of the row and of the fields: the holder\'s read reads the row again and opens them')
        : ko('a first deposit landing between the reads of the row and of the fields: the holder\'s read returned other fields', json_encode($got));
} catch (EscrowEnvelopeMismatchException) {
    ko('a first deposit landing between the reads of the row and of the fields: the holder\'s read says the row was deleted');
}

/** Runs $before once, before the envelope is written: the row changes between a deposit's read and its write. */
final class ChangeBeforeWrite extends DelegatingStorage
{
    /** @var (callable(): void)|null */
    public $before = null;

    public function rotateEscrow(VaultGeneration $generation, EscrowRecord $record, array $fields): void
    {
        if ($this->before !== null) {
            $act = $this->before;
            $this->before = null;
            $act();
        }
        $this->inner->rotateEscrow($generation, $record, $fields);
    }
}
$pdoW = new PDO('sqlite::memory:');
$wInner = new SqliteAdapter($pdoW);
$wDb = new ChangeBeforeWrite($wInner);
$dgW = new SelfDataGuard($wDb, $blindKey, keeper: $keeper);
$wren = $dgW->register('wren', 'motdepasse-wren-01');
$dgW->setEscrowFields($wren, $admin['publicKey'], ['contact' => 'wren@example.org']);
$wDb->before = static fn () => $pdoW->exec("UPDATE selfdataguard_escrow SET revision = 'abc' WHERE user_id = 'wren'");
try {
    $dgW->setEscrowFields($wren, $admin['publicKey'], ['contact' => 'wren-2@example.org']);
    ko('a row made unreadable between a deposit\'s read and its write: the deposit went through');
} catch (EscrowEnvelopeMismatchException) {
    ok('a row made unreadable between a deposit\'s read and its write: EscrowEnvelopeMismatchException, nothing written');
} catch (Throwable $e) {
    ko('a row made unreadable between a deposit\'s read and its write: the deposit raised a bare error', get_class($e));
}
final class DepositAtEveryRead extends DelegatingStorage
{
    /** @var (callable(): void)|null */
    public $between = null;

    public function loadEscrowFields(string $u, array $n = []): array
    {
        if ($this->between !== null) {
            ($this->between)();
        }
        return $this->inner->loadEscrowFields($u, $n);
    }
}
$stormy = new DepositAtEveryRead($storage);
$stormy->between = $depositMeanwhile(['indice' => 'jules-orage']);
$stormLog = sys_get_temp_dir() . '/sdg-keeper-storm-' . bin2hex(random_bytes(4)) . '.log';
try {
    benchCeremony($stormy, logPath: $stormLog, keeper: $keeper)->readEscrow('jules', 'LIT-J2', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
    ko('a ceremony under deposits that never stop read anyway');
} catch (StaleEscrowException) {
    $last = logEntries($stormLog);
    (end($last)['event']['reason'] ?? null) === 'stale-escrow'
        ? ok('deposits that keep landing: the ceremony gives up after its attempts, and the log says stale-escrow — not open-failed')
        : ko('the log does not name the race', json_encode(end($last)));
}
$stormy->between = null;
@unlink($stormLog);

// -----------------------------------------------------------------------------

section('The file keeper of the benches, under processes that call it at once');

$raceWork = sys_get_temp_dir() . '/sdg-keeper-race-' . bin2hex(random_bytes(4));
mkdir($raceWork);
$raceKeeper = new FileBenchKeeper("{$raceWork}/keeper.json");
$raceSecret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
$raceId     = KeeperSeal::escrowIdFor(sodium_crypto_sign_publickey_from_secretkey($raceSecret));
$raceOuter  = KeeperSeal::outerOf(KeeperSeal::wrap($raceId, 'race', sodium_crypto_sign_publickey_from_secretkey($raceSecret), random_bytes(80), $raceKeeper->publicKeys()['box']));
file_put_contents("{$raceWork}/child.php", sprintf(
    "<?php\nrequire %s;\nrequire_once %s;\n[, \$file, \$id, \$outer, \$proc] = \$argv;\n\$k = new FileBenchKeeper(\$file);\n"
    . "for (\$i = 0; \$i < 25; \$i++) {\n    \$k->announce(hex2bin(\$id), hex2bin(\$outer), 'race', \"R-{\$proc}-{\$i}\", 0, 'h');\n}\n",
    var_export(__DIR__ . '/../src/autoload.php', true),
    var_export(__DIR__ . '/keeper_fixture.php', true)
));
$children = [];
foreach (range(1, 8) as $proc) {
    $children[] = proc_open([PHP_BINARY, "{$raceWork}/child.php", "{$raceWork}/keeper.json", bin2hex($raceId), bin2hex($raceOuter), (string) $proc], [], $pipes);
}
$raceRc = array_map('proc_close', $children);
$raced = count((new FileBenchKeeper("{$raceWork}/keeper.json"))->pending);
$raceRc === array_fill(0, 8, 0) && $raced === 200
    ? ok('eight processes announcing at once on one file keeper: 200 announcements, none lost')
    : ko('the file keeper lost announcements made at once', "{$raced} of 200, exit codes " . implode(',', $raceRc));
array_map('unlink', glob("{$raceWork}/*") ?: []);
rmdir($raceWork);

// -----------------------------------------------------------------------------

section('The keeper conformance suite — the bench keeper passes, each broken one fails on its clause');

$failedCases = static fn (array $results): array => array_column(
    array_filter($results, static fn (array $r): bool => $r['outcome'] === 'fail'), 'detail', 'case'
);
$factoryOf = static fn (string $class): callable => static fn (int $delay, callable $clock): BenchKeeper => new $class($delay, $clock);
$failedOfBench = $failedCases(KeeperConformance::run($factoryOf(BenchKeeper::class)));
$failedOfBench === []
    ? ok('BenchKeeper: every case passes')
    : ko('the bench keeper fails the suite', json_encode($failedOfBench));
foreach ([
    BadKeysKeeper::class            => 'public-keys',
    OpensAnyBoxKeeper::class                  => 'announce-reads-the-box',
    MovingDeadlineKeeper::class               => 'announce-is-idempotent',
    RestartingKeeper::class                   => 'announcement-holds-the-case',
    ForeignKeyKeeper::class                   => 'announce-checks-the-objection-key',
    KeylessAfterKeyedKeeper::class            => 'keyless-box-refused-once-keyed',
    WrongInnerKeeper::class                   => 'release-hands-back-the-inner-box',
    UnrecordedReleaseKeeper::class            => 'release-is-recorded',
    UnannouncedReleaseKeeper::class           => 'release-only-announced',
    HeadJudgeKeeper::class                    => 'release-takes-any-head',
    EarlyReleaseKeeper::class                 => 'release-only-when-due',
    LapsesAtOnceKeeper::class                 => 'release-within-the-lapse',
    ConsumingReleaseKeeper::class             => 'release-repeatable-within-the-window',
    NeverLapsesKeeper::class                  => 'announcement-lapses',
    StaleAttestationKeeper::class             => 'attestation-fresh-and-scoped',
    ObjectionBlindKeeper::class               => 'objection-under-the-escrow-key',
    ObjectionWithoutAnnouncementKeeper::class => 'objection-needs-an-announcement',
    ForgetsObjectionKeeper::class             => 'objection-survives-re-announce',
    ForgetsRetirementKeeper::class            => 'retire-is-final',
    UnsaidRetirementKeeper::class             => 'retire-is-final',
    ForgetsHistoryOnRetireKeeper::class       => 'retire-is-final',
    AnyEscrowReleaseKeeper::class             => 'release-refuses-another-escrow',
    UnboundRetirementKeeper::class            => 'retire-names-the-keeper',
    RetiresOnceKeeper::class                  => 'retire-is-idempotent',
    NoObjectionAfterReleaseKeeper::class      => 'objection-after-a-release',
] as $class => $case) {
    $failed = $failedCases(KeeperConformance::run($factoryOf($class)));
    isset($failed[$case])
        ? ok("{$class} fails {$case}")
        : ko("{$class} passed a case it breaks", $case);
}
$keeperWork = sys_get_temp_dir() . '/sdg-keeper-conf-' . bin2hex(random_bytes(4));
mkdir($keeperWork);
$keeperFileOf = static function (string $class) use ($keeperWork): string {
    $file = "{$keeperWork}/{$class}.php";
    file_put_contents($file, sprintf(
        "<?php\nrequire %s;\nrequire_once %s;\nreturn fn (int \$delay, callable \$clock) => new %s(\$delay, \$clock);\n",
        var_export(__DIR__ . '/../src/autoload.php', true),
        var_export(__DIR__ . '/keeper_doubles.php', true),
        $class
    ));
    return $file;
};
$runBin = static function (string $file): array {
    $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/keeper-conformance.php', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($process), $out];
};
[$rc, $out] = $runBin($keeperFileOf(BenchKeeper::class));
$rc === 0 && str_contains($out, 'Conforme.')
    ? ok('bin/keeper-conformance.php: 0 on the bench keeper')
    : ko('the bench keeper was not reported conformant', "rc={$rc} {$out}");
[$rc, $out] = $runBin($keeperFileOf(EarlyReleaseKeeper::class));
$rc === 1 && str_contains($out, '❌ release-only-when-due')
    ? ok('1 on a broken keeper, naming the case it fails')
    : ko('a broken keeper was not reported', "rc={$rc} {$out}");
[$rc] = $runBin("{$keeperWork}/absent.php");
$rc === 3 ? ok('3 on a file that does not return a factory') : ko('a missing keeper file did not exit 3', "rc={$rc}");
file_put_contents("{$keeperWork}/instance.php", sprintf(
    "<?php\nrequire %s;\nrequire_once %s;\nreturn new BenchKeeper();\n",
    var_export(__DIR__ . '/../src/autoload.php', true),
    var_export(__DIR__ . '/keeper_fixture.php', true)
));
[$rc] = $runBin("{$keeperWork}/instance.php");
$rc === 3 ? ok('3 on a file that returns a keeper instead of a factory') : ko('a keeper instance was taken for a factory', "rc={$rc}");
array_map('unlink', glob("{$keeperWork}/*") ?: []);
rmdir($keeperWork);

sodium_memzero($adminSk);
@unlink($noKeeperLog);
@unlink($slowLog);

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Keeper Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
