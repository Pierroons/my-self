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

use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Storage\StorageInterface;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\StaleVaultException;
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

$dg->changePassword($session2, 'new-much-stronger-passphrase');

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

$dg->changeMemorized($sessionNew, 'autumn-leaves-quiet');

$sessionMem = $dg->loginWithMemorized('user-cosmo', 'autumn-leaves-quiet');
$plain3 = $dg->getFields($sessionMem);
$plain3['email'] === 'alice@example.com'
    ? ok('after changeMemorized, recovery flow still works')
    : ko('memorized rotation broke recovery');

// Remove memorized recovery
$dg->changeMemorized($sessionMem, null);
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

$dg->delete('user-cosmo');
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
$dg->delete('user-gen');
$dg->register('user-gen', 'gen-password-0002', 'gen-memorized');

$guarded = [
    'setFields'              => static fn () => $dg->setFields($stale, ['a' => '2']),
    'getFields'              => static fn () => $dg->getFields($stale),
    'setEscrowFields'        => static fn () => $dg->setEscrowFields($stale, $admin['publicKey'], ['c' => 'd']),
    'getEscrowFieldsAsUser'  => static fn () => $dg->getEscrowFieldsAsUser($stale),
    'changePassword'         => static fn () => $dg->changePassword($stale, 'gen-password-0003'),
    'changePassphrase'       => static fn () => $dg->changePassphrase($stale, $PH1),
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
!$dg->hasEscrow('user-gen')
    ? ok('no escrow was created under the old master key')
    : ko('an escrow was sealed with a stale session');

// -----------------------------------------------------------------------------

section('A storage that drops wrap_phrase is caught, not trusted');

/** A StorageInterface implementation that predates 0.5.0: wrap_phrase is not written. */
final class ForgetfulStorage implements StorageInterface
{
    public function __construct(private readonly StorageInterface $inner) {}
    public function saveVault(VaultRecord $r): void { $this->inner->saveVault($r->withWrapPhrase(null, $r->updatedAt)); }
    public function updateVault(VaultRecord $r): void { $this->inner->updateVault($r->withWrapPhrase(null, $r->updatedAt)); }
    public function loadVault(string $u): VaultRecord { return $this->inner->loadVault($u); }
    public function findVault(string $u): ?VaultRecord { return $this->inner->findVault($u); }
    public function vaultExists(string $u): bool { return $this->inner->vaultExists($u); }
    public function deleteVault(string $u): void { $this->inner->deleteVault($u); }
    public function saveFields(string $u, array $f): void { $this->inner->saveFields($u, $f); }
    public function loadFields(string $u, array $n = []): array { return $this->inner->loadFields($u, $n); }
    public function findUserIdByBlindIndex(string $f, string $b): ?string { return $this->inner->findUserIdByBlindIndex($f, $b); }
    public function saveEscrow(EscrowRecord $r): void { $this->inner->saveEscrow($r); }
    public function loadEscrow(string $u): ?EscrowRecord { return $this->inner->loadEscrow($u); }
    public function saveEscrowFields(string $u, array $f): void { $this->inner->saveEscrowFields($u, $f); }
    public function loadEscrowFields(string $u, array $n = []): array { return $this->inner->loadEscrowFields($u, $n); }
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

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Phase 5 Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
