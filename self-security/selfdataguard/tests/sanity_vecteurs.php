<?php

declare(strict_types=1);

/**
 * The three lock keys, against vectors computed by a second implementation.
 *
 * `vecteurs-argon2.json` is written by `vecteurs_argon2.py` (argon2-cffi and the
 * construction written out by hand), not by this library. A change to a context
 * suffix, the salt construction, the passphrase normalisation or the cost profile
 * makes this bench fail, even when every round trip inside the library still
 * agrees with itself.
 *
 * Run:  php tests/sanity_vecteurs.php
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\UserVault;

$failures = 0;
$passes = 0;
function ok(string $l): void { global $passes; $passes++; echo "  ✅ {$l}\n"; }
function ko(string $l, string $d = ''): void { global $failures; $failures++; echo "  ❌ {$l}" . ($d ? " — {$d}" : '') . "\n"; }

$vecteurs = json_decode((string) file_get_contents(__DIR__ . '/vecteurs-argon2.json'), true, flags: JSON_THROW_ON_ERROR);

echo "\n→ The cost profile is the one the vectors were computed with\n";
$vecteurs['profil'] === ['opslimit' => Primitives::ARGON2_OPSLIMIT, 'memlimit' => Primitives::ARGON2_MEMLIMIT]
    ? ok('opslimit and memlimit match the vectors')
    : ko('the cost profile differs from the vectors', json_encode($vecteurs['profil']));

// The composition under test is UserVault's own: which secret, which context,
// which normalisation goes to which derivation.
$deriver = new ReflectionMethod(UserVault::class, 'deriveKey');

foreach ($vecteurs['cas'] as $cas) {
    echo "\n→ {$cas['nom']}\n";
    $sel = (string) hex2bin($cas['sel_compte_hex']);

    UserVault::normalizePassphrase($cas['phrase']) === $cas['phrase_normalisee']
        ? ok('the passphrase normalises as the vector says')
        : ko('the passphrase normalises differently');

    foreach ([
        [Lock::Password, 'mot_de_passe', 'attendu_mot_de_passe'],
        [Lock::Memorized, 'mot_memorise', 'attendu_mot_memorise'],
        [Lock::Passphrase, 'phrase', 'attendu_phrase'],
    ] as [$serrure, $secret, $cle]) {
        $obtenu = bin2hex($deriver->invoke(null, $serrure, $cas[$secret], $sel));
        $obtenu === $cas[$cle]
            ? ok("{$serrure->name}: key matches the second implementation")
            : ko("{$serrure->name}: key differs from the second implementation", $obtenu);
    }
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  Vectors Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
