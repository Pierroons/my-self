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
 * Une borne de régime (un plancher ou un plafond du service, le plus long
 * palier d'une démonstration) se mesure : le banc construit la valeur promise,
 * puis la valeur d'un cran plus faible, qui doit lever, et d'un cran plus dure,
 * qui doit passer. Une borne qu'on retire de `Config` fait rougir le banc,
 * même quand la valeur promise reste celle de `Config::prod()`.
 *
 * Usage  : php tests/sanity_promesses.php
 *          SM_SRC=<copie de src> php tests/sanity_promesses.php    (canari)
 * Sortie : 0 si tous les contrôles passent, 1 sinon.
 */

declare(strict_types=1);

$racine = getenv('SM_SRC') ?: dirname(__DIR__) . '/src';   // surchargeable : canari
require $racine . '/autoload.php';

use Pierroons\SelfModerate\Config;
use Pierroons\SelfModerate\Messagerie;
use Pierroons\SelfModerate\Usage;

$echecs = 0; $reussites = 0;
function ok(string $m): void  { global $reussites; echo "  ✓ $m\n"; $reussites++; }
function nok(string $m): void { global $echecs; fwrite(STDERR, "  ✗ $m\n"); $echecs++; }

/** La promesse vaut pour les deux jeux quand le README ne les distingue pas. */
function deuxJeux(string $champ, mixed $valeur): array
{
    return [[$champ, 'demo', $valeur], [$champ, 'prod', $valeur]];
}

/**
 * Le jeu, ou le refus de le construire : une `Config` qui lève se lit comme un
 * écart sur le champ, pas comme une panne du banc.
 */
function jeu(string $jeu): Config|string
{
    try {
        return $jeu === 'prod' ? Config::prod(Messagerie::DM) : Config::demo();
    } catch (Throwable $e) {
        return "Config::$jeu() lève : " . $e->getMessage();
    }
}

/** `Config` accepte-t-elle ces réglages ? Seul son refus de valeur répond non. */
function construit(array $reglages): bool
{
    try {
        new Config(...$reglages);
        return true;
    } catch (InvalidArgumentException) {
        return false;
    }
}

/**
 * La borne qu'un hôte de service ne franchit pas : la valeur de `Config::prod()`
 * si `Config` l'accepte, refuse un cran plus faible et accepte un cran plus dur.
 * Un plancher durcit en montant, un plafond en descendant.
 */
function borneDuService(string $sens, string $champ): mixed
{
    $prod = jeu('prod');
    if (is_string($prod)) {
        return $prod;
    }
    $base = get_object_vars($prod);
    $v = $base[$champ];
    $durcir = $sens === 'plancher' ? 1 : -1;
    $tient = construit([...$base, $champ => $v])
        && !construit([...$base, $champ => $v - $durcir])
        && construit([...$base, $champ => $v + $durcir]);
    return $tient ? $v : "aucun $sens à $v en service";
}

/** Le quorum aux seuils : allumé, la config de service se construit ; éteint, elle lève. */
function quorumExigeEnService(): bool|string
{
    $prod = jeu('prod');
    if (is_string($prod)) {
        return $prod;
    }
    $base = get_object_vars($prod);
    return construit([...$base, 'quorumAuxSeuils' => true]) && !construit([...$base, 'quorumAuxSeuils' => false]);
}

/**
 * La plus longue remontée qui rend en démonstration un vote retiré au plancher : du plancher (`banA`) au
 * seuil du vote, en intervalles de convalescence, mesurée autour de `PALIER_DEMONSTRATION_MAX`.
 */
function retraitDemonstrationMax(): int|string
{
    $demo = jeu('demo');
    if (is_string($demo)) {
        return $demo;
    }
    $max = Config::PALIER_DEMONSTRATION_MAX;
    $base = get_object_vars($demo);
    $pas = intdiv($max, $base['perteDroitDeVoteSous'] - $base['banA']);
    return construit([...$base, 'intervalleConvalescenceSecondes' => $pas])
        && !construit([...$base, 'intervalleConvalescenceSecondes' => $pas + 1])
        ? $max
        : "aucune borne du retrait du vote à $max s en démonstration";
}

/** Le plus long palier qu'une démonstration accepte, mesuré autour de `PALIER_DEMONSTRATION_MAX`. */
function palierDemonstrationMax(): int|string
{
    $demo = jeu('demo');
    if (is_string($demo)) {
        return $demo;
    }
    if (!defined(Config::class . '::PALIER_DEMONSTRATION_MAX')) {
        return 'Config::PALIER_DEMONSTRATION_MAX absente';
    }
    $max = Config::PALIER_DEMONSTRATION_MAX;
    $base = get_object_vars($demo);
    return construit([...$base, 'dureesBan' => [$max]]) && !construit([...$base, 'dureesBan' => [$max + 1]])
        ? $max
        : "aucune borne à $max s en démonstration";
}

/**
 * Ce que le jeu applique. Un nom terminé par `()` est une méthode de `Config`,
 * ou une valeur qui s'en déduit ; `plancherService(x)` et `plafondService(x)`
 * sont les bornes que `Config` tient en service.
 */
function applique(string $jeu, string $champ): mixed
{
    $config = jeu($jeu);
    if (is_string($config)) {
        return $config;
    }
    $deduites = [
        'premierPalier()'           => static fn (Config $c): int => $c->dureesBan[0],
        'plancherDuDernierPalier()' => static fn (Config $c): int => $c->plancherDe(0, $c->dureesBan[count($c->dureesBan) - 1]),
        'quorumDuVote()'            => static fn (Config $c): int => $c->voixPourSanction($c->perteDroitDeVoteSous - 1),
        'quorumDuBan()'             => static fn (Config $c): int => $c->voixPourSanction($c->banA),
        'quorumExigeEnService()'    => static fn (): bool|string => quorumExigeEnService(),
        'palierDemonstrationMax()'  => static fn (): int|string => palierDemonstrationMax(),
        'retraitDemonstrationMax()' => static fn (): int|string => retraitDemonstrationMax(),
    ];
    return match (true) {
        isset($deduites[$champ])     => $deduites[$champ]($config),
        preg_match('/^(plancher|plafond)Service\((\w+)\)$/', $champ, $borne) === 1 => borneDuService($borne[1], $borne[2]),
        str_ends_with($champ, '()')  => $config->{substr($champ, 0, -2)}(),
        default                      => $config->$champ,
    };
}

function egal(mixed $a, mixed $b): bool
{
    return is_float($a) || is_float($b) ? abs((float) $a - (float) $b) < 1e-9 : $a === $b;
}

/** Une valeur lisible dans un écart : `json_encode` ne sait pas écrire un `enum` pur. */
function montrer(mixed $v): string
{
    return $v instanceof UnitEnum
        ? substr(strrchr('\\' . $v::class, '\\'), 1) . '::' . $v->name
        : (string) json_encode($v, JSON_UNESCAPED_UNICODE);
}

/**
 * Une ligne de la table des bornes du service. Le mot dit le sens (« au moins »,
 * un plancher ; « au plus », un plafond) ; l'unité suit le suffixe du champ.
 */
function ligneDeBorne(string $fichier, string $champ): array
{
    $unites = str_ends_with($champ, 'Secondes')
        ? ['h' => 3600, 'hour' => 3600, 'hours' => 3600, 'min' => 60, 'j' => 86400, 'days' => 86400]
        : (str_ends_with($champ, 'Jours') ? ['j' => 1, 'days' => 1] : ['' => 1]);
    $sens = ['au moins' => 'plancher', 'at least' => 'plancher', 'au plus' => 'plafond', 'at most' => 'plafond'];
    return [$fichier, '/^\| `' . $champ . '` \| [^|\n]+ \| (au moins|au plus|at least|at most) \*\*(\d+)(?: (h|j|min|hours?|days))?\*\* \|$/mu',
        static fn ($m) => [[$sens[$m[1]] . "Service($champ)", 'prod', (int) $m[2] * $unites[$m[3] ?? '']],
                           [$champ, 'prod', (int) $m[2] * $unites[$m[3] ?? '']]]];
}

$h = 3600; $j = 86400;
$bornesDuService = ['ageMinPourVoterSecondes', 'ageEtabliSecondes', 'fenetreSalveSecondes', 'arrondiFinBanSecondes',
    'fenetreFarmingJours', 'fenetreMeuteJours', 'ciblesParVotantMax', 'farmingDownvotesMax', 'farmingUpvotesMax',
    'salveVotantsMin', 'messagesPourVoterAvantAge'];

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
    ['README.fr.md', '/^- Score < (\d+) → \*\*perte du droit de vote\*\* : la machine le retire en démonstration, l\'inscrit au journal \(`vote_retire`\), et la remontée le rend en une heure de calme au plus ; en service, elle signale la perte due à l\'arbitre/mu',
        fn ($m) => deuxJeux('perteDroitDeVoteSous', (int) $m[1])],
    ['README.fr.md', '/^- Score = (\d+) → \*\*ban temporaire, gradué\*\* : (\d+) → (\d+) → (\d+) min en démonstration \(`Config::demo\(\)`\)\. Le dernier palier/mu',
        fn ($m) => [...deuxJeux('banA', (int) $m[1]),
                    ['dureesBan', 'demo', [$m[2] * 60, $m[3] * 60, $m[4] * 60]]]],
    ['README.fr.md', '/En service, la machine ne bannit pas : la réputation au plancher se signale à l\'arbitre comme un ban dû \(`reputation_zero`\)\. Les paliers de `Config::prod\(\)`, (\d+) h → (\d+) j → (\d+) j, n\'y tombent pas seuls ; le premier donne la durée par défaut d\'un ban d\'arbitre et d\'une suspension du vote \(`dureeBanAdmin\(\)`\)/u',
        fn ($m) => [['dureesBan', 'prod', [$m[1] * $h, $m[2] * $j, $m[3] * $j]], ['dureeBanAdmin()', 'prod', $m[1] * $h]]],
    ['README.fr.md', '/^- Après un ban automatique purgé : score remis à \*\*(\d+)\*\*, strikes préservés/mu',
        fn ($m) => deuxJeux('reputationInitiale', (int) $m[1])],
    ['README.fr.md', '/avant (un tiers) de la peine \*\*en cours\*\* \(`Config::\$plancherFraction`\), une levée exige/u',
        fn ($m) => deuxJeux('plancherFraction', ['un tiers' => 1 / 3][$m[1]])],
    ['README.fr.md', '/sinon le plancher d\'un premier ban d\'(un) jour durerait (dix) jours/u',
        fn ($m) => [['premierPalier()', 'prod', ['un' => 1][$m[1]] * $j], ['plancherDuDernierPalier()', 'prod', ['dix' => 10][$m[2]] * $j]]],
    ['README.fr.md', '/\*\*Le quorum aux seuils\*\* \(`quorumAuxSeuils`\) : la perte du vote et le ban automatique exigent que la chute soit portée par assez de votants \*\*sans lien entre eux\*\* — \*\*(\d+)\*\* pour la perte du vote, \*\*(\d+)\*\* pour le ban/u',
        fn ($m) => [...deuxJeux('quorumDuVote()', (int) $m[1]), ...deuxJeux('quorumDuBan()', (int) $m[2])]],
    ['README.fr.md', '/Deux comptes sont liés quand chacun a écrit à l\'autre en privé sur les \*\*(\d+) derniers jours\*\* \(`fenetreMeuteJours`\)/u',
        fn ($m) => deuxJeux('fenetreMeuteJours', (int) $m[1])],
    ['README.fr.md', '/Son travail est borné à \*\*(\d+)\*\* sommets lus \(`quorumTravailMax`\), et la lecture des liens à \*\*(\d+)\*\*\s+recherches dans l\'index de la paire \(`quorumRecherchesMax`\)/u',
        fn ($m) => [...deuxJeux('quorumTravailMax', (int) $m[1]), ...deuxJeux('quorumRecherchesMax', (int) $m[2])]],
    ['README.fr.md', '/le quorum aux seuils \(`quorumAuxSeuils`, \*\*(actif|éteint)\*\* par défaut/u',
        fn ($m) => deuxJeux('quorumAuxSeuils', $m[1] === 'actif')],
    ['README.fr.md', '/\*\*Anti-Sybil\*\* : délai sur les nouveaux comptes, sauf s\'ils ont déjà publié : \*\*(\d+) h\*\* en service, aligné sur la période d\'échauffement décrite par \[Bi-Self\]\(\.\.\/README\.fr\.md\), \*\*(\d+) min\*\* en démonstration\. Le refus/u',
        fn ($m) => [['ageMinPourVoterSecondes', 'prod', $m[1] * $h], ['ageMinPourVoterSecondes', 'demo', $m[2] * 60]]],
    ['README.fr.md', '/\*\*Comptes récents\*\* : un compte de moins de \*\*(\d+) jours\*\* en service, \*\*(\d+) h\*\* en démonstration \(`ageEtabliSecondes`\)/u',
        fn ($m) => [['ageEtabliSecondes', 'prod', $m[1] * 86400], ['ageEtabliSecondes', 'demo', $m[2] * $h]]],
    ['README.fr.md', '/\*\*Salve rapide\*\* : plusieurs votants contre la même cible dans une fenêtre courte \((\d+) min en service, (\d+) min en démonstration\), liés ou non ; un vote que la part des comptes récents a neutralisé n\'y entre pas\. Ce n\'est pas une coordination/u',
        fn ($m) => [['fenetreSalveSecondes', 'prod', $m[1] * 60], ['fenetreSalveSecondes', 'demo', $m[2] * 60]]],
    ['README.fr.md', '/\*\*Farming\*\* : au-delà de (\d+) votes positifs d\'un même votant vers un même membre en (\d+) jours, les suivants sont neutralisés ; même plafond pour les votes négatifs, contre l\'érosion lente/u',
        fn ($m) => [...deuxJeux('farmingUpvotesMax', (int) $m[1]), ...deuxJeux('farmingDownvotesMax', (int) $m[1]),
                    ...deuxJeux('fenetreFarmingJours', (int) $m[2])]],
    ['README.fr.md', '/Et un votant ne vise pas plus de (\d+) membres distincts sur la même fenêtre \(`ciblesParVotantMax`\) : au-delà/u',
        fn ($m) => deuxJeux('ciblesParVotantMax', (int) $m[1])],
    ['README.fr.md', '/\*\*Vote croisé\*\* : deux membres qui votent l\'un contre l\'autre en moins de \*\*(\d+) h\*\* en service, \*\*(\d+) min\*\* en démonstration \(`fenetreCroiseeSecondes`\)/u',
        fn ($m) => [['fenetreCroiseeSecondes', 'prod', $m[1] * $h], ['fenetreCroiseeSecondes', 'demo', $m[2] * 60]]],
    ['README.fr.md', '/au plus \*\*(\d+)\*\* critiques distincts sur \*\*(\d+) jours\*\* \(`ciblesParVotantMax`, `fenetreFarmingJours`\), et \*\*(\d+)\*\* votes contre chacun d\'eux \(`farmingDownvotesMax`\)/u',
        fn ($m) => [...deuxJeux('ciblesParVotantMax', (int) $m[1]), ...deuxJeux('fenetreFarmingJours', (int) $m[2]),
                    ...deuxJeux('farmingDownvotesMax', (int) $m[3])]],
    ['README.fr.md', '/Sa fin s\'arrondit dès la pose, au multiple suivant de \*\*(\d+) h\*\* en service, \*\*(\d+) min\*\* en démonstration \(`arrondiFinBanSecondes`\)/u',
        fn ($m) => [['arrondiFinBanSecondes', 'prod', $m[1] * $h], ['arrondiFinBanSecondes', 'demo', $m[2] * 60]]],

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
    ['README.md', '/^- Score < (\d+) → \*\*loss of voting rights\*\*: the machine removes it in demonstration, records it in the journal \(`vote_retire`\), and the recovery gives it back within one quiet hour at most; in service, it flags the loss as due to the arbiter/mu',
        fn ($m) => deuxJeux('perteDroitDeVoteSous', (int) $m[1])],
    ['README.md', '/^- Score = (\d+) → \*\*temporary ban, graduated\*\*: (\d+) → (\d+) → (\d+) min in demonstration \(`Config::demo\(\)`\)\. The last tier/mu',
        fn ($m) => [...deuxJeux('banA', (int) $m[1]),
                    ['dureesBan', 'demo', [$m[2] * 60, $m[3] * 60, $m[4] * 60]]]],
    ['README.md', '/In service, the machine does not ban: a reputation at the floor is flagged to the arbiter as a ban due \(`reputation_zero`\)\. The tiers of `Config::prod\(\)`, (\d+) h → (\d+) d → (\d+) d, do not fall there on their own; the first gives the default length of an arbiter\'s ban and of a voting suspension \(`dureeBanAdmin\(\)`\)/u',
        fn ($m) => [['dureesBan', 'prod', [$m[1] * $h, $m[2] * $j, $m[3] * $j]], ['dureeBanAdmin()', 'prod', $m[1] * $h]]],
    ['README.md', '/^- After a served automatic ban: score back to \*\*(\d+)\*\*, strikes kept/mu',
        fn ($m) => deuxJeux('reputationInitiale', (int) $m[1])],
    ['README.md', '/before (one third) of the \*\*running\*\* penalty \(`Config::\$plancherFraction`\), a lift requires/u',
        fn ($m) => deuxJeux('plancherFraction', ['one third' => 1 / 3][$m[1]])],
    ['README.md', '/otherwise the floor of a (one)-day first ban would last (ten) days/u',
        fn ($m) => [['premierPalier()', 'prod', ['one' => 1][$m[1]] * $j], ['plancherDuDernierPalier()', 'prod', ['ten' => 10][$m[2]] * $j]]],
    ['README.md', '/\*\*The quorum at the thresholds\*\* \(`quorumAuxSeuils`\): losing the right to vote and the automatic ban require the fall to be carried by enough voters \*\*with no link between them\*\* — \*\*(\d+)\*\* for losing the right to vote, \*\*(\d+)\*\* for a ban/u',
        fn ($m) => [...deuxJeux('quorumDuVote()', (int) $m[1]), ...deuxJeux('quorumDuBan()', (int) $m[2])]],
    ['README.md', '/Two accounts are linked when each has written privately to the other over the \*\*last (\d+) days\*\* \(`fenetreMeuteJours`\)/u',
        fn ($m) => deuxJeux('fenetreMeuteJours', (int) $m[1])],
    ['README.md', '/Its work\s+is bounded at \*\*(\d+)\*\* vertices read \(`quorumTravailMax`\), and the reading of links at \*\*(\d+)\*\* searches\s+in the pair index \(`quorumRecherchesMax`\)/u',
        fn ($m) => [...deuxJeux('quorumTravailMax', (int) $m[1]), ...deuxJeux('quorumRecherchesMax', (int) $m[2])]],
    ['README.md', '/the quorum at the thresholds \(`quorumAuxSeuils`, \*\*(on|off)\*\* by default/u',
        fn ($m) => deuxJeux('quorumAuxSeuils', $m[1] === 'on')],
    ['README.md', '/\*\*Anti-Sybil\*\*: a delay on new accounts, unless they have already posted: \*\*(\d+) hours\*\* in service, matching the warm-up period described by \[Bi-Self\]\(\.\.\/README\.md\), \*\*(\d+) minutes\*\* in demonstration\. The refusal/u',
        fn ($m) => [['ageMinPourVoterSecondes', 'prod', $m[1] * $h], ['ageMinPourVoterSecondes', 'demo', $m[2] * 60]]],
    ['README.md', '/\*\*Recent accounts\*\*: an account less than \*\*(\d+) days\*\* old in service, \*\*(\d+) hour\*\* in demonstration \(`ageEtabliSecondes`\)/u',
        fn ($m) => [['ageEtabliSecondes', 'prod', $m[1] * 86400], ['ageEtabliSecondes', 'demo', $m[2] * $h]]],
    ['README.md', '/\*\*Fast burst\*\*: several voters against the same target within a short window \((\d+) min in service, (\d+) min in demonstration\), linked or not; a vote the recent accounts\' share neutralised does not enter it\. That is not coordination/u',
        fn ($m) => [['fenetreSalveSecondes', 'prod', $m[1] * 60], ['fenetreSalveSecondes', 'demo', $m[2] * 60]]],
    ['README.md', '/\*\*Farming\*\*: beyond (\d+) positive votes from one voter to one member within (\d+) days, the next ones are neutralised; the same cap applies to negative votes, against the slow erosion/u',
        fn ($m) => [...deuxJeux('farmingUpvotesMax', (int) $m[1]), ...deuxJeux('farmingDownvotesMax', (int) $m[1]),
                    ...deuxJeux('fenetreFarmingJours', (int) $m[2])]],
    ['README.md', '/And a voter targets no more than (\d+) distinct members over the same window \(`ciblesParVotantMax`\): beyond that/u',
        fn ($m) => deuxJeux('ciblesParVotantMax', (int) $m[1])],
    ['README.md', '/\*\*Crossed votes\*\*: two members who vote against each other within \*\*(\d+) hours\*\* in service, \*\*(\d+) minutes\*\* in demonstration \(`fenetreCroiseeSecondes`\)/u',
        fn ($m) => [['fenetreCroiseeSecondes', 'prod', $m[1] * $h], ['fenetreCroiseeSecondes', 'demo', $m[2] * 60]]],
    ['README.md', '/at most \*\*(\d+)\*\* distinct critics over \*\*(\d+) days\*\* \(`ciblesParVotantMax`, `fenetreFarmingJours`\), and \*\*(\d+)\*\* votes against each of them \(`farmingDownvotesMax`\)/u',
        fn ($m) => [...deuxJeux('ciblesParVotantMax', (int) $m[1]), ...deuxJeux('fenetreFarmingJours', (int) $m[2]),
                    ...deuxJeux('farmingDownvotesMax', (int) $m[3])]],
    ['README.md', '/Its end is rounded as soon as the ban is set, to the next multiple of \*\*(\d+) hour\*\* in service, \*\*(\d+) minute\*\* in demonstration \(`arrondiFinBanSecondes`\)/u',
        fn ($m) => [['arrondiFinBanSecondes', 'prod', $m[1] * $h], ['arrondiFinBanSecondes', 'demo', $m[2] * 60]]],
    ['README.fr.md', '/au \*\*(\d+)ᵉ ban d\'arbitre\*\* sur un même compte \(`bansArbitreSignal`\)/u',
        fn ($m) => deuxJeux('bansArbitreSignal', (int) $m[1])],
    ['README.md', '/at the \*\*(\d+)(?:st|nd|rd|th) arbiter ban\*\* on the same account \(`bansArbitreSignal`\)/u',
        fn ($m) => deuxJeux('bansArbitreSignal', (int) $m[1])],
    ['README.fr.md', '/pour \*\*(\d+)\*\* comptes au plus \(`balayageFiletMax`\)/u',
        fn ($m) => deuxJeux('balayageFiletMax', (int) $m[1])],
    ['README.md', '/for at most \*\*(\d+)\*\* accounts \(`balayageFiletMax`\)/u',
        fn ($m) => deuxJeux('balayageFiletMax', (int) $m[1])],
    ['README.fr.md', '/^\*\*Ce que l\'hôte règle en plus des seuils\*\*, toujours dans `Config` : sa messagerie et son usage, à déclarer .*?\(`messagesPourVoterAvantAge`, \*\*(\d+)\*\* par défaut.*?\(`motifsVotantsMin`, \*\*(\d+)\*\* par défaut/mu',
        fn ($m) => [...deuxJeux('messagesPourVoterAvantAge', (int) $m[1]), ...deuxJeux('motifsVotantsMin', (int) $m[2])]],
    ['README.md', '/^\*\*What the host sets besides the thresholds\*\*, still in `Config`: its messaging and its use, to be declared .*?\(`messagesPourVoterAvantAge`, \*\*(\d+)\*\* by default.*?\(`motifsVotantsMin`, \*\*(\d+)\*\* by default/mu',
        fn ($m) => [...deuxJeux('messagesPourVoterAvantAge', (int) $m[1]), ...deuxJeux('motifsVotantsMin', (int) $m[2])]],

    // Les deux régimes : la déclaration de chaque jeu, la borne de la
    // démonstration, et les bornes du service, ligne par ligne.
    ['README.fr.md', '/^`Config::prod\(Messagerie::DM\)` et `Config::prod\(Messagerie::HORS_MOTEUR\)` donnent les valeurs de service ;\s+`Config::demo\(\)` donne celles de démonstration, avec la messagerie `Messagerie::(DM|HORS_MOTEUR)`\./mu',
        fn ($m) => [['usage', 'prod', Usage::SERVICE], ['usage', 'demo', Usage::DEMONSTRATION],
                    ['messagerie', 'demo', constant(Messagerie::class . '::' . $m[1])]]],
    ['README.md', '/^`Config::prod\(Messagerie::DM\)` and `Config::prod\(Messagerie::HORS_MOTEUR\)` give the service values; `Config::demo\(\)`\s+gives the demonstration ones, with the `Messagerie::(DM|HORS_MOTEUR)` messaging\./mu',
        fn ($m) => [['usage', 'prod', Usage::SERVICE], ['usage', 'demo', Usage::DEMONSTRATION],
                    ['messagerie', 'demo', constant(Messagerie::class . '::' . $m[1])]]],
    ['README.fr.md', '/Un palier de `dureesBan` y dure \*\*(\d+) h\*\* au plus\s+\(`Config::PALIER_DEMONSTRATION_MAX`\)/u',
        fn ($m) => [['palierDemonstrationMax()', 'demo', $m[1] * $h]]],
    ['README.md', '/A `dureesBan` tier there lasts \*\*(\d+) hour\*\* at most\s+\(`Config::PALIER_DEMONSTRATION_MAX`\)/u',
        fn ($m) => [['palierDemonstrationMax()', 'demo', $m[1] * $h]]],
    ['README.fr.md', '/la remontée du plancher\s+\(`banA`\) au seuil du vote y tient en \*\*(\d+) h\*\* au plus de convalescence/u',
        fn ($m) => [['retraitDemonstrationMax()', 'demo', $m[1] * $h]]],
    ['README.md', '/the climb from the floor \(`banA`\) to the voting\s+threshold fits in \*\*(\d+) hour\*\* at most of recovery there/u',
        fn ($m) => [['retraitDemonstrationMax()', 'demo', $m[1] * $h]]],
    ['README.fr.md', '/^\| `quorumAuxSeuils` \| [^|\n]+ \| \*\*(toujours actif|éteignable)\*\* \|$/mu',
        fn ($m) => [['quorumExigeEnService()', 'prod', $m[1] === 'toujours actif']]],
    ['README.md', '/^\| `quorumAuxSeuils` \| [^|\n]+ \| \*\*(always on|can be turned off)\*\* \|$/mu',
        fn ($m) => [['quorumExigeEnService()', 'prod', $m[1] === 'always on']]],
    ...array_map(static fn (string $c): array => ligneDeBorne('README.fr.md', $c), $bornesDuService),
    ...array_map(static fn (string $c): array => ligneDeBorne('README.md', $c), $bornesDuService),
];

// Les champs dont le README parle sans en donner la valeur, et la phrase qui
// en parle ainsi, dans chaque langue.
$nonPromis = [
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
        nok("$champ ($jeu) : $fichier promet " . montrer($promis) . ", Config::$jeu() applique " . montrer($valeur));
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
$portes = array_filter(array_keys($portes), static fn (string $c): bool => !str_contains($c, '('));
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
