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
use Pierroons\SelfRecover\Recovery\Escalade;

// Le profil d'écriture de `sr-kdf.js` se dit « celui de SelfDataGuard ».
$js = (string) file_get_contents(__DIR__ . '/../client/sr-kdf.js');
preg_match('/const PROFIL = Object\.freeze\(\{[^}]*\bt: (\d+), m: (\d+), p: (\d+), dkLen: (\d+)/', $js, $p);

$controles = [
    'minimum du mot de passe : Escalade (caractères) = UserVault (octets)'
        => [Escalade::MOT_DE_PASSE_MINIMUM, UserVault::PASSWORD_MIN_LEN],
    'sr-kdf.js t = Primitives::ARGON2_OPSLIMIT'
        => [(int) ($p[1] ?? -1), Primitives::ARGON2_OPSLIMIT],
    'sr-kdf.js m (Kio) = Primitives::ARGON2_MEMLIMIT (octets) / 1024'
        => [(int) ($p[2] ?? -1), intdiv(Primitives::ARGON2_MEMLIMIT, 1024)],
    'sr-kdf.js dkLen = Primitives::KEY_LEN'
        => [(int) ($p[4] ?? -1), Primitives::KEY_LEN],
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
