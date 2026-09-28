<?php

declare(strict_types=1);

/**
 * La dérivation des scripts sans navigateur retrouve-t-elle les vecteurs figés ?
 *
 * `sr_derive_like_browser()` recopie en PHP ce que `srDerive()` fait dans le
 * navigateur. `seed.php`, la simulation d'attaque et trois bancs s'en servent :
 * si elle divergeait, les comptes qu'ils peuplent deviendraient irrécupérables
 * depuis le navigateur, sans qu'aucun banc le voie. Les vecteurs sont ceux qui
 * tiennent déjà `derivation.php`, `derivation.js` et l'équivalence du duo.
 *
 * Usage : php demo/lab/tests/sanity_derive_cli.php
 */

require __DIR__ . '/../lib/derive_cli.php';

$doc = json_decode(
    (string) file_get_contents(__DIR__ . '/../../../bi-self/selfrecover/tests/vecteurs-derivation.json'),
    true,
);
$vecteurs = array_filter($doc['vecteurs'] ?? [], static fn (array $v): bool => $v['mode'] === 'hostname');

$echecs = 0;
foreach ($vecteurs as $v) {
    $rendu = sr_derive_like_browser($v['mot'], $v['sel'], $v['materiel']);
    $ok    = str_starts_with($rendu, $v['empreinte']);
    $echecs += $ok ? 0 : 1;
    printf("  %s %s\n", $ok ? '✓' : '✗', $v['quoi']);
}
if ($vecteurs === []) {
    $echecs = 1;
    echo "  ✗ aucun vecteur « hostname » lu : le fichier a-t-il changé de forme ?\n";
}
printf("%s — %d/%d vecteurs\n", $echecs === 0 ? 'OK' : 'ÉCHEC', count($vecteurs) - ($vecteurs === [] ? 0 : $echecs), count($vecteurs));

exit($echecs === 0 ? 0 : 1);
