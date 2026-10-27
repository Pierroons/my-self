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
 * chiffres dans la prose : une heuristique sur un texte mesure son style. Chaque
 * motif couvre un passage autour de la valeur : une retouche dans ce passage le
 * fait rougir — on relit alors ce qu'il promet, et on met la liste à jour. Ce
 * qui s'écrit hors du passage n'est pas lu : un qualificatif placé avant le
 * début d'un motif qui n'est pas ancré en début de ligne, une phrase neuve qui
 * annonce un chiffre.
 *
 * Chaque champ de `Config` est soit porté, soit déclaré non promis avec un
 * passage qui en parle sans chiffre ; un chiffre ajouté hors de ce passage
 * n'est pas lu. Un champ ajouté sans être classé fait rougir le banc.
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

/**
 * Ce que le jeu applique. Un nom terminé par `()` est une méthode de `Config`,
 * ou une valeur qui s'en déduit.
 */
function applique(string $jeu, string $champ): mixed
{
    $config = $jeu === 'prod' ? Config::prod() : Config::demo();
    $deduites = [
        'premierPalier()'           => static fn (Config $c): int => $c->dureesBan[0],
        'plancherDuDernierPalier()' => static fn (Config $c): int => $c->plancherDe(0, $c->dureesBan[count($c->dureesBan) - 1]),
    ];
    return match (true) {
        isset($deduites[$champ])     => $deduites[$champ]($config),
        str_ends_with($champ, '()')  => $config->{substr($champ, 0, -2)}(),
        default                      => $config->$champ,
    };
}

function egal(mixed $a, mixed $b): bool
{
    return is_float($a) || is_float($b) ? abs((float) $a - (float) $b) < 1e-9 : $a === $b;
}

$h = 3600; $j = 86400;

// [fichier, phrase, lecture] — `lecture` rend, depuis les captures, les valeurs
// promises sous la forme [champ, jeu, valeur].
$porteurs = [
    ['README.fr.md', '/^- Chaque utilisateur démarre à \*\*(\d+)\*\* \(`Config`\)$/mu',
        fn ($m) => deuxJeux('reputationInitiale', (int) $m[1])],
    ['README.fr.md', '/^- Score plafonné à \*\*(\d+)\*\* \(`Config`\) — pas d\'accumulation de crédit social$/mu',
        fn ($m) => deuxJeux('reputationMax', (int) $m[1])],
    ['README.fr.md', '/\*\*Convalescence\*\* : sous (\d+), l\'état est posé et le score remonte tout seul \*\*jusqu\'à (\d+)\*\*, son point de départ — jamais au-delà\./u',
        fn ($m) => [...deuxJeux('perteDroitDeVoteSous', (int) $m[1]), ...deuxJeux('sortieConvalescence()', (int) $m[2])]],
    ['README.fr.md', '/Le droit de vote revient à (\d+), l\'état se lève à (\d+)$/mu',
        fn ($m) => [...deuxJeux('perteDroitDeVoteSous', (int) $m[1]), ...deuxJeux('sortieConvalescence()', (int) $m[2])]],
    ['README.fr.md', '/Conditionner la remontée à « score < (\d+) » l\'arrête pile au seuil qui rend le droit de vote/u',
        fn ($m) => deuxJeux('perteDroitDeVoteSous', (int) $m[1])],
    ['README.fr.md', '/^- Score < (\d+) → \*\*perte du droit de vote\*\*$/mu',
        fn ($m) => deuxJeux('perteDroitDeVoteSous', (int) $m[1])],
    ['README.fr.md', '/^- Score = (\d+) → \*\*ban temporaire, gradué\*\* : (\d+) h → (\d+) j → (\d+) j en service \(`Config::prod\(\)`\), (\d+) → (\d+) → (\d+) min en démonstration \(`Config::demo\(\)`\)\. Le dernier palier/mu',
        fn ($m) => [...deuxJeux('banA', (int) $m[1]),
                    ['dureesBan', 'prod', [$m[2] * $h, $m[3] * $j, $m[4] * $j]],
                    ['dureesBan', 'demo', [$m[5] * 60, $m[6] * 60, $m[7] * 60]]]],
    ['README.fr.md', '/^- Après un ban automatique purgé : score remis à \*\*(\d+)\*\*, strikes préservés/mu',
        fn ($m) => deuxJeux('reputationInitiale', (int) $m[1])],
    ['README.fr.md', '/avant (un tiers) de la peine \*\*en cours\*\* \(`Config::\$plancherFraction`\), une levée exige/u',
        fn ($m) => deuxJeux('plancherFraction', ['un tiers' => 1 / 3][$m[1]])],
    ['README.fr.md', '/sinon le plancher d\'un premier ban d\'(un) jour durerait (dix) jours/u',
        fn ($m) => [['premierPalier()', 'prod', ['un' => 1][$m[1]] * $j], ['plancherDuDernierPalier()', 'prod', ['dix' => 10][$m[2]] * $j]]],
    ['README.fr.md', '/Un votant ne monte \*\*qu\'un seul rang par (\d+) heures\*\*, comptées depuis le \*\*début\*\*/u',
        fn ($m) => deuxJeux('meuteEpisodeCooldown', $m[1] * $h)],
    ['README.fr.md', '/une meute qui frappe à moins de (\d+) heures d\'intervalle resterait au premier rang/u',
        fn ($m) => deuxJeux('meuteEpisodeCooldown', $m[1] * $h)],
    ['README.fr.md', '/\*\*Anti-Sybil\*\* : délai sur les nouveaux comptes, sauf s\'ils ont déjà publié : \*\*(\d+) h\*\* en service, aligné sur la période d\'échauffement décrite par \[Bi-Self\]\(\.\.\/README\.fr\.md\), \*\*(\d+) min\*\* en démonstration\. Le refus/u',
        fn ($m) => [['ageMinPourVoterSecondes', 'prod', $m[1] * $h], ['ageMinPourVoterSecondes', 'demo', $m[2] * 60]]],
    ['README.fr.md', '/\*\*Meute\*\* : (deux) votants \*\*liés entre eux\*\* qui frappent la même cible sur (\d+) jours → le premier garde/u',
        fn ($m) => [...deuxJeux('meuteLiensMin', ['deux' => 2][$m[1]]), ...deuxJeux('fenetreMeuteJours', (int) $m[2])]],
    ['README.fr.md', '/Au-delà de (\d+) votants sur une cible \(`meuteVotantsMax`\), les plus récents sont examinés/u',
        fn ($m) => deuxJeux('meuteVotantsMax', (int) $m[1])],
    ['README.fr.md', '/\*\*Salve rapide\*\* : plusieurs votants \*\*sans aucun lien\*\* dans une fenêtre courte \((\d+) min en service, (\d+) min en démonstration\)\. Ce n\'est pas une meute/u',
        fn ($m) => [['fenetreSalveSecondes', 'prod', $m[1] * 60], ['fenetreSalveSecondes', 'demo', $m[2] * 60]]],
    ['README.fr.md', '/\*\*Farming\*\* : au-delà de (\d+) votes positifs d\'un même votant vers un même membre en (\d+) jours, les suivants sont neutralisés ; même plafond pour les votes négatifs, contre l\'érosion lente/u',
        fn ($m) => [...deuxJeux('farmingUpvotesMax', (int) $m[1]), ...deuxJeux('farmingDownvotesMax', (int) $m[1]),
                    ...deuxJeux('fenetreFarmingJours', (int) $m[2])]],
    ['README.fr.md', '/Et un votant ne vise pas plus de (\d+) membres distincts sur la même fenêtre \(`ciblesParVotantMax`\) : au-delà/u',
        fn ($m) => deuxJeux('ciblesParVotantMax', (int) $m[1])],

    ['README.md', '/^- Every user starts at \*\*(\d+)\*\* \(configurable\)$/mu',
        fn ($m) => deuxJeux('reputationInitiale', (int) $m[1])],
    ['README.md', '/^- Score is capped at \*\*(\d+)\*\* \(configurable\) — no hoarding social credit$/mu',
        fn ($m) => deuxJeux('reputationMax', (int) $m[1])],
    ['README.md', '/\*\*Recovery\*\*: below (\d+) the state is set and the score climbs back on its own \*\*up to (\d+)\*\*, its starting point — never beyond\./u',
        fn ($m) => [...deuxJeux('perteDroitDeVoteSous', (int) $m[1]), ...deuxJeux('sortieConvalescence()', (int) $m[2])]],
    ['README.md', '/Voting rights return at (\d+), the state lifts at (\d+)$/mu',
        fn ($m) => [...deuxJeux('perteDroitDeVoteSous', (int) $m[1]), ...deuxJeux('sortieConvalescence()', (int) $m[2])]],
    ['README.md', '/Gating recovery on "score < (\d+)" stops it exactly at the threshold that restores voting rights/u',
        fn ($m) => deuxJeux('perteDroitDeVoteSous', (int) $m[1])],
    ['README.md', '/^- Score < (\d+) → \*\*loss of voting rights\*\*$/mu',
        fn ($m) => deuxJeux('perteDroitDeVoteSous', (int) $m[1])],
    ['README.md', '/^- Score = (\d+) → \*\*temporary ban, graduated\*\*: (\d+) h → (\d+) d → (\d+) d in service \(`Config::prod\(\)`\), (\d+) → (\d+) → (\d+) min in demonstration \(`Config::demo\(\)`\)\. The last tier/mu',
        fn ($m) => [...deuxJeux('banA', (int) $m[1]),
                    ['dureesBan', 'prod', [$m[2] * $h, $m[3] * $j, $m[4] * $j]],
                    ['dureesBan', 'demo', [$m[5] * 60, $m[6] * 60, $m[7] * 60]]]],
    ['README.md', '/^- After a served automatic ban: score back to \*\*(\d+)\*\*, strikes kept/mu',
        fn ($m) => deuxJeux('reputationInitiale', (int) $m[1])],
    ['README.md', '/before (one third) of the \*\*running\*\* penalty \(`Config::\$plancherFraction`\), a lift requires/u',
        fn ($m) => deuxJeux('plancherFraction', ['one third' => 1 / 3][$m[1]])],
    ['README.md', '/otherwise the floor of a (one)-day first ban would last (ten) days/u',
        fn ($m) => [['premierPalier()', 'prod', ['one' => 1][$m[1]] * $j], ['plancherDuDernierPalier()', 'prod', ['ten' => 10][$m[2]] * $j]]],
    ['README.md', '/A voter climbs \*\*one rank per (\d+) hours at most\*\*, counted from the \*\*start\*\*/u',
        fn ($m) => deuxJeux('meuteEpisodeCooldown', $m[1] * $h)],
    ['README.md', '/less than (\d+) hours apart would stay at the first rank forever/u',
        fn ($m) => deuxJeux('meuteEpisodeCooldown', $m[1] * $h)],
    ['README.md', '/\*\*Anti-Sybil\*\*: a delay on new accounts, unless they have already posted: \*\*(\d+) hours\*\* in service, matching the warm-up period described by \[Bi-Self\]\(\.\.\/README\.md\), \*\*(\d+) minutes\*\* in demonstration\. The refusal/u',
        fn ($m) => [['ageMinPourVoterSecondes', 'prod', $m[1] * $h], ['ageMinPourVoterSecondes', 'demo', $m[2] * 60]]],
    ['README.md', '/\*\*Pack\*\*: (two) voters \*\*linked to each other\*\* hitting the same target within (\d+) days → the first keeps/u',
        fn ($m) => [...deuxJeux('meuteLiensMin', ['two' => 2][$m[1]]), ...deuxJeux('fenetreMeuteJours', (int) $m[2])]],
    ['README.md', '/Beyond (\d+) voters on a target \(`meuteVotantsMax`\), the most recent are examined/u',
        fn ($m) => deuxJeux('meuteVotantsMax', (int) $m[1])],
    ['README.md', '/\*\*Fast burst\*\*: several voters with \*\*no link at all\*\* within a short window \((\d+) min in service, (\d+) min in demonstration\)\. That is not a pack/u',
        fn ($m) => [['fenetreSalveSecondes', 'prod', $m[1] * 60], ['fenetreSalveSecondes', 'demo', $m[2] * 60]]],
    ['README.md', '/\*\*Farming\*\*: beyond (\d+) positive votes from one voter to one member within (\d+) days, the next ones are neutralised; the same cap applies to negative votes, against the slow erosion/u',
        fn ($m) => [...deuxJeux('farmingUpvotesMax', (int) $m[1]), ...deuxJeux('farmingDownvotesMax', (int) $m[1]),
                    ...deuxJeux('fenetreFarmingJours', (int) $m[2])]],
    ['README.md', '/And a voter targets no more than (\d+) distinct members over the same window \(`ciblesParVotantMax`\): beyond that/u',
        fn ($m) => deuxJeux('ciblesParVotantMax', (int) $m[1])],
    ['README.fr.md', '/au \*\*(\d+)ᵉ ban d\'arbitre\*\* sur un même compte \(`bansArbitreSignal`\)/u',
        fn ($m) => deuxJeux('bansArbitreSignal', (int) $m[1])],
    ['README.md', '/at the \*\*(\d+)(?:st|nd|rd|th) arbiter ban\*\* on the same account \(`bansArbitreSignal`\)/u',
        fn ($m) => deuxJeux('bansArbitreSignal', (int) $m[1])],
];

// Les champs dont le README parle sans en donner la valeur, et la phrase qui
// en parle ainsi, dans chaque langue.
$nonPromis = [
    'salveVotantsMin' => [
        'README.fr.md' => '/\*\*Salve rapide\*\* : plusieurs votants \*\*sans aucun lien\*\*/u',
        'README.md'    => '/\*\*Fast burst\*\*: several voters with \*\*no link at all\*\*/u',
    ],
    'balayagePeriodeSecondes' => [
        'README.fr.md' => '/`mod_sweeps` \(une ligne par tranche de `balayagePeriodeSecondes` déjà balayée ;/u',
        'README.md'    => '/`mod_sweeps` \(one row per `balayagePeriodeSecondes` slot already swept;/u',
    ],
    'dureeBanAdminSecondes' => [
        'README.fr.md' => '/^\| `adminBan\(PDO, id, arbitre, motif\)` · /mu',
        'README.md'    => '/^\| `adminBan\(PDO, id, arbiter, reason\)` · /mu',
    ],
    'intervalleConvalescenceSecondes' => [
        'README.fr.md' => '/la remontée passive en rend un par intervalle de calme$/mu',
        'README.md'    => '/passive recovery gives one back per quiet interval$/mu',
    ],
];

$textes = [];
foreach (['README.fr.md', 'README.md'] as $fichier) {
    $textes[$fichier] = (string) file_get_contents(dirname(__DIR__) . '/' . $fichier);
}

// ── 1. Chaque phrase porteuse dit ce que la config applique ─────────────────
$portes = [];
foreach ($porteurs as [$fichier, $phrase, $lecture]) {
    if (preg_match_all($phrase, $textes[$fichier], $trouvees, PREG_SET_ORDER) !== 1) {
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
        ok("$fichier · « " . mb_substr(ltrim($trouvees[0][0], '- '), 0, 60) . ' » : la config applique la valeur promise');
    }
    foreach ($ecarts as [$champ, $jeu, $promis, $valeur]) {
        nok("$champ ($jeu) : $fichier promet " . json_encode($promis) . ", Config::$jeu() applique " . json_encode($valeur));
    }
}

// ── 2. Un champ non promis en parle toujours sans chiffre ───────────────────
foreach ($nonPromis as $champ => $phrases) {
    $perdues = array_keys(array_filter(
        $phrases,
        static fn (string $phrase, string $fichier): bool => preg_match_all($phrase, $textes[$fichier]) !== 1,
        ARRAY_FILTER_USE_BOTH
    ));
    $perdues === []
        ? ok("$champ : les deux README en parlent sans en donner la valeur")
        : nok("$champ (non promis) : la phrase qui en parle sans chiffre a changé dans " . implode(', ', $perdues)
            . ' — relire ce qu\'elle promet désormais');
}

// ── 3. Chaque champ de `Config` est porté ou déclaré non promis ─────────────
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
