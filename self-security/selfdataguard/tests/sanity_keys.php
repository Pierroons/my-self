<?php

declare(strict_types=1);

/**
 * Sanity test — no object that holds a key hands it out.
 *
 * Run:  php tests/sanity_keys.php
 * Exit: 0 on success, non-zero if any check failed.
 *
 * A type is not enough to tell: a key is a string, and so is a ciphertext. Three
 * checks instead. The public methods of each key-holding class are a closed
 * list, so that a new one fails here until someone reads it. Each of them is
 * called with a known key inside, and the key is looked for in what comes
 * back, raw, base64 and hex. And no public method of the object layer takes a
 * key: the admin key is unsealed and used inside EscrowCeremony, in private
 * methods.
 * Primitives is the raw layer and is left out by name: deriving and opening
 * keys is what it is for.
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/key_fixture.php';

use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Escrow\AdminKey;
use Pierroons\SelfDataGuard\Escrow\EscrowCeremony;
use Pierroons\SelfDataGuard\Escrow\EscrowFieldCrypter;
use Pierroons\SelfDataGuard\Escrow\EscrowVault;
use Pierroons\SelfDataGuard\Escrow\UnlockedEscrow;
use Pierroons\SelfDataGuard\Fields\FieldCrypter;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\UnlockedArchive;
use Pierroons\SelfDataGuard\Vault\UnlockedVault;
use Pierroons\SelfDataGuard\Vault\UserVault;

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

/** @return list<string> the public methods a class declares, magic ones included */
function publicMethods(string $class): array
{
    $names = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        array_filter(
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $class
        )
    );
    sort($names);
    return $names;
}

/**
 * Does $key show in $value — as bytes, base64 or hex — in any string it holds,
 * private properties of the objects it returns included? The values are walked,
 * not exported: var_export() cuts a binary string at each NUL byte, and a key
 * searched in the export is missed.
 */
function carries(mixed $value, string $key, int $depth = 0): bool
{
    if ($depth > 8) {
        return false;
    }
    if (is_string($value)) {
        foreach ([$key, base64_encode($key), bin2hex($key)] as $form) {
            if (str_contains($value, $form)) {
                return true;
            }
        }
        return false;
    }
    if (is_object($value)) {
        $value = (array) $value;
    }
    if (is_array($value)) {
        foreach ($value as $inner) {
            if (carries($inner, $key, $depth + 1)) {
                return true;
            }
        }
    }
    return false;
}

$MAGIC = ['__clone', '__construct', '__debugInfo', '__destruct', '__serialize', '__unserialize'];

// -----------------------------------------------------------------------------

section('The public methods of each key-holding class are a closed list');

$closed = [
    UnlockedVault::class   => ['createEscrow', 'decryptField', 'encryptField', 'generation', 'isLocked', 'lock', 'openEscrow', 'sealLock'],
    UnlockedEscrow::class  => ['decryptField', 'encryptField', 'isLocked', 'lock', 'sealToAdmin'],
    UnlockedArchive::class => ['decryptFields', 'lock', 'openEscrow', 'userId'],
];
foreach ($closed as $class => $expected) {
    $actual = array_values(array_diff(publicMethods($class), $MAGIC));
    $actual === $expected
        ? ok(substr($class, strrpos($class, '\\') + 1) . ': ' . implode(', ', $expected))
        : ko(substr($class, strrpos($class, '\\') + 1) . ' gained or lost a public method — read it, then update this list',
            'now: ' . implode(', ', $actual));
}
foreach ([UnlockedVault::class => 'masterKey', UnlockedEscrow::class => 'escrowKey'] as $class => $property) {
    (new ReflectionProperty($class, $property))->isPrivate()
        ? ok(substr($class, strrpos($class, '\\') + 1) . "::\${$property} is private")
        : ko(substr($class, strrpos($class, '\\') + 1) . "::\${$property} is reachable");
}

// -----------------------------------------------------------------------------

section('Called with a known key inside, no method gives it back');

$admin = SelfDataGuard::generateAdminRecoveryKey('admin passphrase for the key bench');
$now   = new DateTimeImmutable();
$built = (new UserVault())->register('user-keys', 'keys-password-0001', 'keys-memorized', 'cheval agrafe batterie correct moulin ivoire');
$vault = $built['unlocked'];
$K     = heldKey($vault);
$field = $vault->encryptField('note', 'une note');
$created = $vault->createEscrow($admin['publicKey'], $now);

$probes = [
    'UnlockedVault::generation()'   => static fn () => $vault->generation(),
    'UnlockedVault::encryptField()' => static fn () => $vault->encryptField('note', 'une note'),
    'UnlockedVault::decryptField()' => static fn () => $vault->decryptField('note', $field),
    'UnlockedVault::sealLock()'     => static fn () => $vault->sealLock(Lock::Password, 'keys-password-0002'),
    'UnlockedVault::createEscrow()' => static fn () => $vault->createEscrow($admin['publicKey'], $now)['record'],
    'UnlockedVault::isLocked()'     => static fn () => $vault->isLocked(),
];
foreach ($probes as $name => $call) {
    !carries($call(), $K)
        ? ok("{$name}: the master key is not in what it returns")
        : ko("{$name} returns the master key");
}
!carries($vault->openEscrow($created['record']), $K) && !carries($created['unlocked'], $K)
    ? ok('UnlockedVault::createEscrow() and openEscrow(): the escrow they hand out carries its own key, not the master key')
    : ko('an escrow handed out by the vault carries the master key');

$escrow = $created['unlocked'];
$E      = heldKey($escrow);
$secret = $escrow->encryptField('contact', 'contact de secours');
foreach ([
    'UnlockedEscrow::encryptField()' => static fn () => $escrow->encryptField('contact', 'x'),
    'UnlockedEscrow::decryptField()' => static fn () => $escrow->decryptField('contact', $secret),
    'UnlockedEscrow::sealToAdmin()'  => static fn () => $escrow->sealToAdmin($admin['publicKey']),
    'UnlockedEscrow::isLocked()'     => static fn () => $escrow->isLocked(),
] as $name => $call) {
    !carries($call(), $E)
        ? ok("{$name}: the escrow key is not in what it returns")
        : ko("{$name} returns the escrow key");
}

$dg = new SelfDataGuard(new SqliteAdapter('sqlite::memory:'), random_bytes(32));
$old = $dg->register('user-arch-keys', 'arch-keys-password-01');
$dg->setFields($old, ['note' => 'archivée']);
$dg->setEscrowFields($old, $admin['publicKey'], ['contact' => 'secours']);
$l3 = $dg->reEnroll($dg->generationOf('user-arch-keys'), 'arch-keys-password-02');
$opened = $dg->openArchive($l3['unlocked'], $l3['archiveId'], Lock::Password, 'arch-keys-password-01');
$A = heldKey((fn (): UnlockedVault => $this->session)->call($opened));
foreach ([
    'UnlockedArchive::userId()'        => static fn () => $opened->userId(),
    'UnlockedArchive::decryptFields()' => static fn () => $opened->decryptFields(array_map(
        static fn (array $f): string => $f['ciphertext'], $opened->archive->privateFields)),
    'UnlockedArchive::openEscrow()'    => static fn () => $opened->openEscrow($opened->archive->escrow),
] as $name => $call) {
    !carries($call(), $A)
        ? ok("{$name}: the archive's master key is not in what it returns")
        : ko("{$name} returns the archive's master key");
}

// -----------------------------------------------------------------------------

section('No public method of the object layer takes a key');


$found = [];
foreach ([UnlockedVault::class, UnlockedEscrow::class, UnlockedArchive::class, UserVault::class, EscrowVault::class,
          EscrowCeremony::class, AdminKey::class, FieldCrypter::class, EscrowFieldCrypter::class, SelfDataGuard::class] as $class) {
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isConstructor()) {
            continue;
        }
        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if (preg_match('/key$/i', $name) === 1 && stripos($name, 'public') === false) {
                $found[] = substr($class, strrpos($class, '\\') + 1) . "::{$method->getName()}(\${$name})";
            }
        }
    }
}
sort($found);
$found === []
    ? ok('none does — the admin key is unsealed and used inside EscrowCeremony, in private methods')
    : ko('a public method takes a key', implode(', ', $found));

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Keys Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
