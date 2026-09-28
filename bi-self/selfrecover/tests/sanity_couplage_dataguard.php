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

use Pierroons\SelfDataGuard\Vault\UserVault;
use Pierroons\SelfRecover\Recovery\Escalade;

$accord = Escalade::MOT_DE_PASSE_MINIMUM === UserVault::PASSWORD_MIN_LEN;

printf(
    "%s — minimum du mot de passe : Escalade %d (caractères), UserVault %d (octets)\n",
    $accord ? 'OK' : 'ÉCHEC',
    Escalade::MOT_DE_PASSE_MINIMUM,
    UserVault::PASSWORD_MIN_LEN,
);

exit($accord ? 0 : 1);
