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
$total  = 8;
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
    $pdo->prepare('UPDATE member_moderation SET reputation = ?, voting_rights = 1, needs_review = 0 WHERE account_id = ?')
        ->execute([$valeur, $id]);
}

// ── 1. Un downvote retire un point à l'auteur du message ────────────────────
$cible  = membre($pdo, 'cible');
$votant = membre($pdo, 'votant');
$post   = message($pdo, $cible, 'fil-1');

$r = Moderate::applyVote($pdo, $votant, 'post', $post, -1);
$r['ok'] && reputation($pdo, $cible) === Moderate::INITIAL_REPUTATION - 1
    ? ok('un downvote fait passer la réputation de 20 à ' . reputation($pdo, $cible))
    : nok('le downvote n\'a pas été appliqué : ' . json_encode($r));

// ── 2. On ne vote qu'une fois sur la même cible ─────────────────────────────
// Protégé à deux niveaux : la garde de `applyVote` rend le message, et
// `idx_modvotes_unique` est le filet. Retirer la garde ne rend pas le double
// vote possible — la base lève une violation de contrainte. Un contrôle qui
// n'exerçait que la garde applicative laisserait croire qu'elle est seule.
$r = Moderate::applyVote($pdo, $votant, 'post', $post, -1);
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
Moderate::applyVote($pdo, membre($pdo, 'passant'), 'post', $p, -1);
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
    $dernier = Moderate::applyVote($pdo, $harceleur, 'post', message($pdo, $harcele, "fil-h$i"), -1);
}
!empty($dernier['blocked'])
    && reputation($pdo, $harcele) === $avant - Moderate::FARMING_MAX_DOWNVOTES
    ? ok('le ' . (Moderate::FARMING_MAX_DOWNVOTES + 1) . 'e downvote du même membre est neutralisé (anti slow-drip)')
    : nok('le slow-drip est passé : ' . json_encode($dernier) . ' rep=' . reputation($pdo, $harcele));

// ── 6. 🔑 Trois votants SANS AUCUN LIEN sont classés pack coordonné ──────────
// Les trois n'ont échangé aucun message privé et ne partagent aucun fil. Dans
// le modèle décrit par le README, ils ne devraient pas former un pack. Ici ils
// en forment un, parce que seul l'écart de temps est regardé.
$fache = membre($pdo, 'fache');
$post6 = message($pdo, $fache, 'fil-litigieux');
poserReputation($pdo, $fache, 20);
$inconnus = [membre($pdo, 'inconnu-a'), membre($pdo, 'inconnu-b'), membre($pdo, 'inconnu-c')];
foreach ($inconnus as $v) {
    Moderate::applyVote($pdo, $v, 'member', $fache, -1);
}
$liens = (int) $pdo->query(
    'SELECT COUNT(*) FROM dm WHERE sender_id IN (' . implode(',', $inconnus) . ')
        OR recipient_id IN (' . implode(',', $inconnus) . ')'
)->fetchColumn();
$bloques = (int) $pdo->query(
    'SELECT COUNT(*) FROM mod_votes WHERE target_author = ' . $fache . " AND blocked_reason = 'pack_voting'"
)->fetchColumn();
$liens === 0 && $bloques === 3 && reputation($pdo, $fache) === 20
    ? ok("trois votants sans aucun lien ($liens échange) sont classés pack : $bloques votes annulés, réputation restituée — DÉFAUT CONNU, le recoupement n'est pas implémenté")
    : nok("comportement du pack inattendu : liens=$liens bloqués=$bloques rep=" . reputation($pdo, $fache));

// ── 7. La fenêtre glissante épargne ce qui est hors du cluster ──────────────
// Un downvote isolé, très antérieur, ne doit pas être emporté par l'annulation
// du cluster dense qui suit.
$vieux = membre($pdo, 'vieux-grief');
$cible7 = membre($pdo, 'cible7');
poserReputation($pdo, $cible7, 20);
Moderate::applyVote($pdo, $vieux, 'member', $cible7, -1);
$pdo->exec('UPDATE mod_votes SET created_at = ' . (time() - 10 * Moderate::PACK_WINDOW_SECONDS)
         . ' WHERE voter_id = ' . $vieux . ' AND target_author = ' . $cible7);
foreach ([membre($pdo, 'grp-a'), membre($pdo, 'grp-b'), membre($pdo, 'grp-c')] as $v) {
    Moderate::applyVote($pdo, $v, 'member', $cible7, -1);
}
$vieuxBloque = (int) $pdo->query(
    'SELECT blocked FROM mod_votes WHERE voter_id = ' . $vieux . ' AND target_author = ' . $cible7
)->fetchColumn();
$vieuxBloque === 0
    ? ok('le downvote hors fenêtre survit à l\'annulation du cluster')
    : nok('un vote hors fenêtre a été emporté par l\'annulation du cluster');

// ── 8. R10-LAB-01 — à 0, le lab flagge pour revue humaine et ne bannit pas ──
// L'écart avec le whitepaper est délibéré et commenté dans le moteur : un
// bannissement automatique serait un vecteur d'escalade sans droits admin.
$zero = membre($pdo, 'a-zero');
$p8   = message($pdo, $zero, 'fil-zero');
poserReputation($pdo, $zero, 1);
Moderate::applyVote($pdo, membre($pdo, 'dernier-vote'), 'post', $p8, -1);
$rep = Moderate::getReputation($pdo, $zero);
$flag = (int) $pdo->query('SELECT needs_review FROM member_moderation WHERE account_id = ' . $zero)->fetchColumn();
$rep['reputation'] === 0 && $flag === 1 && !$rep['banned']
    ? ok('à 0, le compte est marqué pour revue humaine sans bannissement automatique')
    : nok('sanction inattendue à 0 : ' . json_encode($rep) . " needs_review=$flag");

echo "\n" . ($echecs === 0
    ? "✅ $total/$total contrôles passés\n"
    : "❌ $echecs échec(s) sur $total\n");
exit($echecs === 0 ? 0 : 1);
