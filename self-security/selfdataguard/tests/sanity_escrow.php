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
use Pierroons\SelfDataGuard\Escrow\EscrowVault;
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

$storage  = new SqliteAdapter('sqlite::memory:');
$blindKey = random_bytes(32);
$dg       = new SelfDataGuard($storage, $blindKey);

const ADMIN_PASS = 'ceremonie-admin-recuperation-tres-longue-2026';

// -----------------------------------------------------------------------------

section('Admin recovery key generation + passphrase seal');

$admin = SelfDataGuard::generateAdminRecoveryKey(ADMIN_PASS);
isset($admin['publicKey'], $admin['sealedSecret']) ? ok('generate returns publicKey + sealedSecret') : ko('missing keys');
strlen(base64_decode($admin['publicKey'], true) ?: '') === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES
    ? ok('public key is a valid 32-byte box public key') : ko('public key wrong length');
str_contains($admin['sealedSecret'], ':') ? ok('sealed secret has salt:blob layout') : ko('sealed secret malformed');

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
$dg->setEscrowFields($session, $admin['publicKey'], [
    'contact_secours' => 'alice-secours@example.org',
    'indice_recup'    => 'ville de naissance de mon chat',
]);

$dg->hasEscrow('alice') ? ok('hasEscrow() true after enrollment') : ko('hasEscrow should be true');
$dg->hasEscrow('inconnu') ? ko('hasEscrow(inconnu) should be false') : ok('hasEscrow(unknown) false');

$asUser = $dg->getEscrowFieldsAsUser($session, ['contact_secours', 'indice_recup']);
$asUser['contact_secours'] === 'alice-secours@example.org' ? ok('user reads own escrow (contact_secours)') : ko('user escrow read wrong');
$asUser['indice_recup'] === 'ville de naissance de mon chat' ? ok('user reads own escrow (indice_recup)') : ko('user escrow read wrong');

// -----------------------------------------------------------------------------

section('2FA path: memorized unlock also opens the escrow');

$viaMemorized = $dg->loginWithMemorized('alice', 'sentier-brume-rocher-menthe-fusain');
$escViaMem = $dg->getEscrowFieldsAsUser($viaMemorized, ['contact_secours']);
$escViaMem['contact_secours'] === 'alice-secours@example.org'
    ? ok('escrow readable via memorized-secret session (2FA)') : ko('escrow not readable via memorized');

// -----------------------------------------------------------------------------

section('Admin recovery: unseal → open escrow (same data as the user)');

$sk = SelfDataGuard::unsealAdminRecoveryKey($admin['sealedSecret'], ADMIN_PASS);
$asAdmin = $dg->getEscrowFieldsAsAdmin('alice', $sk, $admin['publicKey'], ['contact_secours']);
$asAdmin['contact_secours'] === 'alice-secours@example.org'
    ? ok('admin opens escrow with recovery key → same contact_secours') : ko('admin escrow read wrong');

// -----------------------------------------------------------------------------

section('COMPARTMENTALISATION: escrow_key cannot read the private zone');

// Obtain the escrow_key the way the admin does, then try it on a private field.
$escrowRecord = $storage->loadEscrow('alice');
$unlockedEscrow = (new EscrowVault())->unlockAsAdmin($escrowRecord, $sk, $admin['publicKey']);
$escrowKey = $unlockedEscrow->getEscrowKey();

$privCipher = $storage->loadFields('alice', ['mots_de_passe'])['mots_de_passe'];
try {
    Primitives::aesGcmDecrypt(EncryptedBlob::fromBase64($privCipher), $escrowKey, aad: 'alice|mots_de_passe');
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
$leak = false;
foreach ($rawEscrow as $ct) {
    if (str_contains($ct, 'proton.me') || str_contains($ct, 'chat')) {
        $leak = true;
    }
}
$leak ? ko('escrow plaintext leaked at rest') : ok('escrow fields are opaque at rest (no plaintext)');

// -----------------------------------------------------------------------------

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
