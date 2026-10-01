<?php
/**
 * Garde de troncature du catalogue SelfAct — chargé par scraper.php.
 *
 * Hors du scraper, qui moissonne dès qu'on le charge : la règle s'éprouve ici
 * sans réseau (tests/test_garde_catalogue.sh).
 */

declare(strict_types=1);

/** Part minimale de son volume précédent qu'une source doit garder. */
const SELFACT_PLANCHER_SOURCE = 0.8;

/**
 * Les sources du catalogue en place qui perdent plus que le plancher.
 *
 * 🔑 Source par source, pas sur le total : les modèles de lettre ne pèsent
 * qu'une fraction du catalogue, et leur disparition entière passerait sous un
 * seuil global. Une baisse moindre est l'ordinaire d'un site qui retire des
 * démarches — la sonde de fraîcheur ne la signale pas, c'est ce garde qui en
 * répond.
 *
 * @param array<int, array<string, mixed>> $anciens   entrées du catalogue en place
 * @param array<string, int>               $nouveaux  entrées moissonnées, par type
 * @return list<string> une ligne lisible par source tronquée, vide si aucune
 */
function selfact_sources_tronquees(array $anciens, array $nouveaux): array
{
    $avant = [];
    foreach ($anciens as $m) {
        $type = (string) ($m['type'] ?? '');
        $avant[$type] = ($avant[$type] ?? 0) + 1;
    }

    $tronquees = [];
    foreach ($avant as $type => $n) {
        $apres = $nouveaux[$type] ?? 0;
        if ($apres < $n * SELFACT_PLANCHER_SOURCE) {
            $tronquees[] = sprintf('%s %d → %d (-%.0f %%)', $type, $n, $apres, 100 - $apres / $n * 100);
        }
    }
    return $tronquees;
}
