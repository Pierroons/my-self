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
use Pierroons\SelfDataGuard\Escrow\EscrowKeeperException;
use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\Escrow\EscrowVault;
use Pierroons\SelfDataGuard\Escrow\KeeperConformance;
use Pierroons\SelfDataGuard\Escrow\KeeperSeal;
use Pierroons\SelfDataGuard\Escrow\NoKeeper;
use Pierroons\SelfDataGuard\Escrow\StaleEscrowException;
use Pierroons\SelfDataGuard\Escrow\UnlockedEscrow;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\VaultFrozenException;

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
!$reads['established'] && $reads['reason'] === 'keeper-changed'
    ? ok('a former keeper the database did not record — changed by hand, not by changeKeeper() — is not asked')
    : ko('a former keeper the database never recorded answered for the holder', json_encode($reads));
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
$viewDb->replaceSetting(SelfDataGuard::SETTING_FORMER_KEEPERS, '["unterminated', $formerHeld);
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
