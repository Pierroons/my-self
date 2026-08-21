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

$echecs = 0;
$total  = 16;
function ok(string $m): void  { echo "  ✓ $m\n"; }
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

// ── 1. Un downvote retire un point à l'auteur du message ────────────────────
$cible  = membre($pdo, 'cible');
$votant = membre($pdo, 'votant');
$post   = message($pdo, $cible, 'fil-1');

$r = Moderate::applyVote($pdo, $votant, 'post', $post, -1, motif(), 'hors_sujet');
$r['ok'] && reputation($pdo, $cible) === Moderate::INITIAL_REPUTATION - 1
    ? ok('un downvote fait passer la réputation de 20 à ' . reputation($pdo, $cible))
    : nok('le downvote n\'a pas été appliqué : ' . json_encode($r));

// ── 2. On ne vote qu'une fois sur la même cible ─────────────────────────────
// Protégé à deux niveaux : la garde de `applyVote` rend le message, et
// `idx_modvotes_unique` est le filet. Retirer la garde ne rend pas le double
// vote possible — la base lève une violation de contrainte. Un contrôle qui
// n'exerçait que la garde applicative laisserait croire qu'elle est seule.
$r = Moderate::applyVote($pdo, $votant, 'post', $post, -1, motif(), 'hors_sujet');
!$r['ok'] && reputation($pdo, $cible) === Moderate::INITIAL_REPUTATION - 1
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
poserReputation($pdo, $chute, Moderate::LOSE_VOTING_AT);   // exactement au seuil
Moderate::applyVote($pdo, membre($pdo, 'passant'), 'post', $p, -1, motif(), 'hors_sujet');
$rep = Moderate::getReputation($pdo, $chute);
$rep['reputation'] === Moderate::LOSE_VOTING_AT - 1 && !$rep['voting_rights']
    ? ok('sous ' . Moderate::LOSE_VOTING_AT . ', le droit de vote est retiré')
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
for ($i = 1; $i <= Moderate::FARMING_MAX_DOWNVOTES + 1; $i++) {
    $dernier = Moderate::applyVote($pdo, $harceleur, 'post', message($pdo, $harcele, "fil-h$i"), -1, motif(), 'hors_sujet');
}
!empty($dernier['blocked'])
    && reputation($pdo, $harcele) === $avant - Moderate::FARMING_MAX_DOWNVOTES
    ? ok('le ' . (Moderate::FARMING_MAX_DOWNVOTES + 1) . 'e downvote du même membre est neutralisé (anti slow-drip)')
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

// ── 7. Un downvote ancien ne complète pas une salve ─────────────────────────
// La salve se mesure sur une fenêtre glissante. Deux votants récents et un vote
// très antérieur ne font pas trois : sans cela, un vote de camouflage suffirait
// à faire signaler n'importe qui.
$isole = membre($pdo, 'isole');
poserReputation($pdo, $isole, 20);
$vieux = membre($pdo, 'vieux-grief');
Moderate::applyVote($pdo, $vieux, 'member', $isole, -1, motif(), 'hors_sujet');
$pdo->exec('UPDATE mod_votes SET created_at = ' . (time() - 10 * Moderate::PACK_WINDOW_SECONDS)
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
$bloques9 === Moderate::MEUTE_MIN_LINKED && reputation($pdo, $vise) === 20
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
    ->execute([time() - 3 * Moderate::REGEN_INTERVAL_SECONDS, $conv]);
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
    ->execute([time() - 40 * Moderate::REGEN_INTERVAL_SECONDS, $conv]);
$etat13 = Moderate::getReputation($pdo, $conv);
$etat13['reputation'] === Moderate::REGEN_EXIT_AT && !$etat13['convalescent']
    ? ok('la remontée s\'arrête à ' . Moderate::REGEN_EXIT_AT . ' et lève la convalescence')
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

echo "\n" . ($echecs === 0
    ? "✅ $total/$total contrôles passés\n"
    : "❌ $echecs échec(s) sur $total\n");
exit($echecs === 0 ? 0 : 1);
