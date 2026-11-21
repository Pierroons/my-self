<?php

declare(strict_types=1);

/**
 * Sanity test — the pepper on the locks, and the upgrade from 0.9.x.
 *
 * Run:  php tests/sanity_upgrade.php
 * Exit: 0 on success, non-zero if any check failed.
 *
 * A new installation seals wrap_pwd and wrap_recov with the pepper in their
 * Argon2id input. A database written by 0.9.x is laid over by the operator's
 * pass, with no secret of the users in hand; the end of the upgrade refuses a
 * lock without the pepper; the inverse pass gives back what 0.9.x opens. The
 * locks without the pepper are sealed here by UserVault without a pepper,
 * which seals them as 0.9.x did.
 */

require __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/pepper_fixture.php';

use Pierroons\SelfDataGuard\Fields\BlindIndex;
use Pierroons\SelfDataGuard\Pepper\LocalPepperFile;
use Pierroons\SelfDataGuard\Pepper\Pepper;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\PepperForms;
use Pierroons\SelfDataGuard\Vault\ProfileSeal;
use Pierroons\SelfDataGuard\Vault\UnlayeredLockException;
use Pierroons\SelfDataGuard\Vault\UserVault;
use Pierroons\SelfDataGuard\Vault\VaultRecord;
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

/** The class of what $call throws, or null. */
function thrown(callable $call): ?string
{
    try {
        $call();
        return null;
    } catch (Throwable $e) {
        return get_class($e);
    }
}

$work = sys_get_temp_dir() . '/sdg-upgrade-' . bin2hex(random_bytes(4));
mkdir($work, 0o700);
register_shutdown_function(static function () use ($work): void {
    array_map('unlink', glob("{$work}/*") ?: []);
    @rmdir($work);
});

const PWD    = 'upgrade-password-01';
const PHRASE = 'cheval agrafe batterie correct moulin ivoire';
$memo = static fn (string $who): string => hash('sha256', "{$who}-memorized");
$key  = random_bytes(32);

/** $record at another revision. */
function atRevision(VaultRecord $r, int $revision): VaultRecord
{
    return new VaultRecord(
        userId: $r->userId, userSalt: $r->userSalt, wrapPwd: $r->wrapPwd, wrapRecov: $r->wrapRecov, wrapAdmin: $r->wrapAdmin,
        createdAt: $r->createdAt, updatedAt: $r->updatedAt, wrapPhrase: $r->wrapPhrase, revision: $revision,
        kdfOpslimit: $r->kdfOpslimit, kdfMemlimit: $r->kdfMemlimit, profileSeal: $r->profileSeal
    );
}

/** A database as 0.9.x leaves it: bare locks, sealed, the blind-index key recorded. */
function oldStore(string $path, string $key, array $users): SqliteAdapter
{
    $store = new SqliteAdapter('sqlite:' . $path);
    $store->claimSetting(SelfDataGuard::SETTING_BLIND_KEY, BlindIndex::keyCheck($key));
    foreach ($users as $user) {
        $store->saveVault(sealedBare($key, $user));
    }
    return $store;
}

function sealedBare(string $key, string $user): VaultRecord
{
    $r = (new UserVault())->register($user, PWD, hash('sha256', "{$user}-memorized"), PHRASE)['record'];
    return $r->withProfileSeal(ProfileSeal::of(ProfileSeal::key($key), $r, $r->kdfOpslimit, $r->kdfMemlimit));
}

/** A second pepper, another file. */
function otherPepper(string $work): LocalPepperFile
{
    $path = "{$work}/other-pepper";
    file_put_contents($path, Pepper::TAG . ':' . bin2hex(random_bytes(32)) . "\n");
    chmod($path, 0o600);
    return new LocalPepperFile($path, '');
}

// -----------------------------------------------------------------------------

section('A new installation: the pepper in the Argon2id input of wrap_pwd and wrap_recov');

$fresh = new SqliteAdapter('sqlite:' . "{$work}/fresh.sqlite");
$dg = new SelfDataGuard($fresh, $key, benchPepper(), PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
$dg->register('alice', PWD, $memo('alice'), PHRASE);
[$dg->lockState('alice', Lock::Password), $dg->lockState('alice', Lock::Memorized), $dg->lockState('alice', Lock::Passphrase)]
    === ['native', 'native', 'bare']
    ? ok('register(): wrap_pwd and wrap_recov native, the passphrase without the pepper')
    : ko('the locks of a new vault are not in the forms expected', json_encode([$dg->lockState('alice', Lock::Password), $dg->lockState('alice', Lock::Memorized)]));
$opens = 0;
foreach ([[Lock::Password, PWD], [Lock::Memorized, $memo('alice')], [Lock::Passphrase, PHRASE]] as [$lock, $secret]) {
    $opens += thrown(fn () => $dg->recover('alice', $lock, $secret, PWD)) === null ? 1 : 0;
}
$opens === 3
    ? ok('each of the three locks opens the vault')
    : ko('a lock of the new vault does not open it', "{$opens}/3");
is_a((string) thrown(fn () => $dg->loginWithPassword('alice', 'not-the-password-1')), WrongSecretException::class, true)
    ? ok('a wrong password is a wrong secret')
    : ko('a wrong password was not refused as a wrong secret');
$sealedRow = $fresh->loadVault('alice');
is_a((string) thrown(fn () => (new UserVault())->unlockWithPassword($sealedRow, PWD)), WrongSecretException::class, true)
    ? ok('without the pepper, the right password opens nothing: the database alone is not enough')
    : ko('the database and the password opened the vault without the pepper');
is_a((string) thrown(fn () => new SelfDataGuard($fresh, $key, otherPepper($work), PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle())), InvalidArgumentException::class, true)
    ? ok('a façade given another pepper is refused')
    : ko('a façade with another pepper was built on the database');

section('The memorized secret is sealed as its fingerprint only (P2)');

thrown(fn () => $dg->register('bob', PWD, 'le mot lui-même', PHRASE)) === InvalidArgumentException::class
    ? ok('the word itself is refused at register()')
    : ko('the word itself was sealed');
$s = $dg->loginWithPassword('alice', PWD);
thrown(fn () => $dg->setLock($s, Lock::Memorized, strtoupper($memo('alice')))) === InvalidArgumentException::class
    ? ok('the fingerprint in capitals is refused at setLock()')
    : ko('the fingerprint in capitals was sealed');

// -----------------------------------------------------------------------------

section('The upgrade: the operator lays the pepper over every lock, with no secret in hand');

$old = oldStore("{$work}/old.sqlite", $key, ['u1', 'u2', 'u3']);
$u1Before = $old->loadVault('u1');
$old->replaceWithArchive($old->loadVault('u2')->generation(), sealedBare($key, 'u2'));
$old->freezeVault($old->loadVault('u3')->generation(), 'arbiter', new DateTimeImmutable());

$refused = thrown(fn () => new SelfDataGuard($old, $key, benchPepper(), PepperForms::AcceptBare, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle()));
$message = '';
try {
    new SelfDataGuard($old, $key, benchPepper(), PepperForms::AcceptBare, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
} catch (InvalidArgumentException $e) {
    $message = $e->getMessage();
}
str_contains($message, 'bin/pepper.php adopt')
    ? ok('a database written by 0.9.x is refused until the pass, and the refusal names it')
    : ko('a database without the pepper recorded was opened, or refused without naming the pass', (string) $refused);

$r = SelfDataGuard::adoptPepper($old, $key, benchPepper());
$r['vaults'] === 2 && $r['archives'] === 1 && $r['frozen'] === ['u3'] && $r['refused'] === []
    ? ok('the pass lays two live vaults and one archive, and names the frozen one')
    : ko('the pass did not report what it laid', json_encode($r));
$r = SelfDataGuard::adoptPepper($old, $key, benchPepper());
$r['vaults'] === 0 && $r['archives'] === 0 && $r['frozen'] === ['u3']
    ? ok('run again, it lays nothing more')
    : ko('a second pass laid again', json_encode($r));
$st = SelfDataGuard::pepperStatus($old, $key, benchPepper());
$st['layer'] === 6 && $st['bare'] === 2 && $st['native'] === 0 && $st['unreadable'] === 0
    ? ok('status: six locks under the layer, the frozen vault\'s two still bare')
    : ko('status does not count the forms', json_encode($st));

$accept = new SelfDataGuard($old, $key, benchPepper(), PepperForms::AcceptBare, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
$accept->lockState('u1', Lock::Password) === 'layer'
    && $accept->loginWithPassword('u1', PWD)->userId === 'u1'
    && $accept->loginWithMemorized('u1', $memo('u1'))->userId === 'u1'
    && $accept->loginWithPassword('u3', PWD)->userId === 'u3'
    ? ok('under AcceptBare: a layered lock opens with its secret, and so does a bare one')
    : ko('a lock did not open during the upgrade');

$layered = new SelfDataGuard($old, $key, benchPepper(), PepperForms::LayeredOnly, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle());
$layered->loginWithPassword('u1', PWD)->userId === 'u1'
    ? ok('under LayeredOnly: a layered lock opens with its secret')
    : ko('a layered lock did not open once the upgrade was declared over');
thrown(fn () => $layered->loginWithPassword('u3', PWD)) === UnlayeredLockException::class
    && is_a(UnlayeredLockException::class, WrongSecretException::class, true)
    ? ok('under LayeredOnly: a bare lock is refused, as a wrong secret, by a class the operator can log')
    : ko('a bare lock opened once the upgrade was declared over');

$old->updateVault(atRevision($u1Before, $old->loadVault('u1')->revision));
thrown(fn () => $layered->loginWithPassword('u1', PWD)) === UnlayeredLockException::class
    ? ok('a row put back from a copy taken before the pass passes its seal, and is refused all the same')
    : ko('a row restored from before the pass opened under LayeredOnly');

$median = static function (callable $f): float {
    $t = [];
    for ($i = 0; $i < 5; $i++) {
        $start = hrtime(true);
        try {
            $f();
        } catch (Throwable) {
        }
        $t[] = (hrtime(true) - $start) / 1e6;
    }
    sort($t);
    return $t[2];
};
$bare  = $median(fn () => $layered->loginWithPassword('u1', PWD));
$wrong = $median(fn () => $layered->loginWithPassword('u2', 'not-the-password-1'));
$ratio = $bare / max($wrong, 0.001);
$ratio > 0.67 && $ratio < 1.5
    ? ok(sprintf('the refusal of a bare lock takes the time of a wrong secret: %.1f ms and %.1f ms (median of five)', $bare, $wrong))
    : ko('the refusal of a bare lock does not take the time of a wrong secret', sprintf('%.1f ms against %.1f ms', $bare, $wrong));

$layered->recover('u2', Lock::Memorized, $memo('u2'), 'upgrade-password-02');
$layered->lockState('u2', Lock::Memorized) === 'native' && $layered->lockState('u2', Lock::Password) === 'native'
    && $layered->loginWithMemorized('u2', $memo('u2'))->userId === 'u2'
    ? ok('recover() by the memorized secret, once the upgrade is over: both locks native, and the memorized secret still opens')
    : ko('recover() did not seal the locks native', $layered->lockState('u2', Lock::Memorized));
$r = SelfDataGuard::unlayPepper($old, $key, benchPepper());
in_array(true, array_map(static fn (string $u): bool => str_starts_with($u, 'u2 '), $r['refused']), true)
    ? ok('the inverse pass names a vault whose lock is native, and leaves it')
    : ko('the inverse pass took a native lock for a layered one', json_encode($r));

// -----------------------------------------------------------------------------

section('The return to 0.9.x, before the end of the upgrade');

$back = oldStore("{$work}/back.sqlite", $key, ['v1', 'v2']);
SelfDataGuard::adoptPepper($back, $key, benchPepper());
is_a((string) thrown(fn () => (new UserVault())->unlockWithPassword($back->loadVault('v1'), PWD)), WrongSecretException::class, true)
    ? ok('laid over, a lock no longer opens as 0.9.x opens it: the right password answers as a wrong one')
    : ko('0.9.x would still open a layered lock');
$r = SelfDataGuard::unlayPepper($back, $key, benchPepper());
$asOld = new UserVault();
$r['vaults'] === 2 && $r['refused'] === []
    && $asOld->unlockWithPassword($back->loadVault('v1'), PWD)->userId === 'v1'
    && $asOld->unlockWithMemorized($back->loadVault('v2'), $memo('v2'))->userId === 'v2'
    ? ok('the inverse pass takes every layer off: 0.9.x opens by the password and by the memorized secret')
    : ko('the inverse pass did not give back what 0.9.x opens', json_encode($r));
SelfDataGuard::pepperStatus($back, $key, benchPepper())['bare'] === 4
    && (new SelfDataGuard($back, $key, benchPepper(), PepperForms::AcceptBare, throttle: new \Pierroons\SelfDataGuard\Throttle\NoThrottle()))->loginWithPassword('v1', PWD)->userId === 'v1'
    ? ok('the check of the pepper stays: a façade of 0.10.0 opens the database again, the locks bare')
    : ko('after the inverse pass, the database does not reopen under 0.10.0');

section('The pass refuses what would close the locks');

thrown(fn () => SelfDataGuard::adoptPepper($back, $key, otherPepper($work))) === InvalidArgumentException::class
    ? ok('a pass under another pepper is refused once a pass ran')
    : ko('a pass under another pepper laid over layers of the first');
$cut = oldStore("{$work}/cut.sqlite", $key, ['x1']);
$cut->claimSetting(SelfDataGuard::SETTING_PEPPER_PASS, Pepper::check(benchPepper()));
$cutMessage = '';
try {
    SelfDataGuard::adoptPepper($cut, $key, otherPepper($work));
} catch (InvalidArgumentException $e) {
    $cutMessage = $e->getMessage();
}
str_contains($cutMessage, 'A pass under another pepper') && $cut->loadSetting(SelfDataGuard::SETTING_PEPPER) === null
    ? ok('a pass cut short before it recorded its pepper: a second one under another pepper is refused, and records nothing')
    : ko('a pass under another pepper went on over a pass cut short', $cutMessage);
thrown(fn () => SelfDataGuard::adoptPepper($back, random_bytes(32), benchPepper())) === InvalidArgumentException::class
    ? ok('a pass with another blind-index key is refused: every seal would read as tampered')
    : ko('a pass ran under another blind-index key');

// -----------------------------------------------------------------------------

section('bin/pepper.php adopt, status and unlayer');

$pepperFile = "{$work}/bin-pepper";
$keyFile    = "{$work}/bin-key";
file_put_contents($keyFile, $key);
$run = static function (array $args, array $env) : array {
    $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/pepper.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($process), $out];
};
$run(['new', $pepperFile], []);
$binStore = oldStore("{$work}/bin.sqlite", $key, ['w1']);
$binStore->freezeVault($binStore->loadVault('w1')->generation(), 'arbiter', new DateTimeImmutable());
$env = ['DATAGUARD_DB' => "{$work}/bin.sqlite", 'DATAGUARD_BLINDKEY_PATH' => $keyFile];
[$rc, $out] = $run(['adopt', $pepperFile], $env);
$rc === 2 && str_contains($out, 'w1')
    ? ok('adopt: 2 while a frozen vault is left, which it names')
    : ko('adopt did not report the frozen vault', "rc={$rc} {$out}");
[$rc, $out] = $run(['status', $pepperFile], $env);
$rc === 2 && str_contains($out, 'nue 2')
    ? ok('status: 2 while locks are bare')
    : ko('status did not report the bare locks', "rc={$rc} {$out}");
$binStore->unfreezeVault($binStore->loadVault('w1')->generation());
[$rc] = $run(['adopt', $pepperFile], $env);
[$rc2, $out] = $run(['status', $pepperFile], $env);
$rc === 0 && $rc2 === 0 && str_contains($out, 'nue 0')
    ? ok('once the vault is unfrozen, adopt then status: 0 — nothing bare, the end of the upgrade can be declared')
    : ko('adopt after the closure did not finish', "adopt={$rc} status={$rc2} {$out}");
[$rc] = $run(['unlayer', $pepperFile], $env);
[$rc2, $out] = $run(['status', $pepperFile], $env);
$rc === 0 && $rc2 === 2 && str_contains($out, 'nue 2')
    ? ok('unlayer: 0, and status counts the locks bare again')
    : ko('unlayer did not take the layers off', "unlayer={$rc} status={$rc2} {$out}");
[$rc] = $run(['adopt', $pepperFile], ['DATAGUARD_DB' => "{$work}/nowhere.sqlite", 'DATAGUARD_BLINDKEY_PATH' => $keyFile]);
$rc === 3 ? ok('3 when the database it is given does not exist') : ko('a missing database did not exit 3', "rc={$rc}");

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Upgrade Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
