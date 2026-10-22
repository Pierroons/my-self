<?php

/**
 * Les seuils que le README promet sont-ils ceux que `Config` applique ?
 *
 * La promesse vit dans le README, la valeur dans `Config` : ce banc lit l'une
 * et l'autre, et ne garde aucune troisième copie du chiffre. Les autres bancs
 * lisent leurs seuils dans `Moderate::config()` pour viser la borne là où elle
 * est — ils suivent donc la config par construction, et ne verraient pas
 * qu'une valeur promise a changé.
 *
 * 🔑 Les porteurs sont une **liste explicite** de phrases, pas une recherche de
 * chiffres dans la prose : une heuristique sur un texte mesure son style. Une
 * phrase reformulée fait rougir son porteur — on met alors la liste à jour,
 * en relisant ce que la phrase promet désormais.
 *
 * Chaque champ de `Config` est soit porté, soit déclaré non promis : un champ
 * ajouté sans être classé fait rougir le banc.
 *
 * Usage  : php tests/sanity_promesses.php
 *          SM_SRC=<copie de src> php tests/sanity_promesses.php    (canari)
 * Sortie : 0 si tous les contrôles passent, 1 sinon.
 */

declare(strict_types=1);

$racine = getenv('SM_SRC') ?: dirname(__DIR__) . '/src';   // surchargeable : canari
require $racine . '/autoload.php';

use Pierroons\SelfModerate\Config;

$echecs = 0; $reussites = 0;
function ok(string $m): void  { global $reussites; echo "  ✓ $m\n"; $reussites++; }
function nok(string $m): void { global $echecs; fwrite(STDERR, "  ✗ $m\n"); $echecs++; }

/** La promesse vaut pour les deux jeux quand le README ne les distingue pas. */
function deuxJeux(string $champ, mixed $valeur): array
{
    return [[$champ, 'demo', $valeur], [$champ, 'prod', $valeur]];
}

/** Ce que le jeu applique. Un nom terminé par `()` est une méthode de `Config`. */
function applique(string $jeu, string $champ): mixed
{
    $config = $jeu === 'prod' ? Config::prod() : Config::demo();
    return str_ends_with($champ, '()') ? $config->{substr($champ, 0, -2)}() : $config->$champ;
}

function egal(mixed $a, mixed $b): bool
{
    return is_float($a) || is_float($b) ? abs((float) $a - (float) $b) < 1e-9 : $a === $b;
}

$h = 3600; $j = 86400;

// [fichier, phrase, lecture] — `lecture` rend, depuis les captures, les valeurs
// promises sous la forme [champ, jeu, valeur].
$porteurs = [
    ['README.fr.md', '/Chaque utilisateur démarre à \*\*(\d+)\*\*/u',
        fn ($m) => deuxJeux('reputationInitiale', (int) $m[1])],
    ['README.fr.md', '/Score plafonné à \*\*(\d+)\*\*/u',
        fn ($m) => deuxJeux('reputationMax', (int) $m[1])],
    ['README.fr.md', '/\*\*Convalescence\*\* : sous (\d+), l\'état est posé et le score remonte tout seul \*\*jusqu\'à (\d+)\*\*/u',
        fn ($m) => [...deuxJeux('perteDroitDeVoteSous', (int) $m[1]), ...deuxJeux('sortieConvalescence()', (int) $m[2])]],
    ['README.fr.md', '/Le droit de vote revient à (\d+), l\'état se lève à (\d+)/u',
        fn ($m) => [...deuxJeux('perteDroitDeVoteSous', (int) $m[1]), ...deuxJeux('sortieConvalescence()', (int) $m[2])]],
    ['README.fr.md', '/Score < (\d+) → \*\*perte du droit de vote\*\*/u',
        fn ($m) => deuxJeux('perteDroitDeVoteSous', (int) $m[1])],
    ['README.fr.md', '/Score = (\d+) → \*\*ban temporaire, gradué\*\* : (\d+) h → (\d+) j → (\d+) j en service \(`Config::prod\(\)`\), (\d+) → (\d+) → (\d+) min en démonstration/u',
        fn ($m) => [...deuxJeux('banA', (int) $m[1]),
                    ['dureesBan', 'prod', [$m[2] * $h, $m[3] * $j, $m[4] * $j]],
                    ['dureesBan', 'demo', [$m[5] * 60, $m[6] * 60, $m[7] * 60]]]],
    ['README.fr.md', '/avant (un tiers) de la peine \*\*en cours\*\*/u',
        fn ($m) => deuxJeux('plancherFraction', ['un tiers' => 1 / 3][$m[1]])],
    ['README.fr.md', '/qu\'un seul rang par (\d+) heures/u',
        fn ($m) => deuxJeux('meuteEpisodeCooldown', $m[1] * $h)],
    ['README.fr.md', '/\*\*Anti-Sybil\*\* : [^\n]*?\*\*(\d+) h\*\* en service, [^\n]*?\*\*(\d+) min\*\* en démonstration/u',
        fn ($m) => [['ageMinPourVoterSecondes', 'prod', $m[1] * $h], ['ageMinPourVoterSecondes', 'demo', $m[2] * 60]]],
    ['README.fr.md', '/(deux) votants \*\*liés entre eux\*\* qui frappent la même cible sur (\d+) jours/u',
        fn ($m) => [...deuxJeux('meuteLiensMin', ['deux' => 2][$m[1]]), ...deuxJeux('fenetreMeuteJours', (int) $m[2])]],
    ['README.fr.md', '/Au-delà de (\d+) votants sur une cible \(`meuteVotantsMax`\)/u',
        fn ($m) => deuxJeux('meuteVotantsMax', (int) $m[1])],
    ['README.fr.md', '/\*\*Salve rapide\*\* : [^\n]*?\((\d+) min en service, (\d+) min en démonstration\)/u',
        fn ($m) => [['fenetreSalveSecondes', 'prod', $m[1] * 60], ['fenetreSalveSecondes', 'demo', $m[2] * 60]]],
    ['README.fr.md', '/au-delà de (\d+) votes positifs d\'un même votant vers un même membre en (\d+) jours, les suivants sont neutralisés ; même plafond pour les votes négatifs/u',
        fn ($m) => [...deuxJeux('farmingUpvotesMax', (int) $m[1]), ...deuxJeux('farmingDownvotesMax', (int) $m[1]),
                    ...deuxJeux('fenetreFarmingJours', (int) $m[2])]],
    ['README.fr.md', '/un votant ne vise pas plus de (\d+) membres distincts sur la même fenêtre \(`ciblesParVotantMax`\)/u',
        fn ($m) => deuxJeux('ciblesParVotantMax', (int) $m[1])],

    ['README.md', '/Every user starts at \*\*(\d+)\*\*/u',
        fn ($m) => deuxJeux('reputationInitiale', (int) $m[1])],
    ['README.md', '/Score is capped at \*\*(\d+)\*\*/u',
        fn ($m) => deuxJeux('reputationMax', (int) $m[1])],
    ['README.md', '/\*\*Recovery\*\*: below (\d+) the state is set and the score climbs back on its own \*\*up to (\d+)\*\*/u',
        fn ($m) => [...deuxJeux('perteDroitDeVoteSous', (int) $m[1]), ...deuxJeux('sortieConvalescence()', (int) $m[2])]],
    ['README.md', '/Voting rights return at (\d+), the state lifts at (\d+)/u',
        fn ($m) => [...deuxJeux('perteDroitDeVoteSous', (int) $m[1]), ...deuxJeux('sortieConvalescence()', (int) $m[2])]],
    ['README.md', '/Score < (\d+) → \*\*loss of voting rights\*\*/u',
        fn ($m) => deuxJeux('perteDroitDeVoteSous', (int) $m[1])],
    ['README.md', '/Score = (\d+) → \*\*temporary ban, graduated\*\*: (\d+) h → (\d+) d → (\d+) d in service \(`Config::prod\(\)`\), (\d+) → (\d+) → (\d+) min in demonstration/u',
        fn ($m) => [...deuxJeux('banA', (int) $m[1]),
                    ['dureesBan', 'prod', [$m[2] * $h, $m[3] * $j, $m[4] * $j]],
                    ['dureesBan', 'demo', [$m[5] * 60, $m[6] * 60, $m[7] * 60]]]],
    ['README.md', '/before (one third) of the \*\*running\*\* penalty/u',
        fn ($m) => deuxJeux('plancherFraction', ['one third' => 1 / 3][$m[1]])],
    ['README.md', '/one rank per (\d+) hours at most/u',
        fn ($m) => deuxJeux('meuteEpisodeCooldown', $m[1] * $h)],
    ['README.md', '/\*\*Anti-Sybil\*\*: [^\n]*?\*\*(\d+) hours\*\* in service, [^\n]*?\*\*(\d+) minutes\*\* in demonstration/u',
        fn ($m) => [['ageMinPourVoterSecondes', 'prod', $m[1] * $h], ['ageMinPourVoterSecondes', 'demo', $m[2] * 60]]],
    ['README.md', '/(two) voters \*\*linked to each other\*\* hitting the same target within (\d+) days/u',
        fn ($m) => [...deuxJeux('meuteLiensMin', ['two' => 2][$m[1]]), ...deuxJeux('fenetreMeuteJours', (int) $m[2])]],
    ['README.md', '/Beyond (\d+) voters on a target \(`meuteVotantsMax`\)/u',
        fn ($m) => deuxJeux('meuteVotantsMax', (int) $m[1])],
    ['README.md', '/\*\*Fast burst\*\*: [^\n]*?\((\d+) min in service, (\d+) min in demonstration\)/u',
        fn ($m) => [['fenetreSalveSecondes', 'prod', $m[1] * 60], ['fenetreSalveSecondes', 'demo', $m[2] * 60]]],
    ['README.md', '/beyond (\d+) positive votes from one voter to one member within (\d+) days, the next ones are neutralised; the same cap applies to negative votes/u',
        fn ($m) => [...deuxJeux('farmingUpvotesMax', (int) $m[1]), ...deuxJeux('farmingDownvotesMax', (int) $m[1]),
                    ...deuxJeux('fenetreFarmingJours', (int) $m[2])]],
    ['README.md', '/a voter targets no more than (\d+) distinct members over the same window \(`ciblesParVotantMax`\)/u',
        fn ($m) => deuxJeux('ciblesParVotantMax', (int) $m[1])],
];

// Les champs dont le README parle sans en donner la valeur.
$nonPromis = [
    'salveVotantsMin'                 => '« plusieurs votants »',
    'balayagePeriodeSecondes'         => 'nommé dans le schéma attendu, sans durée',
    'dureeBanAdminSecondes'           => 'aucune durée de ban d\'arbitre annoncée',
    'intervalleConvalescenceSecondes' => '« un par intervalle de calme »',
];

// ── 1. Chaque phrase porteuse dit ce que la config applique ─────────────────
$portes = [];
foreach ($porteurs as [$fichier, $phrase, $lecture]) {
    $texte = (string) file_get_contents(dirname(__DIR__) . '/' . $fichier);
    if (preg_match_all($phrase, $texte, $trouvees, PREG_SET_ORDER) !== 1) {
        nok("$fichier : la phrase porteuse est introuvable ou répétée — " . $phrase);
        continue;
    }
    $ecarts = [];
    foreach ($lecture($trouvees[0]) as [$champ, $jeu, $promis]) {
        $portes[$champ] = true;
        $valeur = applique($jeu, $champ);
        if (!egal($promis, $valeur)) {
            $ecarts[] = [$champ, $jeu, $promis, $valeur];
        }
    }
    if ($ecarts === []) {
        ok("$fichier · « " . mb_substr($trouvees[0][0], 0, 60) . ' » : la config applique la valeur promise');
    }
    foreach ($ecarts as [$champ, $jeu, $promis, $valeur]) {
        nok("$champ ($jeu) : $fichier promet " . json_encode($promis) . ", Config::$jeu() applique " . json_encode($valeur));
    }
}

// ── 2. Chaque champ de `Config` est porté ou déclaré non promis ─────────────
$champs = array_map(
    static fn (ReflectionParameter $p): string => $p->getName(),
    (new ReflectionMethod(Config::class, '__construct'))->getParameters()
);
$portes = array_filter(array_keys($portes), static fn (string $c): bool => !str_ends_with($c, '()'));
$orphelins = array_diff($champs, $portes, array_keys($nonPromis));
$deuxFois  = array_intersect($portes, array_keys($nonPromis));
$perimes   = array_diff(array_keys($nonPromis), $champs);
$orphelins === [] && $deuxFois === [] && $perimes === []
    ? ok(count($portes) . ' champs portés par le README, ' . count($nonPromis) . ' déclarés non promis, aucun oublié')
    : nok('classement des champs : non classés ' . json_encode(array_values($orphelins))
        . ', portés ET déclarés non promis ' . json_encode(array_values($deuxFois))
        . ', déclarés mais absents de Config ' . json_encode(array_values($perimes)));

$total = $reussites + $echecs;
echo "\n" . ($echecs === 0
    ? "✅ $total/$total contrôles passés\n"
    : "❌ $echecs échec(s) sur $total\n");
exit($echecs === 0 ? 0 : 1);
