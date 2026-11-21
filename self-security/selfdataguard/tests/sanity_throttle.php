<?php

declare(strict_types=1);

/**
 * Sanity test — the throttle at the doors where a secret from outside costs an
 * Argon2id.
 *
 * Run:  php tests/sanity_throttle.php
 * Exit: 0 on success, non-zero if any check failed.
 *
 * The façade requires a throttle. At each door it admits the attempt before
 * reading anything, then settles it once with what the secret did: opened,
 * refused, or nothing told. An account that does not exist costs the same
 * derivation as a wrong secret, so that neither the refusal nor the time says
 * whether it exists.
 */

require __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/pepper_fixture.php';
require_once __DIR__ . '/storage_doubles.php';

use Pierroons\SelfDataGuard\Pepper\PepperUnavailableException;
use Pierroons\SelfDataGuard\Pepper\RemotePepperSource;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Throttle\Door;
use Pierroons\SelfDataGuard\Throttle\NoThrottle;
use Pierroons\SelfDataGuard\Throttle\Outcome;
use Pierroons\SelfDataGuard\Throttle\Throttle;
use Pierroons\SelfDataGuard\Throttle\ThrottledException;
use Pierroons\SelfDataGuard\Vault\ArchivedVault;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\MissingEnvelopeException;
use Pierroons\SelfDataGuard\Vault\PepperForms;
use Pierroons\SelfDataGuard\Vault\ProfileSeal;
use Pierroons\SelfDataGuard\Vault\StaleVaultException;
use Pierroons\SelfDataGuard\Vault\UnlayeredLockException;
use Pierroons\SelfDataGuard\Vault\UserVault;
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

/** What $call throws, or null. */
function caught(callable $call): ?Throwable
{
    try {
        $call();
        return null;
    } catch (Throwable $e) {
        return $e;
    }
}

/** A throttle that writes down every call, and refuses when told to. */
final class RecordingThrottle implements Throttle
{
    /** @var list<string> */
    public array $calls = [];
    public ?ThrottledException $refusal = null;

    public function admit(Door $door, string $userId): void
    {
        $this->calls[] = "admit {$door->value} {$userId}";
        if ($this->refusal !== null) {
            throw $this->refusal;
        }
    }

    public function settle(Door $door, string $userId, Outcome $outcome): void
    {
        $this->calls[] = "settle {$door->value} {$userId} {$outcome->name}";
    }

    /** The calls since the last take, then forgets them. */
    public function take(): string
    {
        $calls = implode(' | ', $this->calls);
        $this->calls = [];
        return $calls;
    }
}

/** Counts every read the façade makes; reads that come before the throttle are what it catches. */
final class ReadCountingStorage extends DelegatingStorage
{
    public int $reads = 0;
    public bool $tamperProfile = false;
    public bool $dropWrites = false;

    public function loadVault(string $u): VaultRecord
    {
        $this->reads++;
        $record = parent::loadVault($u);
        return $this->tamperProfile ? recordWith($record, opslimit: $record->kdfOpslimit + 1) : $record;
    }
    public function findVault(string $u): ?VaultRecord { $this->reads++; return parent::findVault($u); }
    public function vaultExists(string $u): bool { $this->reads++; return parent::vaultExists($u); }
    public function loadArchive(string $u, string $id): ?ArchivedVault { $this->reads++; return parent::loadArchive($u, $id); }
    public function listArchives(string $u): array { $this->reads++; return parent::listArchives($u); }
    public function loadSetting(string $n): ?string { $this->reads++; return parent::loadSetting($n); }
    public function hasVaults(): bool { $this->reads++; return parent::hasVaults(); }
    public function frozenState(string $u): ?array { $this->reads++; return parent::frozenState($u); }
    public function updateVault(VaultRecord $r): int { return $this->dropWrites ? 0 : parent::updateVault($r); }
}

/** A pepper on another machine that does not answer. */
final class DownPepper implements RemotePepperSource
{
    public function mac(string $usage, string $data): string
    {
        throw new PepperUnavailableException('The pepper machine does not answer');
    }
}

/** The median duration of $call, in milliseconds, over $runs runs; what it throws is swallowed. */
function medianMs(callable $call, int $runs = 5): float
{
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $t = hrtime(true);
        try {
            $call();
        } catch (Throwable) {
        }
        $times[] = (hrtime(true) - $t) / 1e6;
    }
    sort($times);
    return $times[intdiv($runs, 2)];
}

$work = sys_get_temp_dir() . '/sdg-throttle-' . bin2hex(random_bytes(4));
mkdir($work, 0o700);
register_shutdown_function(static function () use ($work): void {
    array_map('unlink', glob("{$work}/*") ?: []);
    @rmdir($work);
});

const PWD    = 'throttle-password-01';
const PHRASE = 'cheval agrafe batterie correct moulin ivoire';

$key      = random_bytes(32);
$mot      = hash('sha256', 'throttle-memorized');
$store    = new ReadCountingStorage(new SqliteAdapter('sqlite:' . "{$work}/throttle.sqlite"));
$throttle = new RecordingThrottle();
$dg = new SelfDataGuard($store, $key, benchPepper(), PepperForms::LayeredOnly, $throttle);
$dg->register('alice', PWD, $mot, PHRASE);
$dg->register('bob', PWD);
$throttle->take();

// -----------------------------------------------------------------------------

section('The façade requires a throttle, and "none" is written by its name');

$param = null;
foreach ((new ReflectionMethod(SelfDataGuard::class, '__construct'))->getParameters() as $p) {
    if ($p->getName() === 'throttle') {
        $param = $p;
    }
}
$param !== null && !$param->isOptional() && (string) $param->getType() === Throttle::class
    ? ok('the constructor takes a required Throttle $throttle, with no default')
    : ko('the constructor does not require a throttle', $param === null ? 'no such parameter' : (string) $param->getType());
new NoThrottle() instanceof Throttle && !is_a(ThrottledException::class, WrongSecretException::class, true)
    ? ok('NoThrottle is a Throttle; ThrottledException is not a WrongSecretException — a refusal of the brake is not a wrong secret')
    : ko('NoThrottle or ThrottledException is not what the contract says');

// -----------------------------------------------------------------------------

section('Admitted before anything is read: a refusal reads nothing, derives nothing, settles nothing');

$session = $dg->loginWithPassword('alice', PWD);
$archive = $dg->reEnroll($session->generation(), 'throttle-password-02', $mot, PHRASE)['archiveId'];
$current = $dg->loginWithPassword('alice', 'throttle-password-02');
$throttle->take();

$doors = [
    'loginWithPassword'  => [Door::LoginPassword,  fn () => $dg->loginWithPassword('alice', 'wrong-password-01')],
    'loginWithMemorized' => [Door::LoginMemorized, fn () => $dg->loginWithMemorized('alice', $mot)],
    'recover'            => [Door::Recover,        fn () => $dg->recover('alice', Lock::Passphrase, PHRASE, 'throttle-password-03')],
    'openArchive'        => [Door::OpenArchive,    fn () => $dg->openArchive($current, $archive, Lock::Password, PWD)],
    'setLockWhileFrozen' => [Door::SetLockWhileFrozen, fn () => $dg->setLockWhileFrozen('alice', $mot, Lock::Password, 'throttle-password-09')],
];
foreach ($doors as $name => [$door, $call]) {
    $throttle->refusal = new ThrottledException('slow down');
    $store->reads = 0;
    $t = hrtime(true);
    $e = caught($call);
    $ms = (hrtime(true) - $t) / 1e6;
    $calls = $throttle->take();
    $e === $throttle->refusal && $store->reads === 0 && $calls === "admit {$door->value} alice" && $ms < 10
        ? ok("{$name}: the host's ThrottledException comes back as thrown, after admit({$door->value}, alice) alone — 0 reads, " . sprintf('%.2f ms', $ms))
        : ko("{$name}: the refusal did not come before the read", sprintf('%s, %d reads, [%s], %.2f ms', $e === null ? 'nothing thrown' : get_class($e), $store->reads, $calls, $ms));
}
$throttle->refusal = null;

// -----------------------------------------------------------------------------

section('Settled once, with what the secret did');

/** Runs $call, then checks the exception class and the calls the throttle saw. */
$settles = function (string $label, callable $call, ?string $expected, string $calls) use ($throttle): void {
    $e = caught($call);
    $seen = $throttle->take();
    ($expected === null ? $e === null : $e !== null && get_class($e) === $expected) && $seen === $calls
        ? ok("{$label}: [{$seen}]")
        : ko($label, sprintf('%s, [%s]', $e === null ? 'nothing thrown' : get_class($e) . ': ' . $e->getMessage(), $seen));
};

$settles('login by the right password', fn () => $dg->loginWithPassword('bob', PWD), null,
    'admit login-password bob | settle login-password bob Opened');
$settles('login by a wrong password', fn () => $dg->loginWithPassword('bob', 'wrong-password-01'), WrongSecretException::class,
    'admit login-password bob | settle login-password bob Refused');
$settles('login by the right memorized secret', fn () => $dg->loginWithMemorized('alice', $mot), null,
    'admit login-memorized alice | settle login-memorized alice Opened');
$settles('login by a memorized secret on a vault that holds no such lock', fn () => $dg->loginWithMemorized('bob', $mot), MissingEnvelopeException::class,
    'admit login-memorized bob | settle login-memorized bob Refused');
$settles('login on an account that does not exist: VaultNotFoundException kept, settled as a refusal',
    fn () => $dg->loginWithPassword('nobody', PWD), VaultNotFoundException::class,
    'admit login-password nobody | settle login-password nobody Refused');
$settles('an empty secret on an account that exists', fn () => $dg->loginWithPassword('bob', ''), InvalidArgumentException::class,
    'admit login-password bob | settle login-password bob Unavailable');
$settles('an empty secret on an account that does not exist: the same answer', fn () => $dg->loginWithPassword('nobody', ''), InvalidArgumentException::class,
    'admit login-password nobody | settle login-password nobody Unavailable');

$store->tamperProfile = true;
$settles('a row that no longer matches its seal: nothing told of the secret', fn () => $dg->loginWithPassword('bob', PWD), VaultTamperedException::class,
    'admit login-password bob | settle login-password bob Unavailable');
$store->tamperProfile = false;

// A lock left bare, under a façade that declared the upgrade over.
$bareStore = new SqliteAdapter('sqlite:' . "{$work}/bare.sqlite");
$bare = (new UserVault())->register('carol', PWD, $mot)['record'];
$bareStore->saveVault($bare->withProfileSeal(ProfileSeal::of(ProfileSeal::key($key), $bare, $bare->kdfOpslimit, $bare->kdfMemlimit)));
$bareStore->claimSetting(SelfDataGuard::SETTING_BLIND_KEY, \Pierroons\SelfDataGuard\Fields\BlindIndex::keyCheck($key));
benchPepperRecorded($bareStore);
$bareDg = new SelfDataGuard($bareStore, $key, benchPepper(), PepperForms::LayeredOnly, $throttle);
$settles('a bare lock under LayeredOnly, given the right password: refused as a wrong secret',
    fn () => $bareDg->loginWithPassword('carol', PWD), UnlayeredLockException::class,
    'admit login-password carol | settle login-password carol Refused');

$downDg = new SelfDataGuard($store, $key, new DownPepper(), PepperForms::LayeredOnly, $throttle);
$settles('the pepper out of reach: nothing told of the secret', fn () => $downDg->loginWithPassword('bob', PWD), PepperUnavailableException::class,
    'admit login-password bob | settle login-password bob Unavailable');

$settles('recover by a wrong passphrase', fn () => $dg->recover('alice', Lock::Passphrase, 'wrong passphrase of six words here', 'throttle-password-03'),
    WrongSecretException::class, 'admit recover alice | settle recover alice Refused');
$store->dropWrites = true;
$settles('recover whose write is lost after the secret opened: settled Opened — the brake hears of the secret, not of the write',
    fn () => $dg->recover('alice', Lock::Passphrase, PHRASE, 'throttle-password-03'), RuntimeException::class,
    'admit recover alice | settle recover alice Opened');
$store->dropWrites = false;
$settles('recover by the right passphrase', fn () => $dg->recover('alice', Lock::Passphrase, PHRASE, 'throttle-password-03'), null,
    'admit recover alice | settle recover alice Opened');

$settles('setLockWhileFrozen by a wrong memorized secret', fn () => $dg->setLockWhileFrozen('alice', hash('sha256', 'wrong'), Lock::Password, 'throttle-password-09'),
    WrongSecretException::class, 'admit set-lock-while-frozen alice | settle set-lock-while-frozen alice Refused');
$settles('setLockWhileFrozen by the right one on a vault not frozen: the secret held, the gesture is refused after',
    fn () => $dg->setLockWhileFrozen('alice', $mot, Lock::Password, 'throttle-password-09'), LogicException::class,
    'admit set-lock-while-frozen alice | settle set-lock-while-frozen alice Opened');

$current = $dg->loginWithPassword('alice', 'throttle-password-03');
$throttle->take();
$settles('openArchive by the right old password', fn () => $dg->openArchive($current, $archive, Lock::Password, PWD), null,
    'admit open-archive alice | settle open-archive alice Opened');
$settles('openArchive by a wrong old password', fn () => $dg->openArchive($current, $archive, Lock::Password, 'wrong-password-01'),
    WrongSecretException::class, 'admit open-archive alice | settle open-archive alice Refused');
$settles('openArchive of an archive that does not exist', fn () => $dg->openArchive($current, 'no-such-archive', Lock::Password, PWD),
    VaultNotFoundException::class, 'admit open-archive alice | settle open-archive alice Refused');
$stale = $current;
$dg->reEnroll($dg->generationOf('alice'), 'throttle-password-04', $mot, PHRASE);
$throttle->take();
$settles('openArchive from a session on a vault no longer live: nothing told of the secret',
    fn () => $dg->openArchive($stale, $archive, Lock::Password, PWD), StaleVaultException::class,
    'admit open-archive alice | settle open-archive alice Unavailable');

// -----------------------------------------------------------------------------

section('The time does not tell whether the account exists');

/** $unknown within [½, 3×] of $known: one Argon2id each, not none. */
$alike = function (string $label, callable $known, callable $unknown): void {
    $knownMs   = medianMs($known);
    $unknownMs = medianMs($unknown);
    $unknownMs >= $knownMs / 2 && $unknownMs <= $knownMs * 3
        ? ok(sprintf('%s: %.1f ms against %.1f ms (medians of 5)', $label, $unknownMs, $knownMs))
        : ko($label, sprintf('%.2f ms against %.2f ms', $unknownMs, $knownMs));
};

$alike('loginWithPassword, unknown account against a wrong password',
    fn () => $dg->loginWithPassword('bob', 'wrong-password-01'), fn () => $dg->loginWithPassword('nobody', 'wrong-password-01'));
$alike('loginWithMemorized, unknown account against a wrong memorized secret',
    fn () => $dg->loginWithMemorized('alice', hash('sha256', 'wrong')), fn () => $dg->loginWithMemorized('nobody', hash('sha256', 'wrong')));
$alike('recover by the passphrase, unknown account against a wrong passphrase',
    fn () => $dg->recover('alice', Lock::Passphrase, 'wrong passphrase of six words here', 'throttle-password-05'),
    fn () => $dg->recover('nobody', Lock::Passphrase, 'wrong passphrase of six words here', 'throttle-password-05'));
$alike('a bare lock under LayeredOnly against a wrong password',
    fn () => $dg->loginWithPassword('bob', 'wrong-password-01'), fn () => $bareDg->loginWithPassword('carol', PWD));
$throttle->take();

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Throttle Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
