<?php

/**
 * Le motif de vote reçoit-il une phrase, et refuse-t-il un remplissage ?
 *
 * `validateReason()` est une borne anti-remplissage, pas un examen : elle doit
 * refuser ce qui atteint la longueur sans rien dire, et recevoir ce qu'une
 * personne écrit vraiment — un reproche de modération ou le récit d'une perte,
 * en français ou en anglais, court ou détaillé.
 *
 * ⭐ Le contrôle qui compte est le premier. Une phrase détaillée répète ses
 * mots-outils (« de », « les », « mon ») : une règle qui les compte comme des
 * mots porteurs la refuse, et c'est la personne la plus précise qui se fait
 * renvoyer. Il rougit si la règle redevient un plafond fixe.
 *
 * Les refus sont vérifiés sur leur MESSAGE : un remplissage refusé par une
 * autre règle que celle des répétitions ne mesurerait plus celle-ci.
 *
 * Le contrôle n° 4 est écrit à l'envers : il constate une limite. La mesure
 * suit le mot le plus fréquent, pas la rotation ; le jour où elle la suivra,
 * il doit basculer.
 *
 * Usage  : php tests/sanity_motif.php
 *          SM_SRC=<copie de src> php tests/sanity_motif.php    (canari)
 * Sortie : 0 si tous les contrôles passent, 1 sinon.
 */

declare(strict_types=1);

$racine = getenv('SM_SRC') ?: dirname(__DIR__) . '/src';   // surchargeable : canari
require $racine . '/autoload.php';

use Pierroons\SelfModerate\Moderate;

$echecs = 0; $reussites = 0;
function ok(string $m): void  { global $reussites; echo "  ✓ $m\n"; $reussites++; }
function nok(string $m): void { global $echecs; fwrite(STDERR, "  ✗ $m\n"); $echecs++; }

const REFUS_REPETITION = 'Un mot revient trop souvent : dis ce que tu reproches.';

/** Les cas dont le verdict n'est pas celui attendu, avec ce qu'ils ont reçu. */
function ecarts(array $cas, bool $recevable, ?string $message = null): array
{
    $ecarts = [];
    foreach ($cas as $nom => $texte) {
        [$recu, $pourquoi] = Moderate::validateReason($texte);
        if ($recu !== $recevable || (!$recu && $message !== null && $pourquoi !== $message)) {
            $ecarts[] = $recu ? "$nom (reçu)" : "$nom (« $pourquoi »)";
        }
    }
    return $ecarts;
}

// ── ⭐ 1. Une phrase est reçue, courte ou détaillée ──────────────────────────
// Les détaillées répètent un mot plus de deux fois, comme toute phrase qui
// dépasse une ligne. Les courtes gardent la borne de ne pas se durcir.
$phrases = [
    'modération, courte'          => "Propos racistes répétés dans le fil sur le logement, malgré deux avertissements.",
    'modération, « les » ×3'      => "Il insulte les autres membres dans les commentaires et les messages privés.",
    'modération, « le » ×3'       => "Il relance le même membre dans le fil public puis dans le canal privé.",
    'modération, « de » ×4'       => "Il publie de la publicité pour son site dans chaque fil, depuis trois jours, et de façon de plus en plus insistante.",
    'modération, « messages » ×3' => "Il m'envoie des messages insultants. Ses messages privés sont menaçants, et j'ai gardé les messages.",
    'modération, anglais court'   => "He spams ads in all my DMs and in the chat.",
    'modération, « the » ×6'      => "Spam: the same advert for the same shop, posted in the morning, in the afternoon and in the evening.",
    'perte, courte'               => "mon ordinateur a brule dans l incendie de la maison",
    'perte, « mon » ×3'           => "J ai perdu mon mot de passe, mon mot memorise et mon carnet.",
    'perte, « mon » ×4'           => "J'ai perdu mon téléphone, mon ordinateur, mon carnet et mon sac.",
    // le « 3 » donne à array_count_values() une clé entière
    'perte, récit long'           => "Mon téléphone a été volé le 3 octobre avec mon portefeuille. Le carnet où j'avais noté mes mots était dans le portefeuille. Mon ordinateur est resté chez moi, mais je ne me souviens plus du mot de passe du compte.",
    'perte, « compte » ×3'        => "Je n'arrive plus à entrer dans mon compte. Le compte a été créé il y a deux ans, et j'ai toujours utilisé ce compte depuis le même téléphone, que j'ai perdu.",
];
$e = ecarts($phrases, true);
$e === []
    ? ok('⭐ ' . count($phrases) . ' phrases reçues, courtes ou détaillées, reproches comme récits de perte')
    : nok('⭐ une phrase est refusée : ' . implode(' · ', $e));

// ── 2. Un mot porteur ressassé est refusé ───────────────────────────────────
$porteurs = [
    'trois mots ×4'         => "compte compte compte compte urgent urgent urgent urgent aide aide aide aide",
    '« troll » ×4'          => "troll troll troll, vraiment un gros troll ce type, franchement",
    '« troll » ×4, dilué'   => "troll troll troll troll, ce type pourrit tous les fils de discussion depuis des semaines",
];
$e = ecarts($porteurs, false, REFUS_REPETITION);
$e === []
    ? ok('un mot porteur ressassé est refusé, par la règle des répétitions')
    : nok('un mot porteur ressassé passe : ' . implode(' · ', $e));

// ── 3. Un mot court ressassé est refusé ─────────────────────────────────────
// La part laissée aux mots-outils ne doit pas servir à remplir : ces textes
// passent les quatre autres règles et ne disent qu'un mot.
$courts = [
    '« bof » ×8'  => "bof bof bof bof bof bof bof bof, franchement c'est vraiment nul",
    '« nul » ×6'  => "ce membre est nul nul nul nul vraiment nul nul, franchement pénible",
    '« bon » ×6'  => "bon bon bon bon bon bon, vraiment rien à redire franchement",
];
$e = ecarts($courts, false, REFUS_REPETITION);
$e === []
    ? ok('un mot court ressassé est refusé, par la règle des répétitions')
    : nok('un mot court ressassé passe : ' . implode(' · ', $e));

// ── 4. Constat : des mots qui se relaient passent ───────────────────────────
// Écrit à l'envers. Chaque mot reste sous sa part ; c'est leur rotation qui
// remplit, et la mesure ne la suit pas. Le motif reste lisible pour la
// personne visée : il ne cache rien, il est pauvre. Ce contrôle doit basculer
// le jour où la rotation sera mesurée.
$relais = [
    'quatre mots porteurs ×2' => "spam scam fake bidon spam scam fake bidon",
    '« il est nul » ×5'        => "il est nul il est nul il est nul il est nul il est vraiment nul, franchement",
];
$e = ecarts($relais, true);
$e === []
    ? ok('constat : des mots qui se relaient passent — la mesure ne suit pas la rotation')
    : nok('des mots qui se relaient sont refusés — la rotation est mesurée, ou la règle s\'est durcie : réécrire ce constat — ' . implode(' · ', $e));

// ── 5. Un motif a une longueur maximale, et le refus la dit ─────────────────
// Sans borne haute, un motif d'un mégaoctet passerait et s'afficherait en entier
// sur le profil de la personne visée. Des mots tous distincts : seules les
// bornes de longueur jugent ces textes. La borne en clair, et non la constante :
// un contrôle qui lit la borne qu'il mesure se décale avec elle.
$borne = 1000;
$texteLong = static fn (int $n): string => mb_substr(implode(' ', array_map(static fn (int $k): string => "terme$k", range(1, 400))), 0, $n);
[$recuMax]                  = Moderate::validateReason($texteLong($borne));
[$recuTrop, $pourquoiTrop]  = Moderate::validateReason($texteLong($borne + 1));
$recuMax && !$recuTrop && str_contains($pourquoiTrop, (string) $borne)
    ? ok("un motif de $borne caractères passe, un de plus est refusé, et le refus dit la borne")
    : nok('borne haute du motif : ' . json_encode([$recuMax, $recuTrop, $pourquoiTrop ?? null], JSON_UNESCAPED_UNICODE));

// ── 6. Un motif a une longueur minimale, et le refus la dit ─────────────────
// La même phrase des deux côtés de la borne : elle passe les quatre autres
// règles, seule la longueur la juge. Le refus est comparé en entier — il doit
// venir de cette règle-là, et dire ce qui manque.
$plancher = 40;
$phrase = 'Propos racistes répétés dans le fil hier';
[$recuMin]                    = Moderate::validateReason($phrase);
[$recuCourt, $pourquoiCourt]  = Moderate::validateReason(mb_substr($phrase, 0, $plancher - 1));
mb_strlen($phrase) === $plancher && $recuMin && !$recuCourt
    && $pourquoiCourt === "Explique en $plancher caractères au moins : il en manque 1."
    ? ok("un motif de $plancher caractères passe, un de moins est refusé par la règle de longueur, et le refus dit ce qui manque")
    : nok('borne basse du motif : ' . json_encode([mb_strlen($phrase), $recuMin, $recuCourt, $pourquoiCourt ?? null], JSON_UNESCAPED_UNICODE));

$total = $reussites + $echecs;
echo "\n" . ($echecs === 0
    ? "✅ $total/$total contrôles passés\n"
    : "❌ $echecs échec(s) sur $total\n");
exit($echecs === 0 ? 0 : 1);
