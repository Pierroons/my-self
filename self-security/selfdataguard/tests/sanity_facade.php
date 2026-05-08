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
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;

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
    'email'   => 'pierre@example.com',
    'phone'   => '+33612345678',
    'address' => '12 rue des Champs, 33220 Sainte-Foy',
    'iban'    => 'FR7612345678901234567890123',
], indexed: ['email', 'phone']);
ok('setFields with indexing on email + phone');

// -----------------------------------------------------------------------------

section('Duplicate registration');

try {
    $dg->register('user-cosmo', 'another-pwd');
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
$plain['email'] === 'pierre@example.com' ? ok('email decrypted') : ko('email wrong: ' . ($plain['email'] ?? 'null'));
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
$recPlain['email'] === 'pierre@example.com'
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

$found = $dg->findUserByField('email', 'pierre@example.com');
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

$cosmoStill = $dg->findUserByField('email', 'pierre@example.com');
$cosmoStill === 'user-cosmo' ? ok('cosmo still findable after bob registered') : ko('cosmo lost from index');

// -----------------------------------------------------------------------------

section('Password rotation via façade');

$dg->changePassword($session2, 'new-much-stronger-passphrase');

$sessionNew = $dg->loginWithPassword('user-cosmo', 'new-much-stronger-passphrase');
$plain2 = $dg->getFields($sessionNew);
$plain2['email'] === 'pierre@example.com'
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
$plain3['email'] === 'pierre@example.com'
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

$dg->setFields($sessionPwdAfter, ['email' => 'pierre.new@example.com'], indexed: ['email']);
$updated = $dg->getFields($sessionPwdAfter, ['email']);
$updated['email'] === 'pierre.new@example.com' ? ok('field upserted via setFields') : ko('upsert via façade failed');

// Old index should miss now
$missOld = $dg->findUserByField('email', 'pierre@example.com');
$missOld === null ? ok('old indexed value no longer resolves') : ko('stale index still hits');

$hitNew = $dg->findUserByField('email', 'pierre.new@example.com');
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

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Phase 5 Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
