<?php

declare(strict_types=1);

/**
 * Sanity smoke test for Phase 4 — SqliteAdapter end-to-end.
 *
 * Run:  php tests/sanity_storage.php
 * Exit: 0 on success, non-zero on first failure.
 *
 * Validates the persistence layer: vault save/load/update/delete + fields
 * batch save + blind-index lookup on a real SQLite file (in /tmp, cleaned up
 * automatically). Also runs a "DB dump" demo to prove that the on-disk data
 * is unreadable without the user's password.
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Fields\BlindIndex;
use Pierroons\SelfDataGuard\Fields\FieldCrypter;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Vault\StaleVaultException;
use Pierroons\SelfDataGuard\Vault\UserVault;
use Pierroons\SelfDataGuard\Vault\VaultExistsException;
use Pierroons\SelfDataGuard\Vault\VaultGeneration;
use Pierroons\SelfDataGuard\Vault\VaultNotFoundException;

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

section('Setup — fresh SQLite DB in /tmp');

$dbPath = sys_get_temp_dir() . '/selfdataguard-sanity-' . bin2hex(random_bytes(4)) . '.sqlite';
@unlink($dbPath);
register_shutdown_function(static fn () => @unlink($dbPath));

$storage = new SqliteAdapter("sqlite:{$dbPath}");
ok("SqliteAdapter constructed (DB at {$dbPath})");

is_file($dbPath) ? ok('schema bootstrapped to disk') : ko('DB file not created');

// -----------------------------------------------------------------------------

section('Vault — save / load / exists / find');

$vault = new UserVault();
$blindKey = Primitives::randomBytes(32);

$result = $vault->register(
    userId: 'user-cosmo',
    password: 'correct horse battery staple',
    memorized: 'sunset-river-marble'
);
$record = $result['record'];
$unlocked = $result['unlocked'];

$storage->saveVault($record);
ok('saveVault — initial insert');

$storage->vaultExists('user-cosmo') ? ok('vaultExists → true after save') : ko('vaultExists wrong');
!$storage->vaultExists('nobody') ? ok('vaultExists → false for unknown user') : ko('vaultExists false positive');

$reloaded = $storage->loadVault('user-cosmo');
$reloaded->userId === 'user-cosmo' ? ok('loaded record userId correct') : ko('userId reloaded wrong');
hash_equals($reloaded->userSalt, $record->userSalt) ? ok('loaded record userSalt matches') : ko('userSalt corrupted');
$reloaded->wrapPwd->ciphertext === $record->wrapPwd->ciphertext ? ok('wrapPwd ciphertext intact') : ko('wrapPwd corrupted');
$reloaded->wrapRecov !== null ? ok('wrapRecov preserved') : ko('wrapRecov lost');

// findVault when present
$found = $storage->findVault('user-cosmo');
$found !== null ? ok('findVault returns record when present') : ko('findVault returned null');
$found?->userId === 'user-cosmo' ? ok('findVault userId correct') : ko('findVault wrong record');

// findVault when absent
$missing = $storage->findVault('user-ghost');
$missing === null ? ok('findVault returns null for unknown user') : ko('findVault should be null');

// loadVault throws when absent
try {
    $storage->loadVault('user-ghost');
    ko('loadVault should throw on missing');
} catch (RuntimeException) {
    ok('loadVault throws on missing user');
}

// -----------------------------------------------------------------------------

section('Vault — duplicate insert protection');

try {
    $storage->saveVault($record);
    ko('duplicate saveVault should throw');
} catch (VaultExistsException) {
    ok('duplicate saveVault throws VaultExistsException — an insert never replaces a vault');
}

// -----------------------------------------------------------------------------

section('Vault — update after password rotation');

$rotated = $vault->setLock($record, $unlocked, Lock::Password, 'new-much-stronger-passphrase');
$storage->updateVault($rotated);
ok('updateVault accepted');

$reloaded2 = $storage->loadVault('user-cosmo');
$reloaded2->wrapPwd->ciphertext !== $record->wrapPwd->ciphertext
    ? ok('wrapPwd updated in DB')
    : ko('wrapPwd not updated');
hash_equals($reloaded2->userSalt, $record->userSalt) ? ok('userSalt unchanged on update') : ko('salt changed');

// updateVault on missing user throws
$ghostResult = $vault->register(userId: 'user-ghost', password: 'ghost-password-long');
try {
    $storage->updateVault($ghostResult['record']);
    ko('updateVault should throw on missing user');
} catch (RuntimeException) {
    ok('updateVault throws on missing user');
}

// -----------------------------------------------------------------------------

section('Fields — saveFields + loadFields round trip');

$plaintexts = [
    'email'   => 'alice@example.com',
    'phone'   => '+33612345678',
    'address' => '12 rue des Champs, 33220 Sainte-Foy',
    'iban'    => 'FR7612345678901234567890123',
];

$encrypted = [];
foreach ($plaintexts as $name => $value) {
    $encrypted[$name] = [
        'ciphertext' => FieldCrypter::encrypt($unlocked, $name, $value),
        'blindIndex' => BlindIndex::compute($value, $blindKey, $name),
    ];
}

$storage->saveFields($record->generation(), $encrypted);
ok('saveFields batch (4 fields)');

$loaded = $storage->loadFields('user-cosmo');
count($loaded) === 4 ? ok('loadFields (no filter) returns all 4') : ko('field count wrong (got ' . count($loaded) . ')');

$decrypted = [];
foreach ($loaded as $name => $cipher) {
    $decrypted[$name] = FieldCrypter::decrypt($unlocked, $name, $cipher);
}
ksort($decrypted);
$expected = $plaintexts;
ksort($expected);
$decrypted == $expected ? ok('all fields decrypt to original plaintext') : ko('round trip lost data');

// Filtered load
$partial = $storage->loadFields('user-cosmo', ['email', 'phone']);
count($partial) === 2 ? ok('loadFields (filtered) returns 2') : ko('filter wrong count');
isset($partial['email'], $partial['phone']) ? ok('filtered fields present') : ko('filtered keys missing');

// -----------------------------------------------------------------------------

section('Fields — upsert (re-saving an existing field)');

$updated = [
    'email' => [
        'ciphertext' => FieldCrypter::encrypt($unlocked, 'email', 'new@example.com'),
        'blindIndex' => BlindIndex::compute('new@example.com', $blindKey, 'email'),
    ],
];
$storage->saveFields($record->generation(), $updated);
$reloaded = $storage->loadFields('user-cosmo', ['email']);
FieldCrypter::decrypt($unlocked, 'email', $reloaded['email']) === 'new@example.com'
    ? ok('upsert overwrites existing field')
    : ko('upsert did not update');

// -----------------------------------------------------------------------------

section('Blind index — equality lookup across users');

// Register a second user
$bobResult = $vault->register(userId: 'user-bob', password: 'bob-pwd-1234');
$bobRecord = $bobResult['record'];
$bobUnlocked = $bobResult['unlocked'];

$storage->saveVault($bobRecord);
$storage->saveFields($bobRecord->generation(), [
    'email' => [
        'ciphertext' => FieldCrypter::encrypt($bobUnlocked, 'email', 'bob@example.com'),
        'blindIndex' => BlindIndex::compute('bob@example.com', $blindKey, 'email'),
    ],
]);

// Lookup by blind index
$lookupNew = BlindIndex::compute('new@example.com', $blindKey, 'email');
$found = $storage->findUserIdsByIndex('email', $lookupNew);
$found === ['user-cosmo'] ? ok('findUserIdsByIndex → cosmo by his email index') : ko("got: " . var_export($found, true));

$lookupBob = BlindIndex::compute('bob@example.com', $blindKey, 'email');
$foundBob = $storage->findUserIdsByIndex('email', $lookupBob);
$foundBob === ['user-bob'] ? ok('findUserIdsByIndex → bob by his email index') : ko('bob lookup failed');

// Miss
$missLookup = BlindIndex::compute('nobody@example.com', $blindKey, 'email');
$miss = $storage->findUserIdsByIndex('email', $missLookup);
$miss === [] ? ok('findUserIdsByIndex returns no account on a miss') : ko('false hit on miss');

// -----------------------------------------------------------------------------

section('THE BIG TEST — DB dump = soup');

$dump = file_get_contents($dbPath);
$dumpLen = strlen($dump);

// The plaintext email "alice@example.com" was originally encrypted, then
// we updated to "new@example.com" then "bob@example.com" was added.
// Let's verify NONE of these plaintexts appear anywhere in the raw DB file.
$hasOriginal = str_contains($dump, 'alice@example.com');
$hasNew      = str_contains($dump, 'new@example.com');
$hasBob      = str_contains($dump, 'bob@example.com');
$hasIban     = str_contains($dump, 'FR7612345678901234567890123');
$hasAddr     = str_contains($dump, 'rue des Champs');

!$hasOriginal ? ok('dump does NOT contain "alice@example.com"') : ko('LEAK: original email visible in DB dump');
!$hasNew      ? ok('dump does NOT contain "new@example.com"')    : ko('LEAK: updated email visible');
!$hasBob      ? ok('dump does NOT contain "bob@example.com"')    : ko('LEAK: bob email visible');
!$hasIban     ? ok('dump does NOT contain IBAN plaintext')        : ko('LEAK: IBAN visible');
!$hasAddr     ? ok('dump does NOT contain address plaintext')     : ko('LEAK: address visible');

echo "    (DB file size: {$dumpLen} bytes — fully encrypted user data)\n";

// -----------------------------------------------------------------------------

section('Delete — cascade vault + fields');

$storage->deleteVault($record->generation());

!$storage->vaultExists('user-cosmo') ? ok('deleteVault removes the vault') : ko('vault still exists');

$leftoverFields = $storage->loadFields('user-cosmo');
count($leftoverFields) === 0 ? ok('deleteVault cascades to fields') : ko('orphan fields remain');

// Bob still there
$storage->vaultExists('user-bob') ? ok('other users untouched') : ko('cascade was too wide');

// -----------------------------------------------------------------------------

section('Edge — empty fields batch is no-op');

$storage->saveFields($bobRecord->generation(), []);
ok('saveFields([]) does not throw');

// -----------------------------------------------------------------------------

section('Atomicity — an error midway leaves nothing written and no transaction open');

$pdo = new PDO('sqlite::memory:');
$own = new SqliteAdapter($pdo);
$tx = (new UserVault())->register('user-tx', 'tx-password-0001')['record'];
$own->saveVault($tx);

$thrown = false;
try {
    $own->saveFields($tx->generation(), ['first' => ['ciphertext' => 'c1'], 'second' => []]);
} catch (RuntimeException) {
    $thrown = true;
}
$thrown ? ok('saveFields rejects a field without ciphertext') : ko('malformed batch accepted');
!$pdo->inTransaction()
    ? ok('no transaction left open on the connection')
    : ko('transaction left open', 'the next write on this connection would throw');
$own->loadFields('user-tx') === []
    ? ok('the field written before the error is rolled back')
    : ko('partial batch persisted');

// -----------------------------------------------------------------------------

section('Nesting — inside the caller\'s transaction, the caller decides');

$pdo->beginTransaction();
$own->saveFields($tx->generation(), ['kept' => ['ciphertext' => 'k1']]);
$thrown = false;
try {
    $own->saveFields($tx->generation(), ['bad' => []]);
} catch (RuntimeException) {
    $thrown = true;
}
$thrown ? ok('the failing batch throws inside the caller\'s transaction') : ko('failing batch did not throw');
$pdo->inTransaction()
    ? ok('the caller\'s transaction is still open')
    : ko('the library closed the caller\'s transaction');
$pdo->commit();
array_keys($own->loadFields('user-tx')) === ['kept']
    ? ok('the caller commits: the earlier batch is kept, the failed one is not')
    : ko('unexpected fields after commit', implode(',', array_keys($own->loadFields('user-tx'))));

$pdo->beginTransaction();
$own->saveFields($tx->generation(), ['rolled' => ['ciphertext' => 'r1']]);
$pdo->rollBack();
!array_key_exists('rolled', $own->loadFields('user-tx'))
    ? ok('the caller rolls back: our write goes with it')
    : ko('write survived the caller\'s rollback');

// -----------------------------------------------------------------------------

section('wrap_phrase — persisted on insert and on update');

$withPhrase = (new UserVault())->register('user-phrase', 'phrase-password-0001', null, 'cheval agrafe batterie correct moulin ivoire');
$own->saveVault($withPhrase['record']);
$loaded = $own->loadVault('user-phrase');
$loaded->wrapPhrase?->toBase64() === $withPhrase['record']->wrapPhrase->toBase64()
    ? ok('saveVault() then loadVault() round-trips wrap_phrase')
    : ko('wrap_phrase lost on insert');
$own->updateVault((new UserVault())->removeLock($loaded, $withPhrase['unlocked'], Lock::Passphrase));
!$own->loadVault('user-phrase')->hasPassphrase()
    ? ok('updateVault() writes wrap_phrase too (here: its removal)')
    : ko('updateVault() left the old wrap_phrase in place');

// -----------------------------------------------------------------------------

section('A caller transaction opened in plain SQL — PDO before 8.4 does not see it');

$pdo->exec('BEGIN');
$thrown = null;
try {
    $own->saveFields($tx->generation(), ['raw' => ['ciphertext' => 'r1']]);
} catch (Throwable $e) {
    $thrown = $e;
}
$thrown === null ? ok('a write inside it nests instead of opening a second transaction') : ko('the write failed inside a plain-SQL transaction', $thrown->getMessage());
try {
    $pdo->exec('ROLLBACK');
    ok('the caller\'s transaction is still open, and still the caller\'s to end');
} catch (PDOException $e) {
    ko('the caller\'s transaction was closed by the library', $e->getMessage());
}
!array_key_exists('raw', $own->loadFields('user-tx'))
    ? ok('rolled back by the caller, the write is gone')
    : ko('the write survived the caller\'s rollback');

// -----------------------------------------------------------------------------

section('Committed, and the write lock released, for every connection');

$storage->saveFields($bobRecord->generation(), ['seen' => ['ciphertext' => 's1']]);
$second = new PDO("sqlite:{$dbPath}");
$second->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$second->setAttribute(PDO::ATTR_TIMEOUT, 1);
$seen = $second->query("SELECT COUNT(*) FROM selfdataguard_fields WHERE user_id = 'user-bob' AND field_name = 'seen'")->fetchColumn();
(int) $seen === 1 ? ok('another connection reads the field: it was committed') : ko('the field is not visible to another connection');
try {
    $second->exec('BEGIN IMMEDIATE');
    $second->exec('ROLLBACK');
    ok('another connection takes the write lock: no transaction was left open');
} catch (PDOException $e) {
    ko('the write lock is still held after the write', $e->getMessage());
}

// -----------------------------------------------------------------------------

section('updateVault — a record read from a replaced vault cannot overwrite the new one');

$uv = new UserVault();
$first = $uv->register('user-cas', 'cas-password-0001');
$own->saveVault($first['record']);
$stale = $own->loadVault('user-cas');
$own->deleteVault($first['record']->generation());
$second = $uv->register('user-cas', 'cas-password-0002');
$own->saveVault($second['record']);

$thrown = null;
try {
    $own->updateVault($uv->setLock($stale, $first['unlocked'], Lock::Password, 'cas-password-0003'));
} catch (StaleVaultException $e) {
    $thrown = $e;
}
$thrown !== null ? ok('StaleVaultException on a record from the replaced vault') : ko('stale record written');
$own->loadVault('user-cas')->wrapPwd->toBase64() === $second['record']->wrapPwd->toBase64()
    ? ok('the new vault is untouched')
    : ko('the new vault was overwritten by the old one');
try {
    $uv->unlockWithPassword($own->loadVault('user-cas'), 'cas-password-0002');
    ok('the new vault still opens with its own password');
} catch (RuntimeException $e) {
    ko('the new vault no longer opens', $e->getMessage());
}

$thrown = null;
try {
    $own->updateVault($second['record']->withWrapPwd($second['record']->wrapPwd, new DateTimeImmutable()));
    $own->deleteVault($second['record']->generation());
    $own->updateVault($second['record']);
} catch (VaultNotFoundException $e) {
    $thrown = $e;
}
$thrown !== null ? ok('VaultNotFoundException once the vault is gone') : ko('update of a missing vault did not throw');

// -----------------------------------------------------------------------------

section('revision — two requests that read the same vault cannot lose one update');

$rv = (new UserVault())->register('user-rev', 'rev-password-0001', null, 'cheval agrafe batterie correct moulin ivoire');
$own->saveVault($rv['record']);
$readA = $own->loadVault('user-rev');
$readB = $own->loadVault('user-rev');
$own->updateVault((new UserVault())->setLock($readA, $rv['unlocked'], Lock::Password, 'rev-password-0002')) === 1
    && $own->loadVault('user-rev')->revision === 1
    ? ok('an update bumps the revision')
    : ko('revision not bumped', (string) $own->loadVault('user-rev')->revision);
$thrown = null;
try {
    $own->updateVault((new UserVault())->removeLock($readB, $rv['unlocked'], Lock::Passphrase));
} catch (StaleVaultException $e) {
    $thrown = $e;
}
$thrown !== null ? ok('the second writer, holding the older revision, gets StaleVaultException') : ko('a stale revision was written');
$after = $own->loadVault('user-rev');
try {
    (new UserVault())->unlockWithPassword($after, 'rev-password-0002');
    $after->hasPassphrase()
        ? ok('both states survive: the new password stands, the passphrase was not silently dropped')
        : ko('the passphrase removal overwrote the password change\'s write');
} catch (RuntimeException $e) {
    ko('the password change was lost', $e->getMessage());
}

// -----------------------------------------------------------------------------

section('Generation — checked inside the write, not only before it');

$gen = (new UserVault())->register('user-genw', 'genw-password-001');
$own->saveVault($gen['record']);
$other = new VaultGeneration('user-genw', random_bytes(16));
$refused = [];
foreach ([
    'saveFields'         => static fn () => $own->saveFields($other, ['f' => ['ciphertext' => 'c']]),
    'saveEscrowFields'   => static fn () => $own->saveEscrowFields($other, ['e' => 'c']),
    'deleteArchive'      => static fn () => $own->deleteArchive($other, 'no-such-archive'),
    'deleteVault'        => static fn () => $own->deleteVault($other),
    'replaceWithArchive' => static fn () => $own->replaceWithArchive($other, (new UserVault())->register('user-genw', 'genw-password-002')['record']),
] as $name => $call) {
    try {
        $call();
    } catch (StaleVaultException) {
        $refused[] = $name;
    }
}
count($refused) === 5
    ? ok('a write naming another vault\'s salt is refused: ' . implode(', ', $refused))
    : ko('a write went through with the wrong salt', implode(', ', $refused));
$own->loadFields('user-genw') === [] && $own->loadEscrowFields('user-genw') === [] && !$pdo->inTransaction()
    ? ok('nothing was written, no transaction left open')
    : ko('a refused write left data or a transaction behind');
$own->vaultExists('user-genw') && $own->listArchives('user-genw') === []
    ? ok('the vault is still there, and nothing was archived')
    : ko('a refused deleteVault or replaceWithArchive changed the vault');
$own->saveFields($gen['record']->generation(), ['f' => ['ciphertext' => 'c']]);
array_keys($own->loadFields('user-genw')) === ['f']
    ? ok('the right salt writes')
    : ko('the right salt was refused');

$thrown = null;
try {
    $own->saveFields(new VaultGeneration('user-nobody', random_bytes(16)), ['f' => ['ciphertext' => 'c']]);
} catch (VaultNotFoundException $e) {
    $thrown = $e;
}
$thrown !== null && !$own->vaultExists('user-nobody') && $own->loadFields('user-nobody') === []
    ? ok('a write for a userId without a live vault is VaultNotFoundException, not an orphan field')
    : ko('a field was written for a userId that holds no vault');

// -----------------------------------------------------------------------------
section('replaceWithArchive — archives exactly the generation it is given');

$g1 = (new UserVault())->register('user-reenrol', 'reenrol-password-01');
$own->saveVault($g1['record']);
$g2 = (new UserVault())->register('user-reenrol', 'reenrol-password-02');
$g3 = (new UserVault())->register('user-reenrol', 'reenrol-password-03');
$own->replaceWithArchive($g1['record']->generation(), $g2['record']);
$thrown = null;
try {
    $own->replaceWithArchive($g1['record']->generation(), $g3['record']);
} catch (StaleVaultException $e) {
    $thrown = $e;
}
$thrown !== null
    && count($own->listArchives('user-reenrol')) === 1
    && hash_equals($g2['record']->userSalt, $own->loadVault('user-reenrol')->userSalt)
    ? ok('a second re-enrolment naming the same generation is refused: one archive, the first new vault stays')
    : ko('a second re-enrolment archived the vault the first one made');

$own->deleteVault($g2['record']->generation());
$own->eraseAccount('user-reenrol') === 1 && $own->listArchives('user-reenrol') === []
    ? ok('eraseAccount() erases the archives of a userId left without a live vault')
    : ko('eraseAccount() left archives behind, or miscounted');

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Phase 4 Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
