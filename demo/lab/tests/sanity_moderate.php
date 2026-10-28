#!/usr/bin/env php
<?php
/**
 * Contrôles du moteur de modération du lab.
 *
 * Chacun a été vu rougir avant d'être écrit : le mécanisme correspondant a été
 * neutralisé, la mesure refaite, puis le code restauré. Un contrôle qu'on n'a
 * jamais fait échouer ne se distingue pas d'un contrôle qui ne mesure rien.
 *
 * 🔑 Les contrôles 6 et 9 se lisent ensemble : même nombre de votes, même
 * minute, seul le lien entre les votants change. Trois votants sans aucun lien
 * forment une salve — signalée à l'arbitre, jamais annulée — et deux votants
 * qui se sont écrit forment une meute, ramenée au poids d'un membre seul. Si
 * l'un des deux bascule, c'est la frontière entre les deux détections qui a bougé.
 *
 * Le banc tourne sans journal branché jusqu'au contrôle 26 : à réputation
 * nulle, le moteur signale et ne bannit pas. Les contrôles du ban automatique
 * branchent le leur, et le retirent.
 *
 * Usage : php tests/sanity_moderate.php
 */

declare(strict_types=1);

// La base et les quatre secrets de ce banc, à lui seul — jamais ceux de `data/`.
require __DIR__ . '/instance_isolee.inc';

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/moderate.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Moderate;
use Pierroons\SelfModerate\Config;
use Pierroons\SelfModerate\Moderate as Moteur;

// 🔑 Le total se compte, il ne s'écrit pas.
//
// `deploy/my-self/tests/test_deploy.sh` a supprimé ce défaut le 22/08/2026 en
// calculant le sien : « le banc annonçait dix propriétés en en éprouvant
// douze ». Le remède n'avait pas voyagé jusqu'ici. Un chiffre recopié à côté de
// ce qu'il décrit finit toujours par le démentir — et un banc qui se trompe sur
// son propre compte est mal placé pour en corriger d'autres.
$echecs = 0; $reussites = 0;
function ok(string $m): void  { global $reussites; echo "  ✓ $m\n"; $reussites++; }
function nok(string $m): void { global $echecs; fwrite(STDERR, "  ✗ $m\n"); $echecs++; }

// Bac à sable : la base réelle du lab n'est jamais touchée.
$dir = sys_get_temp_dir() . '/sanity_moderate_' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
putenv("LAB_DB_PATH=$dir/lab.db");

register_shutdown_function(static function () use ($dir): void {
    foreach (glob("$dir/*") ?: [] as $f) {
        unlink($f);
    }
    @rmdir($dir);
});

// La base d'abord, comme sur une instance neuve : une base ne naît pas sous une clé qui
// a déjà servi. Puis la clé, sur une base qui ne porte encore aucun message — le banc en
// range ensuite de faux en SQL, et la garde refuserait à raison de la créer sous eux.
$pdo = Db::pdo();
require_once __DIR__ . '/../lib/dataguard.php';
\Pierroons\MySelfLab\DataGuard::exigerCle();

/** Crée un compte assez ancien pour passer l'anti-Sybil. */
function membre(PDO $pdo, string $nom): int
{
    $pdo->prepare(
        'INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$nom, 'x', 'x', 'x', time() - 30 * 86400]);
    return (int) $pdo->lastInsertId();
}

/** Crée un fil et un message, et rend l'identifiant du message. */
function message(PDO $pdo, int $auteur, string $titre): int
{
    $pdo->prepare('INSERT INTO threads (account_id, titre, created_at) VALUES (?, ?, ?)')
        ->execute([$auteur, $titre, time()]);
    $threadId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO posts (thread_id, account_id, contenu, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$threadId, $auteur, 'contenu de ' . $titre, time()]);
    return (int) $pdo->lastInsertId();
}

function reputation(PDO $pdo, int $id): int
{
    return Moderate::getReputation($pdo, $id)['reputation'];
}

function poserReputation(PDO $pdo, int $id, int $valeur): void
{
    Moderate::ensureRow($pdo, $id);
    $pdo->prepare(
        'UPDATE member_moderation
            SET reputation = ?, voting_rights = 1, needs_review = 0, review_reason = NULL,
                convalescent = 0, last_regen_at = 0
          WHERE account_id = ?'
    )->execute([$valeur, $id]);
    $pdo->prepare('DELETE FROM mod_signaux WHERE account_id = ?')->execute([$id]);
}

/**
 * Un signal ouvert sans la détection qui le cause : une ligne dans `mod_signaux`,
 * et la case recalée comme le moteur la tient — le premier comportement à voir,
 * sinon le dernier état. Sans écrire par le moteur : la première écriture sur
 * le compte referme une peine échue, et le cas qui la prépare la trouverait déjà
 * close.
 */
function planterSignal(PDO $pdo, int $id, string $cause, ?int $depuis = null): void
{
    Moderate::ensureRow($pdo, $id);
    $pdo->prepare('INSERT OR IGNORE INTO mod_signaux (account_id, cause, ouvert_le, derniere_le) VALUES (?, ?, ?, ?)')
        ->execute([$id, $cause, $depuis ?? time(), $depuis ?? time()]);
    recalerCase($pdo, $id);
}

/** La case d'un compte, recalée sur ses signaux à voir. */
function recalerCase(PDO $pdo, int $id): void
{
    $pdo->prepare(
        "UPDATE member_moderation
            SET review_reason = COALESCE(
                    (SELECT cause FROM mod_signaux WHERE account_id = :id AND vu_le IS NULL
                        AND cause NOT IN ('reputation_zero', 'ban_auto', 'journal_en_panne') ORDER BY id LIMIT 1),
                    (SELECT cause FROM mod_signaux WHERE account_id = :id AND vu_le IS NULL ORDER BY id DESC LIMIT 1)),
                needs_review = EXISTS (SELECT 1 FROM mod_signaux WHERE account_id = :id AND vu_le IS NULL)
          WHERE account_id = :id"
    )->execute(['id' => $id]);
}

/** Un motif qui passe les cinq règles. Varié, pour ne pas buter sur la diversité. */
function motif(): string
{
    static $n = 0;
    $phrases = [
        'Aucune source ne vient appuyer cette affirmation, et plusieurs points sont faux.',
        'Ce propos vise une personne plutôt que son argument, ce qui bloque la discussion.',
        'Le sujet du fil est ailleurs ; cette réponse emmène tout le monde à côté.',
        'Les chiffres avancés contredisent ceux publiés plus haut, sans aucune explication.',
        'Rien dans ce paragraphe ne répond à la question posée par le message initial.',
    ];
    return $phrases[$n++ % count($phrases)] . ' (' . $n . ')';
}

/** Échange privé réciproque : chacun a écrit à l'autre. */
function echangePrive(PDO $pdo, int $a, int $b): void
{
    foreach ([[$a, $b], [$b, $a]] as [$de, $vers]) {
        $pdo->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$de, $vers, 'chiffre', time() - 3600]);
    }
}

/** Message privé à sens unique : aucune réciprocité. */
function messagePrive(PDO $pdo, int $de, int $vers): void
{
    $pdo->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$de, $vers, 'chiffre', time() - 3600]);
}

/**
 * Un épisode complet : des votants liés entre eux frappent la même cible.
 * Rend l'identifiant de la cible, qui est neuve à chaque appel — l'index unique
 * de mod_votes interdit de rejouer un vote sur le même message.
 */
function episodeMeute(PDO $pdo, array $votants, string $nom): int
{
    $cible = membre($pdo, $nom);
    $post  = message($pdo, $cible, 'fil-' . $nom);
    for ($i = 0; $i < count($votants); $i++) {
        for ($j = $i + 1; $j < count($votants); $j++) {
            echangePrive($pdo, $votants[$i], $votants[$j]);
        }
    }
    foreach ($votants as $v) {
        Moderate::applyVote($pdo, $v, 'post', $post, -1, motif(), 'agressif');
    }
    return $cible;
}

/**
 * Recule les épisodes d'un votant : ils sortent du cooldown, et l'épisode
 * suivant peut monter de rang. Une suspension encore en base expire avec eux.
 */
function vieillirSanctions(PDO $pdo, array $votants, int $recul): void
{
    foreach ($votants as $v) {
        $pdo->prepare('UPDATE mod_pack_flags SET detected_at = detected_at - ? WHERE voter_id = ?')
            ->execute([$recul, $v]);
        $pdo->prepare('UPDATE member_moderation SET vote_muted_until = MAX(0, vote_muted_until - ?) WHERE account_id = ?')
            ->execute([$recul, $v]);
    }
}

/** Vote inséré directement : sert à constituer un passage de détection en une fois. */
function voteBrut(PDO $pdo, int $votant, int $post, int $auteur): void
{
    $pdo->prepare(
        'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, reason_code, effet, created_at)
         VALUES (?, ?, ?, ?, -1, ?, ?, -1, ?)'
    )->execute([$votant, 'post', $post, $auteur, motif(), 'agressif', time()]);
}

/** Joue un cas sous une autre config, et rend celle par défaut quoi qu'il arrive. */
function sousConfig(Config $config, callable $cas): mixed
{
    Moderate::setConfig($config);
    try {
        return $cas();
    } finally {
        Moderate::setConfig(null);
    }
}

/** Compte les votes annulés comme meute sur une cible. */
function bloquesMeute(PDO $pdo, int $cible): int
{
    return (int) $pdo->query(
        'SELECT COUNT(*) FROM mod_votes WHERE target_author = ' . $cible . " AND blocked_reason = 'pack_voting'"
    )->fetchColumn();
}

// ── 1. Un downvote retire un point à l'auteur du message ────────────────────
$cible  = membre($pdo, 'cible');
$votant = membre($pdo, 'votant');
$post   = message($pdo, $cible, 'fil-1');

$r = Moderate::applyVote($pdo, $votant, 'post', $post, -1, motif(), 'hors_sujet');
$r['ok'] && reputation($pdo, $cible) === Moderate::config()->reputationInitiale - 1
    ? ok('un downvote fait passer la réputation de 20 à ' . reputation($pdo, $cible))
    : nok('le downvote n\'a pas été appliqué : ' . json_encode($r));

// ── 2. On ne vote qu'une fois sur la même cible ─────────────────────────────
// Protégé à deux niveaux : la garde de `applyVote` rend le message, et
// `idx_modvotes_unique` est le filet. Retirer la garde ne rend pas le double
// vote possible — la base lève une violation de contrainte. Un contrôle qui
// n'exerçait que la garde applicative laisserait croire qu'elle est seule.
$r = Moderate::applyVote($pdo, $votant, 'post', $post, -1, motif(), 'hors_sujet');
!$r['ok'] && reputation($pdo, $cible) === Moderate::config()->reputationInitiale - 1
    ? ok('un second vote sur la même cible est refusé, la réputation ne bouge plus')
    : nok('le double vote est passé : ' . json_encode($r));

// ── 3. On ne vote pas pour soi-même ─────────────────────────────────────────
$sien = message($pdo, $votant, 'fil-du-votant');
$r = Moderate::applyVote($pdo, $votant, 'post', $sien, 1);
!$r['ok']
    ? ok('l\'auto-vote est refusé')
    : nok('l\'auto-vote est passé : ' . json_encode($r));

// ── 4. Sous le seuil, le droit de vote est retiré ───────────────────────────
$chute = membre($pdo, 'chute');
$p     = message($pdo, $chute, 'fil-chute');
poserReputation($pdo, $chute, Moderate::config()->perteDroitDeVoteSous);   // exactement au seuil
Moderate::applyVote($pdo, membre($pdo, 'passant'), 'post', $p, -1, motif(), 'hors_sujet');
$rep = Moderate::getReputation($pdo, $chute);
$rep['reputation'] === Moderate::config()->perteDroitDeVoteSous - 1 && !$rep['voting_rights']
    ? ok('sous ' . Moderate::config()->perteDroitDeVoteSous . ', le droit de vote est retiré')
    : nok('droit de vote conservé sous le seuil : ' . json_encode($rep));

// ── 5. Le harcèlement d'un seul votant est neutralisé ───────────────────────
// Des downvotes du même membre vers le même auteur, sur autant de messages
// différents pour contourner la garde du double vote. Celui qui dépasse le
// plafond `farmingDownvotesMax` de la config est enregistré bloqué, sans effet
// sur le score.
$harcele = membre($pdo, 'harcele');
$harceleur = membre($pdo, 'harceleur');
poserReputation($pdo, $harcele, 20);
$avant = reputation($pdo, $harcele);
$dernier = null;
for ($i = 1; $i <= Moderate::config()->farmingDownvotesMax + 1; $i++) {
    $dernier = Moderate::applyVote($pdo, $harceleur, 'post', message($pdo, $harcele, "fil-h$i"), -1, motif(), 'hors_sujet');
}
!empty($dernier['blocked'])
    && reputation($pdo, $harcele) === $avant - Moderate::config()->farmingDownvotesMax
    ? ok('le ' . (Moderate::config()->farmingDownvotesMax + 1) . 'e downvote du même membre est neutralisé (anti slow-drip)')
    : nok('le slow-drip est passé : ' . json_encode($dernier) . ' rep=' . reputation($pdo, $harcele));

// ── 6. 🔑 Trois votants sans lien ne forment PAS une meute ──────────────────
// Trois membres qui ne se sont jamais écrit votent dans la même minute : une
// salve, pas une meute. Leurs votes tiennent, et la cible part en revue humaine.
// Les annuler protégerait un message d'autant mieux qu'il choque plus de monde
// à la fois : la détection travaillerait pour l'abuseur.
$fache = membre($pdo, 'fache');
poserReputation($pdo, $fache, 20);
$inconnus = [membre($pdo, 'inconnu-a'), membre($pdo, 'inconnu-b'), membre($pdo, 'inconnu-c')];
foreach ($inconnus as $v) {
    Moderate::applyVote($pdo, $v, 'member', $fache, -1, motif(), 'agressif');
}
$liens = (int) $pdo->query(
    'SELECT COUNT(*) FROM dm WHERE sender_id IN (' . implode(',', $inconnus) . ')
        OR recipient_id IN (' . implode(',', $inconnus) . ')'
)->fetchColumn();
$bloques = (int) $pdo->query(
    'SELECT COUNT(*) FROM mod_votes WHERE target_author = ' . $fache . " AND blocked_reason = 'pack_voting'"
)->fetchColumn();
$etat6 = Moderate::getReputation($pdo, $fache);
$liens === 0 && $bloques === 0
    && $etat6['reputation'] === 17
    && $etat6['needs_review'] && $etat6['review_reason'] === 'salve_rapide'
    ? ok("trois votants sans lien ($liens échange) gardent leurs votes : réputation {$etat6['reputation']}, cible signalée en revue humaine")
    : nok('la salve rapide a été traitée comme une meute : bloqués=' . $bloques . ' ' . json_encode($etat6));

// ── 5bis. Un identifiant nu ne dit pas si la cible existe ───────────────────
// 🔑 L'oracle d'énumération, fermé par l'ORDRE des contrôles. Avant, la cible
// était résolue avant que le motif soit validé : un `target_id` sans motif
// rendait « Cible introuvable » ou « il manque 40 caractères » selon que
// l'identifiant existait. Aucun vote inscrit, aucun compteur touché, aucune zone
// de lissage sur cette route — l'espace des identifiants se balayait
// gratuitement, y compris les comptes qu'aucune autre route ne montre.
//
// Ce cas éprouve la propriété par l'ÉGALITÉ des deux réponses, et non par leur
// contenu : il reste vrai si le libellé change. Il ne vaut que pour le vote
// négatif : le positif, au motif facultatif, reste une sonde, déclarée au README.
$existe = membre($pdo, 'cible_existante');
$msgExiste = message($pdo, $existe, 'fil de la cible existante');
$sondeur = membre($pdo, 'sondeur');
$inexistant = 999777;

$rNu  = Moderate::applyVote($pdo, $sondeur, 'member', $existe, -1, null, 'agressif');
$rVide = Moderate::applyVote($pdo, $sondeur, 'member', $inexistant, -1, null, 'agressif');
$rNu['ok'] === false && $rVide['ok'] === false && $rNu['message'] === $rVide['message']
    ? ok('sans motif, une cible existante et une cible absente rendent le MÊME refus')
    : nok('l\'identifiant fuit encore : existante=' . json_encode($rNu) . ' absente=' . json_encode($rVide));

// Et le témoin : avec un motif valide, la distinction revient — mais elle coûte
// alors un vote inscrit, tracé, et non répétable (index unique).
$rMotif = Moderate::applyVote($pdo, $sondeur, 'member', $existe, -1, motif(), 'agressif');
$rAbsent = Moderate::applyVote($pdo, $sondeur, 'member', $inexistant, -1, motif(), 'agressif');
$rMotif['ok'] === true && $rAbsent['ok'] === false
    ? ok('avec un motif valide, sonder une cible existante INSCRIT le vote : la sonde est tracée et non répétable')
    : nok('le vote motivé ne passe plus : ' . json_encode([$rMotif, $rAbsent]));

// ── 6bis. Une salve n'ÉCRASE pas le motif d'arbitrage déjà posé ─────────────
// 🔑 Trois comptes ordinaires suffisent à déclencher la branche « salve rapide »,
// et elle tourne à chaque vote négatif. Dans une case unique, un tiers remplaçait
// le motif d'un autre par le plus bénin de la liste, ou la salve s'y perdait :
// l'arbitre ne voyait qu'un des deux faits. Chaque cause a sa ligne, et les deux
// restent ouvertes.
//
// ⚠️ Les DEUX moitiés comptent, et la seconde est le témoin : un cas qui vérifie
// seulement « le motif grave a survécu » passe aussi bien quand la salve ne s'est
// pas déclenchée du tout. C'est ce témoin qui a révélé que le premier montage de
// ce cas ne déclenchait rien.
$grave = membre($pdo, 'deja_signale');
poserReputation($pdo, $grave, 20);
planterSignal($pdo, $grave, 'meute_recidive');
foreach (['sv-a', 'sv-b', 'sv-c'] as $n) {
    Moderate::applyVote($pdo, membre($pdo, $n), 'member', $grave, -1, motif(), 'agressif');
}
$etatGrave = Moderate::getReputation($pdo, $grave);
$etatGrave['reputation'] === 17 && $etatGrave['review_reason'] === 'meute_recidive'
    && array_column($etatGrave['signaux'] ?? [], 'cause') === ['meute_recidive', 'salve_rapide']
    ? ok('une salve de trois votants ne remplace pas le motif « meute_recidive » déjà posé, et s\'ouvre à côté')
    : nok('motif écrasé ou salve perdue : ' . json_encode($etatGrave));

$vierge = membre($pdo, 'pas_signale');
poserReputation($pdo, $vierge, 20);
foreach (['vv-a', 'vv-b', 'vv-c'] as $n) {
    Moderate::applyVote($pdo, membre($pdo, $n), 'member', $vierge, -1, motif(), 'agressif');
}
$etatVierge = Moderate::getReputation($pdo, $vierge);
$etatVierge['needs_review'] && $etatVierge['review_reason'] === 'salve_rapide'
    ? ok('et sur une case vide, la salve pose bien son signalement — la garde n\'éteint pas le signal')
    : nok('la garde a éteint le signal au lieu de le préserver : ' . json_encode($etatVierge));

// ── 7. Un downvote ancien ne complète pas une salve ─────────────────────────
// La salve se mesure sur une fenêtre glissante. Deux votants récents et un vote
// très antérieur ne font pas trois : sans cela, un vote de camouflage suffirait
// à faire signaler n'importe qui.
$isole = membre($pdo, 'isole');
poserReputation($pdo, $isole, 20);
$vieux = membre($pdo, 'vieux-grief');
Moderate::applyVote($pdo, $vieux, 'member', $isole, -1, motif(), 'hors_sujet');
$pdo->exec('UPDATE mod_votes SET created_at = ' . (time() - 10 * Moderate::config()->fenetreSalveSecondes)
         . ' WHERE voter_id = ' . $vieux . ' AND target_author = ' . $isole);
foreach ([membre($pdo, 'recent-a'), membre($pdo, 'recent-b')] as $v) {
    Moderate::applyVote($pdo, $v, 'member', $isole, -1, motif(), 'hors_sujet');
}
$etat7 = Moderate::getReputation($pdo, $isole);
!$etat7['needs_review']
    ? ok('un downvote hors fenêtre ne complète pas une salve : aucun signalement')
    : nok('salve signalée à tort avec un vote hors fenêtre : ' . json_encode($etat7));

// ── 8. Sans journal branché : à 0, revue humaine, aucun bannissement ────────
// Le ban automatique ne s'arme qu'avec un journal (`setJournal()`). Ici aucun
// n'est branché : la réputation nulle lève un signalement, et l'arbitre tranche.
$zero = membre($pdo, 'a-zero');
$p8   = message($pdo, $zero, 'fil-zero');
poserReputation($pdo, $zero, 1);
Moderate::applyVote($pdo, membre($pdo, 'dernier-vote'), 'post', $p8, -1, motif(), 'agressif');
$rep = Moderate::getReputation($pdo, $zero);
$rep['reputation'] === 0 && $rep['needs_review'] && $rep['review_reason'] === 'reputation_zero'
    && $rep['convalescent'] && !$rep['banned']
    ? ok('à 0 : revue humaine pour érosion, convalescence ouverte, aucun bannissement')
    : nok('sanction inattendue à 0 : ' . json_encode($rep));

// ── 9. 🔑 Deux votants liés forment une meute ───────────────────────────────
// Le pendant du contrôle 6. Même nombre de votes, même minute — seul le lien
// change. Le duo ne pèse alors que ce que pèse un membre seul : le premier
// garde son vote, le second est annulé.
$vise = membre($pdo, 'vise-par-duo');
poserReputation($pdo, $vise, 20);
$duoA = membre($pdo, 'duo-a');
$duoB = membre($pdo, 'duo-b');
echangePrive($pdo, $duoA, $duoB);
Moderate::applyVote($pdo, $duoA, 'member', $vise, -1, motif(), 'agressif');
Moderate::applyVote($pdo, $duoB, 'member', $vise, -1, motif(), 'agressif');
$bloques9 = (int) $pdo->query(
    'SELECT COUNT(*) FROM mod_votes WHERE target_author = ' . $vise . " AND blocked_reason = 'pack_voting'"
)->fetchColumn();
$bloques9 === 1 && reputation($pdo, $vise) === 19
    ? ok('deux votants qui se sont écrit : le premier garde son vote, le second est annulé, réputation à 19')
    : nok('la meute n\'a pas été ramenée à un membre seul : bloqués=' . $bloques9 . ' rep=' . reputation($pdo, $vise));

// ── 10. Un message privé à sens unique ne crée pas de lien ──────────────────
// Le contrôle négatif du n° 9 : sans réciprocité, pas de meute. Sinon un
// spammeur se rendrait invulnérable en écrivant à tout le monde avant de voter.
$viseSolo = membre($pdo, 'vise-par-solo');
poserReputation($pdo, $viseSolo, 20);
$ecrivain = membre($pdo, 'ecrivain');
$muet     = membre($pdo, 'muet');
messagePrive($pdo, $ecrivain, $muet);
Moderate::applyVote($pdo, $ecrivain, 'member', $viseSolo, -1, motif(), 'agressif');
Moderate::applyVote($pdo, $muet, 'member', $viseSolo, -1, motif(), 'agressif');
$bloques10 = (int) $pdo->query(
    'SELECT COUNT(*) FROM mod_votes WHERE target_author = ' . $viseSolo . ' AND blocked = 1'
)->fetchColumn();
!Moderate::areLinked($pdo, $ecrivain, $muet) && $bloques10 === 0 && reputation($pdo, $viseSolo) === 18
    ? ok('un message privé sans réponse ne lie pas deux votants : aucune annulation')
    : nok('lien reconnu à sens unique : bloqués=' . $bloques10 . ' rep=' . reputation($pdo, $viseSolo));

// ── 10bis. La détection ne lit jamais le contenu des messages privés ────────
// Une vue du même nom masque la vraie table le temps du cas. Elle rend
// l'expéditeur, le destinataire et la date ; son contenu lève une erreur dès
// qu'une requête le lit — nommé, filtré ou par `SELECT *`.
$viseSansContenu = membre($pdo, 'vise-sans-contenu');
poserReputation($pdo, $viseSansContenu, 20);
$lieA = membre($pdo, 'lie-sans-contenu-a');
$lieB = membre($pdo, 'lie-sans-contenu-b');
$pdo->exec('CREATE TEMP TABLE dm_echanges (id INTEGER PRIMARY KEY, sender_id INTEGER NOT NULL, recipient_id INTEGER NOT NULL, created_at INTEGER NOT NULL)');
$pdo->exec("CREATE TEMP VIEW dm AS SELECT id, sender_id, recipient_id, created_at, json('contenu illisible') AS ciphertext FROM dm_echanges");
try {
    foreach ([[$lieA, $lieB], [$lieB, $lieA]] as [$de, $vers]) {
        $pdo->prepare('INSERT INTO temp.dm_echanges (sender_id, recipient_id, created_at) VALUES (?, ?, ?)')
            ->execute([$de, $vers, time() - 3600]);
    }
    Moderate::applyVote($pdo, $lieA, 'member', $viseSansContenu, -1, motif(), 'agressif');
    Moderate::applyVote($pdo, $lieB, 'member', $viseSansContenu, -1, motif(), 'agressif');
    $sansContenu = 'bloqués=' . bloquesMeute($pdo, $viseSansContenu);
} catch (Throwable $t) {
    $sansContenu = $t->getMessage();
} finally {
    $pdo->exec('DROP VIEW IF EXISTS temp.dm');
    $pdo->exec('DROP TABLE IF EXISTS temp.dm_echanges');
}
$sansContenu === 'bloqués=1'
    ? ok('la meute se reconnaît sur des messages privés réduits à qui, à qui et quand')
    : nok('la détection lit le contenu des messages privés : ' . $sansContenu);

// ── 11. La meute se propage par transitivité ────────────────────────────────
// A–B et B–C se connaissent, A et C non. Les trois forment une composante : une
// meute a un meneur, et exiger que tous se connaissent deux à deux la laisserait
// passer. Les votes sont posés en base puis la détection lancée une seule fois —
// en passant par applyVote, la paire A–B déclencherait avant que C ait voté.
$viseTrio = membre($pdo, 'vise-par-trio');
poserReputation($pdo, $viseTrio, 17);
$trio = [membre($pdo, 'trio-a'), membre($pdo, 'trio-b'), membre($pdo, 'trio-c')];
echangePrive($pdo, $trio[0], $trio[1]);
echangePrive($pdo, $trio[1], $trio[2]);
foreach ($trio as $v) {
    $pdo->prepare('INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, effet, created_at)
                   VALUES (?, ?, ?, ?, -1, ?, -1, ?)')
        ->execute([$v, 'member', $viseTrio, $viseTrio, motif(), time()]);
}
Moderate::detectPackVoting($pdo);
$bloques11 = (int) $pdo->query(
    'SELECT COUNT(*) FROM mod_votes WHERE target_author = ' . $viseTrio . " AND blocked_reason = 'pack_voting'"
)->fetchColumn();
!Moderate::areLinked($pdo, $trio[0], $trio[2]) && $bloques11 === 2 && reputation($pdo, $viseTrio) === 19
    ? ok('A–B et B–C liés forment un groupe de trois, même si A et C ne se connaissent pas : seul le premier compte')
    : nok('la transitivité n\'a pas joué : bloqués=' . $bloques11 . ' rep=' . reputation($pdo, $viseTrio));

// ── 11bis. ⭐ La troncature garde les votants récents, et elle se dit ───────
// Le graphe des votants est borné. Coupé par identifiant croissant, il écarterait
// les comptes les plus récents — ceux qu'une meute de comptes neufs ajoute en
// dernier —, et une troncature tue se lirait comme une absence de meute. Borne
// abaissée pour que le cas ne dépende pas de la valeur par défaut.
Moderate::setConfig(new Config(meuteVotantsMax: 5));
$foule = membre($pdo, 'cible-tres-votee');
$pdo->prepare('INSERT INTO threads (account_id, titre, created_at) VALUES (?, ?, ?)')->execute([$foule, 'fil-foule', time()]);
$pdo->prepare('INSERT INTO posts (thread_id, account_id, contenu, created_at) VALUES (?, ?, ?, ?)')
    ->execute([(int) $pdo->lastInsertId(), $foule, 'contenu', time()]);
$postFoule = (int) $pdo->lastInsertId();
for ($k = 0; $k < 6; $k++) {
    $pdo->prepare('INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, created_at)
                   VALUES (?, ?, ?, ?, -1, ?, ?)')
        ->execute([membre($pdo, "foule-ancien-$k"), 'post', $postFoule, $foule, motif(), time() - 3600]);
}
$neuf1 = membre($pdo, 'foule-neuf-1');
$neuf2 = membre($pdo, 'foule-neuf-2');
echangePrive($pdo, $neuf1, $neuf2);
Moderate::applyVote($pdo, $neuf1, 'member', $foule, -1, motif(), 'agressif');
Moderate::applyVote($pdo, $neuf2, 'member', $foule, -1, motif(), 'agressif');
Moderate::setConfig(null);
$bloquesFoule = (int) $pdo->query("SELECT COUNT(*) FROM mod_votes WHERE target_author = $foule AND blocked_reason = 'pack_voting'")->fetchColumn();
$modFoule = Moderate::getReputation($pdo, $foule);
$bloquesFoule === 1 && $modFoule['review_reason'] === 'votants_tronques'
    ? ok('⭐ au-delà de la borne, la meute de comptes récents est vue, et la troncature signalée à l\'arbitre')
    : nok('⭐ la troncature cache la meute ou se tait : bloqués=' . $bloquesFoule . ' ' . json_encode($modFoule['review_reason']));

// ── 11ter. ⭐ Un meneur annulé relie encore sa meute ────────────────────────
// A–B et B–C liés, A et C non ; ils votent dans cet ordre. B, second, est annulé :
// s'il sortait du graphe avec son vote, C ne serait plus relié à personne et
// garderait le sien — la meute pèserait deux membres.
$viseChaine = membre($pdo, 'vise-par-chaine');
$chaine = [membre($pdo, 'chaine-a'), membre($pdo, 'chaine-b'), membre($pdo, 'chaine-c')];
echangePrive($pdo, $chaine[0], $chaine[1]);
echangePrive($pdo, $chaine[1], $chaine[2]);
foreach ($chaine as $v) {
    Moderate::applyVote($pdo, $v, 'member', $viseChaine, -1, motif(), 'agressif');
}
$gardesChaine = (int) $pdo->query("SELECT COUNT(*) FROM mod_votes WHERE target_author = $viseChaine AND blocked = 0")->fetchColumn();
$gardesChaine === 1 && reputation($pdo, $viseChaine) === 19
    ? ok('⭐ une chaîne A–B–C qui vote dans l\'ordre ne garde que le vote de A, même une fois B annulé')
    : nok('⭐ le meneur annulé ne relie plus sa meute : votes gardés=' . $gardesChaine . ' rep=' . reputation($pdo, $viseChaine));

// ── 11quater. ⭐ Un vote déjà annulé par un autre passage ne se rend pas deux fois ─
// Deux balayages peuvent lire les mêmes votes avant que l'un les annule. Seul le
// passage qui les annule effectivement doit rendre leur effet et inscrire
// l'épisode ; l'autre ne trouve plus rien.
$doublon = membre($pdo, 'annule-deux-fois');
$pdo->prepare('INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, effet, created_at)
               VALUES (?, ?, ?, ?, -1, ?, -1, ?)')
    ->execute([membre($pdo, 'course-annulation'), 'member', $doublon, $doublon, motif(), time()]);
$idCourse = (int) $pdo->lastInsertId();
try {
    $annuler = new ReflectionMethod(Moteur::class, 'annulerVotes');
    $premierPassage = $annuler->invoke(null, $pdo, [$idCourse]);
    $secondPassage  = $annuler->invoke(null, $pdo, [$idCourse]);
    $premierPassage === [$idCourse] && $secondPassage === []
        ? ok('⭐ un vote déjà annulé par un autre passage n\'est annulé, ni rendu, qu\'une fois')
        : nok('⭐ le second passage annule encore : ' . json_encode([$premierPassage, $secondPassage]));
} catch (ReflectionException $ex) {
    nok('⭐ aucune garde contre une double annulation : ' . $ex->getMessage());
}

// ── 12. La convalescence rend un point par intervalle, et le droit de vote ──
$conv = membre($pdo, 'convalescent');
poserReputation($pdo, $conv, 2);
$pdo->prepare('UPDATE member_moderation SET convalescent = 1, voting_rights = 0, last_regen_at = ? WHERE account_id = ?')
    ->execute([time() - 3 * Moderate::config()->intervalleConvalescenceSecondes, $conv]);
$etat12 = Moderate::getReputation($pdo, $conv);
$etat12['reputation'] === 5 && $etat12['voting_rights'] && $etat12['convalescent']
    ? ok('trois intervalles de calme rendent trois points et le droit de vote, sans lever la convalescence')
    : nok('remontée passive incorrecte : ' . json_encode($etat12));

// ── 13. 🔑 La remontée va jusqu'à 20, pas jusqu'au seuil de vote ────────────
// Le piège du moteur dont celui-ci s'inspire : sa régénération est conditionnée
// à « score < 5 », donc elle s'arrête pile au seuil qui rend le droit de vote et
// laisse le compte à vie sur le fil du rasoir. Ici l'état est posé sous 5 et levé
// à 20 : ce contrôle doit rougir si quelqu'un réintroduit la condition de seuil.
$pdo->prepare('UPDATE member_moderation SET last_regen_at = ? WHERE account_id = ?')
    ->execute([time() - 40 * Moderate::config()->intervalleConvalescenceSecondes, $conv]);
$etat13 = Moderate::getReputation($pdo, $conv);
$etat13['reputation'] === Moderate::config()->sortieConvalescence() && !$etat13['convalescent']
    ? ok('la remontée s\'arrête à ' . Moderate::config()->sortieConvalescence() . ' et lève la convalescence')
    : nok('la convalescence ne se termine pas où elle devrait : ' . json_encode($etat13));

// ── 14. Le motif refuse ce qui ne dit rien ──────────────────────────────────
// Quatre façons de remplir un champ sans écrire. Chaque cas ne viole QU'UNE
// règle et satisfait les trois autres : autrement la défense en profondeur
// rattrape le trou, le contrôle reste vert, et on ne saurait jamais laquelle des
// quatre mesure encore quelque chose.
$refus = [
    // court, mais riche en lettres et en mots — seule la longueur pèche
    'trop court'       => 'argument faux, blocage',
    // 44 caractères, huit mots distincts, aucune rafale — 4 lettres en tout
    'quatre lettres'   => 'abcd bcda cdab dabc abdc badc cbad dcba abcd',
    // long, varié, mots distincts — une seule touche est restée enfoncée
    'rafale au milieu' => 'ce message est vraiment nuuuuuuuuuuul et sans intérêt aucun',
    // long, varié, quatre mots distincts — mais « encore » revient trois fois
    'un mot ressassé'  => 'ce message répète encore et encore et encore la même idée sans fond',
];
$tousRefuses = true;
$detail = [];
foreach ($refus as $quoi => $texte) {
    [$recevable, $pourquoi] = Moderate::validateReason($texte);
    if ($recevable) {
        $tousRefuses = false;
        $detail[] = $quoi;
    }
}
[$bonOk] = Moderate::validateReason(motif());
$tousRefuses && $bonOk
    ? ok('les quatre remplissages sont refusés, un motif écrit passe')
    : nok('le filtre de motif laisse passer : ' . implode(', ', $detail) . ($bonOk ? '' : ' — et refuse un motif valide'));

// ── 15. Le motif est exigé au downvote, facultatif à l'upvote ───────────────
// Un motif protège la personne sanctionnée. Un pouce en l'air ne sanctionne
// personne : lui imposer une justification écrite tuerait l'usage sans protéger
// qui que ce soit. L'upvote reste tenu par son plafond anti-farming.
$auteur15 = membre($pdo, 'auteur-15');
$p15a = message($pdo, $auteur15, 'fil-15a');
$p15b = message($pdo, $auteur15, 'fil-15b');
$juge = membre($pdo, 'juge-15');
$sansMotif = Moderate::applyVote($pdo, $juge, 'post', $p15a, -1);
$upSansMotif = Moderate::applyVote($pdo, $juge, 'post', $p15b, 1);
!$sansMotif['ok'] && $upSansMotif['ok']
    ? ok('un downvote sans motif est refusé, un upvote sans motif passe')
    : nok('asymétrie du motif non respectée : down=' . json_encode($sansMotif) . ' up=' . json_encode($upSansMotif));

// ── 16. Les motifs rendus à la cible ne portent aucun votant ────────────────
// Le protocole promet que la personne voit les raisons, pas qui a voté. La date
// est ramenée au jour : à la seconde près, recoupée avec les présences, elle
// désignerait son auteur.
$recus = Moderate::reasonsFor($pdo, $fache);
$colonnes = $recus ? array_keys($recus[0]) : [];
$fuite = array_intersect($colonnes, ['voter_id', 'voter', 'username', 'id', 'created_at']);
count($recus) === 3 && !$fuite && in_array('jour', $colonnes, true)
    ? ok('les ' . count($recus) . ' motifs reçus sortent sans votant ni horodatage fin (' . implode(', ', $colonnes) . ')')
    : nok('fuite dans les motifs rendus : ' . implode(', ', $fuite) . ' — colonnes ' . implode(', ', $colonnes));

// ── 16bis. ⭐ Un vote ne rend pas la réputation de la cible ─────────────────
// Le profil tait le chiffre sous le seuil du vote. La réponse d'un vote qui le
// rendrait exact le trahirait : un vote positif sur un banni répondrait 1, donc
// 0 avant, donc banni.
$sondee = membre($pdo, 'sondee-par-vote');
$rSonde = Moderate::applyVote($pdo, membre($pdo, 'sondeur-16bis'), 'member', $sondee, 1);
($rSonde['ok'] ?? false) && !array_key_exists('new_reputation', $rSonde)
    ? ok('⭐ la réponse d\'un vote ne porte plus la réputation de la cible')
    : nok('⭐ la réponse d\'un vote révèle la réputation : ' . json_encode($rSonde));

// ── 16ter. ⭐ Un votant ne vise pas tout le forum ───────────────────────────
// Le plafond anti-farming borne un votant par cible. Sans borne par votant, un
// seul compte retirerait des points à chaque membre et déposerait un motif
// anonyme sur chaque profil, sans qu'aucune détection le voie.
$etendu = membre($pdo, 'votant-etendu');
$plafond = Moderate::config()->ciblesParVotantMax;
$resultats = [];
for ($k = 0; $k <= $plafond; $k++) {
    $resultats[] = Moderate::applyVote($pdo, $etendu, 'member', membre($pdo, "visee-large-$k"), -1, motif(), 'agressif');
}
$comptes = count(array_filter($resultats, static fn (array $r): bool => ($r['ok'] ?? false) && !($r['blocked'] ?? false)));
$dernier = end($resultats);
$modEtendu = Moderate::getReputation($pdo, $etendu);
$comptes === $plafond && ($dernier['blocked_reason'] ?? '') === 'plafond_votant' && $modEtendu['review_reason'] === 'votant_etendu'
    ? ok("⭐ $plafond membres distincts visés, le suivant est neutralisé, et le votant est signalé à l'arbitre")
    : nok('⭐ un votant érode tout le forum : comptés=' . $comptes . ' dernier=' . json_encode($dernier) . ' ' . json_encode($modEtendu['review_reason']));

// ── 16quater. Les motifs sont datés au jour, et rangés hors de leur ordre d'arrivée ─
// Trois motifs posés le même jour, à midi UTC — la date reste la même quel que
// soit le fuseau de l'hôte. L'ordre d'arrivée (B, A, C) diffère de l'ordre
// alphabétique dans les deux sens : un tri chronologique, croissant ou
// décroissant, se verrait.
$recoit = membre($pdo, 'recoit-motifs-dates');
$midi   = intdiv(time(), 86400) * 86400 - 2 * 86400 + 43200;
$poses  = [
    'B — Le sujet du fil est ailleurs ; cette réponse emmène tout le monde à côté.',
    'A — Aucune source ne vient appuyer cette affirmation, et plusieurs points sont faux.',
    'C — Les chiffres avancés contredisent ceux publiés plus haut, sans aucune explication.',
];
$postDate = message($pdo, $recoit, 'fil-motifs-dates');
foreach ($poses as $k => $texte) {
    $pdo->prepare(
        'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, reason_code, effet, created_at)
         VALUES (?, ?, ?, ?, -1, ?, ?, -1, ?)'
    )->execute([membre($pdo, "date-motif-$k"), 'post', $postDate, $recoit, $texte, 'hors_sujet', $midi + 60 * $k]);
}
$datesRecues = Moderate::reasonsFor($pdo, $recoit);
$colonnesRecues = $datesRecues ? array_keys($datesRecues[0]) : [];
sort($colonnesRecues);
array_column($datesRecues, 'reason') === [$poses[1], $poses[0], $poses[2]]
    && array_unique(array_column($datesRecues, 'jour')) === [gmdate('Y-m-d', $midi)]
    && $colonnesRecues === ['jour', 'reason', 'reason_code', 'value']
    ? ok('les motifs d\'un même jour sortent datés au jour et rien de plus fin, dans l\'ordre alphabétique et non d\'arrivée')
    : nok('motifs mal datés ou rangés dans leur ordre d\'arrivée : ' . json_encode([array_map(
        static fn (array $m): array => [$m['jour'], mb_substr($m['reason'], 0, 1)], $datesRecues), $colonnesRecues], JSON_UNESCAPED_UNICODE));

// ── 17. Premier épisode : le groupe pèse un membre seul, et personne ne paie ─
// Le lien par message privé ne distingue ni deux amis de bonne foi, ni un lien
// fabriqué par la cible, d'une coordination. Il neutralise ce que le groupe
// ajoute ; il ne punit personne.
$a = membre($pdo, 'meute-a');
$b = membre($pdo, 'meute-b');
$c1 = episodeMeute($pdo, [$a, $b], 'victime-1');

$rangA = Moderate::rangDe($pdo, $a);
$modA  = Moderate::getReputation($pdo, $a);
[$peutVoter, ] = Moderate::canVote($pdo, $a);
$rangA === 1 && Moderate::rangDe($pdo, $b) === 1 && $modA['vote_muted_until'] === 0 && $peutVoter
    && reputation($pdo, $c1) === Moderate::config()->reputationInitiale - 1
    ? ok('premier épisode : rang 1, aucune peine, et la victime ne perd que le vote du premier votant')
    : nok('premier épisode mal traité : rang=' . $rangA . ' ' . json_encode($modA) . ' vote=' . var_export($peutVoter, true)
        . ' victime=' . reputation($pdo, $c1));

// ── 18. ⭐ La récidive part chez l'arbitre, sans suspension ─────────────────
vieillirSanctions($pdo, [$a, $b], 2 * 86400);
$c2 = episodeMeute($pdo, [$a, $b], 'victime-2');

$modA = Moderate::getReputation($pdo, $a);
[$peutVoter, ] = Moderate::canVote($pdo, $a);
Moderate::rangDe($pdo, $a) === 2 && $modA['vote_muted_until'] === 0 && $peutVoter
    && $modA['needs_review'] && $modA['review_reason'] === 'meute_recidive'
    ? ok('⭐ deuxième épisode : rang 2, revue humaine, et le droit de vote reste entier')
    : nok('⭐ la récidive a été punie, ou n\'est pas allée à l\'arbitre : ' . json_encode($modA) . ' vote=' . var_export($peutVoter, true));

// ── 19. Les rangs suivants n'ajoutent aucune peine ──────────────────────────
vieillirSanctions($pdo, [$a, $b], 8 * 86400);
$repAvant = reputation($pdo, $a);
$c3 = episodeMeute($pdo, [$a, $b], 'victime-3');
vieillirSanctions($pdo, [$a, $b], 31 * 86400);
$c4 = episodeMeute($pdo, [$a, $b], 'victime-4');

$modA = Moderate::getReputation($pdo, $a);
Moderate::rangDe($pdo, $a) === 4 && $modA['reputation'] === $repAvant && $modA['vote_muted_until'] === 0
    && $modA['banned_until'] === 0 && $modA['review_reason'] === 'meute_recidive'
    ? ok('troisième et quatrième épisodes : le rang monte, aucun point retiré, aucune suspension, aucun ban')
    : nok('un rang a apporté une peine : rang=' . Moderate::rangDe($pdo, $a) . ' ' . json_encode($modA) . ' avant=' . $repAvant);

// ── 21. 🔑 Trois cibles dans le même passage ne valent qu'un seul rang ──────
// detectPackVoting traite toutes les cibles d'un même appel. Sans le cooldown,
// un groupe qui a frappé trois personnes franchirait trois paliers d'un coup, et
// l'avertissement du premier rang ne serait jamais vu par personne. Les votes
// sont insérés directement pour que la détection les découvre en une seule fois.
$d = membre($pdo, 'salve-d');
$e = membre($pdo, 'salve-e');
echangePrive($pdo, $d, $e);
foreach (['triple-1', 'triple-2', 'triple-3'] as $nom) {
    $v = membre($pdo, $nom);
    $post = message($pdo, $v, 'fil-' . $nom);
    voteBrut($pdo, $d, $post, $v);
    voteBrut($pdo, $e, $post, $v);
}
Moderate::detectPackVoting($pdo);

$lignes = (int) $pdo->query('SELECT COUNT(*) FROM mod_pack_flags WHERE voter_id = ' . $d)->fetchColumn();
$modD   = Moderate::getReputation($pdo, $d);
Moderate::rangDe($pdo, $d) === 1 && $lignes === 3 && $modD['vote_muted_until'] === 0
    ? ok('trois cibles en un passage : rang 1, ' . $lignes . ' épisodes tracés, aucune peine')
    : nok('le cooldown ne tient pas : rang=' . Moderate::rangDe($pdo, $d) . ' lignes=' . $lignes . ' ' . json_encode($modD));

// ── 22. 🔑 Une suspension de vote survit à la remontée de réputation ────────
// Le moteur n'en pose plus, mais une base peut en porter une. La réputation dit
// ce qu'on vaut, la suspension ce qu'on a fait : la convalescence, qui rend le
// droit de vote, ne doit pas la lever.
$pdo->prepare(
    'UPDATE member_moderation SET reputation = 2, convalescent = 1, last_regen_at = ?, vote_muted_until = ? WHERE account_id = ?'
)->execute([time() - 10 * 86400, time() + 7 * 86400, $a]);
$modA = Moderate::getReputation($pdo, $a);      // déclenche la convalescence
[$peutVoter, $raison] = Moderate::canVote($pdo, $a);
$modA['reputation'] === 12 && !$peutVoter && str_contains($raison, 'meute')
    ? ok('la réputation remonte à ' . $modA['reputation'] . ' et la suspension tient : « ' . $raison . ' »')
    : nok('la remontée a effacé la peine : rep=' . $modA['reputation'] . ' vote=' . var_export($peutVoter, true) . ' ' . $raison);

// ── 23. 🔑 Le compteur appartient au votant, pas à la cible ─────────────────
// Compté sur la cible, le second groupe hériterait du rang laissé par le
// premier et prendrait une peine pour sa première meute.
$victime = membre($pdo, 'visee-deux-fois');
$g = membre($pdo, 'groupe-g'); $h = membre($pdo, 'groupe-h');
echangePrive($pdo, $g, $h);
$p1 = message($pdo, $victime, 'fil-visee-1');
Moderate::applyVote($pdo, $g, 'post', $p1, -1, motif(), 'agressif');
Moderate::applyVote($pdo, $h, 'post', $p1, -1, motif(), 'agressif');

$i = membre($pdo, 'groupe-i'); $j = membre($pdo, 'groupe-j');
echangePrive($pdo, $i, $j);
$p2 = message($pdo, $victime, 'fil-visee-2');
Moderate::applyVote($pdo, $i, 'post', $p2, -1, motif(), 'agressif');
Moderate::applyVote($pdo, $j, 'post', $p2, -1, motif(), 'agressif');

$modI = Moderate::getReputation($pdo, $i);
Moderate::rangDe($pdo, $g) === 1 && Moderate::rangDe($pdo, $i) === 1 && $modI['vote_muted_until'] === 0
    ? ok('deux groupes distincts sur la même victime : chacun au rang 1, aucun n\'hérite de l\'autre')
    : nok('le rang suit la cible : g=' . Moderate::rangDe($pdo, $g) . ' i=' . Moderate::rangDe($pdo, $i) . ' ' . json_encode($modI));

// ── 24. Chaque victime ne perd que ce qu'un membre seul lui aurait retiré ───
$restaurees = array_map(static fn (int $id): int => reputation($pdo, $id), [$c1, $c2, $c3, $c4]);
count(array_unique($restaurees)) === 1 && $restaurees[0] === Moderate::config()->reputationInitiale - 1
    ? ok('les quatre victimes ont perdu un point, le vote du premier votant, du premier rang au dernier')
    : nok('une victime a perdu plus, ou moins, qu\'un membre seul : ' . implode(', ', $restaurees));

// ── 24bis. ⭐ Un troll ne fait pas punir ceux qui le critiquent ensemble ────
// Un membre publie un message odieux par jour ; deux habitués qui se parlent en
// privé le downvotent chaque fois. Une peine fondée sur le lien laisserait le
// troll choisir qui récidive.
$troll = membre($pdo, 'troll-quotidien');
$h1 = membre($pdo, 'habitue-1');
$h2 = membre($pdo, 'habitue-2');
echangePrive($pdo, $h1, $h2);
for ($jour = 1; $jour <= 4; $jour++) {
    $odieux = message($pdo, $troll, "odieux-$jour");
    Moderate::applyVote($pdo, $h1, 'post', $odieux, -1, motif(), 'agressif');
    Moderate::applyVote($pdo, $h2, 'post', $odieux, -1, motif(), 'agressif');
    vieillirSanctions($pdo, [$h1, $h2], 25 * 3600);
}
[$voteH1] = Moderate::canVote($pdo, $h1);
[$voteH2] = Moderate::canVote($pdo, $h2);
$modH1 = Moderate::getReputation($pdo, $h1);
$modH2 = Moderate::getReputation($pdo, $h2);
$voteH1 && $voteH2 && $modH1['reputation'] === Moderate::config()->reputationInitiale
    && $modH2['reputation'] === Moderate::config()->reputationInitiale
    && $modH1['vote_muted_until'] === 0 && $modH2['vote_muted_until'] === 0 && Moderate::rangDe($pdo, $h2) >= 2
    ? ok('⭐ quatre jours de critiques liées contre un troll : rang ' . Moderate::rangDe($pdo, $h2) . ', aucun vote suspendu, aucun point retiré')
    : nok('⭐ le troll a fait punir ses critiques : ' . json_encode([$voteH1, $voteH2, $modH1, $modH2]));

// ── 24ter. ⭐ Un compte jetable ne fait pas punir celui qui lui a répondu ───
// La cible ouvre un jetable, écrit au critique et obtient une réponse : les deux
// sont liés. Le jetable vote AVANT le critique à chaque message, pour que ce
// soit le vote du critique qui tombe.
$cibleJ   = membre($pdo, 'cible-du-critique');
$critique = membre($pdo, 'critique-honnete');
$jetable  = membre($pdo, 'jetable');
echangePrive($pdo, $jetable, $critique);
for ($k = 1; $k <= 3; $k++) {
    $pique = message($pdo, $cibleJ, "pique-$k");
    Moderate::applyVote($pdo, $jetable, 'post', $pique, -1, motif(), 'agressif');
    Moderate::applyVote($pdo, $critique, 'post', $pique, -1, motif(), 'agressif');
    vieillirSanctions($pdo, [$jetable, $critique], 25 * 3600);
}
[$voteC] = Moderate::canVote($pdo, $critique);
$modC = Moderate::getReputation($pdo, $critique);
$voteC && $modC['reputation'] === Moderate::config()->reputationInitiale && $modC['vote_muted_until'] === 0
    ? ok('⭐ un jetable lié par une seule réponse ne fait ni suspendre ni pénaliser le critique, sur trois épisodes')
    : nok('⭐ le jetable a fait punir le critique : ' . json_encode([$voteC, $modC]));

// ── 24quater. ⭐ Une meute qui frappe toutes les 23 h monte quand même de rang ─
// L'épisode se compte depuis son début. Compté depuis la dernière détection,
// une meute réglée juste sous le délai resterait au premier rang pour toujours.
$q1 = membre($pdo, 'cadence-1');
$q2 = membre($pdo, 'cadence-2');
for ($k = 1; $k <= 3; $k++) {
    episodeMeute($pdo, [$q1, $q2], "cadence-victime-$k");
    vieillirSanctions($pdo, [$q1, $q2], 23 * 3600);
}
Moderate::rangDe($pdo, $q1) === 2
    ? ok('⭐ trois épisodes à 23 h d\'intervalle : le rang monte 24 h après le début de l\'épisode')
    : nok('⭐ l\'épisode glisse : rang ' . Moderate::rangDe($pdo, $q1) . ' après 46 h de meute');

// ── 24quinquies. ⭐ Un tiers ne fait pas monter le rang de qui n'a rien refait ─
// A vote une fois ; B, lié à A, vote après lui et tombe. Plus d'un jour après, B
// revote seul : le vote de A est encore dans la fenêtre, le groupe se reforme.
// A n'a rien fait depuis son épisode — il ne doit pas en recevoir un second.
$cibleT = membre($pdo, 'cible-du-tiers');
$seulA  = membre($pdo, 'premier-seul');
$tiersB = membre($pdo, 'tiers-qui-revote');
echangePrive($pdo, $seulA, $tiersB);
$postT1 = message($pdo, $cibleT, 'fil-tiers-1');
Moderate::applyVote($pdo, $seulA, 'post', $postT1, -1, motif(), 'agressif');
Moderate::applyVote($pdo, $tiersB, 'post', $postT1, -1, motif(), 'agressif');
vieillirSanctions($pdo, [$seulA, $tiersB], 25 * 3600);
$pdo->prepare('UPDATE mod_votes SET created_at = created_at - ? WHERE voter_id IN (?, ?)')->execute([25 * 3600, $seulA, $tiersB]);
$postT2 = message($pdo, $cibleT, 'fil-tiers-2');
Moderate::applyVote($pdo, $tiersB, 'post', $postT2, -1, motif(), 'agressif');
$modSeulA = Moderate::getReputation($pdo, $seulA);
Moderate::rangDe($pdo, $seulA) === 1 && $modSeulA['review_reason'] !== 'meute_recidive' && Moderate::rangDe($pdo, $tiersB) === 2
    ? ok('⭐ un tiers lié qui revote monte de rang seul : le premier votant, qui n\'a rien refait, reste au rang 1')
    : nok('⭐ un tiers fait monter le rang du premier votant : A=' . Moderate::rangDe($pdo, $seulA) . ' '
        . json_encode($modSeulA['review_reason']) . ' B=' . Moderate::rangDe($pdo, $tiersB));

// ── 25. La config est la seule source des seuils ────────────────────────────
// Deux sources pour un seuil, c'est un seuil que la config ne règle qu'à
// moitié : `Config::prod()` ne changeait que les durées de ban, le moteur
// lisant ses propres constantes pour tout le reste.
$jumelles = array_values(array_filter(
    array_keys((new ReflectionClass(Moteur::class))->getConstants()),
    static fn (string $c): bool => !str_starts_with($c, 'REASON_')
));
$jumelles === []
    ? ok('le moteur ne garde aucune constante de seuil à côté de la config')
    : nok('constantes de seuil encore dans le moteur : ' . implode(', ', $jumelles));

$recent = membre($pdo, 'recent-config');
$pdo->prepare('UPDATE accounts SET created_at = ? WHERE id = ?')->execute([time() - 600, $recent]);
Moderate::setConfig(new Config(ageMinPourVoterSecondes: 3600));
[$refuse, $attente] = Moderate::canVote($pdo, $recent);
Moderate::setConfig(null);
[$accepteEnDemo] = Moderate::canVote($pdo, $recent);
!$refuse && str_contains($attente, '50 minutes') && $accepteEnDemo
    ? ok('l\'anti-Sybil suit la config et dit l\'attente restante : « ' . $attente . ' »')
    : nok('anti-Sybil sourd à la config : refus=' . var_export(!$refuse, true) . ' « ' . $attente . ' » démo=' . var_export($accepteEnDemo, true));

// Les points rendus ET l'horloge qui les compte : avancée d'un autre pas que
// l'intervalle, elle rendrait les points suivants plus tôt ou plus tard.
$lent = membre($pdo, 'convalescent-config');
poserReputation($pdo, $lent, 2);
$departRegen = time() - 600;
$pdo->prepare('UPDATE member_moderation SET convalescent = 1, last_regen_at = ? WHERE account_id = ?')
    ->execute([$departRegen, $lent]);
Moderate::setConfig(new Config(intervalleConvalescenceSecondes: 60));
Moderate::regenerate($pdo, $lent);
$remonte = reputation($pdo, $lent);
Moderate::setConfig(null);
$horlogeRegen = (int) etatBan($pdo, $lent)['last_regen_at'] - $departRegen;
$remonte === 12 && $horlogeRegen === 600
    ? ok('la convalescence suit l\'intervalle de la config : +10 en 10 intervalles, horloge avancée d\'autant')
    : nok('convalescence sourde à la config : ' . $remonte . ' au lieu de 12, horloge avancée de ' . $horlogeRegen . ' s au lieu de 600');

// Chaque seuil, réglé autrement que par défaut, et sa borne visée à la valeur
// réglée — des deux côtés pour une fenêtre de temps, où une unité fausse
// passerait d'un seul côté. Un moteur dont aucun lecteur ne suit la config
// garderait la borne par défaut ; un seuil lu à plusieurs endroits est gardé
// dans son ensemble, pas lecteur par lecteur. La fenêtre du farming, par
// exemple, n'est visée que sur les votes positifs : celle des votes négatifs et
// celle du plafond de cibles ne le sont pas.
//
// Le balayage passe en premier : sa tranche neuve rejuge tout le site sous les
// autres seuils par défaut, et rejugerait les cas qui suivent.
$tranchesAvant = $pdo->query('SELECT slot FROM mod_sweeps')->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec('DELETE FROM mod_sweeps');
try {
    $balaye = sousConfig(new Config(balayagePeriodeSecondes: 7), static function () use ($pdo): array {
        $avantVote = intdiv(time(), 7);
        Moderate::applyVote($pdo, membre($pdo, 'balaie-config'), 'member', membre($pdo, 'balayee-config'), -1, motif(), 'agressif');
        return [$avantVote, (int) $pdo->query('SELECT MAX(slot) FROM mod_sweeps')->fetchColumn(), intdiv(time(), 7)];
    });
} finally {
    $pdo->exec('DELETE FROM mod_sweeps');
    foreach ($tranchesAvant as $slot) {
        $pdo->prepare('INSERT INTO mod_sweeps (slot) VALUES (?)')->execute([$slot]);
    }
}
$balaye[1] >= $balaye[0] && $balaye[1] <= $balaye[2]
    ? ok('la tranche de balayage se découpe selon la période de la config')
    : nok('seuil sourd à la config (balayagePeriodeSecondes) : tranche ' . $balaye[1] . ' hors de ' . $balaye[0] . '…' . $balaye[2]);

$neuf = sousConfig(new Config(reputationInitiale: 13), static fn (): int => reputation($pdo, membre($pdo, 'neuf-config')));
$neuf === 13
    ? ok('un compte neuf démarre à la réputation de la config')
    : nok("seuil sourd à la config (reputationInitiale) : $neuf au lieu de 13");

$plafonne = membre($pdo, 'plafonne-config');
$auPlafond = sousConfig(new Config(reputationMax: 21), static function () use ($pdo, $plafonne): array {
    Moderate::applyVote($pdo, membre($pdo, 'pouce-1'), 'member', $plafonne, 1);
    Moderate::applyVote($pdo, membre($pdo, 'pouce-2'), 'member', $plafonne, 1);
    return [reputation($pdo, $plafonne)];
});
$auPlafond === [21]
    ? ok('le plafond de réputation suit la config : un pouce de plus ne monte pas')
    : nok('seuil sourd à la config (reputationMax) : ' . $auPlafond[0] . ' au lieu de 21');

$retire = membre($pdo, 'retire-config');
$sousSeuil = sousConfig(new Config(perteDroitDeVoteSous: 9), static function () use ($pdo, $retire): array {
    poserReputation($pdo, $retire, 9);
    Moderate::applyVote($pdo, membre($pdo, 'retire-votant'), 'member', $retire, -1, motif(), 'hors_sujet');
    return Moderate::getReputation($pdo, $retire);
});
$sousSeuil['reputation'] === 8 && !$sousSeuil['voting_rights']
    ? ok('le droit de vote se perd sous le seuil de la config')
    : nok('seuil sourd à la config (perteDroitDeVoteSous) : ' . json_encode($sousSeuil));

$plancherRep = membre($pdo, 'plancher-config');
$auPlancher = sousConfig(new Config(banA: 3), static function () use ($pdo, $plancherRep): array {
    poserReputation($pdo, $plancherRep, 4);
    Moderate::applyVote($pdo, membre($pdo, 'plancher-a'), 'member', $plancherRep, -1, motif(), 'agressif');
    Moderate::applyVote($pdo, membre($pdo, 'plancher-b'), 'member', $plancherRep, -1, motif(), 'agressif');
    return Moderate::getReputation($pdo, $plancherRep);
});
$auPlancher['reputation'] === 3 && $auPlancher['review_reason'] === 'reputation_zero'
    ? ok('la réputation s\'arrête au plancher de la config, et le signalement y tombe')
    : nok('seuil sourd à la config (banA) : ' . json_encode($auPlancher));

// Trois votes, les deux premiers 570 ou 630 secondes avant le troisième.
$salveFenetre = sousConfig(new Config(fenetreSalveSecondes: 600), static function () use ($pdo): array {
    $raisons = [];
    foreach (['dedans' => 570, 'dehors' => 630] as $cote => $ecart) {
        $cible = membre($pdo, "salve-$cote-config");
        foreach ([membre($pdo, "salve-$cote-a"), membre($pdo, "salve-$cote-b")] as $v) {
            Moderate::applyVote($pdo, $v, 'member', $cible, -1, motif(), 'agressif');
        }
        $pdo->exec('UPDATE mod_votes SET created_at = ' . (time() - $ecart) . ' WHERE target_author = ' . $cible);
        Moderate::applyVote($pdo, membre($pdo, "salve-$cote-c"), 'member', $cible, -1, motif(), 'agressif');
        $raisons[$cote] = Moderate::getReputation($pdo, $cible)['review_reason'];
    }
    return $raisons;
});
$salveFenetre === ['dedans' => 'salve_rapide', 'dehors' => null]
    ? ok('la salve se mesure sur la fenêtre de la config, des deux côtés de la borne')
    : nok('seuil sourd à la config (fenetreSalveSecondes) : ' . json_encode($salveFenetre));

$salveCourte = membre($pdo, 'salve-courte-config');
$etatSalveCourte = sousConfig(new Config(salveVotantsMin: 2), static function () use ($pdo, $salveCourte): array {
    foreach ([membre($pdo, 'salve-courte-a'), membre($pdo, 'salve-courte-b')] as $v) {
        Moderate::applyVote($pdo, $v, 'member', $salveCourte, -1, motif(), 'agressif');
    }
    return Moderate::getReputation($pdo, $salveCourte);
});
$etatSalveCourte['review_reason'] === 'salve_rapide'
    ? ok('la salve compte ses votants selon la config : deux suffisent quand elle le dit')
    : nok('seuil sourd à la config (salveVotantsMin) : ' . json_encode($etatSalveCourte['review_reason']));

// La fenêtre de meute borne deux choses : l'âge de l'échange qui lie, et l'âge
// des votes que la détection rapproche. Une heure de part et d'autre de trois jours.
$cotesMeute = ['dedans' => 3 * 86400 - 3600, 'dehors' => 3 * 86400 + 3600];
$lienFenetre = sousConfig(new Config(fenetreMeuteJours: 3), static function () use ($pdo, $cotesMeute): array {
    $bloques = [];
    foreach ($cotesMeute as $cote => $age) {
        $cible = membre($pdo, "lien-$cote-config");
        [$x, $y] = [membre($pdo, "lien-$cote-a"), membre($pdo, "lien-$cote-b")];
        foreach ([[$x, $y], [$y, $x]] as [$de, $vers]) {
            $pdo->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$de, $vers, 'chiffre', time() - $age]);
        }
        Moderate::applyVote($pdo, $x, 'member', $cible, -1, motif(), 'agressif');
        Moderate::applyVote($pdo, $y, 'member', $cible, -1, motif(), 'agressif');
        $bloques[$cote] = bloquesMeute($pdo, $cible);
    }
    return $bloques;
});
$lienFenetre === ['dedans' => 1, 'dehors' => 0]
    ? ok('un échange lie deux votants tant qu\'il tient dans la fenêtre de meute de la config')
    : nok('seuil sourd à la config (fenetreMeuteJours) : lien ' . json_encode($lienFenetre));

$votesFenetre = sousConfig(new Config(fenetreMeuteJours: 3), static function () use ($pdo, $cotesMeute): array {
    $bloques = [];
    foreach ($cotesMeute as $cote => $age) {
        $cible = membre($pdo, "votes-$cote-config");
        [$x, $y] = [membre($pdo, "votes-$cote-a"), membre($pdo, "votes-$cote-b")];
        echangePrive($pdo, $x, $y);
        Moderate::applyVote($pdo, $x, 'member', $cible, -1, motif(), 'agressif');
        $pdo->exec('UPDATE mod_votes SET created_at = ' . (time() - $age) . ' WHERE voter_id = ' . $x . ' AND target_author = ' . $cible);
        Moderate::applyVote($pdo, $y, 'member', $cible, -1, motif(), 'agressif');
        $bloques[$cote] = bloquesMeute($pdo, $cible);
    }
    return $bloques;
});
$votesFenetre === ['dedans' => 1, 'dehors' => 0]
    ? ok('la détection ne rapproche que les votes que la fenêtre de meute de la config contient')
    : nok('seuil sourd à la config (fenetreMeuteJours) : votes ' . json_encode($votesFenetre));

$duoSeul = membre($pdo, 'duo-seul-config');
$bloquesDuo = sousConfig(new Config(meuteLiensMin: 3), static function () use ($pdo, $duoSeul): int {
    [$x, $y] = [membre($pdo, 'duo-seul-a'), membre($pdo, 'duo-seul-b')];
    echangePrive($pdo, $x, $y);
    Moderate::applyVote($pdo, $x, 'member', $duoSeul, -1, motif(), 'agressif');
    Moderate::applyVote($pdo, $y, 'member', $duoSeul, -1, motif(), 'agressif');
    return bloquesMeute($pdo, $duoSeul);
});
$bloquesDuo === 0
    ? ok('la taille minimale d\'une meute suit la config : un duo n\'en est plus une à trois')
    : nok("seuil sourd à la config (meuteLiensMin) : $bloquesDuo vote(s) annulé(s) au lieu de 0");

// Le second épisode, une minute avant ou après la fin d'un épisode d'une heure.
$rangsRecidive = sousConfig(new Config(meuteEpisodeCooldown: 3600), static function () use ($pdo): array {
    $rangs = [];
    foreach (['dedans' => 3540, 'dehors' => 3660] as $cote => $recul) {
        $duo = [membre($pdo, "recidive-$cote-a"), membre($pdo, "recidive-$cote-b")];
        episodeMeute($pdo, $duo, "recidive-$cote-1");
        vieillirSanctions($pdo, $duo, $recul);
        episodeMeute($pdo, $duo, "recidive-$cote-2");
        $rangs[$cote] = Moderate::rangDe($pdo, $duo[0]);
    }
    return $rangs;
});
$rangsRecidive === ['dedans' => 1, 'dehors' => 2]
    ? ok('un épisode de meute dure ce que dit la config : le rang monte après, pas avant')
    : nok('seuil sourd à la config (meuteEpisodeCooldown) : rangs ' . json_encode($rangsRecidive));

// Les pouces déjà donnés, une heure de part et d'autre de sept jours.
$farmingFenetre = sousConfig(new Config(fenetreFarmingJours: 7), static function () use ($pdo): array {
    $bloque = [];
    foreach (['dedans' => 7 * 86400 - 3600, 'dehors' => 7 * 86400 + 3600] as $cote => $age) {
        $fidele = membre($pdo, "fidele-$cote-config");
        $fan = membre($pdo, "fan-$cote-config");
        for ($k = 1; $k <= Moderate::config()->farmingUpvotesMax; $k++) {
            Moderate::applyVote($pdo, $fan, 'post', message($pdo, $fidele, "fil-fidele-$cote-$k"), 1);
        }
        $pdo->exec('UPDATE mod_votes SET created_at = ' . (time() - $age) . ' WHERE voter_id = ' . $fan);
        $r = Moderate::applyVote($pdo, $fan, 'post', message($pdo, $fidele, "fil-fidele-$cote-retour"), 1);
        $bloque[$cote] = !empty($r['blocked']);
    }
    return $bloque;
});
$farmingFenetre === ['dedans' => true, 'dehors' => false]
    ? ok('la fenêtre du farming suit la config, des deux côtés de la borne')
    : nok('seuil sourd à la config (fenetreFarmingJours) : bloqué ' . json_encode($farmingFenetre));

$choye = membre($pdo, 'choye-config');
$repChoye = sousConfig(new Config(farmingUpvotesMax: 1), static function () use ($pdo, $choye): int {
    $fan = membre($pdo, 'fan-unique-config');
    Moderate::applyVote($pdo, $fan, 'post', message($pdo, $choye, 'fil-choye-1'), 1);
    Moderate::applyVote($pdo, $fan, 'post', message($pdo, $choye, 'fil-choye-2'), 1);
    return reputation($pdo, $choye);
});
$repChoye === 21
    ? ok('le plafond de pouces par votant suit la config : le second est neutralisé')
    : nok("seuil sourd à la config (farmingUpvotesMax) : réputation $repChoye au lieu de 21");

$use = membre($pdo, 'use-config');
$repUse = sousConfig(new Config(farmingDownvotesMax: 1), static function () use ($pdo, $use): int {
    $acharne = membre($pdo, 'acharne-config');
    Moderate::applyVote($pdo, $acharne, 'post', message($pdo, $use, 'fil-use-1'), -1, motif(), 'hors_sujet');
    Moderate::applyVote($pdo, $acharne, 'post', message($pdo, $use, 'fil-use-2'), -1, motif(), 'hors_sujet');
    return reputation($pdo, $use);
});
$repUse === 19
    ? ok('le plafond de votes négatifs par votant suit la config : le second est neutralisé')
    : nok("seuil sourd à la config (farmingDownvotesMax) : réputation $repUse au lieu de 19");

$troisieme = sousConfig(new Config(ciblesParVotantMax: 2), static function () use ($pdo): array {
    $large = membre($pdo, 'large-config');
    $r = [];
    foreach (['visee-config-1', 'visee-config-2', 'visee-config-3'] as $nom) {
        $r = Moderate::applyVote($pdo, $large, 'member', membre($pdo, $nom), -1, motif(), 'agressif');
    }
    return $r;
});
($troisieme['blocked_reason'] ?? '') === 'plafond_votant'
    ? ok('le nombre de membres qu\'un votant peut viser suit la config')
    : nok('seuil sourd à la config (ciblesParVotantMax) : ' . json_encode($troisieme));

// ── Le ban tracé : un journal factice, relu par le banc ─────────────────────
final class JournalDeBanc implements Pierroons\SelfModerate\Journal
{
    /** @var list<array<string, mixed>> */
    public array $actes = [];

    public function inscrire(array $acte): void
    {
        $this->actes[] = $acte;
    }

    /** @return list<array<string, mixed>> */
    public function de(int $compte, string $acte): array
    {
        return array_values(array_filter($this->actes, static fn (array $a): bool =>
            $a['compte'] === $compte && $a['acte'] === $acte));
    }
}

final class JournalEnPanne implements Pierroons\SelfModerate\Journal
{
    public function inscrire(array $acte): void
    {
        throw new RuntimeException('journal indisponible');
    }
}

function etatBan(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM member_moderation WHERE account_id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/** Recule une peine dans le temps : début et fin décalés de $secondes. */
function vieillirBan(PDO $pdo, int $id, int $secondes): void
{
    $pdo->prepare('UPDATE member_moderation SET ban_debut = ban_debut - ?, banned_until = banned_until - ? WHERE account_id = ?')
        ->execute([$secondes, $secondes, $id]);
}

/**
 * Ce que ferait la première écriture sur ce compte : inscrire ce que le temps lui
 * a fait. Une lecture ne l'inscrit pas ; le vote, un geste ou le balayage le font.
 */
function rattraper(PDO $pdo, int $id): void
{
    (new ReflectionMethod(Moteur::class, 'rattraperSeul'))->invoke(null, $pdo, $id);
}

$journal = new JournalDeBanc();
Moderate::setJournal($journal);

// ── 26. À zéro, avec journal : ban gradué, écrit avant la base ──────────────
$banni = membre($pdo, 'banni-auto');
poserReputation($pdo, $banni, 1);
$vx = membre($pdo, 'votant-x');
Moderate::applyVote($pdo, $vx, 'member', $banni, -1, motif(), 'agressif');
$e = Moderate::getReputation($pdo, $banni);
$debut = $journal->de($banni, 'ban_debut');
$e['banned'] && $e['ban_origine'] === 'auto' && $e['strikes'] === 1 && count($debut) === 1
    && $debut[0]['origine'] === 'auto' && $debut[0]['motif'] === 'reputation_zero'
    && $debut[0]['duree'] === Moderate::config()->dureeBanPourEpisode(1)
    ? ok('à zéro, le ban automatique tombe pour ' . Moderate::dureeEnClair($debut[0]['duree']) . ', et le journal l\'a reçu')
    : nok('ban automatique absent ou non tracé : ' . json_encode([$e, $debut]));

$vy = membre($pdo, 'votant-y');
$finAvant = $e['banned_until'];
Moderate::applyVote($pdo, $vy, 'member', $banni, -1, motif(), 'agressif');
Moderate::getReputation($pdo, $banni)['banned_until'] === $finAvant && count($journal->de($banni, 'ban_debut')) === 1
    ? ok('une peine en cours ne se rallonge pas au vote contraire suivant')
    : nok('la peine a reculé ou s\'est doublée');

// ── 27. La fin : réputation au départ, strikes gardés, même motif ────────────
vieillirBan($pdo, $banni, 10_000);
rattraper($pdo, $banni);
$e = Moderate::getReputation($pdo, $banni);
$fin = $journal->de($banni, 'ban_fin');
!$e['banned'] && $e['reputation'] === Moderate::config()->reputationInitiale && $e['strikes'] === 1
    && $e['voting_rights'] && $e['review_reason'] !== 'ban_auto'
    && count($fin) === 1 && $fin[0]['motif'] === 'reputation_zero' && $fin[0]['origine'] === 'auto'
    && isset($fin[0]['observe_a'], $fin[0]['jusqu_a'])
    ? ok('à la fin, la réputation revient à ' . $e['reputation'] . ', le strike reste, et le journal dit la même cause qu\'au début')
    : nok('fin de ban mal refermée : ' . json_encode([$e, $fin]));

poserReputation($pdo, $banni, 1);
$vz = membre($pdo, 'votant-z');
Moderate::applyVote($pdo, $vz, 'member', $banni, -1, motif(), 'agressif');
$second = $journal->de($banni, 'ban_debut');
count($second) === 2 && $second[1]['episode'] === 2 && $second[1]['duree'] === Moderate::config()->dureeBanPourEpisode(2)
    ? ok('le ban suivant monte d\'un palier : ' . Moderate::dureeEnClair($second[1]['duree']))
    : nok('le second ban n\'a pas monté d\'un palier : ' . json_encode($second));

// ── 28. Le balayage referme ce que personne n'a regardé ─────────────────────
vieillirBan($pdo, $banni, 10_000);
$closAvant = count($journal->de($banni, 'ban_fin'));
Moderate::balayerBansEchus($pdo) >= 1 && count($journal->de($banni, 'ban_fin')) === $closAvant + 1
    ? ok('balayerBansEchus() referme une peine échue et l\'inscrit')
    : nok('le balayage n\'a rien refermé');

// ── 29. Le ban d'arbitre : motif exigé, signé, et sans effet sur la réputation ─
$cible = membre($pdo, 'banni-admin');
poserReputation($pdo, $cible, 15);
$sansMotif = Moderate::adminBan($pdo, $cible, 'arbitre-banc', 'parce que');
$r = Moderate::adminBan($pdo, $cible, 'arbitre-banc', motif());
$doublon = Moderate::adminBan($pdo, $cible, 'arbitre-banc', motif());
$dA = $journal->de($cible, 'ban_debut');
!$sansMotif['ok'] && $r['ok'] && !$doublon['ok'] && count($dA) === 1 && $dA[0]['origine'] === 'admin'
    && $dA[0]['arbitre'] === 'arbitre-banc' && $dA[0]['duree'] === Moderate::config()->dureeBanAdmin()
    ? ok('le ban d\'arbitre exige un motif, se signe, prend dureeBanAdmin() et ne s\'empile pas')
    : nok('ban d\'arbitre mal tenu : ' . json_encode([$sansMotif, $r, $doublon, $dA]));

vieillirBan($pdo, $cible, 10_000);
rattraper($pdo, $cible);
$e = Moderate::getReputation($pdo, $cible);
$finA = $journal->de($cible, 'ban_fin');
!$e['banned'] && $e['reputation'] === 15 && count($finA) === 1 && $finA[0]['origine'] === 'admin'
    ? ok('la fin d\'un ban d\'arbitre est tracée comme telle, et laisse la réputation où elle était')
    : nok('fin de ban d\'arbitre : ' . json_encode([$e, $finA]));

// ── 30. Le plancher : avant lui, pas de grâce sans motif ────────────────────
Moderate::adminBan($pdo, $cible, 'arbitre-banc', motif());
$refus = Moderate::adminPardon($pdo, $cible, 'arbitre-banc');
$grace = Moderate::adminPardon($pdo, $cible, 'arbitre-banc', motif());
$leve = $journal->de($cible, 'ban_leve');
!$refus['ok'] && str_contains($refus['message'], 'à partir du') && $grace['ok'] && $grace['anticipee']
    && count($leve) === 1 && $leve[0]['anticipee'] === true && $leve[0]['reste'] > 0 && $leve[0]['cause'] === 'grace'
    && !Moderate::estBanni($pdo, $cible)
    ? ok('avant le plancher, la grâce exige un motif et s\'inscrit comme levée anticipée')
    : nok('plancher non tenu : ' . json_encode([$refus, $grace, $leve]));

Moderate::adminBan($pdo, $cible, 'arbitre-banc', motif());
$pdo->prepare('UPDATE member_moderation SET ban_debut = ?, banned_until = ? WHERE account_id = ?')
    ->execute([time() - 100, time() + 20, $cible]);   // plancher dépassé, peine encore en cours
$libre = Moderate::adminPardon($pdo, $cible, 'arbitre-banc');
$leve = $journal->de($cible, 'ban_leve');
$libre['ok'] && !$libre['anticipee'] && count($leve) === 2 && $leve[1]['anticipee'] === false
    ? ok('après le plancher, la grâce se passe de motif, mais s\'inscrit quand même')
    : nok('grâce après plancher : ' . json_encode([$libre, $leve]));

// ── 30bis. Les durées de peine et le plancher suivent la config ─────────────
// Le pendant du n° 25 pour les seuils que seul un ban réel exerce.
$banCourt = membre($pdo, 'ban-court-config');
$dureeAuto = sousConfig(new Config(dureesBan: [77]), static function () use ($pdo, $banCourt): int {
    poserReputation($pdo, $banCourt, 1);
    Moderate::applyVote($pdo, membre($pdo, 'ban-court-votant'), 'member', $banCourt, -1, motif(), 'agressif');
    $e = etatBan($pdo, $banCourt);
    return (int) $e['banned_until'] - (int) $e['ban_debut'];
});
$dureeAuto === 77
    ? ok('le ban automatique dure le palier de la config')
    : nok("seuil sourd à la config (dureesBan) : $dureeAuto s au lieu de 77");

$banArbitre = membre($pdo, 'ban-arbitre-config');
$dureeArbitre = sousConfig(new Config(dureeBanAdminSecondes: 99), static function () use ($pdo, $banArbitre): int {
    Moderate::adminBan($pdo, $banArbitre, 'arbitre-banc', motif());
    $e = etatBan($pdo, $banArbitre);
    return (int) $e['banned_until'] - (int) $e['ban_debut'];
});
$dureeArbitre === 99
    ? ok('le ban d\'arbitre dure ce que dit la config')
    : nok("seuil sourd à la config (dureeBanAdminSecondes) : $dureeArbitre s au lieu de 99");

$sansPlancher = membre($pdo, 'sans-plancher-config');
$graceImmediate = sousConfig(new Config(plancherFraction: 0.0), static function () use ($pdo, $sansPlancher): array {
    Moderate::adminBan($pdo, $sansPlancher, 'arbitre-banc', motif());
    return Moderate::adminPardon($pdo, $sansPlancher, 'arbitre-banc');
});
($graceImmediate['ok'] ?? false) && !($graceImmediate['anticipee'] ?? true)
    ? ok('sans plancher dans la config, la grâce se passe de motif dès le début de la peine')
    : nok('seuil sourd à la config (plancherFraction) : ' . json_encode($graceImmediate, JSON_UNESCAPED_UNICODE));

// ── 31. Le maintien prend acte d'un ban automatique, et de lui seul ─────────
// Un ban d'arbitre a déjà eu son regard humain : rien à y maintenir. Sur un ban
// automatique, le signal du ban sort de la file — il reste en base, vu, jusqu'à
// la fin de la peine — et la salve qui l'accompagne reste ouverte : elle se
// classe avec son propre motif. Un second maintien n'a plus rien à voir.
Moderate::adminBan($pdo, $cible, 'arbitre-banc', motif());
planterSignal($pdo, $cible, 'salve_rapide');
$surArbitre = Moderate::adminMaintenir($pdo, $cible, 'arbitre-banc', motif());
$auto31 = membre($pdo, 'maintien-auto');
poserReputation($pdo, $auto31, 1);
planterSignal($pdo, $auto31, 'salve_rapide');
Moderate::applyVote($pdo, membre($pdo, 'votant-maintien'), 'member', $auto31, -1, motif(), 'agressif');
$m = Moderate::adminMaintenir($pdo, $auto31, 'arbitre-banc', motif());
$e = Moderate::getReputation($pdo, $auto31);
$acte31 = $journal->de($auto31, 'ban_maintenu');
$vus31 = array_column(array_filter($e['signaux'] ?? [], static fn (array $s): bool => $s['vu_le'] !== null), 'cause');
!$surArbitre['ok'] && $m['ok'] && $e['banned'] && $e['review_reason'] === 'salve_rapide' && $vus31 === ['ban_auto']
    && count($acte31) === 1 && ($acte31[0]['raison'] ?? null) === 'ban_auto'
    && array_column(liste($acte31[0]['ferme'] ?? null) ?? [], 'cause') === ['ban_auto']
    && !Moderate::adminMaintenir($pdo, $auto31, 'arbitre-banc', motif())['ok']
    && !in_array($auto31, array_column(array_filter(Moderate::bansEnCours($pdo, 1000), static fn (array $b): bool => $b['a_revoir']), 'account_id'), true)
    && !in_array([$auto31, 'ban_auto'], array_map(static fn (array $s): array => [$s['account_id'], $s['cause']], Moderate::signauxOuverts($pdo, 1000)), true)
    ? ok('le maintien prend acte du ban automatique seul, la peine court, la salve reste à classer, et le journal le dit')
    : nok('maintien : ' . json_encode([$surArbitre, $m, $e, $acte31], JSON_UNESCAPED_UNICODE));

// ── 31bis. ⭐ La grâce lève une suspension de vote et efface le rang de meute ─
// La grâce lève aussi ce que la réputation ne dit pas : une suspension de vote
// et le rang de meute. L'historique des épisodes reste lisible.
$gracie = membre($pdo, 'gracie-meute');
Moderate::ensureRow($pdo, $gracie);
$pdo->prepare('UPDATE member_moderation SET vote_muted_until = ? WHERE account_id = ?')->execute([time() + 7 * 86400, $gracie]);
foreach ([1, 2, 3] as $r) {
    $pdo->prepare("INSERT INTO mod_pack_flags (voter_id, target_author, rang, action, detected_at) VALUES (?, ?, ?, 'avertissement', ?)")
        ->execute([$gracie, $gracie, $r, time() - (4 - $r) * 86400]);
}
[$avantGrace] = Moderate::canVote($pdo, $gracie);
$grace = Moderate::adminPardon($pdo, $gracie, 'arbitre-banc', motif());
[$apresGrace] = Moderate::canVote($pdo, $gracie);
$traceGrace = $journal->de($gracie, 'grace');
$lignesGrace = (int) $pdo->query("SELECT COUNT(*) FROM mod_pack_flags WHERE voter_id = $gracie")->fetchColumn();
!$avantGrace && $grace['ok'] && $apresGrace && Moderate::rangDe($pdo, $gracie) === 0 && count($traceGrace) === 1
    && ($traceGrace[0]['rang_efface'] ?? null) === 3 && ($traceGrace[0]['suspension_levee'] ?? null) === true
    && $lignesGrace === 4
    ? ok('⭐ la grâce rend le droit de vote, efface le rang sans effacer l\'historique, et le journal le dit')
    : nok('⭐ la grâce laisse la suspension ou le rang : ' . json_encode([$avantGrace, $grace, $apresGrace,
        Moderate::rangDe($pdo, $gracie), $traceGrace, $lignesGrace]));

// ── 31ter. ⭐ Classer un signalement ne touche rien d'autre ──────────────────
// La grâce remet la réputation au départ : lever un signalement par elle ferait
// perdre ses points à un membre que l'arbitre a seulement regardé.
$classe = membre($pdo, 'signal-a-classer');
poserReputation($pdo, $classe, 27);
planterSignal($pdo, $classe, 'votant_etendu');
if (!method_exists(Moderate::class, 'adminClasser')) {
    nok('⭐ aucun geste ne classe un signalement sans gracier le compte');
} else {
    $sansMotifC = Moderate::adminClasser($pdo, $classe, 'arbitre-banc', 'vu');
    $classeOk   = Moderate::adminClasser($pdo, $classe, 'arbitre-banc', motif());
    $etatC      = Moderate::getReputation($pdo, $classe);
    $traceC     = $journal->de($classe, 'revue_classee');
    !$sansMotifC['ok'] && $classeOk['ok'] && !$etatC['needs_review'] && $etatC['reputation'] === 27
        && count($traceC) === 1 && ($traceC[0]['raison'] ?? null) === 'votant_etendu' && ($traceC[0]['arbitre'] ?? null) === 'arbitre-banc'
        ? ok('⭐ classer un signalement exige un motif, lève le signal seul, et s\'inscrit au journal avec sa cause')
        : nok('⭐ classement mal tenu : ' . json_encode([$sansMotifC, $classeOk, $etatC, $traceC]));
}

// ── 32. La meute détectée lève un ban automatique, jamais un ban d'arbitre ──
$proie = membre($pdo, 'proie-ban-auto');
poserReputation($pdo, $proie, 1);
$m1 = membre($pdo, 'meute-ban-1'); $m2 = membre($pdo, 'meute-ban-2');
echangePrive($pdo, $m1, $m2);
Moderate::applyVote($pdo, $m1, 'member', $proie, -1, motif(), 'agressif');
$banniParMeute = Moderate::estBanni($pdo, $proie);
Moderate::applyVote($pdo, $m2, 'member', $proie, -1, motif(), 'agressif');
$leveM = $journal->de($proie, 'ban_leve');
$banniParMeute && Moderate::estBanni($pdo, $proie) && $leveM === []
    ? ok('le premier votant d\'une paire liée garde son vote : le ban qu\'il a causé seul tient, comme s\'il avait voté seul')
    : nok('un ban causé par un seul votant a été levé par son ami : ' . json_encode([$banniParMeute, $leveM, etatBan($pdo, $proie)]));

$tenu = membre($pdo, 'tenu-par-arbitre');
poserReputation($pdo, $tenu, 1);
Moderate::adminBan($pdo, $cibleArbitre = $tenu, 'arbitre-banc', motif());
$m3 = membre($pdo, 'meute-ban-3'); $m4 = membre($pdo, 'meute-ban-4');
echangePrive($pdo, $m3, $m4);
Moderate::applyVote($pdo, $m3, 'member', $tenu, -1, motif(), 'agressif');
Moderate::applyVote($pdo, $m4, 'member', $tenu, -1, motif(), 'agressif');
Moderate::estBanni($pdo, $tenu) && Moderate::getReputation($pdo, $tenu)['ban_origine'] === 'admin'
    && $journal->de($tenu, 'ban_leve') === []
    ? ok('un ban d\'arbitre survit à l\'annulation d\'une meute : il n\'était pas venu des votes')
    : nok('la meute a levé un ban d\'arbitre : ' . json_encode(etatBan($pdo, $tenu)));

// ── 32bis. ⭐ Une meute ne lève que le ban qu'elle a causé ──────────────────
// Des votes posés PENDANT un ban butent sur le plancher : ils n'ont rien retiré.
// Les annuler ne doit ni lever ce ban, ni rendre des points qu'ils n'ont jamais
// pris — sinon deux comptes liés achètent la fin de n'importe quel ban
// automatique, et l'effacement de ses strikes, pour deux votes.
$honni = membre($pdo, 'banni-par-honnetes');
poserReputation($pdo, $honni, 1);
Moderate::applyVote($pdo, membre($pdo, 'honnete-1'), 'member', $honni, -1, motif(), 'agressif');
$avantSocks = Moderate::getReputation($pdo, $honni);
$s1 = membre($pdo, 'sock-1'); $s2 = membre($pdo, 'sock-2');
echangePrive($pdo, $s1, $s2);
Moderate::applyVote($pdo, $s1, 'member', $honni, -1, motif(), 'agressif');
Moderate::applyVote($pdo, $s2, 'member', $honni, -1, motif(), 'agressif');
$apresSocks = Moderate::getReputation($pdo, $honni);
$annulesSocks = (int) $pdo->query(
    "SELECT COUNT(*) FROM mod_votes WHERE target_author = $honni AND blocked_reason = 'pack_voting'"
)->fetchColumn();
$avantSocks['banned'] && $annulesSocks >= 1 && $apresSocks['banned'] && $apresSocks['strikes'] === $avantSocks['strikes']
    && $apresSocks['reputation'] === 0 && $journal->de($honni, 'ban_leve') === []
    ? ok('⭐ une meute qui vote pendant un ban ne le lève pas, n\'efface aucun strike et ne rend aucun point')
    : nok('⭐ des votes posés au plancher ont levé le ban ou rendu des points : '
        . json_encode([$annulesSocks, $avantSocks, $apresSocks]));

// Le plancher n'est pas la seule garde : des votes positifs reçus PENDANT le
// ban remontent la réputation, et des votes négatifs posés ensuite ont un
// effet. Annulés, ils rendent ce qu'ils ont pris, sans lever un ban qu'ils
// n'ont pas causé. Le ban est reculé d'une minute : ce qui suit vient après lui.
$remonte = membre($pdo, 'remonte-pendant-ban');
poserReputation($pdo, $remonte, 1);
$postR = message($pdo, $remonte, 'fil-remonte');
Moderate::applyVote($pdo, membre($pdo, 'honnete-2'), 'member', $remonte, -1, motif(), 'agressif');
vieillirBan($pdo, $remonte, 60);
Moderate::applyVote($pdo, membre($pdo, 'ami-1'), 'post', $postR, 1);
Moderate::applyVote($pdo, membre($pdo, 'ami-2'), 'post', $postR, 1);
$s3 = membre($pdo, 'sock-3'); $s4 = membre($pdo, 'sock-4');
echangePrive($pdo, $s3, $s4);
Moderate::applyVote($pdo, $s3, 'post', $postR, -1, motif(), 'agressif');
Moderate::applyVote($pdo, $s4, 'post', $postR, -1, motif(), 'agressif');
$apresR = Moderate::getReputation($pdo, $remonte);
$apresR['banned'] && $apresR['reputation'] === 1 && $journal->de($remonte, 'ban_leve') === []
    ? ok('⭐ des votes posés après le début du ban rendent ce qu\'ils ont pris, sans lever un ban qu\'ils n\'ont pas causé')
    : nok('⭐ un ban levé par des votes postérieurs à son début : ' . json_encode($apresR));

// La meute qui a CAUSÉ la chute lève ce ban, et ne rend que ce qu'elle a pris :
// le vote du second, et le strike de ce ban — non autant de strikes que de votes
// annulés. Sans ce second vote, la réputation ne serait pas tombée à zéro.
$chute = membre($pdo, 'chute-par-meute');
poserReputation($pdo, $chute, 2);
$pdo->prepare('UPDATE member_moderation SET strikes = 2 WHERE account_id = ?')->execute([$chute]);
$x1 = membre($pdo, 'lie-apres-1'); $x2 = membre($pdo, 'lie-apres-2');
Moderate::applyVote($pdo, $x1, 'member', $chute, -1, motif(), 'agressif');
Moderate::applyVote($pdo, $x2, 'member', $chute, -1, motif(), 'agressif');
$tombe = Moderate::getReputation($pdo, $chute);
echangePrive($pdo, $x1, $x2);
Moderate::detectPackVoting($pdo, $chute);
$releve = Moderate::getReputation($pdo, $chute);
$tombe['banned'] && $tombe['strikes'] === 3 && !$releve['banned'] && $releve['strikes'] === 2
    && $releve['reputation'] === 1 && count($journal->de($chute, 'ban_leve')) === 1
    ? ok('⭐ la meute qui a causé la chute lève ce ban, rend le point du second votant, et ne retire que le strike de ce ban')
    : nok('⭐ levée sur meute mal bornée : ' . json_encode([$tombe, $releve]));

// Un vote dont l'effet a déjà été effacé par une remise au départ n'est pas
// rendu une seconde fois quand on l'annule : la fin du ban avait tout rendu.
$remis = membre($pdo, 'remis-au-depart');
poserReputation($pdo, $remis, 2);
$y1 = membre($pdo, 'lie-tard-1'); $y2 = membre($pdo, 'lie-tard-2');
Moderate::applyVote($pdo, $y1, 'member', $remis, -1, motif(), 'agressif');
Moderate::applyVote($pdo, $y2, 'member', $remis, -1, motif(), 'agressif');
vieillirBan($pdo, $remis, 10_000);
$depart = Moderate::getReputation($pdo, $remis)['reputation'];
echangePrive($pdo, $y1, $y2);
Moderate::detectPackVoting($pdo, $remis);
$depart === Moderate::config()->reputationInitiale && reputation($pdo, $remis) === $depart
    ? ok('⭐ un vote déjà effacé par la remise au départ n\'est pas rendu une seconde fois')
    : nok('⭐ points rendus deux fois : départ=' . $depart . ', après annulation=' . reputation($pdo, $remis));

// Un vote d'avant la colonne `effet` n'a pas d'effet connu : il ne compte ni
// comme cause d'un ban, ni dans ce qu'une annulation rend. Lu comme sa valeur,
// il rendrait à deux comptes liés d'avant la migration le pouvoir de lever un ban.
$migre = membre($pdo, 'base-migree');
poserReputation($pdo, $migre, 1);
Moderate::applyVote($pdo, membre($pdo, 'honnete-migre'), 'member', $migre, -1, motif(), 'agressif');
$avantMigre = Moderate::getReputation($pdo, $migre);
$anciens = [membre($pdo, 'ancien-lie-1'), membre($pdo, 'ancien-lie-2')];
foreach ($anciens as $k => $ancien) {
    $postM = message($pdo, $migre, "fil-migre-$k");
    $pdo->prepare('INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, created_at)
                   VALUES (?, ?, ?, ?, -1, ?, ?)')
        ->execute([$ancien, 'post', $postM, $migre, motif(), time() - 10 * 86400]);
}
echangePrive($pdo, $anciens[0], $anciens[1]);
Moderate::detectPackVoting($pdo, $migre);
$apresMigre = Moderate::getReputation($pdo, $migre);
$avantMigre['banned'] && $apresMigre['banned'] && $apresMigre['strikes'] === $avantMigre['strikes'] && $apresMigre['reputation'] === 0
    ? ok('⭐ des votes sans effet connu, d\'avant la colonne, ne lèvent pas un ban et ne rendent rien')
    : nok('⭐ des votes d\'avant la colonne lèvent un ban : ' . json_encode([$avantMigre, $apresMigre]));

// ── 32ter. ⭐ Une salve qui mène au ban garde son signal ────────────────────
// Trois comptes sans lien frappent dans la même minute et la cible tombe à
// zéro. Si le signal du ban écrasait celui de la salve et s'effaçait à sa fin,
// l'arbitre ne verrait qu'une chute ordinaire : la liste « à revoir » écarte les
// bans.
$salvee = membre($pdo, 'salvee-puis-bannie');
poserReputation($pdo, $salvee, 3);
foreach (['salve-x', 'salve-y', 'salve-z'] as $nom) {
    Moderate::applyVote($pdo, membre($pdo, $nom), 'member', $salvee, -1, motif(), 'agressif');
}
$pendant = Moderate::getReputation($pdo, $salvee);
vieillirBan($pdo, $salvee, 10_000);
$apres = Moderate::getReputation($pdo, $salvee);
$pendant['banned'] && $pendant['review_reason'] === 'salve_rapide'
    && !$apres['banned'] && $apres['needs_review'] && $apres['review_reason'] === 'salve_rapide'
    ? ok('⭐ une salve qui fait tomber le ban reste signalée pendant la peine et après elle')
    : nok('⭐ le ban a écrasé ou effacé la salve : ' . json_encode([$pendant['review_reason'], $apres['review_reason']]));

// Une coordination espacée n'est reconnue par aucune détection : ce que
// l'arbitre peut en voir, c'est la composition de la chute — qui a voté, l'âge
// de ces comptes, et en combien de temps. Un soutien qui a voté pour le compte
// n'a rien retiré, et un vote d'il y a deux cents jours sort de la fenêtre de
// meute : ni l'un ni l'autre n'en fait partie.
$espacee = membre($pdo, 'chute-espacee');
poserReputation($pdo, $espacee, 2);
Moderate::applyVote($pdo, membre($pdo, 'soutien-ancien'), 'member', $espacee, 1);
$pdo->prepare('INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, effet, created_at)
               VALUES (?, ?, ?, ?, -1, ?, -1, ?)')
    ->execute([membre($pdo, 'votant-d-il-y-a-200-jours'), 'member', $espacee, $espacee, motif(), time() - 200 * 86400]);
foreach ([['jeune-1', 2], ['jeune-2', 2], ['ancien-1', 30]] as [$nom, $jours]) {
    $votantE = membre($pdo, $nom);
    $pdo->prepare('UPDATE accounts SET created_at = ? WHERE id = ?')->execute([time() - $jours * 86400, $votantE]);
    Moderate::applyVote($pdo, $votantE, 'member', $espacee, -1, motif(), 'agressif');
    $pdo->prepare('UPDATE mod_votes SET created_at = created_at - 400 WHERE target_author = ?')->execute([$espacee]);
}
$vueE = array_values(array_filter(Moderate::bansEnCours($pdo), static fn (array $b): bool => $b['account_id'] === $espacee));
$chuteE = $vueE[0]['chute'] ?? null;
count($vueE) === 1 && $vueE[0]['origine'] === 'auto' && $chuteE !== null
    && $chuteE['votants'] === 3 && $chuteE['ages_jours'] === [2, 2, 30] && $chuteE['etalement'] === 800
    ? ok('⭐ l\'arbitre voit la composition d\'un ban automatique : 3 votants, comptes de 2, 2 et 30 jours, sur ' . Moderate::dureeEnClair(800))
    : nok('⭐ composition de la chute absente ou fausse : ' . json_encode($vueE));

// ── 33. La vue de l'arbitre ─────────────────────────────────────────────────
$vue = array_values(array_filter(Moderate::bansEnCours($pdo), static fn (array $b): bool => $b['account_id'] === $tenu));
count($vue) === 1 && $vue[0]['origine'] === 'admin' && $vue[0]['reste'] > 0 && $vue[0]['plancher'] !== null
    && $vue[0]['motif'] !== null && $vue[0]['username'] === 'tenu-par-arbitre'
    ? ok('bansEnCours() rend l\'origine, le motif, le temps restant et le plancher')
    : nok('bansEnCours() : ' . json_encode($vue));

// ── 34. 🔑 Journal en panne : aucun ban sans sa trace ────────────────────────
Moderate::setJournal(new JournalEnPanne());
$sansTrace = membre($pdo, 'journal-en-panne');
poserReputation($pdo, $sansTrace, 1);
$vw = membre($pdo, 'votant-w');
$leve = null;
try {
    Moderate::applyVote($pdo, $vw, 'member', $sansTrace, -1, motif(), 'agressif');
} catch (RuntimeException $ex) {
    $leve = $ex->getMessage();
}
Moderate::setJournal(null);
$leve !== null && (int) etatBan($pdo, $sansTrace)['banned_until'] === 0
    ? ok('un journal en panne empêche le ban : l\'exception remonte et la base n\'a rien reçu')
    : nok('ban posé sans trace, ou panne avalée : ' . json_encode([$leve, etatBan($pdo, $sansTrace)]));

// ── 34bis. ⭐ Un journal en panne ne fait pas tomber les pages ──────────────
// Une peine échue s'inscrit à la première écriture sur le compte, en écrivant
// d'abord au journal. Journal en panne : la lecture montre la peine finie, sans
// rien écrire, et dit que sa fin attend ; seul l'état inscrit autorise une
// écriture, donc le vote du compte est refusé, et dit pourquoi ; l'arbitre est
// prévenu (`journal_en_panne`) par cet écrivain, pas par une page. La clôture
// suit le retour du journal. Le planificateur, lui, laisse remonter l'exception.
Moderate::setJournal($journal);
$echu = membre($pdo, 'peine-echue-journal-mort');
poserReputation($pdo, $echu, 1);
Moderate::applyVote($pdo, membre($pdo, 'votant-echu'), 'member', $echu, -1, motif(), 'agressif');
vieillirBan($pdo, $echu, 10_000);
Moderate::setJournal(new JournalEnPanne());
$leveLecture = null;
$pendantPanne = null;
$voteEchu = null;
try {
    $pendantPanne = Moderate::getReputation($pdo, $echu);
    $lecturePanne = $pendantPanne['review_reason'];
    Moderate::canVote($pdo, $echu);
    $voteEchu = Moderate::applyVote($pdo, $echu, 'member', membre($pdo, 'visee-par-echu'), -1, motif(), 'agressif');
} catch (RuntimeException $ex) {
    $leveLecture = $ex->getMessage();
}
$signalPanne = Moderate::getReputation($pdo, $echu)['review_reason'];
$enBasePanne = (int) etatBan($pdo, $echu)['banned_until'];
$leveBalayage = null;
try {
    Moderate::balayerBansEchus($pdo);
} catch (RuntimeException $ex) {
    $leveBalayage = $ex->getMessage();
}
Moderate::setJournal($journal);
rattraper($pdo, $echu);
$apresPanne = Moderate::getReputation($pdo, $echu);
$leveLecture === null && $pendantPanne !== null && !$pendantPanne['banned'] && $pendantPanne['fin_en_attente']
    && $pendantPanne['reputation'] === Moderate::config()->reputationInitiale && $lecturePanne !== 'journal_en_panne'
    && !($voteEchu['ok'] ?? true) && str_contains($voteEchu['message'] ?? '', 'attend que le journal')
    && $signalPanne === 'journal_en_panne' && $enBasePanne > 0 && $leveBalayage !== null
    && (int) etatBan($pdo, $echu)['banned_until'] === 0 && count($journal->de($echu, 'ban_fin')) === 1
    && $apresPanne['review_reason'] !== 'journal_en_panne' && !$apresPanne['fin_en_attente']
    ? ok('⭐ journal en panne : la lecture tient, la peine échue attend sa trace, l\'arbitre est prévenu, et la clôture suit le retour du journal')
    : nok('⭐ un journal en panne fait tomber la lecture ou referme sans trace : '
        . json_encode([$leveLecture, $pendantPanne['fin_en_attente'] ?? null, $voteEchu, $signalPanne, $enBasePanne, $leveBalayage, etatBan($pdo, $echu)['banned_until']], JSON_UNESCAPED_UNICODE));
Moderate::setJournal(null);

// ── 34ter. ⭐ Pendant une panne, un vote passe, et une salve survit à la clôture ─
// Un compte dont la peine attend sa clôture reste à zéro en base : un vote négatif
// qui tenterait de le rebannir ferait tomber la requête. Et un signal de panne tenu
// pour un comportement empêcherait une salve de s'inscrire, puis l'effacerait
// avec lui à la clôture.
Moderate::setJournal($journal);
$enAttente = membre($pdo, 'cloture-en-attente');
poserReputation($pdo, $enAttente, 1);
Moderate::applyVote($pdo, membre($pdo, 'votant-attente'), 'member', $enAttente, -1, motif(), 'agressif');
vieillirBan($pdo, $enAttente, 10_000);
Moderate::setJournal(new JournalEnPanne());
$leveAttente = null;
$pendantAttente = null;
try {
    Moderate::getReputation($pdo, $enAttente);
    $postAttente = message($pdo, $enAttente, 'fil-attente');
    foreach (['salve-panne-1', 'salve-panne-2', 'salve-panne-3'] as $nom) {
        Moderate::applyVote($pdo, membre($pdo, $nom), 'post', $postAttente, -1, motif(), 'agressif');
    }
    $pendantAttente = Moderate::getReputation($pdo, $enAttente)['review_reason'];
} catch (RuntimeException $ex) {
    $leveAttente = $ex->getMessage();
}
Moderate::setJournal($journal);
rattraper($pdo, $enAttente);
$apresAttente = Moderate::getReputation($pdo, $enAttente);
$leveAttente === null && $pendantAttente === 'salve_rapide' && $apresAttente['review_reason'] === 'salve_rapide'
    && !$apresAttente['banned'] && count($journal->de($enAttente, 'ban_fin')) === 1
    ? ok('⭐ journal en panne : les votes passent, une salve se signale, et son signal survit à la clôture')
    : nok('⭐ panne du journal : ' . json_encode([$leveAttente, $pendantAttente, $apresAttente['review_reason'] ?? null]));
Moderate::setJournal(null);

// ── 35. Le journal du lab : une chaîne signée, lisible par le groupe ────────
require_once __DIR__ . '/../lib/journal_moderation.php';
require_once __DIR__ . '/../lib/attack_sim.php';
putenv("LAB_MODAUDIT_PATH=$dir/modaudit");
$journalLab = new Pierroons\MySelfLab\JournalModeration("$dir/moderation.log");
$journalLab->inscrire(['acte' => 'ban_debut', 'compte' => 1, 'origine' => 'auto']);
$journalLab->inscrire(['acte' => 'ban_fin', 'compte' => 1, 'origine' => 'auto']);
$v = $journalLab->verifier();
clearstatcache();
$modeJournal = fileperms("$dir/moderation.log") & 0777;
$v['ok'] && $v['count'] === 2 && $modeJournal === 0640
    ? ok('le journal du lab chaîne et signe ses actes, et naît en 0640 (lisible par le groupe de la console)')
    : nok('journal du lab : ' . json_encode($v) . ' mode ' . sprintf('%o', $modeJournal));

// ── 36. Le simulateur d'attaques rend le journal du site tel qu'il l'a trouvé ─
// ⚠️ Aucun scénario n'atteint un ban aujourd'hui : ce contrôle garde la
// restitution du journal, pas sa suspension pendant la sandbox.
$journalSite = new JournalDeBanc();
Moderate::setJournal($journalSite);
Pierroons\MySelfLab\AttackSimulator::run('packvoting');
$journalSite->actes === [] && Moderate::journal() === $journalSite
    ? ok('le simulateur rend le journal du site tel qu\'il l\'a trouvé')
    : nok('le simulateur a écrit au journal du site, ou ne l\'a pas rendu : ' . count($journalSite->actes) . ' acte(s)');
Moderate::setJournal(null);

// ── 37. Dans le lab, un ban coupe la publication, pas la connexion ──────────
// Un geste d'arbitre exige un journal (n° 50) : celui du banc, le temps de 37 et 37bis.
Moderate::setJournal(new JournalDeBanc());
require_once __DIR__ . '/../lib/forum.php';
$muet = membre($pdo, 'banni-forum');
Moderate::adminBan($pdo, $muet, 'arbitre-banc', motif());
$refusFil = Pierroons\MySelfLab\Forum::createThread($pdo, $muet, 'un fil', 'general', 'bonjour');
$refusMsg = Pierroons\MySelfLab\Forum::createPost($pdo, $muet, 1, 'bonjour');
Moderate::adminPardon($pdo, $muet, 'arbitre-banc', motif());
$accepte = Pierroons\MySelfLab\Forum::createThread($pdo, $muet, 'un fil', 'general', 'bonjour');
($refusFil['error'] ?? '') === 'compte_banni' && ($refusMsg['error'] ?? '') === 'compte_banni' && $accepte['ok']
    ? ok('un compte banni ne publie ni fil ni message, et publie de nouveau une fois le ban levé')
    : nok('publication pendant un ban : ' . json_encode([$refusFil, $refusMsg, $accepte]));

// ── 37bis. ⭐ Pendant un ban, le profil public est gelé ────────────────────
// La bio et le lien sont publics : un ban qui coupe la parole publique les
// coupe aussi. Sans cette garde, un banni garderait une tribune par son profil.
require_once __DIR__ . '/../lib/profile.php';
$gele = membre($pdo, 'profil-gele');
Moderate::adminBan($pdo, $gele, 'arbitre-banc', motif());
$champsProfil = ['bio' => 'une tribune pendant la peine', 'localisation' => '', 'lien' => 'https://exemple.invalid'];
$refusProfil = Pierroons\MySelfLab\Profile::save($pdo, $gele, $champsProfil);
Moderate::adminPardon($pdo, $gele, 'arbitre-banc', motif());
$accepteProfil = Pierroons\MySelfLab\Profile::save($pdo, $gele, $champsProfil);
($refusProfil['error'] ?? '') === 'compte_banni' && ($accepteProfil['ok'] ?? false)
    ? ok('⭐ un compte banni ne modifie pas son profil public, et le modifie de nouveau une fois le ban levé')
    : nok('⭐ profil public pendant un ban : ' . json_encode([$refusProfil, $accepteProfil]));
Moderate::setJournal(null);

// ── 38. Les textes du lab ne portent aucun seuil à eux ──────────────────────
// Les pages annonçaient « 24 h → 7 j → 30 j → définitive » pendant que le
// moteur appliquait 2 / 10 / 30 minutes et ne bannissait jamais sans fin.
$fautes = [];
foreach (['fr', 'en'] as $langue) {
    $dico = require __DIR__ . '/../lang/' . $langue . '.php';
    foreach (['mod.how.body', 'sec.5.body'] as $cle) {
        $texte = strip_tags($dico[$cle]);
        if (preg_match('/\b(24 ?h|7 ?[jd]|30 ?[jd]|20\/30)\b/iu', $texte, $m)) {
            $fautes[] = "$langue:$cle « {$m[0]} »";
        }
        if (!str_contains($texte, '%1$d') || !preg_match('/%\d\$s/', $texte)) {
            $fautes[] = "$langue:$cle sans valeurs lues";
        }
    }
}
$fautes === []
    ? ok('les textes de modération du lab (fr, en) lisent leurs seuils dans la config')
    : nok('seuil écrit en dur dans un texte : ' . implode(' ; ', $fautes));

// ── Le palier public ne chiffre pas la réputation sous le seuil de sanction ──
// 🔑 Le ban tombe à `banA` et la perte du droit de vote à
// `perteDroitDeVoteSous` : une réputation affichée à 0 DISAIT « banni en ce
// moment », et deux relevés espacés donnaient quand la peine finirait. L'état de
// sanction est pourtant déclaré non public. Une propriété ne se garde pas sur
// deux canaux dont l'un calcule l'autre.
//
// Ce cas éprouve la frontière des deux côtés — c'est elle qui compte, pas les
// libellés — et le fait que les quatre paliers n'ont plus qu'une source : leurs
// seuils étaient recopiés trois fois, avec un 5 en dur dans deux des copies.
require_once __DIR__ . '/../lib/layout.php';
$seuil = Moderate::config()->perteDroitDeVoteSous;
$sous   = palier_reputation($seuil - 1);
$juste  = palier_reputation($seuil);
$banni  = palier_reputation(Moderate::config()->banA ?? 0);
$sous['chiffrable'] === false && $juste['chiffrable'] === true && $banni['chiffrable'] === false
    ? ok("le palier public ne chiffre pas sous $seuil et chiffre à partir de $seuil")
    : nok('la frontière du chiffre ne suit pas le seuil de sanction : ' . json_encode([$sous, $juste, $banni]));

// Et la source est bien unique : la couleur comme le libellé en viennent, donc
// déplacer le seuil dans la configuration déplace les trois d'un coup.
$sous['libelle'] !== '' && $sous['couleur'] !== '' && $juste['libelle'] !== $sous['libelle']
    ? ok('le palier rend son libellé et sa couleur depuis la même source que son seuil')
    : nok('le palier ne porte pas ses trois valeurs : ' . json_encode([$sous, $juste]));

// ── Le coût d'un vote se compte en requêtes ─────────────────────────────────
// Un temps dépend de la machine et ne rougit nulle part ; un nombre d'allers-
// retours avec la base ne dépend que du code. Les trois chemins sont comptés —
// une requête préparée à chaque exécution, `query()` et `exec()` à l'appel —,
// pour qu'un chemin nouveau ne passe pas sous le compteur.
final class PdoCompteur extends PDO
{
    public int $requetes = 0;

    public function __construct(string $dsn)
    {
        parent::__construct($dsn);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RequeteComptee::class, [$this]]);
        parent::exec('PRAGMA foreign_keys = ON');
    }

    public function exec(string $statement): int|false
    {
        $this->requetes++;
        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->requetes++;
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

final class RequeteComptee extends PDOStatement
{
    protected function __construct(private PdoCompteur $connexion) {}

    public function execute(?array $params = null): bool
    {
        $this->connexion->requetes++;
        return parent::execute($params);
    }
}

$compteur = new PdoCompteur('sqlite:' . getenv('LAB_DB_PATH'));

/** Downvotes antidatés d'une heure : dans la fenêtre de meute, hors de celle des salves. */
function votesDeFond(PDO $pdo, int $auteur, int $post, int $combien, string $nom): void
{
    for ($j = 0; $j < $combien; $j++) {
        $pdo->prepare(
            'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, created_at)
             VALUES (?, ?, ?, ?, -1, ?, ?)'
        )->execute([membre($pdo, "fond-$nom-$j"), 'post', $post, $auteur, motif(), time() - 3600]);
    }
}

/** Requêtes que coûte un downvote neuf sur ce message ; null si le vote est refusé. */
function coutDownvote(PdoCompteur $compteur, PDO $pdo, int $post, string $nom): ?int
{
    $votant = membre($pdo, "votant-$nom");
    $compteur->requetes = 0;
    $r = Moderate::applyVote($compteur, $votant, 'post', $post, -1, motif());
    return ($r['ok'] ?? false) && !($r['blocked'] ?? false) ? $compteur->requetes : null;
}

/** Inscrit la tranche en cours et la suivante : un vote mesuré ne balaie pas, même si l'heure tourne. */
function trancheBalayee(PDO $pdo): void
{
    $t = intdiv(time(), Moderate::config()->balayagePeriodeSecondes);
    $pdo->prepare('INSERT OR IGNORE INTO mod_sweeps (slot) VALUES (?), (?)')->execute([$t, $t + 1]);
}

// ── 39. Le coût d'un downvote ne croît pas avec le carré de ses votants ─────
// Une cible déjà visée par quatre votants, une autre par seize, tous sans lien
// entre eux. Les deux sont posées AVANT les deux mesures, pour que le reste de
// la base soit le même pour chacune : seul le graphe de la cible diffère.
// Tester chaque paire par une requête rend 10 puis 136 requêtes pour ce seul
// graphe ; les échanges entre ces votants se lisent en une fois.
trancheBalayee($pdo);
$cibleP = membre($pdo, 'cible-39p');
$postP  = message($pdo, $cibleP, 'fil-39p');
votesDeFond($pdo, $cibleP, $postP, 4, '39p');
$cibleQ = membre($pdo, 'cible-39q');
$postQ  = message($pdo, $cibleQ, 'fil-39q');
votesDeFond($pdo, $cibleQ, $postQ, 16, '39q');
$cinq   = coutDownvote($compteur, $pdo, $postP, '39p');
$dixSept = coutDownvote($compteur, $pdo, $postQ, '39q');
$cinq !== null && $cinq === $dixSept
    ? ok("un downvote coûte $cinq requêtes, que sa cible compte cinq votants ou dix-sept")
    : nok('le coût d\'un downvote croît avec ses votants : ' . json_encode(['5_votants' => $cinq, '17_votants' => $dixSept]));

// ── 40. Un downvote ne paie pas pour l'activité du reste du site ────────────
// ⭐ Le coût d'un vote ne doit dépendre que de sa cible. Mesuré avant puis
// après que vingt autres membres ont été downvotés chacun par deux votants,
// dans une tranche déjà balayée : si chaque vote rebalaie la communauté, son
// coût suit l'activité du site, et n'importe quel compte qui vote tient ce levier.
trancheBalayee($pdo);
$cibleA = membre($pdo, 'cible-40a');
$avant = coutDownvote($compteur, $pdo, message($pdo, $cibleA, 'fil-40a'), '40a');
for ($i = 0; $i < 20; $i++) {
    $autre = membre($pdo, "autre-40-$i");
    votesDeFond($pdo, $autre, message($pdo, $autre, "fil-40-$i"), 2, "40-$i");
}
$cibleB = membre($pdo, 'cible-40b');
$apres = coutDownvote($compteur, $pdo, message($pdo, $cibleB, 'fil-40b'), '40b');
$avant !== null && $avant === $apres
    ? ok("⭐ un downvote coûte $avant requêtes, que vingt autres membres soient en cours de vote ou non")
    : nok('⭐ le coût d\'un downvote suit l\'activité du site : ' . json_encode(['avant' => $avant, 'apres_20_cibles' => $apres]));

// ── 41. Le premier downvote d'une tranche balaie le site, et lui seul ───────
// Le filet a un prix, payé une fois par tranche et par un seul vote : celui
// qui inscrit la tranche. Le suivant la trouve, et revient au coût d'un vote
// qui ne regarde que sa cible.
$pdo->exec('DELETE FROM mod_sweeps');
$tranche  = intdiv(time(), Moderate::config()->balayagePeriodeSecondes);
$cible41a = membre($pdo, 'cible-41a');
$premier  = coutDownvote($compteur, $pdo, message($pdo, $cible41a, 'fil-41a'), '41a');
$inscrite = $pdo->query("SELECT COUNT(*) FROM mod_sweeps WHERE slot >= $tranche")->fetchColumn() > 0;
trancheBalayee($pdo);
$cible41b = membre($pdo, 'cible-41b');
$second   = coutDownvote($compteur, $pdo, message($pdo, $cible41b, 'fil-41b'), '41b');
$premier !== null && $inscrite && $premier > $second && $second === $avant
    ? ok("le premier downvote de la tranche balaie le site ($premier requêtes), le suivant revient à $second")
    : nok('le balayage ne tombe pas sur le seul premier vote de la tranche : '
        . json_encode(['premier' => $premier, 'tranche_inscrite' => $inscrite, 'second' => $second, 'cible_seule' => $avant]));

// ── 42. Une meute liée après coup est rattrapée par la tranche suivante ─────
// ⭐ Ce que le ciblage ne voit pas : deux votants qui s'écrivent APRÈS avoir
// frappé la même cible, sur laquelle personne ne revote. Dans la tranche en
// cours, un vote ailleurs ne la rattrape pas — c'est le prix du ciblage, borné
// à une tranche. Le premier vote de la tranche suivante, si : il annule le vote
// du second, le premier garde le sien.
trancheBalayee($pdo);
$victime = membre($pdo, 'victime-42');
$postV   = message($pdo, $victime, 'fil-42');
foreach (['a', 'b'] as $x) {
    $meute42[$x] = membre($pdo, "meute-42-$x");
    Moderate::applyVote($pdo, $meute42[$x], 'post', $postV, -1, motif());
}
messagePrive($pdo, $meute42['a'], $meute42['b']);
messagePrive($pdo, $meute42['b'], $meute42['a']);
$annules42 = static function () use ($pdo, $victime): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM mod_votes WHERE target_author = ? AND blocked = 1 AND blocked_reason = 'pack_voting'");
    $st->execute([$victime]);
    return (int) $st->fetchColumn();
};
$avantLien = $annules42();
$ailleurs = membre($pdo, 'ailleurs-42a');
coutDownvote($compteur, $pdo, message($pdo, $ailleurs, 'fil-42a'), '42a');
$memeTranche = $annules42();
$pdo->exec('DELETE FROM mod_sweeps');
$ailleurs = membre($pdo, 'ailleurs-42b');
coutDownvote($compteur, $pdo, message($pdo, $ailleurs, 'fil-42b'), '42b');
$trancheSuivante = $annules42();
$avantLien === 0 && $memeTranche === 0 && $trancheSuivante === 1
    ? ok('⭐ une meute liée après coup est rattrapée par le premier vote de la tranche suivante, et par lui')
    : nok('⭐ une meute liée après coup n\'est pas rattrapée comme annoncé : '
        . json_encode(['avant_le_lien' => $avantLien, 'meme_tranche' => $memeTranche, 'tranche_suivante' => $trancheSuivante]));

// ── 43. Une course perdue coûte un balayage, jamais un vote ; une table absente fait du bruit ──
// Deux premiers votes simultanés insèrent la même tranche : la base refuse le
// second, et c'est le seul refus que le moteur doit rattraper — celui-là vote
// quand même, sans balayer : il ne paie que son insertion refusée, une requête
// de plus qu'un vote ciblé. La course est rejouée ici par un déclencheur
// temporaire qui insère la tranche juste avant le vote : sa clé est alors prise.
// Toute autre erreur doit remonter : une table absente qui rendrait « pas de
// balayage » éteindrait le filet sans que personne le sache.
$pdo->exec('DELETE FROM mod_sweeps');
$compteur->exec('CREATE TEMP TRIGGER course_perdue BEFORE INSERT ON mod_sweeps
                 BEGIN INSERT INTO mod_sweeps (slot) VALUES (NEW.slot); END');
$cible43a = membre($pdo, 'cible-43a');
try {
    $perdant = coutDownvote($compteur, $pdo, message($pdo, $cible43a, 'fil-43a'), '43a');
} catch (PDOException $e) {
    $perdant = 'exception : ' . $e->getMessage();
}
$compteur->exec('DROP TRIGGER course_perdue');
trancheBalayee($pdo);
$pdo->exec('ALTER TABLE mod_sweeps RENAME TO mod_sweeps_cachee');
$cible43b = membre($pdo, 'cible-43b');
try {
    coutDownvote($compteur, $pdo, message($pdo, $cible43b, 'fil-43b'), '43b');
    $bruit = false;
} catch (PDOException) {
    $bruit = true;
}
$pdo->exec('ALTER TABLE mod_sweeps_cachee RENAME TO mod_sweeps');
$perdant === $avant + 1 && $bruit
    ? ok('une course perdue vote sans balayer, et une table absente fait échouer le vote au lieu d\'éteindre le filet')
    : nok('la course ou la table absente est mal tenue : ' . json_encode(['perdant' => $perdant, 'cible_seule' => $avant, 'table_absente_bruyante' => $bruit]));

// ── 44. Chaque phrase que le moteur affiche a sa traduction ─────────────────
// Le lab traduit les phrases du module par leur texte source (`tc()`), avec un
// repli sur le français quand elles manquent : la page reste lisible, et aucun
// contrôle ne voyait donc qu'une réponse sortait en français sur la page
// anglaise. Une phrase qui contourne `static::t()` ne passe même pas par là.
// ⚠️ L'extraction ne lit que les guillemets simples : une phrase entre
// guillemets doubles lui échapperait, et le contrôle refuse donc d'en voir une.
$sourceMoteur = (string) file_get_contents(__DIR__ . '/../../../bi-self/selfmoderate/src/Moderate.php');
preg_match_all("/static::t\\('((?:[^'\\\\]|\\\\.)*)'\\)/", $sourceMoteur, $phrases);
$contenuEn = require __DIR__ . '/../lang/contenu-en.php';
$sansAnglais = array_values(array_filter(array_unique(array_map('stripslashes', $phrases[1])),
    static fn (string $p): bool => !array_key_exists($p, $contenuEn)));
preg_match_all("/'message'\\s*=>\\s*'([^']*)'/", $sourceMoteur, $horsT);
$guillemetsDoubles = substr_count($sourceMoteur, 'static::t("');
count($phrases[1]) > 0 && $sansAnglais === [] && $horsT[1] === [] && $guillemetsDoubles === 0
    ? ok(count(array_unique($phrases[1])) . ' phrases du moteur passent par la traduction, et chacune a son anglais')
    : nok('une phrase du moteur sort en français sur la page anglaise : ' . json_encode([
        'sans_anglais' => $sansAnglais, 'hors_traduction' => $horsT[1], 'guillemets_doubles' => $guillemetsDoubles,
    ], JSON_UNESCAPED_UNICODE));

// ── 45. Un vote s'inscrit tout ou rien ──────────────────────────────────────
// Le journal refuse d'écrire le début du ban que ce downvote déclenche. Le
// refus remonte, comme le contrat du journal l'exige ; et rien de ce que le
// vote avait déjà écrit — le vote lui-même, le point retiré — ne doit rester :
// une annulation ultérieure rendrait sinon un point jamais pris.
$journalAvant45 = Moderate::journal();
Moderate::setJournal(new class implements \Pierroons\SelfModerate\Journal {
    public function inscrire(array $acte): void
    {
        if ($acte['acte'] === 'ban_debut') {
            throw new RuntimeException('journal fermé');
        }
    }
});
$fragile45 = membre($pdo, 'fragile-45');
$post45    = message($pdo, $fragile45, 'fil-45');
poserReputation($pdo, $fragile45, 1);
$votant45  = membre($pdo, 'votant-45');
$remonte45 = false;
try {
    Moderate::applyVote($pdo, $votant45, 'post', $post45, -1, motif(), 'agressif');
} catch (RuntimeException $e) {
    $remonte45 = $e->getMessage() === 'journal fermé';
}
Moderate::setJournal($journalAvant45);
$votes45 = (int) $pdo->query("SELECT COUNT(*) FROM mod_votes WHERE voter_id = $votant45")->fetchColumn();
$rep45   = (int) $pdo->query("SELECT reputation FROM member_moderation WHERE account_id = $fragile45")->fetchColumn();
$remonte45 && $votes45 === 0 && $rep45 === 1 && !$pdo->inTransaction()
    ? ok('un journal qui refuse le début du ban fait remonter le refus, et le vote n\'a rien laissé : ni vote, ni point retiré')
    : nok('le vote a laissé une trace malgré le refus du journal : ' . json_encode([
        'remonte' => $remonte45, 'votes' => $votes45, 'reputation' => $rep45, 'transaction_ouverte' => $pdo->inTransaction(),
    ]));

// ── 46. Un vote ne décide pas pour la transaction de l'hôte ─────────────────
// L'hôte ouvre la sienne, vote, puis l'annule : rien ne doit rester, et la
// transaction doit encore être à lui après le vote. Un moteur qui validerait ce
// qu'il n'a pas ouvert rendrait durable un travail de l'hôte à moitié fait.
$hote46  = membre($pdo, 'hote-46');
$cible46 = membre($pdo, 'cible-46');
$post46  = message($pdo, $cible46, 'fil-46');
$pdo->beginTransaction();
$r46 = Moderate::applyVote($pdo, $hote46, 'post', $post46, -1, motif(), 'agressif');
$encoreOuverte46 = $pdo->inTransaction();
if ($encoreOuverte46) {
    $pdo->rollBack();
}
$votes46 = (int) $pdo->query("SELECT COUNT(*) FROM mod_votes WHERE voter_id = $hote46")->fetchColumn();
($r46['ok'] ?? false) && $encoreOuverte46 && $votes46 === 0 && reputation($pdo, $cible46) === 20
    ? ok('dans la transaction de l\'hôte, le vote laisse l\'hôte décider : annulée par lui, elle n\'a rien écrit')
    : nok('le vote a validé ou perdu la transaction de l\'hôte : ' . json_encode([
        'ok' => $r46['ok'] ?? null, 'encore_ouverte' => $encoreOuverte46, 'votes' => $votes46, 'reputation' => reputation($pdo, $cible46),
    ]));

// ── 47. Lire un compte calme coûte une requête ──────────────────────────────
// Ni convalescence ni peine à refermer : la lecture n'a rien à écrire, et ne
// doit rien tenter. Une insertion « si absent » à chaque lecture prendrait un
// verrou d'écriture par message affiché sur une page de fil.
$calme47 = membre($pdo, 'calme-47');
Moderate::ensureRow($pdo, $calme47);
$compteur->requetes = 0;
$lu47 = Moderate::getReputation($compteur, $calme47);
$cout47 = $compteur->requetes;
$cout47 === 1 && $lu47['reputation'] === 20 && !$lu47['convalescent'] && !$lu47['banned']
    ? ok('lire la réputation d\'un compte calme coûte une requête')
    : nok("lire un compte calme coûte $cout47 requêtes au lieu d'une : " . json_encode($lu47));

// ── 48. Dans la transaction de l'hôte, un vote qui échoue ne laisse rien ────
// L'hôte ouvre la sienne, écrit son propre travail, vote ; le vote échoue (un
// déclencheur temporaire refuse la réputation de la cible, après l'inscription
// du vote), l'hôte attrape et valide son travail. Son travail reste, le vote
// partiel non : ni vote, ni point retiré. Sans point de reprise, le vote resterait
// inscrit sans son effet.
$hote48  = membre($pdo, 'hote-48');
$cible48 = membre($pdo, 'cible-48');
$post48  = message($pdo, $cible48, 'fil-48');
poserReputation($pdo, $cible48, 1);
$pdo->exec("CREATE TEMP TRIGGER reputation_refusee_48 BEFORE UPDATE OF reputation ON main.member_moderation
            WHEN NEW.account_id = $cible48 BEGIN SELECT RAISE(ABORT, 'réputation refusée'); END");
$pdo->beginTransaction();
$travail48 = message($pdo, $hote48, 'travail-48');
$remonte48 = false;
try {
    Moderate::applyVote($pdo, $hote48, 'post', $post48, -1, motif(), 'agressif');
} catch (PDOException $e) {
    $remonte48 = str_contains($e->getMessage(), 'réputation refusée');
}
$encore48 = $pdo->inTransaction();
if ($encore48) {
    $pdo->commit();
}
$pdo->exec('DROP TRIGGER IF EXISTS temp.reputation_refusee_48');
$votes48   = (int) $pdo->query("SELECT COUNT(*) FROM mod_votes WHERE voter_id = $hote48")->fetchColumn();
$rep48     = (int) $pdo->query("SELECT reputation FROM member_moderation WHERE account_id = $cible48")->fetchColumn();
$travail48 = (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE id = $travail48")->fetchColumn();
$remonte48 && $encore48 && $travail48 === 1 && $votes48 === 0 && $rep48 === 1
    ? ok('dans la transaction de l\'hôte, un vote qui échoue se défait seul : le travail de l\'hôte reste, le vote partiel non')
    : nok('le vote partiel a survécu dans la transaction de l\'hôte : ' . json_encode([
        'remonte' => $remonte48, 'encore_ouverte' => $encore48, 'travail' => $travail48, 'votes' => $votes48, 'reputation' => $rep48,
    ]));

// ── 49. Une lecture passe sous le verrou d'un autre, sans rien écrire ───────
// Une lecture n'écrit rien : pendant qu'une autre connexion tient le verrou
// d'écriture, elle rend la peine échue comme finie, et n'inscrit rien. Une
// écriture, elle, se fait sous le verrou, pris d'emblée, et l'état se relit sous
// lui : un écrivain qui attend puis échoue n'a rien inscrit. Le verrou rendu, la
// fin s'inscrit une fois. Deux connexions : A tient le verrou, B lit puis écrit.
$journalAvant49 = Moderate::journal();
$journal49 = new class implements \Pierroons\SelfModerate\Journal {
    public array $actes = [];
    public function inscrire(array $acte): void
    {
        $this->actes[] = $acte['acte'];
    }
};
Moderate::setJournal($journal49);
$echu49 = membre($pdo, 'echu-49');
Moderate::ensureRow($pdo, $echu49);
$pdo->prepare("UPDATE member_moderation
                  SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                      ban_origine = 'auto', ban_motif = 'reputation_zero'
                WHERE account_id = ?")->execute([time() - 10, time() - 130, $echu49]);
planterSignal($pdo, $echu49, 'ban_auto');
$a49 = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
$a49->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$b49 = new PDO('sqlite:' . getenv('LAB_DB_PATH'), null, null, [PDO::ATTR_TIMEOUT => 1]);
$b49->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$b49->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$a49->exec('BEGIN IMMEDIATE');
$a49->exec('UPDATE member_moderation SET updated_at = updated_at WHERE account_id = ' . $echu49);
$lu49 = null;
try {
    $lu49 = Moderate::getReputation($b49, $echu49);
} catch (PDOException $e) {
    $lu49 = $e->getMessage();
}
$refuse49 = false;
try {
    rattraper($b49, $echu49);
} catch (PDOException $e) {
    $refuse49 = true;
}
$pendantLeVerrou49 = count($journal49->actes);
$a49->exec('ROLLBACK');
rattraper($b49, $echu49);
$apres49 = Moderate::getReputation($b49, $echu49);
Moderate::setJournal($journalAvant49);
is_array($lu49) && !$lu49['banned'] && $lu49['reputation'] === 20 && $lu49['fin_en_attente']
    && $refuse49 && $pendantLeVerrou49 === 0 && $journal49->actes === ['ban_fin'] && !$apres49['banned'] && $apres49['reputation'] === 20
    && !$apres49['fin_en_attente']
    ? ok('une lecture passe sous le verrou d\'un autre sans rien écrire ; l\'écrivain qui ne peut pas le prendre n\'inscrit rien ; le verrou rendu, la peine se referme une fois')
    : nok('une fin de peine s\'inscrit avant que le verrou soit pris : ' . json_encode([
        'lu' => $lu49, 'refuse' => $refuse49, 'pendant_le_verrou' => $pendantLeVerrou49, 'actes' => $journal49->actes, 'apres' => $apres49,
    ]));

// ── 50. Sans journal branché, aucun geste d'arbitre ─────────────────────────
// Le moteur refuse déjà de bannir seul sans trace ; un arbitre non plus. Le
// geste qui passerait sans journal est celui qui efface : une grâce remet la
// réputation au départ et les strikes à zéro.
$journal50 = Moderate::journal();
Moderate::setJournal(new JournalDeBanc());      // la mise en place passe par des gestes, qui l'exigent
$aBannir50 = membre($pdo, 'a-bannir-50');
poserReputation($pdo, $aBannir50, 15);
[$gracie50, $maintenu50] = [membre($pdo, 'gracie-50'), membre($pdo, 'maintenu-50')];
foreach ([$gracie50, $maintenu50] as $id50) {
    poserReputation($pdo, $id50, 15);
    Moderate::adminBan($pdo, $id50, 'arbitre-banc', motif());
}
$enRevue50 = membre($pdo, 'en-revue-50');
poserReputation($pdo, $enRevue50, 15);
planterSignal($pdo, $enRevue50, 'salve_rapide');
$gestes50 = [
    'adminBan'       => [$aBannir50, static fn (): array => Moderate::adminBan($pdo, $aBannir50, 'arbitre-banc', motif())],
    'adminPardon'    => [$gracie50, static fn (): array => Moderate::adminPardon($pdo, $gracie50, 'arbitre-banc', motif())],
    'adminMaintenir' => [$maintenu50, static fn (): array => Moderate::adminMaintenir($pdo, $maintenu50, 'arbitre-banc', motif())],
    'adminClasser'   => [$enRevue50, static fn (): array => Moderate::adminClasser($pdo, $enRevue50, 'arbitre-banc', motif())],
];
Moderate::setJournal(null);
try {
    foreach ($gestes50 as $geste => [$compte50, $faire50]) {
        $avant50 = etatBan($pdo, $compte50);
        $r50 = $faire50();
        $intact50 = etatBan($pdo, $compte50) === $avant50;
        !$r50['ok'] && str_contains($r50['message'], 'journal') && $intact50
            ? ok("sans journal, $geste refuse et ne touche à rien")
            : nok("$geste passe sans journal : " . json_encode([$r50, 'base_intacte' => $intact50], JSON_UNESCAPED_UNICODE));
    }
} finally {
    Moderate::setJournal($journal50);
}

// ── 51. Un geste d'arbitre qui ne peut pas prendre le verrou n'écrit rien ───
// Le pendant du n° 49 pour les quatre gestes : la décision se prend et
// s'inscrit sous le verrou d'écriture, pris d'emblée. Sans cela, un geste
// inscrit son acte puis échoue sur la base — un journal qui annonce un ban, ou
// une grâce, que la base n'a jamais vu. Deux connexions : A tient le verrou,
// B agit ; B n'attend le verrou que 100 ms.
$journalAvant51 = Moderate::journal();
$journal51 = new class implements \Pierroons\SelfModerate\Journal {
    public array $actes = [];
    public function inscrire(array $acte): void
    {
        $this->actes[] = $acte['acte'];
    }
};
Moderate::setJournal($journal51);
$cible51 = [];
foreach (['ban', 'pardon', 'maintenir', 'classer'] as $geste) {
    $cible51[$geste] = membre($pdo, "verrou-$geste-51");
    poserReputation($pdo, $cible51[$geste], 15);
}
Moderate::adminBan($pdo, $cible51['pardon'], 'arbitre-banc', motif());
// Seul un ban automatique attend un maintien.
$pdo->prepare("UPDATE member_moderation
                  SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                      ban_origine = 'auto', ban_motif = 'reputation_zero'
                WHERE account_id = ?")->execute([time() + 3600, time(), $cible51['maintenir']]);
planterSignal($pdo, $cible51['maintenir'], 'ban_auto');
planterSignal($pdo, $cible51['classer'], 'salve_rapide');
$a51 = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
$a51->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$b51 = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
$b51->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$b51->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$b51->exec('PRAGMA busy_timeout = 100');
$gestes51 = [
    'ban'       => static fn (): array => Moderate::adminBan($b51, $cible51['ban'], 'arbitre-banc', motif()),
    'pardon'    => static fn (): array => Moderate::adminPardon($b51, $cible51['pardon'], 'arbitre-banc', motif()),
    'maintenir' => static fn (): array => Moderate::adminMaintenir($b51, $cible51['maintenir'], 'arbitre-banc', motif()),
    'classer'   => static fn (): array => Moderate::adminClasser($b51, $cible51['classer'], 'arbitre-banc', motif()),
];
$fautes51 = [];
foreach ($gestes51 as $geste => $faire51) {
    $a51->exec('BEGIN IMMEDIATE');
    $a51->exec('UPDATE member_moderation SET updated_at = updated_at WHERE account_id = ' . $cible51[$geste]);
    $avant51 = count($journal51->actes);
    try {
        $faire51();
    } catch (PDOException) {
    }
    $pendant51 = count($journal51->actes) - $avant51;
    $a51->exec('ROLLBACK');
    $apres51 = $faire51();
    if ($pendant51 !== 0 || !($apres51['ok'] ?? false)) {
        $fautes51[$geste] = ['actes_pendant_le_verrou' => $pendant51, 'apres' => $apres51];
    }
}
Moderate::setJournal($journalAvant51);
$fautes51 === []
    ? ok('aucun geste d\'arbitre n\'inscrit d\'acte sans tenir le verrou ; le verrou rendu, chacun passe')
    : nok('un geste d\'arbitre inscrit son acte avant de tenir le verrou : ' . json_encode($fautes51, JSON_UNESCAPED_UNICODE));

// ── 52. Un ban d'arbitre laisse la revue ouverte, et dit laquelle ───────────
// Un signal de comportement ne se lève que classé par un arbitre : un ban posé
// pour un autre motif ne l'a pas lu. L'acte du ban dit quelle revue était
// ouverte quand il est tombé.
$journalAvant52 = Moderate::journal();
$journal52 = new JournalDeBanc();
Moderate::setJournal($journal52);
$recidiviste52 = membre($pdo, 'recidiviste-52');
poserReputation($pdo, $recidiviste52, 15);
planterSignal($pdo, $recidiviste52, 'meute_recidive');
$r52 = Moderate::adminBan($pdo, $recidiviste52, 'arbitre-banc', motif());
$e52 = Moderate::getReputation($pdo, $recidiviste52);
$acte52 = $journal52->de($recidiviste52, 'ban_debut');
Moderate::setJournal($journalAvant52);
$r52['ok'] && $e52['banned'] && $e52['needs_review'] && $e52['review_reason'] === 'meute_recidive'
    && count($acte52) === 1 && array_key_exists('raison', $acte52[0]) && $acte52[0]['raison'] === 'meute_recidive'
    && liste($acte52[0]['ouverts'] ?? null) === ['meute_recidive'] && liste($acte52[0]['ferme'] ?? null) === []
    ? ok('un ban d\'arbitre laisse la revue de meute ouverte, et son acte dit laquelle l\'était')
    : nok('un ban d\'arbitre efface la revue ou la tait : '
        . json_encode([$r52, $e52['review_reason'], $acte52[0]['raison'] ?? 'absente'], JSON_UNESCAPED_UNICODE));

// ── 53. Le balayage qui ne peut pas prendre le verrou n'écrit rien au journal ─
// Le pendant du n° 49 pour le planificateur. Sans le verrou, chaque balayage
// inscrit la fin de la même peine puis échoue sur la base : autant de fins que
// de balayages, et la page qui balaie tombe. La peine est la plus ancienne de
// la base : le balayage la prend en premier.
$journalAvant53 = Moderate::journal();
$journal53 = new JournalDeBanc();
Moderate::setJournal($journal53);
$echu53 = membre($pdo, 'echu-53');
Moderate::ensureRow($pdo, $echu53);
$pdo->prepare("UPDATE member_moderation
                  SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                      ban_origine = 'auto', ban_motif = 'reputation_zero'
                WHERE account_id = ?")->execute([time() - 10_000_000, time() - 10_000_120, $echu53]);
$a53 = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
$a53->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$b53 = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
$b53->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$b53->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$b53->exec('PRAGMA busy_timeout = 100');
$a53->exec('BEGIN IMMEDIATE');
$a53->exec('UPDATE member_moderation SET updated_at = updated_at WHERE account_id = ' . $echu53);
$refuse53 = false;
try {
    Moderate::balayerBansEchus($b53);
} catch (PDOException) {
    $refuse53 = true;
}
$pendant53 = count($journal53->de($echu53, 'ban_fin'));
$a53->exec('ROLLBACK');
Moderate::balayerBansEchus($b53);
$apres53 = count($journal53->de($echu53, 'ban_fin'));
Moderate::setJournal($journalAvant53);
$refuse53 && $pendant53 === 0 && $apres53 === 1 && !Moderate::getReputation($pdo, $echu53)['banned']
    ? ok('un balayage qui ne peut pas prendre le verrou n\'écrit rien au journal ; le verrou rendu, la peine se referme une fois')
    : nok('le balayage inscrit une fin de peine avant de tenir le verrou : '
        . json_encode(['refuse' => $refuse53, 'pendant_le_verrou' => $pendant53, 'apres' => $apres53]));

// ── 54. Une meute se neutralise tout ou rien ────────────────────────────────
// L'hôte appelle la détection hors de tout vote (`api/detect_abuse.php`, le
// simulateur). Une écriture qui échoue entre l'annulation des votes et leurs
// rangs ne doit pas laisser des votes annulés et une réputation rendue sans
// épisode consigné : plus rien ne les rattraperait. Un déclencheur temporaire
// refuse l'écriture des rangs le temps du premier passage.
$cible54 = membre($pdo, 'cible-54');
$post54 = message($pdo, $cible54, 'fil-54');
[$lieA54, $lieB54] = [membre($pdo, 'meute-a-54'), membre($pdo, 'meute-b-54')];
echangePrive($pdo, $lieA54, $lieB54);
voteBrut($pdo, $lieA54, $post54, $cible54);
voteBrut($pdo, $lieB54, $post54, $cible54);
$repAvant54 = reputation($pdo, $cible54);
$pdo->exec("CREATE TEMP TRIGGER rangs_refuses_54 BEFORE INSERT ON main.mod_pack_flags
            BEGIN SELECT RAISE(ABORT, 'rangs refusés'); END");
$echec54 = false;
try {
    Moderate::detectPackVoting($pdo, $cible54);
} catch (PDOException) {
    $echec54 = true;
} finally {
    $pdo->exec('DROP TRIGGER IF EXISTS temp.rangs_refuses_54');
}
$pendant54 = [bloquesMeute($pdo, $cible54), reputation($pdo, $cible54) - $repAvant54];
Moderate::detectPackVoting($pdo, $cible54);
$rangs54 = (int) $pdo->query('SELECT COUNT(*) FROM mod_pack_flags WHERE target_author = ' . $cible54)->fetchColumn();
$echec54 && $pendant54 === [0, 0] && bloquesMeute($pdo, $cible54) === 1 && $rangs54 === 2
    ? ok('une meute dont les rangs ne s\'écrivent pas n\'est pas annulée à moitié ; au passage suivant, tout se consigne')
    : nok('une meute est neutralisée à moitié : ' . json_encode([
        'echec' => $echec54, 'annules_et_rendus_apres_echec' => $pendant54,
        'annules' => bloquesMeute($pdo, $cible54), 'rangs' => $rangs54,
    ]));

// ── 55. Dans la transaction différée d'un hôte, rien ne s'inscrit sans le verrou ─
// Un point de reprise ne prend aucun verrou, et l'hôte ouvre souvent en
// différé, puis lit. Une autre connexion qui écrit alors refuse aussitôt le
// verrou au geste comme au balayage : ni l'un ni l'autre ne doit avoir inscrit
// son acte avant de le demander.
$journalAvant55 = Moderate::journal();
$journal55 = new JournalDeBanc();
Moderate::setJournal($journal55);
$banni55 = membre($pdo, 'banni-hote-55');
poserReputation($pdo, $banni55, 15);
Moderate::adminBan($pdo, $banni55, 'arbitre-banc', motif());
$echu55 = membre($pdo, 'echu-hote-55');
Moderate::ensureRow($pdo, $echu55);
$pdo->prepare("UPDATE member_moderation
                  SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                      ban_origine = 'auto', ban_motif = 'reputation_zero'
                WHERE account_id = ?")->execute([time() - 10, time() - 130, $echu55]);
$journal55->actes = [];
$autre55 = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
$autre55->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$hote55 = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
$hote55->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$hote55->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$hote55->exec('PRAGMA busy_timeout = 100');
$issues55 = [];
foreach (['grace' => static fn (): mixed => Moderate::adminPardon($hote55, $banni55, 'arbitre-banc', motif()),
          'balayage' => static fn (): mixed => Moderate::balayerBansEchus($hote55)] as $geste => $faire55) {
    $hote55->beginTransaction();
    $hote55->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
    $autre55->exec('BEGIN IMMEDIATE');
    $autre55->exec('UPDATE member_moderation SET updated_at = updated_at WHERE account_id = ' . $banni55);
    try {
        $r55 = $faire55();
        $issues55[$geste] = is_array($r55) && !($r55['ok'] ?? true) ? 'refusé' : 'passé';
    } catch (PDOException) {
        $issues55[$geste] = 'refusé';
    }
    $autre55->exec('ROLLBACK');
    if ($hote55->inTransaction()) {
        $hote55->rollBack();
    }
}
$actes55 = count($journal55->actes);
Moderate::setJournal($journalAvant55);
$issues55 === ['grace' => 'refusé', 'balayage' => 'refusé'] && $actes55 === 0
    ? ok('dans la transaction différée d\'un hôte, un geste et le balayage refusés faute de verrou n\'ont rien inscrit')
    : nok('un acte s\'inscrit dans la transaction d\'un hôte sans le verrou : ' . json_encode(['issues' => $issues55, 'actes' => $actes55]));

// ── 56. Une peine qui attend sa fin au journal arrête les gestes d'arbitre ──
// Le journal a refusé la fin d'une peine échue : elle attend sa clôture
// (`journal_en_panne`). Un journal peut refuser un acte et accepter le suivant ;
// un ban posé par-dessus la recouvrirait, une grâce la refermerait sans que sa
// fin soit écrite nulle part, un classement effacerait le seul signal qui en
// reste.
$journalAvant56 = Moderate::journal();
$journal56 = new class implements \Pierroons\SelfModerate\Journal {
    public array $actes = [];
    public function inscrire(array $acte): void
    {
        if ($acte['acte'] === 'ban_fin') {
            throw new RuntimeException('fin refusée');
        }
        $this->actes[] = $acte['acte'];
    }
};
Moderate::setJournal($journal56);
$fautes56 = [];
foreach (['ban', 'grace', 'classement'] as $geste) {
    $attente56 = membre($pdo, "attente-$geste-56");
    Moderate::ensureRow($pdo, $attente56);
    $pdo->prepare("UPDATE member_moderation
                      SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                          ban_origine = 'auto', ban_motif = 'reputation_zero'
                    WHERE account_id = ?")->execute([time() - 10, time() - 130, $attente56]);
    // Le geste rattrape le compte avant de décider, et contient le refus du
    // journal : s'il ne le contenait plus, le cas rougirait sur son signal absent,
    // au lieu d'arrêter le banc.
    try {
        $journal56->actes = [];
        $r56 = match ($geste) {
            'ban'        => Moderate::adminBan($pdo, $attente56, 'arbitre-banc', motif()),
            'grace'      => Moderate::adminPardon($pdo, $attente56, 'arbitre-banc', motif()),
            'classement' => Moderate::adminClasser($pdo, $attente56, 'arbitre-banc', motif()),
        };
    } catch (Throwable $t56) {
        $r56 = ['ok' => false, 'exception' => $t56->getMessage()];
    }
    $e56 = etatBan($pdo, $attente56);
    if (($r56['ok'] ?? true) || $journal56->actes !== [] || $e56['review_reason'] !== 'journal_en_panne' || (int) $e56['banned_until'] === 0) {
        $fautes56[$geste] = [$r56, $journal56->actes, $e56['review_reason'], (int) $e56['banned_until']];
    }
}
Moderate::setJournal($journalAvant56);
$fautes56 === []
    ? ok('une peine qui attend sa fin au journal arrête le ban, la grâce et le classement, et son signal reste')
    : nok('un geste passe par-dessus une peine qui attend sa fin : ' . json_encode($fautes56, JSON_UNESCAPED_UNICODE));

// ── 57. Un geste décide sur l'état relu sous le verrou ───────────────────────
// Le n° 51 tient le verrou puis le rend : l'état n'a pas changé entre la
// lecture d'avant le verrou et celle d'après. Ici, une connexion à point d'arrêt
// laisse un autre arbitre agir juste avant que le geste prenne son verrou : un
// geste qui déciderait sur ce qu'il a lu avant banirait deux fois, ou lèverait
// deux fois le même ban. Le compte n'a rien à rattraper, et le premier verrou
// est celui du geste — le contrôle compte les verrous pour s'en assurer.
final class PdoAPointDArret extends PDO
{
    public ?Closure $avantLeVerrou = null;
    public int $verrous = 0;

    public function exec(string $statement): int|false
    {
        if ($statement === 'BEGIN IMMEDIATE') {
            $this->verrous++;
            if ($this->avantLeVerrou !== null) {
                $interlude = $this->avantLeVerrou;
                $this->avantLeVerrou = null;
                $interlude();
            }
        }

        return parent::exec($statement);
    }
}
$journalAvant57 = Moderate::journal();
$journal57 = new JournalDeBanc();
Moderate::setJournal($journal57);
$c57 = new PdoAPointDArret('sqlite:' . getenv('LAB_DB_PATH'));
$c57->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$c57->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$visee57 = membre($pdo, 'visee-57');
poserReputation($pdo, $visee57, 15);
$c57->avantLeVerrou = static function () use ($pdo, $visee57): void {
    Moderate::adminBan($pdo, $visee57, 'arbitre-un', motif());
};
$c57->verrous = 0;
$ban57 = Moderate::adminBan($c57, $visee57, 'arbitre-deux', motif());
$verrous57 = [$c57->verrous];
$graciee57 = membre($pdo, 'graciee-57');
poserReputation($pdo, $graciee57, 15);
Moderate::adminBan($pdo, $graciee57, 'arbitre-un', motif());
$c57->avantLeVerrou = static function () use ($pdo, $graciee57): void {
    Moderate::adminPardon($pdo, $graciee57, 'arbitre-un', motif());
};
$c57->verrous = 0;
Moderate::adminPardon($c57, $graciee57, 'arbitre-deux', motif());
$verrous57[] = $c57->verrous;
$debuts57 = count($journal57->de($visee57, 'ban_debut'));
$leves57 = count($journal57->de($graciee57, 'ban_leve'));
Moderate::setJournal($journalAvant57);
if ($verrous57 !== [1, 1]) {
    nok('le contrôle 57 ne mesure plus rien : verrous pris par geste ' . json_encode($verrous57) . ', l\'autre arbitre n\'agit plus juste avant celui du geste');
} else {
    !$ban57['ok'] && $debuts57 === 1 && $leves57 === 1
        ? ok('un geste devancé par un autre arbitre décide sur l\'état relu sous le verrou : un seul ban, une seule levée')
        : nok('un geste décide sur l\'état lu avant le verrou : ' . json_encode(['ban' => $ban57, 'ban_debut' => $debuts57, 'ban_leve' => $leves57], JSON_UNESCAPED_UNICODE));
}

// ── 58. Une meute qui a causé un ban n'en écrit pas la levée si ses rangs échouent ─
// Le pendant du n° 54, sur une cible que la meute a fait bannir : sa
// restitution inscrit au journal la levée du ban. Les rangs passent avant :
// s'ils échouent, tout se défait avant que la levée soit inscrite. Dans l'autre
// ordre, le journal dirait le ban levé sur une base qui le garde.
$journalAvant58 = Moderate::journal();
$journal58 = new JournalDeBanc();
Moderate::setJournal($journal58);
$cible58 = membre($pdo, 'cible-58');
$post58 = message($pdo, $cible58, 'fil-58');
[$lieA58, $lieB58] = [membre($pdo, 'meute-a-58'), membre($pdo, 'meute-b-58')];
echangePrive($pdo, $lieA58, $lieB58);
voteBrut($pdo, $lieA58, $post58, $cible58);
voteBrut($pdo, $lieB58, $post58, $cible58);
$pdo->exec('UPDATE mod_votes SET created_at = ' . (time() - 100) . ' WHERE target_author = ' . $cible58);
Moderate::ensureRow($pdo, $cible58);
$pdo->prepare("UPDATE member_moderation
                  SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                      ban_origine = 'auto', ban_motif = 'reputation_zero'
                WHERE account_id = ?")->execute([time() + 3600, time() - 50, $cible58]);
$pdo->exec("CREATE TEMP TRIGGER rangs_refuses_58 BEFORE INSERT ON main.mod_pack_flags
            BEGIN SELECT RAISE(ABORT, 'rangs refusés'); END");
try {
    Moderate::detectPackVoting($pdo, $cible58);
} catch (PDOException) {
} finally {
    $pdo->exec('DROP TRIGGER IF EXISTS temp.rangs_refuses_58');
}
$levees58 = count($journal58->de($cible58, 'ban_leve'));
$encoreBanni58 = Moderate::getReputation($pdo, $cible58)['banned'];
Moderate::detectPackVoting($pdo, $cible58);
$apres58 = [count($journal58->de($cible58, 'ban_leve')), Moderate::getReputation($pdo, $cible58)['banned']];
Moderate::setJournal($journalAvant58);
$levees58 === 0 && $encoreBanni58 && $apres58 === [1, false]
    ? ok('une meute dont les rangs échouent n\'inscrit pas la levée du ban qu\'elle a causé ; au passage suivant, la levée s\'inscrit et tombe')
    : nok('une levée de ban s\'inscrit pour une meute défaite : ' . json_encode([
        'levees_apres_echec' => $levees58, 'encore_banni' => $encoreBanni58, 'apres' => $apres58,
    ]));

// ── 59. Aucun geste d'arbitre dans la transaction d'un hôte ─────────────────
// Le journal ne se défait pas avec la transaction de l'hôte : un geste qui y
// rendrait `ok` laisserait son acte au journal si l'hôte annulait ensuite. Les
// quatre gestes refusent, et rien n'est inscrit.
$journalAvant59 = Moderate::journal();
$journal59 = new JournalDeBanc();
Moderate::setJournal($journal59);
$cibles59 = [];
foreach (['ban', 'grace', 'maintien', 'classement'] as $geste) {
    $cibles59[$geste] = membre($pdo, "hote-$geste-59");
    poserReputation($pdo, $cibles59[$geste], 15);
}
Moderate::adminBan($pdo, $cibles59['grace'], 'arbitre-banc', motif());
Moderate::adminBan($pdo, $cibles59['maintien'], 'arbitre-banc', motif());
planterSignal($pdo, $cibles59['classement'], 'salve_rapide');
$journal59->actes = [];
$fautes59 = [];
foreach (['ban'        => static fn (): array => Moderate::adminBan($pdo, $cibles59['ban'], 'arbitre-banc', motif()),
          'grace'      => static fn (): array => Moderate::adminPardon($pdo, $cibles59['grace'], 'arbitre-banc', motif()),
          'maintien'   => static fn (): array => Moderate::adminMaintenir($pdo, $cibles59['maintien'], 'arbitre-banc', motif()),
          'classement' => static fn (): array => Moderate::adminClasser($pdo, $cibles59['classement'], 'arbitre-banc', motif())]
         as $geste => $faire59) {
    $pdo->beginTransaction();
    $r59 = $faire59();
    $pdo->rollBack();
    if ($r59['ok'] || !str_contains($r59['message'], 'transaction de l\'hôte')) {
        $fautes59[$geste] = $r59;
    }
}
$actes59 = count($journal59->actes);
Moderate::setJournal($journalAvant59);
$fautes59 === [] && $actes59 === 0
    ? ok('dans la transaction d\'un hôte, aucun des quatre gestes ne passe, et rien n\'est inscrit')
    : nok('un geste d\'arbitre passe dans la transaction d\'un hôte : ' . json_encode(['gestes' => $fautes59, 'actes' => $actes59], JSON_UNESCAPED_UNICODE));

// ── 60. Dans la transaction d'un hôte, un vote n'arme pas le ban automatique ─
// Le vote y a sa place, le ban automatique non : l'hôte peut encore annuler, et
// l'acte survivrait à la base. La réputation à zéro y est signalée, comme sans
// journal ; le même vote, hors de la transaction de l'hôte, bannit.
$journalAvant60 = Moderate::journal();
$journal60 = new JournalDeBanc();
Moderate::setJournal($journal60);
$dansLHote60 = membre($pdo, 'zero-hote-60');
poserReputation($pdo, $dansLHote60, 1);
$pdo->beginTransaction();
$vote60 = Moderate::applyVote($pdo, membre($pdo, 'votant-hote-60'), 'member', $dansLHote60, -1, motif(), 'agressif');
$pendant60 = Moderate::getReputation($pdo, $dansLHote60);
$pdo->rollBack();
$actesHote60 = count($journal60->actes);
$horsHote60 = membre($pdo, 'zero-libre-60');
poserReputation($pdo, $horsHote60, 1);
Moderate::applyVote($pdo, membre($pdo, 'votant-libre-60'), 'member', $horsHote60, -1, motif(), 'agressif');
$libre60 = Moderate::getReputation($pdo, $horsHote60);
Moderate::setJournal($journalAvant60);
($vote60['ok'] ?? false) && !$pendant60['banned'] && $pendant60['review_reason'] === 'reputation_zero'
    && $actesHote60 === 0 && $libre60['banned'] && count($journal60->de($horsHote60, 'ban_debut')) === 1
    ? ok('dans la transaction d\'un hôte, un vote signale la réputation à zéro sans bannir ni rien inscrire ; hors d\'elle, il bannit')
    : nok('un vote arme le ban automatique dans la transaction d\'un hôte : ' . json_encode([
        'vote' => $vote60, 'pendant' => [$pendant60['banned'], $pendant60['review_reason']],
        'actes_dans_l_hote' => $actesHote60, 'hors_hote_banni' => $libre60['banned'],
    ], JSON_UNESCAPED_UNICODE));

// ══ Un signal par cause : rien ne s'y perd, l'arbitre ferme ce qu'il a vu ═══

/**
 * Une liste du journal (`ouverts`, `ferme`), qui arrive en texte JSON. Toute autre
 * forme rend null : une liste brute rangerait `Array` dans un journal clé par clé.
 */
function liste(mixed $valeur): ?array
{
    return is_string($valeur) ? json_decode($valeur, true) : null;
}

/**
 * Ce qui doit tenir sur toute base : un signal d'état n'existe pas sans son état,
 * un ban automatique en cours a son signal, une réputation au plancher hors peine
 * a le sien, et la case dit la table — le premier comportement à voir, sinon le
 * dernier état.
 *
 * @return array{orphelins: list<array>, bans_sans_signal: list<array>, chutes_sans_signal: list<array>, cases: list<array>}
 */
function invariantsSignaux(PDO $p): array
{
    return [
        'orphelins' => $p->query(
            "SELECT s.account_id, s.cause FROM mod_signaux s JOIN member_moderation m ON m.account_id = s.account_id
              WHERE (s.cause = 'ban_auto' AND NOT (m.ban_origine = 'auto' AND m.banned_until > 0))
                 OR (s.cause = 'journal_en_panne' AND m.banned_until = 0)
                 OR (s.cause = 'reputation_zero' AND m.convalescent = 0)"
        )->fetchAll(PDO::FETCH_ASSOC),
        'bans_sans_signal' => $p->query(
            "SELECT account_id FROM member_moderation m
              WHERE ban_origine = 'auto' AND banned_until > " . time() . "
                AND NOT EXISTS (SELECT 1 FROM mod_signaux s WHERE s.account_id = m.account_id AND s.cause = 'ban_auto')"
        )->fetchAll(PDO::FETCH_ASSOC),
        'chutes_sans_signal' => $p->query(
            "SELECT account_id FROM member_moderation m
              WHERE reputation <= " . Moderate::config()->banA . " AND banned_until = 0
                AND NOT EXISTS (SELECT 1 FROM mod_signaux s WHERE s.account_id = m.account_id AND s.cause = 'reputation_zero')"
        )->fetchAll(PDO::FETCH_ASSOC),
        'cases' => $p->query(
            "SELECT account_id, needs_review, review_reason FROM (
                SELECT m.account_id, m.needs_review, m.review_reason,
                       EXISTS (SELECT 1 FROM mod_signaux s WHERE s.account_id = m.account_id AND s.vu_le IS NULL) AS attendu_besoin,
                       COALESCE(
                           (SELECT cause FROM mod_signaux s WHERE s.account_id = m.account_id AND s.vu_le IS NULL
                               AND cause NOT IN ('reputation_zero', 'ban_auto', 'journal_en_panne') ORDER BY id LIMIT 1),
                           (SELECT cause FROM mod_signaux s WHERE s.account_id = m.account_id AND s.vu_le IS NULL ORDER BY id DESC LIMIT 1)
                       ) AS attendu_cause
                  FROM member_moderation m)
              WHERE needs_review <> attendu_besoin OR review_reason IS NOT attendu_cause"
        )->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/** Les causes ouvertes sur un compte, dans l'ordre où elles ont été posées. */
function causes(PDO $pdo, int $id): array
{
    return array_column(Moderate::getReputation($pdo, $id)['signaux'] ?? [], 'cause');
}

/** Le compte vise un membre de plus que `ciblesParVotantMax` : le dernier vote est neutralisé. */
function voterLarge(PDO $pdo, int $votant, string $nom): void
{
    for ($k = 0; $k <= Moderate::config()->ciblesParVotantMax; $k++) {
        Moderate::applyVote($pdo, $votant, 'member', membre($pdo, "$nom-$k"), -1, motif(), 'agressif');
    }
}

// Ces contrôles branchent leur journal, comme les n° 49 à 60 : un geste
// d'arbitre et le ban automatique l'exigent.
$journalAvantC = Moderate::journal();
$journalC = new JournalDeBanc();
Moderate::setJournal($journalC);

// ── 61. ⭐ Une salve ne sert pas de bouclier à sa cible ──────────────────────
// Trois complices salvent un compte avant qu'il vise tout le forum : dans une
// case unique, la salve tenait la place, et le signal que le compte se donnait
// lui-même se perdait. Les deux causes restent ouvertes.
$bouclier61 = membre($pdo, 'bouclier-61');
poserReputation($pdo, $bouclier61, 20);
foreach (['a', 'b', 'c'] as $n) {
    Moderate::applyVote($pdo, membre($pdo, "salve-bouclier-$n-61"), 'member', $bouclier61, -1, motif(), 'agressif');
}
voterLarge($pdo, $bouclier61, 'large-61');
causes($pdo, $bouclier61) === ['salve_rapide', 'votant_etendu']
    ? ok('⭐ la cible d\'une salve qui vise ensuite tout le forum garde les deux signaux')
    : nok('⭐ la salve sert de bouclier : ' . json_encode(causes($pdo, $bouclier61)));

// ── 62. ⭐ Un compte ne masque pas sa chute en se signalant lui-même ─────────
// Sans journal, une réputation à zéro est signalée et rien d'autre. Le compte,
// revenu au-dessus du seuil de vote, qui vise tout le forum ne fait pas
// disparaître ce signal sous le sien.
$journalAvant62 = Moderate::journal();
Moderate::setJournal(null);
try {
    $masque62 = membre($pdo, 'masque-62');
    poserReputation($pdo, $masque62, 1);
    Moderate::applyVote($pdo, membre($pdo, 'chute-62'), 'member', $masque62, -1, motif(), 'agressif');
    $pdo->prepare('UPDATE member_moderation SET reputation = 6, voting_rights = 1 WHERE account_id = ?')->execute([$masque62]);
    voterLarge($pdo, $masque62, 'large-62');
    $causes62 = causes($pdo, $masque62);
} finally {
    Moderate::setJournal($journalAvant62);
}
$causes62 === ['reputation_zero', 'votant_etendu']
    ? ok('⭐ un compte à zéro qui se fait signaler n\'efface pas le signal de sa chute')
    : nok('⭐ le compte masque sa réputation à zéro : ' . json_encode($causes62));

// ── 63. ⭐ Un flot de salves ne pousse pas un signal grave hors de la vue ─────
// Des trios de comptes ordinaires salvent cinquante et une cibles. La récidive
// de meute posée avant eux reste dans la liste des comptes à arbitrer et dans
// la page de sa cause ; la page des salves s'arrête à sa borne, la plus ancienne
// en tête, et son total dit ce qu'elle tait. Les salves tombent dans la même
// seconde : leurs dates s'étalent ensuite, sans quoi l'ordre ne se verrait pas.
$grave63 = membre($pdo, 'grave-63');
poserReputation($pdo, $grave63, 20);
planterSignal($pdo, $grave63, 'meute_recidive', time() - 20000);
$pdo->prepare('UPDATE member_moderation SET updated_at = ? WHERE account_id = ?')->execute([time() - 20000, $grave63]);
$visees63 = [];
for ($salvees63 = 0, $g = 0; $salvees63 < 51; $g++) {
    $trio63 = [membre($pdo, "flot-$g-a-63"), membre($pdo, "flot-$g-b-63"), membre($pdo, "flot-$g-c-63")];
    for ($k = 0; $k < Moderate::config()->ciblesParVotantMax && $salvees63 < 51; $k++, $salvees63++) {
        $visee63 = membre($pdo, "flot-$g-$k-visee-63");
        $visees63[] = $visee63;
        foreach ($trio63 as $v63) {
            Moderate::applyVote($pdo, $v63, 'member', $visee63, -1, motif(), 'agressif');
        }
    }
}
$etale63 = $pdo->prepare("UPDATE mod_signaux SET ouvert_le = ? WHERE account_id = ? AND cause = 'salve_rapide'");
foreach ($visees63 as $rang63 => $visee63) {
    $etale63->execute([time() - 10000 + $rang63, $visee63]);
}
$liste63  = array_column(Moderate::flaggedForReview($pdo, 50), 'account_id');
$page63   = method_exists(Moderate::class, 'signauxOuverts') ? Moderate::signauxOuverts($pdo, 50) : [];
$salves63 = array_values(array_filter($page63, static fn (array $s): bool => $s['cause'] === 'salve_rapide'));
$meute63  = array_values(array_filter($page63, static fn (array $s): bool => $s['account_id'] === $grave63));
$ordre63  = array_column($salves63, 'ouvert_le');
$croissant63 = $ordre63;
sort($croissant63);
in_array($grave63, $liste63, true) && count($meute63) === 1 && count($salves63) === 50 && ($salves63[0]['total_cause'] ?? 0) >= 51
    && $ordre63 === $croissant63 && ($salves63[0]['account_id'] ?? 0) === $visees63[0]
    ? ok('⭐ cinquante et une salves ne poussent pas la récidive hors de la vue ; la page des salves dit ce qu\'elle tait')
    : nok('⭐ un flot de salves enfouit le signal grave : ' . json_encode([
        'dans_la_liste' => in_array($grave63, $liste63, true), 'dans_sa_page' => count($meute63),
        'salves_affichees' => count($salves63), 'total' => $salves63[0]['total_cause'] ?? null,
        'plus_anciennes_d_abord' => $ordre63 === $croissant63, 'la_plus_ancienne_en_tete' => ($salves63[0]['account_id'] ?? 0) === $visees63[0],
    ]));

// ── 64. ⭐ Un ban automatique ne laisse pas de signal orphelin ───────────────
// Pendant la peine, un vote passé dans la transaction d'un hôte ne pose pas un
// second signal pour le même fait ; à la fin, la chute et le ban cessent
// ensemble, et le compte quitte la liste de l'arbitre.
$orphelin64 = membre($pdo, 'orphelin-64');
poserReputation($pdo, $orphelin64, 1);
$post64 = message($pdo, $orphelin64, 'fil-64');
Moderate::applyVote($pdo, membre($pdo, 'chute-64'), 'member', $orphelin64, -1, motif(), 'agressif');
$votant64 = membre($pdo, 'hote-64');
$pdo->beginTransaction();
Moderate::applyVote($pdo, $votant64, 'post', $post64, -1, motif(), 'agressif');
$pdo->commit();
$pendant64 = causes($pdo, $orphelin64);
vieillirBan($pdo, $orphelin64, 10_000);
rattraper($pdo, $orphelin64);
$apres64 = Moderate::getReputation($pdo, $orphelin64);
$liste64 = in_array($orphelin64, array_column(Moderate::flaggedForReview($pdo, 1000), 'account_id'), true);
$fin64   = $journalC->de($orphelin64, 'ban_fin');
$pendant64 === ['ban_auto'] && !$apres64['banned'] && !$apres64['needs_review'] && ($apres64['signaux'] ?? null) === [] && !$liste64
    && array_column(liste($fin64[0]['ferme'] ?? null) ?? [], 'cause') === ['ban_auto']
    ? ok('⭐ un ban automatique ne porte qu\'un signal, et il tombe avec la peine : aucun orphelin')
    : nok('⭐ un signal survit à la fin du ban : ' . json_encode([
        'pendant' => $pendant64, 'apres' => [$apres64['banned'], $apres64['review_reason'], $apres64['signaux'] ?? 'absents'],
        'liste' => $liste64, 'ferme' => $fin64[0]['ferme'] ?? null,
    ]));

// ── 65. ⭐ Un signal compte les faits, et l'arbitre ne ferme que ce qu'il a vu ─
// Une salve se redétecte à chaque downvote de sa fenêtre : cinq votants, une
// occurrence. Une détection dans la fenêtre ne déplace pas la dernière
// occurrence comptée — sinon un filet continu resterait à un pour toujours. Une
// salve neuve, la fenêtre passée, compte une occurrence de plus, et l'arbitre
// qui classe ce qu'il avait affiché avant elle est refusé.
$compte65  = membre($pdo, 'compte-65');
poserReputation($pdo, $compte65, 20);
$post65    = message($pdo, $compte65, 'fil-65');
$fenetre65 = 2 * Moderate::config()->fenetreSalveSecondes;
foreach (range(1, 5) as $i) {
    Moderate::applyVote($pdo, membre($pdo, "salve-$i-65"), 'post', $post65, -1, motif(), 'agressif');
}
$vu65 = Moderate::getReputation($pdo, $compte65)['signaux'][0] ?? ['signal' => 0, 'occurrences' => 0, 'detections' => 0];
$jeton65 = ['signal' => $vu65['signal'], 'detections' => $vu65['detections']];
$filetLe65 = time() - $fenetre65 + 30;
$pdo->prepare('UPDATE mod_signaux SET derniere_le = ? WHERE account_id = ?')->execute([$filetLe65, $compte65]);
Moderate::applyVote($pdo, membre($pdo, 'salve-6-65'), 'post', $post65, -1, motif(), 'agressif');
$filet65 = Moderate::getReputation($pdo, $compte65)['signaux'][0] ?? null;
// Un votant de plus, après l'affichage, replié dans le même fait : le compte ne bouge
// pas, le jeton si — l'arbitre ne le ferme pas sans l'avoir vu.
$replie65 = Moderate::adminClasser($pdo, $compte65, 'arbitre-banc', motif(), $jeton65);
$pdo->prepare('UPDATE mod_votes SET created_at = created_at - ? WHERE target_author = ?')->execute([3 * $fenetre65, $compte65]);
$pdo->prepare('UPDATE mod_signaux SET derniere_le = derniere_le - ? WHERE account_id = ?')->execute([3 * $fenetre65, $compte65]);
foreach (range(7, 9) as $i) {
    Moderate::applyVote($pdo, membre($pdo, "salve-$i-65"), 'post', $post65, -1, motif(), 'agressif');
}
$neuve65  = Moderate::getReputation($pdo, $compte65)['signaux'][0] ?? null;
$perime65 = Moderate::adminClasser($pdo, $compte65, 'arbitre-banc', motif(), ['signal' => $vu65['signal'], 'detections' => $filet65['detections'] ?? 0]);
$juste65  = Moderate::adminClasser($pdo, $compte65, 'arbitre-banc', motif(), ['signal' => $vu65['signal'], 'detections' => $neuve65['detections'] ?? 0]);
$acte65   = $journalC->de($compte65, 'revue_classee');
$vu65['occurrences'] === 1 && ($filet65['occurrences'] ?? 0) === 1 && ($filet65['derniere_le'] ?? 0) === $filetLe65
    && !$replie65['ok'] && str_contains($replie65['message'], 'changé')
    && ($neuve65['occurrences'] ?? 0) === 2 && ($neuve65['signal'] ?? 0) === $vu65['signal']
    && !$perime65['ok'] && str_contains($perime65['message'], 'changé') && $juste65['ok'] && causes($pdo, $compte65) === []
    && count($acte65) === 1 && (liste($acte65[0]['ferme'] ?? null)[0]['occurrences'] ?? 0) === 2
    && (liste($acte65[0]['ferme'] ?? null)[0]['detections'] ?? 0) === ($neuve65['detections'] ?? -1)
    ? ok('⭐ une salve redétectée reste un fait, une salve neuve en fait deux, et un classement sur l\'affichage d\'avant est refusé — même quand la détection a replié d\'autres votants')
    : nok('⭐ le signal compte les détections, ou l\'arbitre ferme ce qu\'il n\'a pas vu : ' . json_encode([
        'vu' => $vu65, 'filet' => $filet65, 'attendu_derniere_le' => $filetLe65, 'neuve' => $neuve65,
        'replie' => $replie65, 'perime' => $perime65, 'juste' => $juste65, 'actes' => count($acte65),
    ], JSON_UNESCAPED_UNICODE));

// ── 66. ⭐ Classer ferme UNE cause, que l'arbitre nomme ──────────────────────
// Deux causes ouvertes : un classement qui n'en nomme aucune est refusé, et
// dit lesquelles ; nommée, la salve se ferme et la récidive reste. Rejoué sur
// la salve déjà close, le même geste est refusé, et rien ne s'inscrit.
$deux66 = membre($pdo, 'deux-causes-66');
poserReputation($pdo, $deux66, 20);
planterSignal($pdo, $deux66, 'salve_rapide');
planterSignal($pdo, $deux66, 'meute_recidive');
$aveugle66 = Moderate::adminClasser($pdo, $deux66, 'arbitre-banc', motif());
$salve66   = array_values(array_filter(Moderate::getReputation($pdo, $deux66)['signaux'] ?? [],
    static fn (array $s): bool => $s['cause'] === 'salve_rapide'))[0] ?? ['signal' => 0, 'detections' => 0];
$jeton66   = ['signal' => $salve66['signal'], 'detections' => $salve66['detections']];
$nomme66   = Moderate::adminClasser($pdo, $deux66, 'arbitre-banc', motif(), $jeton66);
$rejoue66  = Moderate::adminClasser($pdo, $deux66, 'arbitre-banc', motif(), $jeton66);
$actes66   = $journalC->de($deux66, 'revue_classee');
!$aveugle66['ok'] && str_contains($aveugle66['message'], 'salve_rapide, meute_recidive') && $nomme66['ok'] && !$rejoue66['ok']
    && causes($pdo, $deux66) === ['meute_recidive'] && count($actes66) === 1
    && liste($actes66[0]['ouverts'] ?? null) === ['salve_rapide', 'meute_recidive']
    && array_column(liste($actes66[0]['ferme'] ?? null) ?? [], 'cause') === ['salve_rapide'] && ($actes66[0]['raison'] ?? null) === 'salve_rapide'
    ? ok('⭐ classer ferme la cause nommée et elle seule ; sans nom, ou sur une cause close, le geste est refusé')
    : nok('⭐ un classement ferme ce que l\'arbitre n\'a pas nommé : ' . json_encode([
        $aveugle66, $nomme66, $rejoue66, causes($pdo, $deux66), $actes66,
    ], JSON_UNESCAPED_UNICODE));

// ── 67. ⭐ La grâce ferme ce qu'elle défait, et laisse le reste à classer ────
// Elle remet la réputation au départ et efface le rang de meute : la chute et
// la récidive cessent avec eux. Une salve n'a rien à voir avec ce qu'elle remet,
// et attend un classement motivé. Sans rang à effacer, la récidive reste aussi.
$gracie67 = membre($pdo, 'gracie-67');
poserReputation($pdo, $gracie67, 3);
$pdo->prepare('UPDATE member_moderation SET convalescent = 1, last_regen_at = ? WHERE account_id = ?')->execute([time(), $gracie67]);
foreach (['reputation_zero', 'salve_rapide', 'meute_recidive'] as $cause67) {
    planterSignal($pdo, $gracie67, $cause67);
}
foreach ([1, 2] as $r) {
    $pdo->prepare("INSERT INTO mod_pack_flags (voter_id, target_author, rang, action, detected_at) VALUES (?, ?, ?, 'avertissement', ?)")
        ->execute([$gracie67, $gracie67, $r, time() - (3 - $r) * 86400]);
}
$g67 = Moderate::adminPardon($pdo, $gracie67, 'arbitre-banc', motif());
$acte67 = $journalC->de($gracie67, 'grace');
$sansRang67 = membre($pdo, 'sans-rang-67');
poserReputation($pdo, $sansRang67, 20);
planterSignal($pdo, $sansRang67, 'meute_recidive');
Moderate::adminPardon($pdo, $sansRang67, 'arbitre-banc', motif());
$g67['ok'] && causes($pdo, $gracie67) === ['salve_rapide']
    && liste($acte67[0]['ouverts'] ?? null) === ['reputation_zero', 'salve_rapide', 'meute_recidive']
    && array_column(liste($acte67[0]['ferme'] ?? null) ?? [], 'cause') === ['reputation_zero', 'meute_recidive']
    && causes($pdo, $sansRang67) === ['meute_recidive']
    ? ok('⭐ la grâce ferme la chute et la récidive dont elle efface le rang, et laisse la salve à classer')
    : nok('⭐ la grâce ferme plus, ou moins, qu\'elle ne défait : ' . json_encode([
        $g67, causes($pdo, $gracie67), $acte67, causes($pdo, $sansRang67),
    ], JSON_UNESCAPED_UNICODE));

// ── 68. ⭐ Un état vu sort de la file sans quitter la base ───────────────────
// Une réputation à zéro classée reste en base, vue, tant que la réputation
// n'est pas remontée — puis elle tombe seule. Un ban automatique ne se classe
// pas : il se maintient.
$vu68 = membre($pdo, 'vu-68');
poserReputation($pdo, $vu68, 0);
$pdo->prepare('UPDATE member_moderation SET convalescent = 1, last_regen_at = ? WHERE account_id = ?')->execute([time(), $vu68]);
planterSignal($pdo, $vu68, 'reputation_zero');
$c68 = Moderate::adminClasser($pdo, $vu68, 'arbitre-banc', motif());
$e68 = Moderate::getReputation($pdo, $vu68);
$enBase68 = (int) $pdo->query("SELECT COUNT(*) FROM mod_signaux WHERE account_id = $vu68 AND vu_le IS NOT NULL")->fetchColumn();
$enFile68 = in_array($vu68, array_column(Moderate::signauxOuverts($pdo, 1000), 'account_id'), true)
    || in_array($vu68, array_column(Moderate::flaggedForReview($pdo, 1000), 'account_id'), true);
$pdo->prepare('UPDATE member_moderation SET last_regen_at = ? WHERE account_id = ?')
    ->execute([time() - 100 * Moderate::config()->intervalleConvalescenceSecondes, $vu68]);
Moderate::regenerate($pdo, $vu68);
$remonte68 = Moderate::getReputation($pdo, $vu68);
$restant68 = (int) $pdo->query("SELECT COUNT(*) FROM mod_signaux WHERE account_id = $vu68")->fetchColumn();
$auto68 = membre($pdo, 'auto-68');
Moderate::ensureRow($pdo, $auto68);
$pdo->prepare("UPDATE member_moderation
                  SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                      ban_origine = 'auto', ban_motif = 'reputation_zero'
                WHERE account_id = ?")->execute([time() + 3600, time(), $auto68]);
planterSignal($pdo, $auto68, 'ban_auto');
$refus68 = Moderate::adminClasser($pdo, $auto68, 'arbitre-banc', motif());
($c68['ok'] ?? false) && !$e68['needs_review'] && $enBase68 === 1 && !$enFile68 && !$remonte68['convalescent'] && $restant68 === 0
    && !$refus68['ok'] && str_contains($refus68['message'], 'se maintient')
    ? ok('⭐ une chute classée reste en base, vue, puis tombe avec la remontée ; un ban automatique ne se classe pas')
    : nok('⭐ un état vu quitte la base trop tôt, ou un ban se classe : ' . json_encode([
        $c68, $e68['needs_review'], $enBase68, 'en_file' => $enFile68, $remonte68['convalescent'], $restant68, $refus68,
    ], JSON_UNESCAPED_UNICODE));

// ── 69. ⭐ Une base d'avant la table migre une fois, sans perdre un signal ───
// Une copie de la base, ramenée à la forme d'avant : la case seule, pas de
// table, pas de marque. À l'ouverture, chaque case ouverte devient une ligne,
// une case sans cause devient `signal_anterieur`, un état dont l'état a cessé
// ne passe pas — la chute reste sous un ban d'arbitre, que rien ne lève avec
// elle —, et un ban automatique en cours retrouve son signal même quand la
// case portait autre chose — une salve qui l'a causé, ou la chute —, vu s'il
// avait été maintenu, daté de sa dernière écriture s'il ne portait pas son
// début. Rouverte, la base ne bouge plus — même quand un écrivain
// resté à l'ancienne forme a écrit la case entre-temps : rejouée, la copie en
// ferait un signal sans un mot.
$formes69 = [
    'salve'          => ['salve_rapide', null, 0, 0, 1],
    'sans-cause'     => [null, null, 0, 0, 1],
    'ban'            => ['ban_auto', 'auto', time() + 3600, 0, 1],
    'orphelin'       => ['reputation_zero', null, 0, 0, 1],
    'salve-ban'      => ['salve_rapide', 'auto', time() + 3600, 0, 1],
    'ban-maintenu'   => [null, 'auto', time() + 3600, 0, 0],
    'chute-ban'      => ['reputation_zero', 'auto', time() + 3600, 1, 1],
    'case-close'     => ['salve_rapide', null, 0, 0, 0],
    'chute-arbitre'  => ['reputation_zero', 'admin', time() + 3600, 1, 1],
    'ban-sans-debut' => ['salve_rapide', 'auto', time() + 3600, 0, 1, 0],
];
$comptes69 = [];
foreach (array_keys($formes69) as $forme) {
    $comptes69[$forme] = membre($pdo, "mig-$forme-69");
    Moderate::ensureRow($pdo, $comptes69[$forme]);
}
$copie69 = $dir . '/ancienne-forme.db';
$pdo->exec("VACUUM INTO '$copie69'");
$ancienne69 = new PDO('sqlite:' . $copie69);
$ancienne69->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ancienne69->exec("DROP TABLE IF EXISTS mod_signaux; DELETE FROM lab_migrations WHERE nom = 'signaux-en-table'");
$case69 = $ancienne69->prepare(
    'UPDATE member_moderation SET review_reason = ?, ban_origine = ?, banned_until = ?, ban_debut = ?, convalescent = ?, needs_review = ?
      WHERE account_id = ?'
);
foreach ($formes69 as $forme => $f69) {
    [$cause, $origine, $jusqua, $convalescent, $ouverte] = $f69;
    $case69->execute([$cause, $origine, $jusqua, $f69[5] ?? ($jusqua > 0 ? time() - 60 : 0), $convalescent, $ouverte, $comptes69[$forme]]);
}
$ancienne69 = null;
$cheminBanc69 = getenv('LAB_DB_PATH');
putenv("LAB_DB_PATH=$copie69");
try {
    Db::fermer();
    $lue69 = static function () use ($comptes69): array {
        $p = Db::pdo();
        $lu = ['signaux' => [], 'cases' => []];
        foreach ($comptes69 as $forme => $m) {
            $s = $p->prepare("SELECT cause || CASE WHEN vu_le IS NULL THEN '' ELSE ' (vu)' END AS cause FROM mod_signaux WHERE account_id = ? ORDER BY id");
            $s->execute([$m]);
            $c = $p->prepare('SELECT needs_review, review_reason FROM member_moderation WHERE account_id = ?');
            $c->execute([$m]);
            $lu['signaux'][$forme] = array_column($s->fetchAll(), 'cause');
            $lu['cases'][$forme] = $c->fetch();
        }
        $lu['total'] = (int) $p->query('SELECT COUNT(*) FROM mod_signaux')->fetchColumn();
        $lu['en_1970'] = (int) $p->query('SELECT COUNT(*) FROM mod_signaux WHERE ouvert_le = 0')->fetchColumn();
        $lu['invariants'] = invariantsSignaux($p);

        return $lu;
    };
    $premiere69 = $lue69();
    Db::pdo()->prepare("UPDATE member_moderation SET needs_review = 1, review_reason = 'salve_rapide' WHERE account_id = ?")
        ->execute([$comptes69['orphelin']]);
    Db::fermer();
    $seconde69 = $lue69();
} catch (Throwable $t69) {
    $premiere69 = $seconde69 = ['exception' => $t69->getMessage()];
} finally {
    Db::fermer();
    putenv("LAB_DB_PATH=$cheminBanc69");
}
$attendu69 = [
    'salve'        => [['salve_rapide'], ['needs_review' => 1, 'review_reason' => 'salve_rapide']],
    'sans-cause'   => [['signal_anterieur'], ['needs_review' => 1, 'review_reason' => 'signal_anterieur']],
    'ban'          => [['ban_auto'], ['needs_review' => 1, 'review_reason' => 'ban_auto']],
    'orphelin'     => [[], ['needs_review' => 0, 'review_reason' => null]],
    'salve-ban'    => [['salve_rapide', 'ban_auto'], ['needs_review' => 1, 'review_reason' => 'salve_rapide']],
    'ban-maintenu' => [['ban_auto (vu)'], ['needs_review' => 0, 'review_reason' => null]],
    'chute-ban'    => [['ban_auto'], ['needs_review' => 1, 'review_reason' => 'ban_auto']],
    'case-close'   => [[], ['needs_review' => 0, 'review_reason' => null]],
    'chute-arbitre'  => [['reputation_zero'], ['needs_review' => 1, 'review_reason' => 'reputation_zero']],
    'ban-sans-debut' => [['salve_rapide', 'ban_auto'], ['needs_review' => 1, 'review_reason' => 'salve_rapide']],
];
$obtenu69 = [];
foreach (array_keys($attendu69) as $forme) {
    $obtenu69[$forme] = [$premiere69['signaux'][$forme] ?? null, $premiere69['cases'][$forme] ?? null];
}
$obtenu69 === $attendu69 && ($premiere69['invariants'] ?? null) === ['orphelins' => [], 'bans_sans_signal' => [], 'chutes_sans_signal' => [], 'cases' => []]
    && ($premiere69['en_1970'] ?? -1) === 0
    && ($seconde69['signaux'] ?? null) === $premiere69['signaux'] && ($seconde69['total'] ?? null) === $premiere69['total']
    ? ok('⭐ une base d\'avant la table migre une fois : chaque case ouverte devient une ligne, chaque ban automatique retrouve son signal, l\'orphelin ne passe pas')
    : nok('⭐ la migration perd un signal, en invente un, ou se rejoue : ' . json_encode([
        'obtenu' => $obtenu69, 'invariants' => $premiere69['invariants'] ?? $premiere69, 'en_1970' => $premiere69['en_1970'] ?? null,
        'seconde' => $seconde69['signaux'] ?? $seconde69,
    ], JSON_UNESCAPED_UNICODE));

// ── 70. ⭐ Un votant étendu ne prive pas l'arbitre du classement en votant encore ─
// Ses votes neutralisés ne portent que lui-même : ils ne comptent qu'une fois par
// période de balayage, et un classement fondé sur l'affichage tient tant qu'aucun
// fait neuf n'est compté. La période passée, le vote suivant compte, et
// l'affichage d'avant est refusé.
$etendu70 = membre($pdo, 'etendu-70');
poserReputation($pdo, $etendu70, 20);
voterLarge($pdo, $etendu70, 'large-70');
$vu70 = array_values(array_filter(Moderate::getReputation($pdo, $etendu70)['signaux'] ?? [],
    static fn (array $s): bool => $s['cause'] === 'votant_etendu'))[0] ?? ['signal' => 0, 'detections' => 0];
foreach (range(1, 3) as $k) {
    Moderate::applyVote($pdo, $etendu70, 'member', membre($pdo, "encore-$k-70"), -1, motif(), 'agressif');
}
$classe70 = Moderate::adminClasser($pdo, $etendu70, 'arbitre-banc', motif(), ['signal' => $vu70['signal'], 'detections' => $vu70['detections']]);
voterLarge($pdo, $etendu70, 'relance-70');
$vu70b = array_values(array_filter(Moderate::getReputation($pdo, $etendu70)['signaux'] ?? [],
    static fn (array $s): bool => $s['cause'] === 'votant_etendu'))[0] ?? ['signal' => 0, 'detections' => 0];
$pdo->prepare('UPDATE mod_signaux SET derniere_le = derniere_le - ? WHERE account_id = ?')
    ->execute([Moderate::config()->balayagePeriodeSecondes + 1, $etendu70]);
Moderate::applyVote($pdo, $etendu70, 'member', membre($pdo, 'apres-tranche-70'), -1, motif(), 'agressif');
$perime70 = Moderate::adminClasser($pdo, $etendu70, 'arbitre-banc', motif(), ['signal' => $vu70b['signal'], 'detections' => $vu70b['detections']]);
($classe70['ok'] ?? false) && !($perime70['ok'] ?? true) && str_contains($perime70['message'] ?? '', 'changé')
    ? ok('⭐ les votes neutralisés d\'un votant étendu ne bloquent pas le classement ; un fait compté après la tranche fait refuser l\'affichage d\'avant')
    : nok('⭐ le votant étendu tient le classement, ou un fait neuf passe inaperçu : ' . json_encode([$classe70, $perime70], JSON_UNESCAPED_UNICODE));

// ── 71. ⭐ Une reprise oubliée fait du bruit, et n'écrase pas la case ────────
// Une base d'avant la table, sans `migrerSignaux()` : la case porte un
// signalement que la table ignore. Le moteur refuse de lire ce compte et de lui
// poser un signal — recaler la case effacerait le seul endroit où ce signalement
// existe encore —, en nommant la reprise. Faite, tout repart.
$oublie71 = membre($pdo, 'oublie-71');
poserReputation($pdo, $oublie71, 20);
foreach (['a', 'b', 'c'] as $n) {
    $pdo->prepare('INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, created_at)
                   VALUES (?, ?, ?, ?, -1, ?, ?)')
        ->execute([membre($pdo, "salve-$n-71"), 'member', $oublie71, $oublie71, motif(), time()]);
}
$pdo->prepare("UPDATE member_moderation SET needs_review = 1, review_reason = 'meute_recidive' WHERE account_id = ?")
    ->execute([$oublie71]);
$lecture71 = $pose71 = null;
try {
    Moderate::getReputation($pdo, $oublie71);
} catch (LogicException $e71) {
    $lecture71 = $e71->getMessage();
}
try {
    Moderate::detectPackVoting($pdo, $oublie71);
} catch (LogicException $e71) {
    $pose71 = $e71->getMessage();
}
$case71 = $pdo->query("SELECT review_reason FROM member_moderation WHERE account_id = $oublie71")->fetchColumn();
Moderate::migrerSignaux($pdo);
Moderate::detectPackVoting($pdo, $oublie71);
$apres71 = causes($pdo, $oublie71);
str_contains((string) $lecture71, 'migrerSignaux') && str_contains((string) $pose71, 'migrerSignaux')
    && $case71 === 'meute_recidive' && $apres71 === ['meute_recidive', 'salve_rapide']
    ? ok('⭐ sans la reprise, le moteur refuse de lire ou de signaler le compte et nomme migrerSignaux() ; la case reste intacte, et la reprise faite, rien n\'est perdu')
    : nok('⭐ une reprise oubliée se tait, ou la case est écrasée : ' . json_encode([
        'lecture' => $lecture71, 'pose' => $pose71, 'case' => $case71, 'apres' => $apres71,
    ], JSON_UNESCAPED_UNICODE));

// ── 72. ⭐ Une cause illisible ne fige pas le compte ─────────────────────────
// La base, que l'hôte écrit aussi, peut porter une cause qui n'est pas de
// l'UTF-8. Elle est remplacée dans l'acte : la fin de peine s'inscrit, et aucun
// journal n'est dit en panne à tort.
$illisible72 = membre($pdo, 'illisible-72');
poserReputation($pdo, $illisible72, 1);
Moderate::applyVote($pdo, membre($pdo, 'chute-72'), 'member', $illisible72, -1, motif(), 'agressif');
planterSignal($pdo, $illisible72, "ancien_\xE9tat");
vieillirBan($pdo, $illisible72, 10_000);
rattraper($pdo, $illisible72);
$apres72   = Moderate::getReputation($pdo, $illisible72);
$fin72     = $journalC->de($illisible72, 'ban_fin');
$ouverts72 = liste($fin72[0]['ouverts'] ?? null);
!$apres72['banned'] && !in_array('journal_en_panne', array_column($apres72['signaux'] ?? [], 'cause'), true)
    && count($fin72) === 1 && is_array($ouverts72) && in_array('ban_auto', $ouverts72, true) && count($ouverts72) === 2
    ? ok('⭐ une cause qui n\'est pas de l\'UTF-8 n\'empêche pas la fin de peine de s\'inscrire, et ne passe pas pour une panne du journal')
    : nok('⭐ une cause illisible fige le compte : ' . json_encode([
        'banni' => $apres72['banned'], 'causes' => array_column($apres72['signaux'] ?? [], 'cause'), 'fins' => count($fin72),
        'ouverts' => $fin72[0]['ouverts'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

// ── 73. ⭐ Le balayage ne fait refuser que là où un vote neuf est tombé ──────
// Le premier downvote d'une tranche balaie tout le site, et relit sur les autres
// comptes des votes qu'une détection a déjà vus : l'affichage de l'arbitre y
// reste valable. Sur la cible du vote qui lance le balayage, un votant de plus
// replié dans la salve fait refuser l'affichage d'avant, comme hors balayage.
$autre73    = membre($pdo, 'autre-73');
$cible73    = membre($pdo, 'cible-73');
$ailleurs73 = membre($pdo, 'ailleurs-73');
foreach ([$autre73, $cible73, $ailleurs73] as $m73) {
    poserReputation($pdo, $m73, 20);
}
foreach (['a', 'b', 'c'] as $n) {
    Moderate::applyVote($pdo, membre($pdo, "salve-autre-$n-73"), 'member', $autre73, -1, motif(), 'agressif');
    Moderate::applyVote($pdo, membre($pdo, "salve-cible-$n-73"), 'member', $cible73, -1, motif(), 'agressif');
}
$jeton73 = static function (PDO $pdo, int $id, string $cause): array {
    $s = array_values(array_filter(Moderate::getReputation($pdo, $id)['signaux'] ?? [],
        static fn (array $s): bool => $s['cause'] === $cause))[0] ?? ['signal' => 0, 'detections' => 0];

    return ['signal' => $s['signal'], 'detections' => $s['detections']];
};
$vuAutre73 = $jeton73($pdo, $autre73, 'salve_rapide');
$vuCible73 = $jeton73($pdo, $cible73, 'salve_rapide');
$pdo->exec('DELETE FROM mod_sweeps');
Moderate::applyVote($pdo, membre($pdo, 'balai-73'), 'member', $ailleurs73, -1, motif(), 'agressif');
$classeAutre73 = Moderate::adminClasser($pdo, $autre73, 'arbitre-banc', motif(), $vuAutre73);
$pdo->exec('DELETE FROM mod_sweeps');
Moderate::applyVote($pdo, membre($pdo, 'salve-cible-d-73'), 'member', $cible73, -1, motif(), 'agressif');
$classeCible73 = Moderate::adminClasser($pdo, $cible73, 'arbitre-banc', motif(), $vuCible73);
($classeAutre73['ok'] ?? false) && !($classeCible73['ok'] ?? true) && str_contains($classeCible73['message'] ?? '', 'changé')
    ? ok('⭐ un balayage ne périme pas l\'affichage des comptes qu\'il relit ; sur la cible du vote qui le lance, un votant de plus le périme')
    : nok('⭐ le balayage périme un affichage sans vote neuf, ou laisse passer une salve repliée : ' . json_encode([
        'ailleurs' => $classeAutre73, 'cible' => $classeCible73,
    ], JSON_UNESCAPED_UNICODE));

// ── 74. ⭐ Une troncature ne fait refuser qu'un fait compté ──────────────────
// Elle dit la taille du graphe des votants, pas qui le compose : un vote de plus
// sur un compte très voté ne périme pas l'affichage. Borne abaissée, comme au
// n° 11bis.
Moderate::setConfig(new Config(meuteVotantsMax: 3));
try {
    $foule74 = membre($pdo, 'foule-74');
    poserReputation($pdo, $foule74, 20);
    foreach (range(1, 4) as $k) {
        $pdo->prepare('INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, created_at)
                       VALUES (?, ?, ?, ?, -1, ?, ?)')
            ->execute([membre($pdo, "foule-$k-74"), 'member', $foule74, $foule74, motif(), time() - 3600]);
    }
    Moderate::detectPackVoting($pdo, $foule74);
    $vu74 = $jeton73($pdo, $foule74, 'votants_tronques');
    Moderate::applyVote($pdo, membre($pdo, 'foule-5-74'), 'member', $foule74, -1, motif(), 'agressif');
} finally {
    Moderate::setConfig(null);
}
$classe74 = Moderate::adminClasser($pdo, $foule74, 'arbitre-banc', motif(), $vu74);
$vu74['signal'] > 0 && ($classe74['ok'] ?? false)
    ? ok('⭐ un vote de plus sur un compte tronqué ne périme pas l\'affichage de l\'arbitre')
    : nok('⭐ la troncature prive l\'arbitre du classement à chaque vote : ' . json_encode([$vu74, $classe74], JSON_UNESCAPED_UNICODE));

// ── 75. ⭐ Une détection ne compte que pour son signal, et un état ne se recompte pas ─
// Redétectée, une salve bouge son jeton et pas celui d'une autre cause du même
// compte. Une réputation à zéro, constatée à chaque vote qui la trouve à zéro,
// reste un fait, vu une fois. Et la fenêtre de regroupement inclut sa borne : un
// fait tombé pile à son terme compte.
$deux75 = membre($pdo, 'deux-75');
poserReputation($pdo, $deux75, 20);
foreach (['a', 'b', 'c'] as $n) {
    Moderate::applyVote($pdo, membre($pdo, "salve-$n-75"), 'member', $deux75, -1, motif(), 'agressif');
}
planterSignal($pdo, $deux75, 'meute_recidive');
Moderate::applyVote($pdo, membre($pdo, 'salve-d-75'), 'member', $deux75, -1, motif(), 'agressif');
$parCause75 = array_column(Moderate::getReputation($pdo, $deux75)['signaux'] ?? [], 'detections', 'cause');
$journalAvant75 = Moderate::journal();
Moderate::setJournal(null);
try {
    $zero75 = membre($pdo, 'zero-75');
    poserReputation($pdo, $zero75, 1);
    foreach (range(1, 3) as $k) {
        Moderate::applyVote($pdo, membre($pdo, "chute-$k-75"), 'member', $zero75, -1, motif(), 'agressif');
    }
    $chute75 = array_values(array_filter(Moderate::getReputation($pdo, $zero75)['signaux'] ?? [],
        static fn (array $s): bool => $s['cause'] === 'reputation_zero'))[0] ?? null;
} finally {
    Moderate::setJournal($journalAvant75);
}
$borne75 = membre($pdo, 'borne-75');
poserReputation($pdo, $borne75, 20);
voterLarge($pdo, $borne75, 'large-75');
$pdo->prepare("UPDATE mod_signaux SET derniere_le = ? WHERE account_id = ? AND cause = 'votant_etendu'")
    ->execute([time() - Moderate::config()->balayagePeriodeSecondes, $borne75]);
Moderate::applyVote($pdo, $borne75, 'member', membre($pdo, 'borne-apres-75'), -1, motif(), 'agressif');
$etendu75 = array_values(array_filter(Moderate::getReputation($pdo, $borne75)['signaux'] ?? [],
    static fn (array $s): bool => $s['cause'] === 'votant_etendu'))[0] ?? null;
($parCause75['salve_rapide'] ?? 0) === 2 && ($parCause75['meute_recidive'] ?? 0) === 1
    && ($chute75['occurrences'] ?? 0) === 1 && ($chute75['detections'] ?? 0) === 1
    && ($etendu75['occurrences'] ?? 0) === 2
    ? ok('⭐ une détection ne bouge que son signal, un état constaté trois fois reste un fait, et un fait à la borne du regroupement compte')
    : nok('⭐ le compte d\'un signal suit autre chose que ses faits : ' . json_encode([
        'par_cause' => $parCause75, 'chute' => $chute75, 'a_la_borne' => $etendu75,
    ], JSON_UNESCAPED_UNICODE));

// ── 76. ⭐ Une cause inconnue se classe comme un comportement, et la machine n'en ferme aucun ─
// `signal_anterieur`, reprise d'une case sans cause, quitte la base quand
// l'arbitre le classe, comme tout ce qu'il ferme. Et la levée d'un état refuse
// une cause de comportement : la machine ne ferme jamais ce qu'un arbitre doit voir.
$anterieur76 = membre($pdo, 'anterieur-76');
poserReputation($pdo, $anterieur76, 20);
planterSignal($pdo, $anterieur76, 'signal_anterieur');
$classe76  = Moderate::adminClasser($pdo, $anterieur76, 'arbitre-banc', motif());
$reste76   = (int) $pdo->query("SELECT COUNT(*) FROM mod_signaux WHERE account_id = $anterieur76")->fetchColumn();
$machine76 = membre($pdo, 'machine-76');
poserReputation($pdo, $machine76, 20);
planterSignal($pdo, $machine76, 'salve_rapide');
$refus76 = null;
try {
    (new ReflectionMethod(Moteur::class, 'designaler'))->invoke(null, $pdo, $machine76, 'salve_rapide');
} catch (LogicException $e76) {
    $refus76 = $e76->getMessage();
}
($classe76['ok'] ?? false) && $reste76 === 0 && $refus76 !== null && causes($pdo, $machine76) === ['salve_rapide']
    ? ok('⭐ un signal antérieur classé quitte la base, et la levée d\'un état refuse de fermer un comportement')
    : nok('⭐ une cause inconnue reste en base une fois classée, ou la machine ferme un comportement : ' . json_encode([
        $classe76, $reste76, $refus76, causes($pdo, $machine76),
    ], JSON_UNESCAPED_UNICODE));

// ── 77. ⭐ Une chute pendant un ban d'arbitre se signale, et reste à sa fin ──
// Un ban automatique porte le signal de la chute qui l'a causé ; un ban d'arbitre
// n'en porte aucun. La réputation qui tombe à zéro pendant lui se signale, avec
// ou sans journal, et le signal survit à l'échéance : rien ne le lève avec elle.
$sousArbitre77 = [];
foreach (['journal' => $journalC, 'sans-journal' => null] as $forme77 => $journal77) {
    $journalAvant77 = Moderate::journal();
    Moderate::setJournal($journalC);
    $compte77 = membre($pdo, "sous-arbitre-$forme77-77");
    poserReputation($pdo, $compte77, 1);
    Moderate::adminBan($pdo, $compte77, 'arbitre-banc', motif());
    Moderate::setJournal($journal77);
    try {
        Moderate::applyVote($pdo, membre($pdo, "chute-$forme77-77"), 'member', $compte77, -1, motif(), 'agressif');
        $pendant77 = in_array('reputation_zero', array_column(
            $pdo->query("SELECT cause FROM mod_signaux WHERE account_id = $compte77")->fetchAll(), 'cause'), true);
    } finally {
        Moderate::setJournal($journalAvant77);
    }
    vieillirBan($pdo, $compte77, 100 * Moderate::config()->dureeBanPourEpisode(1));
    rattraper($pdo, $compte77);
    $apres77 = Moderate::getReputation($pdo, $compte77);
    $sousArbitre77[$forme77] = [
        'pendant' => $pendant77, 'banni_apres' => $apres77['banned'],
        'apres' => array_column($apres77['signaux'] ?? [], 'cause'),
        'dans_la_liste' => in_array($compte77, array_column(Moderate::flaggedForReview($pdo, 1000), 'account_id'), true),
    ];
}
$sousArbitre77 === array_fill_keys(['journal', 'sans-journal'],
        ['pendant' => true, 'banni_apres' => false, 'apres' => ['reputation_zero'], 'dans_la_liste' => true])
    ? ok('⭐ une chute à zéro pendant un ban d\'arbitre se signale, avec ou sans journal, et le signal survit à l\'échéance')
    : nok('⭐ une chute pendant un ban d\'arbitre passe inaperçue : ' . json_encode($sousArbitre77, JSON_UNESCAPED_UNICODE));

// ── 78. ⭐ La convalescence ne reprend pas un point gagné par un vote positif ──
// La remontée passive borne sa propre progression, pas le score : un compte que des
// votes positifs ont monté au-dessus de son point de départ pendant la
// convalescence y reste, et l'état se lève dès le seuil atteint, sans attendre un
// intervalle. Le score monté est posé ici directement.
$monte78 = [];
foreach (['sans-intervalle' => 0, 'apres-un-intervalle' => Moderate::config()->intervalleConvalescenceSecondes] as $forme78 => $ecoule78) {
    $compte78 = membre($pdo, "convalescent-$forme78-78");
    poserReputation($pdo, $compte78, 25);
    $pdo->prepare('UPDATE member_moderation SET convalescent = 1, voting_rights = 0, last_regen_at = ? WHERE account_id = ?')
        ->execute([time() - $ecoule78, $compte78]);
    $e78 = Moderate::getReputation($pdo, $compte78);
    $monte78[$forme78] = ['reputation' => $e78['reputation'], 'convalescent' => $e78['convalescent'], 'vote' => $e78['voting_rights']];
}
$monte78 === array_fill_keys(['sans-intervalle', 'apres-un-intervalle'], ['reputation' => 25, 'convalescent' => false, 'vote' => true])
    ? ok('⭐ la convalescence garde un score monté par des votes positifs, et se lève dès le seuil atteint')
    : nok('⭐ la convalescence rabat un score, ou tient un compte déjà remonté : ' . json_encode($monte78));

// ── 79. ⭐ Un geste sur un compte inexistant est refusé, sans erreur ─────────
// La ligne de modération a une clé étrangère vers les comptes de l'hôte : un
// geste sur un identifiant inconnu butait sur elle, et la route rendait une
// erreur 500. Les quatre gestes refusent, et rien ne s'inscrit au journal.
$inconnu79 = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM accounts')->fetchColumn();
$actesAvant79 = count($journalC->actes);
$gestes79 = [];
foreach ([
    'ban'       => static fn (): array => Moderate::adminBan($pdo, $inconnu79, 'arbitre-banc', motif()),
    'grace'     => static fn (): array => Moderate::adminPardon($pdo, $inconnu79, 'arbitre-banc', motif()),
    'maintenir' => static fn (): array => Moderate::adminMaintenir($pdo, $inconnu79, 'arbitre-banc', motif()),
    'classer'   => static fn (): array => Moderate::adminClasser($pdo, $inconnu79, 'arbitre-banc', motif()),
] as $geste79 => $appel79) {
    try {
        $r79 = $appel79();
        $gestes79[$geste79] = [$r79['ok'], $r79['message']];
    } catch (Throwable $t79) {
        $gestes79[$geste79] = get_class($t79);
    }
}
$gestes79 === array_fill_keys(['ban', 'grace', 'maintenir', 'classer'], [false, 'Compte introuvable.'])
    && count($journalC->actes) === $actesAvant79
    ? ok('⭐ les quatre gestes refusent un compte inexistant, sans erreur et sans rien inscrire')
    : nok('⭐ un geste sur un compte inexistant échoue autrement qu\'en refus : ' . json_encode([
        'gestes' => $gestes79, 'actes' => count($journalC->actes) - $actesAvant79,
    ], JSON_UNESCAPED_UNICODE));

// ── 80. ⭐ Le jour des motifs est celui de l'hôte, et la borne garde l'ordre ──
// Le jour se compte dans le fuseau de l'hôte : un motif reçu à 23:30 UTC est du
// lendemain à Paris, où le lab affiche ses heures. Et la limite ne coupe pas le
// dernier jour lu dans l'ordre d'arrivée : il reste alphabétique — B y arrive
// après A, pour que les deux ordres se distinguent.
$fuseauAvant80 = date_default_timezone_get();
date_default_timezone_set('Europe/Paris');
try {
    $recoit80 = membre($pdo, 'recoit-tard-80');
    $post80 = message($pdo, $recoit80, 'fil-tard-80');
    $veille80 = intdiv(time(), 86400) * 86400 - 3 * 86400;
    $poses80 = [
        ['B — Une réponse qui détourne le fil vers un autre sujet sans rapport avec la question.', $veille80 + 7200],
        ['A — Une affirmation présentée comme établie, sans aucune source pour l\'appuyer.', $veille80 + 3600],
        ['C — Un ton méprisant envers la personne plutôt qu\'une critique de ce qu\'elle dit.', $veille80 + 86400 + 23 * 3600 + 1800],
    ];
    foreach ($poses80 as $k => [$texte80, $quand80]) {
        $pdo->prepare(
            'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, reason_code, effet, created_at)
             VALUES (?, ?, ?, ?, -1, ?, ?, -1, ?)'
        )->execute([membre($pdo, "tard-motif-$k-80"), 'post', $post80, $recoit80, $texte80, 'hors_sujet', $quand80]);
    }
    $tard80  = Moderate::reasonsFor($pdo, $recoit80);
    $borne80 = Moderate::reasonsFor($pdo, $recoit80, 2);
    $attenduTard80 = (new DateTimeImmutable('@' . $poses80[2][1]))->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d');
} finally {
    date_default_timezone_set($fuseauAvant80);
}
($tard80[0]['jour'] ?? '') === $attenduTard80 && $attenduTard80 !== gmdate('Y-m-d', $poses80[2][1])
    && array_map(static fn (array $m): string => mb_substr($m['reason'], 0, 1), $borne80) === ['C', 'A']
    ? ok('⭐ un motif reçu tard est daté du jour de l\'hôte, et la limite garde le dernier jour dans l\'ordre alphabétique')
    : nok('⭐ le jour des motifs suit UTC, ou la limite coupe dans l\'ordre d\'arrivée : ' . json_encode([
        'jour' => $tard80[0]['jour'] ?? null, 'attendu' => $attenduTard80,
        'borne' => array_map(static fn (array $m): string => mb_substr($m['reason'], 0, 1), $borne80),
    ], JSON_UNESCAPED_UNICODE));

// ── 81. ⭐ Les bans d'arbitre se comptent à part, et leur enchaînement se signale ─
// Un ban d'arbitre est un épisode, et alourdit le ban automatique suivant. L'acte de
// début de ban dit combien de ses épisodes viennent d'un arbitre ; au deuxième ban
// d'arbitre sur un même compte, `bans_arbitre` part à l'arbitre. La grâce remet
// les deux compteurs et ferme ce signal.
$enchaine81 = membre($pdo, 'enchaine-81');
poserReputation($pdo, $enchaine81, 20);
$premier81 = Moderate::adminBan($pdo, $enchaine81, 'arbitre-banc', motif());
$apresUn81 = causes($pdo, $enchaine81);
vieillirBan($pdo, $enchaine81, 100 * Moderate::config()->dureeBanAdmin());
$second81 = Moderate::adminBan($pdo, $enchaine81, 'arbitre-banc', motif());
$apresDeux81 = causes($pdo, $enchaine81);
vieillirBan($pdo, $enchaine81, 100 * Moderate::config()->dureeBanAdmin());
$pdo->prepare('UPDATE member_moderation SET reputation = 1, voting_rights = 1 WHERE account_id = ?')->execute([$enchaine81]);
Moderate::applyVote($pdo, membre($pdo, 'chute-81'), 'member', $enchaine81, -1, motif(), 'agressif');
$debuts81 = array_map(static fn (array $a): array => [$a['origine'], $a['episode'], $a['dont_arbitre'] ?? null],
    $journalC->de($enchaine81, 'ban_debut'));
$grace81 = Moderate::adminPardon($pdo, $enchaine81, 'arbitre-banc', motif());
$acteGrace81 = $journalC->de($enchaine81, 'ban_leve')[0] ?? [];
$compteurs81 = $pdo->query("SELECT strikes, strikes_admin FROM member_moderation WHERE account_id = $enchaine81")->fetch(PDO::FETCH_ASSOC);
($premier81['ok'] ?? false) && ($second81['ok'] ?? false)
    && !in_array('bans_arbitre', $apresUn81, true) && in_array('bans_arbitre', $apresDeux81, true)
    && $debuts81 === [['admin', 1, 1], ['admin', 2, 2], ['auto', 3, 2]]
    && ($grace81['ok'] ?? false) && !in_array('bans_arbitre', causes($pdo, $enchaine81), true)
    && in_array('bans_arbitre', array_column(liste($acteGrace81['ferme'] ?? null) ?? [], 'cause'), true)
    && $compteurs81 === ['strikes' => 0, 'strikes_admin' => 0]
    ? ok('⭐ chaque début de ban dit ses épisodes d\'arbitre, le deuxième ban d\'arbitre se signale, et la grâce remet tout à zéro')
    : nok('⭐ les bans d\'arbitre se perdent dans les strikes : ' . json_encode([
        'premier' => $premier81, 'second' => $second81, 'apres_un' => $apresUn81, 'apres_deux' => $apresDeux81,
        'debuts' => $debuts81, 'grace' => $grace81, 'ferme' => $acteGrace81['ferme'] ?? null, 'compteurs' => $compteurs81,
    ], JSON_UNESCAPED_UNICODE));

// ── 82. ⭐ Une lecture n'écrit rien, et montre ce que l'écriture inscrira ────
// Sur chaque état que le temps fait bouger — ligne absente, convalescence due,
// convalescence guérie, ban automatique échu, ban d'arbitre échu, ban en cours —,
// une lecture coûte au plus deux requêtes et n'écrit rien, ni en base ni au
// journal ; elle rend ce que la première écriture inscrira ensuite, et dit, pour
// une peine échue, que sa fin attend.
$intervalle82 = Moderate::config()->intervalleConvalescenceSecondes;
$formes82 = [
    'absente'         => [null, []],
    'convalescent-du' => [['reputation' => 3, 'convalescent' => 1, 'voting_rights' => 0, 'last_regen_at' => time() - 3 * $intervalle82], ['reputation_zero']],
    'gueri'           => [['reputation' => 18, 'convalescent' => 1, 'voting_rights' => 1, 'last_regen_at' => time() - 5 * $intervalle82], ['reputation_zero']],
    'auto-echu'       => [['reputation' => 0, 'strikes' => 1, 'convalescent' => 1, 'voting_rights' => 0, 'last_regen_at' => time() - 60,
                           'banned_until' => time() - 10, 'ban_debut' => time() - 130, 'ban_origine' => 'auto', 'ban_motif' => 'reputation_zero'], ['ban_auto']],
    'admin-echu'      => [['reputation' => 12, 'strikes' => 1, 'voting_rights' => 0,
                           'banned_until' => time() - 10, 'ban_debut' => time() - 130, 'ban_origine' => 'admin', 'ban_motif' => 'motif d\'arbitre'], []],
    'en-cours'        => [['reputation' => 0, 'strikes' => 1, 'convalescent' => 1, 'voting_rights' => 0, 'last_regen_at' => time() - 60,
                           'banned_until' => time() + 3600, 'ban_debut' => time() - 60, 'ban_origine' => 'auto', 'ban_motif' => 'reputation_zero'], ['ban_auto']],
];
$vue82 = static fn (array $e): array => [
    $e['reputation'], $e['voting_rights'], $e['banned'], $e['banned_until'], $e['convalescent'], array_column($e['signaux'], 'cause'),
];
$ecarts82 = [];
foreach ($formes82 as $forme82 => [$colonnes82, $signaux82]) {
    $compte82 = membre($pdo, "lecture-$forme82-82");
    if ($colonnes82 !== null) {
        Moderate::ensureRow($pdo, $compte82);
        $pose82 = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($colonnes82)));
        $pdo->prepare("UPDATE member_moderation SET $pose82 WHERE account_id = ?")->execute([...array_values($colonnes82), $compte82]);
        foreach ($signaux82 as $cause82) {
            planterSignal($pdo, $compte82, $cause82);
        }
    }
    $actes82 = count($journalC->actes);
    $avant82 = (int) $compteur->query('SELECT total_changes()')->fetchColumn();
    $compteur->requetes = 0;
    $lu82 = Moderate::getReputation($compteur, $compte82);
    $cout82 = $compteur->requetes;
    $ecrit82 = (int) $compteur->query('SELECT total_changes()')->fetchColumn() - $avant82;
    $lignes82 = (int) $pdo->query("SELECT COUNT(*) FROM member_moderation WHERE account_id = $compte82")->fetchColumn();
    $journalLecture82 = count($journalC->actes) - $actes82;
    rattraper($pdo, $compte82);
    $inscrit82 = Moderate::getReputation($pdo, $compte82);
    $echu82 = in_array($forme82, ['auto-echu', 'admin-echu'], true);
    if ($cout82 > 2 || $ecrit82 !== 0 || $journalLecture82 !== 0 || ($forme82 === 'absente' && $lignes82 !== 0)
        || $vue82($lu82) !== $vue82($inscrit82) || $lu82['fin_en_attente'] !== $echu82 || $inscrit82['fin_en_attente']) {
        $ecarts82[$forme82] = ['requetes' => $cout82, 'ecrit' => $ecrit82, 'journal' => $journalLecture82, 'lignes' => $lignes82,
            'lu' => $vue82($lu82), 'inscrit' => $vue82($inscrit82), 'fin_en_attente' => [$lu82['fin_en_attente'], $inscrit82['fin_en_attente']]];
    }
}
$ecarts82 === []
    ? ok('⭐ une lecture n\'écrit rien sur aucun état, coûte au plus deux requêtes, et montre ce que la première écriture inscrit')
    : nok('⭐ une lecture écrit, ou montre autre chose que ce qui s\'inscrira : ' . json_encode($ecarts82, JSON_UNESCAPED_UNICODE));

// ── 83. ⭐ Un écrivain du moteur ne décide pas sur la lecture ────────────────
// La lecture montre ce que la base ne porte pas encore ; un écrivain qui
// déciderait sur elle perdrait en silence ce qu'elle seule voit. Dans une
// écriture du moteur, `getReputation()` refuse, et nomme ce qu'il faut lire.
$garde83 = null;
try {
    (new ReflectionMethod(Moteur::class, 'transaction'))->invoke(null, $pdo,
        static fn (): array => Moderate::getReputation($pdo, membre($pdo, 'garde-83')));
} catch (LogicException $e83) {
    $garde83 = $e83->getMessage();
}
str_contains((string) $garde83, 'etat()')
    ? ok('⭐ une écriture du moteur qui lit par getReputation() est refusée')
    : nok('⭐ un écrivain du moteur peut décider sur la lecture : ' . json_encode($garde83, JSON_UNESCAPED_UNICODE));

// ── 84. ⭐ Le premier vote d'une tranche inscrit ce que personne n'a touché ──
// Sans planificateur, une fin de peine sur un compte que rien n'écrit ne
// s'inscrirait jamais. Le vote qui prend la tranche de balayage inscrit ensuite,
// hors de sa transaction, les fins et convalescences dues ; dans la transaction
// d'un hôte, il ne le fait pas.
Moderate::setConfig(new Config(balayageFiletMax: 10_000));
try {
    $oublie84 = membre($pdo, 'oublie-84');
    Moderate::ensureRow($pdo, $oublie84);
    $pdo->prepare("UPDATE member_moderation
                      SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                          ban_origine = 'auto', ban_motif = 'reputation_zero'
                    WHERE account_id = ?")->execute([time() - 10_000_000, time() - 10_000_120, $oublie84]);
    planterSignal($pdo, $oublie84, 'ban_auto');
    $hote84 = membre($pdo, 'oublie-hote-84');
    Moderate::ensureRow($pdo, $hote84);
    $pdo->prepare("UPDATE member_moderation
                      SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                          ban_origine = 'auto', ban_motif = 'reputation_zero'
                    WHERE account_id = ?")->execute([time() - 10_000_000, time() - 10_000_120, $hote84]);
    planterSignal($pdo, $hote84, 'ban_auto');
    $pdo->exec('DELETE FROM mod_sweeps');
    $pdo->beginTransaction();
    Moderate::applyVote($pdo, membre($pdo, 'votant-hote-84'), 'member', membre($pdo, 'vise-hote-84'), -1, motif(), 'agressif');
    $pdo->commit();
    $hoteIntact84 = (int) etatBan($pdo, $hote84)['banned_until'] > 0 && $journalC->de($hote84, 'ban_fin') === [];
    $pdo->exec('DELETE FROM mod_sweeps');
    Moderate::applyVote($pdo, membre($pdo, 'votant-84'), 'member', membre($pdo, 'vise-84'), -1, motif(), 'agressif');
} finally {
    Moderate::setConfig(null);
}
$inscrit84 = (int) etatBan($pdo, $oublie84)['banned_until'] === 0 && count($journalC->de($oublie84, 'ban_fin')) === 1;
$hoteIntact84 && $inscrit84
    ? ok('⭐ le premier vote d\'une tranche inscrit les fins que personne n\'a touchées, jamais dans la transaction d\'un hôte')
    : nok('⭐ le filet sans planificateur ne tient pas : ' . json_encode(['hote_intact' => $hoteIntact84, 'inscrit' => $inscrit84]));

// ── 85. ⭐ Le balayage inscrit les convalescences dues ───────────────────────
// La console lit la base : un compte remonté au point de départ que personne
// n'a écrit y garderait le signalement de sa chute. Le balayage l'inscrit, comme
// une peine échue — et un compte que des votes positifs ont déjà porté au seuil
// sort de convalescence.
$gueri85 = membre($pdo, 'gueri-85');
poserReputation($pdo, $gueri85, 18);
$pdo->prepare('UPDATE member_moderation SET convalescent = 1, last_regen_at = ? WHERE account_id = ?')
    ->execute([time() - 5 * Moderate::config()->intervalleConvalescenceSecondes, $gueri85]);
planterSignal($pdo, $gueri85, 'reputation_zero');
$porte85 = membre($pdo, 'porte-85');
poserReputation($pdo, $porte85, 24);
$pdo->prepare('UPDATE member_moderation SET convalescent = 1, last_regen_at = ? WHERE account_id = ?')->execute([time(), $porte85]);
planterSignal($pdo, $porte85, 'reputation_zero');
$erreur85 = null;
try {
    Moderate::balayerBansEchus($pdo);
} catch (Throwable $t85) {
    $erreur85 = $t85->getMessage();
}
$base85 = static fn (PDO $pdo, int $id): array => $pdo->query(
    "SELECT reputation, convalescent, (SELECT COUNT(*) FROM mod_signaux s WHERE s.account_id = m.account_id) AS signaux
       FROM member_moderation m WHERE account_id = $id")->fetch(PDO::FETCH_ASSOC);
$liste85 = array_column(Moderate::flaggedForReview($pdo, 1000), 'account_id');
$erreur85 === null && $base85($pdo, $gueri85) == ['reputation' => 20, 'convalescent' => 0, 'signaux' => 0]
    && $base85($pdo, $porte85) == ['reputation' => 24, 'convalescent' => 0, 'signaux' => 0]
    && !in_array($gueri85, $liste85, true) && !in_array($porte85, $liste85, true)
    ? ok('⭐ le balayage inscrit les convalescences dues : la console ne garde pas la chute d\'un compte guéri')
    : nok('⭐ un compte guéri garde sa chute en base : ' . json_encode([$erreur85, $base85($pdo, $gueri85), $base85($pdo, $porte85)], JSON_UNESCAPED_UNICODE));

// ── 86. ⭐ Une levée pour meute rend ce que le temps n'a pas déjà rendu ──────
// La sortie de convalescence remet le compte au départ et efface l'effet des
// votes reçus. Une meute reconnue ensuite ne rend rien de plus : compter la
// restitution avant d'inscrire la sortie rendrait deux fois les mêmes points.
$rendu86 = membre($pdo, 'rendu-86');
poserReputation($pdo, $rendu86, 19);
$pdo->prepare('UPDATE member_moderation SET convalescent = 1, last_regen_at = ? WHERE account_id = ?')
    ->execute([time() - Moderate::config()->intervalleConvalescenceSecondes, $rendu86]);
planterSignal($pdo, $rendu86, 'reputation_zero');
$pdo->prepare('INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, reason_code, effet, created_at)
               VALUES (?, ?, ?, ?, -1, ?, ?, -3, ?)')
    ->execute([membre($pdo, 'meute-86'), 'member', $rendu86, $rendu86, motif(), 'agressif', time() - 100]);
$vote86 = (int) $pdo->lastInsertId();
$restaurer86 = new ReflectionMethod(Moteur::class, 'restoreAfterCancel');
(new ReflectionMethod(Moteur::class, 'transaction'))->invoke(null, $pdo,
    static fn () => $restaurer86->invoke(null, $pdo, $rendu86, [$vote86]));
$apres86 = (int) $pdo->query("SELECT reputation FROM member_moderation WHERE account_id = $rendu86")->fetchColumn();
$effet86 = (int) $pdo->query("SELECT effet FROM mod_votes WHERE id = $vote86")->fetchColumn();
$apres86 === 20 && $effet86 === 0
    ? ok('⭐ une meute reconnue après la sortie de convalescence ne rend pas une seconde fois ce que la remise a rendu')
    : nok('⭐ une levée pour meute rend deux fois les mêmes points : ' . json_encode(['reputation' => $apres86, 'effet' => $effet86]));

// ── 87. ⭐ Le droit de vote revient au seuil, quel que soit le chemin ────────
// Le README rend le vote à 5 et lève l'état à 20. Un convalescent que des votes
// positifs portent au seuil n'attend pas le point suivant de la remontée, et
// l'horloge ne bouge pas ; une base migrée, sans horloge, lève son état dès la
// première écriture.
$porte87 = membre($pdo, 'porte-87');
poserReputation($pdo, $porte87, 4);
$pdo->prepare('UPDATE member_moderation SET convalescent = 1, voting_rights = 0, last_regen_at = ? WHERE account_id = ?')
    ->execute([time(), $porte87]);
planterSignal($pdo, $porte87, 'reputation_zero');
$horloge87 = (int) etatBan($pdo, $porte87)['last_regen_at'];
$pousse87 = Moderate::applyVote($pdo, membre($pdo, 'soutien-87'), 'member', $porte87, 1);
[$vote87, $pourquoi87] = Moderate::canVote($pdo, $porte87);
rattraper($pdo, $porte87);
$base87 = etatBan($pdo, $porte87);
$migre87 = membre($pdo, 'migre-87');
poserReputation($pdo, $migre87, 22);
$pdo->prepare('UPDATE member_moderation SET convalescent = 1, last_regen_at = 0 WHERE account_id = ?')->execute([$migre87]);
planterSignal($pdo, $migre87, 'reputation_zero');
rattraper($pdo, $migre87);
$leve87 = etatBan($pdo, $migre87);
($pousse87['ok'] ?? false) && $vote87 && (int) $base87['reputation'] === 5 && (int) $base87['voting_rights'] === 1
    && (int) $base87['convalescent'] === 1 && (int) $base87['last_regen_at'] === $horloge87
    && (int) $leve87['convalescent'] === 0 && causes($pdo, $migre87) === []
    ? ok('⭐ des votes positifs rendent le droit de vote au seuil sans attendre l\'intervalle, et une base migrée lève son état en une écriture')
    : nok('⭐ le droit de vote attend l\'intervalle au-dessus du seuil, ou une base migrée garde son état : ' . json_encode([
        'vote' => $pousse87, 'peut_voter' => [$vote87, $pourquoi87],
        'base' => array_intersect_key($base87, array_flip(['reputation', 'voting_rights', 'convalescent', 'last_regen_at'])),
        'horloge' => $horloge87, 'migre' => [(int) $leve87['convalescent'], causes($pdo, $migre87)],
    ], JSON_UNESCAPED_UNICODE));

// ── 88. ⭐ Un refus que la lecture décide ne prend pas le verrou ──────────────
// Un compte sans droit de vote, ou un motif trop court, se refusent sur ce que
// la lecture montre. Le rattrapage d'un compte qui n'a rien de dû ne prend pas
// le verrou d'écriture : sinon un autre écrivain qui le tient changerait ces
// refus en exception, et chaque tentative tiendrait le verrou du site.
$muet88 = membre($pdo, 'muet-88');
poserReputation($pdo, $muet88, 3);
$pdo->prepare('UPDATE member_moderation SET convalescent = 1, voting_rights = 0, last_regen_at = ? WHERE account_id = ?')
    ->execute([time(), $muet88]);
planterSignal($pdo, $muet88, 'reputation_zero');
$bavard88 = membre($pdo, 'bavard-88');
poserReputation($pdo, $bavard88, 20);
$vise88 = membre($pdo, 'vise-88');
poserReputation($pdo, $vise88, 20);
$tient88 = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
$tient88->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$vote88 = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
$vote88->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$vote88->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$vote88->exec('PRAGMA busy_timeout = 100');
$refus88 = [];
$tient88->exec('BEGIN IMMEDIATE');
try {
    foreach (['sans_droit' => [$muet88, motif()], 'motif_court' => [$bavard88, 'trop court']] as $cas88 => [$votant88, $motif88]) {
        try {
            $refus88[$cas88] = Moderate::applyVote($vote88, $votant88, 'member', $vise88, -1, $motif88, 'agressif');
        } catch (Throwable $t88) {
            $refus88[$cas88] = get_class($t88) . ' : ' . $t88->getMessage();
        }
    }
} finally {
    $tient88->exec('ROLLBACK');
}
is_array($refus88['sans_droit']) && !$refus88['sans_droit']['ok'] && str_contains($refus88['sans_droit']['message'], 'Droit de vote retiré')
    && is_array($refus88['motif_court']) && !$refus88['motif_court']['ok']
    ? ok('⭐ sous un autre écrivain, un vote sans droit ou au motif trop court rend son refus, sans attendre le verrou')
    : nok('⭐ un refus décidé par la lecture prend le verrou d\'écriture : ' . json_encode($refus88, JSON_UNESCAPED_UNICODE));

// ── 89. ⭐ La page dit le refus que le vote rendra ───────────────────────────
// Une peine échue dont le journal a refusé la fin : la page la montre finie, et
// le vote est refusé. `canVote()` le dit d'avance. Une fin qui attend seulement
// la première écriture, sans refus du journal, ne refuse rien : elle s'inscrit
// au vote même.
$refuse89 = membre($pdo, 'refuse-89');
Moderate::ensureRow($pdo, $refuse89);
$pdo->prepare("UPDATE member_moderation
                  SET reputation = 0, strikes = 1, voting_rights = 0, convalescent = 1, last_regen_at = ?,
                      banned_until = ?, ban_debut = ?, ban_origine = 'auto', ban_motif = 'reputation_zero'
                WHERE account_id = ?")->execute([time() - 200, time() - 10, time() - 130, $refuse89]);
planterSignal($pdo, $refuse89, 'ban_auto');
[$avant89] = Moderate::canVote($pdo, $refuse89);
Moderate::setJournal(new JournalEnPanne());
try {
    $vote89 = Moderate::applyVote($pdo, $refuse89, 'member', membre($pdo, 'vise-89'), 1);
} catch (Throwable $t89) {
    $vote89 = ['exception' => $t89->getMessage()];
} finally {
    Moderate::setJournal($journalC);
}
[$page89, $pourquoi89] = Moderate::canVote($pdo, $refuse89);
rattraper($pdo, $refuse89);
[$apres89] = Moderate::canVote($pdo, $refuse89);
$avant89 && !($vote89['ok'] ?? true) && !$page89 && str_contains($pourquoi89, 'attend que le journal') && $apres89
    ? ok('⭐ la page refuse d\'avance le vote d\'un compte dont le journal a refusé la fin, et le rend dès qu\'elle s\'inscrit')
    : nok('⭐ la page promet un vote que la fin en attente fera refuser : ' . json_encode([
        'avant' => $avant89, 'vote' => $vote89, 'page' => [$page89, $pourquoi89], 'apres' => $apres89,
    ], JSON_UNESCAPED_UNICODE));

// ── 90. ⭐ Un compte que le journal refuse n'arrête pas le balayage ──────────
// Le balayage prend les peines les plus anciennes d'abord. Un journal qui refuse
// la fin d'un seul compte — par son contenu — le signale, passe au suivant, et
// fait remonter le refus à la fin : les fins et les convalescences derrière lui
// s'inscrivent quand même.
$tete90 = membre($pdo, 'tete-90');
$suivant90 = membre($pdo, 'suivant-90');
foreach ([$tete90 => 30_000_000, $suivant90 => 29_000_000] as $compte90 => $recul90) {
    Moderate::ensureRow($pdo, $compte90);
    $pdo->prepare("UPDATE member_moderation
                      SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                          ban_origine = 'auto', ban_motif = 'reputation_zero'
                    WHERE account_id = ?")->execute([time() - $recul90, time() - $recul90 - 120, $compte90]);
    planterSignal($pdo, $compte90, 'ban_auto');
}
$conv90 = membre($pdo, 'conv-90');
poserReputation($pdo, $conv90, 3);
$pdo->prepare('UPDATE member_moderation SET convalescent = 1, voting_rights = 0, last_regen_at = ? WHERE account_id = ?')
    ->execute([time() - 2 * Moderate::config()->intervalleConvalescenceSecondes - 5, $conv90]);
planterSignal($pdo, $conv90, 'reputation_zero');
$journal90 = new class ($tete90) implements \Pierroons\SelfModerate\Journal {
    /** @var list<array<string, mixed>> */
    public array $actes = [];

    public function __construct(private int $refuse) {}

    public function inscrire(array $acte): void
    {
        if ($acte['compte'] === $this->refuse) {
            throw new RuntimeException('acte refusé par son contenu');
        }
        $this->actes[] = $acte;
    }
};
Moderate::setJournal($journal90);
$erreur90 = null;
try {
    Moderate::balayerBansEchus($pdo);
} catch (RuntimeException $e90) {
    $erreur90 = $e90->getMessage();
} finally {
    Moderate::setJournal($journalC);
}
$fins90 = array_column(array_filter($journal90->actes, static fn (array $a): bool => $a['acte'] === 'ban_fin'), 'compte');
$tenu90 = [(int) etatBan($pdo, $tete90)['banned_until'] > 0, causes($pdo, $tete90)];
$suivi90 = (int) etatBan($pdo, $suivant90)['banned_until'];
$remonte90 = (int) etatBan($pdo, $conv90)['reputation'];
rattraper($pdo, $tete90);
$erreur90 !== null && in_array($suivant90, $fins90, true) && !in_array($tete90, $fins90, true) && $suivi90 === 0
    && $remonte90 === 5 && $tenu90[0] && in_array('journal_en_panne', $tenu90[1], true)
    ? ok('⭐ le balayage passe le compte que le journal refuse, le signale, inscrit les suivants, et le dit à la fin')
    : nok('⭐ un compte refusé par le journal arrête le balayage : ' . json_encode([
        'erreur' => $erreur90, 'fins' => $fins90, 'tete' => $tenu90, 'suivant' => $suivi90, 'convalescent' => $remonte90,
    ], JSON_UNESCAPED_UNICODE));

// ── 91. ⭐ Un journal qui rappelle le moteur est une erreur, pas une panne ───
// L'implémentation est appelée au milieu d'une écriture du moteur, où une lecture
// par le moteur est refusée. Ce refus remonte tel quel : rangé en panne du
// journal, il tiendrait le compte hors du vote et accuserait un journal qui
// fonctionne.
$rappel91 = membre($pdo, 'rappel-91');
Moderate::ensureRow($pdo, $rappel91);
$pdo->prepare("UPDATE member_moderation
                  SET reputation = 12, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                      ban_origine = 'admin', ban_motif = 'motif d''arbitre'
                WHERE account_id = ?")->execute([time() - 10, time() - 130, $rappel91]);
Moderate::setJournal(new class ($pdo) implements \Pierroons\SelfModerate\Journal {
    public function __construct(private PDO $pdo) {}

    public function inscrire(array $acte): void
    {
        Moderate::getReputation($this->pdo, (int) $acte['compte']);
    }
});
$leve91 = null;
try {
    Moderate::applyVote($pdo, $rappel91, 'member', membre($pdo, 'vise-91'), 1);
} catch (LogicException $e91) {
    $leve91 = $e91->getMessage();
} finally {
    Moderate::setJournal($journalC);
}
$panne91 = in_array('journal_en_panne', causes($pdo, $rappel91), true);
rattraper($pdo, $rappel91);
$leve91 !== null && !$panne91
    ? ok('⭐ un journal qui rappelle le moteur fait remonter l\'erreur, sans poser de panne du journal')
    : nok('⭐ un journal qui rappelle le moteur passe pour une panne : ' . json_encode(['exception' => $leve91, 'panne' => $panne91], JSON_UNESCAPED_UNICODE));

// ── 92. ⭐ Les seuils neufs refusent une valeur qui les vide de sens ─────────
// `LIMIT -1` se lit « sans limite » : un filet réglé à -1 balaierait tout le site
// dans la requête d'un votant. Un signal au zéroième ban d'arbitre partirait sur
// un compte qui n'en a aucun. Le filet à 0 s'éteint.
$refuses92 = [];
foreach (['balayageFiletMax' => -1, 'bansArbitreSignal' => 0] as $champ92 => $valeur92) {
    try {
        new Config(...[$champ92 => $valeur92]);
    } catch (InvalidArgumentException) {
        $refuses92[] = $champ92;
    }
}
$eteint92 = (new Config(balayageFiletMax: 0))->balayageFiletMax === 0;
$refuses92 === ['balayageFiletMax', 'bansArbitreSignal'] && $eteint92
    ? ok('⭐ un filet négatif et un signal au zéroième ban d\'arbitre sont refusés ; un filet à 0 s\'éteint')
    : nok('⭐ un seuil neuf accepte une valeur qui le vide de sens : ' . json_encode(['refuses' => $refuses92, 'eteint' => $eteint92]));

// ── 93. ⭐ Les seuils neufs suivent la config ─────────────────────────────────
// Comme les autres seuils du n° 25 : réglés autrement que par défaut, leur borne
// visée à la valeur réglée.
$enchaine93 = membre($pdo, 'enchaine-93');
poserReputation($pdo, $enchaine93, 20);
$signal93 = sousConfig(new Config(bansArbitreSignal: 3), static function () use ($pdo, $enchaine93): array {
    $vus = [];
    for ($i = 1; $i <= 3; $i++) {
        Moderate::adminBan($pdo, $enchaine93, 'arbitre-banc', motif());
        $vus[$i] = in_array('bans_arbitre', causes($pdo, $enchaine93), true);
        vieillirBan($pdo, $enchaine93, 100 * Moderate::config()->dureeBanAdmin());
    }
    return $vus;
});
rattraper($pdo, $enchaine93);
$signal93 === [1 => false, 2 => false, 3 => true]
    ? ok('le signal des bans d\'arbitre répétés part au nombre de la config')
    : nok('seuil sourd à la config (bansArbitreSignal) : ' . json_encode($signal93));

$oublies93 = [membre($pdo, 'oublie-a-93'), membre($pdo, 'oublie-b-93')];
foreach ($oublies93 as $k93 => $oublie93) {
    Moderate::ensureRow($pdo, $oublie93);
    $pdo->prepare("UPDATE member_moderation
                      SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                          ban_origine = 'auto', ban_motif = 'reputation_zero'
                    WHERE account_id = ?")->execute([time() - 40_000_000 + $k93, time() - 40_000_120 + $k93, $oublie93]);
    planterSignal($pdo, $oublie93, 'ban_auto');
}
$pdo->exec('DELETE FROM mod_sweeps');
sousConfig(new Config(balayageFiletMax: 1), static fn (): array =>
    Moderate::applyVote($pdo, membre($pdo, 'votant-93'), 'member', membre($pdo, 'vise-93'), -1, motif(), 'agressif'));
$inscrits93 = array_map(static fn (int $id): bool => (int) etatBan($pdo, $id)['banned_until'] === 0, $oublies93);
foreach ($oublies93 as $oublie93) {
    rattraper($pdo, $oublie93);
}
$inscrits93 === [true, false]
    ? ok('le filet inscrit au plus le nombre de comptes de la config, les plus anciens d\'abord')
    : nok('seuil sourd à la config (balayageFiletMax) : ' . json_encode($inscrits93));

// ── Fin 1. ⭐ En fin de banc : un état et son signal vont ensemble, la case dit la table ─
// Une transition qui oublierait de lever son signal le laisserait orphelin ; un
// ban automatique en cours sans son signal échapperait à l'arbitre, comme une
// réputation au plancher hors peine sans le sien ; une case qui ne suit plus la
// table tromperait qui lit la base sans le moteur. Tout ce que le banc a joué
// jusqu'ici est relu.
$invFin = invariantsSignaux($pdo);
$invFin === ['orphelins' => [], 'bans_sans_signal' => [], 'chutes_sans_signal' => [], 'cases' => []]
    ? ok('⭐ en fin de banc, chaque signal d\'état a son état, chaque ban automatique son signal, et chaque case dit sa table')
    : nok('⭐ un état et son signal se sont séparés, ou la case ment : ' . json_encode([
        'orphelins' => $invFin['orphelins'], 'bans_sans_signal' => $invFin['bans_sans_signal'],
        'chutes_sans_signal' => array_slice($invFin['chutes_sans_signal'], 0, 5),
        'cases' => array_slice($invFin['cases'], 0, 5), 'nb_cases' => count($invFin['cases']),
    ], JSON_UNESCAPED_UNICODE));

// ── Fin 2. ⭐ Toute valeur d'un acte est scalaire ───────────────────────────────
// Un journal qui range une colonne par clé — la forme que `Journal.php` nomme —
// rangeait `Array` à la place d'une liste, avec un simple avertissement. Les
// listes partent en texte JSON ; tous les actes que le banc a fait inscrire sont
// relus.
$nonScalairesFin = [];
foreach ([...$journal->actes, ...$journalC->actes] as $acteFin) {
    foreach ($acteFin as $cleFin => $valeurFin) {
        if ($valeurFin !== null && !is_scalar($valeurFin)) {
            $nonScalairesFin[] = ($acteFin['acte'] ?? '?') . '.' . $cleFin;
        }
    }
}
$nonScalairesFin === [] && count($journalC->actes) > 0
    ? ok('⭐ aucune valeur d\'acte n\'est une liste : un journal clé par clé n\'a rien à sérialiser')
    : nok('⭐ un acte porte une valeur non scalaire : ' . json_encode(array_values(array_unique($nonScalairesFin))));

Moderate::setJournal($journalAvantC);

$total = $reussites + $echecs;
echo "\n" . ($echecs === 0
    ? "✅ $total/$total contrôles passés\n"
    : "❌ $echecs échec(s) sur $total\n");
exit($echecs === 0 ? 0 : 1);
