<?php

declare(strict_types=1);

/**
 * La liste close des étiquettes que cette bibliothèque écrit.
 *
 * `Etiquette::PREFIXES` est ce que `StorageInterface::compterEchecsIp()` reçoit
 * pour ne peser que nos propres portes. Un préfixe déclaré hors de cette liste
 * est donc invisible à ce frein : les échecs qu'il compte ne freinent plus rien,
 * et aucune erreur ne le dit. Ce banc ferme cet angle en demandant la liste à la
 * classe elle-même, par réflexion, plutôt qu'en la recopiant.
 *
 * Usage : php bi-self/selfrecover/tests/sanity_etiquettes.php
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfRecover\Etiquette;

$passes = 0;
$echecs = 0;
$verifier = static function (string $quoi, bool $ok, string $detail = '') use (&$passes, &$echecs): void {
    if ($ok) {
        $passes++;
        echo "  ✅ $quoi\n";

        return;
    }
    $echecs++;
    echo "  ❌ $quoi" . ($detail !== '' ? " — $detail" : '') . "\n";
};

echo "\n▸ Étiquettes — la liste que le frein par origine reçoit\n\n";

$constantes = (new ReflectionClass(Etiquette::class))->getConstants();
$declares   = [];
foreach ($constantes as $nom => $valeur) {
    if (str_starts_with($nom, 'PREFIXE_')) {
        $declares[$nom] = $valeur;
    }
}

$verifier('la classe déclare au moins un préfixe', $declares !== []);

$manquants = array_filter(
    $declares,
    static fn (string $v): bool => !in_array($v, Etiquette::PREFIXES, true)
);
$verifier(
    'chaque constante PREFIXE_* figure dans PREFIXES',
    $manquants === [],
    $manquants === [] ? '' : 'absente(s) : ' . implode(', ', array_keys($manquants))
);

$orphelins = array_filter(
    Etiquette::PREFIXES,
    static fn (string $p): bool => !in_array($p, $declares, true)
);
$verifier(
    'et PREFIXES ne contient rien qui ne soit déclaré',
    $orphelins === [],
    $orphelins === [] ? '' : 'sans constante : ' . implode(', ', $orphelins)
);

$verifier('aucun doublon dans PREFIXES',
    count(Etiquette::PREFIXES) === count(array_unique(Etiquette::PREFIXES)));

// Un préfixe qui en contient un autre rendrait deux compteurs indistinguables
// pour le `LIKE` d'un adaptateur : `l1:` compterait les lignes de `l1-liste:`
// si celui-ci s'écrivait `l1:liste:`.
$chevauchements = [];
foreach (Etiquette::PREFIXES as $a) {
    foreach (Etiquette::PREFIXES as $b) {
        if ($a !== $b && str_starts_with($b, $a)) {
            $chevauchements[] = "$a ⊂ $b";
        }
    }
}
$verifier('aucun préfixe n\'est le début d\'un autre', $chevauchements === [],
    implode(' · ', $chevauchements));

// Les jokers de `LIKE` : un `%` ou un `_` dans un préfixe élargirait le filtre
// d'un adaptateur SQL sans que rien ne le signale.
$jokers = array_filter(
    Etiquette::PREFIXES,
    static fn (string $p): bool => str_contains($p, '%') || str_contains($p, '_')
);
$verifier('aucun préfixe ne porte de joker LIKE', $jokers === [],
    implode(' · ', $jokers));

// `sous()` produit bien une étiquette que le filtre reconnaît : sans ce cas, un
// préfixe correct et un calcul qui l'oublie rendraient tous les deux vert.
$etiquette = Etiquette::sous(Etiquette::PREFIXE_L1, 'alice', 'sel-de-banc');
$verifier('une étiquette fabriquée commence par son préfixe',
    str_starts_with($etiquette, Etiquette::PREFIXE_L1));
$verifier('et elle ne porte pas la valeur en clair',
    !str_contains($etiquette, 'alice'));

// 🔑 L'espace de noms des compteurs est RÉSERVÉ, et c'est ce qui rend un préfixe
// fixe sans danger. Sans cette garde, un compte nommé comme un préfixe voit ses
// propres échecs comptés avec ceux que la bibliothèque a fait payer.
$empietent = array_filter(
    Etiquette::PREFIXES,
    static fn (string $p): bool => !Etiquette::empieteSurUnCompteur($p . 'titulaire'),
);
$verifier('un nom commençant par N\'IMPORTE QUEL préfixe empiète', $empietent === [],
    implode(' · ', $empietent));
$verifier('un nom licite n\'empiète pas', !Etiquette::empieteSurUnCompteur('titulaire'));
$verifier('et la casse ne contourne pas la réserve',
    Etiquette::empieteSurUnCompteur(strtoupper(Etiquette::PREFIXE_L2) . 'X'));

// 🔑 **Trois filtres de console écartent la famille du niveau 3 par `l3:%`** —
// deux dans la couche d'administration du lab, un dans son chiffre public. Ils
// ne tiennent que parce que les DEUX préfixes du niveau 3 commencent par `l3:`.
// Renommer l'un d'eux les ferait cesser d'écarter les dépôts de faisceau sans
// adresse, et chaque dépôt légitime recompterait pour une attaque repoussée —
// sans qu'aucun de ces filtres ne change. Ce cas est le seul endroit où ce
// renommage se voit.
$horsFamille = array_values(array_filter(
    [Etiquette::PREFIXE_L3_OUVRIR, Etiquette::PREFIXE_L3_DEPOT],
    static fn (string $p): bool => !str_starts_with($p, 'l3:'),
));
$verifier('⭐ les deux préfixes du niveau 3 commencent par `l3:`, dont trois filtres dépendent',
    $horsFamille === [], implode(' · ', $horsFamille));

echo "\n" . str_repeat('=', 63) . "\n";
printf("  Étiquettes — %d passés, %d échoués\n", $passes, $echecs);
printf("%s — %d/%d\n", $echecs === 0 ? 'OK' : 'ÉCHEC', $passes, $passes + $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
