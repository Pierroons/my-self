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

use Pierroons\SelfDataGuard\Escrow\EscrowAnnouncedException;
use Pierroons\SelfDataGuard\Escrow\EscrowKeeperException;
use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\Escrow\EscrowVault;
use Pierroons\SelfDataGuard\Escrow\KeeperConformance;
use Pierroons\SelfDataGuard\Escrow\KeeperSeal;
use Pierroons\SelfDataGuard\Escrow\NoKeeper;
use Pierroons\SelfDataGuard\Escrow\UnlockedEscrow;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Vault\Lock;

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
($intent[0]['event']['keeper'] ?? null) === BenchKeeper::class && ($intent[0]['seq'] ?? null) === $released[0]['seq']
    ? ok('the intention names the keeper, and the keeper holds the head of that intention')
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
$forged = sodium_crypto_sign_detached(KeeperSeal::objectionMessage($carolEscrowId, 'LIT-8'), sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
try {
    $slow->object($carolEscrowId, 'LIT-8', $forged);
    ko('the keeper took an objection signed by a key the escrow does not carry');
} catch (RuntimeException $e) {
    str_contains($e->getMessage(), 'not signed by the key the escrow carries')
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
try {
    $slowCeremony->readEscrow('carol', 'LIT-9', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
} catch (EscrowAnnouncedException) {
}
$slowDg->setEscrowFields($carol, $admin['publicKey'], ['contact' => 'carol-secours@example.org']);
$slow->pending[bin2hex($carolEscrowId) . '|LIT-9']['due'] = time() - 1;
try {
    $slowCeremony->readEscrow('carol', 'LIT-9', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
    ko('a read announced before a deposit went through once due, on the box the deposit sealed');
} catch (EscrowAnnouncedException $e) {
    $e->due > time()
        ? ok('a deposit during an announcement starts the delay again: the read is announced anew, on the new box')
        : ko('announced again, but already due');
} catch (Throwable $e) {
    ko('a read announced before a deposit was not announced anew on the new box', $e->getMessage());
}
try {
    $slowDg->objectToEscrowRead($carol, 'LIT-9');
    $slow->pending[bin2hex($carolEscrowId) . '|LIT-9']['objected']
        ? ok('the holder objects to it under the key the new box carries')
        : ko('the objection to the read announced anew is not recorded');
} catch (Throwable $e) {
    ko('the holder cannot object to a read announced anew', $e->getMessage());
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
$slowCeremony->readArchiveEscrow('carol', $l3['archiveId'], 'LIT-7', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey'])
    === ['contact' => 'carol-secours@example.org']
    ? ok('once due, the escrow of the archive is read through the keeper')
    : ko('the archive escrow did not read through the keeper');
$archive = $slowDg->openArchive($l3['unlocked'], $l3['archiveId'], Lock::Password, 'motdepasse-carol-01');
$archiveReads = $slowDg->archiveEscrowReads($archive);
$archiveReads['established'] && in_array('LIT-7', array_column($archiveReads['reads'], 'case'), true)
    ? ok('the holder who reopens the archive sees its reads, LIT-7 among them')
    : ko('archiveEscrowReads() did not show the archive read', json_encode($archiveReads));

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
$withKeeper->sealEscrowToKeeper($dave, $admin['publicKey']) && EscrowVault::isKeeperSealed($plainDb->loadEscrow('dave'))
    && !$withKeeper->sealEscrowToKeeper($dave, $admin['publicKey'])
    ? ok('sealEscrowToKeeper() seals it once, then has nothing to do')
    : ko('sealEscrowToKeeper() did not seal the escrow to the keeper, or sealed it twice');
$withKeeper->getEscrowFieldsAsUser($dave) === ['contact' => 'dave-secours@example.org']
    ? ok('its fields come through')
    : ko('sealing to the keeper lost the escrow fields');

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
    OpensAnyBoxKeeper::class        => 'announce-reads-the-box',
    UnrecordedReleaseKeeper::class  => 'release-is-recorded',
    UnannouncedReleaseKeeper::class => 'release-only-announced',
    HeadJudgeKeeper::class          => 'release-takes-any-head',
    EarlyReleaseKeeper::class       => 'release-only-when-due',
    AnyBoxReleaseKeeper::class      => 'release-needs-the-announced-box',
    FirstBoxKeeper::class           => 'new-box-restarts-the-delay',
    NeverLapsesKeeper::class        => 'announcement-lapses',
    StaleAttestationKeeper::class   => 'attestation-fresh-and-scoped',
    ObjectionBlindKeeper::class     => 'objection-under-the-box-key',
    ForgetsObjectionKeeper::class   => 'objection-survives-re-announce',
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
