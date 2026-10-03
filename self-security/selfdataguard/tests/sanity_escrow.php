<?php

declare(strict_types=1);

/**
 * Sanity smoke test for the Escrow compartment (recovery-escrow sub-vault).
 *
 * Run:  php tests/sanity_escrow.php
 * Exit: 0 on success, non-zero on first failure.
 *
 * Validates the two-zone design: private E2E zone untouched, plus a consented
 * admin-recoverable escrow sealed to an admin recovery key (itself passphrase-
 * sealed). Proves compartmentalisation and cold-seizure resistance.
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Escrow\AdminKey;
use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\Escrow\EscrowVault;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
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

// -----------------------------------------------------------------------------

$storage  = new SqliteAdapter('sqlite::memory:');
$blindKey = random_bytes(32);
$dg       = new SelfDataGuard($storage, $blindKey);

const ADMIN_PASS = 'ceremonie-admin-recuperation-tres-longue-2026';

// The escrow plaintexts live here and nowhere else. The at-rest leak check
// below searches the ciphertext for these very values: copied by hand, they
// drift, and a witness that no longer exists in the plaintext turns that
// check green by construction rather than by encryption.
const ESCROW_PLAINTEXT = [
    'contact_secours' => 'alice-secours@example.org',
    'indice_recup'    => 'ville de naissance de mon chat',
];

// -----------------------------------------------------------------------------

section('Admin recovery key generation + passphrase seal');

$admin = SelfDataGuard::generateAdminRecoveryKey(ADMIN_PASS);
isset($admin['publicKey'], $admin['sealedSecret']) ? ok('generate returns publicKey + sealedSecret') : ko('missing keys');
strlen(base64_decode($admin['publicKey'], true) ?: '') === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES
    ? ok('public key is a valid 32-byte box public key') : ko('public key wrong length');
str_starts_with($admin['sealedSecret'], sprintf('%s:%d:%d:', AdminKey::FORMAT_V2, Primitives::ARGON2_OPSLIMIT, Primitives::ARGON2_MEMLIMIT))
    ? ok('sealed secret records its Argon2id profile (v2:ops:mem:salt:blob)') : ko('sealed secret malformed', $admin['sealedSecret']);

$sk = SelfDataGuard::unsealAdminRecoveryKey($admin['sealedSecret'], ADMIN_PASS);
strlen($sk) === SODIUM_CRYPTO_BOX_SECRETKEYBYTES ? ok('unseal returns 32-byte secret key') : ko('unseal wrong length');
sodium_memzero($sk);

try {
    SelfDataGuard::unsealAdminRecoveryKey($admin['sealedSecret'], 'wrong-passphrase');
    ko('unseal with wrong passphrase → should throw');
} catch (RuntimeException) {
    ok('unseal with wrong passphrase → throws (cold-seizure resistance)');
}

// -----------------------------------------------------------------------------

section('User enrolls escrow fields + reads them back');

$session = $dg->register('alice', 'motdepasse-fort', 'sentier-brume-rocher-menthe-fusain');
// Private zone (untouched behaviour)
$dg->setFields($session, ['notes' => 'note privée', 'mots_de_passe' => 'forum:hunter2'], []);
// Escrow zone (consented, admin-recoverable)
$dg->setEscrowFields($session, $admin['publicKey'], ESCROW_PLAINTEXT);

$dg->hasEscrow('alice') ? ok('hasEscrow() true after enrollment') : ko('hasEscrow should be true');
$dg->hasEscrow('inconnu') ? ko('hasEscrow(inconnu) should be false') : ok('hasEscrow(unknown) false');

$asUser = $dg->getEscrowFieldsAsUser($session, ['contact_secours', 'indice_recup']);
$asUser['contact_secours'] === ESCROW_PLAINTEXT['contact_secours'] ? ok('user reads own escrow (contact_secours)') : ko('user escrow read wrong');
$asUser['indice_recup'] === ESCROW_PLAINTEXT['indice_recup'] ? ok('user reads own escrow (indice_recup)') : ko('user escrow read wrong');

// -----------------------------------------------------------------------------

section('2FA path: memorized unlock also opens the escrow');

$viaMemorized = $dg->loginWithMemorized('alice', 'sentier-brume-rocher-menthe-fusain');
$escViaMem = $dg->getEscrowFieldsAsUser($viaMemorized, ['contact_secours']);
$escViaMem['contact_secours'] === ESCROW_PLAINTEXT['contact_secours']
    ? ok('escrow readable via memorized-secret session (2FA)') : ko('escrow not readable via memorized');

// -----------------------------------------------------------------------------

section('Admin recovery: unseal → open escrow (same data as the user)');

$sk = SelfDataGuard::unsealAdminRecoveryKey($admin['sealedSecret'], ADMIN_PASS);
$asAdmin = $dg->getEscrowFieldsAsAdmin('alice', $sk, $admin['publicKey'], ['contact_secours']);
$asAdmin['contact_secours'] === ESCROW_PLAINTEXT['contact_secours']
    ? ok('admin opens escrow with recovery key → same contact_secours') : ko('admin escrow read wrong');

// -----------------------------------------------------------------------------

section('COMPARTMENTALISATION: escrow_key cannot read the private zone');

// Obtain the escrow_key the way the admin does, then try it on a private field.
$escrowRecord = $storage->loadEscrow('alice');
$unlockedEscrow = (new EscrowVault())->unlockAsAdmin($escrowRecord, $sk, $admin['publicKey']);
$escrowKey = $unlockedEscrow->getEscrowKey();

$privCipher = $storage->loadFields('alice', ['mots_de_passe'])['mots_de_passe'];
try {
    Primitives::decrypt(EncryptedBlob::fromBase64($privCipher), $escrowKey, aad: 'alice|mots_de_passe');
    ko('escrow_key decrypted a private field — COMPARTMENTALISATION BROKEN (CRITICAL)');
} catch (RuntimeException) {
    ok('escrow_key cannot decrypt private field (mots_de_passe) — private zone out of admin reach');
}
$unlockedEscrow->lock();
sodium_memzero($sk);

// -----------------------------------------------------------------------------

section('Cold seizure: wrong admin key cannot open the sealed escrow');

$escrowRecord = $storage->loadEscrow('alice');
$attackerKp   = sodium_crypto_box_keypair();
$attackerSk   = sodium_crypto_box_secretkey($attackerKp);
$attackerPk   = base64_encode(sodium_crypto_box_publickey($attackerKp));
try {
    (new EscrowVault())->unlockAsAdmin($escrowRecord, $attackerSk, $attackerPk);
    ko('sealed escrow opened with attacker key (CRITICAL)');
} catch (RuntimeException) {
    ok('sealed escrow refuses any key but the admin recovery key');
}

// -----------------------------------------------------------------------------

section('At-rest: escrow ciphertext leaks no plaintext');

$rawEscrow = $storage->loadEscrowFields('alice');

// Nothing to read is not the same as nothing to find: an empty set would walk
// the loop below without a single comparison and still report opacity.
count($rawEscrow) === count(ESCROW_PLAINTEXT)
    ? ok('at-rest inspection has every enrolled field to read (' . count($rawEscrow) . ')')
    : ko('nothing to inspect at rest', count($rawEscrow) . ' stored vs ' . count(ESCROW_PLAINTEXT) . ' enrolled');

$leaked = [];
foreach ($rawEscrow as $field => $ct) {
    foreach (ESCROW_PLAINTEXT as $plaintext) {
        if (str_contains($ct, $plaintext)) {
            $leaked[] = $field;
        }
    }
}
$leaked === []
    ? ok('escrow fields are opaque at rest (no plaintext)')
    : ko('escrow plaintext leaked at rest', implode(', ', array_unique($leaked)));

// -----------------------------------------------------------------------------

section('The admin passphrase has a floor when sealing, not when unsealing');

try {
    SelfDataGuard::generateAdminRecoveryKey(str_repeat('a', UserVault::PASSWORD_MIN_LEN - 1));
    ko('an admin key was sealed under a passphrase below the floor');
} catch (InvalidArgumentException) {
    ok('sealing under a passphrase below the floor is refused');
}
// A key sealed before the floor existed, under a short passphrase, still opens.
$court = 'court';
$selCourt = Primitives::randomBytes(Primitives::SALT_LEN);
$cleCourt = Primitives::deriveFromPassword($court, $selCourt);
$skCourt = sodium_crypto_box_secretkey(sodium_crypto_box_keypair());
$scelleCourt = base64_encode($selCourt) . ':'
    . Primitives::encrypt($skCourt, $cleCourt, aad: AdminKey::SEAL_AAD)->toBase64();
AdminKey::unseal($scelleCourt, $court) === $skCourt
    ? ok('a key sealed earlier under a short passphrase still unseals')
    : ko('the floor locked out a key sealed earlier');

section('The sealed admin key carries its Argon2id profile');

// The format before 0.6.0, "salt:blob", opens under the frozen legacy profile.
$selAncien = Primitives::randomBytes(Primitives::SALT_LEN);
$skAncien  = sodium_crypto_box_secretkey(sodium_crypto_box_keypair());
$scelleAncien = base64_encode($selAncien) . ':' . Primitives::encrypt(
    $skAncien,
    Primitives::deriveFromPassword(ADMIN_PASS, $selAncien, Primitives::LEGACY_OPSLIMIT, Primitives::LEGACY_MEMLIMIT),
    aad: AdminKey::SEAL_AAD
)->toBase64();
AdminKey::unseal($scelleAncien, ADMIN_PASS) === $skAncien
    ? ok('a key sealed before 0.6.0 (salt:blob) still unseals')
    : ko('the pre-0.6.0 format no longer unseals');

// A key sealed under another profile opens by the profile it records.
$OPS = 2;
$MEM = 32 * 1024 * 1024;
($OPS !== Primitives::ARGON2_OPSLIMIT || $MEM !== Primitives::ARGON2_MEMLIMIT)
    ? ok('fixture: the other profile differs from the current constants')
    : ko('fixture: the other profile equals the current constants — the next check proves nothing');
$selAutre = Primitives::randomBytes(Primitives::SALT_LEN);
$skAutre  = sodium_crypto_box_secretkey(sodium_crypto_box_keypair());
$scelleAutre = implode(':', [AdminKey::FORMAT_V2, $OPS, $MEM, base64_encode($selAutre), Primitives::encrypt(
    $skAutre, Primitives::deriveFromPassword(ADMIN_PASS, $selAutre, $OPS, $MEM), aad: AdminKey::SEAL_AAD
)->toBase64()]);
try {
    AdminKey::unseal($scelleAutre, ADMIN_PASS) === $skAutre
        ? ok('a v2 key sealed under another profile unseals by the profile it records')
        : ko('the v2 key opened to another secret key');
} catch (Throwable $e) {
    ko('a v2 key sealed under another profile no longer unseals', $e->getMessage());
}

foreach ([
    'v9:3:67108864:' . base64_encode($selAutre) . ':SDG2.x' => 'a newer format version',
    'v2:trois:67108864:' . base64_encode($selAutre) . ':SDG2.x' => 'a v2 key with a non-numeric profile',
    'v2:3:' . base64_encode($selAutre) . ':SDG2.x' => 'a v2 key missing a field',
] as $scelle => $cas) {
    try {
        AdminKey::unseal($scelle, ADMIN_PASS);
        ko("{$cas} was read");
    } catch (InvalidArgumentException $e) {
        ok("{$cas} is refused before any derivation");
    }
}

section('wrap_admin names its account');

$skLien = SelfDataGuard::unsealAdminRecoveryKey($admin['sealedSecret'], ADMIN_PASS);
$ev = new EscrowVault();
EscrowVault::isAccountBound($storage->loadEscrow('alice'))
    ? ok('a new escrow seals wrap_admin with its account')
    : ko('a new escrow still seals the bare key');

$bob = $dg->register('bob', 'motdepasse-bob-01', 'bob-memorized');
$dg->setEscrowFields($bob, $admin['publicKey'], ['contact_secours' => 'bob-secours@example.org']);
$recAlice = $storage->loadEscrow('alice');
$recBob   = $storage->loadEscrow('bob');
$echange  = new EscrowRecord(
    userId: 'bob', wrapUser: $recBob->wrapUser, wrapAdmin: $recAlice->wrapAdmin,
    createdAt: $recBob->createdAt, updatedAt: $recBob->updatedAt
);
try {
    $ev->unlockAsAdmin($echange, $skLien, $admin['publicKey']);
    ko("alice's wrap_admin, moved into bob's record, opened as bob's");
} catch (RuntimeException $e) {
    str_contains($e->getMessage(), 'another account')
        ? ok("alice's wrap_admin moved into bob's record is refused, named as such")
        : ko('refused for another reason', $e->getMessage());
}

// An escrow sealed before 0.6.0: wrap_admin holds the bare key.
$carol   = $dg->register('carol', 'motdepasse-carol-01', 'carol-memorized');
$neuf    = $ev->create($carol, $admin['publicKey']);
$ancien  = new EscrowRecord(
    userId: 'carol', wrapUser: $neuf['record']->wrapUser,
    wrapAdmin: sodium_crypto_box_seal($neuf['unlocked']->getEscrowKey(), base64_decode($admin['publicKey'])),
    createdAt: $neuf['record']->createdAt, updatedAt: $neuf['record']->updatedAt
);
$storage->saveEscrow($ancien, $carol->vaultSalt);
!EscrowVault::isAccountBound($storage->loadEscrow('carol'))
    ? ok('fixture: a pre-0.6.0 wrap_admin is told apart by its length')
    : ko('fixture: the pre-0.6.0 wrap_admin reads as bound');
$ev->unlockAsAdmin($storage->loadEscrow('carol'), $skLien, $admin['publicKey'])->getEscrowKey() === $neuf['unlocked']->getEscrowKey()
    ? ok('a pre-0.6.0 wrap_admin still opens')
    : ko('a pre-0.6.0 wrap_admin opened to another key');

$dg->setEscrowFields($carol, $admin['publicKey'], ['contact_secours' => 'carol-secours@example.org']);
EscrowVault::isAccountBound($storage->loadEscrow('carol'))
    && $dg->getEscrowFieldsAsAdmin('carol', $skLien, $admin['publicKey']) === ['contact_secours' => 'carol-secours@example.org']
    ? ok("the holder's next escrow write re-seals wrap_admin with the account, and the admin still reads it")
    : ko('the pre-0.6.0 wrap_admin was not re-sealed on write, or no longer opens');
sodium_memzero($skLien);

section('Delete cascade removes escrow');

$dg->delete('alice');
$storage->loadEscrow('alice') === null ? ok('deleteVault() cascades to escrow envelope') : ko('escrow envelope survived delete');
$storage->loadEscrowFields('alice') === [] ? ok('deleteVault() cascades to escrow fields') : ko('escrow fields survived delete');

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Escrow Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
