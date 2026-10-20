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
require __DIR__ . '/instance_isolee.php';

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
// Le banc range de faux messages privés en SQL, avant tout chiffrement : sa clé se
// pose donc d'abord, sur une base qui n'en porte encore aucun. Après, la garde
// refuserait à raison de la créer sous des messages qui l'attendent.
require_once __DIR__ . '/../lib/dataguard.php';
\Pierroons\MySelfLab\DataGuard::exigerCle();

register_shutdown_function(static function () use ($dir): void {
    foreach (glob("$dir/*") ?: [] as $f) {
        unlink($f);
    }
    @rmdir($dir);
});

$pdo = Db::pdo();

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
// contenu : il reste vrai si le libellé change.
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
// et elle tourne à chaque vote négatif. Sans garde, un tiers remplaçait donc le
// motif d'un autre par le plus bénin de la liste — et comme les deux chemins qui
// lèvent un signalement tout seuls n'acceptent que `reputation_zero` ou
// `ban_auto`, le motif écrasé ne se levait PLUS jamais sans geste d'arbitre.
//
// ⚠️ Les DEUX moitiés comptent, et la seconde est le témoin : un cas qui vérifie
// seulement « le motif grave a survécu » passe aussi bien quand la salve ne s'est
// pas déclenchée du tout. C'est ce témoin qui a révélé que le premier montage de
// ce cas ne déclenchait rien.
$grave = membre($pdo, 'deja_signale');
poserReputation($pdo, $grave, 20);
$pdo->prepare("UPDATE member_moderation SET needs_review = 1, review_reason = 'meute_recidive',
                   updated_at = ? WHERE account_id = ?")->execute([time(), $grave]);
foreach (['sv-a', 'sv-b', 'sv-c'] as $n) {
    Moderate::applyVote($pdo, membre($pdo, $n), 'member', $grave, -1, motif(), 'agressif');
}
$etatGrave = Moderate::getReputation($pdo, $grave);
$etatGrave['reputation'] === 17 && $etatGrave['review_reason'] === 'meute_recidive'
    ? ok('une salve de trois votants ne remplace pas le motif « meute_recidive » déjà posé')
    : nok('motif écrasé ou salve non déclenchée : ' . json_encode($etatGrave));

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

$lent = membre($pdo, 'convalescent-config');
poserReputation($pdo, $lent, 2);
$pdo->prepare('UPDATE member_moderation SET convalescent = 1, last_regen_at = ? WHERE account_id = ?')
    ->execute([time() - 600, $lent]);
Moderate::setConfig(new Config(intervalleConvalescenceSecondes: 60));
$remonte = reputation($pdo, $lent);
Moderate::setConfig(null);
$remonte === 12
    ? ok('la convalescence suit l\'intervalle de la config (+10 en 10 intervalles)')
    : nok('convalescence sourde à la config : ' . $remonte . ' au lieu de 12');

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

// ── 31. Le maintien clôt la revue sans lever la peine ───────────────────────
Moderate::adminBan($pdo, $cible, 'arbitre-banc', motif());
$pdo->prepare("UPDATE member_moderation SET needs_review = 1, review_reason = 'salve_rapide' WHERE account_id = ?")->execute([$cible]);
$m = Moderate::adminMaintenir($pdo, $cible, 'arbitre-banc', motif());
$e = Moderate::getReputation($pdo, $cible);
$m['ok'] && $e['banned'] && !$e['needs_review'] && count($journal->de($cible, 'ban_maintenu')) === 1
    && !Moderate::adminMaintenir($pdo, $banni, 'arbitre-banc', motif())['ok']
    ? ok('le maintien clôt la revue, la peine court, et le journal le dit')
    : nok('maintien : ' . json_encode([$m, $e]));

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
$pdo->prepare("UPDATE member_moderation SET needs_review = 1, review_reason = 'votant_etendu' WHERE account_id = ?")->execute([$classe]);
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
// Une peine échue se referme à la lecture, en écrivant d'abord au journal.
// Journal en panne : la lecture contient le refus et ne tombe pas ; la peine
// reste en base à refermer, l'arbitre est prévenu (`journal_en_panne`), et la
// clôture suit le retour du journal. Le planificateur, lui, laisse remonter
// l'exception.
Moderate::setJournal($journal);
$echu = membre($pdo, 'peine-echue-journal-mort');
poserReputation($pdo, $echu, 1);
Moderate::applyVote($pdo, membre($pdo, 'votant-echu'), 'member', $echu, -1, motif(), 'agressif');
vieillirBan($pdo, $echu, 10_000);
Moderate::setJournal(new JournalEnPanne());
$leveLecture = null;
$pendantPanne = null;
try {
    $pendantPanne = Moderate::getReputation($pdo, $echu);
    Moderate::canVote($pdo, $echu);
} catch (RuntimeException $ex) {
    $leveLecture = $ex->getMessage();
}
$enBasePanne = (int) etatBan($pdo, $echu)['banned_until'];
$leveBalayage = null;
try {
    Moderate::balayerBansEchus($pdo);
} catch (RuntimeException $ex) {
    $leveBalayage = $ex->getMessage();
}
Moderate::setJournal($journal);
$apresPanne = Moderate::getReputation($pdo, $echu);
$leveLecture === null && $pendantPanne !== null && !$pendantPanne['banned']
    && $pendantPanne['review_reason'] === 'journal_en_panne' && $enBasePanne > 0 && $leveBalayage !== null
    && (int) etatBan($pdo, $echu)['banned_until'] === 0 && count($journal->de($echu, 'ban_fin')) === 1
    && $apresPanne['review_reason'] !== 'journal_en_panne'
    ? ok('⭐ journal en panne : la lecture tient, la peine échue attend sa trace, l\'arbitre est prévenu, et la clôture suit le retour du journal')
    : nok('⭐ un journal en panne fait tomber la lecture ou referme sans trace : '
        . json_encode([$leveLecture, $pendantPanne['review_reason'] ?? null, $enBasePanne, $leveBalayage, etatBan($pdo, $echu)['banned_until']]));
Moderate::setJournal(null);

// ── 34ter. ⭐ Pendant une panne, un vote passe, et une salve survit à la clôture ─
// Un compte dont la peine attend sa clôture reste à zéro : un vote négatif qui
// tenterait de le rebannir ferait tomber la requête. Et un signal de panne tenu
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
// L'hôte ouvre la sienne, écrit son propre travail, vote ; le vote échoue (le
// journal refuse le début du ban), l'hôte attrape et valide son travail. Son
// travail reste, le vote partiel non : ni vote, ni point retiré, ni ban. Sans
// point de reprise, la cible resterait à zéro, sans ban ni trace.
$journalAvant48 = Moderate::journal();
Moderate::setJournal(new class implements \Pierroons\SelfModerate\Journal {
    public function inscrire(array $acte): void
    {
        if ($acte['acte'] === 'ban_debut') {
            throw new RuntimeException('journal fermé');
        }
    }
});
$hote48  = membre($pdo, 'hote-48');
$cible48 = membre($pdo, 'cible-48');
$post48  = message($pdo, $cible48, 'fil-48');
poserReputation($pdo, $cible48, 1);
$pdo->beginTransaction();
$travail48 = message($pdo, $hote48, 'travail-48');
$remonte48 = false;
try {
    Moderate::applyVote($pdo, $hote48, 'post', $post48, -1, motif(), 'agressif');
} catch (RuntimeException $e) {
    $remonte48 = $e->getMessage() === 'journal fermé';
}
$encore48 = $pdo->inTransaction();
if ($encore48) {
    $pdo->commit();
}
Moderate::setJournal($journalAvant48);
$votes48   = (int) $pdo->query("SELECT COUNT(*) FROM mod_votes WHERE voter_id = $hote48")->fetchColumn();
$rep48     = (int) $pdo->query("SELECT reputation FROM member_moderation WHERE account_id = $cible48")->fetchColumn();
$travail48 = (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE id = $travail48")->fetchColumn();
$remonte48 && $encore48 && $travail48 === 1 && $votes48 === 0 && $rep48 === 1
    ? ok('dans la transaction de l\'hôte, un vote qui échoue se défait seul : le travail de l\'hôte reste, le vote partiel non')
    : nok('le vote partiel a survécu dans la transaction de l\'hôte : ' . json_encode([
        'remonte' => $remonte48, 'encore_ouverte' => $encore48, 'travail' => $travail48, 'votes' => $votes48, 'reputation' => $rep48,
    ]));

// ── 49. Un lecteur qui ne peut pas prendre le verrou n'écrit rien au journal ─
// Une peine échue se referme sous le verrou d'écriture, pris d'emblée, et
// l'état se relit sous lui : un lecteur qui attend puis échoue n'a rien inscrit.
// Sans cela, chaque lecteur écrit `ban_fin`, puis tous sauf un échouent en
// voulant promouvoir leur verrou de lecture — N fins pour une peine, et la page
// qui lit le compte tombe. Deux connexions : A tient le verrou d'écriture, B lit.
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
                      ban_origine = 'auto', ban_motif = 'reputation_zero', needs_review = 1, review_reason = 'ban_auto'
                WHERE account_id = ?")->execute([time() - 10, time() - 130, $echu49]);
$a49 = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
$a49->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$b49 = new PDO('sqlite:' . getenv('LAB_DB_PATH'), null, null, [PDO::ATTR_TIMEOUT => 1]);
$b49->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$b49->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$a49->exec('BEGIN IMMEDIATE');
$a49->exec('UPDATE member_moderation SET updated_at = updated_at WHERE account_id = ' . $echu49);
$refuse49 = false;
try {
    Moderate::getReputation($b49, $echu49);
} catch (PDOException $e) {
    $refuse49 = true;
}
$pendantLeVerrou49 = count($journal49->actes);
$a49->exec('ROLLBACK');
$apres49 = Moderate::getReputation($b49, $echu49);
Moderate::setJournal($journalAvant49);
$refuse49 && $pendantLeVerrou49 === 0 && $journal49->actes === ['ban_fin'] && !$apres49['banned'] && $apres49['reputation'] === 20
    ? ok('un lecteur qui ne peut pas prendre le verrou n\'écrit rien au journal ; le verrou rendu, la peine se referme une fois')
    : nok('une fin de peine s\'inscrit avant que le verrou soit pris : ' . json_encode([
        'refuse' => $refuse49, 'pendant_le_verrou' => $pendantLeVerrou49, 'actes' => $journal49->actes, 'apres' => $apres49,
    ]));

$total = $reussites + $echecs;
echo "\n" . ($echecs === 0
    ? "✅ $total/$total contrôles passés\n"
    : "❌ $echecs échec(s) sur $total\n");
exit($echecs === 0 ? 0 : 1);
