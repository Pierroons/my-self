#!/usr/bin/env php
<?php
/**
 * Contrôles du moteur de modération du lab.
 *
 * Chacun a été vu rougir avant d'être écrit : le mécanisme correspondant a été
 * neutralisé, la mesure refaite, puis le code restauré. Un contrôle qu'on n'a
 * jamais fait échouer ne se distingue pas d'un contrôle qui ne mesure rien.
 *
 * 🔑 Le contrôle n° 6 est le plus important, et il ne valide rien : il CONSTATE
 * que trois membres sans le moindre lien entre eux — aucun message privé
 * échangé, aucun fil en commun — sont classés pack coordonné du seul fait
 * qu'ils ont voté dans la même minute. Le README promet un recoupement des
 * votants liés ; il n'existe pas ici, la détection est purement temporelle.
 *
 * Ce contrôle est donc écrit à l'envers des autres : il passe au vert sur le
 * défaut. Le jour où le recoupement sera implémenté, il DOIT basculer — trois
 * votants sans lien ne devront plus déclencher de pack — et c'est en le voyant
 * rougir qu'on saura que la réparation mesure quelque chose. Le réparer sans
 * toucher au moteur reviendrait à effacer la seule trace écrite du trou.
 *
 * Usage : php tests/sanity_moderate.php
 */

declare(strict_types=1);

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
 * Recule tout ce qui retient un votant : ses épisodes sortent du cooldown, et
 * sa suspension expire. Sans ça, un votant suspendu ne peut plus voter — c'est
 * précisément l'effet recherché, mais il empêche d'éprouver le palier suivant.
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
        'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, reason_code, created_at)
         VALUES (?, ?, ?, ?, -1, ?, ?, ?)'
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

// ── 5. R10 — le harcèlement d'un seul votant est neutralisé ─────────────────
// Quatre downvotes du même membre vers le même auteur, sur quatre messages
// différents pour contourner la garde du double vote. Le quatrième dépasse
// FARMING_MAX_DOWNVOTES et doit être enregistré bloqué, sans effet sur le score.
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
// Le contrôle historique de ce dépôt : il constatait qu'ils en formaient une, et
// que leurs votes étaient annulés. C'était le défaut central du moteur — plus un
// message choquait de monde à la fois, mieux son auteur était protégé.
// Désormais leurs votes tiennent, et la cible part en revue humaine.
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

// ── 8. R10-LAB-01 — à 0, revue humaine, aucun bannissement automatique ──────
// L'écart avec le whitepaper est délibéré et commenté dans le moteur : un
// bannissement automatique serait un vecteur d'escalade sans droits admin.
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
// change, et il suffit à déclencher l'annulation.
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
$bloques9 === Moderate::config()->meuteLiensMin && reputation($pdo, $vise) === 20
    ? ok("deux votants qui se sont écrit voient leurs $bloques9 votes annulés, réputation restituée")
    : nok('la meute n\'a pas été détectée : bloqués=' . $bloques9 . ' rep=' . reputation($pdo, $vise));

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
    $pdo->prepare('INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, created_at)
                   VALUES (?, ?, ?, ?, -1, ?, ?)')
        ->execute([$v, 'member', $viseTrio, $viseTrio, motif(), time()]);
}
Moderate::detectPackVoting($pdo);
$bloques11 = (int) $pdo->query(
    'SELECT COUNT(*) FROM mod_votes WHERE target_author = ' . $viseTrio . " AND blocked_reason = 'pack_voting'"
)->fetchColumn();
!Moderate::areLinked($pdo, $trio[0], $trio[2]) && $bloques11 === 3 && reputation($pdo, $viseTrio) === 20
    ? ok('A–B et B–C liés annulent les trois votes, même si A et C ne se connaissent pas')
    : nok('la transitivité n\'a pas joué : bloqués=' . $bloques11 . ' rep=' . reputation($pdo, $viseTrio));

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

// ── 17. Premier épisode : les votes tombent, les votants ne paient rien ─────
// Le critère de meute est le message privé réciproque : deux amis qui réagissent
// de bonne foi au même message pénible le remplissent. Annuler leurs votes se
// défait et protège la victime ; leur retirer le droit de vote, non. Le premier
// épisode ne coûte donc que ses votes.
$a = membre($pdo, 'meute-a');
$b = membre($pdo, 'meute-b');
$c1 = episodeMeute($pdo, [$a, $b], 'victime-1');

$rangA = Moderate::rangDe($pdo, $a);
$modA  = Moderate::getReputation($pdo, $a);
[$peutVoter, ] = Moderate::canVote($pdo, $a);
$rangA === 1 && $modA['vote_muted_until'] === 0 && $peutVoter
    && reputation($pdo, $c1) === Moderate::config()->reputationInitiale
    ? ok('premier épisode : rang 1, aucune peine, droit de vote intact, victime restaurée')
    : nok('premier épisode mal traité : rang=' . $rangA . ' ' . json_encode($modA) . ' vote=' . var_export($peutVoter, true));

// ── 18. Deuxième épisode : le droit de vote est suspendu, avec une échéance ──
vieillirSanctions($pdo, [$a, $b], 2 * 86400);
$c2 = episodeMeute($pdo, [$a, $b], 'victime-2');

$modA = Moderate::getReputation($pdo, $a);
[$peutVoter, $raison] = Moderate::canVote($pdo, $a);
$attendu = time() + Moderate::config()->meuteMute2;
Moderate::rangDe($pdo, $a) === 2
    && abs($modA['vote_muted_until'] - $attendu) <= 5
    && !$peutVoter && str_contains($raison, date('d/m/Y', $modA['vote_muted_until']))
    ? ok('deuxième épisode : vote suspendu 7 jours, le refus porte la date du ' . date('d/m/Y', $modA['vote_muted_until']))
    : nok('suspension absente ou muette : ' . json_encode($modA) . ' raison=' . $raison);

// ── 19. Troisième épisode : 30 jours et cinq points ─────────────────────────
vieillirSanctions($pdo, [$a, $b], 8 * 86400);
$repAvant = reputation($pdo, $a);
$c3 = episodeMeute($pdo, [$a, $b], 'victime-3');

$modA = Moderate::getReputation($pdo, $a);
$attendu = time() + Moderate::config()->meuteMute3;
// 5 en clair, et non Moderate::config()->meutePenalite3 : un contrôle qui calcule son
// attendu depuis la constante qu'il mesure se décale avec elle. Mise à 0, la
// pénalité disparaissait sans que rien ne rougisse — mesuré, pas supposé.
Moderate::rangDe($pdo, $a) === 3
    && abs($modA['vote_muted_until'] - $attendu) <= 5
    && $modA['reputation'] === $repAvant - 5
    ? ok('troisième épisode : 30 jours de suspension et 5 points de moins (' . $repAvant . ' → ' . $modA['reputation'] . ')')
    : nok('palier 3 mal appliqué : ' . json_encode($modA) . ' avant=' . $repAvant);

// ── 20. Quatrième épisode : la main passe à l'humain, aucun bannissement ────
// R10-LAB-01 vaut aussi pour l'agresseur récidiviste : exclure reste un geste
// humain, y compris quand il l'a bien cherché.
vieillirSanctions($pdo, [$a, $b], 31 * 86400);
$c4 = episodeMeute($pdo, [$a, $b], 'victime-4');

$modA = Moderate::getReputation($pdo, $a);
Moderate::rangDe($pdo, $a) === 4 && $modA['needs_review']
    && $modA['review_reason'] === 'meute_recidive' && $modA['banned_until'] === 0
    ? ok('quatrième épisode : revue humaine (meute_recidive), aucun bannissement automatique')
    : nok('palier 4 mal appliqué : ' . json_encode($modA));

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

// ── 22. 🔑 La suspension survit à la remontée de réputation ─────────────────
// La réputation dit ce qu'on vaut, la suspension ce qu'on a fait. Si la peine
// tenait dans voting_rights, la convalescence la rendrait au bout de quelques
// jours calmes — c'est pour ça qu'elle vit dans sa propre colonne.
$pdo->prepare(
    'UPDATE member_moderation SET reputation = 2, convalescent = 1, last_regen_at = ? WHERE account_id = ?'
)->execute([time() - 10 * 86400, $a]);
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

// ── 24. La victime est protégée à tous les paliers ──────────────────────────
// La sanction du votant est venue après l'annulation, et n'y a rien changé.
$restaurees = array_map(static fn (int $id): int => reputation($pdo, $id), [$c1, $c2, $c3, $c4]);
count(array_unique($restaurees)) === 1 && $restaurees[0] === Moderate::config()->reputationInitiale
    ? ok('les quatre victimes sont revenues à ' . Moderate::config()->reputationInitiale . ', du premier palier au dernier')
    : nok('une victime n\'a pas été restaurée : ' . implode(', ', $restaurees));

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

// ── 32. La meute détectée lève un ban automatique, jamais un ban d'arbitre ──
$proie = membre($pdo, 'proie-ban-auto');
poserReputation($pdo, $proie, 1);
$m1 = membre($pdo, 'meute-ban-1'); $m2 = membre($pdo, 'meute-ban-2');
echangePrive($pdo, $m1, $m2);
Moderate::applyVote($pdo, $m1, 'member', $proie, -1, motif(), 'agressif');
$banniParMeute = Moderate::estBanni($pdo, $proie);
Moderate::applyVote($pdo, $m2, 'member', $proie, -1, motif(), 'agressif');
$leveM = $journal->de($proie, 'ban_leve');
$banniParMeute && !Moderate::estBanni($pdo, $proie) && count($leveM) === 1
    && $leveM[0]['cause'] === 'meute_detectee' && $leveM[0]['arbitre'] === null
    ? ok('la meute reconnue lève le ban automatique qu\'elle avait provoqué, et la levée s\'inscrit')
    : nok('ban de meute non levé : ' . json_encode([$banniParMeute, $leveM, etatBan($pdo, $proie)]));

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

$total = $reussites + $echecs;
echo "\n" . ($echecs === 0
    ? "✅ $total/$total contrôles passés\n"
    : "❌ $echecs échec(s) sur $total\n");
exit($echecs === 0 ? 0 : 1);
