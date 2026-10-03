<?php

declare(strict_types=1);

/**
 * Sanity test — a database created by SelfDataGuard 0.4.0, opened by 0.5.0.
 *
 * Run:  php tests/sanity_migration.php
 * Exit: 0 on success, non-zero if any check failed.
 *
 * The 0.4.0 schema below is copied verbatim from the selfdataguard-v0.4.0
 * tag, not derived from the current code: a migration test that builds its
 * "old" database with today's CREATE statements proves nothing.
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\UserVault;
use Pierroons\SelfDataGuard\Vault\VaultRecord;

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

const SCHEMA_040 = [
    'CREATE TABLE IF NOT EXISTS selfdataguard_vaults (
        user_id     TEXT PRIMARY KEY,
        user_salt   TEXT NOT NULL,
        wrap_pwd    TEXT NOT NULL,
        wrap_recov  TEXT,
        wrap_admin  TEXT,
        created_at  TEXT NOT NULL,
        updated_at  TEXT NOT NULL
    )',
    'CREATE TABLE IF NOT EXISTS selfdataguard_fields (
        user_id     TEXT NOT NULL,
        field_name  TEXT NOT NULL,
        ciphertext  TEXT NOT NULL,
        blind_index TEXT,
        updated_at  TEXT NOT NULL,
        PRIMARY KEY (user_id, field_name),
        FOREIGN KEY (user_id) REFERENCES selfdataguard_vaults(user_id) ON DELETE CASCADE
    )',
    'CREATE INDEX IF NOT EXISTS selfdataguard_fields_blind
     ON selfdataguard_fields(field_name, blind_index)',
    'CREATE TABLE IF NOT EXISTS selfdataguard_escrow (
        user_id     TEXT PRIMARY KEY,
        wrap_user   TEXT NOT NULL,   -- escrow_key wrapped by master_key (base64 EncryptedBlob)
        wrap_admin  TEXT NOT NULL,   -- escrow_key sealed to admin pubkey (base64 sealed box)
        created_at  TEXT NOT NULL,
        updated_at  TEXT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES selfdataguard_vaults(user_id) ON DELETE CASCADE
    )',
    'CREATE TABLE IF NOT EXISTS selfdataguard_escrow_fields (
        user_id     TEXT NOT NULL,
        field_name  TEXT NOT NULL,
        ciphertext  TEXT NOT NULL,   -- escrow field encrypted with escrow_key (base64 EncryptedBlob)
        updated_at  TEXT NOT NULL,
        PRIMARY KEY (user_id, field_name),
        FOREIGN KEY (user_id) REFERENCES selfdataguard_vaults(user_id) ON DELETE CASCADE
    )',
];

/** A 0.4.0 database holding one vault, written with the 0.4.0 column list. */
function oldDatabase(string $userId, string $password, string $memorized): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (SCHEMA_040 as $sql) {
        $pdo->exec($sql);
    }
    $r = (new UserVault())->register($userId, $password, $memorized)['record'];
    $pdo->prepare(
        'INSERT INTO selfdataguard_vaults
         (user_id, user_salt, wrap_pwd, wrap_recov, wrap_admin, created_at, updated_at)
         VALUES (:uid, :salt, :wp, :wr, :wa, :ca, :ua)'
    )->execute([
        ':uid'  => $r->userId,
        ':salt' => base64_encode($r->userSalt),
        ':wp'   => $r->wrapPwd->toBase64(),
        ':wr'   => $r->wrapRecov?->toBase64(),
        ':wa'   => null,
        ':ca'   => $r->createdAt->format('c'),
        ':ua'   => $r->updatedAt->format('c'),
    ]);
    return $pdo;
}

/** @return list<string> one line per column: name, type, notnull, default, pk */
function vaultColumns(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('PRAGMA table_info(selfdataguard_vaults)', PDO::FETCH_ASSOC) as $c) {
        $out[] = implode('|', [$c['name'], $c['type'], $c['notnull'], (string) $c['dflt_value'], $c['pk']]);
    }
    return $out;
}

$uv = new UserVault();
$PHRASE = 'cheval agrafe batterie correct moulin ivoire';

// -----------------------------------------------------------------------------

section('A 0.4.0 database gains wrap_phrase when 0.5.0 opens it');

$old = oldDatabase('user-old', 'old-password-0001', 'old-memorized');
in_array('wrap_phrase', array_map(static fn ($l) => explode('|', $l)[0], vaultColumns($old)), true)
    ? ko('the 0.4.0 fixture already has wrap_phrase — it is not a 0.4.0 schema')
    : ok('fixture: the 0.4.0 table has no wrap_phrase');

$storage = new SqliteAdapter($old);
$fresh = new PDO('sqlite::memory:');
new SqliteAdapter($fresh);
vaultColumns($old) === vaultColumns($fresh)
    ? ok('migrated table == freshly created table (same columns, same order)')
    : ko('migrated and fresh schemas differ', implode(' / ', array_diff(vaultColumns($fresh), vaultColumns($old))));

try {
    $record = $storage->loadVault('user-old');
    $session = $uv->unlockWithPassword($record, 'old-password-0001');
    $uv->unlockWithMemorized($record, 'old-memorized');
    !$record->hasPassphrase()
        ? ok('the 0.4.0 vault still opens by password and memorized secret, with no passphrase wrap')
        : ko('a passphrase wrap appeared out of nowhere');
} catch (Throwable $e) {
    ko('the 0.4.0 vault no longer opens', $e->getMessage());
    exit(1);
}

$storage->updateVault($uv->changePassphrase($record, $session, $PHRASE));
try {
    $uv->unlockWithPassphrase($storage->loadVault('user-old'), $PHRASE);
    ok('a passphrase added to the migrated vault is persisted and opens it');
} catch (Throwable $e) {
    ko('the passphrase did not survive a round-trip', $e->getMessage());
}

try {
    new SqliteAdapter($old);
    ok('a second opening is idempotent (no duplicate-column error)');
} catch (Throwable $e) {
    ko('reopening a migrated database fails', $e->getMessage());
}

// -----------------------------------------------------------------------------

section('A 0.5.x database gains the Argon2id profile when 0.6.0 opens it');

/** A 0.5.x database: the 0.4.0 tables plus the two columns 0.5.0 added, holding one vault with a passphrase. */
function database05x(string $userId, string $password, string $memorized, string $phrase): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (SCHEMA_040 as $sql) {
        $pdo->exec($sql);
    }
    $pdo->exec('ALTER TABLE selfdataguard_vaults ADD COLUMN wrap_phrase TEXT');
    $pdo->exec('ALTER TABLE selfdataguard_vaults ADD COLUMN revision INTEGER NOT NULL DEFAULT 0');
    $r = (new UserVault())->register($userId, $password, $memorized, $phrase)['record'];
    $pdo->prepare(
        'INSERT INTO selfdataguard_vaults
         (user_id, user_salt, wrap_pwd, wrap_recov, wrap_admin, created_at, updated_at, wrap_phrase, revision)
         VALUES (:uid, :salt, :wp, :wr, NULL, :ca, :ua, :ph, 0)'
    )->execute([
        ':uid'  => $r->userId,
        ':salt' => base64_encode($r->userSalt),
        ':wp'   => $r->wrapPwd->toBase64(),
        ':wr'   => $r->wrapRecov?->toBase64(),
        ':ca'   => $r->createdAt->format('c'),
        ':ua'   => $r->updatedAt->format('c'),
        ':ph'   => $r->wrapPhrase?->toBase64(),
    ]);
    return $pdo;
}

$v05 = database05x('user-05x', 'v05-password-0001', 'v05-memorized', $PHRASE);
$noms05 = array_map(static fn ($l) => explode('|', $l)[0], vaultColumns($v05));
in_array('revision', $noms05, true) && !in_array('kdf_opslimit', $noms05, true)
    ? ok('fixture: the 0.5.x table has revision and no profile column')
    : ko('the 0.5.x fixture is not a 0.5.x schema', implode(',', $noms05));

$storage05 = new SqliteAdapter($v05);
$fresh05 = new PDO('sqlite::memory:');
new SqliteAdapter($fresh05);
vaultColumns($v05) === vaultColumns($fresh05)
    ? ok('migrated table == freshly created table (same columns, defaults and order)')
    : ko('migrated and fresh schemas differ', implode(' / ', array_diff(vaultColumns($fresh05), vaultColumns($v05))));

$r05 = $storage05->loadVault('user-05x');
$r05->kdfOpslimit === Primitives::LEGACY_OPSLIMIT && $r05->kdfMemlimit === Primitives::LEGACY_MEMLIMIT
    ? ok('a row stored before 0.6.0 reads back under the legacy profile')
    : ko('a pre-0.6.0 row reads back under another profile', "{$r05->kdfOpslimit}/{$r05->kdfMemlimit}");
try {
    $uv->unlockWithPassword($r05, 'v05-password-0001');
    $uv->unlockWithMemorized($r05, 'v05-memorized');
    $uv->unlockWithPassphrase($r05, $PHRASE);
    ok('the 0.5.x vault opens by its three locks after the migration');
} catch (Throwable $e) {
    ko('the 0.5.x vault no longer opens', $e->getMessage());
}

// The profile goes through the table and back: a vault sealed under another one.
$OPS = 2;
$MEM = 32 * 1024 * 1024;
$base = $uv->register('user-profile', 'profile-password-01');
$mk   = $base['unlocked']->getMasterKey();
$wrap = (new ReflectionMethod(UserVault::class, 'seal'))->invoke(
    null, Lock::Password, 'profile-password-01', $base['record']->userSalt, 'user-profile', $mk, $OPS, $MEM);
$storage05->saveVault(new VaultRecord(
    userId: 'user-profile', userSalt: $base['record']->userSalt, wrapPwd: $wrap, wrapRecov: null,
    wrapAdmin: null, createdAt: $base['record']->createdAt, updatedAt: $base['record']->updatedAt,
    kdfOpslimit: $OPS, kdfMemlimit: $MEM
));
$relu = $storage05->loadVault('user-profile');
try {
    $relu->kdfOpslimit === $OPS && $relu->kdfMemlimit === $MEM
        && $uv->unlockWithPassword($relu, 'profile-password-01')->getMasterKey() === $mk
        ? ok('a vault saved under another profile reads back with it, and opens')
        : ko('the profile did not survive the table', "{$relu->kdfOpslimit}/{$relu->kdfMemlimit}");
} catch (Throwable $e) {
    ko('a vault saved under another profile no longer opens', $e->getMessage());
}

// -----------------------------------------------------------------------------

section('Migration inside the caller\'s transaction');

$inTx = oldDatabase('user-tx', 'tx-password-0001', 'tx-memorized');
$inTx->beginTransaction();
try {
    new SqliteAdapter($inTx);
    $inTx->inTransaction()
        ? ok('migrating inside an open transaction leaves it to the caller')
        : ko('the migration closed the caller\'s transaction');
    $inTx->commit();
    in_array('wrap_phrase', array_map(static fn ($l) => explode('|', $l)[0], vaultColumns($inTx)), true)
        ? ok('committed by the caller, the column is there')
        : ko('column missing after the caller committed');
} catch (Throwable $e) {
    ko('migration inside a caller transaction failed', $e->getMessage());
}

// -----------------------------------------------------------------------------

section('Two processes migrating the same old database');

// This process holds the write lock and adds the column itself, as a
// concurrent migrator would. A second process opens the database meanwhile:
// it sees the column missing, waits for the lock, and must then find the
// column there instead of adding it a second time ("duplicate column name").
$file = sys_get_temp_dir() . '/selfdataguard-migration-' . bin2hex(random_bytes(4)) . '.sqlite';
register_shutdown_function(static fn () => @unlink($file));
$holder = new PDO("sqlite:{$file}");
$holder->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$holder->exec(SCHEMA_040[0]);
$holder->exec('BEGIN IMMEDIATE');
$child = proc_open([PHP_BINARY, '-r', sprintf(
    'require %s; $t = microtime(true); try { new Pierroons\SelfDataGuard\Storage\SqliteAdapter(%s);'
    . ' printf("opened %%d", (microtime(true) - $t) * 1000); }'
    . ' catch (Throwable $e) { echo $e->getMessage(); exit(1); }',
    var_export(__DIR__ . '/../src/autoload.php', true),
    var_export("sqlite:{$file}", true)
)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
usleep(1_500_000);
$holder->exec('ALTER TABLE selfdataguard_vaults ADD COLUMN wrap_phrase TEXT');
$holder->exec('COMMIT');
$childOut = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
$childWaited = preg_match('/^opened (\d+)$/', $childOut, $mm) === 1 ? (int) $mm[1] : -1;
proc_close($child) === 0 && $childWaited >= 0
    ? ok('the second migrator waits for the lock, then finds the column already added')
    : ko('the second migrator failed', $childOut);
$childWaited >= 300
    ? ok("it did wait ({$childWaited} ms) — the second check under the lock was exercised")
    : ko('the second migrator never waited: the race was not exercised', $childOut);

// -----------------------------------------------------------------------------

section('Rollback to 0.4.0 — what an older library leaves behind (documented risk)');

// 0.4.0 rewrites a vault with its own UPDATE, which does not name wrap_phrase.
// The column keeps its value: a passphrase that SelfRecover has since
// replaced keeps opening the vault. The CHANGELOG states this; the test pins it.
$before = $storage->loadVault('user-old');
$old->prepare(
    'UPDATE selfdataguard_vaults
     SET user_salt = :salt,
         wrap_pwd  = :wp,
         wrap_recov = :wr,
         wrap_admin = :wa,
         updated_at = :ua
     WHERE user_id = :uid'
)->execute([
    ':uid'  => $before->userId,
    ':salt' => base64_encode($before->userSalt),
    ':wp'   => $before->wrapPwd->toBase64(),
    ':wr'   => $before->wrapRecov?->toBase64(),
    ':wa'   => null,
    ':ua'   => (new DateTimeImmutable())->format('c'),
]);
$storage->loadVault('user-old')->wrapPhrase?->toBase64() === $before->wrapPhrase?->toBase64()
    ? ok('a 0.4.0 UPDATE leaves wrap_phrase untouched — stale after a rollback, as documented')
    : ko('a 0.4.0 UPDATE altered wrap_phrase');

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Migration Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
