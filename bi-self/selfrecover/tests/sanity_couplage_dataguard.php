<?php

declare(strict_types=1);

/**
 * Les valeurs que SelfRecover et SelfDataGuard disent partager.
 *
 * Les deux bibliothèques ne dépendent pas l'une de l'autre, et on n'ajoute pas
 * une dépendance pour partager une constante : chacune déclare sa valeur, et ce
 * banc les tient d'accord — le motif de `scripts/check-plancher-secret.sh`.
 *
 * Usage : php bi-self/selfrecover/tests/sanity_couplage_dataguard.php
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/../../../self-security/selfdataguard/src/autoload.php';

use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Vault\UserVault;
use Pierroons\SelfRecover\Diceware\Wordlist;
use Pierroons\SelfRecover\Recovery\Escalade;
use Pierroons\SelfRecover\Recovery\Recovery;

// Le profil d'écriture de `sr-kdf.js` se dit « celui de SelfDataGuard ».
$js = (string) file_get_contents(__DIR__ . '/../client/sr-kdf.js');
preg_match('/const PROFIL = Object\.freeze\(\{[^}]*\bt: (\d+), m: (\d+), p: (\d+), dkLen: (\d+)/', $js, $p);

// La passphrase que SelfRecover accepte doit être celle qui ouvre le coffre :
// SelfDataGuard scelle sa serrure « passphrase » sur la chaîne normalisée.
// Les entrées visent ce qu'un copier-coller apporte — espace insécable,
// cadratin, NEL sur un octet — et ce qu'un client mal formé enverrait.
$entrees = [
    '  cheval   agrafe batterie  correct ',
    "cheval\tagrafe\nbatterie\r\ncorrect",
    "cheval\vagrafe\fbatterie",
    "cheval\0agrafe",
    "cheval\u{00A0}agrafe",
    "cheval\u{2003}agrafe",
    "cheval\x85agrafe",
    "\xff\xfe cheval agrafe",
    '',
    ' ',
];
$accords = count(array_filter($entrees, static fn (string $e): bool =>
    UserVault::normalizePassphrase($e) === Recovery::normaliserPassphrase($e)));
$stables = count(array_filter($entrees, static fn (string $e): bool =>
    UserVault::normalizePassphrase(UserVault::normalizePassphrase($e)) === UserVault::normalizePassphrase($e)));
$engendrees = array_map(static fn (): string => Recovery::engendrerPassphrase(), range(1, 20));
$normales = count(array_filter($engendrees, static fn (string $p): bool => UserVault::normalizePassphrase($p) === $p));
$plusCourte = Recovery::MOTS_PASSPHRASE * min(array_map('strlen', Wordlist::load('en')))
    + Recovery::MOTS_PASSPHRASE - 1;

$controles = [
    'minimum du mot de passe : Escalade (caractères) = UserVault (octets)'
        => [Escalade::MOT_DE_PASSE_MINIMUM, UserVault::PASSWORD_MIN_LEN],
    'sr-kdf.js t = Primitives::ARGON2_OPSLIMIT'
        => [(int) ($p[1] ?? -1), Primitives::ARGON2_OPSLIMIT],
    'sr-kdf.js m (Kio) = Primitives::ARGON2_MEMLIMIT (octets) / 1024'
        => [(int) ($p[2] ?? -1), intdiv(Primitives::ARGON2_MEMLIMIT, 1024)],
    'sr-kdf.js dkLen = Primitives::KEY_LEN'
        => [(int) ($p[4] ?? -1), Primitives::KEY_LEN],
    'normalisation de la passphrase : entrées où SelfDataGuard = SelfRecover'
        => [$accords, count($entrees)],
    'normalisation de SelfDataGuard idempotente : entrées stables'
        => [$stables, count($entrees)],
    'passphrases engendrées par SelfRecover déjà normalisées'
        => [$normales, count($engendrees)],
    'plus courte passphrase engendrable ≥ plancher de scellement (1 = oui)'
        => [(int) ($plusCourte >= UserVault::PASSWORD_MIN_LEN), 1],
];

$echecs = 0;
foreach ($controles as $intitule => [$ici, $labas]) {
    $ok = $ici === $labas;
    $echecs += $ok ? 0 : 1;
    printf("  %s %s — %d / %d\n", $ok ? '✓' : '✗', $intitule, $ici, $labas);
}
printf("%s — %d/%d valeurs partagées d'accord\n", $echecs === 0 ? 'OK' : 'ÉCHEC',
    count($controles) - $echecs, count($controles));

exit($echecs === 0 ? 0 : 1);
