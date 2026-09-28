<?php

declare(strict_types=1);

/**
 * La taille du sel de compte, déclarée à quatre endroits qui ne peuvent pas se lire.
 *
 * Le navigateur l'engendre (`sr-derive.js`), la dérivation du mémo la contrôle
 * (`sr-kdf.js`), le serveur la vérifie (`Recovery::SEL_OCTETS`) et la démo duo
 * la contraint en base (`CHECK`). Si la taille changeait côté serveur seul, les
 * faux sels anti-oracle se distingueraient des vrais, et l'oracle d'existence se
 * rouvrirait sans bruit.
 *
 * Usage : php bi-self/selfrecover/tests/sanity_forme_sel.php
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfRecover\Recovery\Recovery;

$racine = __DIR__ . '/../../..';
$lire = static function (string $fichier, string $motif) use ($racine): int {
    return preg_match($motif, (string) @file_get_contents("$racine/$fichier"), $m) ? (int) $m[1] : -1;
};

$octets = Recovery::SEL_OCTETS;
$controles = [
    'sr-derive.js SEL_OCTETS'
        => [$lire('bi-self/selfrecover/client/sr-derive.js', '/const SEL_OCTETS = (\d+);/'), $octets],
    'sr-kdf.js SEL_OCTETS'
        => [$lire('bi-self/selfrecover/client/sr-kdf.js', '/const SEL_OCTETS = (\d+);/'), $octets],
    'duo selfrecover.sql CHECK (hexadécimaux)'
        => [$lire('demo/bi-self-duo/schemas/selfrecover.sql', '/length\(recovery_salt\) = (\d+)/'), 2 * $octets],
    'estSelCompte accepte un sel engendré'
        => [(int) Recovery::estSelCompte(bin2hex(random_bytes($octets))), 1],
    'estSelCompte refuse un caractère de moins'
        => [(int) Recovery::estSelCompte(str_repeat('a', 2 * $octets - 1)), 0],
    'estSelCompte refuse les majuscules'
        => [(int) Recovery::estSelCompte(str_repeat('A', 2 * $octets)), 0],
    'estSelCompte refuse un caractère non hexadécimal'
        => [(int) Recovery::estSelCompte(str_repeat('a', 2 * $octets - 1) . 'g'), 0],
];

$echecs = 0;
foreach ($controles as $intitule => [$lu, $attendu]) {
    $ok = $lu === $attendu;
    $echecs += $ok ? 0 : 1;
    printf("  %s %s — %d / %d\n", $ok ? '✓' : '✗', $intitule, $lu, $attendu);
}
printf("%s — %d/%d\n", $echecs === 0 ? 'OK' : 'ÉCHEC', count($controles) - $echecs, count($controles));

exit($echecs === 0 ? 0 : 1);
