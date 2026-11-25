#!/usr/bin/env php
<?php
/**
 * Contrôles du moteur de modération du lab.
 *
 * Chacun a été vu rougir avant d'être écrit : le mécanisme correspondant a été
 * neutralisé, la mesure refaite, puis le code restauré. Un contrôle qu'on n'a
 * jamais fait échouer ne se distingue pas d'un contrôle qui ne mesure rien.
 *
 * 🔑 Les contrôles 6 et 9 se lisent ensemble : même minute, seul le lien entre
 * les votants change, et aucun vote ne tombe. Trois votants sans aucun lien
 * forment une salve, signalée à l'arbitre ; deux votants qui se sont écrit
 * gardent leurs deux votes. Un lien ne retire rien à personne : il ne compte
 * qu'au quorum des seuils (contrôles 171 et suivants).
 *
 * Le banc tourne sans journal branché jusqu'au contrôle 26 : à réputation
 * nulle, le moteur signale et ne bannit pas ; sous le seuil du vote, il laisse
 * le vote et signale la perte due. Les contrôles du ban automatique et du
 * retrait du vote branchent le leur, et le retirent.
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
use Pierroons\SelfModerate\Messagerie;
use Pierroons\SelfModerate\Usage;
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

/** L'identifiant d'un compte, par son nom : les gestes d'arbitre ne prennent que lui. */
function idDe(PDO $pdo, string $nom): int
{
    $stmt = $pdo->prepare('SELECT id FROM accounts WHERE username = ?');
    $stmt->execute([$nom]);
    $id = $stmt->fetchColumn();
    $stmt->closeCursor();
    if ($id === false) {
        throw new LogicException("banc : aucun compte « $nom »");
    }

    return (int) $id;
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

// Les arbitres du banc sont des comptes : le moteur résout un nom en compte, et
// refuse un nom qui n'en désigne aucun.
foreach (['arbitre-banc', 'arbitre-un', 'arbitre-deux', 'arbitre', 'arbitre-109', 'arbitre-114', 'arbitre-118', 'arbitre-125', 'arbitre-126'] as $nomArbitre) {
    membre($pdo, $nomArbitre);
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

/**
 * La reprise d'un seul compte, comme `migrerSignaux()` la ferait pour lui : sa case
 * ouverte devient son signal. Les autres cases ne sont pas recalées : l'invariant de
 * fin de banc les relit telles que le moteur les a laissées.
 */
function reprendreLeCompte(PDO $pdo, int $id): void
{
    $case = $pdo->prepare('SELECT review_reason FROM member_moderation WHERE account_id = ? AND needs_review = 1');
    $case->execute([$id]);
    $cause = $case->fetchColumn();
    if ($cause !== false) {
        planterSignal($pdo, $id, $cause ?? 'signal_anterieur');
    }
}

/** La case d'un compte, recalée sur ses signaux à voir. */
function recalerCase(PDO $pdo, int $id): void
{
    $pdo->prepare(
        "UPDATE member_moderation
            SET review_reason = COALESCE(
                    (SELECT cause FROM mod_signaux WHERE account_id = :id AND vu_le IS NULL
                        AND cause NOT IN ('reputation_zero', 'ban_auto', 'journal_en_panne', 'levee_en_attente', 'ban_en_attente', 'droit_de_vote_perdu', 'perte_du_vote_due', 'ban_sans_fin', 'chute_sans_quorum') ORDER BY id LIMIT 1),
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
    // Le compteur rend chaque motif unique ; ses chiffres sont séparés, sans quoi
    // « (1111) » ferait refuser le motif comme un caractère répété en rafale.
    return $phrases[$n++ % count($phrases)] . ' (' . implode('.', str_split((string) $n)) . ')';
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

/** Vote inséré directement : sert à constituer un passage de détection en une fois. */
function voteBrut(PDO $pdo, int $votant, int $post, int $auteur): void
{
    $pdo->prepare(
        'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, reason_code, effet, created_at)
         VALUES (?, ?, ?, ?, -1, ?, ?, -1, ?)'
    )->execute([$votant, 'post', $post, $auteur, motif(), 'agressif', time()]);
}

/**
 * La config du banc : une démonstration dont les messages passent par `dm`,
 * quorum aux seuils éteint. La plupart des cas font tomber un compte avec un ou
 * deux votants pour éprouver autre chose — le journal, les peines, l'arbitre ;
 * le quorum a ses propres cas, qui l'allument.
 */
function configBanc(mixed ...$reglages): Config
{
    return new Config(...['messagerie' => Messagerie::DM, 'usage' => Usage::DEMONSTRATION, 'quorumAuxSeuils' => false, ...$reglages]);
}

/** Joue un cas sous une autre config, et rend celle du banc quoi qu'il arrive. */
function sousConfig(Config $config, callable $cas): mixed
{
    Moderate::setConfig($config);
    try {
        return $cas();
    } finally {
        Moderate::setConfig(configBanc());
    }
}

/** Une config de service : les valeurs de `Config::prod()`, les réglages du cas par-dessus. */
function configService(mixed ...$reglages): Config
{
    return new Config(...[...get_object_vars(Config::prod(Messagerie::DM)), ...$reglages]);
}

// ── Le ban tracé : un journal factice, relu par le banc ─────────────────────
final class JournalDeBanc implements Pierroons\SelfModerate\Journal
{
    /** @var list<array<string, mixed>> */
    public array $actes = [];

    public function inscrire(array $acte): void
    {
        // Encodé comme le journal du lab, sans substitution : un acte qu'il
        // refuserait, le banc le refuse aussi.
        json_encode($acte, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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

/**
 * Joue un cas sous un journal neuf qu'il lui passe, et rend le journal d'avant
 * quoi qu'il arrive : en démonstration, le retrait du vote s'inscrit au journal
 * avant la base, comme le ban automatique.
 */
function sousJournal(callable $cas): mixed
{
    $avant = Moderate::journal();
    $journal = new JournalDeBanc();
    Moderate::setJournal($journal);
    try {
        return $cas($journal);
    } finally {
        Moderate::setJournal($avant);
    }
}

Moderate::setConfig(configBanc());

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
// En démonstration, le retrait s'inscrit au journal avant la base : le cas
// branche le sien.
$chute = membre($pdo, 'chute');
$p     = message($pdo, $chute, 'fil-chute');
poserReputation($pdo, $chute, Moderate::config()->perteDroitDeVoteSous);   // exactement au seuil
[$rep, $retraits4] = sousJournal(static function (JournalDeBanc $j) use ($pdo, $chute, $p): array {
    Moderate::applyVote($pdo, membre($pdo, 'passant'), 'post', $p, -1, motif(), 'hors_sujet');

    return [Moderate::getReputation($pdo, $chute), $j->de($chute, 'vote_retire')];
});
$rep['reputation'] === Moderate::config()->perteDroitDeVoteSous - 1 && !$rep['voting_rights'] && count($retraits4) === 1
    ? ok('sous ' . Moderate::config()->perteDroitDeVoteSous . ', le droit de vote est retiré, et le retrait inscrit au journal')
    : nok('droit de vote conservé sous le seuil : ' . json_encode([$rep, 'retraits' => count($retraits4)]));

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

// ── 6. 🔑 Trois votants sans lien forment une salve, et leurs votes tiennent ─
// Trois membres qui ne se sont jamais écrit votent dans la même minute : une
// salve. Leurs votes tiennent, et la cible part en revue humaine.
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
$bloques = (int) $pdo->query('SELECT COUNT(*) FROM mod_votes WHERE blocked = 1 AND target_author = ' . $fache)->fetchColumn();
$etat6 = Moderate::getReputation($pdo, $fache);
$liens === 0 && $bloques === 0
    && $etat6['reputation'] === 17
    && $etat6['needs_review'] && $etat6['review_reason'] === 'salve_rapide'
    ? ok("trois votants sans lien ($liens échange) gardent leurs votes : réputation {$etat6['reputation']}, cible signalée en revue humaine")
    : nok('la salve a fait tomber un vote ou s\'est tue : bloqués=' . $bloques . ' ' . json_encode($etat6));

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

// ── 9. 🔑 Deux votants liés gardent leurs deux votes ─────────────────────────
// Le pendant du contrôle 6 : même minute, mais les deux votants se sont écrit.
// Le lien ne retire rien à aucun des deux — un lien que la cible fabrique ne
// doit rien pouvoir sur les votes d'un tiers.
$vise = membre($pdo, 'vise-par-duo');
poserReputation($pdo, $vise, 20);
$duoA = membre($pdo, 'duo-a');
$duoB = membre($pdo, 'duo-b');
echangePrive($pdo, $duoA, $duoB);
Moderate::applyVote($pdo, $duoA, 'member', $vise, -1, motif(), 'agressif');
Moderate::applyVote($pdo, $duoB, 'member', $vise, -1, motif(), 'agressif');
$bloques9 = (int) $pdo->query('SELECT COUNT(*) FROM mod_votes WHERE blocked = 1 AND target_author = ' . $vise)->fetchColumn();
Moderate::areLinked($pdo, $duoA, $duoB) && $bloques9 === 0 && reputation($pdo, $vise) === 18
    ? ok('deux votants qui se sont écrit gardent leurs deux votes : réputation à 18')
    : nok('un lien a fait tomber un vote : bloqués=' . $bloques9 . ' rep=' . reputation($pdo, $vise));

// ── 10. Un message privé à sens unique ne crée pas de lien ──────────────────
// Sans réciprocité, pas de lien : sinon un spammeur se lierait à tout le monde
// en lui écrivant, et pèserait sur le quorum de quiconque.
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
    ? ok('un message privé sans réponse ne lie pas deux votants')
    : nok('lien reconnu à sens unique : bloqués=' . $bloques10 . ' rep=' . reputation($pdo, $viseSolo));

// ── 10bis. Le quorum ne lit jamais le contenu des messages privés ──────────
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
    $sansContenu = 'paires=' . Moderate::compositionChute($pdo, $viseSansContenu, time())['paires_liees'];
} catch (Throwable $t) {
    $sansContenu = $t->getMessage();
} finally {
    $pdo->exec('DROP VIEW IF EXISTS temp.dm');
    $pdo->exec('DROP TABLE IF EXISTS temp.dm_echanges');
}
$sansContenu === 'paires=1'
    ? ok('le quorum reconnaît le lien sur des messages privés réduits à qui, à qui et quand')
    : nok('le quorum lit le contenu des messages privés : ' . $sansContenu);

// ── 11. Au quorum, un lien ne se propage pas ─────────────────────────────────
// A–B et B–C se connaissent, A et C non. A et C ne se sont jamais écrit : ce sont
// deux voix. Grouper la chaîne en une voix donnerait à B, que la cible peut être,
// le pouvoir de fondre deux inconnus en un.
$viseTrio = membre($pdo, 'vise-par-trio');
poserReputation($pdo, $viseTrio, 17);
$trio = [membre($pdo, 'trio-a'), membre($pdo, 'trio-b'), membre($pdo, 'trio-c')];
echangePrive($pdo, $trio[0], $trio[1]);
echangePrive($pdo, $trio[1], $trio[2]);
foreach ($trio as $v) {
    Moderate::applyVote($pdo, $v, 'member', $viseTrio, -1, motif(), 'agressif');
}
$trio11 = Moderate::compositionChute($pdo, $viseTrio, time());
!Moderate::areLinked($pdo, $trio[0], $trio[2]) && $trio11['paires_liees'] === 2 && $trio11['voix_sans_lien'] === 2
    && reputation($pdo, $viseTrio) === 14
    ? ok('A–B et B–C liés, A et C non : deux voix sans lien, et les trois votes tiennent')
    : nok('le lien s\'est propagé, ou un vote est tombé : ' . json_encode($trio11) . ' rep=' . reputation($pdo, $viseTrio));

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

// ── 22. 🔑 Une suspension de vote survit à la remontée de réputation ────────
// Un arbitre la pose (`adminSuspendreVote()`). La réputation dit ce qu'on vaut,
// la suspension ce qu'on a fait : la convalescence, qui rend le droit de vote,
// ne doit pas la lever.
$a = membre($pdo, 'suspendu-herite');
poserReputation($pdo, $a, 2);
$pdo->prepare(
    'UPDATE member_moderation SET reputation = 2, convalescent = 1, last_regen_at = ?, vote_muted_until = ? WHERE account_id = ?'
)->execute([time() - 10 * Moderate::config()->intervalleConvalescenceSecondes, time() + 7 * 86400, $a]);
$modA = Moderate::getReputation($pdo, $a);      // déclenche la convalescence
[$peutVoter, $raison] = Moderate::canVote($pdo, $a);
$modA['reputation'] === 12 && !$peutVoter && str_contains($raison, 'par un arbitre')
    ? ok('la réputation remonte à ' . $modA['reputation'] . ' et la suspension tient : « ' . $raison . ' »')
    : nok('la remontée a effacé la peine : rep=' . $modA['reputation'] . ' vote=' . var_export($peutVoter, true) . ' ' . $raison);

// ── 24ter. ⭐ Un compte jetable lié au critique ne retire rien à ses votes ──
// La cible ouvre un jetable, écrit au critique et obtient une réponse : les deux
// sont liés. Le jetable vote AVANT le critique à chaque message. Les votes du
// critique portent tous, et lui ne perd ni point ni droit.
$cibleJ   = membre($pdo, 'cible-du-critique');
$critique = membre($pdo, 'critique-honnete');
$jetable  = membre($pdo, 'jetable');
echangePrive($pdo, $jetable, $critique);
for ($k = 1; $k <= 3; $k++) {
    $pique = message($pdo, $cibleJ, "pique-$k");
    Moderate::applyVote($pdo, $jetable, 'post', $pique, -1, motif(), 'agressif');
    Moderate::applyVote($pdo, $critique, 'post', $pique, -1, motif(), 'agressif');
}
[$voteC] = Moderate::canVote($pdo, $critique);
$modC = Moderate::getReputation($pdo, $critique);
$portes24 = (int) $pdo->query("SELECT COUNT(*) FROM mod_votes WHERE voter_id = $critique AND blocked = 0")->fetchColumn();
$voteC && $modC['reputation'] === Moderate::config()->reputationInitiale && $portes24 === 3
    && reputation($pdo, $cibleJ) === Moderate::config()->reputationInitiale - 6
    ? ok('⭐ un jetable lié par une seule réponse ne retire rien au critique : ses trois votes portent')
    : nok('⭐ le lien du jetable a pesé sur le critique : ' . json_encode([$voteC, $modC, $portes24, reputation($pdo, $cibleJ)]));

// ── 25. La config est la seule source des seuils ────────────────────────────
// Deux sources pour un seuil, c'est un seuil que la config ne règle qu'à
// moitié : `Config::prod()` ne changeait que les durées de ban, le moteur
// lisant ses propres constantes pour tout le reste. `SANS_FIN` n'en est pas
// un : c'est l'échéance sentinelle d'un ban sans fin, qu'aucune config ne règle.
// `DUREE_MAX` non plus : c'est la plus longue échéance qui se lise encore comme
// une échéance, et non comme `SANS_FIN`. `VOTE_AU_PLAFOND` et `VOTE_RECU` sont
// des filtres SQL — les votes au plafond et dans la salve, les votes qui ont
// porté —, sans nombre à régler ; `RAISONS_DU_CROISEMENT` et `CAUSES_DUES` sont
// des listes de noms.
$jumelles = array_values(array_filter(
    array_keys((new ReflectionClass(Moteur::class))->getConstants()),
    static fn (string $c): bool => !str_starts_with($c, 'REASON_')
        && !in_array($c, ['SANS_FIN', 'DUREE_MAX', 'VOTE_AU_PLAFOND', 'VOTE_RECU', 'RAISONS_DU_CROISEMENT', 'CAUSES_DUES'], true)
));
$jumelles === []
    ? ok('le moteur ne garde aucune constante de seuil à côté de la config')
    : nok('constantes de seuil encore dans le moteur : ' . implode(', ', $jumelles));

$recent = membre($pdo, 'recent-config');
$pdo->prepare('UPDATE accounts SET created_at = ? WHERE id = ?')->execute([time() - 600, $recent]);
Moderate::setConfig(configBanc(ageMinPourVoterSecondes: 3600));
[$refuse, $attente] = Moderate::canVote($pdo, $recent);
Moderate::setConfig(configBanc());
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
Moderate::setConfig(configBanc(intervalleConvalescenceSecondes: 60));
Moderate::regenerate($pdo, $lent);
$remonte = reputation($pdo, $lent);
Moderate::setConfig(configBanc());
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
    $balaye = sousConfig(configBanc(balayagePeriodeSecondes: 7), static function () use ($pdo): array {
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

$neuf = sousConfig(configBanc(reputationInitiale: 13), static fn (): int => reputation($pdo, membre($pdo, 'neuf-config')));
$neuf === 13
    ? ok('un compte neuf démarre à la réputation de la config')
    : nok("seuil sourd à la config (reputationInitiale) : $neuf au lieu de 13");

$plafonne = membre($pdo, 'plafonne-config');
$auPlafond = sousConfig(configBanc(reputationMax: 21), static function () use ($pdo, $plafonne): array {
    Moderate::applyVote($pdo, membre($pdo, 'pouce-1'), 'member', $plafonne, 1);
    Moderate::applyVote($pdo, membre($pdo, 'pouce-2'), 'member', $plafonne, 1);
    return [reputation($pdo, $plafonne)];
});
$auPlafond === [21]
    ? ok('le plafond de réputation suit la config : un pouce de plus ne monte pas')
    : nok('seuil sourd à la config (reputationMax) : ' . $auPlafond[0] . ' au lieu de 21');

$retire = membre($pdo, 'retire-config');
$sousSeuil = sousJournal(static fn (): array => sousConfig(configBanc(perteDroitDeVoteSous: 9, intervalleConvalescenceSecondes: 60), static function () use ($pdo, $retire): array {
    poserReputation($pdo, $retire, 9);
    Moderate::applyVote($pdo, membre($pdo, 'retire-votant'), 'member', $retire, -1, motif(), 'hors_sujet');
    return Moderate::getReputation($pdo, $retire);
}));
$sousSeuil['reputation'] === 8 && !$sousSeuil['voting_rights']
    ? ok('le droit de vote se perd sous le seuil de la config')
    : nok('seuil sourd à la config (perteDroitDeVoteSous) : ' . json_encode($sousSeuil));

$plancherRep = membre($pdo, 'plancher-config');
$auPlancher = sousConfig(configBanc(banA: 3), static function () use ($pdo, $plancherRep): array {
    poserReputation($pdo, $plancherRep, 4);
    Moderate::applyVote($pdo, membre($pdo, 'plancher-a'), 'member', $plancherRep, -1, motif(), 'agressif');
    Moderate::applyVote($pdo, membre($pdo, 'plancher-b'), 'member', $plancherRep, -1, motif(), 'agressif');
    return Moderate::getReputation($pdo, $plancherRep);
});
$auPlancher['reputation'] === 3 && $auPlancher['review_reason'] === 'reputation_zero'
    ? ok('la réputation s\'arrête au plancher de la config, et le signalement y tombe')
    : nok('seuil sourd à la config (banA) : ' . json_encode($auPlancher));

// Trois votes, les deux premiers 570 ou 630 secondes avant le troisième.
$salveFenetre = sousConfig(configBanc(fenetreSalveSecondes: 600), static function () use ($pdo): array {
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
$etatSalveCourte = sousConfig(configBanc(salveVotantsMin: 2), static function () use ($pdo, $salveCourte): array {
    foreach ([membre($pdo, 'salve-courte-a'), membre($pdo, 'salve-courte-b')] as $v) {
        Moderate::applyVote($pdo, $v, 'member', $salveCourte, -1, motif(), 'agressif');
    }
    return Moderate::getReputation($pdo, $salveCourte);
});
$etatSalveCourte['review_reason'] === 'salve_rapide'
    ? ok('la salve compte ses votants selon la config : deux suffisent quand elle le dit')
    : nok('seuil sourd à la config (salveVotantsMin) : ' . json_encode($etatSalveCourte['review_reason']));

// Le quorum lit deux fenêtres : celle des liens (`fenetreMeuteJours`), l'âge de
// l'échange qui lie ; celle des plafonds (`fenetreFarmingJours`), l'âge des votes
// qui font une voix. Une heure de part et d'autre de trois jours.
$cotes = ['dedans' => 3 * 86400 - 3600, 'dehors' => 3 * 86400 + 3600];
$lienFenetre = sousConfig(configBanc(fenetreMeuteJours: 3), static function () use ($pdo, $cotes): array {
    $paires = [];
    foreach ($cotes as $cote => $age) {
        $cible = membre($pdo, "lien-$cote-config");
        [$x, $y] = [membre($pdo, "lien-$cote-a"), membre($pdo, "lien-$cote-b")];
        foreach ([[$x, $y], [$y, $x]] as [$de, $vers]) {
            $pdo->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$de, $vers, 'chiffre', time() - $age]);
        }
        Moderate::applyVote($pdo, $x, 'member', $cible, -1, motif(), 'agressif');
        Moderate::applyVote($pdo, $y, 'member', $cible, -1, motif(), 'agressif');
        $paires[$cote] = Moderate::compositionChute($pdo, $cible, time())['paires_liees'];
    }
    return $paires;
});
$lienFenetre === ['dedans' => 1, 'dehors' => 0]
    ? ok('un échange lie deux votants tant qu\'il tient dans la fenêtre des liens de la config')
    : nok('seuil sourd à la config (fenetreMeuteJours) : lien ' . json_encode($lienFenetre));

$votesFenetre = sousConfig(configBanc(fenetreFarmingJours: 3), static function () use ($pdo, $cotes): array {
    $voix = [];
    foreach ($cotes as $cote => $age) {
        $cible = membre($pdo, "votes-$cote-config");
        [$x, $y] = [membre($pdo, "votes-$cote-a"), membre($pdo, "votes-$cote-b")];
        Moderate::applyVote($pdo, $x, 'member', $cible, -1, motif(), 'agressif');
        $pdo->exec('UPDATE mod_votes SET created_at = ' . (time() - $age) . ' WHERE voter_id = ' . $x . ' AND target_author = ' . $cible);
        Moderate::applyVote($pdo, $y, 'member', $cible, -1, motif(), 'agressif');
        $voix[$cote] = Moderate::compositionChute($pdo, $cible, time())['voix_sans_lien'];
    }
    return $voix;
});
$votesFenetre === ['dedans' => 2, 'dehors' => 1]
    ? ok('le quorum ne compte que les voix que la fenêtre des plafonds de la config contient')
    : nok('seuil sourd à la config (fenetreFarmingJours) : voix ' . json_encode($votesFenetre));

// Les pouces déjà donnés, une heure de part et d'autre de sept jours.
$farmingFenetre = sousConfig(configBanc(fenetreFarmingJours: 7), static function () use ($pdo): array {
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
$repChoye = sousConfig(configBanc(farmingUpvotesMax: 1), static function () use ($pdo, $choye): int {
    $fan = membre($pdo, 'fan-unique-config');
    Moderate::applyVote($pdo, $fan, 'post', message($pdo, $choye, 'fil-choye-1'), 1);
    Moderate::applyVote($pdo, $fan, 'post', message($pdo, $choye, 'fil-choye-2'), 1);
    return reputation($pdo, $choye);
});
$repChoye === 21
    ? ok('le plafond de pouces par votant suit la config : le second est neutralisé')
    : nok("seuil sourd à la config (farmingUpvotesMax) : réputation $repChoye au lieu de 21");

$use = membre($pdo, 'use-config');
$repUse = sousConfig(configBanc(farmingDownvotesMax: 1), static function () use ($pdo, $use): int {
    $acharne = membre($pdo, 'acharne-config');
    Moderate::applyVote($pdo, $acharne, 'post', message($pdo, $use, 'fil-use-1'), -1, motif(), 'hors_sujet');
    Moderate::applyVote($pdo, $acharne, 'post', message($pdo, $use, 'fil-use-2'), -1, motif(), 'hors_sujet');
    return reputation($pdo, $use);
});
$repUse === 19
    ? ok('le plafond de votes négatifs par votant suit la config : le second est neutralisé')
    : nok("seuil sourd à la config (farmingDownvotesMax) : réputation $repUse au lieu de 19");

$troisieme = sousConfig(configBanc(ciblesParVotantMax: 2), static function () use ($pdo): array {
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

function etatBan(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM member_moderation WHERE account_id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/** Recule une peine dans le temps : début et fin décalés de $secondes. */
function vieillirBan(PDO $pdo, int $id, int $secondes): void
{
    $pdo->prepare('UPDATE member_moderation SET ban_debut = CASE WHEN ban_debut > 0 THEN ban_debut - ? ELSE ban_debut END,
                                                banned_until = banned_until - ? WHERE account_id = ?')
        ->execute([$secondes, $secondes, $id]);
    // L'historique passe le temps avec l'état : la peine ouverte recule d'autant.
    $pdo->prepare('UPDATE mod_peines SET debut = CASE WHEN debut > 0 THEN debut - ? ELSE debut END,
                                         decide_le = CASE WHEN decide_le > 0 THEN decide_le - ? ELSE decide_le END,
                                         echeance_initiale = echeance_initiale - ?, echeance = echeance - ?
                    WHERE account_id = ? AND fin IS NULL')
        ->execute([$secondes, $secondes, $secondes, $secondes, $id]);
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
// La durée inscrite est la peine : le palier, plus moins d'un pas d'arrondi de sa fin.
$peineDuPalier = static fn (array $acte, int $episode): bool =>
    $acte['duree'] === $acte['jusqu_a'] - $acte['debut']
    && $acte['duree'] >= Moderate::config()->dureeBanPourEpisode($episode)
    && $acte['duree'] < Moderate::config()->dureeBanPourEpisode($episode) + Moderate::config()->arrondiFinBanSecondes;
$banni = membre($pdo, 'banni-auto');
poserReputation($pdo, $banni, 1);
$vx = membre($pdo, 'votant-x');
Moderate::applyVote($pdo, $vx, 'member', $banni, -1, motif(), 'agressif');
$e = Moderate::getReputation($pdo, $banni);
$debut = $journal->de($banni, 'ban_debut');
$e['banned'] && $e['ban_origine'] === 'auto' && $e['strikes'] === 1 && count($debut) === 1
    && $debut[0]['origine'] === 'auto' && $debut[0]['motif'] === 'reputation_zero'
    && $peineDuPalier($debut[0], 1)
    ? ok('à zéro, le ban automatique tombe pour ' . Moderate::dureeEnClair(Moderate::config()->dureeBanPourEpisode(1)) . ', et le journal l\'a reçu')
    : nok('ban automatique absent ou non tracé : ' . json_encode([$e, $debut]));

$vy = membre($pdo, 'votant-y');
$finAvant = $e['banned_until'];
// Le moteur refuse aussi d'ouvrir une peine sur une autre : le vote qui le tenterait
// lève, et le cas le nomme au lieu de faire tomber le banc.
try {
    Moderate::applyVote($pdo, $vy, 'member', $banni, -1, motif(), 'agressif');
    $leve26 = null;
} catch (Throwable $e26) {
    $leve26 = get_class($e26) . ' : ' . $e26->getMessage();
}
$leve26 === null && Moderate::getReputation($pdo, $banni)['banned_until'] === $finAvant && count($journal->de($banni, 'ban_debut')) === 1
    ? ok('une peine en cours ne se rallonge pas au vote contraire suivant')
    : nok('la peine a reculé ou s\'est doublée' . ($leve26 !== null ? ' : ' . $leve26 : ''));

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
count($second) === 2 && $second[1]['episode'] === 2 && $peineDuPalier($second[1], 2)
    ? ok('le ban suivant monte d\'un palier : ' . Moderate::dureeEnClair(Moderate::config()->dureeBanPourEpisode(2)))
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
$sansMotif = Moderate::adminBan($pdo, $cible, idDe($pdo, 'arbitre-banc'), 'parce que');
$r = Moderate::adminBan($pdo, $cible, idDe($pdo, 'arbitre-banc'), motif());
$doublon = Moderate::adminBan($pdo, $cible, idDe($pdo, 'arbitre-banc'), motif());
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
Moderate::adminBan($pdo, $cible, idDe($pdo, 'arbitre-banc'), motif());
$refus = Moderate::adminPardon($pdo, $cible, idDe($pdo, 'arbitre-banc'));
$grace = Moderate::adminPardon($pdo, $cible, idDe($pdo, 'arbitre-banc'), motif());
$leve = $journal->de($cible, 'ban_leve');
!$refus['ok'] && str_contains($refus['message'], 'à partir du') && $grace['ok'] && $grace['anticipee']
    && count($leve) === 1 && $leve[0]['anticipee'] === true && $leve[0]['reste'] > 0 && $leve[0]['cause'] === 'grace'
    && !Moderate::estBanni($pdo, $cible)
    ? ok('avant le plancher, la grâce exige un motif et s\'inscrit comme levée anticipée')
    : nok('plancher non tenu : ' . json_encode([$refus, $grace, $leve]));

Moderate::adminBan($pdo, $cible, idDe($pdo, 'arbitre-banc'), motif());
$t30 = time();   // un seul instant pour les deux tables : la peine dit le ban
$pdo->prepare('UPDATE member_moderation SET ban_debut = ?, banned_until = ? WHERE account_id = ?')
    ->execute([$t30 - 100, $t30 + 20, $cible]);   // plancher dépassé, peine encore en cours
$pdo->prepare('UPDATE mod_peines SET debut = ?, decide_le = ?, echeance = ? WHERE account_id = ? AND fin IS NULL')
    ->execute([$t30 - 100, $t30 - 100, $t30 + 20, $cible]);
$libre = Moderate::adminPardon($pdo, $cible, idDe($pdo, 'arbitre-banc'));
$leve = $journal->de($cible, 'ban_leve');
$libre['ok'] && !$libre['anticipee'] && count($leve) === 2 && $leve[1]['anticipee'] === false
    ? ok('après le plancher, la grâce se passe de motif, mais s\'inscrit quand même')
    : nok('grâce après plancher : ' . json_encode([$libre, $leve]));

// ── 30bis. Les durées de peine et le plancher suivent la config ─────────────
// Le pendant du n° 25 pour les seuils que seul un ban réel exerce.
$banCourt = membre($pdo, 'ban-court-config');
// Le pas d'arrondi à 1 laisse la fin exacte : c'est le palier qu'on mesure ici.
$dureeAuto = sousConfig(configBanc(dureesBan: [77], arrondiFinBanSecondes: 1), static function () use ($pdo, $banCourt): int {
    poserReputation($pdo, $banCourt, 1);
    Moderate::applyVote($pdo, membre($pdo, 'ban-court-votant'), 'member', $banCourt, -1, motif(), 'agressif');
    $e = etatBan($pdo, $banCourt);
    return (int) $e['banned_until'] - (int) $e['ban_debut'];
});
$dureeAuto === 77
    ? ok('le ban automatique dure le palier de la config')
    : nok("seuil sourd à la config (dureesBan) : $dureeAuto s au lieu de 77");

$banArbitre = membre($pdo, 'ban-arbitre-config');
$dureeArbitre = sousConfig(configBanc(dureeBanAdminSecondes: 99), static function () use ($pdo, $banArbitre): int {
    Moderate::adminBan($pdo, $banArbitre, idDe($pdo, 'arbitre-banc'), motif());
    $e = etatBan($pdo, $banArbitre);
    return (int) $e['banned_until'] - (int) $e['ban_debut'];
});
$dureeArbitre === 99
    ? ok('le ban d\'arbitre dure ce que dit la config')
    : nok("seuil sourd à la config (dureeBanAdminSecondes) : $dureeArbitre s au lieu de 99");

$sansPlancher = membre($pdo, 'sans-plancher-config');
$graceImmediate = sousConfig(configBanc(plancherFraction: 0.0), static function () use ($pdo, $sansPlancher): array {
    Moderate::adminBan($pdo, $sansPlancher, idDe($pdo, 'arbitre-banc'), motif());
    return Moderate::adminPardon($pdo, $sansPlancher, idDe($pdo, 'arbitre-banc'));
});
($graceImmediate['ok'] ?? false) && !($graceImmediate['anticipee'] ?? true)
    ? ok('sans plancher dans la config, la grâce se passe de motif dès le début de la peine')
    : nok('seuil sourd à la config (plancherFraction) : ' . json_encode($graceImmediate, JSON_UNESCAPED_UNICODE));

// ── 31. Le maintien prend acte d'un ban automatique, et de lui seul ─────────
// Un ban d'arbitre a déjà eu son regard humain : rien à y maintenir. Sur un ban
// automatique, le signal du ban sort de la file — il reste en base, vu, jusqu'à
// la fin de la peine — et la salve qui l'accompagne reste ouverte : elle se
// classe avec son propre motif. Un second maintien n'a plus rien à voir.
Moderate::adminBan($pdo, $cible, idDe($pdo, 'arbitre-banc'), motif());
planterSignal($pdo, $cible, 'salve_rapide');
$surArbitre = Moderate::adminMaintenir($pdo, $cible, idDe($pdo, 'arbitre-banc'), motif());
$auto31 = membre($pdo, 'maintien-auto');
poserReputation($pdo, $auto31, 1);
planterSignal($pdo, $auto31, 'salve_rapide');
Moderate::applyVote($pdo, membre($pdo, 'votant-maintien'), 'member', $auto31, -1, motif(), 'agressif');
$m = Moderate::adminMaintenir($pdo, $auto31, idDe($pdo, 'arbitre-banc'), motif());
$e = Moderate::getReputation($pdo, $auto31);
$acte31 = $journal->de($auto31, 'ban_maintenu');
$vus31 = array_column(array_filter($e['signaux'] ?? [], static fn (array $s): bool => $s['vu_le'] !== null), 'cause');
!$surArbitre['ok'] && $m['ok'] && $e['banned'] && $e['review_reason'] === 'salve_rapide' && $vus31 === ['ban_auto']
    && count($acte31) === 1 && ($acte31[0]['raison'] ?? null) === 'ban_auto'
    && array_column(liste($acte31[0]['ferme'] ?? null) ?? [], 'cause') === ['ban_auto']
    && !Moderate::adminMaintenir($pdo, $auto31, idDe($pdo, 'arbitre-banc'), motif())['ok']
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
$grace = Moderate::adminPardon($pdo, $gracie, idDe($pdo, 'arbitre-banc'), motif());
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
    $sansMotifC = Moderate::adminClasser($pdo, $classe, idDe($pdo, 'arbitre-banc'), 'vu');
    $classeOk   = Moderate::adminClasser($pdo, $classe, idDe($pdo, 'arbitre-banc'), motif());
    $etatC      = Moderate::getReputation($pdo, $classe);
    $traceC     = $journal->de($classe, 'revue_classee');
    !$sansMotifC['ok'] && $classeOk['ok'] && !$etatC['needs_review'] && $etatC['reputation'] === 27
        && count($traceC) === 1 && ($traceC[0]['raison'] ?? null) === 'votant_etendu' && ($traceC[0]['arbitre'] ?? null) === 'arbitre-banc'
        ? ok('⭐ classer un signalement exige un motif, lève le signal seul, et s\'inscrit au journal avec sa cause')
        : nok('⭐ classement mal tenu : ' . json_encode([$sansMotifC, $classeOk, $etatC, $traceC]));
}

// ── 32. Aucun lien ne lève un ban, automatique ou d'arbitre ─────────────────
$proie = membre($pdo, 'proie-ban-auto');
poserReputation($pdo, $proie, 1);
$m1 = membre($pdo, 'meute-ban-1'); $m2 = membre($pdo, 'meute-ban-2');
echangePrive($pdo, $m1, $m2);
Moderate::applyVote($pdo, $m1, 'member', $proie, -1, motif(), 'agressif');
$banniParMeute = Moderate::estBanni($pdo, $proie);
Moderate::applyVote($pdo, $m2, 'member', $proie, -1, motif(), 'agressif');
$leveM = $journal->de($proie, 'ban_leve');
$banniParMeute && Moderate::estBanni($pdo, $proie) && $leveM === []
    ? ok('le vote d\'un ami du premier votant ne lève pas le ban que celui-ci a causé')
    : nok('un ban a été levé par le vote d\'un votant lié : ' . json_encode([$banniParMeute, $leveM, etatBan($pdo, $proie)]));

$tenu = membre($pdo, 'tenu-par-arbitre');
poserReputation($pdo, $tenu, 1);
Moderate::adminBan($pdo, $cibleArbitre = $tenu, idDe($pdo, 'arbitre-banc'), motif());
$m3 = membre($pdo, 'meute-ban-3'); $m4 = membre($pdo, 'meute-ban-4');
echangePrive($pdo, $m3, $m4);
Moderate::applyVote($pdo, $m3, 'member', $tenu, -1, motif(), 'agressif');
Moderate::applyVote($pdo, $m4, 'member', $tenu, -1, motif(), 'agressif');
Moderate::estBanni($pdo, $tenu) && Moderate::getReputation($pdo, $tenu)['ban_origine'] === 'admin'
    && $journal->de($tenu, 'ban_leve') === []
    ? ok('un ban d\'arbitre survit aux votes d\'une paire liée : il n\'était pas venu des votes')
    : nok('des votes liés ont levé un ban d\'arbitre : ' . json_encode(etatBan($pdo, $tenu)));

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
// n'a rien retiré, et un vote d'il y a deux cents jours sort de la fenêtre des
// voix : ni l'un ni l'autre n'en fait partie.
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

// ── 34. 🔑 Journal en panne : aucun ban sans sa trace, et le vote tient ──────
// Le refus du `ban_debut` ne défait pas le vote qui a fait tomber le compte :
// la chute et le ban dû sont signalés, la base ne reçoit aucun ban, et le vote
// négatif suivant le retente — journal revenu, le ban tombe et ferme le signal.
Moderate::setJournal(new JournalEnPanne());
$sansTrace = membre($pdo, 'journal-en-panne');
poserReputation($pdo, $sansTrace, 1);
$vw = membre($pdo, 'votant-w');
$leve = null;
$vote34 = null;
try {
    $vote34 = Moderate::applyVote($pdo, $vw, 'member', $sansTrace, -1, motif(), 'agressif');
} catch (Throwable $ex) {
    $leve = $ex->getMessage();
}
$etat34   = etatBan($pdo, $sansTrace);
$causes34 = causes($pdo, $sansTrace);
$journal34 = new JournalDeBanc();
Moderate::setJournal($journal34);
$vx34 = membre($pdo, 'votant-34');
$reprise34 = Moderate::applyVote($pdo, $vx34, 'member', $sansTrace, -1, motif(), 'agressif');
$apres34   = etatBan($pdo, $sansTrace);
$causesApres34 = causes($pdo, $sansTrace);
$debut34 = $journal34->de($sansTrace, 'ban_debut');
Moderate::setJournal(null);
$leve === null && ($vote34['ok'] ?? false) && (int) $etat34['banned_until'] === 0 && (int) $etat34['reputation'] === 0
    && in_array('ban_en_attente', $causes34, true) && in_array('reputation_zero', $causes34, true)
    && $etat34['review_reason'] === 'reputation_zero'
    ? ok('un journal en panne empêche le ban sans défaire le vote : la chute et le ban dû sont signalés, la base n\'a reçu aucun ban')
    : nok('ban posé sans trace, vote défait, ou refus muet : ' . json_encode([$leve, $vote34, $etat34, $causes34]));
($reprise34['ok'] ?? false) && (int) $apres34['banned_until'] > time() && count($debut34) === 1
    && !in_array('ban_en_attente', $causesApres34, true) && in_array('ban_auto', $causesApres34, true)
    && str_contains((string) ($debut34[0]['ferme'] ?? ''), 'ban_en_attente')
    ? ok('journal revenu, le vote négatif suivant pose le ban, qui ferme le ban dû et le dit dans son acte')
    : nok('le ban dû ne tombe pas au retour du journal : ' . json_encode([$reprise34, $apres34, $causesApres34, $debut34]));

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
// ne tente pas de le rebannir — aucun ban ne commence avant que la fin du
// précédent soit écrite, donc aucun ban dû ne se signale. Et un signal de panne tenu
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
$dusAttente = null;
try {
    Moderate::getReputation($pdo, $enAttente);
    $postAttente = message($pdo, $enAttente, 'fil-attente');
    foreach (['salve-panne-1', 'salve-panne-2', 'salve-panne-3'] as $nom) {
        Moderate::applyVote($pdo, membre($pdo, $nom), 'post', $postAttente, -1, motif(), 'agressif');
    }
    $pendantAttente = Moderate::getReputation($pdo, $enAttente)['review_reason'];
    $dusAttente = causes($pdo, $enAttente);
} catch (Throwable $ex) {
    $leveAttente = get_class($ex) . ' : ' . $ex->getMessage();
}
Moderate::setJournal($journal);
rattraper($pdo, $enAttente);
$apresAttente = Moderate::getReputation($pdo, $enAttente);
$leveAttente === null && $pendantAttente === 'salve_rapide' && $apresAttente['review_reason'] === 'salve_rapide'
    && !in_array('ban_en_attente', $dusAttente ?? [], true)
    && !$apresAttente['banned'] && count($journal->de($enAttente, 'ban_fin')) === 1
    ? ok('⭐ journal en panne : les votes passent, une salve se signale, et son signal survit à la clôture')
    : nok('⭐ panne du journal : ' . json_encode([$leveAttente, $pendantAttente, $dusAttente, $apresAttente['review_reason'] ?? null]));
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
Moderate::adminBan($pdo, $muet, idDe($pdo, 'arbitre-banc'), motif());
$refusFil = Pierroons\MySelfLab\Forum::createThread($pdo, $muet, 'un fil', 'general', 'bonjour');
$refusMsg = Pierroons\MySelfLab\Forum::createPost($pdo, $muet, 1, 'bonjour');
Moderate::adminPardon($pdo, $muet, idDe($pdo, 'arbitre-banc'), motif());
$accepte = Pierroons\MySelfLab\Forum::createThread($pdo, $muet, 'un fil', 'general', 'bonjour');
($refusFil['error'] ?? '') === 'compte_banni' && ($refusMsg['error'] ?? '') === 'compte_banni' && $accepte['ok']
    ? ok('un compte banni ne publie ni fil ni message, et publie de nouveau une fois le ban levé')
    : nok('publication pendant un ban : ' . json_encode([$refusFil, $refusMsg, $accepte]));

// ── 37bis. ⭐ Pendant un ban, le profil public est gelé ────────────────────
// La bio et le lien sont publics : un ban qui coupe la parole publique les
// coupe aussi. Sans cette garde, un banni garderait une tribune par son profil.
require_once __DIR__ . '/../lib/profile.php';
$gele = membre($pdo, 'profil-gele');
Moderate::adminBan($pdo, $gele, idDe($pdo, 'arbitre-banc'), motif());
$champsProfil = ['bio' => 'une tribune pendant la peine', 'localisation' => '', 'lien' => 'https://exemple.invalid'];
$refusProfil = Pierroons\MySelfLab\Profile::save($pdo, $gele, $champsProfil);
Moderate::adminPardon($pdo, $gele, idDe($pdo, 'arbitre-banc'), motif());
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

    /** Au-delà, une requête lève : une lecture qui ne rend pas rougit au lieu de pendre. */
    public ?int $plafond = null;

    public function compter(): void
    {
        if (++$this->requetes > ($this->plafond ?? PHP_INT_MAX)) {
            throw new RuntimeException("plafond de {$this->plafond} requêtes franchi");
        }
    }

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
        $this->compter();
        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->compter();
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

final class RequeteComptee extends PDOStatement
{
    protected function __construct(private PdoCompteur $connexion) {}

    public function execute(?array $params = null): bool
    {
        $this->connexion->compter();
        return parent::execute($params);
    }
}

$compteur = new PdoCompteur('sqlite:' . getenv('LAB_DB_PATH'));

/** Downvotes antidatés d'une heure : dans la fenêtre des plafonds, hors de celle des salves. */
function votesDeFond(PDO $pdo, int $auteur, int $post, int $combien, string $nom): void
{
    for ($j = 0; $j < $combien; $j++) {
        $pdo->prepare(
            'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, effet, created_at)
             VALUES (?, ?, ?, ?, -1, ?, -1, ?)'
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
// Une cible déjà visée par assez de votants sans lien pour atteindre le quorum du
// vote, une autre par bien plus, chacune au seuil du vote : le downvote mesuré la
// fait passer dessous, et le quorum lit ses votants et leurs liens — atteint des
// deux côtés, pour que la même sanction s'écrive, au journal que le cas branche.
// Les deux sont posées AVANT les deux mesures, pour que le reste de la base soit
// le même pour chacune : seul le graphe de la cible diffère. Les liens se lisent votant par votant dans l'index de la
// paire : dix votants de plus coûtent au plus deux requêtes chacun. Tester chaque paire
// par une requête ferait croître le coût comme le nombre de paires.
trancheBalayee($pdo);
$cibleP = membre($pdo, 'cible-39p');
$postP  = message($pdo, $cibleP, 'fil-39p');
votesDeFond($pdo, $cibleP, $postP, 6, '39p');
$cibleQ = membre($pdo, 'cible-39q');
$postQ  = message($pdo, $cibleQ, 'fil-39q');
votesDeFond($pdo, $cibleQ, $postQ, 16, '39q');
poserReputation($pdo, $cibleP, Moderate::config()->perteDroitDeVoteSous);
poserReputation($pdo, $cibleQ, Moderate::config()->perteDroitDeVoteSous);
// La connexion du compteur écrit et lit des liens une fois avant la mesure : le schéma
// et l'index de la paire se vérifient une fois par connexion (`exigerSchema()`,
// `exigerIndexPaire()`), et n'entrent pas dans le coût d'un vote.
Moderate::regenerate($compteur, $cibleP);
Moderate::areLinked($compteur, $cibleP, $cibleQ);
[$sept, $dixSept] = sousQuorum(static fn (): array => [
    coutDownvote($compteur, $pdo, $postP, '39p'),
    coutDownvote($compteur, $pdo, $postQ, '39q'),
]);
$sept !== null && $dixSept !== null && $dixSept - $sept <= 2 * 10 && reputation($pdo, $cibleP) === Moderate::config()->perteDroitDeVoteSous - 1
    && !Moderate::getReputation($pdo, $cibleP)['voting_rights'] && !Moderate::getReputation($pdo, $cibleQ)['voting_rights']
    ? ok("un downvote qui fait passer le seuil coûte $sept requêtes avec sept votants, $dixSept avec dix-sept, quorum compris : au plus deux par votant de plus")
    : nok('le coût d\'un downvote croît avec ses votants : ' . json_encode(['7_votants' => $sept, '17_votants' => $dixSept]));

// ── 40. Un downvote ne paie pas pour l'activité du reste du site ────────────
// ⭐ Le coût d'un vote ne doit dépendre que de sa cible. Mesuré avant puis
// après que vingt autres membres ont reçu chacun une salve que personne n'a
// encore vue, dans une tranche déjà balayée : si chaque vote rebalaie la
// communauté, il paie leurs signaux, son coût suit l'activité du site, et
// n'importe quel compte qui vote tient ce levier.
trancheBalayee($pdo);
$cibleA = membre($pdo, 'cible-40a');
$avant = coutDownvote($compteur, $pdo, message($pdo, $cibleA, 'fil-40a'), '40a');
for ($i = 0; $i < 20; $i++) {
    $autre = membre($pdo, "autre-40-$i");
    $postAutre = message($pdo, $autre, "fil-40-$i");
    foreach (['a', 'b', 'c'] as $l) {
        voteBrut($pdo, membre($pdo, "autre-40-$i-$l"), $postAutre, $autre);
    }
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
// La phrase peut commencer à la ligne suivante de l'appel.
// ⚠️ L'extraction ne lit que les guillemets simples : une phrase entre
// guillemets doubles lui échapperait, et le contrôle refuse donc d'en voir une.
$sourceMoteur = (string) file_get_contents(__DIR__ . '/../../../bi-self/selfmoderate/src/Moderate.php');
preg_match_all("/static::t\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*\\)/", $sourceMoteur, $phrases);
$contenuEn = require __DIR__ . '/../lang/contenu-en.php';
$sansAnglais = array_values(array_filter(array_unique(array_map('stripslashes', $phrases[1])),
    static fn (string $p): bool => !array_key_exists($p, $contenuEn)));
preg_match_all("/'message'\\s*=>\\s*'([^']*)'/", $sourceMoteur, $horsT);
$guillemetsDoubles = preg_match_all('/static::t\\(\\s*"/', $sourceMoteur);
count($phrases[1]) > 0 && $sansAnglais === [] && $horsT[1] === [] && $guillemetsDoubles === 0
    ? ok(count(array_unique($phrases[1])) . ' phrases du moteur passent par la traduction, et chacune a son anglais')
    : nok('une phrase du moteur sort en français sur la page anglaise : ' . json_encode([
        'sans_anglais' => $sansAnglais, 'hors_traduction' => $horsT[1], 'guillemets_doubles' => $guillemetsDoubles,
    ], JSON_UNESCAPED_UNICODE));

// ── 45. Un vote s'inscrit tout ou rien ──────────────────────────────────────
// Le journal rappelle le moteur depuis `inscrire()` au début du ban que ce
// downvote déclenche : une erreur d'intégration, qui remonte toujours. Rien de
// ce que le vote avait déjà écrit — le vote lui-même, le point retiré — ne doit
// rester : une annulation ultérieure rendrait sinon un point jamais pris.
$journalAvant45 = Moderate::journal();
$fragile45 = membre($pdo, 'fragile-45');
Moderate::setJournal(new class ($pdo, $fragile45) implements \Pierroons\SelfModerate\Journal {
    public function __construct(private PDO $p, private int $compte)
    {
    }

    public function inscrire(array $acte): void
    {
        if ($acte['acte'] === 'ban_debut') {
            Moderate::getReputation($this->p, $this->compte);
        }
    }
});
$post45    = message($pdo, $fragile45, 'fil-45');
poserReputation($pdo, $fragile45, 1);
$votant45  = membre($pdo, 'votant-45');
$remonte45 = false;
try {
    Moderate::applyVote($pdo, $votant45, 'post', $post45, -1, motif(), 'agressif');
} catch (\Pierroons\SelfModerate\LectureDansUneEcriture $e) {
    $remonte45 = true;
}
Moderate::setJournal($journalAvant45);
$votes45 = (int) $pdo->query("SELECT COUNT(*) FROM mod_votes WHERE voter_id = $votant45")->fetchColumn();
$rep45   = (int) $pdo->query("SELECT reputation FROM member_moderation WHERE account_id = $fragile45")->fetchColumn();
$remonte45 && $votes45 === 0 && $rep45 === 1 && !$pdo->inTransaction()
    ? ok('une erreur d\'intégration au début du ban remonte, et le vote n\'a rien laissé : ni vote, ni point retiré')
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
Moderate::migrerBans($pdo);
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
[$gracie50, $maintenu50, $leve50, $prolonge50] = [membre($pdo, 'gracie-50'), membre($pdo, 'maintenu-50'), membre($pdo, 'leve-50'), membre($pdo, 'prolonge-50')];
foreach ([$gracie50, $maintenu50, $leve50, $prolonge50] as $id50) {
    poserReputation($pdo, $id50, 15);
    Moderate::adminBan($pdo, $id50, idDe($pdo, 'arbitre-banc'), motif());
}
$enRevue50 = membre($pdo, 'en-revue-50');
poserReputation($pdo, $enRevue50, 15);
planterSignal($pdo, $enRevue50, 'salve_rapide');
$gestes50 = [
    'adminBan'       => [$aBannir50, static fn (): array => Moderate::adminBan($pdo, $aBannir50, idDe($pdo, 'arbitre-banc'), motif())],
    'adminPardon'    => [$gracie50, static fn (): array => Moderate::adminPardon($pdo, $gracie50, idDe($pdo, 'arbitre-banc'), motif())],
    'adminMaintenir' => [$maintenu50, static fn (): array => Moderate::adminMaintenir($pdo, $maintenu50, idDe($pdo, 'arbitre-banc'), motif())],
    'adminClasser'   => [$enRevue50, static fn (): array => Moderate::adminClasser($pdo, $enRevue50, idDe($pdo, 'arbitre-banc'), motif())],
    'adminLever'     => [$leve50, static fn (): array => Moderate::adminLever($pdo, $leve50, idDe($pdo, 'arbitre-banc'), motif())],
    'adminProlonger' => [$prolonge50, static fn (): array => Moderate::adminProlonger($pdo, $prolonge50, idDe($pdo, 'arbitre-banc'), motif(), 3600)],
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
// Le pendant du n° 49 pour chaque geste : la décision se prend et
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
foreach (['ban', 'lever', 'pardon', 'prolonger', 'maintenir', 'classer'] as $geste) {
    $cible51[$geste] = membre($pdo, "verrou-$geste-51");
    poserReputation($pdo, $cible51[$geste], 15);
}
foreach (['lever', 'pardon', 'prolonger'] as $geste) {
    Moderate::adminBan($pdo, $cible51[$geste], idDe($pdo, 'arbitre-banc'), motif());
}
// Seul un ban automatique attend un maintien.
$pdo->prepare("UPDATE member_moderation
                  SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                      ban_origine = 'auto', ban_motif = 'reputation_zero'
                WHERE account_id = ?")->execute([time() + 3600, time(), $cible51['maintenir']]);
Moderate::migrerBans($pdo);
planterSignal($pdo, $cible51['maintenir'], 'ban_auto');
planterSignal($pdo, $cible51['classer'], 'salve_rapide');
$a51 = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
$a51->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$b51 = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
$b51->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$b51->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$b51->exec('PRAGMA busy_timeout = 100');
$gestes51 = [
    'ban'       => static fn (): array => Moderate::adminBan($b51, $cible51['ban'], idDe($pdo, 'arbitre-banc'), motif()),
    'lever'     => static fn (): array => Moderate::adminLever($b51, $cible51['lever'], idDe($pdo, 'arbitre-banc'), motif()),
    'pardon'    => static fn (): array => Moderate::adminPardon($b51, $cible51['pardon'], idDe($pdo, 'arbitre-banc'), motif()),
    'prolonger' => static fn (): array => Moderate::adminProlonger($b51, $cible51['prolonger'], idDe($pdo, 'arbitre-banc'), motif(), 600),
    'maintenir' => static fn (): array => Moderate::adminMaintenir($b51, $cible51['maintenir'], idDe($pdo, 'arbitre-banc'), motif()),
    'classer'   => static fn (): array => Moderate::adminClasser($b51, $cible51['classer'], idDe($pdo, 'arbitre-banc'), motif()),
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
$r52 = Moderate::adminBan($pdo, $recidiviste52, idDe($pdo, 'arbitre-banc'), motif());
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
Moderate::migrerBans($pdo);
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

// ── 55. Dans la transaction différée d'un hôte, rien ne s'inscrit sans le verrou ─
// Un point de reprise ne prend aucun verrou, et l'hôte ouvre souvent en
// différé, puis lit. Une autre connexion qui écrit alors refuse aussitôt le
// verrou au vote, et le geste comme le balayage refusent toute transaction
// d'hôte : aucun des trois ne doit avoir inscrit son acte — le vote d'un compte
// à la peine échue pas plus que les autres, dont le rattrapage inscrirait la fin.
$journalAvant55 = Moderate::journal();
$journal55 = new JournalDeBanc();
Moderate::setJournal($journal55);
$banni55 = membre($pdo, 'banni-hote-55');
poserReputation($pdo, $banni55, 15);
Moderate::adminBan($pdo, $banni55, idDe($pdo, 'arbitre-banc'), motif());
$echu55 = membre($pdo, 'echu-hote-55');
Moderate::ensureRow($pdo, $echu55);
$pdo->prepare("UPDATE member_moderation
                  SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                      ban_origine = 'auto', ban_motif = 'reputation_zero'
                WHERE account_id = ?")->execute([time() - 10, time() - 130, $echu55]);
Moderate::migrerBans($pdo);
$cible55 = membre($pdo, 'cible-hote-55');
$journal55->actes = [];
$autre55 = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
$autre55->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$hote55 = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
$hote55->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$hote55->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$hote55->exec('PRAGMA busy_timeout = 100');
$issues55 = [];
foreach (['grace' => static fn (): mixed => Moderate::adminPardon($hote55, $banni55, idDe($pdo, 'arbitre-banc'), motif()),
          'balayage' => static fn (): mixed => Moderate::balayerBansEchus($hote55),
          'vote' => static fn (): mixed => Moderate::applyVote($hote55, $echu55, 'member', $cible55, -1, motif(), 'agressif')] as $geste => $faire55) {
    $hote55->beginTransaction();
    $hote55->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
    $autre55->exec('BEGIN IMMEDIATE');
    $autre55->exec('UPDATE member_moderation SET updated_at = updated_at WHERE account_id = ' . $banni55);
    try {
        $r55 = $faire55();
        $issues55[$geste] = is_array($r55) && !($r55['ok'] ?? true) ? 'refusé' : 'passé';
    } catch (PDOException | LogicException) {
        $issues55[$geste] = 'refusé';
    }
    $autre55->exec('ROLLBACK');
    if ($hote55->inTransaction()) {
        $hote55->rollBack();
    }
}
$actes55 = count($journal55->actes);
Moderate::setJournal($journalAvant55);
$issues55 === ['grace' => 'refusé', 'balayage' => 'refusé', 'vote' => 'refusé'] && $actes55 === 0
    ? ok('dans la transaction différée d\'un hôte, un vote, un geste et le balayage refusés n\'ont rien inscrit')
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
foreach (['ban', 'grace', 'classement', 'levee', 'prolongation'] as $geste) {
    $attente56 = membre($pdo, "attente-$geste-56");
    Moderate::ensureRow($pdo, $attente56);
    $pdo->prepare("UPDATE member_moderation
                      SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                          ban_origine = 'auto', ban_motif = 'reputation_zero'
                    WHERE account_id = ?")->execute([time() - 10, time() - 130, $attente56]);
    Moderate::migrerBans($pdo);
    // Le geste rattrape le compte avant de décider, et contient le refus du
    // journal : s'il ne le contenait plus, le cas rougirait sur son signal absent,
    // au lieu d'arrêter le banc.
    try {
        $journal56->actes = [];
        $r56 = match ($geste) {
            'ban'        => Moderate::adminBan($pdo, $attente56, idDe($pdo, 'arbitre-banc'), motif()),
            'grace'      => Moderate::adminPardon($pdo, $attente56, idDe($pdo, 'arbitre-banc'), motif()),
            'classement' => Moderate::adminClasser($pdo, $attente56, idDe($pdo, 'arbitre-banc'), motif()),
            'levee'        => Moderate::adminLever($pdo, $attente56, idDe($pdo, 'arbitre-banc'), motif()),
            'prolongation' => Moderate::adminProlonger($pdo, $attente56, idDe($pdo, 'arbitre-banc'), motif(), 3600),
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
    ? ok('une peine qui attend sa fin au journal arrête le ban, la grâce, le classement, la levée et la prolongation, et son signal reste')
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
    Moderate::adminBan($pdo, $visee57, idDe($pdo, 'arbitre-un'), motif());
};
$c57->verrous = 0;
// Le refus doit venir de la relecture sous le verrou. Le moteur refuse aussi d'ouvrir
// une peine sur une autre, par une exception : ce filet ne remplace pas la relecture.
try {
    $ban57 = Moderate::adminBan($c57, $visee57, idDe($pdo, 'arbitre-deux'), motif());
} catch (Throwable $e57) {
    $ban57 = ['ok' => false, 'leve' => get_class($e57) . ' : ' . $e57->getMessage()];
}
$verrous57 = [$c57->verrous];
$graciee57 = membre($pdo, 'graciee-57');
poserReputation($pdo, $graciee57, 15);
Moderate::adminBan($pdo, $graciee57, idDe($pdo, 'arbitre-un'), motif());
$c57->avantLeVerrou = static function () use ($pdo, $graciee57): void {
    Moderate::adminPardon($pdo, $graciee57, idDe($pdo, 'arbitre-un'), motif());
};
$c57->verrous = 0;
Moderate::adminPardon($c57, $graciee57, idDe($pdo, 'arbitre-deux'), motif());
$verrous57[] = $c57->verrous;
$debuts57 = count($journal57->de($visee57, 'ban_debut'));
$leves57 = count($journal57->de($graciee57, 'ban_leve'));
Moderate::setJournal($journalAvant57);
if ($verrous57 !== [1, 1]) {
    nok('le contrôle 57 ne mesure plus rien : verrous pris par geste ' . json_encode($verrous57) . ', l\'autre arbitre n\'agit plus juste avant celui du geste');
} else {
    !$ban57['ok'] && !isset($ban57['leve']) && $debuts57 === 1 && $leves57 === 1
        ? ok('un geste devancé par un autre arbitre décide sur l\'état relu sous le verrou : un seul ban, une seule levée')
        : nok('un geste décide sur l\'état lu avant le verrou : ' . json_encode(['ban' => $ban57, 'ban_debut' => $debuts57, 'ban_leve' => $leves57], JSON_UNESCAPED_UNICODE));
}

// ── 59. Aucun geste d'arbitre dans la transaction d'un hôte ─────────────────
// Le journal ne se défait pas avec la transaction de l'hôte : un geste qui y
// rendrait `ok` laisserait son acte au journal si l'hôte annulait ensuite.
// Chaque geste refuse, et rien n'est inscrit.
$journalAvant59 = Moderate::journal();
$journal59 = new JournalDeBanc();
Moderate::setJournal($journal59);
$cibles59 = [];
foreach (['ban', 'grace', 'maintien', 'classement', 'levee', 'prolongation'] as $geste) {
    $cibles59[$geste] = membre($pdo, "hote-$geste-59");
    poserReputation($pdo, $cibles59[$geste], 15);
}
Moderate::adminBan($pdo, $cibles59['grace'], idDe($pdo, 'arbitre-banc'), motif());
Moderate::adminBan($pdo, $cibles59['maintien'], idDe($pdo, 'arbitre-banc'), motif());
Moderate::adminBan($pdo, $cibles59['levee'], idDe($pdo, 'arbitre-banc'), motif());
Moderate::adminBan($pdo, $cibles59['prolongation'], idDe($pdo, 'arbitre-banc'), motif());
planterSignal($pdo, $cibles59['classement'], 'salve_rapide');
$journal59->actes = [];
$fautes59 = [];
foreach (['ban'        => static fn (): array => Moderate::adminBan($pdo, $cibles59['ban'], idDe($pdo, 'arbitre-banc'), motif()),
          'grace'      => static fn (): array => Moderate::adminPardon($pdo, $cibles59['grace'], idDe($pdo, 'arbitre-banc'), motif()),
          'maintien'   => static fn (): array => Moderate::adminMaintenir($pdo, $cibles59['maintien'], idDe($pdo, 'arbitre-banc'), motif()),
          'classement' => static fn (): array => Moderate::adminClasser($pdo, $cibles59['classement'], idDe($pdo, 'arbitre-banc'), motif()),
          'levee'        => static fn (): array => Moderate::adminLever($pdo, $cibles59['levee'], idDe($pdo, 'arbitre-banc'), motif()),
          'prolongation' => static fn (): array => Moderate::adminProlonger($pdo, $cibles59['prolongation'], idDe($pdo, 'arbitre-banc'), motif(), 3600)]
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
    ? ok('dans la transaction d\'un hôte, aucun geste ne passe, et rien n\'est inscrit')
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
 * a le sien — la chute, ou la chute sans quorum —, et la case dit la table — le premier comportement à voir, sinon le
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
                 OR (s.cause = 'reputation_zero' AND m.convalescent = 0)
                 OR (s.cause = 'ban_en_attente' AND NOT EXISTS (SELECT 1 FROM mod_signaux z
                        WHERE z.account_id = s.account_id AND z.cause = 'reputation_zero'))
                 OR (s.cause = 'droit_de_vote_perdu' AND m.voting_rights = 1)
                 OR (s.cause = 'perte_du_vote_due' AND (m.voting_rights = 0 OR m.convalescent = 0))
                 OR (s.cause = 'ban_sans_fin' AND m.banned_until < " . Moteur::SANS_FIN . ")
                 OR (s.cause = 'chute_sans_quorum' AND (m.convalescent = 0 OR (m.ban_origine = 'auto' AND m.banned_until > 0)))"
        )->fetchAll(PDO::FETCH_ASSOC),
        'bans_sans_signal' => $p->query(
            "SELECT account_id FROM member_moderation m
              WHERE ban_origine = 'auto' AND banned_until > " . time() . "
                AND NOT EXISTS (SELECT 1 FROM mod_signaux s WHERE s.account_id = m.account_id AND s.cause = 'ban_auto')"
        )->fetchAll(PDO::FETCH_ASSOC),
        'chutes_sans_signal' => $p->query(
            "SELECT account_id FROM member_moderation m
              WHERE reputation <= " . Moderate::config()->banA . " AND banned_until = 0
                AND NOT EXISTS (SELECT 1 FROM mod_signaux s WHERE s.account_id = m.account_id
                                   AND s.cause IN ('reputation_zero', 'chute_sans_quorum'))"
        )->fetchAll(PDO::FETCH_ASSOC),
        'cases' => $p->query(
            "SELECT account_id, needs_review, review_reason FROM (
                SELECT m.account_id, m.needs_review, m.review_reason,
                       EXISTS (SELECT 1 FROM mod_signaux s WHERE s.account_id = m.account_id AND s.vu_le IS NULL) AS attendu_besoin,
                       COALESCE(
                           (SELECT cause FROM mod_signaux s WHERE s.account_id = m.account_id AND s.vu_le IS NULL
                               AND cause NOT IN ('reputation_zero', 'ban_auto', 'journal_en_panne', 'levee_en_attente', 'ban_en_attente', 'droit_de_vote_perdu', 'perte_du_vote_due', 'ban_sans_fin', 'chute_sans_quorum') ORDER BY id LIMIT 1),
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
$replie65 = Moderate::adminClasser($pdo, $compte65, idDe($pdo, 'arbitre-banc'), motif(), $jeton65);
$pdo->prepare('UPDATE mod_votes SET created_at = created_at - ? WHERE target_author = ?')->execute([3 * $fenetre65, $compte65]);
$pdo->prepare('UPDATE mod_signaux SET derniere_le = derniere_le - ? WHERE account_id = ?')->execute([3 * $fenetre65, $compte65]);
foreach (range(7, 9) as $i) {
    Moderate::applyVote($pdo, membre($pdo, "salve-$i-65"), 'post', $post65, -1, motif(), 'agressif');
}
$neuve65  = Moderate::getReputation($pdo, $compte65)['signaux'][0] ?? null;
$perime65 = Moderate::adminClasser($pdo, $compte65, idDe($pdo, 'arbitre-banc'), motif(), ['signal' => $vu65['signal'], 'detections' => $filet65['detections'] ?? 0]);
$juste65  = Moderate::adminClasser($pdo, $compte65, idDe($pdo, 'arbitre-banc'), motif(), ['signal' => $vu65['signal'], 'detections' => $neuve65['detections'] ?? 0]);
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
$aveugle66 = Moderate::adminClasser($pdo, $deux66, idDe($pdo, 'arbitre-banc'), motif());
$salve66   = array_values(array_filter(Moderate::getReputation($pdo, $deux66)['signaux'] ?? [],
    static fn (array $s): bool => $s['cause'] === 'salve_rapide'))[0] ?? ['signal' => 0, 'detections' => 0];
$jeton66   = ['signal' => $salve66['signal'], 'detections' => $salve66['detections']];
$nomme66   = Moderate::adminClasser($pdo, $deux66, idDe($pdo, 'arbitre-banc'), motif(), $jeton66);
$rejoue66  = Moderate::adminClasser($pdo, $deux66, idDe($pdo, 'arbitre-banc'), motif(), $jeton66);
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
$g67 = Moderate::adminPardon($pdo, $gracie67, idDe($pdo, 'arbitre-banc'), motif());
$acte67 = $journalC->de($gracie67, 'grace');
$sansRang67 = membre($pdo, 'sans-rang-67');
poserReputation($pdo, $sansRang67, 20);
planterSignal($pdo, $sansRang67, 'meute_recidive');
Moderate::adminPardon($pdo, $sansRang67, idDe($pdo, 'arbitre-banc'), motif());
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
$c68 = Moderate::adminClasser($pdo, $vu68, idDe($pdo, 'arbitre-banc'), motif());
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
Moderate::migrerBans($pdo);
planterSignal($pdo, $auto68, 'ban_auto');
$refus68 = Moderate::adminClasser($pdo, $auto68, idDe($pdo, 'arbitre-banc'), motif());
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
$ancienne69->exec("DROP TABLE IF EXISTS mod_signaux; DELETE FROM lab_migrations WHERE nom = 'signaux-en-table';
                    DROP TABLE IF EXISTS mod_peine_causes; DROP TABLE IF EXISTS mod_peines;
                    DELETE FROM lab_migrations WHERE nom = 'peines-en-table'");
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
        $lu['peines'] = (int) $p->query('SELECT COUNT(*) FROM mod_peines WHERE fin IS NULL AND reprise = 1')->fetchColumn();
        $lu['bans_sans_peine'] = (int) $p->query('SELECT COUNT(*) FROM member_moderation m WHERE banned_until > 0
            AND NOT EXISTS (SELECT 1 FROM mod_peines p WHERE p.account_id = m.account_id AND p.fin IS NULL)')->fetchColumn();
        $lu['marque'] = $p->query("SELECT 1 FROM lab_migrations WHERE nom = 'peines-en-table'")->fetchColumn() !== false;
        $lu['bans'] = (int) $p->query('SELECT COUNT(*) FROM member_moderation WHERE banned_until > 0')->fetchColumn();

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
    && ($premiere69['marque'] ?? false) === true && ($premiere69['bans_sans_peine'] ?? -1) === 0
    && ($premiere69['bans'] ?? 0) >= 6 && ($premiere69['peines'] ?? -1) === $premiere69['bans']
    && ($seconde69['peines'] ?? -1) === $premiere69['peines']
    && ($premiere69['en_1970'] ?? -1) === 0
    && ($seconde69['signaux'] ?? null) === $premiere69['signaux'] && ($seconde69['total'] ?? null) === $premiere69['total']
    ? ok('⭐ une base d\'avant les tables migre une fois : chaque case ouverte devient une ligne, chaque ban automatique retrouve son signal et chaque ban sa peine, l\'orphelin ne passe pas')
    : nok('⭐ la migration perd un signal, en invente un, ou se rejoue : ' . json_encode([
        'obtenu' => $obtenu69, 'invariants' => $premiere69['invariants'] ?? $premiere69, 'en_1970' => $premiere69['en_1970'] ?? null,
        'seconde' => $seconde69['signaux'] ?? $seconde69,
        'peines' => [$premiere69['bans'] ?? null, $premiere69['peines'] ?? null, $seconde69['peines'] ?? null, $premiere69['bans_sans_peine'] ?? null, $premiere69['marque'] ?? null],
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
$classe70 = Moderate::adminClasser($pdo, $etendu70, idDe($pdo, 'arbitre-banc'), motif(), ['signal' => $vu70['signal'], 'detections' => $vu70['detections']]);
voterLarge($pdo, $etendu70, 'relance-70');
$vu70b = array_values(array_filter(Moderate::getReputation($pdo, $etendu70)['signaux'] ?? [],
    static fn (array $s): bool => $s['cause'] === 'votant_etendu'))[0] ?? ['signal' => 0, 'detections' => 0];
$pdo->prepare('UPDATE mod_signaux SET derniere_le = derniere_le - ? WHERE account_id = ?')
    ->execute([Moderate::config()->balayagePeriodeSecondes + 1, $etendu70]);
Moderate::applyVote($pdo, $etendu70, 'member', membre($pdo, 'apres-tranche-70'), -1, motif(), 'agressif');
$perime70 = Moderate::adminClasser($pdo, $etendu70, idDe($pdo, 'arbitre-banc'), motif(), ['signal' => $vu70b['signal'], 'detections' => $vu70b['detections']]);
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
$classeAutre73 = Moderate::adminClasser($pdo, $autre73, idDe($pdo, 'arbitre-banc'), motif(), $vuAutre73);
$pdo->exec('DELETE FROM mod_sweeps');
Moderate::applyVote($pdo, membre($pdo, 'salve-cible-d-73'), 'member', $cible73, -1, motif(), 'agressif');
$classeCible73 = Moderate::adminClasser($pdo, $cible73, idDe($pdo, 'arbitre-banc'), motif(), $vuCible73);
($classeAutre73['ok'] ?? false) && !($classeCible73['ok'] ?? true) && str_contains($classeCible73['message'] ?? '', 'changé')
    ? ok('⭐ un balayage ne périme pas l\'affichage des comptes qu\'il relit ; sur la cible du vote qui le lance, un votant de plus le périme')
    : nok('⭐ le balayage périme un affichage sans vote neuf, ou laisse passer une salve repliée : ' . json_encode([
        'ailleurs' => $classeAutre73, 'cible' => $classeCible73,
    ], JSON_UNESCAPED_UNICODE));

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
$classe76  = Moderate::adminClasser($pdo, $anterieur76, idDe($pdo, 'arbitre-banc'), motif());
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
    Moderate::adminBan($pdo, $compte77, idDe($pdo, 'arbitre-banc'), motif());
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
// erreur 500. Chaque geste refuse, et rien ne s'inscrit au journal.
$inconnu79 = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM accounts')->fetchColumn();
$actesAvant79 = count($journalC->actes);
$gestes79 = [];
foreach ([
    'ban'       => static fn (): array => Moderate::adminBan($pdo, $inconnu79, idDe($pdo, 'arbitre-banc'), motif()),
    'grace'     => static fn (): array => Moderate::adminPardon($pdo, $inconnu79, idDe($pdo, 'arbitre-banc'), motif()),
    'maintenir' => static fn (): array => Moderate::adminMaintenir($pdo, $inconnu79, idDe($pdo, 'arbitre-banc'), motif()),
    'classer'   => static fn (): array => Moderate::adminClasser($pdo, $inconnu79, idDe($pdo, 'arbitre-banc'), motif()),
    'lever'     => static fn (): array => Moderate::adminLever($pdo, $inconnu79, idDe($pdo, 'arbitre-banc'), motif()),
    'prolonger' => static fn (): array => Moderate::adminProlonger($pdo, $inconnu79, idDe($pdo, 'arbitre-banc'), motif(), 3600),
] as $geste79 => $appel79) {
    try {
        $r79 = $appel79();
        $gestes79[$geste79] = [$r79['ok'], $r79['message']];
    } catch (Throwable $t79) {
        $gestes79[$geste79] = get_class($t79);
    }
}
$gestes79 === array_fill_keys(['ban', 'grace', 'maintenir', 'classer', 'lever', 'prolonger'], [false, 'Compte introuvable.'])
    && count($journalC->actes) === $actesAvant79
    ? ok('⭐ chaque geste refuse un compte inexistant, sans erreur et sans rien inscrire')
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
$premier81 = Moderate::adminBan($pdo, $enchaine81, idDe($pdo, 'arbitre-banc'), motif());
$apresUn81 = causes($pdo, $enchaine81);
vieillirBan($pdo, $enchaine81, 100 * Moderate::config()->dureeBanAdmin());
$second81 = Moderate::adminBan($pdo, $enchaine81, idDe($pdo, 'arbitre-banc'), motif());
$apresDeux81 = causes($pdo, $enchaine81);
vieillirBan($pdo, $enchaine81, 100 * Moderate::config()->dureeBanAdmin());
$pdo->prepare('UPDATE member_moderation SET reputation = 1, voting_rights = 1 WHERE account_id = ?')->execute([$enchaine81]);
Moderate::applyVote($pdo, membre($pdo, 'chute-81'), 'member', $enchaine81, -1, motif(), 'agressif');
$debuts81 = array_map(static fn (array $a): array => [$a['origine'], $a['episode'], $a['dont_arbitre'] ?? null],
    $journalC->de($enchaine81, 'ban_debut'));
$grace81 = Moderate::adminPardon($pdo, $enchaine81, idDe($pdo, 'arbitre-banc'), motif());
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
        Moderate::migrerBans($pdo);
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
Moderate::setConfig(configBanc(balayageFiletMax: 10_000));
try {
    $oublie84 = membre($pdo, 'oublie-84');
    Moderate::ensureRow($pdo, $oublie84);
    $pdo->prepare("UPDATE member_moderation
                      SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                          ban_origine = 'auto', ban_motif = 'reputation_zero'
                    WHERE account_id = ?")->execute([time() - 10_000_000, time() - 10_000_120, $oublie84]);
    Moderate::migrerBans($pdo);
    planterSignal($pdo, $oublie84, 'ban_auto');
    $hote84 = membre($pdo, 'oublie-hote-84');
    Moderate::ensureRow($pdo, $hote84);
    $pdo->prepare("UPDATE member_moderation
                      SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                          ban_origine = 'auto', ban_motif = 'reputation_zero'
                    WHERE account_id = ?")->execute([time() - 10_000_000, time() - 10_000_120, $hote84]);
    Moderate::migrerBans($pdo);
    planterSignal($pdo, $hote84, 'ban_auto');
    $pdo->exec('DELETE FROM mod_sweeps');
    // Le filet ne part pas dans la transaction de l'hôte : le balayage, qui la refuse
    // aussi, n'y écrit rien, mais sa prise le dirait au journal d'erreurs.
    $erreurs84 = $dir . '/erreurs-84.log';
    $journalErreurs84 = ini_set('error_log', $erreurs84);
    $pdo->beginTransaction();
    Moderate::applyVote($pdo, membre($pdo, 'votant-hote-84'), 'member', membre($pdo, 'vise-hote-84'), -1, motif(), 'agressif');
    $pdo->commit();
    ini_set('error_log', (string) $journalErreurs84);
    $hoteIntact84 = (int) etatBan($pdo, $hote84)['banned_until'] > 0 && $journalC->de($hote84, 'ban_fin') === []
        && !str_contains((string) @file_get_contents($erreurs84), 'balayage du filet');
    $pdo->exec('DELETE FROM mod_sweeps');
    Moderate::applyVote($pdo, membre($pdo, 'votant-84'), 'member', membre($pdo, 'vise-84'), -1, motif(), 'agressif');
} finally {
    Moderate::setConfig(configBanc());
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

// ── 86. ⭐ Un croisement rend ce que le temps n'a pas déjà rendu ───────────
// La sortie de convalescence remet le compte au départ et efface l'effet des
// votes reçus. Un vote croisé ensuite ne rend rien de plus : compter la
// restitution avant d'inscrire la sortie rendrait deux fois les mêmes points.
$rendu86 = membre($pdo, 'rendu-86');
poserReputation($pdo, $rendu86, 19);
$pdo->prepare('UPDATE member_moderation SET convalescent = 1, last_regen_at = ? WHERE account_id = ?')
    ->execute([time() - Moderate::config()->intervalleConvalescenceSecondes, $rendu86]);
planterSignal($pdo, $rendu86, 'reputation_zero');
$pdo->prepare('INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, reason_code, effet, created_at)
               VALUES (?, ?, ?, ?, -1, ?, ?, -3, ?)')
    ->execute([membre($pdo, 'croise-86'), 'member', $rendu86, $rendu86, motif(), 'agressif', time() - 100]);
$vote86 = (int) $pdo->lastInsertId();
$rendre86 = new ReflectionMethod(Moteur::class, 'rendreLePoint');
(new ReflectionMethod(Moteur::class, 'transaction'))->invoke(null, $pdo,
    static fn () => $rendre86->invoke(null, $pdo, $rendu86, $vote86));
$apres86 = (int) $pdo->query("SELECT reputation FROM member_moderation WHERE account_id = $rendu86")->fetchColumn();
$effet86 = (int) $pdo->query("SELECT effet FROM mod_votes WHERE id = $vote86")->fetchColumn();
$apres86 === 20 && $effet86 === 0
    ? ok('⭐ un vote croisé après la sortie de convalescence ne rend pas une seconde fois ce que la remise a rendu')
    : nok('⭐ un croisement rend deux fois les mêmes points : ' . json_encode(['reputation' => $apres86, 'effet' => $effet86]));

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

// ── 89. ⭐ La page ne ferme pas un vote que le journal revenu laisse passer ──
// Une peine échue dont le journal refuse la fin : le vote est refusé, avec sa
// raison, et l'arbitre prévenu. La page ne sait pas si le journal est revenu —
// seul un essai le sait : elle laisse voter, et le vote, journal revenu, inscrit
// la fin sans attendre un balayage.
$refuse89 = membre($pdo, 'refuse-89');
Moderate::ensureRow($pdo, $refuse89);
$pdo->prepare("UPDATE member_moderation
                  SET reputation = 0, strikes = 1, voting_rights = 0, convalescent = 1, last_regen_at = ?,
                      banned_until = ?, ban_debut = ?, ban_origine = 'auto', ban_motif = 'reputation_zero'
                WHERE account_id = ?")->execute([time() - 200, time() - 10, time() - 130, $refuse89]);
Moderate::migrerBans($pdo);
planterSignal($pdo, $refuse89, 'ban_auto');
Moderate::setJournal(new JournalEnPanne());
try {
    $vote89 = Moderate::applyVote($pdo, $refuse89, 'member', membre($pdo, 'vise-89'), 1);
} catch (Throwable $t89) {
    $vote89 = ['exception' => $t89->getMessage()];
} finally {
    Moderate::setJournal($journalC);
}
$panne89 = in_array('journal_en_panne', causes($pdo, $refuse89), true);
[$page89, $pourquoi89] = Moderate::canVote($pdo, $refuse89);
$revote89 = Moderate::applyVote($pdo, $refuse89, 'member', membre($pdo, 'vise-bis-89'), 1);
$inscrite89 = (int) etatBan($pdo, $refuse89)['banned_until'] === 0 && count($journalC->de($refuse89, 'ban_fin')) === 1;
!($vote89['ok'] ?? true) && str_contains($vote89['message'] ?? '', 'attend que le journal') && $panne89
    && $page89 && ($revote89['ok'] ?? false) && $inscrite89
    ? ok('⭐ journal en panne, le vote est refusé avec sa raison ; journal revenu, la page laisse voter et le vote inscrit la fin')
    : nok('⭐ la page ferme un vote que le journal revenu laisse passer : ' . json_encode([
        'vote' => $vote89, 'panne' => $panne89, 'page' => [$page89, $pourquoi89], 'revote' => $revote89, 'inscrite' => $inscrite89,
    ], JSON_UNESCAPED_UNICODE));

// ── 90. ⭐ Un refus du journal coûte un appel par passe, et ne bloque pas la file ─
// Le balayage prend les peines les plus anciennes d'abord. Au premier refus, il
// signale le compte et n'appelle plus le journal de la passe : une panne lente se
// paie une fois, pas une fois par compte dû. Les convalescences s'inscrivent
// encore ; les fins attendent la passe suivante, où le compte déjà refusé passe
// en dernier — un compte que le journal refuse par son contenu ne bloque plus
// ceux qui le suivent.
$tete90 = membre($pdo, 'tete-90');
$suivant90 = membre($pdo, 'suivant-90');
foreach ([$tete90 => 30_000_000, $suivant90 => 29_000_000] as $compte90 => $recul90) {
    Moderate::ensureRow($pdo, $compte90);
    $pdo->prepare("UPDATE member_moderation
                      SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                          ban_origine = 'auto', ban_motif = 'reputation_zero'
                    WHERE account_id = ?")->execute([time() - $recul90, time() - $recul90 - 120, $compte90]);
    Moderate::migrerBans($pdo);
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
    public int $appels = 0;

    public function __construct(private int $refuse) {}

    public function inscrire(array $acte): void
    {
        $this->appels++;
        if ($acte['compte'] === $this->refuse) {
            throw new RuntimeException('acte refusé par son contenu');
        }
        $this->actes[] = $acte;
    }
};
Moderate::setJournal($journal90);
$passes90 = [];
try {
    for ($i90 = 1; $i90 <= 2; $i90++) {
        $appels90 = $journal90->appels;
        try {
            Moderate::balayerBansEchus($pdo);
            $erreur90 = null;
        } catch (RuntimeException $e90) {
            $erreur90 = $e90->getMessage();
        }
        $passes90[$i90] = [
            'leve'     => $erreur90 !== null,
            'appels'   => $journal90->appels - $appels90,
            'suivant'  => (int) etatBan($pdo, $suivant90)['banned_until'] === 0,
            'conv'     => (int) etatBan($pdo, $conv90)['reputation'],
        ];
    }
} finally {
    Moderate::setJournal($journalC);
}
$tenu90 = [(int) etatBan($pdo, $tete90)['banned_until'] > 0, in_array('journal_en_panne', causes($pdo, $tete90), true)];
rattraper($pdo, $tete90);
$passes90[1]['leve'] && $passes90[1]['appels'] === 1 && !$passes90[1]['suivant'] && $passes90[1]['conv'] === 5
    && $passes90[2]['leve'] && $passes90[2]['suivant'] && $tenu90 === [true, true]
    ? ok('⭐ un refus du journal coûte un appel par passe, laisse s\'inscrire les convalescences, et la passe suivante prend la file derrière le compte refusé')
    : nok('⭐ un refus du journal se paie par compte, ou bloque la file : ' . json_encode(['passes' => $passes90, 'tete' => $tenu90], JSON_UNESCAPED_UNICODE));

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
Moderate::migrerBans($pdo);
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
        new Config(Messagerie::DM, Usage::DEMONSTRATION, ...[$champ92 => $valeur92]);
    } catch (InvalidArgumentException) {
        $refuses92[] = $champ92;
    }
}
$eteint92 = (new Config(Messagerie::DM, Usage::DEMONSTRATION, balayageFiletMax: 0))->balayageFiletMax === 0;
$refuses92 === ['balayageFiletMax', 'bansArbitreSignal'] && $eteint92
    ? ok('⭐ un filet négatif et un signal au zéroième ban d\'arbitre sont refusés ; un filet à 0 s\'éteint')
    : nok('⭐ un seuil neuf accepte une valeur qui le vide de sens : ' . json_encode(['refuses' => $refuses92, 'eteint' => $eteint92]));

// ── 93. ⭐ Les seuils neufs suivent la config ─────────────────────────────────
// Comme les autres seuils du n° 25 : réglés autrement que par défaut, leur borne
// visée à la valeur réglée.
$enchaine93 = membre($pdo, 'enchaine-93');
poserReputation($pdo, $enchaine93, 20);
$signal93 = sousConfig(configBanc(bansArbitreSignal: 3), static function () use ($pdo, $enchaine93): array {
    $vus = [];
    for ($i = 1; $i <= 3; $i++) {
        Moderate::adminBan($pdo, $enchaine93, idDe($pdo, 'arbitre-banc'), motif());
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
    Moderate::migrerBans($pdo);
    planterSignal($pdo, $oublie93, 'ban_auto');
}
$pdo->exec('DELETE FROM mod_sweeps');
sousConfig(configBanc(balayageFiletMax: 1), static fn (): array =>
    Moderate::applyVote($pdo, membre($pdo, 'votant-93'), 'member', membre($pdo, 'vise-93'), -1, motif(), 'agressif'));
$inscrits93 = array_map(static fn (int $id): bool => (int) etatBan($pdo, $id)['banned_until'] === 0, $oublies93);
foreach ($oublies93 as $oublie93) {
    rattraper($pdo, $oublie93);
}
$inscrits93 === [true, false]
    ? ok('le filet inscrit au plus le nombre de comptes de la config, les plus anciens d\'abord')
    : nok('seuil sourd à la config (balayageFiletMax) : ' . json_encode($inscrits93));

// ── 94. ⭐ Dans la transaction d'un hôte, le vote prend le verrou avant de lire ─
// Une transaction différée qui a déjà lu ne peut plus attendre le verrou :
// SQLite la refuse aussitôt quand un autre écrit, et un vote valide échouerait
// là où il aurait attendu. Le premier ordre qu'un vote envoie dans la
// transaction d'un hôte est donc une écriture.
final class PdoQuiNote extends PDO
{
    /** @var list<string> */
    public array $ordres = [];

    public function exec(string $statement): int|false
    {
        $this->ordres[] = $statement;
        return parent::exec($statement);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->ordres[] = $query;
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->ordres[] = $query;
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}
$hote94 = new PdoQuiNote('sqlite:' . getenv('LAB_DB_PATH'));
$hote94->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$hote94->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$votant94 = membre($pdo, 'votant-94');
poserReputation($pdo, $votant94, 20);
$vise94 = membre($pdo, 'vise-94');
poserReputation($pdo, $vise94, 20);
$hote94->beginTransaction();
$vote94 = Moderate::applyVote($hote94, $votant94, 'member', $vise94, 1);
$hote94->commit();
$premier94 = $hote94->ordres[0] ?? '';
($vote94['ok'] ?? false) && str_starts_with($premier94, 'SAVEPOINT')
    ? ok('⭐ dans la transaction d\'un hôte, le premier ordre d\'un vote prend le verrou d\'écriture')
    : nok('⭐ dans la transaction d\'un hôte, un vote lit avant de prendre le verrou : ' . json_encode(['vote' => $vote94, 'ordres' => array_slice($hote94->ordres, 0, 3)], JSON_UNESCAPED_UNICODE));

// ── 95. ⭐ Un journal qui refuse par une exception ordinaire voit son refus contenu ─
// Seule l'erreur de la garde des écrivains traverse le rattrapage. Un journal
// conforme peut refuser un acte par n'importe quelle exception —
// `InvalidArgumentException` comprise, qui est une `LogicException` : le refus
// reste un refus, signalé, et le vote d'un autre sur ce compte passe.
$refuse95 = membre($pdo, 'refuse-95');
Moderate::ensureRow($pdo, $refuse95);
$pdo->prepare("UPDATE member_moderation
                  SET reputation = 12, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                      ban_origine = 'admin', ban_motif = 'motif d''arbitre'
                WHERE account_id = ?")->execute([time() - 10, time() - 130, $refuse95]);
Moderate::migrerBans($pdo);
Moderate::setJournal(new class implements \Pierroons\SelfModerate\Journal {
    public function inscrire(array $acte): void
    {
        throw new InvalidArgumentException('acte refusé par son contenu');
    }
});
try {
    $vote95 = Moderate::applyVote($pdo, membre($pdo, 'votant-95'), 'member', $refuse95, 1);
} catch (Throwable $t95) {
    $vote95 = ['exception' => get_class($t95) . ' : ' . $t95->getMessage()];
} finally {
    Moderate::setJournal($journalC);
}
$panne95 = in_array('journal_en_panne', causes($pdo, $refuse95), true);
rattraper($pdo, $refuse95);
($vote95['ok'] ?? false) && $panne95
    ? ok('⭐ un refus du journal par une LogicException ordinaire reste un refus : signalé, et le vote sur ce compte passe')
    : nok('⭐ un refus ordinaire du journal passe pour une erreur d\'intégration : ' . json_encode(['vote' => $vote95, 'panne' => $panne95], JSON_UNESCAPED_UNICODE));

// ── 96. ⭐ Le balayage inscrit le droit de vote rendu au seuil ─────────────────
// Des votes positifs portent un convalescent au seuil du vote : la lecture le
// montre aussitôt, et le balayage l'inscrit pour qui lit la base.
$porte96 = membre($pdo, 'porte-96');
poserReputation($pdo, $porte96, 5);
$pdo->prepare('UPDATE member_moderation SET convalescent = 1, voting_rights = 0, last_regen_at = ? WHERE account_id = ?')
    ->execute([time(), $porte96]);
planterSignal($pdo, $porte96, 'reputation_zero');
$erreur96 = null;
try {
    Moderate::balayerBansEchus($pdo);
} catch (Throwable $t96) {
    $erreur96 = $t96->getMessage();
}
$droit96 = (int) etatBan($pdo, $porte96)['voting_rights'];
$erreur96 === null && $droit96 === 1
    ? ok('⭐ le balayage inscrit le droit de vote qu\'un convalescent a retrouvé au seuil')
    : nok('⭐ le balayage laisse en base un droit de vote que la lecture rend : ' . json_encode(['erreur' => $erreur96, 'droit' => $droit96], JSON_UNESCAPED_UNICODE));

// ── 97. ⭐ La convalescence dit son rythme, celui de la config ───────────────
// La page de la personne en convalescence lui dit quand elle retrouve sa place.
// Le rythme vient de la config, comme les autres seuils : « un point par jour »
// écrit en dur lui annonçait vingt jours là où le service en compte cent quarante.
$enService97 = sousConfig(Config::prod(Messagerie::DM), static fn (): array => Moderate::regles());
$enDur97 = [];
foreach (['fr', 'en'] as $langue97) {
    $phrase97 = (require __DIR__ . "/../lang/$langue97.php")['prf.mod.conv.txt'] ?? '';
    if (!str_contains($phrase97, '%2$s') || preg_match('/\b(jours?|days?)\b/u', $phrase97)) {
        $enDur97[$langue97] = $phrase97;
    }
}
($enService97['convalescence'] ?? null) === '7 jours' && ($enService97['sortie'] ?? null) === 20 && $enDur97 === []
    ? ok('⭐ la page de convalescence dit le rythme de la config : un point par période de 7 jours en service')
    : nok('⭐ la convalescence annonce un rythme écrit en dur : ' . json_encode(['regles' => $enService97, 'en_dur' => $enDur97], JSON_UNESCAPED_UNICODE));

// ── 98. ⭐ Une durée se lit dans l'unité de sa grandeur, jamais plus courte ──
// Le texte annonce souvent une attente : l'arrondi va au-dessus. Une unité
// choisie sur la divisibilité rendait « 1440 minutes » pour une seconde de moins
// qu'un jour.
$attendus98 = [
    150 => '3 minutes', 3599 => '1 heure', 3600 => '1 heure', 5400 => '1 heure 30 minutes',
    86399 => '1 jour', 86400 => '1 jour', 90000 => '1 jour 1 heure', 604799 => '7 jours',
];
$ecarts98 = [];
foreach ($attendus98 as $secondes98 => $attendu98) {
    $lu98 = Moderate::dureeEnClair($secondes98);
    if ($lu98 !== $attendu98) {
        $ecarts98[$secondes98] = $lu98;
    }
}
$ecarts98 === []
    ? ok('⭐ une durée se dit dans l\'unité de sa grandeur, arrondie au-dessus : 86 399 s, « 1 jour »')
    : nok('⭐ une durée se lit dans une unité qui ne lui va pas : ' . json_encode($ecarts98, JSON_UNESCAPED_UNICODE));

// ── 99. ⭐ Sans la reprise, les listes de l'arbitre le disent ────────────────
// Elles lisent les tables sans passer par la ligne d'un compte : une case ouverte
// sans signal s'y lisait « rien à revoir », et un ban automatique sans « à revoir ».
$oublie99 = membre($pdo, 'oublie-99');
poserReputation($pdo, $oublie99, 20);
$pdo->prepare("UPDATE member_moderation SET needs_review = 1, review_reason = 'salve_rapide' WHERE account_id = ?")
    ->execute([$oublie99]);
$listes99 = [];
foreach (['bansEnCours'      => static fn (): array => Moderate::bansEnCours($pdo),
          'flaggedForReview' => static fn (): array => Moderate::flaggedForReview($pdo),
          'signauxOuverts'   => static fn (): array => Moderate::signauxOuverts($pdo)] as $nom99 => $lire99) {
    try {
        $lire99();
        $listes99[$nom99] = 'muette';
    } catch (\Pierroons\SelfModerate\RepriseManquante $e99) {
        $listes99[$nom99] = str_contains($e99->getMessage(), "compte $oublie99 ") && str_contains($e99->getMessage(), 'migrerSignaux')
            ? 'levée' : $e99->getMessage();
    }
}
reprendreLeCompte($pdo, $oublie99);
$apres99 = causes($pdo, $oublie99);
try {
    Moderate::flaggedForReview($pdo);
    $relue99 = true;
} catch (\Throwable) {
    $relue99 = false;
}
$listes99 === ['bansEnCours' => 'levée', 'flaggedForReview' => 'levée', 'signauxOuverts' => 'levée']
    && $apres99 === ['salve_rapide'] && $relue99
    ? ok('⭐ sans la reprise, les trois listes de l\'arbitre lèvent en nommant le compte et migrerSignaux() ; la reprise faite, il est à revoir')
    : nok('⭐ une liste de l\'arbitre tait une reprise oubliée : ' . json_encode(['listes' => $listes99, 'apres' => $apres99, 'relue' => $relue99], JSON_UNESCAPED_UNICODE));

// ── 100. ⭐ Le vote d'un tiers ne tombe pas sur la reprise oubliée d'un autre ──
// Le premier downvote d'une tranche balaie le site. Sur un compte que ce vote ne
// vise pas, une case ouverte sans signal ferait lever le balayage, et le vote
// tomberait avec sa tranche : tant que ce compte resterait dans une détection,
// aucun downvote du site ne passerait. Le compte est sauté, sa case intacte, et le balayage
// le nomme une fois, sous sa raison.
$fautif100 = membre($pdo, 'fautif-100');
poserReputation($pdo, $fautif100, 20);
foreach (['a', 'b', 'c'] as $n) {
    $pdo->prepare('INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, created_at)
                   VALUES (?, ?, ?, ?, -1, ?, ?)')
        ->execute([membre($pdo, "salve-$n-100"), 'member', $fautif100, $fautif100, motif(), time()]);
}
$pdo->prepare("UPDATE member_moderation SET needs_review = 1, review_reason = 'meute_recidive' WHERE account_id = ?")
    ->execute([$fautif100]);
$ailleurs100 = membre($pdo, 'ailleurs-100');
poserReputation($pdo, $ailleurs100, 20);
$votes100 = [];
foreach (['un', 'deux'] as $n) {
    $pdo->exec('DELETE FROM mod_sweeps');
    try {
        $votes100[$n] = (Moderate::applyVote($pdo, membre($pdo, "tiers-$n-100"), 'member', $ailleurs100, -1, motif(), 'agressif')['ok'] ?? false)
            ? 'passé' : 'refusé';
    } catch (\Throwable $e100) {
        $votes100[$n] = $e100::class;
    }
}
$tranche100  = (int) $pdo->query('SELECT COUNT(*) FROM mod_sweeps')->fetchColumn();
$case100     = $pdo->query("SELECT review_reason FROM member_moderation WHERE account_id = $fautif100")->fetchColumn();
$signaux100  = (int) $pdo->query("SELECT COUNT(*) FROM mod_signaux WHERE account_id = $fautif100")->fetchColumn();
try {
    $sautes100 = Moderate::detectPackVoting($pdo)['comptes_sautes'];
} catch (\Throwable $e100) {
    $sautes100 = $e100::class;
}
reprendreLeCompte($pdo, $fautif100);
$votes100 === ['un' => 'passé', 'deux' => 'passé'] && $tranche100 === 1 && $case100 === 'meute_recidive' && $signaux100 === 0
    && $sautes100 === [['compte' => $fautif100, 'raison' => 'reprise_manquante']]
    ? ok('⭐ un downvote qui balaie le site passe et prend sa tranche quand un autre compte attend sa reprise ; ce compte n\'est pas touché')
    : nok('⭐ la reprise oubliée d\'un compte fait tomber le vote d\'un tiers : ' . json_encode([
        'votes' => $votes100, 'tranche' => $tranche100, 'case' => $case100, 'signaux' => $signaux100, 'sautes' => $sautes100,
    ], JSON_UNESCAPED_UNICODE));

// ── 101. ⭐ Le balayage ne s'arrête pas sur une reprise oubliée ────────────────
// Il la saute, referme les peines qui la suivent, et la lève en fin de passe.
$journalAvant101 = Moderate::journal();
$journal101 = new JournalDeBanc();
Moderate::setJournal($journal101);
$avant101 = membre($pdo, 'avant-101');
$fautif101 = membre($pdo, 'fautif-101');
$apres101 = membre($pdo, 'apres-101');
foreach ([$avant101 => 300, $fautif101 => 200, $apres101 => 100] as $id101 => $recul101) {
    Moderate::ensureRow($pdo, $id101);
    $pdo->prepare("UPDATE member_moderation
                      SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                          ban_origine = 'auto', ban_motif = 'reputation_zero'
                    WHERE account_id = ?")->execute([time() - $recul101, time() - $recul101 - 120, $id101]);
    Moderate::migrerBans($pdo);
}
planterSignal($pdo, $avant101, 'ban_auto');
planterSignal($pdo, $apres101, 'ban_auto');
$pdo->prepare("UPDATE member_moderation SET needs_review = 1, review_reason = 'ban_auto' WHERE account_id = ?")
    ->execute([$fautif101]);
$leve101 = null;
try {
    Moderate::balayerBansEchus($pdo);
} catch (\LogicException $e101) {
    $leve101 = $e101->getMessage();
}
$fins101 = array_map(static fn (int $id): int => count($journal101->de($id, 'ban_fin')), [$avant101, $fautif101, $apres101]);
reprendreLeCompte($pdo, $fautif101);
try {
    Moderate::balayerBansEchus($pdo);
} catch (\Throwable $e101) {
    $leve101 .= ' — puis, après la reprise : ' . $e101->getMessage();
}
$repris101 = count($journal101->de($fautif101, 'ban_fin'));
Moderate::setJournal($journalAvant101);
$fins101 === [1, 0, 1] && str_contains((string) $leve101, "compte $fautif101 ") && $repris101 === 1
    ? ok('⭐ le balayage saute le compte qui attend sa reprise, referme les peines qui le suivent, et le nomme en fin de passe')
    : nok('⭐ une reprise oubliée arrête le balayage : ' . json_encode([
        'fins' => $fins101, 'levee' => $leve101, 'apres_reprise' => $repris101,
    ], JSON_UNESCAPED_UNICODE));

// ── 103. ⭐ Un compte refusé à chaque passe ne retient pas les signalés qui le suivent ─
// Les comptes déjà signalés passent en dernier, le plus anciennement refusé
// d'abord. Rangés par fin de peine, un compte que le journal refuse à chaque
// passe ouvrait le groupe, et son refus coupait tous ceux qui le suivaient. Le
// refusé est ici le plus anciennement signalé : il ne passe derrière les autres
// que parce que son refus le date.
$journalAvant103 = Moderate::journal();
$journal103 = new class implements \Pierroons\SelfModerate\Journal {
    public ?int $refuse = null;
    public JournalDeBanc $inscrits;

    public function __construct()
    {
        $this->inscrits = new JournalDeBanc();
    }

    public function inscrire(array $acte): void
    {
        if ($acte['compte'] === $this->refuse) {
            throw new InvalidArgumentException('acte refusé pour ce compte');
        }
        $this->inscrits->inscrire($acte);
    }
};
Moderate::setJournal($journal103);
$refuse103 = membre($pdo, 'refuse-103');
$signaleA103 = membre($pdo, 'signale-a-103');
$signaleB103 = membre($pdo, 'signale-b-103');
foreach ([$refuse103 => [500, 6000], $signaleA103 => [400, 3000], $signaleB103 => [300, 3000]] as $id103 => [$fin103, $refus103]) {
    Moderate::ensureRow($pdo, $id103);
    $pdo->prepare("UPDATE member_moderation
                      SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                          ban_origine = 'auto', ban_motif = 'reputation_zero'
                    WHERE account_id = ?")->execute([time() - $fin103, time() - $fin103 - 120, $id103]);
    Moderate::migrerBans($pdo);
    planterSignal($pdo, $id103, 'ban_auto');
    planterSignal($pdo, $id103, 'journal_en_panne', time() - $refus103);
}
$journal103->refuse = $refuse103;
$leves103 = [];
$fins103 = [];
foreach ([1, 2] as $passe103) {
    try {
        Moderate::balayerBansEchus($pdo);
        $leves103[] = null;
    } catch (\Throwable $e103) {
        $leves103[] = $e103->getMessage();
    }
    $fins103[] = array_map(static fn (int $id): int => count($journal103->inscrits->de($id, 'ban_fin')), [$refuse103, $signaleA103, $signaleB103]);
}
$journal103->refuse = null;
try {
    Moderate::balayerBansEchus($pdo);
} catch (\Throwable $e103) {
    $leves103[] = $e103->getMessage();
}
$repris103 = count($journal103->inscrits->de($refuse103, 'ban_fin'));
Moderate::setJournal($journalAvant103);
$fins103 === [[0, 0, 0], [0, 1, 1]] && count(array_filter($leves103, static fn (?string $l): bool => str_contains((string) $l, "compte $refuse103"))) === 2
    && $repris103 === 1
    ? ok('⭐ les comptes signalés derrière un compte refusé à chaque passe se referment dès la passe suivante ; lui attend son tour')
    : nok('⭐ un compte refusé à chaque passe retient les signalés qui le suivent : ' . json_encode([
        'fins' => $fins103, 'levees' => $leves103, 'repris' => $repris103,
    ], JSON_UNESCAPED_UNICODE));

// ── 104. ⭐ Dans la transaction d'un hôte, un vote refusé ne crée pas la ligne du votant ─
$hote104 = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
$hote104->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$hote104->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$sansLigne104 = membre($pdo, 'sans-ligne-104');
$cible104 = membre($pdo, 'cible-104');
poserReputation($pdo, $cible104, 20);
$hote104->beginTransaction();
$vote104 = Moderate::applyVote($hote104, $sansLigne104, 'member', $cible104, -1, 'court', 'agressif');
$lignes104 = (int) $hote104->query("SELECT COUNT(*) FROM member_moderation WHERE account_id = $sansLigne104")->fetchColumn();
$hote104->rollBack();
!($vote104['ok'] ?? true) && $lignes104 === 0
    ? ok('⭐ dans la transaction d\'un hôte, un vote refusé n\'écrit pas la ligne de son votant')
    : nok('⭐ un vote refusé écrit la ligne de son votant : ' . json_encode(['vote' => $vote104, 'lignes' => $lignes104], JSON_UNESCAPED_UNICODE));

// ── 105. ⭐ Le balayage refuse la transaction d'un hôte, sans rien inscrire ───
// Ses fins s'inscriraient au journal, qui ne se défait pas, et survivraient à
// l'annulation que l'hôte décide : la passe suivante les réinscrirait.
$journalAvant105 = Moderate::journal();
$journal105 = new JournalDeBanc();
Moderate::setJournal($journal105);
$echu105 = membre($pdo, 'echu-105');
Moderate::ensureRow($pdo, $echu105);
$pdo->prepare("UPDATE member_moderation
                  SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                      ban_origine = 'auto', ban_motif = 'reputation_zero'
                WHERE account_id = ?")->execute([time() - 10, time() - 130, $echu105]);
Moderate::migrerBans($pdo);
planterSignal($pdo, $echu105, 'ban_auto');
$hote105 = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
$hote105->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$hote105->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$hote105->beginTransaction();
try {
    Moderate::balayerBansEchus($hote105);
    $issue105 = 'passé';
} catch (\LogicException $e105) {
    $issue105 = str_contains($e105->getMessage(), 'transaction de l\'hôte') ? 'refusé' : $e105->getMessage();
}
$hote105->rollBack();
$dansHote105 = count($journal105->de($echu105, 'ban_fin'));
try {
    Moderate::balayerBansEchus($pdo);
} catch (\Throwable $e105) {
    $issue105 .= ' — puis, hors de la transaction : ' . $e105->getMessage();
}
$horsHote105 = count($journal105->de($echu105, 'ban_fin'));
Moderate::setJournal($journalAvant105);
$issue105 === 'refusé' && $dansHote105 === 0 && $horsHote105 === 1
    ? ok('⭐ le balayage refuse la transaction d\'un hôte sans rien inscrire ; hors d\'elle, la peine se referme une fois')
    : nok('⭐ le balayage inscrit des fins dans la transaction d\'un hôte : ' . json_encode([
        'issue' => $issue105, 'dans' => $dansHote105, 'hors' => $horsHote105,
    ], JSON_UNESCAPED_UNICODE));

// ── 106. ⭐ Une cause illisible ne ferme pas les gestes de l'arbitre ─────────
// Avec un journal qui encode strictement, comme celui du lab : classer ce signal,
// bannir ce compte, et refermer une peine sans motif en base (forme d'avant la
// 0.4.0, dont la fin reprend la cause) passent, la cause remplacée dans l'acte.
$journalAvant106 = Moderate::journal();
$journal106 = new JournalDeBanc();
Moderate::setJournal($journal106);
$illisible106 = "ancien_\xE9tat";
$aClasser106 = membre($pdo, 'classer-106');
poserReputation($pdo, $aClasser106, 20);
planterSignal($pdo, $aClasser106, $illisible106);
$signal106 = array_values(array_filter(Moderate::getReputation($pdo, $aClasser106)['signaux'] ?? [],
    static fn (array $s): bool => $s['cause'] === $illisible106))[0] ?? ['signal' => 0, 'detections' => 0];
$gestes106 = [];
foreach (['classer' => static fn (): array => Moderate::adminClasser($pdo, $aClasser106, idDe($pdo, 'arbitre-banc'), motif(),
              ['signal' => $signal106['signal'], 'detections' => $signal106['detections']]),
          'bannir'  => static function () use ($pdo, $illisible106): array {
              $banni = membre($pdo, 'bannir-106');
              poserReputation($pdo, $banni, 15);
              planterSignal($pdo, $banni, $illisible106);

              return Moderate::adminBan($pdo, $banni, idDe($pdo, 'arbitre-banc'), motif());
          }] as $geste106 => $faire106) {
    try {
        $gestes106[$geste106] = ($faire106()['ok'] ?? false) ? 'passé' : 'refusé';
    } catch (\Throwable $e106) {
        $gestes106[$geste106] = $e106::class;
    }
}
$sansMotif106 = membre($pdo, 'sans-motif-106');
Moderate::ensureRow($pdo, $sansMotif106);
$pdo->prepare("UPDATE member_moderation
                  SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                      ban_origine = 'auto', ban_motif = NULL
                WHERE account_id = ?")->execute([time() - 10, time() - 130, $sansMotif106]);
Moderate::migrerBans($pdo);
planterSignal($pdo, $sansMotif106, $illisible106);
planterSignal($pdo, $sansMotif106, 'ban_auto');
rattraper($pdo, $sansMotif106);
$fin106 = $journal106->de($sansMotif106, 'ban_fin');
$panne106 = in_array('journal_en_panne', causes($pdo, $sansMotif106), true);
$raison106 = $journal106->de($aClasser106, 'revue_classee')[0]['raison'] ?? '';
Moderate::setJournal($journalAvant106);
$gestes106 === ['classer' => 'passé', 'bannir' => 'passé'] && count($fin106) === 1 && !$panne106
    && str_contains($raison106, "\u{FFFD}")
    ? ok('⭐ avec un journal strict, classer et bannir passent sur une cause illisible, et une peine sans motif se referme ; l\'acte porte le caractère de remplacement')
    : nok('⭐ une cause illisible ferme un geste de l\'arbitre, ou fige une peine : ' . json_encode([
        'gestes' => $gestes106, 'fins' => count($fin106), 'panne' => $panne106,
    ], JSON_UNESCAPED_UNICODE));

/**
 * Un serveur du lab sur l'instance du banc, pour jouer une route comme un client.
 * Les prises sont celles que le banc a reposées, pas celles de la naissance de
 * l'instance : la base du banc n'est pas celle qu'`instance_isolee()` a nommée.
 *
 * @param array<string, string> $prises celles qui changent pour ce serveur
 * @return array{proc: resource, port: int, debout: bool}
 */
function serveurLab(array $prises = []): array
{
    // Un port que le système vient de rendre libre : deux bancs lancés ensemble ne
    // se répondent pas l'un à l'autre.
    $libre = stream_socket_server('tcp://127.0.0.1:0', $codeErreur, $erreur);
    $port = $libre !== false ? (int) substr((string) strrchr((string) stream_socket_get_name($libre, false), ':'), 1) : 0;
    if ($libre !== false) {
        fclose($libre);
    }
    $env = [];
    foreach (array_keys(instance_isolee()) as $prise) {
        $env[$prise] = (string) getenv($prise);
    }
    $proc = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', __DIR__ . '/../public'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $tuyaux,
        null,
        array_merge($env, $prises)
    );
    $debout = false;
    for ($i = 0; $i < 50 && !$debout && $port > 0; $i++) {
        $sonde = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if ($sonde) {
            fclose($sonde);
            // Le serveur qui répond est le nôtre seulement s'il tourne encore : pris
            // entre-temps, le port l'aurait fait sortir.
            $debout = proc_get_status($proc)['running'];
            break;
        }
        usleep(100000);
    }

    return ['proc' => $proc, 'port' => $port, 'debout' => $debout];
}

function arreterLab(array $serveur): void
{
    proc_terminate($serveur['proc']);
    proc_close($serveur['proc']);
}

/**
 * Une requête au serveur du lab : son code et son corps. Avec un jeton de session,
 * la session qu'il ouvre ; avec un jeton CSRF en plus, un POST, dont `$json` est le corps.
 *
 * @param array<string, mixed> $json
 * @return array{code: int, corps: string}
 */
function requeteLab(array $serveur, string $chemin, ?string $session = null, ?string $csrf = null, array $json = []): array
{
    if (!$serveur['debout']) {
        return ['code' => 0, 'corps' => ''];
    }
    $entetes = $session !== null ? ['Cookie: lab_session=' . $session] : [];
    $http = ['ignore_errors' => true, 'timeout' => 10];
    if ($csrf !== null) {
        $entetes[] = 'X-CSRF-Token: ' . $csrf;
        $entetes[] = 'Content-Type: application/json';
        $http += ['method' => 'POST', 'content' => $json === [] ? '{}' : json_encode($json, JSON_THROW_ON_ERROR)];
    }
    $http['header'] = implode("\r\n", $entetes);
    $corps = (string) @file_get_contents('http://127.0.0.1:' . $serveur['port'] . $chemin, false, stream_context_create(['http' => $http]));

    return ['code' => (int) (explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1] ?? 0), 'corps' => $corps];
}

/** Une session d'arbitre, posée en base comme la connexion la pose : rend le jeton du cookie. */
function sessionArbitre(PDO $pdo, string $nom): string
{
    return sessionMembre($pdo, $nom, arbitre: true);
}

/** Une session de membre, posée en base comme la connexion la pose : rend le jeton du cookie. */
function sessionMembre(PDO $pdo, string $nom, bool $arbitre = false): string
{
    $id = membre($pdo, $nom);
    if ($arbitre) {
        $pdo->prepare('UPDATE accounts SET is_admin = 1 WHERE id = ?')->execute([$id]);
    }
    // La forme qu'`Auth::estJeton()` accepte : 24 octets en hexadécimal.
    $jeton = bin2hex(random_bytes(24));
    $pdo->prepare('INSERT INTO app_sessions (account_id, token, created_at) VALUES (?, ?, ?)')
        ->execute([$id, Db::empreinteJeton($jeton), time()]);

    return $jeton;
}

/** Le jeton CSRF qu'une page rend au navigateur. */
function csrfDeLaPage(string $corps): ?string
{
    return preg_match('/window\.LAB_CSRF = "([^"]+)"/', $corps, $m) === 1 ? $m[1] : null;
}

// ── 107. ⭐ Une page qui lève après son en-tête rend un 500, pas une page coupée ─
// Par HTTP, comme le visiteur. Le profil public lit la réputation après avoir écrit
// son en-tête : sous la garde de la reprise, la page partait en 200, coupée à cet
// endroit, l'erreur au milieu. Un `PATH_INFO` qui glisse `/api/` dans l'URI n'en
// fait pas une route d'API.
$coupe107 = membre($pdo, 'page-coupee-107');
poserReputation($pdo, $coupe107, 20);
$pdo->prepare("UPDATE member_moderation SET needs_review = 1, review_reason = 'salve_rapide' WHERE account_id = ?")
    ->execute([$coupe107]);
$session108 = sessionArbitre($pdo, 'arbitre-108');
$serveur107 = serveurLab();
$pages107 = [];
foreach (['/profile.php?u=page-coupee-107', '/profile.php/api/?u=page-coupee-107'] as $chemin107) {
    $pages107[$chemin107] = requeteLab($serveur107, $chemin107);
}
$coupees107 = array_keys(array_filter($pages107, static fn (array $r): bool =>
    $r['code'] !== 500 || !str_starts_with($r['corps'], '<!doctype html><meta charset="utf-8"><title>500</title>')));
$serveur107['debout'] && $coupees107 === []
    ? ok('⭐ une page qui lève après son en-tête rend un 500 et la page d\'erreur seule, sans le début de la page, `/api/` glissé dans son chemin compris')
    : nok('⭐ une page qui lève après son en-tête part coupée : ' . json_encode([
        'serveur' => $serveur107['debout'],
        'coupees' => array_map(static fn (string $c): array => [$c, $pages107[$c]['code'], substr($pages107[$c]['corps'], 0, 80)], $coupees107),
    ], JSON_UNESCAPED_UNICODE));

// ── 108. ⭐ Sous une reprise oubliée, la console ne dit pas « aucun ban » ─────
// La bannière dit que les listes ne peuvent pas s'afficher : la même page ne dit
// pas, en dessous, qu'il n'y a ni ban ni signalement.
$console108 = requeteLab($serveur107, '/admin.php', $session108);
arreterLab($serveur107);
reprendreLeCompte($pdo, $coupe107);
$console108['code'] === 200 && str_contains($console108['corps'], 'Les bans et les signalements ne peuvent pas s\'afficher')
    && !str_contains($console108['corps'], 'Aucun ban en cours.') && !str_contains($console108['corps'], 'Aucun signalement en attente.')
    ? ok('⭐ sous une reprise oubliée, la console dit pourquoi ses listes manquent, et ne les dit pas vides')
    : nok('⭐ la console dit la reprise oubliée, puis « aucun ban » sur la même page : ' . json_encode([
        'code' => $console108['code'],
        'banniere' => str_contains($console108['corps'], 'ne peuvent pas s\'afficher'),
        'aucun_ban' => str_contains($console108['corps'], 'Aucun ban en cours.'),
        'aucun_signal' => str_contains($console108['corps'], 'Aucun signalement en attente.'),
    ], JSON_UNESCAPED_UNICODE));

/**
 * Une proie de salve que seul un balayage du site découvre : trois votes posés en
 * base, sans passer par le moteur, sur un compte que le vote qui balaie ne vise pas.
 */
function proieDeSalve(PDO $pdo, string $nom): int
{
    $proie = membre($pdo, 'proie-' . $nom);
    poserReputation($pdo, $proie, 20);
    $post = message($pdo, $proie, 'fil-proie-' . $nom);
    foreach (['a', 'b', 'c'] as $l) {
        voteBrut($pdo, membre($pdo, "salve-$l-$nom"), $post, $proie);
    }

    return $proie;
}

/** Le premier vote négatif d'une tranche, sur un compte sans histoire : il balaie le site. */
function voteQuiBalaie(PDO $pdo, string $nom): string
{
    $ailleurs = membre($pdo, 'ailleurs-' . $nom);
    poserReputation($pdo, $ailleurs, 20);
    $pdo->exec('DELETE FROM mod_sweeps');
    try {
        return (Moderate::applyVote($pdo, membre($pdo, 'tiers-' . $nom), 'member', $ailleurs, -1, motif(), 'agressif')['ok'] ?? false)
            ? 'passé' : 'refusé';
    } catch (\Throwable $e) {
        return $e::class . ' : ' . $e->getMessage();
    }
}

/** Le balayage du planificateur ; une exception qui en sort se rend, pour que le banc rougisse au lieu de s'arrêter. */
function balayerLeSite(PDO $pdo): array
{
    try {
        return Moderate::detectPackVoting($pdo);
    } catch (\Throwable $e) {
        return ['erreur' => $e::class . ' : ' . $e->getMessage()];
    }
}

// ── 110. ⭐ Une erreur qui défait une transaction du moteur remonte telle quelle ─
// `SQLITE_FULL` défait toute la transaction, point de reprise compris. Contenue,
// le vote poursuivrait hors transaction en s'y croyant, et tomberait plus loin sur
// une autre erreur. Elle remonte, et la base reste comme avant le vote. Le
// planificateur n'a pas d'hôte : la transaction défaite est la sienne, et ce
// n'est pas un refus non plus. La base se défait ici sur le signal d'une salve
// qu'un balayage découvre sur un autre compte.
$proie110 = proieDeSalve($pdo, '110');
$pdo->exec("CREATE TRIGGER defaite110 BEFORE INSERT ON mod_signaux WHEN NEW.account_id = $proie110
            BEGIN SELECT RAISE(ROLLBACK, 'base défaite au banc'); END");
$votesAvant110 = (int) $pdo->query('SELECT COUNT(*) FROM mod_votes')->fetchColumn();
$vote110 = voteQuiBalaie($pdo, '110');
$votesApres110 = (int) $pdo->query('SELECT COUNT(*) FROM mod_votes')->fetchColumn();
$pdo->exec('DROP TRIGGER defaite110');
Moderate::detectPackVoting($pdo);
$proiePlanif110 = proieDeSalve($pdo, '110-planif');
$pdo->exec("CREATE TRIGGER defaitePlanif110 BEFORE INSERT ON mod_signaux WHEN NEW.account_id = $proiePlanif110
            BEGIN SELECT RAISE(ROLLBACK, 'base défaite au planificateur'); END");
$planif110 = balayerLeSite($pdo);
$pdo->exec('DROP TRIGGER defaitePlanif110');
Moderate::detectPackVoting($pdo);
str_contains($vote110, 'base défaite au banc') && $votesApres110 === $votesAvant110
    && str_contains($planif110['erreur'] ?? '', 'base défaite au planificateur')
    ? ok('⭐ une erreur qui défait une transaction du moteur remonte telle quelle, au vote comme au planificateur, et le vote ne laisse rien')
    : nok('⭐ le balayage avale une erreur qui a défait la transaction du vote, ou celle du planificateur : ' . json_encode([
        'vote' => $vote110, 'votes_avant' => $votesAvant110, 'votes_apres' => $votesApres110,
        'planificateur' => $planif110['erreur'] ?? $planif110['comptes_sautes'] ?? null,
    ], JSON_UNESCAPED_UNICODE));

// ── 114. ⭐ Un motif qui n'est pas du texte se refuse avant la base ───────────
// Le validateur est un écrivain de plus du motif : des octets hors UTF-8 se
// refusent là, avant que la base les garde et que le journal les substitue.
$journalAvant114 = Moderate::journal();
Moderate::setJournal(new JournalDeBanc());
$illisible114 = 'Il a répondu plut' . "\xF4" . 't que de lire, trois fois de suite ce soir sur le forum.';
[$valide114, $pourquoi114] = Moderate::validateReason($illisible114);
$cible114 = membre($pdo, 'motif-illisible-114');
$ban114 = Moderate::adminBan($pdo, $cible114, idDe($pdo, 'arbitre-114'), $illisible114);
Moderate::setJournal($journalAvant114);
$valide114 === false && ($ban114['ok'] ?? true) === false && ($ban114['message'] ?? '') === $pourquoi114
    && !Moderate::estBanni($pdo, $cible114)
    ? ok('⭐ un motif qui n\'est pas de l\'UTF-8 valide est refusé, au validateur comme au ban d\'arbitre')
    : nok('⭐ un motif illisible passe le validateur, et la base garde ses octets : ' . json_encode([
        'valide' => $valide114, 'ban' => $ban114['ok'] ?? null, 'banni' => Moderate::estBanni($pdo, $cible114),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

// ── 115. ⭐ Le planificateur nomme ce qu'il saute, et dit une panne comme une panne ─
// Par la route, avec une session d'arbitre. Le signal d'une salve que la base
// refuse sur un compte se nomme dans `comptes_sautes`. Une `PDOException` du
// balayage des fins n'est pas un refus du journal : elle ne se range pas dans
// `fins_en_attente` avec `ok: true` et le message SQL, elle rend le 500 du lab.
$proie115 = proieDeSalve($pdo, '115');
$pdo->exec("CREATE TRIGGER refus115 BEFORE INSERT ON mod_signaux WHEN NEW.account_id = $proie115
            BEGIN SELECT RAISE(ABORT, 'signal refusé au banc'); END");
$serveurRefus115 = serveurLab();
$sessionRefus115 = sessionArbitre($pdo, 'arbitre-refus-115');
$csrfRefus115 = csrfDeLaPage(requeteLab($serveurRefus115, '/admin.php', $sessionRefus115)['corps']);
$sautes115 = $csrfRefus115 !== null ? requeteLab($serveurRefus115, '/api/detect_abuse.php', $sessionRefus115, $csrfRefus115) : ['code' => 0, 'corps' => ''];
// La page qui lance le planificateur dit ce qu'il a sauté.
$pageLit115 = str_contains(requeteLab($serveurRefus115, '/moderation.php', $sessionRefus115)['corps'], 'd.comptes_sautes');
arreterLab($serveurRefus115);
$pdo->exec('DROP TRIGGER refus115');
Moderate::detectPackVoting($pdo);
$nommes115 = array_values(array_filter(json_decode($sautes115['corps'], true)['comptes_sautes'] ?? [], static fn (array $sa): bool =>
    $sa['compte'] === $proie115 && $sa['raison'] === 'refus'));
$session115 = sessionArbitre($pdo, 'arbitre-115');
$echu115 = membre($pdo, 'echu-115');
Moderate::ensureRow($pdo, $echu115);
$pdo->prepare("UPDATE member_moderation SET banned_until = ?, ban_debut = ?, ban_origine = 'admin', ban_motif = 'motif du banc'
                WHERE account_id = ?")->execute([time() - 10, time() - 100, $echu115]);
Moderate::migrerBans($pdo);
$pdo->exec("CREATE TRIGGER panne115 BEFORE UPDATE ON member_moderation WHEN OLD.account_id = $echu115
            BEGIN SELECT RAISE(ABORT, 'disque plein simulé'); END");
$serveur115 = serveurLab();
$csrf115 = csrfDeLaPage(requeteLab($serveur115, '/admin.php', $session115)['corps']);
$planif115 = $csrf115 !== null ? requeteLab($serveur115, '/api/detect_abuse.php', $session115, $csrf115) : ['code' => 0, 'corps' => ''];
arreterLab($serveur115);
$pdo->exec('DROP TRIGGER panne115');
$pdo->prepare('UPDATE member_moderation SET banned_until = 0, ban_debut = 0, ban_origine = NULL, ban_motif = NULL WHERE account_id = ?')
    ->execute([$echu115]);
$pdo->prepare('DELETE FROM mod_peines WHERE account_id = ? AND fin IS NULL')->execute([$echu115]);
$reponse115 = json_decode($planif115['corps'], true);
$sautes115['code'] === 200 && count($nommes115) === 1 && $pageLit115
    && $planif115['code'] === 500 && ($reponse115['ok'] ?? null) === false && !str_contains($planif115['corps'], 'SQLSTATE')
    ? ok('⭐ le planificateur nomme le compte dont la base refuse le signal, et une panne de la base rend son 500, pas un « ok » qui la range parmi les refus du journal')
    : nok('⭐ le planificateur tait un compte sauté, ou dit une panne de la base comme un refus du journal : ' . json_encode([
        'sautes' => [$sautes115['code'], substr($sautes115['corps'], 0, 160)], 'page' => $pageLit115,
        'csrf' => $csrf115 !== null, 'code' => $planif115['code'], 'debut' => substr($planif115['corps'], 0, 160),
    ], JSON_UNESCAPED_UNICODE));

// ── 128. ⭐ Un votant que l'hôte ne connaît pas, ou dont la date est illisible, ne vote pas ─
// Pour un compte absent, `fetchColumn()` rend `false`, que `(int)` change en 0 ;
// d'une date ISO, `(int)` garde l'année : les deux paraissaient très anciens et
// passaient l'anti-Sybil.
$cible128   = membre($pdo, 'cible-128');
$avant128   = reputation($pdo, $cible128);
$fantome128 = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM accounts')->fetchColumn();
$peut128    = Moderate::canVote($pdo, $fantome128);
try {
    $vote128 = Moderate::applyVote($pdo, $fantome128, 'member', $cible128, -1, motif(), 'agressif');
} catch (Throwable $t128) {
    $vote128 = ['exception' => get_class($t128)];
}
$traces128  = (int) $pdo->query("SELECT COUNT(*) FROM mod_votes WHERE voter_id = $fantome128")->fetchColumn();
$iso128 = membre($pdo, 'iso-128');
$pdo->prepare('UPDATE accounts SET created_at = ? WHERE id = ?')->execute([date('Y-m-d\TH:i:s', time() - 60), $iso128]);
$peutIso128 = Moderate::canVote($pdo, $iso128);
try {
    $voteIso128 = Moderate::applyVote($pdo, $iso128, 'member', $cible128, -1, motif(), 'agressif');
} catch (Throwable $t128) {
    $voteIso128 = ['exception' => get_class($t128)];
}
$peut128 === [false, 'Compte introuvable.'] && ($vote128['ok'] ?? true) === false && $traces128 === 0
    && $peutIso128[0] === false && str_contains($peutIso128[1], 'illisible') && ($voteIso128['ok'] ?? true) === false
    && reputation($pdo, $cible128) === $avant128
    ? ok('un votant inconnu de l\'hôte, ou à la date illisible, est refusé au vote comme à canVote(), sans rien inscrire')
    : nok('un votant fantôme ou mal daté vote : ' . json_encode([$peut128, $vote128, $traces128, $peutIso128, $voteIso128, reputation($pdo, $cible128), $avant128]));

// ── 129. ⭐ Une table `dm` absente se nomme ; une `dm` présente qui échoue garde son erreur ─
// Le quorum lit les liens quand une chute passe un seuil : sans cette garde, la
// chute tomberait sur « no such table: dm ».
$cible129 = membre($pdo, 'cible-129');
poserReputation($pdo, $cible129, 6);
$v129 = [membre($pdo, 'votant-129-a'), membre($pdo, 'votant-129-b'), membre($pdo, 'votant-129-c')];
[$absente129, $cassee129] = sousConfig(configBanc(quorumAuxSeuils: true), static function () use ($pdo, $cible129, $v129): array {
    $absente = null;
    Moderate::applyVote($pdo, $v129[0], 'member', $cible129, -1, motif(), 'agressif');
    $pdo->exec('ALTER TABLE dm RENAME TO dm_ailleurs_129');
    try {
        Moderate::applyVote($pdo, $v129[1], 'member', $cible129, -1, motif(), 'agressif');
    } catch (Throwable $t) {
        $absente = $t;
    } finally {
        $pdo->exec('ALTER TABLE dm_ailleurs_129 RENAME TO dm');
    }
    $cassee = null;
    $pdo->exec('CREATE TEMP VIEW dm AS SELECT 1 AS sender_id, 1 AS recipient_id');
    try {
        Moderate::applyVote($pdo, $v129[2], 'member', $cible129, -1, motif(), 'agressif');
    } catch (Throwable $t) {
        $cassee = $t;
    } finally {
        $pdo->exec('DROP VIEW IF EXISTS temp.dm');
    }

    return [$absente, $cassee];
});
$absente129 instanceof LogicException && str_contains($absente129->getMessage(), '« dm »')
    && $absente129->getPrevious() instanceof PDOException
    && $cassee129 instanceof PDOException && !$pdo->inTransaction() && reputation($pdo, $cible129) === 5
    ? ok('une table dm absente lève une erreur qui la nomme ; une dm qui existe et échoue garde son erreur de base ; la chute ne passe pas')
    : nok('dm absente muette, ou erreur d\'une dm présente maquillée : ' . json_encode([
        $absente129 === null ? null : [get_class($absente129), $absente129->getMessage()],
        $cassee129 === null ? null : [get_class($cassee129), $cassee129->getMessage()],
        reputation($pdo, $cible129),
    ]));

// ── 130. ⭐ Le ban dû tombe avec la chute à la sortie de convalescence ─────
$journalAvant130 = Moderate::journal();
Moderate::setJournal(new JournalEnPanne());
$chute130 = membre($pdo, 'chute-130');
poserReputation($pdo, $chute130, 1);
try {
    Moderate::applyVote($pdo, membre($pdo, 'votant-130'), 'member', $chute130, -1, motif(), 'agressif');
} catch (Throwable) {
}
$avant130 = causes($pdo, $chute130);
Moderate::setJournal($journalAvant130);
$pdo->prepare('UPDATE member_moderation SET last_regen_at = last_regen_at - ? WHERE account_id = ?')
    ->execute([40 * Moderate::config()->intervalleConvalescenceSecondes, $chute130]);
rattraper($pdo, $chute130);
$apres130 = causes($pdo, $chute130);
in_array('ban_en_attente', $avant130, true) && in_array('reputation_zero', $avant130, true)
    && !in_array('ban_en_attente', $apres130, true) && !in_array('reputation_zero', $apres130, true)
    && reputation($pdo, $chute130) === Moderate::config()->sortieConvalescence()
    ? ok('la sortie de convalescence ferme le ban dû avec la chute')
    : nok('le ban dû survit à la convalescence : ' . json_encode([$avant130, $apres130, reputation($pdo, $chute130)]));

// ── 132. ⭐ Un refus ordinaire du journal au début du ban est contenu, comme un autre ─
// Un journal conforme peut refuser par n'importe quelle exception, `LogicException`
// comprise : seule `LectureDansUneEcriture` est une erreur d'intégration.
$journalAvant132 = Moderate::journal();
Moderate::setJournal(new class implements \Pierroons\SelfModerate\Journal {
    public function inscrire(array $acte): void
    {
        if ($acte['acte'] === 'ban_debut') {
            throw new LogicException('refus ordinaire');
        }
    }
});
$cible132 = membre($pdo, 'cible-132');
poserReputation($pdo, $cible132, 1);
try {
    $vote132 = Moderate::applyVote($pdo, membre($pdo, 'votant-132'), 'member', $cible132, -1, motif(), 'agressif');
} catch (Throwable $t132) {
    $vote132 = ['exception' => get_class($t132) . ' : ' . $t132->getMessage()];
}
Moderate::setJournal($journalAvant132);
($vote132['ok'] ?? false) && (int) etatBan($pdo, $cible132)['banned_until'] === 0
    && in_array('ban_en_attente', causes($pdo, $cible132), true)
    ? ok('un refus ordinaire du journal au début du ban est contenu : le vote tient, le ban dû se signale')
    : nok('un refus ordinaire du journal au début du ban passe pour une erreur d\'intégration : ' . json_encode([$vote132, causes($pdo, $cible132)]));

// ── 133. ⭐ En service, la réputation au plancher se signale, rien ne bannit ─
// L'usage décide seul. Journal branché, sept critiques sans lien font le quorum du
// ban — en démonstration, ils bannissent (172) : en service, la chute se signale
// (`reputation_zero`), le vote reste, rien ne s'inscrit au journal, et
// `banAutomatiqueArme()` le dit. La perte du vote, due depuis le seuil, reste en base
// hors de la file : la chute au plancher y prend sa place.
$chute133 = membre($pdo, 'chute-133');
poserReputation($pdo, $chute133, 20);
$sept133 = membres($pdo, 'critique-133', 7);
try {
    $service133 = sousJournal(static fn (JournalDeBanc $j): array => sousConfig(configService(), static function () use ($pdo, $chute133, $sept133, $j): array {
        frapper($pdo, $chute133, $sept133, 3, '133');

        return ['arme' => Moderate::banAutomatiqueArme(), 'etat' => bilan($pdo, $chute133), 'actes' => array_column($j->actes, 'acte')];
    }));
} catch (Throwable $t133) {
    $service133 = ['exception' => get_class($t133) . ' : ' . $t133->getMessage()];
}
$armeDemo133 = sousJournal(static fn (): bool => Moderate::banAutomatiqueArme());
($service133['arme'] ?? true) === false && $armeDemo133
    && ($service133['etat']['rep'] ?? null) === 0 && !$service133['etat']['banni'] && $service133['etat']['vote']
    && array_values(array_diff($service133['etat']['file'], ['salve_rapide'])) === ['reputation_zero']
    && in_array('perte_du_vote_due', $service133['etat']['causes'], true)
    && $service133['actes'] === []
    ? ok('en service, la réputation au plancher est signalée sans ban ni retrait du vote, rien ne s\'inscrit, et banAutomatiqueArme() le dit')
    : nok('seuil sourd à la config (usage) : ' . json_encode([$service133, $armeDemo133], JSON_UNESCAPED_UNICODE));

// ── 134. ⭐ La perte du droit de vote se signale, et tombe quand le droit revient ─
// En démonstration, le vote se retire et la perte se signale (`droit_de_vote_perdu`) ;
// en service, six critiques sans lien font le quorum du vote, qui reste, et la perte se
// signale due (`perte_du_vote_due`). L'une et l'autre tombent quand la réputation
// repasse le seuil, et cèdent la place à la chute au plancher.
$perte134 = membre($pdo, 'perte-134');
poserReputation($pdo, $perte134, 5);
Moderate::applyVote($pdo, membre($pdo, 'votant-134-a'), 'member', $perte134, -1, motif(), 'agressif');
$pendant134 = causes($pdo, $perte134);
$pdo->prepare('UPDATE member_moderation SET last_regen_at = last_regen_at - ? WHERE account_id = ?')
    ->execute([Moderate::config()->intervalleConvalescenceSecondes, $perte134]);
rattraper($pdo, $perte134);
$apres134 = causes($pdo, $perte134);
$du134 = membre($pdo, 'du-134');
poserReputation($pdo, $du134, 10);
$six134 = membres($pdo, 'critique-134', 6);
try {
    $service134 = sousConfig(configService(), static function () use ($pdo, $du134, $six134): array {
        frapper($pdo, $du134, $six134, 1, '134');
        $pendant = bilan($pdo, $du134);
        $pdo->prepare('UPDATE member_moderation SET last_regen_at = last_regen_at - ? WHERE account_id = ?')
            ->execute([Moderate::config()->intervalleConvalescenceSecondes, $du134]);
        rattraper($pdo, $du134);

        return [$pendant, bilan($pdo, $du134)];
    });
} catch (Throwable $t134) {
    $service134 = ['exception' => get_class($t134) . ' : ' . $t134->getMessage()];
}
$plancher134 = membre($pdo, 'plancher-134');
poserReputation($pdo, $plancher134, 2);
Moderate::applyVote($pdo, membre($pdo, 'votant-134-c'), 'member', $plancher134, -1, motif(), 'agressif');
$avantPlancher134 = causes($pdo, $plancher134);
Moderate::applyVote($pdo, membre($pdo, 'votant-134-d'), 'member', $plancher134, -1, motif(), 'agressif');
$auPlancher134 = causes($pdo, $plancher134);
[$duPendant134, $duApres134] = isset($service134[1]) ? $service134 : [null, null];
$pendant134 === ['droit_de_vote_perdu'] && $apres134 === [] && reputation($pdo, $perte134) === 5
    && ($duPendant134['rep'] ?? null) === 4 && $duPendant134['vote']
    && array_values(array_diff($duPendant134['causes'], ['salve_rapide'])) === ['perte_du_vote_due']
    && ($duApres134['rep'] ?? null) === 5 && !in_array('perte_du_vote_due', $duApres134['causes'], true)
    && $avantPlancher134 === ['droit_de_vote_perdu']
    && !in_array('droit_de_vote_perdu', $auPlancher134, true) && $auPlancher134 !== []
    ? ok('la perte du droit de vote se signale — retirée en démonstration, due en service, le vote gardé —, tombe quand il revient, et cède la place à la chute')
    : nok('seuil sourd à la config (usage), ou perte du vote muette : ' . json_encode([$pendant134, $apres134, reputation($pdo, $perte134), $service134, $avantPlancher134, $auPlancher134], JSON_UNESCAPED_UNICODE));

// ── 135. ⭐ Les messages qui dispensent de l'ancienneté se règlent ──────────────
$jeune135 = membre($pdo, 'jeune-135');
$pdo->prepare('UPDATE accounts SET created_at = ? WHERE id = ?')->execute([time(), $jeune135]);
message($pdo, $jeune135, 'fil-135');
$defaut135 = Moderate::canVote($pdo, $jeune135);
$deux135   = sousConfig(configBanc(messagesPourVoterAvantAge: 2), static fn (): array => Moderate::canVote($pdo, $jeune135));
$zero135   = sousConfig(configBanc(messagesPourVoterAvantAge: 0), static fn (): array => Moderate::canVote($pdo, $jeune135));
$defaut135 === [true, ''] && $deux135[0] === false && str_contains($deux135[1], '2 messages')
    && $zero135[0] === false && !str_contains($zero135[1], 'publie')
    ? ok('un message dispense par défaut ; deux en exigent deux ; zéro ne dispense personne, et chaque refus dit le sien')
    : nok('seuil sourd à la config (messagesPourVoterAvantAge) : ' . json_encode([$defaut135, $deux135, $zero135]));

// ── 136. ⭐ Sous le seuil de votants, reasonsFor() ne rend aucun motif ───────────
$vise136 = membre($pdo, 'vise-136');
$post136 = message($pdo, $vise136, 'fil-136');
foreach (['a', 'b'] as $n) {
    Moderate::applyVote($pdo, membre($pdo, "votant-136-$n"), 'post', $post136, -1, motif(), 'agressif');
}
$defaut136 = count(Moderate::reasonsFor($pdo, $vise136));
$trois136  = sousConfig(configBanc(motifsVotantsMin: 3), static fn (): int => count(Moderate::reasonsFor($pdo, $vise136)));
Moderate::applyVote($pdo, membre($pdo, 'votant-136-c'), 'post', $post136, -1, motif(), 'agressif');
$assez136  = sousConfig(configBanc(motifsVotantsMin: 3), static fn (): int => count(Moderate::reasonsFor($pdo, $vise136)));
$defaut136 === 2 && $trois136 === 0 && $assez136 === 3
    ? ok('reasonsFor() rend les motifs à partir de motifsVotantsMin votants distincts, et rien en dessous')
    : nok('seuil sourd à la config (motifsVotantsMin) : ' . json_encode([$defaut136, $trois136, $assez136]));

// ── 137. ⭐ La fin d'un ban d'arbitre qui rend le vote ferme la perte du vote ───
$journal137 = new JournalDeBanc();
$journalAvant137 = Moderate::journal();
Moderate::setJournal($journal137);
$arbitre137 = membre($pdo, 'arbitre-137');
poserReputation($pdo, $arbitre137, 5);
Moderate::applyVote($pdo, membre($pdo, 'votant-137'), 'member', $arbitre137, -1, motif(), 'agressif');
$avant137 = causes($pdo, $arbitre137);
Moderate::adminBan($pdo, $arbitre137, idDe($pdo, 'arbitre'), motif());
// Remontée pendant la peine, hors convalescence : seule la fin de peine rend le vote.
$pdo->prepare('UPDATE member_moderation SET reputation = 6, convalescent = 0 WHERE account_id = ?')->execute([$arbitre137]);
vieillirBan($pdo, $arbitre137, 10 * Moderate::config()->dureeBanAdmin());
rattraper($pdo, $arbitre137);
$apres137 = causes($pdo, $arbitre137);
$fin137 = $journal137->de($arbitre137, 'ban_fin');
Moderate::setJournal($journalAvant137);
$avant137 === ['droit_de_vote_perdu'] && !in_array('droit_de_vote_perdu', $apres137, true)
    && (int) etatBan($pdo, $arbitre137)['voting_rights'] === 1 && count($fin137) === 1
    && str_contains((string) $fin137[0]['ferme'], 'droit_de_vote_perdu')
    ? ok('la fin d\'un ban d\'arbitre qui rend le vote ferme la perte du vote, et son acte le dit')
    : nok('la perte du vote survit à la fin du ban qui rend le vote : ' . json_encode([$avant137, $apres137, etatBan($pdo, $arbitre137), $fin137]));

// ── 138. ⭐ La levée arrête la peine sans gracier : la récidive reste comptée ──
$journal138 = new JournalDeBanc();
$journalAvant138 = Moderate::journal();
Moderate::setJournal($journal138);
$auto138 = membre($pdo, 'auto-138');
poserReputation($pdo, $auto138, 1);
Moderate::applyVote($pdo, membre($pdo, 'votant-138'), 'member', $auto138, -1, motif(), 'agressif');
$sansMotif138 = Moderate::adminLever($pdo, $auto138, idDe($pdo, 'arbitre-banc'));
$leveeAuto138 = Moderate::adminLever($pdo, $auto138, idDe($pdo, 'arbitre-banc'), motif());
$etatAuto138  = etatBan($pdo, $auto138);
$acteAuto138  = $journal138->de($auto138, 'ban_leve');
$arbitre138 = membre($pdo, 'arbitre-138');
poserReputation($pdo, $arbitre138, 15);
Moderate::adminBan($pdo, $arbitre138, idDe($pdo, 'arbitre-banc'), motif());
vieillirBan($pdo, $arbitre138, (int) ceil(Moderate::config()->dureeBanAdmin() / 2));
$leveeArbitre138 = Moderate::adminLever($pdo, $arbitre138, idDe($pdo, 'arbitre-banc'));
$apresLevee138 = etatBan($pdo, $arbitre138);
Moderate::adminBan($pdo, $arbitre138, idDe($pdo, 'arbitre-banc'), motif());
$causes138 = causes($pdo, $arbitre138);
$rien138 = Moderate::adminLever($pdo, membre($pdo, 'libre-138'), idDe($pdo, 'arbitre-banc'), motif());
Moderate::setJournal($journalAvant138);
!$sansMotif138['ok'] && str_contains($sansMotif138['message'], 'Levée anticipée')
    && $leveeAuto138['ok'] && $leveeAuto138['anticipee'] === true
    && (int) $etatAuto138['banned_until'] === 0 && (int) $etatAuto138['reputation'] === Moderate::config()->reputationInitiale
    && (int) $etatAuto138['strikes'] === 1 && !in_array('ban_auto', causes($pdo, $auto138), true)
    && count($acteAuto138) === 1 && $acteAuto138[0]['cause'] === 'levee' && $journal138->de($auto138, 'ban_fin') === []
    && $leveeArbitre138['ok'] && $leveeArbitre138['anticipee'] === false
    && (int) $apresLevee138['banned_until'] === 0 && (int) $apresLevee138['strikes_admin'] === 1
    && in_array('bans_arbitre', $causes138, true)
    && !$rien138['ok'] && $rien138['message'] === 'Aucun ban en cours à lever.'
    ? ok('la levée arrête la peine dans l\'état de son échéance, garde la récidive, et ban → levée → ban signale l\'enchaînement')
    : nok('la levée gracie, ou rate son état : ' . json_encode([$sansMotif138, $leveeAuto138, $etatAuto138, $acteAuto138, $leveeArbitre138, $apresLevee138, $causes138, $rien138]));

// ── 139. ⭐ La prolongation repousse l'échéance, même épisode, sans strike ─────
$journal139 = new JournalDeBanc();
$journalAvant139 = Moderate::journal();
Moderate::setJournal($journal139);
$prolonge139 = membre($pdo, 'prolonge-139');
poserReputation($pdo, $prolonge139, 15);
Moderate::adminBan($pdo, $prolonge139, idDe($pdo, 'arbitre-banc'), motif());
$avant139 = etatBan($pdo, $prolonge139);
$plus139  = Moderate::adminProlonger($pdo, $prolonge139, idDe($pdo, 'arbitre-banc'), motif(), 3600);
$apres139 = etatBan($pdo, $prolonge139);
$zero139  = Moderate::adminProlonger($pdo, $prolonge139, idDe($pdo, 'arbitre-banc'), motif(), 0);
$bornes139 = [];
// L'heure figée : la borne se juge depuis maintenant, et une seconde qui passe
// entre le banc et le moteur la ferait glisser.
$t139 = time();
Moderate::setHorloge(static fn (): int => $t139);
foreach ([Moderate::DUREE_MAX - ((int) $apres139['banned_until'] - $t139) + 1, Moderate::SANS_FIN - (int) $apres139['banned_until'] - 1, PHP_INT_MAX] as $duree139) {
    try {
        $r139 = Moderate::adminProlonger($pdo, $prolonge139, idDe($pdo, 'arbitre-banc'), motif(), $duree139);
        $bornes139[] = [$r139['ok'], str_contains($r139['message'], 'trop longue')];
    } catch (Throwable $e139) {
        $bornes139[] = [get_class($e139)];
    }
}
$borne139 = (int) etatBan($pdo, $prolonge139)['banned_until'];
$acte139  = $journal139->de($prolonge139, 'ban_prolonge');
// À un siècle tout juste, la prolongation passe : la borne refuse au-delà, pas moins.
try {
    $juste139 = Moderate::adminProlonger($pdo, $prolonge139, idDe($pdo, 'arbitre-banc'), motif(), Moderate::DUREE_MAX - ($borne139 - $t139));
} catch (Throwable $e139) {
    $juste139 = ['ok' => false, 'message' => get_class($e139) . ' : ' . $e139->getMessage()];
}
Moderate::setHorloge(null);
$inconnue139 = membre($pdo, 'origine-inconnue-139');
poserReputation($pdo, $inconnue139, 15);
Moderate::adminBan($pdo, $inconnue139, idDe($pdo, 'arbitre-banc'), motif());
$pdo->prepare('UPDATE member_moderation SET ban_origine = NULL WHERE account_id = ?')->execute([$inconnue139]);
$inconnueAvant139 = (int) etatBan($pdo, $inconnue139)['banned_until'];
$inconnuePlus139  = Moderate::adminProlonger($pdo, $inconnue139, idDe($pdo, 'arbitre-banc'), motif(), 600);
$libre139 = Moderate::adminProlonger($pdo, membre($pdo, 'libre-139'), idDe($pdo, 'arbitre-banc'), motif(), 3600);
$auto139 = membre($pdo, 'auto-139');
poserReputation($pdo, $auto139, 1);
Moderate::applyVote($pdo, membre($pdo, 'votant-139'), 'member', $auto139, -1, motif(), 'agressif');
$autoAvant139   = (int) etatBan($pdo, $auto139)['banned_until'];
$autoSansFin139 = Moderate::adminProlonger($pdo, $auto139, idDe($pdo, 'arbitre-banc'), motif(), 0, true);
$autoPlus139    = Moderate::adminProlonger($pdo, $auto139, idDe($pdo, 'arbitre-banc'), motif(), 600);
Moderate::setJournal($journalAvant139);
$plus139['ok'] && (int) $apres139['banned_until'] === (int) $avant139['banned_until'] + 3600
    && (int) $apres139['strikes'] === (int) $avant139['strikes'] && (int) $apres139['strikes_admin'] === (int) $avant139['strikes_admin']
    && count($acte139) === 1 && $acte139[0]['de'] === (int) $avant139['banned_until'] && $acte139[0]['sans_fin'] === false
    && !$zero139['ok'] && !$libre139['ok'] && $libre139['message'] === 'Aucun ban en cours à rallonger.'
    && $bornes139 === [[false, true], [false, true], [false, true]] && $borne139 === (int) $apres139['banned_until']
    && $juste139['ok']
    && !$autoSansFin139['ok'] && str_contains($autoSansFin139['message'], 'Seul un ban d\'arbitre')
    && !$autoPlus139['ok'] && str_contains($autoPlus139['message'], 'Seul un ban d\'arbitre')
    && (int) etatBan($pdo, $auto139)['banned_until'] === $autoAvant139
    && !$inconnuePlus139['ok'] && (int) etatBan($pdo, $inconnue139)['banned_until'] === $inconnueAvant139
    ? ok('la prolongation repousse l\'échéance d\'un ban d\'arbitre sans strike, l\'inscrit, refuse sans ban, sans durée ou au-delà d\'un siècle, et laisse tel quel un ban automatique ou d\'origine inconnue')
    : nok('la prolongation rate : ' . json_encode([$plus139, $avant139, $apres139, $acte139, $zero139, $bornes139, $juste139, $libre139, $autoSansFin139, $autoPlus139, $inconnuePlus139]));

// ── 140. ⭐ Un ban d'arbitre sans fin : une échéance qui n'arrive pas, un motif pour en sortir ─
$journal140 = new JournalDeBanc();
$journalAvant140 = Moderate::journal();
Moderate::setJournal($journal140);
$sansFin140 = membre($pdo, 'sans-fin-140');
poserReputation($pdo, $sansFin140, 15);
$ban140    = Moderate::adminBan($pdo, $sansFin140, idDe($pdo, 'arbitre-banc'), motif(), null, true);
$etat140   = etatBan($pdo, $sansFin140);
$debut140  = $journal140->de($sansFin140, 'ban_debut');
$vote140   = Moderate::canVote($pdo, $sansFin140);
$vu140     = $pdo->query("SELECT vu_le FROM mod_signaux WHERE account_id = $sansFin140 AND cause = 'ban_sans_fin'")->fetchColumn();
$liste140  = array_values(array_filter(Moderate::bansEnCours($pdo, 10_000), static fn (array $b): bool => $b['account_id'] === $sansFin140));
$encore140 = Moderate::adminBan($pdo, $sansFin140, idDe($pdo, 'arbitre-banc'), motif());
$plus140   = Moderate::adminProlonger($pdo, $sansFin140, idDe($pdo, 'arbitre-banc'), motif(), 3600);
rattraper($pdo, $sansFin140);
$toujours140 = Moderate::estBanni($pdo, $sansFin140);
$leverNu140  = Moderate::adminLever($pdo, $sansFin140, idDe($pdo, 'arbitre-banc'));
$graceNue140 = Moderate::adminPardon($pdo, $sansFin140, idDe($pdo, 'arbitre-banc'));
$lever140    = Moderate::adminLever($pdo, $sansFin140, idDe($pdo, 'arbitre-banc'), motif());
$leve140     = $journal140->de($sansFin140, 'ban_leve');
$fini140 = membre($pdo, 'fini-140');
poserReputation($pdo, $fini140, 15);
Moderate::adminBan($pdo, $fini140, idDe($pdo, 'arbitre-banc'), motif());
$versSansFin140 = Moderate::adminProlonger($pdo, $fini140, idDe($pdo, 'arbitre-banc'), motif(), 0, true);
Moderate::setJournal($journalAvant140);
$ban140['ok'] && $ban140['message'] === 'Compte banni sans fin.' && (int) $etat140['banned_until'] === Moteur::SANS_FIN
    && Moteur::estSansFin((int) $etat140['banned_until']) && count($debut140) === 1 && $debut140[0]['duree'] === null && $debut140[0]['sans_fin'] === true
    && $vote140 === [false, 'Compte banni sans fin.'] && $vu140 !== false && $vu140 !== null
    && count($liste140) === 1 && $liste140[0]['sans_fin'] === true
    && !$encore140['ok'] && str_contains($encore140['message'], 'sans fin') && !$plus140['ok'] && $toujours140
    && !$leverNu140['ok'] && !$graceNue140['ok'] && str_contains($leverNu140['message'], 'motif écrit') && $leverNu140['message'] === $graceNue140['message']
    && $lever140['ok'] && count($leve140) === 1 && $leve140[0]['sans_fin'] === true && $leve140[0]['reste'] === null
    && !in_array('ban_sans_fin', causes($pdo, $sansFin140), true) && !Moderate::estBanni($pdo, $sansFin140)
    && $versSansFin140['ok'] && Moteur::estSansFin((int) etatBan($pdo, $fini140)['banned_until']) && in_array('ban_sans_fin', causes($pdo, $fini140), true)
    ? ok('un ban d\'arbitre sans fin n\'échoit jamais, se signale déjà vu, se dit tel partout, et ne se lève qu\'avec un motif')
    : nok('le ban sans fin rate : ' . json_encode([$ban140, $etat140, $debut140, $vote140, $vu140, $liste140, $encore140, $plus140, $toujours140, $leverNu140, $graceNue140, $lever140, $leve140, $versSansFin140]));

// ── 141. ⭐ La console du lab lève et rallonge par sa route, sans gracier ──────
// Par HTTP, avec une session d'arbitre. Le bouton « lever » de la liste des bans
// appelait la grâce, qui remet la récidive à zéro : il appelle la levée, et
// « rallonger » la prolongation. La route sert les deux.
$journalAvant141 = Moderate::journal();
Moderate::setJournal(new JournalDeBanc());
$cible141 = membre($pdo, 'console-141');
poserReputation($pdo, $cible141, 15);
Moderate::adminBan($pdo, $cible141, idDe($pdo, 'arbitre-banc'), motif());
$auto141 = membre($pdo, 'console-auto-141');
poserReputation($pdo, $auto141, 1);
Moderate::applyVote($pdo, membre($pdo, 'votant-141'), 'member', $auto141, -1, motif(), 'agressif');
Moderate::setJournal($journalAvant141);
$avant141   = etatBan($pdo, $cible141);
$session141 = sessionArbitre($pdo, 'arbitre-141');
$serveur141 = serveurLab();
$page141    = requeteLab($serveur141, '/admin.php', $session141)['corps'];
$csrf141    = csrfDeLaPage($page141);
$geste141   = static fn (string $op, array $plus = []): array => $csrf141 !== null
    ? requeteLab($serveur141, '/api/admin.php', $session141, $csrf141,
        ['action' => 'moderate', 'account_id' => $cible141, 'op' => $op, 'motif' => motif()] + $plus)
    : ['code' => 0, 'corps' => ''];
$plus141      = $geste141('prolonger', ['secondes' => 600]);
$rallonge141  = etatBan($pdo, $cible141);
$leve141      = $geste141('lever');
$apres141     = etatBan($pdo, $cible141);
arreterLab($serveur141);
// La route signe du compte de la session, et la ligne du ban dit qui l'a posé.
$signe141 = Moderate::peinesDe($pdo, $cible141)[0]['fin_arbitre'] ?? null;
$session141Id = (int) $pdo->query("SELECT id FROM accounts WHERE username = 'arbitre-141'")->fetchColumn();
$ligne141 = preg_match('~<tr><td>@console-141<.*?</tr>~s', $page141, $m141) === 1 ? $m141[0] : '';
$boutons141 = [
    'lever'     => str_contains($page141, 'data-id="' . $cible141 . '" data-op="lever"'),
    'rallonger' => str_contains($page141, 'data-id="' . $cible141 . '" data-op="prolonger"'),
    'auto_lever' => str_contains($page141, 'data-id="' . $auto141 . '" data-op="lever"'),
    'auto_rallonger' => str_contains($page141, 'data-id="' . $auto141 . '" data-op="prolonger"'),
];
$boutons141 === ['lever' => true, 'rallonger' => true, 'auto_lever' => true, 'auto_rallonger' => false]
    && $plus141['code'] === 200 && (int) $rallonge141['banned_until'] === (int) $avant141['banned_until'] + 600
    && $leve141['code'] === 200 && (int) $apres141['banned_until'] === 0
    && (int) $apres141['strikes_admin'] === (int) $avant141['strikes_admin'] && (int) $avant141['strikes_admin'] >= 1
    && $signe141 === ['id' => $session141Id, 'nom' => 'arbitre-141'] && str_contains($ligne141, 'arbitre @arbitre-banc · épisode')
    ? ok('⭐ la console lève et rallonge par sa route : la peine s\'arrête ou s\'allonge, la récidive reste comptée, le geste est signé du compte de la session et la ligne dit qui a posé le ban')
    : nok('⭐ la console gracie en levant, ou sa route ne sert ni la levée ni le rallongement : ' . json_encode([
        'boutons' => $boutons141, 'csrf' => $csrf141 !== null, 'signe' => $signe141, 'ligne' => substr($ligne141, 0, 200),
        'rallonger' => [$plus141['code'], substr($plus141['corps'], 0, 160)],
        'lever' => [$leve141['code'], substr($leve141['corps'], 0, 160)],
        'jusqua' => [(int) $avant141['banned_until'], (int) $rallonge141['banned_until'], (int) $apres141['banned_until']],
        'strikes_admin' => [(int) $avant141['strikes_admin'], (int) $apres141['strikes_admin']],
    ], JSON_UNESCAPED_UNICODE));

// ── 142. ⭐ Un hôte aux autres noms branche le moteur par des vues TEMP ───────
// Ses tables s'appellent autrement : il les expose sous les noms que le moteur lit,
// par des vues temporaires sur la connexion qu'il lui passe, sans rien écrire dans
// son schéma. Les clés étrangères des tables du moteur visent ses vraies tables :
// une clé étrangère ne vise pas une vue. Le quorum lit les liens par la vue `dm`,
// l'âge et les messages du votant par `accounts` et `posts`.
$h142 = new PDO('sqlite::memory:');
$h142->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$h142->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$h142->exec('PRAGMA foreign_keys = ON');
$h142->exec('CREATE TABLE membres (uid INTEGER PRIMARY KEY, pseudo TEXT NOT NULL, inscrit INTEGER NOT NULL);
             CREATE TABLE billets (bid INTEGER PRIMARY KEY, auteur INTEGER NOT NULL REFERENCES membres(uid));
             CREATE TABLE prives (de INTEGER NOT NULL, a INTEGER NOT NULL, le INTEGER NOT NULL);
             CREATE INDEX prives_paire ON prives(a, de, le)');
$schema142 = preg_replace('/--[^\n]*/', '', (string) file_get_contents(__DIR__ . '/../schema.sql'));
preg_match_all('/CREATE (?:UNIQUE )?(?:TABLE IF NOT EXISTS|INDEX IF NOT EXISTS \w+ ON) (?:member_moderation|mod_\w+)\b.*?;/s', $schema142, $ddl142);
foreach ($ddl142[0] as $instruction142) {
    $h142->exec(str_replace('REFERENCES accounts(id)', 'REFERENCES membres(uid)', $instruction142));
}
$h142->exec('CREATE TEMP VIEW accounts AS SELECT uid AS id, pseudo AS username, inscrit AS created_at FROM membres;
             CREATE TEMP VIEW posts AS SELECT bid AS id, auteur AS account_id FROM billets;
             CREATE TEMP VIEW dm AS SELECT de AS sender_id, a AS recipient_id, le AS created_at FROM prives');
$ancien142 = time() - 30 * 86400;
$id142 = [];
foreach (['cible', 'meneur', 'lie', 'neuf'] as $nom142) {
    $h142->prepare('INSERT INTO membres (pseudo, inscrit) VALUES (?, ?)')->execute([$nom142, $nom142 === 'neuf' ? time() : $ancien142]);
    $id142[$nom142] = (int) $h142->lastInsertId();
}
// Les messages privés se datent dans la fenêtre des liens, pas sur son bord : une
// seconde qui passe entre le banc et le moteur les en sortirait, et le lien avec eux.
$lien142 = time() - 86400;
$h142->prepare('INSERT INTO prives (de, a, le) VALUES (?, ?, ?), (?, ?, ?)')
    ->execute([$id142['meneur'], $id142['lie'], $lien142, $id142['lie'], $id142['meneur'], $lien142]);
$journalAvant142 = Moderate::journal();
Moderate::setJournal(new JournalDeBanc());
$erreur142 = null;
$mesure142 = [];
Moderate::setConfig(configBanc(quorumAuxSeuils: true));
try {
    Moderate::ensureRow($h142, $id142['cible']);
    $h142->prepare('UPDATE member_moderation SET reputation = 6 WHERE account_id = ?')->execute([$id142['cible']]);
    $mesure142['votes'] = [
        Moderate::applyVote($h142, $id142['meneur'], 'member', $id142['cible'], -1, motif(), 'agressif')['ok'] ?? null,
        Moderate::applyVote($h142, $id142['lie'], 'member', $id142['cible'], -1, motif(), 'agressif')['ok'] ?? null,
    ];
    $etat142 = Moderate::getReputation($h142, $id142['cible']);
    $mesure142['reputation'] = $etat142['reputation'];
    $mesure142['vote_garde'] = $etat142['voting_rights'];
    $mesure142['paires'] = Moderate::compositionChute($h142, $id142['cible'], time())['paires_liees'];
    $mesure142['neuf_sans_message'] = Moderate::canVote($h142, $id142['neuf'])[0];
    $h142->prepare('INSERT INTO billets (auteur) VALUES (?)')->execute([$id142['neuf']]);
    $mesure142['neuf_qui_a_publie'] = Moderate::canVote($h142, $id142['neuf'])[0];
    $mesure142['bans'] = count(Moderate::bansEnCours($h142));
} catch (Throwable $e142) {
    $erreur142 = get_class($e142) . ' : ' . $e142->getMessage();
}
Moderate::setConfig(configBanc());
Moderate::setJournal($journalAvant142);
$attendu142 = 4;
$erreur142 === null && $mesure142['votes'] === [true, true] && $mesure142['reputation'] === $attendu142
    && $mesure142['vote_garde'] === true && $mesure142['paires'] === 1
    && $mesure142['neuf_sans_message'] === false && $mesure142['neuf_qui_a_publie'] === true && $mesure142['bans'] === 0
    ? ok('⭐ un hôte aux autres noms branche le moteur par des vues TEMP : les liens, l\'âge et les messages se lisent à travers elles')
    : nok('⭐ le moteur ne tient pas sur des vues TEMP : ' . json_encode(['erreur' => $erreur142, 'mesure' => $mesure142,
        'attendu' => $attendu142, 'ddl' => count($ddl142[0])], JSON_UNESCAPED_UNICODE));

// ── 143. ⭐ Une durée ne fait pas un ban sans fin, et un ban sans fin ne sort qu'avec un motif ─
// Une durée qui atteignait `SANS_FIN` posait un ban que tout le moteur lit sans fin,
// sans `sans_fin` au journal ni signal ; à `PHP_INT_MAX`, l'acte s'inscrivait puis le
// geste levait. Le motif d'un ban sans fin n'était exigé qu'avant le plancher : sous
// un plancher nul, ou sans `ban_debut`, la levée et la grâce passaient sans un mot.
$journal143 = new JournalDeBanc();
$journalAvant143 = Moderate::journal();
Moderate::setJournal($journal143);
$long143 = membre($pdo, 'long-143');
poserReputation($pdo, $long143, 15);
$bornes143 = [];
foreach ([Moderate::DUREE_MAX + 1, Moderate::SANS_FIN - time() - 1, PHP_INT_MAX] as $duree143) {
    try {
        $r143 = Moderate::adminBan($pdo, $long143, idDe($pdo, 'arbitre-banc'), motif(), $duree143);
        $bornes143[] = [$r143['ok'], str_contains($r143['message'], 'trop longue')];
    } catch (Throwable $e143) {
        $bornes143[] = [get_class($e143)];
    }
}
$bornesActes143 = count($journal143->de($long143, 'ban_debut'));
$bornesBanni143 = Moderate::estBanni($pdo, $long143);
// Un siècle tout juste passe : la borne refuse au-delà, pas moins.
$juste143 = Moderate::adminBan($pdo, $long143, idDe($pdo, 'arbitre-banc'), motif(), Moderate::DUREE_MAX);
Moderate::adminLever($pdo, $long143, idDe($pdo, 'arbitre-banc'), motif());
$sansMotif143 = sousConfig(configBanc(plancherFraction: 0.0), static function () use ($pdo): array {
    $r = [];
    foreach (['lever', 'gracier'] as $geste) {
        foreach (['plancher-nul', 'sans-debut'] as $forme) {
            $id = membre($pdo, "sans-fin-$geste-$forme-143");
            poserReputation($pdo, $id, 15);
            Moderate::adminBan($pdo, $id, idDe($pdo, 'arbitre-banc'), motif(), null, true);
            if ($forme === 'sans-debut') {
                // Un ban d'avant l'enregistrement du début, repris tel quel : la peine ne le sait pas non plus.
                $pdo->prepare('UPDATE member_moderation SET ban_debut = 0 WHERE account_id = ?')->execute([$id]);
                $pdo->prepare('UPDATE mod_peines SET debut = 0 WHERE account_id = ? AND fin IS NULL')->execute([$id]);
            }
            $sortie = $geste === 'lever' ? Moderate::adminLever($pdo, $id, idDe($pdo, 'arbitre-banc')) : Moderate::adminPardon($pdo, $id, idDe($pdo, 'arbitre-banc'));
            $r["$geste/$forme"] = [$sortie['ok'], Moderate::estBanni($pdo, $id)];
            Moderate::adminLever($pdo, $id, idDe($pdo, 'arbitre-banc'), motif());
        }
    }

    return $r;
});
Moderate::setJournal($journalAvant143);
$bornes143 === [[false, true], [false, true], [false, true]] && $bornesActes143 === 0 && !$bornesBanni143 && $juste143['ok']
    && $sansMotif143 === array_fill_keys(['lever/plancher-nul', 'lever/sans-debut', 'gracier/plancher-nul', 'gracier/sans-debut'], [false, true])
    ? ok('⭐ une durée de plus d\'un siècle est refusée sans rien inscrire — la seconde d\'avant la fin des temps comprise —, et un ban sans fin ne sort qu\'avec un motif, même sous un plancher nul')
    : nok('⭐ une durée fait un ban sans fin, ou un ban sans fin sort sans motif : ' . json_encode([
        'bornes' => $bornes143, 'actes' => $bornesActes143, 'banni' => $bornesBanni143, 'juste' => $juste143, 'sans_motif' => $sansMotif143,
    ], JSON_UNESCAPED_UNICODE));

// ── 144. ⭐ La configuration refuse une durée qui ferait un ban sans fin ─────────
// `PHP_INT_MAX` dit « infini » à qui ne lit pas le moteur : comme palier, l'échéance
// débordait en flottant, `estBanni()` répondait non, et le lab laissait publier un
// banni ; un palier qui atteignait `SANS_FIN` faisait prononcer à la machine un ban
// sans fin, au journal `sans_fin: false`. Les paliers se jouent en service : en
// démonstration, la borne d'une heure les refuserait avant celle-ci.
$refus144 = [];
foreach ([
    'palier PHP_INT_MAX'   => static fn (): Config => configService(dureesBan: [86400, PHP_INT_MAX]),
    'palier SANS_FIN'      => static fn (): Config => configService(dureesBan: [Moderate::SANS_FIN - time()]),
    'arbitre PHP_INT_MAX'  => static fn (): Config => new Config(Messagerie::DM, Usage::DEMONSTRATION, dureeBanAdminSecondes: PHP_INT_MAX),
    'arbitre nul'          => static fn (): Config => new Config(Messagerie::DM, Usage::DEMONSTRATION, dureeBanAdminSecondes: 0),
    'palier d\'un siècle et une seconde'  => static fn (): Config => configService(dureesBan: [Moderate::DUREE_MAX + 1]),
    'arbitre d\'un siècle et une seconde' => static fn (): Config => new Config(Messagerie::DM, Usage::DEMONSTRATION, dureeBanAdminSecondes: Moderate::DUREE_MAX + 1),
] as $nom144 => $faire144) {
    try {
        $faire144();
        $refus144[$nom144] = 'accepté';
    } catch (InvalidArgumentException) {
        $refus144[$nom144] = 'refusé';
    }
}
try {
    configService(dureesBan: [86400, 604800, 100 * 365 * 86400], dureeBanAdminSecondes: 100 * 365 * 86400);
    $siecle144 = 'accepté';
} catch (InvalidArgumentException $e144) {
    $siecle144 = $e144->getMessage();
}
$refus144 === array_fill_keys(array_keys($refus144), 'refusé') && count($refus144) === 6 && $siecle144 === 'accepté'
    ? ok('⭐ la configuration refuse une durée qui ferait un ban sans fin, et accepte un siècle')
    : nok('⭐ la configuration accepte une durée qui fait un ban sans fin : ' . json_encode(['refus' => $refus144, 'siecle' => $siecle144], JSON_UNESCAPED_UNICODE));

// ── 145. ⭐ L'horloge branchée fait l'heure du moteur ────────────────────────────
// Une peine échoit quand l'horloge le dit, sans qu'une ligne de la base bouge ;
// débranchée, le moteur relit `time()`. Les cas 145 à 150 tournent sous elle.
$journal145 = new JournalDeBanc();
$journalAvant145 = Moderate::journal();
Moderate::setJournal($journal145);
// Calée sur la minute : la fin d'un ban automatique s'arrondit au pas de la
// config (60 s en démonstration), et l'échéance attendue reste le début plus le palier.
$h145 = intdiv(time(), 60) * 60 + 60;
$t145 = $h145;
$horloge145 = static function () use (&$h145): int {
    return $h145;
};
Moderate::setHorloge($horloge145);
$chute145 = membre($pdo, 'chute-145');
poserReputation($pdo, $chute145, 2);
Moderate::applyVote($pdo, membre($pdo, 'votant-a-145'), 'member', $chute145, -1, motif(), 'agressif');
Moderate::applyVote($pdo, membre($pdo, 'votant-b-145'), 'member', $chute145, -1, motif(), 'agressif');
$duree145 = Moderate::config()->dureeBanPourEpisode(1);
$banni145 = Moderate::getReputation($pdo, $chute145)['banned'];
$h145 += $duree145;
$echu145 = Moderate::getReputation($pdo, $chute145);
$lu145 = Moderate::maintenant();
Moderate::setHorloge(null);
$reel145 = Moderate::getReputation($pdo, $chute145)['banned'];
Moderate::setHorloge($horloge145);
$banni145 && !$echu145['banned'] && $echu145['fin_en_attente'] && $lu145 === $t145 + $duree145 && $reel145
    ? ok('⭐ l\'horloge branchée fait échoir une peine sans qu\'une ligne bouge, et débranchée le moteur relit l\'heure réelle')
    : nok('⭐ le moteur ne lit pas l\'horloge branchée : ' . json_encode([
        'banni' => $banni145, 'echu' => [$echu145['banned'], $echu145['fin_en_attente']], 'lu' => $lu145 - $t145, 'reel' => $reel145,
    ]));

// ── 146. ⭐ Une peine automatique s'inscrit à son début, et ses causes lui survivent ─
// La fin d'un ban automatique remet la réputation au départ et l'effet des votes
// reçus à zéro : sans l'historique, plus rien ne dit qui a fait tomber le compte.
$avant146 = Moderate::peinesDe($pdo, $chute145, precis: true);
rattraper($pdo, $chute145);
$apres146 = Moderate::peinesDe($pdo, $chute145, precis: true);
$effets146 = (int) $pdo->query("SELECT COUNT(*) FROM mod_votes WHERE target_author = $chute145 AND effet < 0")->fetchColumn();
$attendu146 = [
    'origine' => 'auto', 'episode' => 1, 'motif' => 'reputation_zero', 'arbitre' => null, 'debut' => $t145,
    'echeance_initiale' => $t145 + $duree145, 'echeance' => $t145 + $duree145, 'decide_le' => $t145, 'prolongations' => 0,
    'reprise' => false, 'causes' => ['votes' => 2, 'votants' => 2],
];
$vue146 = static fn (array $p): array => array_intersect_key($p, $attendu146);
count($avant146) === 1 && $vue146($avant146[0]) == $attendu146 && $avant146[0]['fin'] === null
    && count($apres146) === 1 && $vue146($apres146[0]) == $attendu146 && $apres146[0]['fin'] === $t145 + $duree145
    && $apres146[0]['fin_cause'] === 'echeance' && $apres146[0]['fin_arbitre'] === null && $effets146 === 0
    ? ok('⭐ une peine automatique s\'inscrit à son début avec ses causes, se ferme à son échéance, et ses causes survivent à la remise au départ')
    : nok('⭐ l\'historique perd une peine automatique ou ses causes : ' . json_encode(['avant' => $avant146, 'apres' => $apres146, 'effets' => $effets146], JSON_UNESCAPED_UNICODE));

// ── 147. ⭐ Un ban d'arbitre porte son compte, de son début à sa fin ─────────────
// Posé par un identifiant, prolongé par un nom, levé par un autre arbitre, puis un
// second ban gracié : l'historique dit qui a décidé chaque fois, et la console voit
// qui a posé le ban en cours.
$arbA147 = membre($pdo, 'arbitre-a-147');
$arbB147 = membre($pdo, 'arbitre-b-147');
$cible147 = membre($pdo, 'cible-147');
poserReputation($pdo, $cible147, 15);
$t147 = $h145;
$gestes147 = [Moderate::adminBan($pdo, $cible147, $arbA147, motif(), 3600)];
$console147 = array_values(array_filter(Moderate::bansEnCours($pdo, 100000), static fn (array $b): bool => $b['account_id'] === $cible147));
$h145 += 100;
$gestes147[] = Moderate::adminProlonger($pdo, $cible147, idDe($pdo, 'arbitre-a-147'), motif(), 600);
$h145 += 100;
$gestes147[] = Moderate::adminLever($pdo, $cible147, $arbB147, motif());
$h145 += 100;
$gestes147[] = Moderate::adminBan($pdo, $cible147, $arbB147, motif(), 3600);
$h145 += 100;
$gestes147[] = Moderate::adminPardon($pdo, $cible147, $arbA147, motif());
$hist147 = Moderate::peinesDe($pdo, $cible147, precis: true);
$debuts147 = $journal145->de($cible147, 'ban_debut');
$a147 = ['id' => $arbA147, 'nom' => 'arbitre-a-147'];
$b147 = ['id' => $arbB147, 'nom' => 'arbitre-b-147'];
array_column($gestes147, 'ok') === [true, true, true, true, true]
    && count($console147) === 1 && $console147[0]['arbitre'] === $a147 && $console147[0]['prolongations'] === 0
    && $console147[0]['echeance_initiale'] === $t147 + 3600 && count($hist147) === 2 && $console147[0]['peine'] === $hist147[1]['peine']
    && $hist147[1]['arbitre'] === $a147 && $hist147[1]['episode'] === 1 && $hist147[1]['debut'] === $t147
    && $hist147[1]['echeance_initiale'] === $t147 + 3600 && $hist147[1]['echeance'] === $t147 + 4200
    && $hist147[1]['decide_le'] === $t147 + 100 && $hist147[1]['prolongations'] === 1
    && $hist147[1]['fin'] === $t147 + 200 && $hist147[1]['fin_cause'] === 'levee' && $hist147[1]['fin_arbitre'] === $b147
    && $hist147[0]['arbitre'] === $b147 && $hist147[0]['episode'] === 2 && $hist147[0]['debut'] === $t147 + 300
    && $hist147[0]['fin'] === $t147 + 400 && $hist147[0]['fin_cause'] === 'grace' && $hist147[0]['fin_arbitre'] === $a147
    && count($debuts147) === 2 && $debuts147[0]['arbitre'] === 'arbitre-a-147' && $debuts147[0]['arbitre_id'] === $arbA147
    ? ok('⭐ un ban d\'arbitre porte son compte du début à la fin : prolongation, levée et grâce s\'inscrivent dans l\'historique, et la console voit qui l\'a posé')
    : nok('⭐ l\'historique ou la console perd l\'arbitre d\'un ban : ' . json_encode([
        'gestes' => $gestes147, 'console' => $console147, 'historique' => $hist147, 'actes' => $debuts147,
    ], JSON_UNESCAPED_UNICODE));

// ── 148. ⭐ L'arbitre est un identifiant, ni le compte qu'il vise, ni un compte qu'un pair a banni ─
// Un nom se prend : un homonyme gèlerait les gestes d'un modérateur, un tiers inscrit
// sous son nom les signerait. Seul un entier passe, même chez un hôte hors du mode
// strict, où PHP convertirait `"1"` ou `true`. Un arbitre ne touche pas à son propre
// compte ; banni par un autre arbitre, ou sous un ban d'origine inconnue, qui peut en
// être un, il ne signe rien ; banni par la machine, il garde ses gestes, et des
// votants ne coupent pas la modération.
require_once __DIR__ . '/appel_non_strict.inc';
$soi148 = membre($pdo, 'soi-148');
poserReputation($pdo, $soi148, 15);
$autre148 = membre($pdo, 'autre-148');
poserReputation($pdo, $autre148, 15);
$pair148 = membre($pdo, 'pair-148');
poserReputation($pdo, $pair148, 15);
// Le vote du cas prendrait sinon la tranche de balayage, et son filet inscrirait au
// journal les fins de peine que les cas précédents ont laissées : le compte des actes
// ne dirait plus les gestes de ce cas.
trancheBalayee($pdo);
$actes148 = count($journal145->actes);
$refus148 = [];
foreach (['nom' => 'soi-148', 'identifiant en chaine' => (string) $soi148] as $forme148 => $chaine148) {
    try {
        Moderate::adminBan($pdo, $autre148, $chaine148, motif());
        $refus148[$forme148] = 'accepté';
    } catch (TypeError) {
        $refus148[$forme148] = 'type';
    }
}
foreach (['1', '01', 1.5, true] as $valeur148) {
    $refus148['hors mode strict ' . var_export($valeur148, true)] = banNonStrict($pdo, $autre148, $valeur148);
}
$refus148['identifiant inconnu'] = Moderate::adminBan($pdo, $autre148, PHP_INT_MAX, motif());
$refus148['bannir soi'] = Moderate::adminBan($pdo, $soi148, $soi148, motif());
Moderate::adminBan($pdo, $soi148, idDe($pdo, 'arbitre-banc'), motif(), 3600);
$refus148 += [
    'lever soi'     => Moderate::adminLever($pdo, $soi148, $soi148, motif()),
    'prolonger soi' => Moderate::adminProlonger($pdo, $soi148, $soi148, motif(), 60),
    'gracier soi'   => Moderate::adminPardon($pdo, $soi148, $soi148, motif()),
    'maintenir soi' => Moderate::adminMaintenir($pdo, $soi148, $soi148, motif()),
    'classer soi'   => Moderate::adminClasser($pdo, $soi148, $soi148, motif()),
];
// Deux arbitres en peine : ni l'un ne lève l'autre, ni aucun ne bannit un tiers.
Moderate::adminBan($pdo, $pair148, idDe($pdo, 'arbitre-banc'), motif(), 3600);
$refus148 += [
    'banni lève son pair'   => Moderate::adminLever($pdo, $pair148, $soi148, motif()),
    'banni gracie son pair' => Moderate::adminPardon($pdo, $soi148, $pair148, motif()),
    'banni bannit un tiers' => Moderate::adminBan($pdo, $autre148, $soi148, motif()),
];
// Un ban écrit avant que l'origine ne s'enregistre peut être un ban d'arbitre.
$inconnu148 = membre($pdo, 'inconnu-148');
poserReputation($pdo, $inconnu148, 15);
Moderate::adminBan($pdo, $inconnu148, idDe($pdo, 'arbitre-banc'), motif(), 3600);
$pdo->prepare('UPDATE member_moderation SET ban_origine = NULL WHERE account_id = ?')->execute([$inconnu148]);
$refus148['banni d\'origine inconnue bannit un tiers'] = Moderate::adminBan($pdo, $autre148, $inconnu148, motif());
// Banni par la machine : ses gestes passent, sauf sur lui-même.
$auto148 = membre($pdo, 'auto-148');
poserReputation($pdo, $auto148, 1);
// À un point, il a déjà perdu son vote : le seul acte de la machine est le début du ban.
$pdo->prepare('UPDATE member_moderation SET voting_rights = 0 WHERE account_id = ?')->execute([$auto148]);
Moderate::applyVote($pdo, membre($pdo, 'votant-148'), 'member', $auto148, -1, motif(), 'agressif');
$tiers148 = membre($pdo, 'tiers-148');
poserReputation($pdo, $tiers148, 15);
$refus148 += [
    'banni par la machine bannit un tiers' => Moderate::adminBan($pdo, $tiers148, $auto148, motif(), 600),
    'banni par la machine se lève'          => Moderate::adminLever($pdo, $auto148, $auto148, motif()),
];
$auto148Banni = Moderate::getReputation($pdo, $auto148);
$tiers148Banni = Moderate::estBanni($pdo, $tiers148);
$inscrits148 = count($journal145->actes) - $actes148;
$motifs148 = array_map(static fn (array|string $r): string => is_string($r) ? $r : ($r['ok'] ? 'accepté' : match (true) {
    str_contains($r['message'], 'n\'en désigne aucun') => 'aucun compte',
    str_contains($r['message'], 'son propre compte')   => 'soi',
    str_contains($r['message'], 'sous peine')          => 'sous peine',
    default                                             => $r['message'],
}), $refus148);
$encore148 = [Moderate::estBanni($pdo, $soi148), Moderate::estBanni($pdo, $pair148), Moderate::estBanni($pdo, $autre148)];
Moderate::adminLever($pdo, $soi148, idDe($pdo, 'arbitre-banc'), motif());
Moderate::adminLever($pdo, $pair148, idDe($pdo, 'arbitre-banc'), motif());
Moderate::adminLever($pdo, $tiers148, idDe($pdo, 'arbitre-banc'), motif());
Moderate::adminLever($pdo, $inconnu148, idDe($pdo, 'arbitre-banc'), motif());
$motifs148 === ['nom' => 'type', 'identifiant en chaine' => 'type',
                'hors mode strict \'1\'' => 'type', 'hors mode strict \'01\'' => 'type', 'hors mode strict 1.5' => 'type',
                'hors mode strict true' => 'type', 'identifiant inconnu' => 'aucun compte', 'bannir soi' => 'soi',
                'lever soi' => 'soi', 'prolonger soi' => 'soi', 'gracier soi' => 'soi', 'maintenir soi' => 'soi', 'classer soi' => 'soi',
                'banni lève son pair' => 'sous peine', 'banni gracie son pair' => 'sous peine', 'banni bannit un tiers' => 'sous peine',
                'banni d\'origine inconnue bannit un tiers' => 'sous peine',
                'banni par la machine bannit un tiers' => 'accepté', 'banni par la machine se lève' => 'soi']
    && $inscrits148 === 5 && $encore148 === [true, true, false]
    && $auto148Banni['banned'] && $auto148Banni['ban_origine'] === 'auto' && $tiers148Banni
    ? ok('⭐ l\'arbitre est un entier, même hors du mode strict : un identifiant sans compte, un geste sur son propre compte et un arbitre banni par un pair ou d\'origine inconnue sont refusés sans rien inscrire ; banni par la machine, il garde ses gestes')
    : nok('⭐ un geste passe sans arbitre identifiable, sur le compte de son arbitre, ou signé d\'un arbitre sous peine : ' . json_encode([
        'refus' => $motifs148, 'inscrits' => $inscrits148, 'bannis' => $encore148,
        'auto' => [$auto148Banni['banned'], $auto148Banni['ban_origine']], 'tiers' => $tiers148Banni,
    ], JSON_UNESCAPED_UNICODE));

// ── 149. ⭐ Une peine ne s'ouvre pas sur une autre ─────────────────────────────
// Une ligne ouverte sans ban en cours vient d'un écrivain hors du moteur : le ban
// suivant refuse avant d'écrire l'acte, et la base refuse d'elle-même une seconde
// peine ouverte pour le même compte.
$orph149 = membre($pdo, 'orpheline-149');
poserReputation($pdo, $orph149, 15);
$plante149 = $pdo->prepare('INSERT INTO mod_peines (account_id, origine, episode, debut, echeance_initiale, echeance, decide_le)
                            VALUES (?, \'admin\', 1, ?, ?, ?, ?)');
$plante149->execute([$orph149, $h145, $h145 + 60, $h145 + 60, $h145]);
$actes149 = count($journal145->actes);
try {
    Moderate::adminBan($pdo, $orph149, idDe($pdo, 'arbitre-banc'), motif());
    $leve149 = 'aucune';
} catch (Throwable $e149) {
    $leve149 = get_class($e149) . ' : ' . $e149->getMessage();
}
$inscrits149 = count($journal145->actes) - $actes149;
try {
    $plante149->execute([$orph149, $h145, $h145 + 60, $h145 + 60, $h145]);
    $double149 = 'acceptée';
} catch (PDOException) {
    $double149 = 'refusée';
}
$pdo->prepare('DELETE FROM mod_peines WHERE account_id = ?')->execute([$orph149]);
str_contains($leve149, 'une peine ne s\'ouvre pas sur une autre') && $inscrits149 === 0 && $double149 === 'refusée'
    && !Moderate::estBanni($pdo, $orph149)
    ? ok('⭐ une peine ne s\'ouvre pas sur une autre : le ban refuse avant d\'écrire l\'acte, et la base refuse une seconde peine ouverte')
    : nok('⭐ une peine s\'ouvre sur une autre : ' . json_encode(['leve' => $leve149, 'inscrits' => $inscrits149, 'double' => $double149], JSON_UNESCAPED_UNICODE));

// ── 150. ⭐ Sans la reprise, un ban d'avant la 0.9.0 se refuse ; la reprise le reprend une fois ─
// Un ban en cours sans sa ligne manquerait à l'historique sans un mot, et sa fin
// n'aurait aucune ligne à fermer : la lecture, le geste, la console et l'historique
// refusent en nommant `migrerBans()`, sans rien inscrire.
$ancien150 = membre($pdo, 'ancien-150');
Moderate::ensureRow($pdo, $ancien150);
$debut150 = $h145 - 600;
$jusqua150 = $h145 + 3600;
$pdo->prepare("UPDATE member_moderation
                  SET strikes = 2, strikes_admin = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                      ban_origine = 'admin', ban_motif = 'motif d''avant'
                WHERE account_id = ?")->execute([$jusqua150, $debut150, $ancien150]);
$actes150 = count($journal145->actes);
$refus150 = [];
foreach ([
    'lecture'    => static fn () => Moderate::getReputation($pdo, $ancien150),
    'levee'      => static fn () => Moderate::adminLever($pdo, $ancien150, idDe($pdo, 'arbitre-banc'), motif()),
    'console'    => static fn () => Moderate::bansEnCours($pdo, 100000),
    'historique' => static fn () => Moderate::peinesDe($pdo, $ancien150),
] as $nom150 => $faire150) {
    try {
        $faire150();
        $refus150[$nom150] = 'passé';
    } catch (Throwable $e150) {
        $refus150[$nom150] = $e150 instanceof \Pierroons\SelfModerate\RepriseManquante && $e150->compte === $ancien150
            && str_contains($e150->getMessage(), 'migrerBans()') ? 'nommée' : get_class($e150) . ' : ' . $e150->getMessage();
    }
}
$inscrits150 = count($journal145->actes) - $actes150;
$repris150 = Moderate::migrerBans($pdo);
$rejoue150 = Moderate::migrerBans($pdo);
$hist150 = Moderate::peinesDe($pdo, $ancien150, precis: true);
Moderate::setHorloge(null);
Moderate::setJournal($journalAvant145);
$refus150 === array_fill_keys(['lecture', 'levee', 'console', 'historique'], 'nommée') && $inscrits150 === 0
    && $repris150 === 1 && $rejoue150 === 0 && count($hist150) === 1 && $hist150[0]['reprise'] === true
    && $hist150[0]['arbitre'] === null && $hist150[0]['origine'] === 'admin' && $hist150[0]['motif'] === 'motif d\'avant'
    && $hist150[0]['episode'] === 2 && $hist150[0]['debut'] === $debut150 && $hist150[0]['echeance'] === $jusqua150
    && $hist150[0]['fin'] === null
    ? ok('⭐ sans la reprise, un ban en cours se refuse à la lecture, au geste, à la console et à l\'historique en nommant migrerBans(), sans rien inscrire ; la reprise le reprend une fois')
    : nok('⭐ un ban sans sa ligne passe en silence, ou la reprise le rate : ' . json_encode([
        'refus' => $refus150, 'inscrits' => $inscrits150, 'repris' => $repris150, 'rejoue' => $rejoue150, 'historique' => $hist150,
    ], JSON_UNESCAPED_UNICODE));

// ── 151. ⭐ Une grâce au bord de l'échéance ne laisse pas de peine orpheline ─────
// L'heure passe la seconde de l'échéance à une position d'appel, puis à la suivante,
// de la 1ʳᵉ à la 60ᵉ lecture du geste. Lue deux fois, elle disait la peine ni en
// cours ni en attente de sa fin : la grâce remettait le ban à zéro sans fermer sa
// ligne, et le compte ne pouvait plus être banni.
$journalAvant151 = Moderate::journal();
$journal151 = new JournalDeBanc();
Moderate::setJournal($journal151);
$t151 = time();
$e151 = $t151 + 600;
$appels151 = 0;
$bascule151 = 0;
$orphelines151 = [];
$sansFin151 = [];
$leves151 = [];
for ($k151 = 1; $k151 <= 60; $k151++) {
    $id151 = membre($pdo, "bord-$k151-151");
    poserReputation($pdo, $id151, 15);
    Moderate::setHorloge(static fn (): int => $t151);
    Moderate::adminBan($pdo, $id151, idDe($pdo, 'arbitre-banc'), motif(), 600);
    $appels151 = 0;
    $bascule151 = $k151;
    Moderate::setHorloge(static function () use (&$appels151, &$bascule151, $e151): int {
        return ++$appels151 >= $bascule151 ? $e151 : $e151 - 1;
    });
    try {
        Moderate::adminPardon($pdo, $id151, idDe($pdo, 'arbitre-banc'), motif());
    } catch (Throwable $e) {
        $leves151[$k151] = get_class($e) . ' : ' . $e->getMessage();
    }
    Moderate::setHorloge(null);
    $o151 = $pdo->prepare('SELECT 1 FROM mod_peines p JOIN member_moderation m ON m.account_id = p.account_id
                            WHERE p.account_id = ? AND p.fin IS NULL AND m.banned_until = 0');
    $o151->execute([$id151]);
    if ($o151->fetchColumn() !== false) {
        $orphelines151[] = $k151;
    }
    $o151->closeCursor();
    // Une peine, une fin au journal : son échéance (`ban_fin`) ou sa levée (`ban_leve`).
    if (count($journal151->de($id151, 'ban_fin')) + count($journal151->de($id151, 'ban_leve')) !== 1) {
        $sansFin151[] = $k151;
    }
}
Moderate::setJournal($journalAvant151);
$orphelines151 === [] && $sansFin151 === [] && $leves151 === []
    ? ok('⭐ une grâce au bord de l\'échéance, à chaque position de la seconde qui passe, ferme sa peine et inscrit sa fin au journal')
    : nok('⭐ une grâce au bord de l\'échéance laisse une peine ouverte sans ban, ou sans fin au journal : ' . json_encode([
        'orphelines' => $orphelines151, 'sans_fin' => $sansFin151, 'leves' => $leves151,
    ], JSON_UNESCAPED_UNICODE));

// ── 152. ⭐ L'historique se réconcilie dans les deux sens ───────────────────────
// Une peine finie hors du moteur (une montée faite service en marche) laisse une
// ligne ouverte sans ban : le ban suivant, d'arbitre ou automatique, refuse sans
// rien inscrire en nommant `migrerBans()`, la console aussi, et la reprise la ferme
// (`hors_moteur`). Une ligne fermée à la main sur un ban qui court : la reprise la
// rouvre, au lieu d'en fabriquer une seconde.
$journal152 = new JournalDeBanc();
$journalAvant152 = Moderate::journal();
Moderate::setJournal($journal152);
$orph152 = membre($pdo, 'orpheline-152');
poserReputation($pdo, $orph152, 15);
Moderate::adminBan($pdo, $orph152, idDe($pdo, 'arbitre-banc'), motif(), 3600);
$pdo->prepare('UPDATE member_moderation SET banned_until = 0, ban_debut = 0, ban_origine = NULL, ban_motif = NULL WHERE account_id = ?')
    ->execute([$orph152]);
$diag152 = static function (callable $geste): string {
    try {
        $geste();
        return 'passé';
    } catch (Throwable $e) {
        return $e instanceof \Pierroons\SelfModerate\RepriseManquante && str_contains($e->getMessage(), 'migrerBans()')
            ? 'nommée' : get_class($e) . ' : ' . $e->getMessage();
    }
};
$actes152 = count($journal152->actes);
$avant152 = [
    'ban'     => $diag152(static fn () => Moderate::adminBan($pdo, $orph152, idDe($pdo, 'arbitre-banc'), motif())),
    'auto'    => $diag152(static function () use ($pdo, $orph152): void {
        poserReputation($pdo, $orph152, 1);
        Moderate::applyVote($pdo, membre($pdo, 'votant-152'), 'member', $orph152, -1, motif(), 'agressif');
    }),
    'auto_sans_vote' => $diag152(static function () use ($pdo, $orph152): void {
        poserReputation($pdo, $orph152, 1);
        $pdo->prepare('UPDATE member_moderation SET voting_rights = 0 WHERE account_id = ?')->execute([$orph152]);
        Moderate::applyVote($pdo, membre($pdo, 'votant-152b'), 'member', $orph152, -1, motif(), 'agressif');
    }),
    'console' => $diag152(static fn () => Moderate::bansEnCours($pdo, 100000)),
];
$inscrits152 = count($journal152->actes) - $actes152;
$repare152 = null;
$diag152(static function () use ($pdo, &$repare152): void {
    $repare152 = Moderate::migrerBans($pdo);
});
$fermee152 = $pdo->query("SELECT fin_cause FROM mod_peines WHERE account_id = $orph152 ORDER BY id DESC LIMIT 1")->fetchColumn();
poserReputation($pdo, $orph152, 15);
$apres152 = $diag152(static fn () => Moderate::adminBan($pdo, $orph152, idDe($pdo, 'arbitre-banc'), motif(), 3600));
if ($apres152 === 'passé') {
    Moderate::adminLever($pdo, $orph152, idDe($pdo, 'arbitre-banc'), motif());
} else {
    $pdo->prepare('DELETE FROM mod_peines WHERE account_id = ? AND fin IS NULL')->execute([$orph152]);
}
$rouv152 = membre($pdo, 'rouverte-152');
poserReputation($pdo, $rouv152, 15);
Moderate::adminBan($pdo, $rouv152, idDe($pdo, 'arbitre-un'), motif(), 3600);
$pdo->prepare("UPDATE mod_peines SET fin = ?, fin_cause = 'levee' WHERE account_id = ? AND fin IS NULL")->execute([time(), $rouv152]);
$lecture152 = '';
try {
    Moderate::getReputation($pdo, $rouv152);
} catch (Throwable $e) {
    $lecture152 = $e->getMessage();
}
$rouvre152 = $diag152(static fn () => Moderate::migrerBans($pdo));
// Une réparation manquée laisse une ligne que le moteur refuse : la lecture le dit, le
// cas rougit sur son nom au lieu de tomber.
$peines152 = static function (int $compte) use ($pdo): array|string {
    try {
        return Moderate::peinesDe($pdo, $compte, precis: true);
    } catch (Throwable $e) {
        return get_class($e) . ' : ' . $e->getMessage();
    }
};
$lignes152 = $rouvre152 === 'passé' ? $peines152($rouv152) : [];
if ($rouvre152 === 'passé') {
    Moderate::adminLever($pdo, $rouv152, idDe($pdo, 'arbitre-banc'), motif());
} else {
    $pdo->prepare('DELETE FROM mod_peines WHERE account_id = ? AND fin IS NULL')->execute([$rouv152]);
    $pdo->prepare('UPDATE member_moderation SET banned_until = 0, ban_debut = 0, ban_origine = NULL, ban_motif = NULL WHERE account_id = ?')
        ->execute([$rouv152]);
}
// La 0.8.0 lève puis repose un ban, puis le prolonge, pendant la montée : la ligne
// ouverte ne dit plus le ban en cours, et la reprise la remet d'accord.
$trois152 = membre($pdo, 'reposee-152');
poserReputation($pdo, $trois152, 15);
Moderate::adminBan($pdo, $trois152, idDe($pdo, 'arbitre-un'), motif(), 3600);
$debut152 = time() + 5;
$fin152 = time() + 7200;
$pdo->prepare('UPDATE member_moderation SET ban_debut = ?, banned_until = ? WHERE account_id = ?')->execute([$debut152, $fin152, $trois152]);
// Avant la reprise, le moteur refuse ce compte — à sa lecture et aux listes de
// l'arbitre — en nommant migrerBans() : l'historique ne se lit pas faux en silence.
$divergente152 = static function (int $compte, callable $geste): string {
    try {
        $geste();
        return 'passé';
    } catch (\Pierroons\SelfModerate\RepriseManquante $e) {
        return $e->compte === $compte && str_contains($e->getMessage(), 'ne dit plus') && str_contains($e->getMessage(), 'migrerBans()')
            ? 'divergente' : $e->getMessage();
    } catch (Throwable $e) {
        return get_class($e) . ' : ' . $e->getMessage();
    }
};
$sansRepriseReposee152 = [
    $divergente152($trois152, static fn () => Moderate::getReputation($pdo, $trois152)),
    $divergente152($trois152, static fn () => Moderate::bansEnCours($pdo, 100000)),
];
$reposee152 = $diag152(static fn () => Moderate::migrerBans($pdo));
$apresReposee152 = $peines152($trois152);
$pdo->prepare('UPDATE member_moderation SET banned_until = banned_until + 600 WHERE account_id = ?')->execute([$trois152]);
$diag152(static fn () => Moderate::migrerBans($pdo));
$apresProlongee152 = $peines152($trois152);
$diag152(static fn () => Moderate::adminLever($pdo, $trois152, idDe($pdo, 'arbitre-banc'), motif()));
// Un ban que le moteur a posé, prolongé hors de lui : refusé de même, puis recalé, et
// sa ligne prend `reprise` — la prolongation n'est pas connue.
$prol152 = membre($pdo, 'prolongee-152');
poserReputation($pdo, $prol152, 15);
Moderate::adminBan($pdo, $prol152, idDe($pdo, 'arbitre-un'), motif(), 3600);
$repriseAvant152 = $peines152($prol152)[0]['reprise'] ?? null;
$pdo->prepare('UPDATE member_moderation SET banned_until = banned_until + 600 WHERE account_id = ?')->execute([$prol152]);
$finProlongee152 = (int) $pdo->query("SELECT banned_until FROM member_moderation WHERE account_id = $prol152")->fetchColumn();
$sansRepriseProlongee152 = [
    $divergente152($prol152, static fn () => Moderate::getReputation($pdo, $prol152)),
    $divergente152($prol152, static fn () => Moderate::bansEnCours($pdo, 100000)),
];
$diag152(static fn () => Moderate::migrerBans($pdo));
$luProlongee152 = $divergente152($prol152, static fn () => Moderate::getReputation($pdo, $prol152));
$ligneProlongee152 = $peines152($prol152);
$ligneProlongee152 = is_array($ligneProlongee152) ? ($ligneProlongee152[0] ?? []) : ['erreur' => $ligneProlongee152];
$diag152(static fn () => Moderate::adminLever($pdo, $prol152, idDe($pdo, 'arbitre-banc'), motif()));
$divergence152 = $sansRepriseReposee152 === ['divergente', 'divergente'] && $sansRepriseProlongee152 === ['divergente', 'divergente']
    && $luProlongee152 === 'passé' && $repriseAvant152 === false
    && ($ligneProlongee152['reprise'] ?? null) === true && ($ligneProlongee152['echeance'] ?? null) === $finProlongee152;
$troisieme152 = $reposee152 === 'passé' && is_array($apresReposee152) && count($apresReposee152) === 2
    && $apresReposee152[1]['fin_cause'] === 'hors_moteur' && $apresReposee152[0]['fin'] === null
    && $apresReposee152[0]['debut'] === $debut152 && $apresReposee152[0]['echeance'] === $fin152
    && is_array($apresProlongee152) && ($apresProlongee152[0]['echeance'] ?? null) === $fin152 + 600 && count($apresProlongee152) === 2;
Moderate::setJournal($journalAvant152);
$avant152 === ['ban' => 'nommée', 'auto' => 'nommée', 'auto_sans_vote' => 'nommée', 'console' => 'nommée'] && $inscrits152 === 0 && $troisieme152 && $divergence152
    && $repare152 === 1 && $fermee152 === 'hors_moteur' && $apres152 === 'passé' && $rouvre152 === 'passé'
    && str_contains($lecture152, 'fermée hors du moteur') && is_array($lignes152) && count($lignes152) === 1 && $lignes152[0]['fin'] === null
    && $lignes152[0]['arbitre'] === ['id' => idDe($pdo, 'arbitre-un'), 'nom' => 'arbitre-un']
    ? ok('⭐ une peine orpheline se refuse au ban et à la console en nommant migrerBans(), qui la ferme ; une ligne fermée à la main est rouverte, sans seconde ligne ; un ban reposé ou prolongé hors du moteur se refuse jusqu\'à la reprise, puis retrouve sa ligne, marquée `reprise` quand l\'échéance est recalée')
    : nok('⭐ l\'historique et les bans divergent sans réparation : ' . json_encode([
        'avant' => $avant152, 'inscrits' => $inscrits152, 'repare' => $repare152, 'fermee' => $fermee152, 'apres' => $apres152,
        'lecture' => $lecture152, 'rouvre' => $rouvre152, 'lignes' => $lignes152,
        'reposee' => [$reposee152, $apresReposee152, $apresProlongee152 ?? null],
        'divergente' => [$sansRepriseReposee152, $sansRepriseProlongee152, $luProlongee152, $repriseAvant152, $ligneProlongee152, $finProlongee152],
    ], JSON_UNESCAPED_UNICODE));

// ── 153. ⭐ La dernière décision dit son auteur ─────────────────────────────────
// A bannit, B rallonge sans fin : l'historique et la console disaient A pour une
// décision de B.
$journalAvant153 = Moderate::journal();
Moderate::setJournal(new JournalDeBanc());
$cible153 = membre($pdo, 'cible-153');
poserReputation($pdo, $cible153, 15);
Moderate::adminBan($pdo, $cible153, idDe($pdo, 'arbitre-un'), motif(), 3600);
$plus153 = Moderate::adminProlonger($pdo, $cible153, idDe($pdo, 'arbitre-deux'), motif(), 0, true);
$hist153 = Moderate::peinesDe($pdo, $cible153, precis: true)[0] ?? [];
$console153 = array_values(array_filter(Moderate::bansEnCours($pdo, 100000), static fn (array $b): bool => $b['account_id'] === $cible153))[0] ?? [];
Moderate::adminLever($pdo, $cible153, idDe($pdo, 'arbitre-banc'), motif());
Moderate::setJournal($journalAvant153);
$un153 = ['id' => idDe($pdo, 'arbitre-un'), 'nom' => 'arbitre-un'];
$deux153 = ['id' => idDe($pdo, 'arbitre-deux'), 'nom' => 'arbitre-deux'];
$plus153['ok'] && ($hist153['arbitre'] ?? null) === $un153 && ($hist153['decide_par'] ?? null) === $deux153
    && ($hist153['sans_fin'] ?? null) === true && ($console153['arbitre'] ?? null) === $un153 && ($console153['decide_par'] ?? null) === $deux153
    ? ok('⭐ une prolongation est signée de l\'arbitre qui rallonge, dans l\'historique comme à la console')
    : nok('⭐ la dernière décision est attribuée à un autre arbitre : ' . json_encode(['historique' => $hist153, 'console' => $console153], JSON_UNESCAPED_UNICODE));

// ── 154. ⭐ Une horloge qui ne rend pas un instant se nomme ─────────────────────
// Ramenée à 0 ou à l'année, elle datait un ban de 1970 avec la sentinelle d'une reprise.
$horloges154 = [];
foreach (['null' => null, 'chaine' => '2026-11-10 12:00', 'zero' => 0, 'fin des temps' => Moderate::SANS_FIN] as $forme154 => $valeur154) {
    Moderate::setHorloge(static fn (): mixed => $valeur154);
    try {
        Moderate::getReputation($pdo, idDe($pdo, 'arbitre-banc'));
        $horloges154[$forme154] = 'acceptée';
    } catch (Throwable $e) {
        $horloges154[$forme154] = $e instanceof LogicException && str_contains($e->getMessage(), 'setHorloge()')
            ? 'nommée' : get_class($e) . ' : ' . $e->getMessage();
    } finally {
        Moderate::setHorloge(null);
    }
}
$horloges154 === array_fill_keys(['null', 'chaine', 'zero', 'fin des temps'], 'nommée')
    ? ok('⭐ une horloge qui rend autre chose qu\'un instant entier, positif et avant la fin des temps lève en se nommant')
    : nok('⭐ une horloge invalide passe : ' . json_encode($horloges154, JSON_UNESCAPED_UNICODE));

// ── 155. ⭐ L'historique rend des jours, et la seconde sur demande ──────────────
// Le début d'une peine automatique est l'instant du vote qui l'a causée : à la
// seconde, recoupé avec les présences, il désignerait son auteur.
$jour155 = Moderate::peinesDe($pdo, $chute145)[0] ?? [];
$seconde155 = Moderate::peinesDe($pdo, $chute145, precis: true)[0] ?? [];
$minuit155 = static fn (int $t): int => (int) strtotime(date('Y-m-d', $t));
$sansFin155 = Moderate::peinesDe($pdo, $cible153)[0] ?? [];
($seconde155['debut'] ?? null) === $t145 && ($jour155['debut'] ?? null) === $minuit155($t145)
    && ($jour155['decide_le'] ?? null) === $minuit155($t145) && ($jour155['fin'] ?? null) === $minuit155($seconde155['fin'] ?? 0)
    && ($jour155['echeance'] ?? null) === $minuit155($seconde155['echeance'] ?? 0)
    && ($sansFin155['echeance'] ?? null) === Moderate::SANS_FIN && ($sansFin155['sans_fin'] ?? null) === true
    ? ok('⭐ l\'historique rend ses dates au jour, la seconde sur demande, et un ban sans fin le reste')
    : nok('⭐ l\'historique rend la seconde du vote décisif : ' . json_encode(['jour' => $jour155, 'seconde' => $seconde155, 'sans_fin' => $sansFin155], JSON_UNESCAPED_UNICODE));

// ── 156. ⭐ Le lab suspend la modération d'un administrateur banni par un pair, et seulement elle ─
// Le moteur refuse ses gestes ; la console le lui dit, et ses autres routes
// d'administration — les litiges SelfRecover compris — restent ouvertes.
$journalAvant156 = Moderate::journal();
Moderate::setJournal(new JournalDeBanc());
$session156 = sessionArbitre($pdo, 'admin-banni-156');
$serveur156 = serveurLab();
$libre156 = requeteLab($serveur156, '/admin.php', $session156);
$csrf156 = csrfDeLaPage($libre156['corps']);
Moderate::adminBan($pdo, idDe($pdo, 'admin-banni-156'), idDe($pdo, 'arbitre-banc'), motif(), 3600);
$page156 = requeteLab($serveur156, '/admin.php', $session156);
$route156 = $csrf156 !== null
    ? requeteLab($serveur156, '/api/admin.php', $session156, $csrf156,
        ['action' => 'moderate', 'account_id' => idDe($pdo, 'arbitre-banc'), 'op' => 'ban', 'motif' => motif()])
    : ['code' => 0, 'corps' => ''];
$profil156 = $csrf156 !== null
    ? requeteLab($serveur156, '/api/admin.php', $session156, $csrf156, ['action' => 'profile', 'account_id' => idDe($pdo, 'arbitre-banc')])
    : ['code' => 0, 'corps' => ''];
// Le balayage de tout le site signale les salves et referme les peines : c'est de la modération.
$balayer156 = static fn (): array => $csrf156 !== null
    ? requeteLab($serveur156, '/api/detect_abuse.php', $session156, $csrf156)
    : ['code' => 0, 'corps' => ''];
$balayage156 = $balayer156();
// Un ban d'origine inconnue peut être un ban d'arbitre : le lab le lit comme le moteur.
$pdo->prepare('UPDATE member_moderation SET ban_origine = NULL WHERE account_id = ?')->execute([idDe($pdo, 'admin-banni-156')]);
$pageInconnue156 = requeteLab($serveur156, '/admin.php', $session156);
$balayageInconnu156 = $balayer156();
// Une reprise manquante sur le compte de l'administrateur se dit sur la console, qui
// ne tombe pas.
$pdo->prepare('DELETE FROM mod_peines WHERE account_id = ? AND fin IS NULL')->execute([idDe($pdo, 'admin-banni-156')]);
$pageReprise156 = requeteLab($serveur156, '/admin.php', $session156);
Moderate::migrerBans($pdo);
Moderate::adminLever($pdo, idDe($pdo, 'admin-banni-156'), idDe($pdo, 'arbitre-banc'), motif());
// Banni par la machine, il garde ses gestes : ni bandeau, ni refus du balayage.
poserReputation($pdo, idDe($pdo, 'admin-banni-156'), 1);
Moderate::applyVote($pdo, membre($pdo, 'votant-156'), 'member', idDe($pdo, 'admin-banni-156'), -1, motif(), 'agressif');
$auto156 = Moderate::getReputation($pdo, idDe($pdo, 'admin-banni-156'));
$pageAuto156 = requeteLab($serveur156, '/admin.php', $session156);
$balayageAuto156 = $balayer156();
arreterLab($serveur156);
Moderate::adminLever($pdo, idDe($pdo, 'admin-banni-156'), idDe($pdo, 'arbitre-banc'), motif());
Moderate::setJournal($journalAvant156);
$bandeau156 = 'Tes gestes de modération sont suspendus';
$libre156['code'] === 200 && !str_contains($libre156['corps'], $bandeau156)
    && $page156['code'] === 200 && str_contains($page156['corps'], $bandeau156)
    && $route156['code'] === 400 && str_contains($route156['corps'], 'sous peine') && $profil156['code'] === 200
    && !Moderate::estBanni($pdo, idDe($pdo, 'arbitre-banc'))
    && $balayage156['code'] === 403 && str_contains($balayage156['corps'], 'sous peine')
    && $pageInconnue156['code'] === 200 && str_contains($pageInconnue156['corps'], $bandeau156)
    && $balayageInconnu156['code'] === 403
    && $pageReprise156['code'] === 200 && str_contains($pageReprise156['corps'], 'ne peuvent pas s\'afficher')
    && $auto156['banned'] && $auto156['ban_origine'] === 'auto'
    && $pageAuto156['code'] === 200 && !str_contains($pageAuto156['corps'], $bandeau156)
    && $balayageAuto156['code'] === 200 && str_contains($balayageAuto156['corps'], '"ok":true')
    ? ok('⭐ le lab suspend la modération d\'un administrateur banni par un pair, le lui dit, et lui laisse ses autres routes')
    : nok('⭐ le lab ferme trop, ou pas assez, à un administrateur banni par un pair : ' . json_encode([
        'libre' => $libre156['code'], 'page' => [$page156['code'], str_contains($page156['corps'], $bandeau156)],
        'route' => [$route156['code'], substr($route156['corps'], 0, 160)], 'profil' => [$profil156['code'], substr($profil156['corps'], 0, 120)],
        'balayage' => [$balayage156['code'], substr($balayage156['corps'], 0, 160)],
        'inconnue' => [$pageInconnue156['code'], str_contains($pageInconnue156['corps'], $bandeau156), $balayageInconnu156['code']],
        'reprise' => [$pageReprise156['code'], str_contains($pageReprise156['corps'], 'ne peuvent pas s\'afficher'), substr($pageReprise156['corps'], 0, 200)],
        'auto' => [$auto156['banned'], $auto156['ban_origine'], $pageAuto156['code'], str_contains($pageAuto156['corps'], $bandeau156),
                   $balayageAuto156['code'], substr($balayageAuto156['corps'], 0, 120)],
    ], JSON_UNESCAPED_UNICODE));

// ── 157. ⭐ La règle de l'arbitre sous peine se lit sous le verrou de chaque geste ─
// Un pair bannit l'arbitre juste avant que le geste de celui-ci prenne le verrou :
// lue avant, la règle laissait passer le geste. Les six gestes la lisent chacun dans
// leur transaction ; chacun est joué ici, sa cible dans l'état qu'il demande.
$journalAvant157 = Moderate::journal();
$journal157 = new JournalDeBanc();
Moderate::setJournal($journal157);
$arb157 = membre($pdo, 'arbitre-157');
poserReputation($pdo, $arb157, 15);
$cible157 = static function (string $nom, string $etat) use ($pdo): int {
    $id = membre($pdo, $nom);
    poserReputation($pdo, $id, $etat === 'auto' ? 1 : 15);
    if ($etat === 'banni') {
        Moderate::adminBan($pdo, $id, idDe($pdo, 'arbitre-banc'), motif(), 3600);
    } elseif ($etat === 'auto') {
        Moderate::applyVote($pdo, membre($pdo, "votant-$nom"), 'member', $id, -1, motif(), 'agressif');
    } elseif ($etat === 'signal') {
        planterSignal($pdo, $id, 'salve_rapide');
    }
    rattraper($pdo, $id);   // rien ne reste à rattraper : le premier verrou est celui du geste

    return $id;
};
$gestes157 = [
    'adminBan'       => ['libre',  static fn (PDO $c, int $id): array => Moderate::adminBan($c, $id, $arb157, motif())],
    'adminPardon'    => ['banni',  static fn (PDO $c, int $id): array => Moderate::adminPardon($c, $id, $arb157, motif())],
    'adminLever'     => ['banni',  static fn (PDO $c, int $id): array => Moderate::adminLever($c, $id, $arb157, motif())],
    'adminProlonger' => ['banni',  static fn (PDO $c, int $id): array => Moderate::adminProlonger($c, $id, $arb157, motif(), 600)],
    'adminMaintenir' => ['auto',   static fn (PDO $c, int $id): array => Moderate::adminMaintenir($c, $id, $arb157, motif())],
    'adminClasser'   => ['signal', static fn (PDO $c, int $id): array => Moderate::adminClasser($c, $id, $arb157, motif())],
];
foreach ($gestes157 as $geste157 => [$etat157, $faire157]) {
    $vise157 = $cible157(strtolower($geste157) . '-157', $etat157);
    $avant157 = etatBan($pdo, $vise157);
    $actesAvant157 = count(array_filter($journal157->actes, static fn (array $a): bool => $a['compte'] === $vise157));
    $c157 = new PdoAPointDArret('sqlite:' . getenv('LAB_DB_PATH'));
    $c157->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $c157->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $c157->avantLeVerrou = static function () use ($pdo, $arb157): void {
        Moderate::adminBan($pdo, $arb157, idDe($pdo, 'arbitre-banc'), motif(), 3600);
    };
    $c157->verrous = 0;
    try {
        $r157 = $faire157($c157, $vise157);
    } catch (Throwable $e) {
        $r157 = ['ok' => false, 'message' => get_class($e) . ' : ' . $e->getMessage()];
    }
    $verrous157 = $c157->verrous;
    $c157 = null;
    $actes157 = count(array_filter($journal157->actes, static fn (array $a): bool => $a['compte'] === $vise157)) - $actesAvant157;
    $intacte157 = etatBan($pdo, $vise157) === $avant157;
    Moderate::adminLever($pdo, $arb157, idDe($pdo, 'arbitre-banc'), motif());
    if (in_array($etat157, ['banni', 'auto'], true)) {
        Moderate::adminLever($pdo, $vise157, idDe($pdo, 'arbitre-banc'), motif());
    }
    if ($verrous157 !== 1) {
        nok("le contrôle 157 ne mesure plus rien pour $geste157 : verrous pris par le geste " . $verrous157 . ', le pair n\'agit plus juste avant le verrou');
        continue;
    }
    !$r157['ok'] && str_contains($r157['message'], 'sous peine') && $actes157 === 0 && $intacte157
        ? ok("⭐ $geste157 : un arbitre banni par un pair pendant son geste voit ce geste refusé, sans acte")
        : nok("⭐ $geste157 : un arbitre banni pendant son geste le voit passer : " . json_encode([
            'geste' => $r157, 'actes' => $actes157, 'cible_intacte' => $intacte157,
        ], JSON_UNESCAPED_UNICODE));
}
Moderate::setJournal($journalAvant157);

// ── 158. ⭐ Deux arbitres bannis par la machine se lèvent l'un l'autre, et c'est signé ─
// Des votants ne coupent pas la modération : banni par la machine, un arbitre garde
// ses gestes, celui de lever un pair banni comme lui compris. Le README écrit ce
// prix ; chaque levée est signée de son arbitre.
$journalAvant158 = Moderate::journal();
$journal158 = new JournalDeBanc();
Moderate::setJournal($journal158);
[$a158, $b158] = [membre($pdo, 'auto-a-158'), membre($pdo, 'auto-b-158')];
foreach ([$a158, $b158] as $i158 => $id158) {
    poserReputation($pdo, $id158, 1);
    Moderate::applyVote($pdo, membre($pdo, "votant-$i158-158"), 'member', $id158, -1, motif(), 'agressif');
}
$origines158 = [Moderate::getReputation($pdo, $a158)['ban_origine'], Moderate::getReputation($pdo, $b158)['ban_origine']];
$aLeveB158 = Moderate::adminLever($pdo, $b158, $a158, motif());
$bLeveA158 = Moderate::adminLever($pdo, $a158, $b158, motif());
$signes158 = array_map(static fn (array $a): array => [$a['compte'], $a['arbitre_id'] ?? null],
    array_merge($journal158->de($b158, 'ban_leve'), $journal158->de($a158, 'ban_leve')));
$libres158 = [Moderate::estBanni($pdo, $a158), Moderate::estBanni($pdo, $b158)];
Moderate::setJournal($journalAvant158);
$origines158 === ['auto', 'auto'] && $aLeveB158['ok'] && $bLeveA158['ok']
    && $signes158 === [[$b158, $a158], [$a158, $b158]] && $libres158 === [false, false]
    ? ok('⭐ deux arbitres bannis par la machine se lèvent l\'un l\'autre, chaque levée signée de son arbitre')
    : nok('⭐ la levée croisée de deux arbitres bannis par la machine ne tient pas ce que le README écrit : ' . json_encode([
        'origines' => $origines158, 'a_leve_b' => $aLeveB158, 'b_leve_a' => $bLeveA158, 'signes' => $signes158, 'bannis' => $libres158,
    ], JSON_UNESCAPED_UNICODE));

// ── 159. ⭐ Deux membres qui votent l'un contre l'autre se croisent ─────────────
// A vote contre B, puis B contre A dans la fenêtre, quel que soit le message : les
// deux votes tombent dans la transaction du second, chacun rend son point, et une
// trêve de la même durée suit. Les votes tombés comptent dans les plafonds, et le
// croisement ne lève aucune peine.
$t159 = time();
Moderate::setHorloge(static function () use (&$t159): int { return $t159; });
$journalAvant159 = Moderate::journal();
Moderate::setJournal(new JournalDeBanc());
$fenetre159 = Moderate::config()->fenetreCroiseeSecondes;
$sorts159 = static function (int $de, int $vers) use ($pdo): array {
    $s = $pdo->prepare('SELECT blocked, blocked_reason FROM mod_votes WHERE voter_id = ? AND target_author = ? ORDER BY id');
    $s->execute([$de, $vers]);
    return array_map(static fn (array $v): string => (int) $v['blocked'] ? (string) $v['blocked_reason'] : 'porte', $s->fetchAll());
};
$raison159 = static fn (array $r): string => !$r['ok'] ? 'refus : ' . $r['message'] : ($r['blocked_reason'] ?? 'porte');
[$a159, $b159] = [membre($pdo, 'croise-a-159'), membre($pdo, 'croise-b-159')];
poserReputation($pdo, $a159, 15);
poserReputation($pdo, $b159, 15);
$messagesA159 = [message($pdo, $a159, 'fil 159 a1'), message($pdo, $a159, 'fil 159 a2')];
$messagesB159 = [message($pdo, $b159, 'fil 159 b1'), message($pdo, $b159, 'fil 159 b2'), message($pdo, $b159, 'fil 159 b3')];
$premier159 = Moderate::applyVote($pdo, $a159, 'post', $messagesB159[0], -1, motif());
$bApresLePremier159 = reputation($pdo, $b159);
$t159 += 60;
$riposte159 = Moderate::applyVote($pdo, $b159, 'post', $messagesA159[0], -1, motif());
$croisement159 = [
    'reputations' => [reputation($pdo, $a159), reputation($pdo, $b159)],
    'b_apres_le_premier' => $bApresLePremier159,
    'sorts' => [$sorts159($a159, $b159), $sorts159($b159, $a159)],
    'reponses' => [$premier159['message'], $riposte159['message'], $riposte159['blocked'] ?? null],
    'mes_votes' => [Moderate::userVote($pdo, $a159, 'post', $messagesB159[0]), Moderate::userVote($pdo, $b159, 'post', $messagesA159[0])],
];
$croisement159 === ['reputations' => [15, 15], 'b_apres_le_premier' => 14, 'sorts' => [['vote_croise'], ['riposte']],
                    'reponses' => ['Vote enregistré.', 'Vote enregistré.', true], 'mes_votes' => [-1, -1]]
    ? ok('⭐ deux membres qui votent l\'un contre l\'autre se croisent : les deux votes tombent dans la transaction du second, chacun rend son point, la réponse est celle d\'un vote compté, et chacun garde son vote sur sa page')
    : nok('⭐ le croisement rate : ' . json_encode($croisement159, JSON_UNESCAPED_UNICODE));

$t159 += 60;
$treve159 = [
    $raison159(Moderate::applyVote($pdo, $a159, 'post', $messagesB159[1], -1, motif())),
    $raison159(Moderate::applyVote($pdo, $b159, 'post', $messagesA159[1], -1, motif())),
];
$repsTreve159 = [reputation($pdo, $a159), reputation($pdo, $b159)];
// La trêve court depuis le croisement, pas depuis le vote de face, posé 60 s plus
// tôt : une seconde avant sa fin, elle tient encore.
$t159 += $fenetre159 - 61;
$bordTreve159 = $raison159(Moderate::applyVote($pdo, $b159, 'post', message($pdo, $a159, 'fil 159 a3'), -1, motif()));
$t159 += 61;   // la trêve est passée
$apresTreve159 = $raison159(Moderate::applyVote($pdo, $a159, 'post', $messagesB159[2], -1, motif()));
$bApresTreve159 = reputation($pdo, $b159);
// Trois votes de A contre B dans la fenêtre longue — tombé, tombé, compté : le plafond est plein.
$plafond159 = $raison159(Moderate::applyVote($pdo, $a159, 'member', $b159, -1, motif()));
$treve159 === ['treve', 'treve'] && $repsTreve159 === [15, 15] && $bordTreve159 === 'treve' && $apresTreve159 === 'porte' && $bApresTreve159 === 14
    && $plafond159 === 'downvote_farming'
    ? ok('⭐ une trêve suit le croisement : pendant elle, aucun des deux ne vote contre l\'autre ; après elle, le vote porte ; et les votes tombés comptent dans les plafonds')
    : nok('⭐ la trêve ou les plafonds ratent : ' . json_encode([
        'treve' => $treve159, 'reputations' => $repsTreve159, 'bord' => $bordTreve159, 'apres' => $apresTreve159, 'b' => $bApresTreve159, 'plafond' => $plafond159,
    ], JSON_UNESCAPED_UNICODE));

[$c159, $d159] = [membre($pdo, 'hors-c-159'), membre($pdo, 'hors-d-159')];
Moderate::applyVote($pdo, $c159, 'post', message($pdo, $d159, 'fil 159 d'), -1, motif());
$t159 += $fenetre159 + 1;
$horsFenetre159 = $raison159(Moderate::applyVote($pdo, $d159, 'post', message($pdo, $c159, 'fil 159 c'), -1, motif()));
[$e159, $f159] = [membre($pdo, 'haut-e-159'), membre($pdo, 'haut-f-159')];
Moderate::applyVote($pdo, $e159, 'member', $f159, 1);
$haut159 = $raison159(Moderate::applyVote($pdo, $f159, 'member', $e159, -1, motif()));
[$g159, $h159] = [membre($pdo, 'un-g-159'), membre($pdo, 'un-h-159')];
poserReputation($pdo, $h159, 15);
Moderate::applyVote($pdo, $g159, 'post', message($pdo, $h159, 'fil 159 h1'), -1, motif());
$t159 += 10;
Moderate::applyVote($pdo, $g159, 'post', message($pdo, $h159, 'fil 159 h2'), -1, motif());
$t159 += 10;
Moderate::applyVote($pdo, $h159, 'post', message($pdo, $g159, 'fil 159 g'), -1, motif());
$unPourUn159 = [$sorts159($g159, $h159), reputation($pdo, $h159)];
$horsFenetre159 === 'porte' && $haut159 === 'porte' && $unPourUn159 === [['porte', 'vote_croise'], 14]
    ? ok('⭐ le croisement s\'en tient à sa règle : hors de la fenêtre rien ne se croise, un vote positif non plus, et un vote ne fait tomber qu\'un vote — le plus récent de l\'autre')
    : nok('⭐ le croisement déborde de sa règle : ' . json_encode([
        'hors_fenetre' => $horsFenetre159, 'positif' => $haut159, 'un_pour_un' => $unPourUn159,
    ], JSON_UNESCAPED_UNICODE));

// Aucune peine levée : banni par un arbitre entre son contrôle et le verrou de son
// vote, le membre qui riposte retrouve son point, pas sa liberté. (Le vote d'un banni
// sous le verrou est une course que le lot suivant ferme ; la règle tient d'ici là.)
// Le ban est d'arbitre : une chute au plancher le mettrait sous le seuil du vote, où
// plus rien ne se croise.
$v159 = membre($pdo, 'riposte-v-159');
poserReputation($pdo, $v159, 7);
$face159 = membre($pdo, 'face-159');
$messageFace159 = message($pdo, $face159, 'fil 159 face');
Moderate::applyVote($pdo, $face159, 'post', message($pdo, $v159, 'fil 159 v'), -1, motif());
$c159p = new PdoAPointDArret('sqlite:' . getenv('LAB_DB_PATH'));
$c159p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$c159p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$c159p->avantLeVerrou = static function () use ($pdo, $v159): void {
    Moderate::adminBan($pdo, $v159, idDe($pdo, 'arbitre-banc'), motif());
};
$c159p->verrous = 0;
try {
    $riposteBannie159 = Moderate::applyVote($c159p, $v159, 'post', $messageFace159, -1, motif());
} catch (Throwable $e) {
    $riposteBannie159 = ['ok' => false, 'message' => get_class($e) . ' : ' . $e->getMessage()];
}
$verrous159 = $c159p->verrous;
$c159p = null;
// Une peine levée hors de sa ligne d'historique se lit comme une reprise manquante :
// c'est la levée elle-même, que le cas rapporte. La base est remise d'accord pour la
// suite du banc — rejouée sur une base d'accord, `migrerBans()` ne fait rien.
try {
    $etatV159 = Moderate::getReputation($pdo, $v159);
    $faceSort159 = $sorts159($face159, $v159);
    if ($etatV159['banned']) {
        Moderate::adminLever($pdo, $v159, idDe($pdo, 'arbitre-banc'), motif());
    }
} catch (\Pierroons\SelfModerate\RepriseManquante $e159) {
    $etatV159 = ['banned' => false, 'ban_origine' => null, 'strikes' => null, 'reputation' => null, 'erreur' => $e159->getMessage()];
    $faceSort159 = $sorts159($face159, $v159);
}
Moderate::migrerBans($pdo);
Moderate::setHorloge(null);
Moderate::setJournal($journalAvant159);
if ($verrous159 !== 1) {
    nok('le contrôle 159 ne mesure plus rien : verrous pris par la riposte ' . $verrous159 . ', la chute n\'arrive plus juste avant le verrou');
} else {
    $etatV159['banned'] && $etatV159['ban_origine'] === 'admin' && $etatV159['strikes'] === 1 && $faceSort159 === ['vote_croise']
        && $etatV159['reputation'] === 7
        ? ok('⭐ un croisement ne lève aucune peine : banni entre son contrôle et le verrou, le membre qui riposte retrouve son point, pas sa liberté, et garde son strike')
        : nok('⭐ un croisement lève une peine : ' . json_encode([
            'riposte' => $riposteBannie159, 'etat' => [$etatV159['banned'], $etatV159['ban_origine'], $etatV159['strikes'], $etatV159['reputation'], $etatV159['erreur'] ?? null],
            'vote_en_face' => $faceSort159,
        ], JSON_UNESCAPED_UNICODE));
}

// ── 160. ⭐ La fin d'un ban automatique se montre arrondie, « au plus tard » ────────
// Elle moins son palier redonne l'instant du vote décisif : à la seconde, elle le
// livrait à qui recoupe les présences. Posée arrondie (n° 164), elle se montre
// telle quelle ; un ban posé avant s'arrondit à l'affichage. Un ban d'arbitre,
// signé, reste à la minute.
$heure160 = 1_800_000_000 - 1_800_000_000 % 3600;
$pas160 = Moderate::config()->arrondiFinBanSecondes;
$fins160 = [
    Moderate::finAffichee($heure160 + 1, 'auto') === $heure160 + $pas160,
    sousConfig(configBanc(dureesBan: [3600], arrondiFinBanSecondes: 3600), static fn (): int => Moderate::finAffichee($heure160 + 1, 'auto')) === $heure160 + 3600,
    Moderate::finAffichee($heure160, 'auto') === $heure160,
    Moderate::finAffichee($heure160 + 1, 'admin') === $heure160 + 1,
    Moderate::finAffichee($heure160 + 1, null) === $heure160 + 1,
    Moderate::finAffichee(Moderate::SANS_FIN, 'auto') === Moderate::SANS_FIN,
    Moderate::finAffichee(0, 'auto') === 0,
];
$auto160 = membre($pdo, 'fin-auto-160');
poserReputation($pdo, $auto160, 1);
Moderate::applyVote($pdo, membre($pdo, 'votant-160'), 'member', $auto160, -1, motif(), 'agressif');
$etatAuto160 = Moderate::getReputation($pdo, $auto160);
[$peutAuto160, $refusAuto160] = Moderate::canVote($pdo, $auto160);
$attenduAuto160 = date('d/m/Y H:i', Moderate::finAffichee($etatAuto160['banned_until'], 'auto'));
$arbitre160 = membre($pdo, 'fin-arbitre-160');
poserReputation($pdo, $arbitre160, 15);
Moderate::adminBan($pdo, $arbitre160, idDe($pdo, 'arbitre-banc'), motif(), 3600);
[$peutArbitre160, $refusArbitre160] = Moderate::canVote($pdo, $arbitre160);
$attenduArbitre160 = date('d/m/Y H:i', Moderate::getReputation($pdo, $arbitre160)['banned_until']);
// Un ban posé avant l'arrondi garde sa fin exacte, peine comprise : le refus du vote
// et la page du lab (`finDeBan()`) l'arrondissent.
$ancien160 = membre($pdo, 'fin-ancienne-160');
poserReputation($pdo, $ancien160, 1);
Moderate::applyVote($pdo, membre($pdo, 'votant-ancien-160'), 'member', $ancien160, -1, motif(), 'agressif');
$pdo->prepare('UPDATE member_moderation SET banned_until = banned_until + 7 WHERE account_id = ?')->execute([$ancien160]);
$pdo->prepare('UPDATE mod_peines SET echeance = echeance + 7 WHERE account_id = ? AND fin IS NULL')->execute([$ancien160]);
$etatAncien160 = Moderate::getReputation($pdo, $ancien160);
[, $refusAncien160] = Moderate::canVote($pdo, $ancien160);
$montreeAncien160 = date('d/m/Y H:i', $etatAncien160['banned_until'] - 7 + $pas160) . ' au plus tard';
$pageAncien160 = Moderate::finDeBan($etatAncien160);
Moderate::adminLever($pdo, $ancien160, idDe($pdo, 'arbitre-banc'), motif());
Moderate::adminLever($pdo, $auto160, idDe($pdo, 'arbitre-banc'), motif());
Moderate::adminLever($pdo, $arbitre160, idDe($pdo, 'arbitre-banc'), motif());
!in_array(false, $fins160, true) && $etatAuto160['banned']
    && !$peutAuto160 && str_contains($refusAuto160, $attenduAuto160 . ' au plus tard')
    && $etatAuto160['banned_until'] % $pas160 === 0 && Moderate::finAffichee($etatAuto160['banned_until'], 'auto') === $etatAuto160['banned_until']
    && str_contains($refusAncien160, $montreeAncien160) && $pageAncien160 === $montreeAncien160
    && !$peutArbitre160 && str_contains($refusArbitre160, $attenduArbitre160) && !str_contains($refusArbitre160, 'au plus tard')
    ? ok('⭐ la fin d\'un ban automatique se montre arrondie, « au plus tard » — le refus du vote compris ; un ban d\'arbitre reste à la minute')
    : nok('⭐ la fin d\'un ban redonne l\'instant du vote décisif : ' . json_encode([
        'fins' => $fins160, 'auto' => [$peutAuto160, $refusAuto160, $attenduAuto160], 'arbitre' => [$peutArbitre160, $refusArbitre160, $attenduArbitre160],
        'ancien' => [$refusAncien160, $pageAncien160, $montreeAncien160],
    ], JSON_UNESCAPED_UNICODE));

// ── 161. ⭐ Le lab ne dit pas le sort d'un vote, et un blocage n'écrit rien dans dm ─
// La route de vote, la page publique et le profil d'un banni sont les canaux par
// lesquels le lab laissait lire un croisement ou l'instant d'un vote ; un message
// reçu sous blocage ferait, dans `dm`, un lien entre un harceleur et sa victime.
$journalAvant161 = Moderate::journal();
Moderate::setJournal(new JournalDeBanc());
$serveur161 = serveurLab();
$sessionB161 = sessionMembre($pdo, 'route-b-161');
$sessionC161 = sessionMembre($pdo, 'route-c-161');
[$a161, $b161] = [membre($pdo, 'route-a-161'), idDe($pdo, 'route-b-161')];
poserReputation($pdo, $a161, 15);
poserReputation($pdo, $b161, 15);
$messageA161 = message($pdo, $a161, 'fil 161 a');
Moderate::applyVote($pdo, $a161, 'post', message($pdo, $b161, 'fil 161 b'), -1, motif());
$voter161 = static function (string $session, int $post) use ($serveur161): array {
    $csrf = csrfDeLaPage(requeteLab($serveur161, '/messages.php', $session)['corps']);
    $r = requeteLab($serveur161, '/api/vote.php', $session, $csrf, ['target_type' => 'post', 'target_id' => $post, 'value' => -1, 'reason' => motif()]);
    return [$r['code'], json_decode($r['corps'], true) ?? []];
};
[$codeCroise161, $croise161] = $voter161($sessionB161, $messageA161);
// Sa page compte le vote croisé parmi ses votes émis, comme un vote compté.
$emisB161 = (int) $pdo->query("SELECT COUNT(*) FROM mod_votes WHERE voter_id = $b161")->fetchColumn();
$pageB161 = requeteLab($serveur161, '/profile.php', $sessionB161)['corps'];
$emisAffiches161 = preg_match('#' . preg_quote(t('prf.act.given'), '#') . '</span><br><strong[^>]*>(\d+)</strong>#u', $pageB161, $mE161) === 1 ? (int) $mE161[1] : null;
[$codePorte161, $porte161] = $voter161($sessionC161, message($pdo, membre($pdo, 'route-d-161'), 'fil 161 d'));
$codeCroise161 === 200 && $croise161 === ['ok' => true, 'message' => 'Vote enregistré.']
    && $codePorte161 === 200 && $porte161 === $croise161 && $emisAffiches161 === $emisB161 && $emisB161 === 1
    ? ok('⭐ la route de vote rend la même réponse à un vote qui porte et à un vote croisé — ok et le message, ni score ni sort ; la page du votant le compte parmi ses votes')
    : nok('⭐ la route de vote dit le sort d\'un vote croisé : ' . json_encode([$codeCroise161, $croise161, $codePorte161, $porte161,
        'emis' => [$emisB161, $emisAffiches161]], JSON_UNESCAPED_UNICODE));

$publique161 = requeteLab($serveur161, '/moderation.php');
$arbitrale161 = requeteLab($serveur161, '/moderation.php', sessionArbitre($pdo, 'arbitre-moderation-161'));
$publique161['code'] === 200 && !str_contains($publique161['corps'], 'vote_croise') && !str_contains($publique161['corps'], 'treve')
    && !str_contains($publique161['corps'], 'riposte')
    && $arbitrale161['code'] === 200 && str_contains($arbitrale161['corps'], 'vote_croise')
    ? ok('⭐ la page publique ne liste ni vote croisé, ni riposte, ni trêve ; l\'arbitre les voit')
    : nok('⭐ la page publique publie un croisement : ' . json_encode([
        'publique' => [$publique161['code'], str_contains($publique161['corps'], 'vote_croise'), str_contains($publique161['corps'], 'riposte'),
            str_contains($publique161['corps'], 'treve')],
        'arbitre' => [$arbitrale161['code'], str_contains($arbitrale161['corps'], 'vote_croise')],
    ], JSON_UNESCAPED_UNICODE));

$sessionE161 = sessionMembre($pdo, 'profil-e-161');
$e161 = idDe($pdo, 'profil-e-161');
poserReputation($pdo, $e161, 1);
Moderate::applyVote($pdo, membre($pdo, 'votant-161'), 'member', $e161, -1, motif(), 'agressif');
$profilE161 = requeteLab($serveur161, '/profile.php', $sessionE161);
// Les dates attendues, formées dans le fuseau des pages du lab.
$finE161 = Moderate::getReputation($pdo, $e161)['banned_until'];
$fuseauAvant161 = date_default_timezone_get();
date_default_timezone_set('Europe/Paris');
$arrondie161 = date('d/m/Y H:i', Moderate::finAffichee($finE161, 'auto')) . ' au plus tard';
date_default_timezone_set($fuseauAvant161);
Moderate::adminLever($pdo, $e161, idDe($pdo, 'arbitre-banc'), motif());
$profilE161['code'] === 200 && substr_count($profilE161['corps'], $arrondie161) >= 2
    && $finE161 % Moderate::config()->arrondiFinBanSecondes === 0
    ? ok('⭐ le profil d\'un compte banni par la machine dit la fin arrondie, « au plus tard »')
    : nok('⭐ le profil d\'un banni dit une fin exacte : ' . json_encode([
        $profilE161['code'], $arrondie161, $finE161, substr_count($profilE161['corps'], $arrondie161),
        preg_match_all('#\d{2}/\d{2}/\d{4} \d{2}:\d{2}[^<]{0,14}#u', $profilE161['corps'], $m161) ? $m161[0] : [],
    ], JSON_UNESCAPED_UNICODE));

$sessionX161 = sessionMembre($pdo, 'bloque-x-161');
$sessionY161 = sessionMembre($pdo, 'bloque-y-161');
[$x161, $y161] = [idDe($pdo, 'bloque-x-161'), idDe($pdo, 'bloque-y-161')];
messagePrive($pdo, $x161, $y161);   // X a écrit à Y avant de le bloquer
$csrfX161 = csrfDeLaPage(requeteLab($serveur161, '/messages.php', $sessionX161)['corps']);
$csrfY161 = csrfDeLaPage(requeteLab($serveur161, '/messages.php', $sessionY161)['corps']);
$bloquer161 = static fn (string $action): int => requeteLab($serveur161, '/api/dm_block.php', $sessionX161, $csrfX161,
    ['action' => $action, 'membre' => 'bloque-y-161'])['code'];
$ecrire161 = static function (string $session, ?string $csrf, string $a) use ($serveur161): array {
    $r = requeteLab($serveur161, '/api/dm_send.php', $session, $csrf, ['destinataire' => $a, 'message' => 'Bonjour, une question sur ton message.']);
    return [$r['code'], json_decode($r['corps'], true)['error'] ?? null];
};
$dmYX161 = static fn (): int => (int) $pdo->query("SELECT COUNT(*) FROM dm WHERE sender_id = $y161 AND recipient_id = $x161")->fetchColumn();
$recusDeY161 = static fn (): int => count(array_filter(
    json_decode(requeteLab($serveur161, '/api/dm_inbox.php', $sessionX161)['corps'], true)['inbox'] ?? [],
    static fn (array $m): bool => $m['expediteur'] === 'bloque-y-161'
));
$blocage161 = ['bloquer' => $bloquer161('bloquer')];
$blocage161['y_ecrit'] = $ecrire161($sessionY161, $csrfY161, 'bloque-x-161');
$blocage161['x_ecrit'] = $ecrire161($sessionX161, $csrfX161, 'bloque-y-161');
$blocage161['sous_blocage'] = [$dmYX161(), Moderate::areLinked($pdo, $x161, $y161)];
$blocage161['debloquer'] = $bloquer161('debloquer');
$blocage161['y_ecrit_apres'] = $ecrire161($sessionY161, $csrfY161, 'bloque-x-161');
$blocage161['debloque'] = [$dmYX161(), Moderate::areLinked($pdo, $x161, $y161)];
$blocage161['rebloquer'] = $bloquer161('bloquer');
$blocage161['masque'] = [$recusDeY161(), $dmYX161()];
$blocage161['redebloquer'] = $bloquer161('debloquer');
$blocage161['revenu'] = $recusDeY161();
arreterLab($serveur161);
Moderate::setJournal($journalAvant161);
$blocage161 === ['bloquer' => 200, 'y_ecrit' => [400, 'bloque'], 'x_ecrit' => [400, 'bloque'], 'sous_blocage' => [0, false],
                 'debloquer' => 200, 'y_ecrit_apres' => [201, null], 'debloque' => [1, true],
                 'rebloquer' => 200, 'masque' => [0, 1], 'redebloquer' => 200, 'revenu' => 1]
    ? ok('⭐ un blocage refuse l\'envoi dans les deux sens sans rien écrire dans dm, masque ce qui était reçu sans l\'effacer, et se lève')
    : nok('⭐ un blocage laisse un message entrer dans dm, ou efface : ' . json_encode($blocage161, JSON_UNESCAPED_UNICODE));

// ── 162. ⭐ Un croisement rend le point, pas la guérison ───────────────────────
// Un convalescent qui riposte retrouve le point du vote croisé ; sa convalescence
// et le signal de sa chute restent. La fermer l'aurait figé à son score du moment,
// et effacé le signal qu'un complice n'avait qu'à faire tomber.
$t162 = time();
Moderate::setHorloge(static function () use (&$t162): int { return $t162; });
$journalAvant162 = Moderate::journal();
Moderate::setJournal(new JournalDeBanc());
[$conv162, $critique162] = [membre($pdo, 'convalescent-162'), membre($pdo, 'critique-162')];
poserReputation($pdo, $conv162, 6);
poserReputation($pdo, $critique162, 15);
$pdo->prepare('UPDATE member_moderation SET convalescent = 1, last_regen_at = ? WHERE account_id = ?')->execute([$t162, $conv162]);
planterSignal($pdo, $conv162, 'reputation_zero');
Moderate::applyVote($pdo, $critique162, 'post', message($pdo, $conv162, 'fil 162 c'), -1, motif());
$avant162 = reputation($pdo, $conv162);
$riposte162 = Moderate::applyVote($pdo, $conv162, 'post', message($pdo, $critique162, 'fil 162 k'), -1, motif());
$apres162 = [
    'reputation'   => reputation($pdo, $conv162),
    'convalescent' => (int) etatBan($pdo, $conv162)['convalescent'],
    'chute'        => in_array('reputation_zero', causes($pdo, $conv162), true),
];
Moderate::setHorloge(null);
Moderate::setJournal($journalAvant162);
($riposte162['blocked_reason'] ?? null) === 'riposte' && $avant162 === 5
    && $apres162 === ['reputation' => 6, 'convalescent' => 1, 'chute' => true]
    ? ok('⭐ un convalescent qui riposte retrouve son point, et reste convalescent, le signal de sa chute avec lui')
    : nok('⭐ un croisement ferme la convalescence : ' . json_encode([$riposte162, $avant162, $apres162], JSON_UNESCAPED_UNICODE));

// ── 163. ⭐ Au croisement, aucune page ne change ────────────────────────────────
// Le vote de face, qui avait porté, garde son motif et sa place parmi les votes
// reçus de sa cible. La riposte et un vote de trêve n'ont jamais porté : montrés,
// un motif apparu sans point perdu désignerait leur auteur. Le seuil des votants
// ne se franchit pas par eux.
[$a163, $b163, $f163, $g163] = [membre($pdo, 'motif-a-163'), membre($pdo, 'motif-b-163'), membre($pdo, 'motif-f-163'), membre($pdo, 'motif-g-163')];
foreach ([$a163, $b163, $f163, $g163] as $m163) {
    poserReputation($pdo, $m163, 15);
}
$motifs163 = ['a' => motif(), 'b' => motif(), 'treve' => motif(), 'f' => motif(), 'g' => motif()];
$page163 = static function (int $id) use ($pdo): array {
    $lus = array_column(Moderate::reasonsFor($pdo, $id), 'reason');
    sort($lus);
    return [$lus, Moderate::votesRecus($pdo, $id)];
};
Moderate::applyVote($pdo, $a163, 'post', message($pdo, $b163, 'fil 163 b1'), -1, $motifs163['a']);
$pages163 = ['face' => [$page163($a163), $page163($b163)]];
Moderate::applyVote($pdo, $b163, 'post', message($pdo, $a163, 'fil 163 a1'), -1, $motifs163['b']);
$pages163['riposte'] = [$page163($a163), $page163($b163)];
Moderate::applyVote($pdo, $a163, 'post', message($pdo, $b163, 'fil 163 b2'), -1, $motifs163['treve']);
$pages163['treve'] = [$page163($a163), $page163($b163)];
Moderate::applyVote($pdo, $f163, 'post', message($pdo, $b163, 'fil 163 b3'), -1, $motifs163['f']);
Moderate::applyVote($pdo, $g163, 'post', message($pdo, $a163, 'fil 163 a2'), -1, $motifs163['g']);
$sorts163 = [
    array_column($pdo->query("SELECT blocked_reason FROM mod_votes WHERE voter_id = $a163 AND target_author = $b163 ORDER BY id")->fetchAll(), 'blocked_reason'),
    array_column($pdo->query("SELECT blocked_reason FROM mod_votes WHERE voter_id = $b163 AND target_author = $a163 ORDER BY id")->fetchAll(), 'blocked_reason'),
];
$attenduB163 = [$motifs163['a'], $motifs163['f']];
sort($attenduB163);
$finB163 = $page163($b163);
// Sous un seuil de deux votants : B en a deux qui ont porté (la face et F), A un seul (G) — la riposte ne compte pas.
$seuil163 = sousConfig(configBanc(motifsVotantsMin: 2), static fn (): array => [count(Moderate::reasonsFor($pdo, $b163)), count(Moderate::reasonsFor($pdo, $a163))]);
$immobile163 = [[[], 0], [[$motifs163['a']], 1]];
$sorts163 === [['vote_croise', 'treve'], ['riposte']]
    && $pages163 === ['face' => $immobile163, 'riposte' => $immobile163, 'treve' => $immobile163]
    && $finB163 === [$attenduB163, 2] && $seuil163 === [2, 0]
    ? ok('⭐ au croisement, aucune page ne change : la face garde son motif et sa place, la riposte et la trêve ne se montrent pas, ni au seuil des votants')
    : nok('⭐ un croisement se lit sur la page d\'un des deux : ' . json_encode([$sorts163, $pages163, $finB163, $seuil163], JSON_UNESCAPED_UNICODE));

// ── 164. ⭐ La fin réelle d'un ban automatique s'arrondit dès la pose ──────────
// Sa fin moins son palier redonnait la seconde du vote décisif, et le compte qui
// revote chaque minute la retrouvait par l'effet. Arrondie à la pose, elle est la
// même dans la ligne, au journal et dans l'historique, et la peine cède à elle.
$t164 = intdiv(time(), 3600) * 3600 + 3600 + 1801;
Moderate::setHorloge(static function () use (&$t164): int { return $t164; });
$journalAvant164 = Moderate::journal();
$journal164 = new JournalDeBanc();
Moderate::setJournal($journal164);
$fins164 = [];
foreach ([60, 3600] as $pas164) {
    $fins164[$pas164] = sousConfig(configBanc(dureesBan: [3600], arrondiFinBanSecondes: $pas164), static function () use ($pdo, $pas164, &$t164, $journal164): array {
        $debut = $t164;
        $chute = membre($pdo, "fin-reelle-164-$pas164");
        poserReputation($pdo, $chute, 1);
        Moderate::applyVote($pdo, membre($pdo, "votant-164-$pas164"), 'member', $chute, -1, motif(), 'agressif');
        $fin = (int) etatBan($pdo, $chute)['banned_until'];
        $palier = Moderate::config()->dureeBanPourEpisode(1);
        $lu = [
            'multiple'   => $fin % $pas164 === 0,
            'borne'      => $fin > $debut + $palier && $fin < $debut + $palier + $pas164,
            'journal'    => ($journal164->de($chute, 'ban_debut')[0]['jusqu_a'] ?? null) === $fin,
            'duree'      => ($journal164->de($chute, 'ban_debut')[0]['duree'] ?? null) === $fin - $debut,
            'historique' => (Moderate::peinesDe($pdo, $chute, precis: true)[0]['echeance'] ?? null) === $fin,
            'affichee'   => Moderate::finAffichee($fin, 'auto') === $fin,
        ];
        $t164 = $fin - 1;
        $lu['avant'] = Moderate::getReputation($pdo, $chute)['banned'];
        $t164 = $fin;
        $lu['a_la_fin'] = Moderate::getReputation($pdo, $chute)['banned'];
        $t164 = $debut;
        Moderate::adminLever($pdo, $chute, idDe($pdo, 'arbitre-banc'), motif());
        return $lu;
    });
}
Moderate::setHorloge(null);
Moderate::setJournal($journalAvant164);
$attendu164 = ['multiple' => true, 'borne' => true, 'journal' => true, 'duree' => true, 'historique' => true, 'affichee' => true, 'avant' => true, 'a_la_fin' => false];
$fins164 === [60 => $attendu164, 3600 => $attendu164]
    ? ok('⭐ la fin d\'un ban automatique se pose arrondie — ligne, journal et historique d\'accord —, et la peine cède à elle, à la minute comme à l\'heure')
    : nok('⭐ la fin réelle d\'un ban automatique redonne la seconde du vote : ' . json_encode($fins164, JSON_UNESCAPED_UNICODE));
$bornes164 = [];
// Un palier d'un jour se joue en service, la démonstration le refusant ; les
// autres en démonstration, où le pas descend sous l'heure.
foreach ([[0, 120], [1, 120], [120, 120], [121, 120], [86400, 86400], [86401, 86400]] as [$pas164, $palier164]) {
    try {
        $palier164 > Config::PALIER_DEMONSTRATION_MAX
            ? configService(dureesBan: [$palier164], arrondiFinBanSecondes: $pas164)
            : new Config(Messagerie::DM, Usage::DEMONSTRATION, dureesBan: [$palier164], arrondiFinBanSecondes: $pas164);
        $bornes164["$pas164/$palier164"] = 'acceptée';
    } catch (InvalidArgumentException) {
        $bornes164["$pas164/$palier164"] = 'refusée';
    }
}
$bornes164 === ['0/120' => 'refusée', '1/120' => 'acceptée', '120/120' => 'acceptée', '121/120' => 'refusée',
                '86400/86400' => 'acceptée', '86401/86400' => 'refusée']
    ? ok('le pas d\'arrondi d\'une fin de ban se borne entre une seconde et un jour, et ne dépasse pas le plus court palier')
    : nok('le pas d\'arrondi passe hors de ses bornes : ' . json_encode($bornes164));

// ── 166. ⭐ Un blocage posé pendant l'envoi refuse encore le message ─────────────
// L'envoi lisait le blocage, chiffrait, puis insérait : un blocage posé entre les
// deux laissait entrer le message dans `dm`, et le lien que le moteur y lit.
require_once __DIR__ . '/../lib/dm.php';
final class PdoAvantLInsertion extends PDO
{
    public ?Closure $avantLInsertion = null;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->avantLInsertion !== null && str_starts_with(ltrim($query), 'INSERT INTO dm ')) {
            $interlude = $this->avantLInsertion;
            $this->avantLInsertion = null;
            $interlude();
        }

        return parent::prepare($query, $options);
    }
}
[$x166, $y166, $z166] = [membre($pdo, 'envoi-x-166'), membre($pdo, 'envoi-y-166'), membre($pdo, 'envoi-z-166')];
$c166 = new PdoAvantLInsertion('sqlite:' . getenv('LAB_DB_PATH'));
$c166->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$c166->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
// L'envoi passe par le lab entier (chiffrement, génération de la base) : ce qui
// s'y casse rougit ici au lieu de faire tomber le banc.
try {
    $temoin166 = \Pierroons\MySelfLab\DM::send($c166, $x166, 'envoi-z-166', 'Bonjour, une question sur ton message.');
    $c166->avantLInsertion = static function () use ($pdo, $y166): void {
        \Pierroons\MySelfLab\DM::bloquer($pdo, $y166, 'envoi-x-166');
    };
    $envoi166 = \Pierroons\MySelfLab\DM::send($c166, $x166, 'envoi-y-166', 'Bonjour, une question sur ton message.');
    $course166 = [
        'interlude' => $c166->avantLInsertion === null,
        'refus'     => $envoi166['error'] ?? null,
        'dm'        => (int) $pdo->query("SELECT COUNT(*) FROM dm WHERE sender_id = $x166 AND recipient_id = $y166")->fetchColumn(),
        'lies'      => Moderate::areLinked($pdo, $x166, $y166),
    ];
} catch (Throwable $e) {
    $temoin166 = ['ok' => false, 'erreur' => $e::class . ' : ' . $e->getMessage()];
    $course166 = [];
}
$c166 = null;
($temoin166['ok'] ?? false) && $course166 === ['interlude' => true, 'refus' => 'bloque', 'dm' => 0, 'lies' => false]
    ? ok('⭐ un blocage posé entre les contrôles et l\'insertion refuse encore l\'envoi : rien n\'entre dans dm')
    : nok('⭐ un message en vol entre dans dm après le blocage : ' . json_encode([$temoin166, $course166], JSON_UNESCAPED_UNICODE));

// ── 167. ⭐ Le lab : la liste publique, les votes reçus, les refus d'un banni ─────
// La liste publique écarte les croisements avant sa limite : écartés après, chacun
// faisait baisser le compte, qui le datait. Le compteur des votes reçus compte ceux
// dont le membre lit les motifs. Publier et enregistrer son profil disent la fin
// arrondie d'un ban automatique, « au plus tard ».
require_once __DIR__ . '/../lib/forum.php';
require_once __DIR__ . '/../lib/profile.php';
$serveur167 = serveurLab();
$t167 = max(time(), (int) $pdo->query('SELECT MAX(created_at) FROM mod_votes')->fetchColumn()) + 60;
Moderate::setHorloge(static fn (): int => $t167);
$journalAvant167 = Moderate::journal();
Moderate::setJournal(new JournalDeBanc());
for ($i167 = 0; $i167 < 15; $i167++) {
    [$x167, $y167] = [membre($pdo, "liste-x-167-$i167"), membre($pdo, "liste-y-167-$i167")];
    Moderate::applyVote($pdo, $x167, 'member', $y167, -1, motif());
    Moderate::applyVote($pdo, $y167, 'member', $x167, -1, motif());
}
$croises167 = (int) $pdo->query("SELECT COUNT(*) FROM mod_votes WHERE blocked_reason IN ('vote_croise', 'riposte') AND created_at = $t167")->fetchColumn();
$attendu167 = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT 1 FROM mod_votes WHERE blocked = 1 AND blocked_reason NOT IN ('vote_croise', 'riposte', 'treve') ORDER BY created_at DESC LIMIT 30)")->fetchColumn();
$publique167 = requeteLab($serveur167, '/moderation.php')['corps'];
$arbitrale167 = requeteLab($serveur167, '/moderation.php', sessionArbitre($pdo, 'arbitre-liste-167'))['corps'];
$croises167 === 30 && $attendu167 > 0 && str_contains($publique167, t('mod.blocked.h2', $attendu167))
    && str_contains($arbitrale167, t('mod.blocked.h2', 30))
    ? ok('⭐ la page publique écarte les croisements avant sa limite : trente croisements ne lui retirent aucune ligne')
    : nok('⭐ la page publique écarte les croisements après sa limite : ' . json_encode([
        $croises167, $attendu167,
        preg_match('#<h2>[^<]*\(\d+\)</h2>#u', $publique167, $mP167) ? $mP167[0] : null,
        preg_match('#<h2>[^<]*\(\d+\)</h2>#u', $arbitrale167, $mA167) ? $mA167[0] : null,
    ], JSON_UNESCAPED_UNICODE));

$sessionR167 = sessionMembre($pdo, 'recus-r-167');
$r167 = idDe($pdo, 'recus-r-167');
$s167 = membre($pdo, 'recus-s-167');
Moderate::applyVote($pdo, $s167, 'post', message($pdo, $r167, 'fil 167 r'), -1, motif());
Moderate::applyVote($pdo, $r167, 'post', message($pdo, $s167, 'fil 167 s'), -1, motif());
$pageR167 = requeteLab($serveur167, '/profile.php', $sessionR167)['corps'];
$recus167 = preg_match('#' . preg_quote(t('prf.act.got'), '#') . '</span><br><strong[^>]*>(\d+)</strong>#u', $pageR167, $mR167) === 1 ? (int) $mR167[1] : null;
$recusS167 = Moderate::votesRecus($pdo, $s167);
$recus167 === 1 && $recusS167 === 0
    ? ok('⭐ le compteur des votes reçus garde la face croisée, et ne compte pas la riposte')
    : nok('⭐ le compteur des votes reçus bouge au croisement : ' . json_encode([$recus167, $recusS167]));

$banni167 = membre($pdo, 'refus-banni-167');
poserReputation($pdo, $banni167, 1);
Moderate::applyVote($pdo, membre($pdo, 'votant-banni-167'), 'member', $banni167, -1, motif(), 'agressif');
$fin167 = date('d/m/Y H:i', (int) etatBan($pdo, $banni167)['banned_until']) . ' au plus tard';
$forum167 = \Pierroons\MySelfLab\Forum::createPost($pdo, $banni167, 1, 'Un message.');
$profil167 = \Pierroons\MySelfLab\Profile::save($pdo, $banni167, ['bio' => 'Une bio.']);
Moderate::adminLever($pdo, $banni167, idDe($pdo, 'arbitre-banc'), motif());
arreterLab($serveur167);
Moderate::setHorloge(null);
Moderate::setJournal($journalAvant167);
($forum167['error'] ?? null) === 'compte_banni' && str_contains($forum167['message'] ?? '', $fin167)
    && ($profil167['error'] ?? null) === 'compte_banni' && str_contains($profil167['message'] ?? '', $fin167)
    ? ok('⭐ publier et enregistrer son profil disent à un banni par la machine la fin arrondie, « au plus tard »')
    : nok('⭐ un refus du lab dit la fin exacte d\'un ban automatique : ' . json_encode([$fin167, $forum167, $profil167], JSON_UNESCAPED_UNICODE));

// ── 168. ⭐ Un vote croisé reste dans la salve ───────────────────────────────────
// Tombé, il a été émis. Sans lui, trois votants sans lien dont l'un est croisé
// ne seraient plus une salve.
$t168 = time() + 7200;
Moderate::setHorloge(static fn (): int => $t168);
$journalAvant168 = Moderate::journal();
Moderate::setJournal(new JournalDeBanc());
$cible168 = membre($pdo, 'salve-cible-168');
poserReputation($pdo, $cible168, 15);
$votants168 = [membre($pdo, 'salve-a-168'), membre($pdo, 'salve-b-168'), membre($pdo, 'salve-c-168')];
// La riposte tombe avant les deux autres votes : la salve ne se voit qu'avec le vote croisé.
Moderate::applyVote($pdo, $votants168[0], 'post', message($pdo, $cible168, 'fil 168 0'), -1, motif());
Moderate::applyVote($pdo, $cible168, 'post', message($pdo, $votants168[0], 'fil 168 riposte'), -1, motif());
foreach ([1, 2] as $i168) {
    Moderate::applyVote($pdo, $votants168[$i168], 'post', message($pdo, $cible168, "fil 168 $i168"), -1, motif());
}
$balaye168 = balayerLeSite($pdo);
$sortA168 = $pdo->query("SELECT blocked_reason FROM mod_votes WHERE voter_id = {$votants168[0]} AND target_author = $cible168")->fetchColumn();
$causes168 = causes($pdo, $cible168);
Moderate::setHorloge(null);
Moderate::setJournal($journalAvant168);
!isset($balaye168['erreur']) && $sortA168 === 'vote_croise' && in_array('salve_rapide', $causes168, true)
    ? ok('⭐ trois votants sans lien dont l\'un est croisé forment encore une salve')
    : nok('⭐ un vote croisé sort de la salve : ' . json_encode([$balaye168['erreur'] ?? null, $sortA168, $causes168], JSON_UNESCAPED_UNICODE));

// La riposte complète la salve : sa détection passe à ce vote, sans balayage.
$tb168 = $t168 + 3600;
Moderate::setHorloge(static fn (): int => $tb168);
Moderate::setJournal(new JournalDeBanc());
[$cibleB168, $z168] = [membre($pdo, 'salve-cible-b-168'), membre($pdo, 'salve-z-168')];
poserReputation($pdo, $cibleB168, 15);
poserReputation($pdo, $z168, 15);
Moderate::applyVote($pdo, $cibleB168, 'post', message($pdo, $z168, 'fil 168 z'), -1, motif());
foreach (['salve-x1-168', 'salve-x2-168'] as $nomB168) {
    Moderate::applyVote($pdo, membre($pdo, $nomB168), 'post', message($pdo, $cibleB168, "fil 168 $nomB168"), -1, motif());
}
$avantB168 = causes($pdo, $cibleB168);
Moderate::applyVote($pdo, $z168, 'post', message($pdo, $cibleB168, 'fil 168 riposte z'), -1, motif());
$sortZ168 = $pdo->query("SELECT blocked_reason FROM mod_votes WHERE voter_id = $z168 AND target_author = $cibleB168")->fetchColumn();
$apresB168 = causes($pdo, $cibleB168);
Moderate::setHorloge(null);
Moderate::setJournal($journalAvant168);
$sortZ168 === 'riposte' && !in_array('salve_rapide', $avantB168, true) && in_array('salve_rapide', $apresB168, true)
    ? ok('⭐ une riposte qui complète une salve la fait signaler à son vote, sans balayage')
    : nok('⭐ une salve complétée par une riposte attend un balayage : ' . json_encode([$sortZ168, $avantB168, $apresB168], JSON_UNESCAPED_UNICODE));

// ── 169. ⭐ Les raisons qu'une page publique tait sont celles des votes tombés et émis ─
// Une page publique écarte `RAISONS_DU_CROISEMENT` ; le moteur compte ces votes
// au plafond et dans la salve par `VOTE_AU_PLAFOND`. Une raison présente dans
// l'un et absente de l'autre serait publiée, ou sortirait des plafonds et de la
// salve. La part des comptes récents n'est dans aucun des deux : publique, elle
// ne dit rien d'un croisement, et elle n'entre ni aux plafonds ni dans la salve.
$plafond169 = (string) (new ReflectionClass(Moteur::class))->getConstant('VOTE_AU_PLAFOND');
$raisons169 = preg_match('#blocked_reason IN \(([^)]*)\)#', $plafond169, $m169) === 1
    ? array_map(static fn (string $r): string => trim($r, " '"), explode(',', $m169[1]))
    : [];
$attendues169 = Moderate::RAISONS_DU_CROISEMENT;
sort($raisons169);
sort($attendues169);
$raisons169 !== [] && $raisons169 === $attendues169
    ? ok('⭐ les raisons qu\'une page publique écarte sont celles que VOTE_AU_PLAFOND compte aux plafonds et dans la salve, et la part des comptes récents n\'en est pas')
    : nok('⭐ RAISONS_DU_CROISEMENT et VOTE_AU_PLAFOND divergent : ' . json_encode([$attendues169, $raisons169, $plafond169]));

// ── 170. ⭐ Les comptes récents pèsent ensemble comme un seul membre ─────────────
// Sept comptes neufs, trois votes chacun, ne retirent pas plus de points qu'un votant
// seul, et la cible est signalée ; sept membres établis bannissent encore. La part se
// lit en solde net des votes qui portent : ni l'aller-retour des comptes de la cible
// ni un croisement ne la remplissent à vide. Un compte est récent au moment de SON vote.
$t170 = time() + 6 * 3600;
Moderate::setHorloge(static function () use (&$t170): int { return $t170; });
$journalAvant170 = Moderate::journal();
Moderate::setJournal(new JournalDeBanc());
$recent170 = static function (string $nom) use ($pdo, &$t170): int {
    $pdo->prepare('INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$nom, 'x', 'x', 'x', $t170 - 600]);
    return (int) $pdo->lastInsertId();
};
$cibleDe170 = static function (string $nom) use ($pdo): array {
    $id = membre($pdo, $nom);
    poserReputation($pdo, $id, 20);
    return [$id, [message($pdo, $id, "$nom 1"), message($pdo, $id, "$nom 2"), message($pdo, $id, "$nom 3")]];
};
$sorts170 = static function (int $cible) use ($pdo): array {
    $s = $pdo->prepare("SELECT COALESCE(blocked_reason, 'porte') AS r, COUNT(*) AS n FROM mod_votes WHERE target_author = ? GROUP BY r ORDER BY r");
    $s->execute([$cible]);
    return array_map('intval', array_column($s->fetchAll(), 'n', 'r'));
};
$raison170 = static fn (array $r): string => !$r['ok'] ? 'refus : ' . $r['message'] : ($r['blocked_reason'] ?? 'porte');

[$cibleA170, $postsA170] = $cibleDe170('recents-cible-a-170');
$neutre170 = null;
for ($i170 = 0; $i170 < 7; $i170++) {
    $votant170 = $recent170("recent-a$i170-170");
    foreach ($postsA170 as $post170) {
        $r170 = Moderate::applyVote($pdo, $votant170, 'post', $post170, -1, motif());
        $neutre170 ??= $i170 === 1 ? $r170 : null;
    }
}
$etatA170 = Moderate::getReputation($pdo, $cibleA170);
$causesA170 = causes($pdo, $cibleA170);
$mesureA170 = [
    'rep' => $etatA170['reputation'], 'banni' => $etatA170['banned'], 'sorts' => $sorts170($cibleA170),
    'signal' => in_array('comptes_recents', $causesA170, true), 'salve' => in_array('salve_rapide', $causesA170, true),
    'reponse' => [$neutre170['blocked'] ?? null, $neutre170['blocked_reason'] ?? null, $neutre170['message'] ?? null],
];
$mesureA170 === ['rep' => 17, 'banni' => false, 'sorts' => ['comptes_recents' => 18, 'porte' => 3], 'signal' => true, 'salve' => false,
                 'reponse' => [true, 'comptes_recents', 'Vote enregistré.']]
    ? ok('⭐ sept comptes récents, trois votes chacun, ne retirent que trois points : dix-huit votes neutralisés, la cible signalée, aucune salve faite de votes neutralisés, et le votant lit le message d\'un vote compté — la part pleine dirait les votes des autres')
    : nok('⭐ des comptes récents pèsent plus qu\'un membre : ' . json_encode($mesureA170, JSON_UNESCAPED_UNICODE));

// Deux heures plus tard, les premiers comptes sont établis, mais leurs votes ont été
// posés récents : la part reste pleine pour un compte neuf, pas pour un membre établi.
$t170 += 2 * 3600;
$tard170 = $raison170(Moderate::applyVote($pdo, $recent170('recent-tard-170'), 'member', $cibleA170, -1, motif()));
$etabli170 = $raison170(Moderate::applyVote($pdo, membre($pdo, 'etabli-a-170'), 'member', $cibleA170, -1, motif()));
[$tard170, $etabli170, reputation($pdo, $cibleA170)] === ['comptes_recents', 'porte', 16]
    ? ok('⭐ un compte est récent au moment de son vote : la part reste pleine quand ses auteurs vieillissent, et un membre établi vote comme avant')
    : nok('⭐ la part suit l\'âge présent des comptes : ' . json_encode([$tard170, $etabli170, reputation($pdo, $cibleA170)]));

// Trois jours plus tard, la part tient encore : elle se compte sur la fenêtre du
// farming, qui est en jours.
$t170 += 3 * 86400;
$fenetre170 = $raison170(Moderate::applyVote($pdo, $recent170('recent-fenetre-170'), 'member', $cibleA170, -1, motif()));
$fenetre170 === 'comptes_recents'
    ? ok('⭐ la part tient sur sa fenêtre : trois jours après, un compte récent la trouve encore pleine')
    : nok('⭐ la part oublie sa fenêtre : ' . $fenetre170);

[$cibleB170, $postsB170] = $cibleDe170('etablis-cible-b-170');
for ($i170 = 0; $i170 < 7; $i170++) {
    $votant170 = membre($pdo, "etabli-b$i170-170");
    foreach ($postsB170 as $post170) {
        Moderate::applyVote($pdo, $votant170, 'post', $post170, -1, motif());
    }
}
$etatB170 = Moderate::getReputation($pdo, $cibleB170);
$etatB170['banned'] === true && $etatB170['reputation'] === 0 && !array_key_exists('comptes_recents', $sorts170($cibleB170))
    ? ok('⭐ sept membres établis font encore tomber un membre : la part ne touche que les comptes récents')
    : nok('⭐ la part retient des membres établis : ' . json_encode([$etatB170['reputation'], $etatB170['banned'], $sorts170($cibleB170)]));

// La cible tente de remplir la part avec ses propres comptes : trois votes contre
// elle, puis trois pour elle ; puis en croisant le vote d'un de ses comptes.
[$cibleC170, $postsC170] = $cibleDe170('aller-retour-cible-c-170');
$autresC170 = [message($pdo, $cibleC170, 'aller-retour 170 a'), message($pdo, $cibleC170, 'aller-retour 170 b'), message($pdo, $cibleC170, 'aller-retour 170 c')];
foreach ([$recent170('sock-c1-170'), $recent170('sock-c2-170'), $recent170('sock-c3-170')] as $k170 => $sock170) {
    Moderate::applyVote($pdo, $sock170, 'post', $postsC170[$k170], -1, motif());
    Moderate::applyVote($pdo, $sock170, 'post', $autresC170[$k170], 1);
}
$repAllerRetour170 = reputation($pdo, $cibleC170);
$critiqueC170 = $recent170('critique-c-170');
$votesC170 = array_map(static fn (int $p): string => $raison170(Moderate::applyVote($pdo, $critiqueC170, 'post', $p, -1, motif())), $postsC170);
$suivantC170 = $raison170(Moderate::applyVote($pdo, $recent170('critique-c2-170'), 'post', $postsC170[0], -1, motif()));

[$cibleD170, $postsD170] = $cibleDe170('croise-cible-d-170');
$sockD170 = $recent170('sock-d-170');
$postSockD170 = message($pdo, $sockD170, 'fil sock d 170');
Moderate::applyVote($pdo, $sockD170, 'post', $postsD170[0], -1, motif());
Moderate::applyVote($pdo, $cibleD170, 'post', $postSockD170, -1, motif());
$sortSockD170 = $pdo->query("SELECT blocked_reason FROM mod_votes WHERE voter_id = $sockD170 AND target_author = $cibleD170")->fetchColumn();
$critiqueD170 = $recent170('critique-d-170');
$votesD170 = array_map(static fn (int $p): string => $raison170(Moderate::applyVote($pdo, $critiqueD170, 'post', $p, -1, motif())), $postsD170);
$mesureCD170 = [$repAllerRetour170, $votesC170, $suivantC170, reputation($pdo, $cibleC170), $sortSockD170, $votesD170, reputation($pdo, $cibleD170)];
$mesureCD170 === [20, ['porte', 'porte', 'porte'], 'comptes_recents', 17, 'vote_croise', ['porte', 'porte', 'porte'], 17]
    ? ok('⭐ la part se lit en solde net des votes qui portent : ni l\'aller-retour des comptes de la cible ni un croisement ne la remplissent')
    : nok('⭐ la cible remplit la part à vide : ' . json_encode($mesureCD170));

// Dans l'autre sens : des comptes récents ne portent pas un membre plus haut qu'un votant seul.
[$cibleE170, $postsE170] = $cibleDe170('soutenu-cible-e-170');
for ($i170 = 0; $i170 < 4; $i170++) {
    $votant170 = $recent170("ami-e$i170-170");
    foreach ($postsE170 as $post170) {
        Moderate::applyVote($pdo, $votant170, 'post', $post170, 1);
    }
}
[reputation($pdo, $cibleE170), $sorts170($cibleE170)] === [23, ['comptes_recents' => 9, 'porte' => 3]]
    ? ok('⭐ quatre comptes récents ne portent un membre que de trois points : la part vaut dans les deux sens')
    : nok('⭐ des comptes récents gonflent un membre : ' . json_encode([reputation($pdo, $cibleE170), $sorts170($cibleE170)]));

// À 0, la part est éteinte ; une durée négative est refusée.
Moderate::setConfig(configBanc(ageEtabliSecondes: 0));
[$cibleF170, $postsF170] = $cibleDe170('eteinte-cible-f-170');
foreach ([$recent170('recent-f1-170'), $recent170('recent-f2-170')] as $votant170) {
    foreach ($postsF170 as $post170) {
        Moderate::applyVote($pdo, $votant170, 'post', $post170, -1, motif());
    }
}
$repF170 = reputation($pdo, $cibleF170);
Moderate::setConfig(configBanc());
try {
    new Config(Messagerie::DM, Usage::DEMONSTRATION, ageEtabliSecondes: -1);
    $refusNegatif170 = false;
} catch (InvalidArgumentException) {
    $refusNegatif170 = true;
}
Moderate::setHorloge(null);
Moderate::setJournal($journalAvant170);
$repF170 === 14 && $refusNegatif170
    ? ok('⭐ ageEtabliSecondes à 0 éteint la part, et une durée négative est refusée')
    : nok('⭐ la part ne s\'éteint pas, ou accepte une durée négative : ' . json_encode([$repF170, $refusNegatif170]));

// Les cas du quorum aux seuils (171 et suivants) jouent quorum allumé, journal
// branché : le ban automatique peut tomber, et seul le quorum le retient.

/** Un compte récent au sens de `ageEtabliSecondes`, assez vieux pour voter. */
function jetable(PDO $pdo, string $nom): int
{
    $pdo->prepare(
        'INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$nom, 'x', 'x', 'x', time() - 600]);
    return (int) $pdo->lastInsertId();
}

/** Des liens deux à deux entre ces comptes. */
function lierTous(PDO $pdo, array $comptes): void
{
    foreach ($comptes as $i => $a) {
        foreach (array_slice($comptes, $i + 1) as $b) {
            echangePrive($pdo, $a, $b);
        }
    }
}

/** `$tours` tours : à chacun, un message neuf de la cible, et chaque votant le vote. */
function frapper(PDO $pdo, int $cible, array $votants, int $tours, string $nom, int $valeur = -1): void
{
    for ($t = 1; $t <= $tours; $t++) {
        $post = message($pdo, $cible, "fil-$nom-$t");
        foreach ($votants as $v) {
            Moderate::applyVote($pdo, $v, 'post', $post, $valeur, motif(), 'agressif');
        }
    }
}

/** Des comptes établis, nommés par un préfixe. */
function membres(PDO $pdo, string $prefixe, int $combien): array
{
    return array_map(static fn (int $k): int => membre($pdo, "$prefixe-$k"), range(1, $combien));
}

/**
 * Joue un cas quorum allumé, sous un journal neuf qu'il lui passe ; rend la
 * config du banc et le journal d'avant quoi qu'il arrive.
 */
function sousQuorum(callable $cas, mixed ...$reglages): mixed
{
    return sousJournal(static fn (JournalDeBanc $journal): mixed =>
        sousConfig(configBanc(...['quorumAuxSeuils' => true, ...$reglages]), static fn (): mixed => $cas($journal)));
}

/** L'état d'une cible, réduit à ce que les cas du quorum regardent. */
function bilan(PDO $pdo, int $cible): array
{
    $e = Moderate::getReputation($pdo, $cible);

    return ['rep' => $e['reputation'], 'banni' => $e['banned'], 'vote' => $e['voting_rights'],
            'causes' => array_column($e['signaux'], 'cause'),
            'file' => array_column(array_filter($e['signaux'], static fn (array $s): bool => $s['vu_le'] === null), 'cause')];
}

/** Les votes négatifs qui portent contre une cible. */
function portent(PDO $pdo, int $cible): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM mod_votes WHERE target_author = $cible AND value = -1 AND blocked = 0")->fetchColumn();
}

// ── 171. ⭐ Le quorum est allumé en démonstration comme en service, et se compte sur la config ─
// Sept voix pour le ban et six pour le vote : autant que de votants au plafond pour
// descendre du départ au seuil. Le nombre suit `farmingDownvotesMax` et les seuils ;
// un budget de recherche nul est refusé.
$q171 = [
    Config::demo()->quorumAuxSeuils, Config::prod(Messagerie::DM)->quorumAuxSeuils, Config::prod(Messagerie::HORS_MOTEUR)->quorumAuxSeuils,
    Config::demo()->voixPourSanction(Config::demo()->banA),
    Config::demo()->voixPourSanction(Config::demo()->perteDroitDeVoteSous - 1),
    (new Config(Messagerie::DM, Usage::DEMONSTRATION, farmingDownvotesMax: 4))->voixPourSanction(0),
    (new Config(Messagerie::DM, Usage::DEMONSTRATION, farmingDownvotesMax: 4))->voixPourSanction(4),
];
$budget171 = [];
foreach (['quorumTravailMax', 'quorumRecherchesMax'] as $cle171) {
    try {
        new Config(Messagerie::DM, Usage::DEMONSTRATION, ...[$cle171 => 0]);
        $budget171[$cle171] = 'accepté';
    } catch (InvalidArgumentException) {
        $budget171[$cle171] = 'refusé';
    }
}
$q171 === [true, true, true, 7, 6, 5, 4] && $budget171 === ['quorumTravailMax' => 'refusé', 'quorumRecherchesMax' => 'refusé']
    ? ok('⭐ le quorum est allumé en démonstration et en service : 7 voix pour le ban, 6 pour le vote, 5 et 4 à quatre votes par votant')
    : nok('⭐ le quorum est éteint quelque part, ou ne suit pas la config : ' . json_encode([$q171, $budget171]));

// ── 172. ⭐ Sept votants sans lien bannissent, et le ban ne garde que son signal ─
$cible172 = membre($pdo, 'cible-172');
poserReputation($pdo, $cible172, 20);
$sept172 = membres($pdo, 'critique-172', 7);
[$b172, $c172] = sousQuorum(static function () use ($pdo, $cible172, $sept172): array {
    frapper($pdo, $cible172, $sept172, 3, '172');

    return [bilan($pdo, $cible172), Moderate::compositionChute($pdo, $cible172, time())];
});
$b172['rep'] === 0 && $b172['banni'] && !$b172['vote'] && in_array('ban_auto', $b172['causes'], true)
    && !array_intersect(['chute_sans_quorum', 'droit_de_vote_perdu', 'reputation_zero'], $b172['causes'])
    && $c172['voix_sans_lien'] === 7 && $c172['paires_liees'] === 0 && $c172['recents'] === 0 && !$c172['indecis']
    ? ok('⭐ sept votants sans lien bannissent, et le ban ne garde que son signal : 7 voix, aucun lien')
    : nok('⭐ sept voix sans lien ne bannissent pas, ou un signal traîne : ' . json_encode([$b172, $c172]));

// ── 173. ⭐ Une clique de sept amis fait tomber sans sanctionner, et l'arbitre le voit ─
// Le prix assumé : sept amis qui se sont tous écrit ne pèsent qu'une voix. La
// cible descend à zéro, garde son vote, et la chute est signalée.
$cible173 = membre($pdo, 'cible-173');
poserReputation($pdo, $cible173, 20);
$amis173 = membres($pdo, 'ami-173', 7);
lierTous($pdo, $amis173);
[$b173, $c173] = sousQuorum(static function () use ($pdo, $cible173, $amis173): array {
    frapper($pdo, $cible173, $amis173, 3, '173');

    return [bilan($pdo, $cible173), Moderate::compositionChute($pdo, $cible173, time())];
});
$conv173 = Moderate::getReputation($pdo, $cible173)['convalescent'];
$b173['rep'] === 0 && !$b173['banni'] && $b173['vote'] && $conv173 && in_array('chute_sans_quorum', $b173['causes'], true)
    && !array_intersect(['ban_auto', 'droit_de_vote_perdu', 'reputation_zero'], $b173['causes'])
    && $c173['voix_sans_lien'] === 1 && $c173['paires_liees'] === 21 && portent($pdo, $cible173) === 21
    ? ok('⭐ une clique de sept amis : la cible tombe à 0 sans ban ni perte du vote, en convalescence, et la chute est signalée')
    : nok('⭐ une clique a sanctionné, ou s\'est tue : ' . json_encode([$b173, $c173, $conv173]));

// ── 174. ⭐ Aucun lien n'annule de vote : la détection rend des clés vides, et se relance sans rien changer ─
// Les clés de `detectPackVoting()` restent pour les hôtes qui les lisent. Relancée,
// elle ne touche ni aux votes, ni à la réputation, et ne classe personne.
$rangsAvant174 = (int) $pdo->query('SELECT COUNT(*) FROM mod_pack_flags')->fetchColumn();
$avant174 = [reputation($pdo, $cible173), portent($pdo, $cible173)];
$d174 = [Moderate::detectPackVoting($pdo, $cible173), Moderate::detectPackVoting($pdo)];
$apres174 = [reputation($pdo, $cible173), portent($pdo, $cible173)];
$vides174 = array_map(static fn (array $d): array => [$d['pack_detected'], $d['cancelled_votes'], $d['packs'], $d['voters_truncated']], $d174);
$vides174 === [[false, 0, [], []], [false, 0, [], []]] && $avant174 === $apres174
    && (int) $pdo->query('SELECT COUNT(*) FROM mod_pack_flags')->fetchColumn() === $rangsAvant174
    && Moderate::rangDe($pdo, $amis173[0]) === 0
    ? ok('⭐ la détection rend des clés vides et se relance sans rien annuler ni classer')
    : nok('⭐ la détection annule ou classe encore : ' . json_encode([$vides174, $avant174, $apres174]));

// ── 175. ⭐ Un compte de la cible, lié à chaque critique, ne retire aucune voix ─
// Trois formes de pivot, chacune sur sa cible : un compte établi lié aux sept
// critiques, qui vote une fois ; sept comptes jetables, un par critique ; et un
// pivot dont la cible croise le vote. Les critiques portent leurs 21 votes, et la
// cible est bannie.
$pivots175 = [];
foreach (['etabli', 'jetables', 'croise'] as $forme) {
    $cible = membre($pdo, "cible-175-$forme");
    poserReputation($pdo, $cible, 20);
    $critiques = membres($pdo, "critique-175-$forme", 7);
    $pivots175[$forme] = sousQuorum(static function () use ($pdo, $cible, $critiques, $forme): array {
        if ($forme === 'jetables') {
            foreach ($critiques as $k => $c) {
                $p = jetable($pdo, "pivot-175-$k");
                echangePrive($pdo, $p, $c);
                Moderate::applyVote($pdo, $p, 'member', $cible, -1, motif(), 'agressif');
            }
        } else {
            $p = membre($pdo, "pivot-175-$forme");
            foreach ($critiques as $c) {
                echangePrive($pdo, $p, $c);
            }
            Moderate::applyVote($pdo, $p, 'member', $cible, -1, motif(), 'agressif');
            if ($forme === 'croise') {
                Moderate::applyVote($pdo, $cible, 'post', message($pdo, $p, 'fil-pivot-175'), -1, motif(), 'agressif');
            }
        }
        $avant = reputation($pdo, $cible);
        frapper($pdo, $cible, $critiques, 3, "175-$forme");
        $votes = (int) $pdo->query('SELECT COUNT(*) FROM mod_votes WHERE blocked = 0 AND voter_id IN (' . implode(',', $critiques) . ')')->fetchColumn();

        return ['avant' => $avant, 'critiques' => $votes, 'banni' => bilan($pdo, $cible)['banni']];
    });
}
$pivots175['croise']['avant'] === 20 && array_column($pivots175, 'critiques') === [21, 21, 21]
    && array_column($pivots175, 'banni') === [true, true, true]
    ? ok('⭐ un pivot établi, sept pivots jetables ou un pivot croisé : les critiques portent leurs 21 votes, et la cible est bannie')
    : nok('⭐ un pivot de la cible a retiré une voix : ' . json_encode($pivots175));

// ── 176. ⭐ Un lien formé après le ban ne le lève pas ──────────────────────────
// La cible bannie par sept voix fait lier un compte aux sept, et il vote : le ban
// tient, aucune levée ne s'inscrit, et rien n'est annulé.
[$b176, $leve176] = sousQuorum(static function (JournalDeBanc $journal) use ($pdo, $cible172, $sept172): array {
    $tardif = membre($pdo, 'pivot-tardif-176');
    foreach ($sept172 as $c) {
        echangePrive($pdo, $tardif, $c);
    }
    Moderate::applyVote($pdo, $tardif, 'member', $cible172, -1, motif(), 'agressif');
    Moderate::detectPackVoting($pdo);

    return [bilan($pdo, $cible172), $journal->de($cible172, 'ban_leve')];
});
$b176['banni'] && $leve176 === [] && portent($pdo, $cible172) === 22
    ? ok('⭐ un compte lié aux sept critiques après le ban ne le lève pas, et ne fait tomber aucun vote')
    : nok('⭐ un lien formé après coup a levé le ban, ou fait tomber un vote : ' . json_encode([$b176, $leve176, portent($pdo, $cible172)]));

// ── 177. ⭐ Six voix sans lien retirent le vote sans bannir ; le nombre suit la config ─
// Au plancher avec six voix, le vote est perdu, le ban attend la septième, et la
// chute le dit. À quatre votes par votant, quatre voix retirent le vote et une
// cinquième bannit : le repli se lit dans la config, il ne s'écrit pas.
$cible177 = membre($pdo, 'cible-177');
poserReputation($pdo, $cible177, 18);
$six177 = membres($pdo, 'critique-177', 6);
$b177 = sousQuorum(static function () use ($pdo, $cible177, $six177): array {
    frapper($pdo, $cible177, $six177, 3, '177');

    return bilan($pdo, $cible177);
});
$cible177b = membre($pdo, 'cible-177b');
poserReputation($pdo, $cible177b, 16);
$quatre177 = membres($pdo, 'critique-177b', 4);
[$b177b, $b177c] = sousQuorum(static function () use ($pdo, $cible177b, $quatre177): array {
    frapper($pdo, $cible177b, $quatre177, 4, '177b');
    $quatre = bilan($pdo, $cible177b);
    Moderate::applyVote($pdo, membre($pdo, 'cinquieme-177b'), 'member', $cible177b, -1, motif(), 'agressif');

    return [$quatre, bilan($pdo, $cible177b)];
}, farmingDownvotesMax: 4);
$b177['rep'] === 0 && !$b177['banni'] && !$b177['vote'] && in_array('chute_sans_quorum', $b177['causes'], true)
    && !array_intersect(['droit_de_vote_perdu', 'reputation_zero', 'ban_auto'], $b177['causes'])
    && $b177b['rep'] === 0 && !$b177b['banni'] && !$b177b['vote'] && $b177c['banni']
    ? ok('⭐ six voix sans lien retirent le vote sans bannir ; à quatre votes par votant, quatre voix le retirent et la cinquième bannit')
    : nok('⭐ le repli ne suit pas la config : ' . json_encode([$b177, $b177b, $b177c]));

// ── 178. ⭐ Les comptes récents font une voix à eux tous ───────────────────────
// Six voix établies sans lien, plus deux comptes récents : sept voix, le ban tombe.
// Trois comptes établis liés entre eux, plus cinq récents : deux voix, aucune
// sanction, et la composition les compte.
$cible178 = membre($pdo, 'cible-178');
poserReputation($pdo, $cible178, 20);
$six178 = membres($pdo, 'critique-178', 6);
$b178 = sousQuorum(static function () use ($pdo, $cible178, $six178): array {
    frapper($pdo, $cible178, $six178, 3, '178');
    foreach ([1, 2] as $k) {
        Moderate::applyVote($pdo, jetable($pdo, "recent-178-$k"), 'member', $cible178, -1, motif(), 'agressif');
    }

    return bilan($pdo, $cible178);
});
$cible178b = membre($pdo, 'cible-178b');
poserReputation($pdo, $cible178b, 12);
$trois178 = membres($pdo, 'patient-178', 3);
lierTous($pdo, $trois178);
[$b178b, $c178b] = sousQuorum(static function () use ($pdo, $cible178b, $trois178): array {
    frapper($pdo, $cible178b, $trois178, 3, '178b');
    foreach (range(1, 5) as $k) {
        Moderate::applyVote($pdo, jetable($pdo, "recent-178b-$k"), 'member', $cible178b, -1, motif(), 'agressif');
    }

    return [bilan($pdo, $cible178b), Moderate::compositionChute($pdo, $cible178b, time())];
});
$b178['rep'] === 0 && $b178['banni']
    && $b178b['rep'] === 0 && !$b178b['banni'] && $b178b['vote'] && in_array('chute_sans_quorum', $b178b['causes'], true)
    && $c178b['voix_sans_lien'] === 2 && $c178b['recents'] === 3 && $c178b['paires_liees'] === 3
    ? ok('⭐ les comptes récents font une voix : six établis et deux récents bannissent ; trois liés et cinq récents ne sanctionnent pas')
    : nok('⭐ la classe des récents compte mal : ' . json_encode([$b178, $b178b, $c178b]));

// ── 179. ⭐ Une recherche à court de budget s'abstient, et le dit ─────────────
// Vingt votants en quatre cycles de cinq : huit voix sans lien. Sous un travail d'un
// sommet, la recherche ne décide pas : aucune sanction, la chute est signalée et la
// composition dit `indecis`. Le même graphe sous le budget par défaut bannit.
$cycles179 = static function (PDO $pdo, string $nom): array {
    $votants = membres($pdo, "cycle-$nom", 20);
    foreach (array_chunk($votants, 5) as $cycle) {
        foreach ($cycle as $k => $v) {
            echangePrive($pdo, $v, $cycle[($k + 1) % 5]);
        }
    }

    return $votants;
};
$cible179 = membre($pdo, 'cible-179');
poserReputation($pdo, $cible179, 20);
$votants179 = $cycles179($pdo, '179');
[$b179, $c179] = sousQuorum(static function () use ($pdo, $cible179, $votants179): array {
    frapper($pdo, $cible179, $votants179, 1, '179');

    return [bilan($pdo, $cible179), Moderate::compositionChute($pdo, $cible179, time())];
}, quorumTravailMax: 1);
$cible179b = membre($pdo, 'cible-179b');
poserReputation($pdo, $cible179b, 20);
$votants179b = $cycles179($pdo, '179b');
[$b179b, $c179b] = sousQuorum(static function () use ($pdo, $cible179b, $votants179b): array {
    frapper($pdo, $cible179b, $votants179b, 1, '179b');

    return [bilan($pdo, $cible179b), Moderate::compositionChute($pdo, $cible179b, time())];
});
$b179['rep'] === 0 && !$b179['banni'] && $b179['vote'] && in_array('chute_sans_quorum', $b179['causes'], true)
    && $c179['indecis'] && $c179['voix_sans_lien'] < 7
    && $b179b['banni'] && !$c179b['indecis'] && $c179b['voix_sans_lien'] >= 7
    ? ok('⭐ à court de budget, la recherche s\'abstient et la composition dit indécis ; le même graphe sous le budget par défaut bannit')
    : nok('⭐ une recherche épuisée a sanctionné, ou s\'est tue : ' . json_encode([$b179, $c179, $b179b, $c179b]));

// ── 180. ⭐ La chute sans quorum est un état : elle tombe avec la remontée, ou avec la sanction ─
// Remontée au seuil du vote par des votes positifs, la cible n'a plus de chute à
// signaler. La lecture le montre aussitôt ; la table, que lisent les listes de
// l'arbitre, le garde jusqu'à l'écriture suivante, et le balayage la reprend même
// sans point de convalescence dû. Et sur la
// clique de 173, six voix de plus sans lien font le quorum : le ban prend la place
// de la chute.
$cible180 = membre($pdo, 'cible-180');
poserReputation($pdo, $cible180, 6);
$duo180 = membres($pdo, 'duo-180', 2);
lierTous($pdo, $duo180);
$table180 = static fn (): int => (int) $pdo->query("SELECT COUNT(*) FROM mod_signaux WHERE account_id = $cible180 AND cause = 'chute_sans_quorum'")->fetchColumn();
[$avant180, $apres180, $ban180, $tables180] = sousQuorum(static function () use ($pdo, $cible180, $duo180, $cible173, $table180): array {
    frapper($pdo, $cible180, $duo180, 1, '180');
    $avant = bilan($pdo, $cible180);
    frapper($pdo, $cible180, [membre($pdo, 'soutien-180')], 1, '180-soutien', 1);
    $tables = [$table180()];
    // Un refus du journal sur un autre compte remonte en fin de passe, après elle.
    try {
        Moderate::balayerBansEchus($pdo);
    } catch (\RuntimeException) {
    }
    $tables[] = $table180();
    $apres = bilan($pdo, $cible180);
    foreach (membres($pdo, 'renfort-180', 6) as $v) {
        Moderate::applyVote($pdo, $v, 'member', $cible173, -1, motif(), 'agressif');
    }

    return [$avant, $apres, bilan($pdo, $cible173), $tables];
});
$avant180['rep'] === 4 && $tables180 === [1, 0] && $avant180['vote'] && in_array('chute_sans_quorum', $avant180['causes'], true)
    && $apres180['rep'] === 5 && !in_array('chute_sans_quorum', $apres180['causes'], true)
    && $ban180['banni'] && in_array('ban_auto', $ban180['causes'], true) && !in_array('chute_sans_quorum', $ban180['causes'], true)
    ? ok('⭐ la chute sans quorum tombe quand la réputation repasse le seuil du vote, et quand le ban prend sa place')
    : nok('⭐ la chute sans quorum survit à son état : ' . json_encode([$avant180, $apres180, $ban180, $tables180]));

// ── 181. ⭐ Aucune borne : deux cents comptes de la cible, votés d'avance, ne cachent pas les critiques ─
// Un moyeu et quatre cliques de cinquante, liés entre eux, votent contre la cible
// avant ses critiques, et la compensent. Une borne remplie dans l'ordre d'arrivée
// ne lirait qu'eux ; sans borne, les sept critiques sans lien bannissent.
$cible181 = membre($pdo, 'cible-181');
$post181 = message($pdo, $cible181, 'fil-181');
$moyeu181 = membre($pdo, 'moyeu-181');
$comptes181 = [$moyeu181];
$pdo->beginTransaction();
voteBrut($pdo, $moyeu181, $post181, $cible181);
foreach (range(1, 4) as $c) {
    $clique = membres($pdo, "clique-181-$c", 50);
    foreach ($clique as $v) {
        voteBrut($pdo, $v, $post181, $cible181);
        echangePrive($pdo, $moyeu181, $v);
    }
    lierTous($pdo, $clique);
    $comptes181 = [...$comptes181, ...$clique];
}
$pdo->commit();
poserReputation($pdo, $cible181, 20);
$critiques181 = membres($pdo, 'critique-181', 7);
[$b181, $c181] = sousQuorum(static function () use ($pdo, $cible181, $critiques181): array {
    frapper($pdo, $cible181, $critiques181, 3, '181');

    return [bilan($pdo, $cible181), Moderate::compositionChute($pdo, $cible181, time())];
});
$b181['banni'] && $c181['voix_sans_lien'] >= 7 && count($comptes181) === 201
    ? ok('⭐ deux cent un comptes de la cible votés d\'avance ne cachent pas sept critiques sans lien : la cible est bannie')
    : nok('⭐ une borne cache les critiques : ' . json_encode([$b181, $c181]));

// ── 182. ⭐ Un graphe de cliques fabriqué par la cible ne cache pas les critiques ─
// Quarante comptes de la cible en cinq cliques de huit reliées par un moyeu, et
// chaque critique a répondu à douze d'entre eux : les critiques sont les sommets
// les plus liés. La recherche les trouve, et la cible est bannie.
$cible182 = membre($pdo, 'cible-182');
$post182 = message($pdo, $cible182, 'fil-182');
$moyeu182 = membre($pdo, 'moyeu-182');
$jetes182 = [];
$pdo->beginTransaction();
voteBrut($pdo, $moyeu182, $post182, $cible182);
foreach (range(1, 5) as $c) {
    $clique = membres($pdo, "clique-182-$c", 8);
    foreach ($clique as $v) {
        voteBrut($pdo, $v, $post182, $cible182);
        echangePrive($pdo, $moyeu182, $v);
    }
    lierTous($pdo, $clique);
    $jetes182 = [...$jetes182, ...$clique];
}
$critiques182 = membres($pdo, 'critique-182', 7);
foreach ($critiques182 as $k => $c) {
    foreach (array_slice([...$jetes182, ...$jetes182], 5 * $k, 12) as $j) {
        echangePrive($pdo, $c, $j);
    }
}
$pdo->commit();
poserReputation($pdo, $cible182, 20);
[$b182, $c182] = sousQuorum(static function () use ($pdo, $cible182, $critiques182): array {
    frapper($pdo, $cible182, $critiques182, 3, '182');

    return [bilan($pdo, $cible182), Moderate::compositionChute($pdo, $cible182, time())];
});
$b182['banni'] && $c182['voix_sans_lien'] >= 7 && !$c182['indecis']
    ? ok('⭐ des cliques fabriquées par la cible, liées aux critiques, ne les cachent pas : la cible est bannie')
    : nok('⭐ un graphe de cliques cache les critiques : ' . json_encode([$b182, $c182]));

// ── 183. ⭐ La recherche est exacte : le moins lié d'abord, sans retour, raterait la septième voix ─
// Douze votants, douze liens : sept d'entre eux ne se sont jamais écrit deux à
// deux, et une recherche qui prendrait toujours le moins lié sans revenir en
// arrière n'en trouverait que six. La cible est bannie.
$cible183 = membre($pdo, 'cible-183');
poserReputation($pdo, $cible183, 12);
$douze183 = membres($pdo, 'votant-183', 12);
foreach ([[1, 5], [1, 10], [2, 6], [2, 7], [4, 10], [4, 12], [5, 7], [7, 8], [7, 11], [7, 12], [8, 9], [9, 11]] as [$x, $y]) {
    echangePrive($pdo, $douze183[$x - 1], $douze183[$y - 1]);
}
[$b183, $c183] = sousQuorum(static function () use ($pdo, $cible183, $douze183): array {
    frapper($pdo, $cible183, $douze183, 1, '183');

    return [bilan($pdo, $cible183), Moderate::compositionChute($pdo, $cible183, time())];
});
$b183['banni'] && $c183['voix_sans_lien'] === 7 && $c183['paires_liees'] === 12
    ? ok('⭐ la recherche trouve les sept voix sans lien qu\'une recherche gloutonne raterait : la cible est bannie')
    : nok('⭐ la recherche n\'est pas exacte : ' . json_encode([$b183, $c183]));

// ── 184. ⭐ Un croisement après la fin d'un ban ne rend pas ce que la remise a rendu ─
// La fin d'un ban automatique remet la réputation au départ et efface l'effet des
// votes reçus. Le banni qui riposte ensuite contre son critique, dans la fenêtre du
// croisement, ne retrouve pas une seconde fois le point de ce vote.
$t184 = time();
Moderate::setHorloge(static function () use (&$t184): int { return $t184; });
$journalAvant184 = Moderate::journal();
Moderate::setJournal(new JournalDeBanc());
[$banni184, $critique184] = [membre($pdo, 'banni-184'), membre($pdo, 'critique-184')];
poserReputation($pdo, $banni184, 1);
Moderate::applyVote($pdo, $critique184, 'post', message($pdo, $banni184, 'fil 184 b'), -1, motif());
$pendant184 = Moderate::getReputation($pdo, $banni184);
$t184 += Moderate::config()->dureesBan[0] + Moderate::config()->arrondiFinBanSecondes + 1;
// Un refus du journal sur un autre compte remonte en fin de passe, après elle.
try {
    Moderate::balayerBansEchus($pdo);
} catch (\RuntimeException) {
}
$riposte184 = Moderate::applyVote($pdo, $banni184, 'post', message($pdo, $critique184, 'fil 184 c'), -1, motif());
$apres184 = Moderate::getReputation($pdo, $banni184);
Moderate::setHorloge(null);
Moderate::setJournal($journalAvant184);
$pendant184['banned'] && ($riposte184['blocked_reason'] ?? null) === 'riposte' && !$apres184['banned']
    && $apres184['reputation'] === Moderate::config()->reputationInitiale
    ? ok('⭐ un croisement après la fin d\'un ban ne rend pas une seconde fois ce que la remise au départ a rendu')
    : nok('⭐ points rendus deux fois après la fin d\'un ban : ' . json_encode([$pendant184['banned'], $riposte184, $apres184['banned'], $apres184['reputation']], JSON_UNESCAPED_UNICODE));

// ── 185. ⭐ Un vote d'avant la colonne `effet` ne rend rien au croisement ───────
// Son effet est inconnu : le rendre au croisement ferait un point gratuit.
[$ancien185, $critique185] = [membre($pdo, 'ancien-185'), membre($pdo, 'critique-185')];
poserReputation($pdo, $ancien185, 19);
Moderate::applyVote($pdo, $critique185, 'post', message($pdo, $ancien185, 'fil 185 a'), -1, motif());
$pdo->prepare('UPDATE mod_votes SET effet = NULL WHERE voter_id = ? AND target_author = ?')->execute([$critique185, $ancien185]);
$riposte185 = Moderate::applyVote($pdo, $ancien185, 'post', message($pdo, $critique185, 'fil 185 c'), -1, motif());
($riposte185['blocked_reason'] ?? null) === 'riposte' && reputation($pdo, $ancien185) === 18
    ? ok('⭐ un vote d\'avant la colonne effet, croisé, ne rend aucun point')
    : nok('⭐ des votes d\'avant la colonne rendent un point au croisement : ' . json_encode([$riposte185, reputation($pdo, $ancien185)], JSON_UNESCAPED_UNICODE));

// ── 186. ⭐ Les voix d'une chute soldée ne portent pas la suivante ────────────────
// Sept critiques sans lien bannissent ; la chute se solde — la peine finie, la grâce
// ou la levée — et la réputation repart au départ. Une clique de sept amis fait
// ensuite tomber la cible au plancher : une voix, aucune sanction. Lues sur toute la
// fenêtre des plafonds, les voix des sept critiques feraient encore le quorum. La fin
// d'une peine s'inscrit aussi au premier vote qui la suit, à la seconde même de ce
// vote : la remise et la clique partagent alors leur première seconde.
$t186 = time();
Moderate::setHorloge(static function () use (&$t186): int { return $t186; });
$soldes186 = [];
foreach (['fin', 'fin_au_vote', 'grace', 'levee'] as $issue186) {
    $cible = membre($pdo, "cible-186-$issue186");
    poserReputation($pdo, $cible, 20);
    $critiques = membres($pdo, "critique-186-$issue186", 7);
    $clique = membres($pdo, "clique-186-$issue186", 7);
    lierTous($pdo, $clique);
    $soldes186[$issue186] = sousQuorum(static function () use ($pdo, $cible, $critiques, $clique, $issue186, &$t186): array {
        frapper($pdo, $cible, $critiques, 3, "186-$issue186-c");
        $banni = Moderate::getReputation($pdo, $cible)['banned'];
        if ($issue186 === 'fin_au_vote') {
            $t186 += Moderate::config()->dureesBan[0] + Moderate::config()->arrondiFinBanSecondes + 1;
        } elseif ($issue186 === 'fin') {
            $t186 += Moderate::config()->dureesBan[0] + Moderate::config()->arrondiFinBanSecondes + 1;
            // Un refus du journal sur un autre compte remonte en fin de passe, après elle.
            try {
                Moderate::balayerBansEchus($pdo);
            } catch (\RuntimeException) {
            }
        } elseif ($issue186 === 'grace') {
            Moderate::adminPardon($pdo, $cible, idDe($pdo, 'arbitre-banc'), motif());
        } else {
            Moderate::adminLever($pdo, $cible, idDe($pdo, 'arbitre-banc'), motif());
        }
        $remise = reputation($pdo, $cible);
        $t186 += $issue186 === 'fin_au_vote' ? 0 : 60;
        frapper($pdo, $cible, $clique, 3, "186-$issue186-k");

        return [$banni, $remise, bilan($pdo, $cible), Moderate::compositionChute($pdo, $cible, Moderate::maintenant())];
    });
}
Moderate::setHorloge(null);
$rates186 = array_filter($soldes186, static fn (array $s): bool => !($s[0] && $s[1] === 20 && $s[2]['rep'] === 0
    && !$s[2]['banni'] && $s[2]['vote'] && in_array('chute_sans_quorum', $s[2]['causes'], true)
    && $s[3]['voix_sans_lien'] === 1 && $s[3]['votants'] === 7));
$rates186 === []
    ? ok('⭐ les voix d\'une chute soldée — peine finie, au balayage ou au vote suivant, grâce, levée — ne portent pas la suivante : une clique de sept amis fait tomber la cible sans la sanctionner')
    : nok('⭐ les voix d\'une chute soldée portent la suivante : ' . json_encode($soldes186, JSON_UNESCAPED_UNICODE));

// ── 187. ⭐ Un vote d'avant la colonne `effet`, ou suivi de son contraire, ne fait pas de voix ─
// Sept votants sans lien ont chacun un vote négatif d'avant la colonne : son effet est
// inconnu, et il n'est cause d'aucun ban. Ailleurs, sept votants sans lien votent
// contre puis pour : leur solde est nul. Une clique de sept amis fait ensuite tomber
// chaque cible : une voix, aucune sanction.
$cibleA187 = membre($pdo, 'cible-187-a');
$postA187 = message($pdo, $cibleA187, 'fil-187-a');
foreach (membres($pdo, 'ancien-187', 7) as $ancien187) {
    $pdo->prepare('INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, created_at) VALUES (?, ?, ?, ?, -1, ?, ?)')
        ->execute([$ancien187, 'post', $postA187, $cibleA187, motif(), time() - 3600]);
}
poserReputation($pdo, $cibleA187, 20);
$cibleB187 = membre($pdo, 'cible-187-b');
poserReputation($pdo, $cibleB187, 20);
$revenus187 = membres($pdo, 'revenu-187', 7);
[$cliqueA187, $cliqueB187] = [membres($pdo, 'clique-187-a', 7), membres($pdo, 'clique-187-b', 7)];
lierTous($pdo, $cliqueA187);
lierTous($pdo, $cliqueB187);
[$a187, $b187, $repB187] = sousQuorum(static function () use ($pdo, $cibleA187, $cibleB187, $revenus187, $cliqueA187, $cliqueB187): array {
    frapper($pdo, $cibleB187, $revenus187, 1, '187-contre');
    frapper($pdo, $cibleB187, $revenus187, 1, '187-pour', 1);
    $rep = reputation($pdo, $cibleB187);
    frapper($pdo, $cibleA187, $cliqueA187, 3, '187-a');
    frapper($pdo, $cibleB187, $cliqueB187, 3, '187-b');

    return [[bilan($pdo, $cibleA187), Moderate::compositionChute($pdo, $cibleA187, time())],
            [bilan($pdo, $cibleB187), Moderate::compositionChute($pdo, $cibleB187, time())], $rep];
});
$sansSanction187 = static fn (array $x): bool => $x[0]['rep'] === 0 && !$x[0]['banni'] && $x[0]['vote']
    && $x[1]['voix_sans_lien'] === 1 && $x[1]['votants'] === 7;
$repB187 === 20 && $sansSanction187($a187) && $sansSanction187($b187)
    ? ok('⭐ un vote d\'avant la colonne effet, ou suivi de son contraire, ne fait pas de voix : la clique ne sanctionne pas')
    : nok('⭐ des votes sans effet connu, ou soldés à zéro, font des voix : ' . json_encode([$a187, $b187, $repB187], JSON_UNESCAPED_UNICODE));

// ── 188. ⭐ Sous le seuil du vote, plus de croisement ─────────────────────────────
// Une cible à 5 garde son vote sous le quorum. Elle vote contre chacun des quatre
// critiques qui viennent de la frapper, puis contre un cinquième avant qu'il ne
// vote : aucun vote ne tombe, ni les siens ni ceux des critiques, et la cible
// descend au plancher sans sanction. Croisés, ses votes effaceraient chaque critique
// avant qu'il fasse une voix.
$cible188 = membre($pdo, 'cible-188');
poserReputation($pdo, $cible188, 5);
$critiques188 = membres($pdo, 'critique-188', 5);
$sorts188 = sousQuorum(static function () use ($pdo, $cible188, $critiques188): array {
    $sort = static fn (array $r): string => $r['ok'] ? ($r['blocked_reason'] ?? 'porte') : 'refus : ' . $r['message'];
    $sorts = [];
    foreach (array_slice($critiques188, 0, 4) as $k => $c) {
        $sorts[] = $sort(Moderate::applyVote($pdo, $c, 'post', message($pdo, $cible188, "fil-188-$k"), -1, motif(), 'agressif'));
        $sorts[] = $sort(Moderate::applyVote($pdo, $cible188, 'post', message($pdo, $c, "fil-188-c$k"), -1, motif(), 'agressif'));
    }
    $sorts[] = $sort(Moderate::applyVote($pdo, $cible188, 'post', message($pdo, $critiques188[4], 'fil-188-c4'), -1, motif(), 'agressif'));
    $sorts[] = $sort(Moderate::applyVote($pdo, $critiques188[4], 'post', message($pdo, $cible188, 'fil-188-4'), -1, motif(), 'agressif'));

    return $sorts;
});
$b188 = bilan($pdo, $cible188);
$sorts188 === array_fill(0, 10, 'porte') && portent($pdo, $cible188) === 5 && $b188['rep'] === 0 && !$b188['banni'] && $b188['vote']
    ? ok('⭐ sous le seuil du vote, une cible qui garde son vote ne croise plus : ses critiques font leurs voix, et elle descend sans sanction')
    : nok('⭐ une cible sous le seuil croise encore ses critiques : ' . json_encode([$sorts188, portent($pdo, $cible188), $b188], JSON_UNESCAPED_UNICODE));

// ── 189. ⭐ Une clique qui cesse de s'écrire reste une clique tant que ses votes comptent ─
// Sept amis, liés par des échanges d'il y a une heure, mènent la cible au plancher :
// une voix. Trente et un jours plus tard, ces échanges sortent de la fenêtre des
// liens, et deux autres amis, liés à la même heure, votent. Les liens d'une paire se
// lisent depuis la fenêtre qui précède le plus ancien vote compté de ses deux
// membres : la clique fait toujours une voix ; la paire, qui s'est écrit trente et un
// jours avant ses propres votes, en fait deux. Rien n'est sanctionné. L'horloge de
// convalescence de la cible repart au saut du mois, pour qu'elle reste au plancher.
$t189 = time();
Moderate::setHorloge(static function () use (&$t189): int { return $t189; });
$cible189 = membre($pdo, 'cible-189');
poserReputation($pdo, $cible189, 20);
$clique189 = membres($pdo, 'clique-189', 7);
lierTous($pdo, $clique189);
$paire189 = membres($pdo, 'paire-189', 2);
lierTous($pdo, $paire189);
[$b189, $c189] = sousQuorum(static function () use ($pdo, $cible189, $clique189, $paire189, &$t189): array {
    frapper($pdo, $cible189, $clique189, 3, '189');
    $t189 += 31 * 86400;
    $pdo->prepare('UPDATE member_moderation SET last_regen_at = ? WHERE account_id = ?')->execute([$t189, $cible189]);
    frapper($pdo, $cible189, $paire189, 1, '189-paire');

    return [bilan($pdo, $cible189), Moderate::compositionChute($pdo, $cible189, Moderate::maintenant())];
});
Moderate::setHorloge(null);
$b189['rep'] === 0 && !$b189['banni'] && $b189['vote'] && $c189['voix_sans_lien'] === 3 && $c189['votants'] === 9
    ? ok('⭐ une clique qui cesse de s\'écrire reste une clique tant que ses votes comptent : un mois de silence ne fait pas sept voix, et chaque paire lit ses liens depuis ses propres votes')
    : nok('⭐ une clique muette depuis un mois fait des voix : ' . json_encode([$b189, $c189]));

// ── 190. ⭐ Un vote de soutien ne sanctionne personne ─────────────────────────────
// Six amis liés mènent la cible à 2 : une voix, le vote est gardé. Leurs échanges
// sont ensuite effacés, et un soutien vote pour la cible, qui reste sous le seuil.
// Seul un vote négatif décide d'une sanction : relu à ce vote, le graphe sans liens
// ferait six voix, et retirerait le vote de celui qu'on soutient.
$cible190 = membre($pdo, 'cible-190');
poserReputation($pdo, $cible190, 8);
$six190 = membres($pdo, 'ami-190', 6);
lierTous($pdo, $six190);
[$avant190, $apres190] = sousQuorum(static function () use ($pdo, $cible190, $six190): array {
    frapper($pdo, $cible190, $six190, 1, '190');
    $avant = bilan($pdo, $cible190);
    $ids = implode(',', $six190);
    $pdo->exec("DELETE FROM dm WHERE sender_id IN ($ids) AND recipient_id IN ($ids)");
    frapper($pdo, $cible190, [membre($pdo, 'soutien-190')], 1, '190-soutien', 1);

    return [$avant, bilan($pdo, $cible190)];
});
$avant190['rep'] === 2 && $avant190['vote'] && $apres190['rep'] === 3 && $apres190['vote']
    ? ok('⭐ un vote de soutien ne retire pas le vote de celui qu\'il soutient, même quand le graphe a changé depuis')
    : nok('⭐ un vote de soutien décide d\'une sanction : ' . json_encode([$avant190, $apres190]));

// ── 191. ⭐ Un ban d'arbitre ne retire pas le droit de vote ─────────────────────────
// Banni par un arbitre, un membre est mené à 2 par six amis liés : une voix. Le ban
// levé, il vote. Posé par le ban, le retrait du vote passerait pour une perte réglée,
// et le quorum ne serait jamais cherché.
$cible191 = membre($pdo, 'cible-191');
poserReputation($pdo, $cible191, 8);
$six191 = membres($pdo, 'ami-191', 6);
lierTous($pdo, $six191);
[$b191, $vote191] = sousQuorum(static function () use ($pdo, $cible191, $six191): array {
    Moderate::adminBan($pdo, $cible191, idDe($pdo, 'arbitre-banc'), motif());
    frapper($pdo, $cible191, $six191, 1, '191');
    Moderate::adminLever($pdo, $cible191, idDe($pdo, 'arbitre-banc'), motif());

    return [bilan($pdo, $cible191), Moderate::canVote($pdo, $cible191)];
});
$b191['rep'] === 2 && !$b191['banni'] && $b191['vote'] && $vote191[0] === true
    && in_array('chute_sans_quorum', $b191['causes'], true) && !in_array('droit_de_vote_perdu', $b191['causes'], true)
    ? ok('⭐ un ban d\'arbitre ne retire pas le vote : six amis liés qui frappent pendant le ban ne le font pas perdre')
    : nok('⭐ un ban d\'arbitre retire le vote sans quorum : ' . json_encode([$b191, $vote191], JSON_UNESCAPED_UNICODE));

// ── 192. ⭐ La part des comptes récents se compte en ce que leurs votes ont déplacé ─
// À 30, trois votes positifs d'un compte récent ne déplacent rien : ils ne laissent
// pas de place à six votes négatifs. Au plancher, trois négatifs ne déplacent rien
// non plus. Et la part suit le plafond de son sens : à deux votes positifs par
// votant, un troisième vote positif récent est neutralisé.
$haut192 = membre($pdo, 'cible-192-haut');
poserReputation($pdo, $haut192, 30);
$bas192 = membre($pdo, 'cible-192-bas');
poserReputation($pdo, $bas192, 0);
$sens192 = membre($pdo, 'cible-192-sens');
poserReputation($pdo, $sens192, 10);
$r192 = array_map(static fn (int $k): int => jetable($pdo, "recent-192-$k"), range(1, 7));
$bornes192 = sousQuorum(static function () use ($pdo, $haut192, $bas192, $r192): array {
    frapper($pdo, $haut192, [$r192[0]], 3, '192-r1p', 1);
    frapper($pdo, $haut192, [$r192[0]], 3, '192-r1n');
    frapper($pdo, $haut192, [$r192[1]], 3, '192-r2n');
    frapper($pdo, $bas192, [$r192[2]], 3, '192-r3n');
    frapper($pdo, $bas192, [$r192[3]], 3, '192-r4p', 1);
    frapper($pdo, $bas192, [$r192[4]], 3, '192-r5p', 1);

    return [reputation($pdo, $haut192), reputation($pdo, $bas192)];
});
$parSens192 = sousQuorum(static function () use ($pdo, $sens192, $r192): int {
    frapper($pdo, $sens192, [$r192[5]], 2, '192-r6p', 1);
    frapper($pdo, $sens192, [$r192[6]], 1, '192-r7p', 1);

    return reputation($pdo, $sens192);
}, farmingUpvotesMax: 2);
$bornes192 === [27, 3] && $parSens192 === 12
    ? ok('⭐ la part des comptes récents se compte en ce que leurs votes ont déplacé, aux deux bornes, et suit le plafond de son sens')
    : nok('⭐ la part des comptes récents déplace plus qu\'un membre : ' . json_encode([$bornes192, $parSens192]));

// ── 193. ⭐ Une part remplie par la cible, puis vidée, ne fait pas taire les comptes récents ─
// Trois comptes récents de la cible votent contre elle : la part est pleine. Trois
// comptes récents honnêtes votent alors, et leurs neuf votes sont neutralisés. La
// cible croise ses trois comptes : leurs votes tombent, la part se vide. Les honnêtes
// revotent, et leurs votes portent : neutralisés, les premiers n'avaient rien déplacé,
// et n'ont pas pris leurs plafonds.
$cible193 = membre($pdo, 'cible-193');
poserReputation($pdo, $cible193, 20);
$siens193 = array_map(static fn (int $k): int => jetable($pdo, "sien-193-$k"), range(1, 3));
$honnetes193 = array_map(static fn (int $k): int => jetable($pdo, "honnete-193-$k"), range(1, 3));
$s193 = sousQuorum(static function () use ($pdo, $cible193, $siens193, $honnetes193): array {
    frapper($pdo, $cible193, $siens193, 1, '193-siens');
    frapper($pdo, $cible193, $honnetes193, 3, '193-honnetes');
    $neutres = (int) $pdo->query("SELECT COUNT(*) FROM mod_votes WHERE target_author = $cible193 AND blocked_reason = 'comptes_recents'")->fetchColumn();
    foreach ($siens193 as $k => $s) {
        Moderate::applyVote($pdo, $cible193, 'post', message($pdo, $s, "fil-193-s$k"), -1, motif(), 'agressif');
    }
    $rep = reputation($pdo, $cible193);
    $revote = [];
    foreach ($honnetes193 as $k => $h) {
        $r = Moderate::applyVote($pdo, $h, 'post', message($pdo, $cible193, "fil-193-r$k"), -1, motif(), 'agressif');
        $revote[] = $r['blocked_reason'] ?? 'porte';
    }

    return [$neutres, $rep, $revote, reputation($pdo, $cible193)];
});
$s193 === [9, 20, ['porte', 'porte', 'porte'], 17]
    ? ok('⭐ une part remplie par la cible puis vidée ne fait pas taire les comptes récents : leurs votes neutralisés n\'ont pas pris leurs plafonds')
    : nok('⭐ une part remplie puis vidée fait taire les comptes récents : ' . json_encode($s193));

// ── 194. ⭐ Une chute classée au seuil du vote revient à l'arbitre au plancher ─────
// Quatre amis liés mènent la cible à 4 ; l'arbitre classe la chute. Les mêmes la
// mènent au plancher : la chute revient dans sa file — il a jugé une chute à 4, pas
// une chute à 0.
$cible194 = membre($pdo, 'cible-194');
poserReputation($pdo, $cible194, 8);
$quatre194 = membres($pdo, 'ami-194', 4);
lierTous($pdo, $quatre194);
$file194 = static fn (): bool => in_array($cible194, array_column(
    array_filter(Moderate::signauxOuverts($pdo), static fn (array $s): bool => $s['cause'] === 'chute_sans_quorum'),
    'account_id'
), true);
[$classee194, $plancher194] = sousQuorum(static function () use ($pdo, $cible194, $quatre194, $file194): array {
    frapper($pdo, $cible194, $quatre194, 1, '194-a');
    $s = $pdo->query("SELECT id, detections FROM mod_signaux WHERE account_id = $cible194 AND cause = 'chute_sans_quorum'")->fetch();
    $classe = $s === false ? ['ok' => false] : Moderate::adminClasser($pdo, $cible194, idDe($pdo, 'arbitre-banc'), motif(),
        ['signal' => (int) $s['id'], 'detections' => (int) $s['detections']]);
    $vue = $file194();
    frapper($pdo, $cible194, $quatre194, 1, '194-b');

    return [[$classe['ok'], $vue], [$file194(), bilan($pdo, $cible194)['rep'], bilan($pdo, $cible194)['banni']]];
});
$classee194 === [true, false] && $plancher194 === [true, 0, false]
    ? ok('⭐ une chute classée au seuil du vote revient dans la file de l\'arbitre quand elle atteint le plancher')
    : nok('⭐ une chute classée au seuil du vote se tait jusqu\'au plancher : ' . json_encode([$classee194, $plancher194]));

// ── 195. ⭐ Au-delà de quorumRecherchesMax, seuls les votants liés à personne font des voix ─
// Sous une borne de vingt-cinq recherches, une clique de quatre (six paires, chacune lue
// dans ses deux sens) la dépasse. Sept critiques liés à personne bannissent quand même. Cinq critiques
// liés à personne et une paire de critiques liés entre eux — sept voix sur le graphe
// entier — n'en font plus que cinq : la chute se dit indécise, et rien n'est sanctionné.
[$cibleA195, $cibleB195] = [membre($pdo, 'cible-195-a'), membre($pdo, 'cible-195-b')];
poserReputation($pdo, $cibleA195, 20);
poserReputation($pdo, $cibleB195, 20);
[$cliqueA195, $cliqueB195] = [membres($pdo, 'clique-195-a', 4), membres($pdo, 'clique-195-b', 4)];
lierTous($pdo, $cliqueA195);
lierTous($pdo, $cliqueB195);
$critiquesA195 = membres($pdo, 'critique-195-a', 7);
$critiquesB195 = membres($pdo, 'critique-195-b', 7);
echangePrive($pdo, $critiquesB195[5], $critiquesB195[6]);
[$a195, $b195] = sousQuorum(static function () use ($pdo, $cibleA195, $cibleB195, $cliqueA195, $cliqueB195, $critiquesA195, $critiquesB195): array {
    frapper($pdo, $cibleA195, $cliqueA195, 1, '195-ka');
    frapper($pdo, $cibleA195, $critiquesA195, 3, '195-ca');
    frapper($pdo, $cibleB195, $cliqueB195, 1, '195-kb');
    frapper($pdo, $cibleB195, $critiquesB195, 3, '195-cb');

    return [[bilan($pdo, $cibleA195), Moderate::compositionChute($pdo, $cibleA195, time())],
            [bilan($pdo, $cibleB195), Moderate::compositionChute($pdo, $cibleB195, time())]];
}, quorumRecherchesMax: 25);
$a195[0]['banni'] && $a195[1]['voix_sans_lien'] >= 7 && $a195[1]['paires_liees'] === null
    && $b195[0]['rep'] === 0 && !$b195[0]['banni'] && $b195[0]['vote'] && in_array('chute_sans_quorum', $b195[0]['causes'], true)
    && $b195[1]['voix_sans_lien'] === 5 && $b195[1]['indecis'] && $b195[1]['paires_liees'] === null
    ? ok('⭐ au-delà de quorumRecherchesMax, seuls les votants liés à personne font des voix : sept bannissent, cinq laissent la chute indécise')
    : nok('⭐ la borne de lecture compte mal : ' . json_encode([$a195, $b195]));

// ── 196. ⭐ Une `dm` datée en clair se nomme ; une vue sans l'index de la paire aussi ─
// Sur des connexions neuves où `dm` est une vue temporaire d'une table indexée : datée
// en clair, elle ne lierait personne, et la chute qui passe un seuil lève une erreur
// qui nomme la date ; un instant écrit en chiffres dans une colonne sans type se lit, et
// la clique ne fait qu'une voix. Une vue qui calcule ses identifiants (`+ 0`) ou sa date
// (`strftime`) ne laisse pas l'index servir : la lecture relirait les messages de chaque
// paire, et l'erreur nomme l'index à créer.
$monter196 = static function (string $vue, string $index) use (&$clique196): PDO {
    $c = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
    $c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $c->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // `quand_txt` n'a pas de type : des chiffres y restent du texte, que SQLite ne compare
    // pas à un nombre.
    $c->exec('CREATE TEMP TABLE dm_196 (de INTEGER, a INTEGER, quand_ms INTEGER, quand TEXT, quand_txt)');
    $c->exec("CREATE INDEX temp.dm_196_paire ON dm_196($index)");
    $ecrire = $c->prepare("INSERT INTO dm_196 VALUES (?, ?, ?, datetime('now', '-1 hour'), strftime('%s', 'now', '-1 hour'))");
    foreach ($clique196 as $i => $x) {
        foreach (array_slice($clique196, $i + 1) as $y) {
            $ecrire->execute([$x, $y, (time() - 3600) * 1000]);
            $ecrire->execute([$y, $x, (time() - 3600) * 1000]);
        }
    }
    $c->exec("CREATE TEMP VIEW dm AS SELECT $vue FROM dm_196");

    return $c;
};
$cibles196 = [];
foreach (['texte' => 6, 'chiffres' => 20, 'ids' => 20, 'strftime' => 20] as $forme196 => $depart196) {
    $cibles196[$forme196] = membre($pdo, "cible-196-$forme196");
    poserReputation($pdo, $cibles196[$forme196], $depart196);
}
$clique196 = membres($pdo, 'clique-196', 7);
$formes196 = sousQuorum(static function () use ($monter196, $cibles196, $clique196): array {
    $frappe = static function (PDO $c, int $cible, string $nom) use ($clique196): array {
        try {
            frapper($c, $cible, $clique196, 3, $nom);
        } catch (Throwable $t) {
            return ['erreur' => get_class($t) . ' : ' . $t->getMessage()];
        }

        return bilan($c, $cible);
    };
    $texte = $monter196('de AS sender_id, a AS recipient_id, quand AS created_at', 'a, de, quand');
    $enTexte = null;
    try {
        Moderate::applyVote($texte, $clique196[0], 'member', $cibles196['texte'], -1, motif(), 'agressif');
        Moderate::applyVote($texte, $clique196[1], 'member', $cibles196['texte'], -1, motif(), 'agressif');
    } catch (Throwable $t) {
        $enTexte = get_class($t) . ' : ' . $t->getMessage();
    }

    return [
        'texte'    => $enTexte,
        'chiffres' => $frappe($monter196('de AS sender_id, a AS recipient_id, quand_txt AS created_at', 'a, de, quand_txt'), $cibles196['chiffres'], '196-c'),
        'ids'      => $frappe($monter196('de + 0 AS sender_id, a + 0 AS recipient_id, quand_ms / 1000 AS created_at', 'a, de, quand_ms'), $cibles196['ids'], '196-i'),
        'strftime' => $frappe($monter196("de AS sender_id, a AS recipient_id, strftime('%s', quand) AS created_at", 'a, de, quand'), $cibles196['strftime'], '196-s'),
    ];
});
$nomme196 = static fn (mixed $r, string $mot): bool => str_contains(is_array($r) ? (string) ($r['erreur'] ?? '') : (string) $r, $mot);
$nomme196($formes196['texte'], 'dm.created_at') && reputation($pdo, $cibles196['texte']) === 5
    && ($formes196['chiffres']['rep'] ?? null) === 0 && !$formes196['chiffres']['banni'] && $formes196['chiffres']['vote']
    && $nomme196($formes196['ids'], 'idx_dm_paire') && $nomme196($formes196['strftime'], 'idx_dm_paire')
    ? ok('⭐ une dm datée en clair lève une erreur qui nomme la date ; des instants en chiffres dans une colonne de texte lient, et la clique ne sanctionne pas ; une vue qui calcule ses identifiants ou sa date nomme l\'index de la paire')
    : nok('⭐ une dm mal typée se tait, ou une vue calculée ne lie personne : ' . json_encode([$formes196, reputation($pdo, $cibles196['texte'])], JSON_UNESCAPED_UNICODE));

// ── 197. ⭐ La console montre à l'arbitre ce que le quorum a lu ─────────────────────
// Une chute sans quorum et un ban automatique : la ligne de chacun dit les voix sans
// lien, les votants et les paires liées. L'arbitre que le prix du quorum saisit
// décide sur ces faits.
$chute197 = membre($pdo, 'console-chute-197');
poserReputation($pdo, $chute197, 20);
$ban197 = membre($pdo, 'console-ban-197');
poserReputation($pdo, $ban197, 20);
$clique197 = membres($pdo, 'clique-197', 7);
lierTous($pdo, $clique197);
$critiques197 = membres($pdo, 'critique-197', 7);
sousQuorum(static function () use ($pdo, $chute197, $ban197, $clique197, $critiques197): void {
    frapper($pdo, $chute197, $clique197, 3, '197-k');
    frapper($pdo, $ban197, $critiques197, 3, '197-c');
});
$serveur197 = serveurLab();
$page197 = requeteLab($serveur197, '/admin.php', sessionArbitre($pdo, 'arbitre-197'))['corps'];
arreterLab($serveur197);
// Toutes les lignes du compte : une salve a la sienne, avant ou après la chute.
$ligne197 = static fn (string $nom): string => preg_match_all('~<tr><td>@' . preg_quote($nom, '~') . '\b.*?</tr>~s', $page197, $m) > 0
    ? implode("\n", $m[0]) : '';
str_contains($ligne197('console-chute-197'), '1 voix sans lien sur 7 votant(s) · 21 paire(s) liée(s)')
    && str_contains($ligne197('console-ban-197'), '7 voix sans lien sur 7 votant(s) · 0 paire(s) liée(s)')
    ? ok('⭐ la console montre ce que le quorum a lu, pour une chute sans quorum comme pour un ban automatique')
    : nok('⭐ la console tait ce que le quorum a lu : ' . json_encode([strlen($page197), $ligne197('console-chute-197'), $ligne197('console-ban-197')], JSON_UNESCAPED_UNICODE));

// ── 198. ⭐ La composition d'un ban se lit à son début : ce qui suit n'y entre pas ─
// Sept critiques sans lien bannissent. Trente secondes plus tard, deux d'entre eux
// s'écrivent, et un huitième votant frappe la cible bannie. Lue à l'instant du ban,
// la composition ne voit ni ce lien ni ce vote : sept votants, sept voix, aucune
// paire — sinon une réponse tardive retirerait une voix à la peine affichée. Le
// votant tardif s'était lié d'avance à un critique : lu, il ajouterait une paire, là
// où la recherche, arrêtée au quorum, ne dirait pas une huitième voix. Le ban fini et
// la chute soldée, la composition lue à son début reste la sienne.
$t198 = time();
Moderate::setHorloge(static function () use (&$t198): int { return $t198; });
$cible198 = membre($pdo, 'cible-198');
poserReputation($pdo, $cible198, 20);
$sept198 = membres($pdo, 'critique-198', 7);
$tard198 = membre($pdo, 'tard-198');
echangePrive($pdo, $tard198, $sept198[2]);
[$debut198, $c198, $ch198, $fini198] = sousQuorum(static function () use ($pdo, $cible198, $sept198, $tard198, &$t198): array {
    frapper($pdo, $cible198, $sept198, 3, '198');
    $debut = $t198;
    $t198 += 30;
    $dm = $pdo->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)');
    $dm->execute([$sept198[0], $sept198[1], 'chiffre', $t198]);
    $dm->execute([$sept198[1], $sept198[0], 'chiffre', $t198]);
    Moderate::applyVote($pdo, $tard198, 'member', $cible198, -1, motif(), 'agressif');
    $enCours = array_values(array_filter(Moderate::bansEnCours($pdo), static fn (array $b): bool => $b['account_id'] === $cible198));

    $pendant = Moderate::compositionChute($pdo, $cible198, $debut);
    $t198 += Moderate::config()->dureesBan[0] + Moderate::config()->arrondiFinBanSecondes;
    // Un refus du journal sur un autre compte remonte en fin de passe, après elle.
    try {
        Moderate::balayerBansEchus($pdo);
    } catch (\RuntimeException) {
    }

    return [$debut, $pendant, $enCours[0]['chute'] ?? null, Moderate::compositionChute($pdo, $cible198, $debut)];
});
Moderate::setHorloge(null);
$c198['votants'] === 7 && $c198['voix_sans_lien'] === 7 && $c198['paires_liees'] === 0 && $ch198 === $c198 && $fini198 === $c198
    ? ok('⭐ la composition d\'un ban se lit à son début : un lien et un vote venus après n\'y entrent pas')
    : nok('⭐ la composition d\'un ban lit ce qui l\'a suivi : ' . json_encode([$debut198, $c198, $ch198, $fini198, bilan($pdo, $cible198),
        $pdo->query("SELECT COALESCE(blocked_reason, 'porte') AS r, MIN(created_at) AS de, MAX(created_at) AS a, COUNT(*) AS n FROM mod_votes WHERE target_author = $cible198 GROUP BY r")->fetchAll()], JSON_UNESCAPED_UNICODE));

// ── 199. ⭐ Un votant dont un vote négatif est d'un compte établi fait une voix à lui ─
// Un compte vote une première fois récent, une seconde fois établi : il n'est pas de
// la classe des récents, qui ne prend que ceux dont chaque vote négatif a été émis
// récent. Cinq établis, ce votant et un compte récent font sept voix : la cible est
// bannie. Rangé avec les récents, il n'en ferait que six.
$t199 = time();
Moderate::setHorloge(static function () use (&$t199): int { return $t199; });
$cible199 = membre($pdo, 'cible-199');
poserReputation($pdo, $cible199, 8);
$cinq199 = membres($pdo, 'critique-199', 5);
[$mixte199, $recent199] = [jetable($pdo, 'mixte-199'), jetable($pdo, 'recent-199')];
[$b199, $c199] = sousQuorum(static function () use ($pdo, $cible199, $cinq199, $mixte199, $recent199, &$t199): array {
    Moderate::applyVote($pdo, $mixte199, 'post', message($pdo, $cible199, 'fil-199-m1'), -1, motif(), 'agressif');
    Moderate::applyVote($pdo, $recent199, 'post', message($pdo, $cible199, 'fil-199-r'), -1, motif(), 'agressif');
    $t199 += Moderate::config()->ageEtabliSecondes;
    frapper($pdo, $cible199, $cinq199, 1, '199');
    Moderate::applyVote($pdo, $mixte199, 'post', message($pdo, $cible199, 'fil-199-m2'), -1, motif(), 'agressif');

    return [bilan($pdo, $cible199), Moderate::compositionChute($pdo, $cible199, Moderate::maintenant())];
});
Moderate::setHorloge(null);
$b199['banni'] && $c199['recents'] === 1 && $c199['voix_sans_lien'] === 7
    ? ok('⭐ un votant dont un vote négatif est d\'un compte établi fait une voix à lui : sept voix, la cible est bannie')
    : nok('⭐ un votant établi est rangé avec les récents : ' . json_encode([$b199, $c199]));

// ── 200. ⭐ La perte du vote ferme la chute sans quorum, et le ban la nomme parmi ce qu'il ferme ─
// Une paire liée mène la cible à 4 : chute sans quorum. Cinq critiques sans lien
// votent ensuite, chacun suivi d'un soutien qui rend le point : six voix, le vote
// tombe, et la chute prend fin. Ailleurs, une clique mène une cible au plancher, puis
// six votants sans lien font le quorum du ban : l'acte du début de ban dit qu'il
// ferme la chute.
$cibleA200 = membre($pdo, 'cible-200-a');
poserReputation($pdo, $cibleA200, 6);
$paire200 = membres($pdo, 'paire-200', 2);
lierTous($pdo, $paire200);
$critiques200 = membres($pdo, 'critique-200', 5);
$soutiens200 = membres($pdo, 'soutien-200', 5);
$cibleB200 = membre($pdo, 'cible-200-b');
poserReputation($pdo, $cibleB200, 20);
$clique200 = membres($pdo, 'clique-200', 7);
lierTous($pdo, $clique200);
$renforts200 = membres($pdo, 'renfort-200', 6);
[$a200, $ferme200] = sousQuorum(static function (JournalDeBanc $journal) use ($pdo, $cibleA200, $paire200, $critiques200, $soutiens200, $cibleB200, $clique200, $renforts200): array {
    frapper($pdo, $cibleA200, $paire200, 1, '200-paire');
    foreach ($critiques200 as $k => $c) {
        Moderate::applyVote($pdo, $c, 'post', message($pdo, $cibleA200, "fil-200-c$k"), -1, motif(), 'agressif');
        Moderate::applyVote($pdo, $soutiens200[$k], 'post', message($pdo, $cibleA200, "fil-200-s$k"), 1, motif(), 'agressif');
    }
    frapper($pdo, $cibleB200, $clique200, 3, '200-k');
    frapper($pdo, $cibleB200, $renforts200, 1, '200-r');

    return [bilan($pdo, $cibleA200), (string) ($journal->de($cibleB200, 'ban_debut')[0]['ferme'] ?? '')];
});
!$a200['vote'] && in_array('droit_de_vote_perdu', $a200['causes'], true) && !in_array('chute_sans_quorum', $a200['causes'], true)
    && str_contains($ferme200, 'chute_sans_quorum')
    ? ok('⭐ la perte du vote ferme la chute sans quorum, et l\'acte du ban qui la remplace la nomme parmi ce qu\'il ferme')
    : nok('⭐ la chute sans quorum survit à la sanction, ou l\'acte du ban la tait : ' . json_encode([$a200, $ferme200], JSON_UNESCAPED_UNICODE));

// ── 201. ⭐ Seuls les deux membres d'une paire règlent la profondeur de ses liens ─
// Sept critiques se sont tous écrit il y a quatre-vingts jours, plus rien depuis, et
// votent aujourd'hui ; un compte de la cible a voté contre elle cinquante-cinq jours
// plus tôt. Lus depuis le vote de ce compte, leurs échanges en feraient une clique, une
// voix ; lus depuis leurs propres votes, ils font sept voix, et la cible est bannie. Le
// partage tient au-delà de la borne de lecture, où le repli cherche compte par compte.
// Témoin : sept critiques qui se sont écrit vingt-cinq jours avant leurs votes restent
// une clique.
$t201 = time();
Moderate::setHorloge(static function () use (&$t201): int { return $t201; });
$ancien201 = static function (string $nom) use ($pdo, $t201): int {
    $pdo->prepare('INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$nom, 'x', 'x', 'x', $t201 - 100 * 86400]);
    return (int) $pdo->lastInsertId();
};
$echange201 = static function (array $comptes, int $quand, int $fois = 1) use ($pdo): void {
    $dm = $pdo->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)');
    foreach ($comptes as $i => $a) {
        foreach (array_slice($comptes, $i + 1) as $b) {
            for ($k = 0; $k < $fois; $k++) {
                $dm->execute([$a, $b, 'chiffre', $quand]);
                $dm->execute([$b, $a, 'chiffre', $quand]);
            }
        }
    }
};
$scenes201 = [];
foreach (['vieux' => 80, 'recent' => 25, 'repli' => 80] as $nom201 => $jours201) {
    $cible = membre($pdo, "cible-201-$nom201");
    poserReputation($pdo, $cible, 20);
    $comptes = [$ancien201("compte-201-$nom201")];
    $critiques = array_map(static fn (int $k): int => $ancien201("critique-201-$nom201-$k"), range(1, 7));
    $echange201($critiques, $t201 - $jours201 * 86400);
    if ($nom201 === 'repli') {
        // Dix comptes de la cible, liés deux à deux, font dépasser la borne de lecture.
        foreach (range(2, 10) as $k) {
            $comptes[] = $ancien201("autre-201-repli-$k");
        }
        $echange201($comptes, $t201 - 3600);
    }
    $scenes201[$nom201] = sousQuorum(static function () use ($pdo, $cible, $comptes, $critiques, $nom201, &$t201): array {
        $t201 -= 55 * 86400;
        frapper($pdo, $cible, $comptes, 1, "201-$nom201-ancien");
        $t201 += 55 * 86400;
        frapper($pdo, $cible, $critiques, 3, "201-$nom201");

        return [bilan($pdo, $cible), Moderate::compositionChute($pdo, $cible, Moderate::maintenant())];
    }, ...($nom201 === 'repli' ? ['quorumRecherchesMax' => 200] : []));
}
Moderate::setHorloge(null);
$attendu201 = static fn (array $s, bool $banni, int $voix, ?int $paires): bool => $s[0]['banni'] === $banni
    && $s[1]['voix_sans_lien'] === $voix && $s[1]['paires_liees'] === $paires;
$attendu201($scenes201['vieux'], true, 7, 0) && $attendu201($scenes201['repli'], true, 7, null)
    && $attendu201($scenes201['recent'], false, 2, 21) && $scenes201['recent'][0]['vote']
    ? ok('⭐ seuls les deux membres d\'une paire règlent la profondeur de ses liens : le vote ancien d\'un compte de la cible n\'en fait pas une clique de sept critiques, au repli compris')
    : nok('⭐ un tiers règle la profondeur des liens d\'une paire : ' . json_encode($scenes201, JSON_UNESCAPED_UNICODE));

// ── 202. ⭐ La sortie de convalescence ne solde pas la chute ───────────────────────
// Quatre critiques sans lien mènent la cible de 20 à 8. Dans un ordre, des soutiens la
// remontent au départ avant toute chute sous 5 ; dans l'autre, quatre votes d'une
// clique la font passer à 4, sans quorum, et les soutiens la remontent ensuite jusqu'à
// la sortie de convalescence. Deux critiques neufs, puis la clique, la mènent au
// plancher : les quatre premiers critiques sont des voix dans les deux ordres, et la
// cible est bannie. Une remontée que des soutiens hâtent n'efface pas les voix des critiques.
$scenes202 = [];
foreach (['avant', 'apres'] as $ordre202) {
    $cible = membre($pdo, "cible-202-$ordre202");
    poserReputation($pdo, $cible, 20);
    $critiques = membres($pdo, "critique-202-$ordre202", 4);
    $neufs = membres($pdo, "neuf-202-$ordre202", 2);
    $clique = membres($pdo, "clique-202-$ordre202", 7);
    lierTous($pdo, $clique);
    $soutiens = membres($pdo, "soutien-202-$ordre202", 6);
    $scenes202[$ordre202] = sousQuorum(static function () use ($pdo, $cible, $critiques, $neufs, $clique, $soutiens, $ordre202): array {
        frapper($pdo, $cible, $critiques, 3, "202-$ordre202-c");
        $a4 = null;
        if ($ordre202 === 'apres') {
            frapper($pdo, $cible, array_slice($clique, 0, 4), 1, "202-$ordre202-k0");
            $a4 = bilan($pdo, $cible);
            frapper($pdo, $cible, $soutiens, 3, "202-$ordre202-s", 1);
        } else {
            frapper($pdo, $cible, $soutiens, 2, "202-$ordre202-s", 1);
        }
        frapper($pdo, $cible, $neufs, 3, "202-$ordre202-n");
        $soldee = (int) $pdo->query("SELECT chute_soldee_le FROM member_moderation WHERE account_id = $cible")->fetchColumn();
        frapper($pdo, $cible, $clique, 3, "202-$ordre202-k");

        return [$a4, $soldee, bilan($pdo, $cible)];
    });
}
$scenes202['avant'][2]['banni'] && $scenes202['apres'][2]['banni'] && $scenes202['apres'][1] === 0
    && $scenes202['apres'][0]['rep'] === 4 && in_array('chute_sans_quorum', $scenes202['apres'][0]['causes'], true)
    ? ok('⭐ la sortie de convalescence ne solde pas la chute : des soutiens qui remontent la cible au départ n\'effacent pas les voix de ses critiques')
    : nok('⭐ la sortie de convalescence blanchit la chute : ' . json_encode($scenes202, JSON_UNESCAPED_UNICODE));

// ── 203. ⭐ Un vote que la part neutralise ne relance pas la salve ─────────────────
// Trois comptes récents votent ensemble contre un membre : la part se remplit, la
// salve est signalée, et l'arbitre l'affiche. Les mêmes votent contre trois autres de
// ses messages : leurs votes sont neutralisés, la salve n'est pas redétectée, et le
// classement de ce que l'arbitre a vu passe.
$recent203 = static function (string $nom) use ($pdo): int {
    $pdo->prepare('INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$nom, 'x', 'x', 'x', time() - 600]);
    return (int) $pdo->lastInsertId();
};
$cible203 = membre($pdo, 'cible-203');
poserReputation($pdo, $cible203, 20);
$recents203 = array_map(static fn (int $k): int => $recent203("recent-203-$k"), range(1, 3));
$salve203 = static fn (): array|false => $pdo->query(
    "SELECT id, detections, occurrences FROM mod_signaux WHERE account_id = $cible203 AND cause = 'salve_rapide'"
)->fetch();
[$vue203, $apres203, $classe203, $sorts203] = sousQuorum(static function () use ($pdo, $cible203, $recents203, $salve203): array {
    frapper($pdo, $cible203, $recents203, 1, '203-a');
    $vue = $salve203();
    frapper($pdo, $cible203, $recents203, 3, '203-b');
    $apres = $salve203();
    $classe = $vue === false ? ['ok' => false, 'message' => 'aucune salve'] : Moderate::adminClasser(
        $pdo, $cible203, idDe($pdo, 'arbitre-banc'), motif(), ['signal' => (int) $vue['id'], 'detections' => (int) $vue['detections']]
    );
    $sorts = $pdo->query("SELECT COALESCE(blocked_reason, 'porte') AS r, COUNT(*) AS n FROM mod_votes WHERE target_author = $cible203 GROUP BY r ORDER BY r")
        ->fetchAll(PDO::FETCH_KEY_PAIR);

    return [$vue, $apres, $classe, array_map('intval', $sorts)];
});
$vue203 !== false && $apres203 == $vue203 && $classe203['ok'] && $sorts203 === ['comptes_recents' => 9, 'porte' => 3]
    ? ok('⭐ un vote que la part des comptes récents neutralise ne relance pas la salve : l\'arbitre classe ce qu\'il a vu')
    : nok('⭐ la part relance la salve, ou l\'arbitre ne peut plus classer : ' . json_encode([$vue203, $apres203, $classe203, $sorts203], JSON_UNESCAPED_UNICODE));

// ── 204. ⭐ Une base sans la colonne de la 0.9.0 se nomme avant d'écrire ─────────
// Un ban automatique tombe, puis la base perd `chute_soldee_le` — celle d'un hôte qui
// a monté sans elle. Sur une connexion neuve, la fin de la peine, le vote et la grâce
// lèvent une erreur qui nomme la colonne, et le journal ne reçoit aucune fin de peine :
// sans cette garde, chaque essai y en inscrirait une de plus.
$f204 = dirname((string) getenv('LAB_DB_PATH')) . '/sans-colonne-204.db';
$a204 = new PDO('sqlite:' . $f204);
$a204->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$a204->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$a204->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));
$cible204 = membre($a204, 'cible-204');
$sept204 = membres($a204, 'critique-204', 7);
$arbitre204 = membre($a204, 'arbitre-204');
$t204 = time();
Moderate::setHorloge(static function () use (&$t204): int { return $t204; });
[$banni204, $gestes204, $fins204] = sousQuorum(static function (JournalDeBanc $journal) use ($a204, $f204, $cible204, $sept204, $arbitre204, &$t204): array {
    frapper($a204, $cible204, $sept204, 3, '204');
    $banni = Moderate::getReputation($a204, $cible204)['banned'];
    $a204->exec('ALTER TABLE member_moderation DROP COLUMN chute_soldee_le');
    $b204 = new PDO('sqlite:' . $f204);
    $b204->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $b204->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $t204 += Moderate::config()->dureesBan[0] + Moderate::config()->arrondiFinBanSecondes + 1;
    $gestes = [];
    foreach ([
        'balayage' => static fn (): mixed => Moderate::balayerBansEchus($b204),
        'vote'     => static fn (): mixed => Moderate::applyVote($b204, $sept204[0], 'member', $cible204, -1, motif(), 'agressif'),
        'grace'    => static fn (): mixed => Moderate::adminPardon($b204, $cible204, $arbitre204, motif()),
    ] as $geste => $faire) {
        try {
            $faire();
            $gestes[$geste] = 'passé';
        } catch (LogicException $e) {
            $gestes[$geste] = str_contains($e->getMessage(), 'chute_soldee_le') ? 'nommée' : $e->getMessage();
        } catch (Throwable $e) {
            $gestes[$geste] = get_class($e) . ' : ' . $e->getMessage();
        }
    }

    return [$banni, $gestes, count($journal->de($cible204, 'ban_fin'))];
});
Moderate::setHorloge(null);
$a204 = null;
$banni204 && $gestes204 === ['balayage' => 'nommée', 'vote' => 'nommée', 'grace' => 'nommée'] && $fins204 === 0
    ? ok('⭐ une base sans chute_soldee_le se nomme à la première écriture d\'une connexion, et le journal ne reçoit aucune fin de peine')
    : nok('⭐ une base sans chute_soldee_le écrit au journal avant d\'échouer : ' . json_encode([$banni204, $gestes204, $fins204], JSON_UNESCAPED_UNICODE));

// ── 205. ⭐ Une recherche du quorum ne laisse rien en mémoire ──────────────────────
// Soixante votants tous liés : trente lectures de la composition de leur chute, le
// ramasse-cycles coupé, ne retiennent pas de mémoire. Un processus qui enchaîne les
// recherches — la console, un hôte persistant — n'en paie pas la somme.
$cible205 = membre($pdo, 'cible-205');
poserReputation($pdo, $cible205, 3);
votesDeFond($pdo, $cible205, message($pdo, $cible205, 'fil-205'), 60, '205');
lierTous($pdo, array_map(static fn (int $j): int => idDe($pdo, "fond-205-$j"), range(0, 59)));
$memoire205 = sousQuorum(static function () use ($pdo, $cible205): int {
    Moderate::compositionChute($pdo, $cible205, time());
    gc_collect_cycles();
    gc_disable();
    try {
        $avant = memory_get_usage();
        for ($i = 0; $i < 30; $i++) {
            Moderate::compositionChute($pdo, $cible205, time());
        }

        return memory_get_usage() - $avant;
    } finally {
        gc_enable();
    }
});
$memoire205 < 256 * 1024
    ? ok('⭐ trente recherches du quorum, sans ramasse-cycles, ne retiennent pas de mémoire')
    : nok('⭐ chaque recherche du quorum reste en mémoire : ' . $memoire205 . ' octets retenus en trente appels');

// ── 206. ⭐ Aux bornes, la part borne encore les votes des comptes récents ──────────
// Un membre à 30 : six comptes récents votent pour ses trois messages — trois votes
// portent, sans rien déplacer, et les quinze suivants sont neutralisés ; les scores de
// ses messages ne montent que de trois points en tout. Au plancher, de même pour
// dix-huit votes négatifs. Un membre à 30 qui reçoit trois votes positifs puis neuf
// négatifs de comptes récents ne descend que de trois points : un vote sans effet ne
// compte pas dans l'autre sens.
$scenes206 = [];
foreach (['haut' => [30, [1]], 'bas' => [0, [-1]], 'retour' => [30, [1, -1]]] as $nom206 => [$depart206, $sens206]) {
    $cible = membre($pdo, "cible-206-$nom206");
    poserReputation($pdo, $cible, $depart206);
    $posts = [message($pdo, $cible, "fil-206-$nom206-1"), message($pdo, $cible, "fil-206-$nom206-2"), message($pdo, $cible, "fil-206-$nom206-3")];
    $scenes206[$nom206] = sousQuorum(static function () use ($pdo, $cible, $posts, $nom206, $sens206, $recent203): array {
        foreach ($sens206 as $s => $valeur) {
            $combien = $nom206 === 'retour' ? 3 : 6;
            foreach (range(1, $combien) as $k) {
                $votant = $recent203("recent-206-$nom206-$s-$k");
                foreach ($nom206 === 'retour' && $valeur === 1 ? [$posts[0]] : $posts as $post) {
                    Moderate::applyVote($pdo, $votant, 'post', $post, $valeur, motif(), 'agressif');
                }
            }
        }
        $sorts = $pdo->query("SELECT COALESCE(blocked_reason, 'porte') || ' ' || value AS r, COUNT(*) AS n FROM mod_votes WHERE target_author = $cible GROUP BY r ORDER BY r")
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        return ['rep' => reputation($pdo, $cible), 'banni' => Moderate::getReputation($pdo, $cible)['banned'],
                'scores' => array_sum(array_map(static fn (int $p): int => Moderate::score($pdo, 'post', $p), $posts)),
                'sorts' => array_map('intval', $sorts)];
    });
}
$scenes206['haut'] === ['rep' => 30, 'banni' => false, 'scores' => 3, 'sorts' => ['comptes_recents 1' => 15, 'porte 1' => 3]]
    && $scenes206['bas'] === ['rep' => 0, 'banni' => false, 'scores' => -3, 'sorts' => ['comptes_recents -1' => 15, 'porte -1' => 3]]
    && $scenes206['retour']['rep'] === 27 && $scenes206['retour']['sorts'] === ['comptes_recents -1' => 6, 'porte -1' => 3, 'porte 1' => 3]
    ? ok('⭐ aux bornes, la part des comptes récents borne encore leurs votes, et un vote sans effet ne compte pas dans l\'autre sens')
    : nok('⭐ aux bornes, les comptes récents votent sans borne, ou un vote sans effet ouvre l\'autre sens : ' . json_encode($scenes206, JSON_UNESCAPED_UNICODE));

// ── 207. ⭐ Une date de `dm` illisible se nomme, ou ne lie personne ; l'index sert ──
// Sur une connexion où `dm` est une vue temporaire de tables indexées : datée en
// millisecondes ou en jour julien, aucun sens ne se lit, et la chute qui passe un seuil
// lève une erreur qui nomme la date — elle ne lierait personne, et chaque votant ferait
// une voix. Une colonne dont une partie seulement est en clair ne lève pas : le sens
// illisible ne lie personne, et le journal d'erreurs le dit. Sur la table du lab, un seul
// vieux message daté en millisecondes, dans un sens, ne gèle aucune chute ; et la
// recherche d'expéditeur passe par l'index de la paire.
final class PdoEspion extends PDO
{
    /** @var list<string> */
    public array $lus = [];

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'FROM dm')) {
            $this->lus[] = $query;
        }

        return parent::prepare($query, $options);
    }
}
$c207 = new PdoEspion('sqlite:' . getenv('LAB_DB_PATH'));
$c207->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$c207->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$paire207 = membres($pdo, 'paire-207', 2);
$formes207 = [
    'millisecondes' => [(time() - 3600) * 1000, (time() - 3600) * 1000],
    'julien'        => [2440587.5 + (time() - 3600) / 86400, 2440587.5 + (time() - 3600) / 86400],
    'melange'       => [time() - 3600, date('Y-m-d H:i:s', time() - 3600)],
];
$leve207 = sousQuorum(static function () use ($c207, $pdo, $paire207, $formes207): array {
    $leve = [];
    foreach ($formes207 as $forme => [$aller, $retour]) {
        // NUMERIC garde à chaque date son type : `execute()` lie tout en texte.
        $c207->exec("CREATE TEMP TABLE dm_207_$forme (de INTEGER, a INTEGER, quand NUMERIC)");
        $c207->exec("CREATE INDEX temp.dm_207_{$forme}_paire ON dm_207_$forme(a, de, quand)");
        $ecrire = $c207->prepare("INSERT INTO dm_207_$forme VALUES (?, ?, ?)");
        $ecrire->execute([$paire207[0], $paire207[1], $aller]);
        $ecrire->execute([$paire207[1], $paire207[0], $retour]);
        $cible = membre($pdo, "cible-207-$forme");
        poserReputation($pdo, $cible, 6);
        $c207->exec("CREATE TEMP VIEW dm AS SELECT de AS sender_id, a AS recipient_id, quand AS created_at FROM dm_207_$forme");
        try {
            frapper($c207, $cible, $paire207, 1, "207-$forme");
            $leve[$forme] = 'muette, réputation ' . reputation($pdo, $cible);
        } catch (Throwable $e) {
            $leve[$forme] = $e instanceof LogicException && str_contains($e->getMessage(), 'dm.created_at') ? 'nommée' : get_class($e) . ' : ' . $e->getMessage();
        }
        $c207->exec('DROP VIEW temp.dm');
    }

    return $leve;
});
$ancien207 = sousQuorum(static function () use ($pdo): string {
    $cible = membre($pdo, 'cible-207-ancien');
    poserReputation($pdo, $cible, 6);
    $deux = membres($pdo, 'paire-207-ancien', 2);
    $pdo->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$deux[0], $deux[1], 'chiffre', (time() - 2 * 365 * 86400) * 1000]);
    try {
        frapper($pdo, $cible, $deux, 1, '207-ancien');
    } catch (Throwable $e) {
        return get_class($e) . ' : ' . $e->getMessage();
    }

    return 'réputation ' . reputation($pdo, $cible);
});
$plan207 = sousQuorum(static function () use ($c207, $pdo): string {
    $cible = membre($pdo, 'cible-207-index');
    poserReputation($pdo, $cible, 6);
    $c207->lus = [];
    frapper($c207, $cible, membres($pdo, 'paire-207-index', 2), 1, '207-index');
    $recherche = array_values(array_filter($c207->lus, static fn (string $q): bool => str_contains($q, 'MIN(sender_id)')));
    if ($recherche === []) {
        return 'aucune recherche d\'expéditeur';
    }

    return implode(' | ', array_column($c207->query('EXPLAIN QUERY PLAN ' . $recherche[0])->fetchAll(), 'detail'));
});
$c207 = null;
$leve207 === ['millisecondes' => 'nommée', 'julien' => 'nommée', 'melange' => 'muette, réputation 4'] && $ancien207 === 'réputation 4'
    && str_contains($plan207, 'USING COVERING INDEX idx_dm_paire (recipient_id=? AND sender_id>?)')
    ? ok('⭐ une dm en millisecondes ou en jour julien se nomme ; un sens illisible parmi des sens lisibles, ou un vieux message mal daté, ne lie personne sans geler la chute ; la recherche d\'expéditeur passe par l\'index de la paire')
    : nok('⭐ une dm mal datée se tait ou gèle la chute, ou la lecture des liens quitte l\'index : ' . json_encode([$leve207, $ancien207, $plan207], JSON_UNESCAPED_UNICODE));

// ── 208. ⭐ La recherche s'arrête au quorum ─────────────────────────────────────────
// Dix-sept critiques sans lien mènent la cible au plancher : la composition dit sept
// voix, le quorum du ban, et non dix-sept — la recherche n'a pas lu plus loin.
$cible208 = membre($pdo, 'cible-208');
poserReputation($pdo, $cible208, 17);
$critiques208 = membres($pdo, 'critique-208', 17);
$c208 = sousQuorum(static function () use ($pdo, $cible208, $critiques208): array {
    frapper($pdo, $cible208, $critiques208, 1, '208');

    return [bilan($pdo, $cible208)['banni'], Moderate::compositionChute($pdo, $cible208, time())];
});
$c208[0] && $c208[1]['votants'] === 17 && $c208[1]['voix_sans_lien'] === Moderate::config()->voixPourSanction(Moderate::config()->banA)
    ? ok('⭐ la recherche du quorum s\'arrête au quorum : dix-sept votants sans lien se lisent sept voix')
    : nok('⭐ la recherche du quorum lit au-delà du quorum : ' . json_encode($c208, JSON_UNESCAPED_UNICODE));

// ── 209. ⭐ Classée au plancher, la chute ne revient pas pour un vote qui n'y change rien ─
// Une clique de sept mène la cible au plancher : une voix, la chute est signalée, et
// l'arbitre la classe. Un huitième ami, lié à la clique, vote au plancher : rien ne
// s'aggrave — ni le plancher, déjà atteint, ni le vote, encore là —, et la chute reste
// classée.
// ── 210. ⭐ Classée au plancher, la chute revient quand elle retire le droit de vote ─
// La même scène, puis cinq critiques sans lien votent : six voix, le vote tombe, et la
// chute revient dans la file de l'arbitre, qui avait classé une chute sans sanction.
$classees209 = [];
foreach (['ami' => 1, 'critiques' => 5] as $suite209 => $combien209) {
    $cible = membre($pdo, "cible-209-$suite209");
    poserReputation($pdo, $cible, 20);
    $clique = membres($pdo, "clique-209-$suite209", 7);
    $suivants = membres($pdo, "suivant-209-$suite209", $combien209);
    lierTous($pdo, $suite209 === 'ami' ? [...$clique, ...$suivants] : $clique);
    $file = static fn (): bool => in_array($cible, array_column(
        array_filter(Moderate::signauxOuverts($pdo), static fn (array $s): bool => $s['cause'] === 'chute_sans_quorum'),
        'account_id'
    ), true);
    $classees209[$suite209] = sousQuorum(static function () use ($pdo, $cible, $clique, $suivants, $suite209, $file): array {
        frapper($pdo, $cible, $clique, 3, "209-$suite209-k");
        $s = $pdo->query("SELECT id, detections FROM mod_signaux WHERE account_id = $cible AND cause = 'chute_sans_quorum'")->fetch();
        $classe = $s === false ? ['ok' => false] : Moderate::adminClasser($pdo, $cible, idDe($pdo, 'arbitre-banc'), motif(),
            ['signal' => (int) $s['id'], 'detections' => (int) $s['detections']]);
        frapper($pdo, $cible, $suivants, 1, "209-$suite209-s");

        return [$classe['ok'], $file(), bilan($pdo, $cible)];
    });
}
[$okA209, $fileA209, $bilanA209] = $classees209['ami'];
$okA209 && !$fileA209 && $bilanA209['rep'] === 0 && $bilanA209['vote'] && !$bilanA209['banni']
    ? ok('⭐ une chute classée au plancher reste classée quand un vote n\'y change rien')
    : nok('⭐ une chute classée au plancher revient sans s\'être aggravée : ' . json_encode($classees209['ami'], JSON_UNESCAPED_UNICODE));
[$okB209, $fileB209, $bilanB209] = $classees209['critiques'];
$okB209 && $fileB209 && $bilanB209['rep'] === 0 && !$bilanB209['vote'] && !$bilanB209['banni']
    && in_array('chute_sans_quorum', $bilanB209['causes'], true)
    ? ok('⭐ une chute classée au plancher revient à l\'arbitre quand elle retire le droit de vote')
    : nok('⭐ une chute classée au plancher retire le vote sans revenir à l\'arbitre : ' . json_encode($classees209['critiques'], JSON_UNESCAPED_UNICODE));

// ── 211. ⭐ Un flot de messages ne coûte rien à la lecture des liens ──────────────
// Deux comptes de la cible votent contre elle et s'écrivent vingt mille messages ; sept
// critiques sans lien votent ensuite. Sous une borne de soixante recherches, les liens se
// lisent en entier — une recherche par sens, quel que soit le volume — : la paire fait
// une voix, les critiques sept, et la cible est bannie.
$cible211 = membre($pdo, 'cible-211');
poserReputation($pdo, $cible211, 20);
$comptes211 = membres($pdo, 'compte-211', 2);
$critiques211 = membres($pdo, 'critique-211', 7);
$flot211 = $pdo->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)');
$pdo->beginTransaction();
for ($k = 0; $k < 10000; $k++) {
    $flot211->execute([$comptes211[0], $comptes211[1], 'chiffre', time() - 3600 - $k]);
    $flot211->execute([$comptes211[1], $comptes211[0], 'chiffre', time() - 3600 - $k]);
}
$pdo->commit();
$c211 = sousQuorum(static function () use ($pdo, $cible211, $comptes211, $critiques211): array {
    frapper($pdo, $cible211, $comptes211, 1, '211-comptes');
    frapper($pdo, $cible211, $critiques211, 3, '211');

    return [bilan($pdo, $cible211)['banni'], Moderate::compositionChute($pdo, $cible211, time())];
}, quorumRecherchesMax: 60);
$c211[0] && $c211[1]['paires_liees'] === 1 && !$c211[1]['indecis'] && $c211[1]['voix_sans_lien'] === 7
    ? ok('⭐ vingt mille messages entre deux comptes ne coûtent rien à la lecture des liens : soixante recherches les lisent en entier, et la cible est bannie')
    : nok('⭐ le volume des messages pèse sur la lecture des liens : ' . json_encode($c211, JSON_UNESCAPED_UNICODE));

// ── 212. ⭐ Mille expéditeurs qui ne votent pas ne coûtent pas mille recherches ───
// Sept critiques sans lien reçoivent chacun un message de mille comptes qui ne votent
// pas, créés entre eux. La lecture saute d'un votant au suivant dans l'index : sous une
// borne de cent recherches, elle lit tout, et la cible est bannie.
$cible212 = membre($pdo, 'cible-212');
poserReputation($pdo, $cible212, 20);
$critiques212 = [];
$foule212 = [];
for ($k = 0; $k < 7; $k++) {
    $critiques212[] = membre($pdo, "critique-212-$k");
    foreach (range(1, 143) as $j) {
        $foule212[] = membre($pdo, "foule-212-$k-$j");
    }
}
$pdo->beginTransaction();
foreach ($critiques212 as $critique) {
    foreach ($foule212 as $x) {
        $flot211->execute([$x, $critique, 'chiffre', time() - 3600]);
    }
}
$pdo->commit();
$c212 = sousQuorum(static function () use ($pdo, $cible212, $critiques212): array {
    frapper($pdo, $cible212, $critiques212, 3, '212');

    return [bilan($pdo, $cible212)['banni'], Moderate::compositionChute($pdo, $cible212, time())];
}, quorumRecherchesMax: 100);
$c212[0] && $c212[1]['paires_liees'] === 0 && !$c212[1]['indecis'] && count($foule212) === 1001
    ? ok('⭐ mille expéditeurs qui ne votent pas, intercalés entre les critiques, ne coûtent pas plus d\'un saut par votant : cent recherches lisent tout')
    : nok('⭐ la lecture des liens paie chaque expéditeur d\'un votant : ' . json_encode([$c212, count($foule212)], JSON_UNESCAPED_UNICODE));

// ── 213. ⭐ Une `dm` sans l'index de la paire se nomme ───────────────────────────
// Deux bases où `dm` n'a pas l'index de la paire : l'une n'a que celui de la boîte reçue,
// l'autre un index sur le destinataire et l'expéditeur, sans la date. Sur une connexion
// neuve, la chute qui passe un seuil lève une erreur qui nomme l'index à créer — sans
// lui, la lecture relirait une boîte, ou les messages d'une paire, sous le verrou
// d'écriture.
$erreurs213 = [];
foreach (['boite' => '', 'deux_colonnes' => 'CREATE INDEX idx_dm_deux ON dm(recipient_id, sender_id);'] as $forme213 => $index213) {
    $f213 = dirname((string) getenv('LAB_DB_PATH')) . "/sans-index-213-$forme213.db";
    $a213 = new PDO('sqlite:' . $f213);
    $a213->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $a213->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $a213->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));
    $a213->exec('DROP INDEX idx_dm_paire;' . $index213);
    $cible213 = membre($a213, 'cible-213');
    poserReputation($a213, $cible213, 6);
    $deux213 = membres($a213, 'critique-213', 2);
    $a213 = null;
    $erreurs213[$forme213] = sousQuorum(static function () use ($f213, $cible213, $deux213): string {
        $b = new PDO('sqlite:' . $f213);
        $b->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $b->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        try {
            frapper($b, $cible213, $deux213, 1, '213');
        } catch (LogicException $e) {
            return $e->getMessage();
        }

        return 'aucune erreur';
    });
}
str_contains($erreurs213['boite'], 'idx_dm_paire') && str_contains($erreurs213['deux_colonnes'], 'idx_dm_paire')
    ? ok('⭐ une dm sans l\'index de la paire — ou avec un index qui n\'a pas la date — lève une erreur qui le nomme, à la première lecture des liens')
    : nok('⭐ une dm sans l\'index de la paire se lit quand même : ' . json_encode($erreurs213, JSON_UNESCAPED_UNICODE));

// ── 214. ⭐ Une part remplie pendant un ban ne fait pas taire les comptes récents après ─
// Sept critiques bannissent la cible ; pendant le ban, trois comptes récents de la cible
// votent contre elle, sans effet au plancher. Le ban échoit, six critiques neufs la mènent
// à 2, et trois comptes récents honnêtes votent : hors du plancher, les votes sans effet
// n'occupent pas la part, la classe des récents fait sa voix, et la cible est bannie une
// seconde fois.
$t214 = time();
Moderate::setHorloge(static function () use (&$t214): int { return $t214; });
$recent214 = static function (string $nom) use ($pdo, $t214): int {
    $pdo->prepare('INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$nom, 'x', 'x', 'x', $t214 - 600]);
    return (int) $pdo->lastInsertId();
};
$cible214 = membre($pdo, 'cible-214');
poserReputation($pdo, $cible214, 20);
$sept214 = membres($pdo, 'critique-214', 7);
$six214 = membres($pdo, 'neuf-214', 6);
$siens214 = array_map(static fn (int $k): int => $recent214("sien-214-$k"), range(1, 3));
$honnetes214 = array_map(static fn (int $k): int => $recent214("honnete-214-$k"), range(1, 3));
$bans214 = sousQuorum(static function (JournalDeBanc $journal) use ($pdo, $cible214, $sept214, $six214, $siens214, $honnetes214, &$t214): array {
    frapper($pdo, $cible214, $sept214, 3, '214-sept');
    frapper($pdo, $cible214, $siens214, 1, '214-siens');
    $t214 += Moderate::config()->dureesBan[0] + Moderate::config()->arrondiFinBanSecondes + 1;
    frapper($pdo, $cible214, $six214, 3, '214-six');
    frapper($pdo, $cible214, $honnetes214, 1, '214-honnetes');

    return [count($journal->de($cible214, 'ban_debut')), bilan($pdo, $cible214)];
});
Moderate::setHorloge(null);
$bans214[0] === 2 && $bans214[1]['banni']
    ? ok('⭐ des votes sans effet émis pendant un ban n\'occupent pas la part après lui : les comptes récents honnêtes font leur voix, et la cible est bannie une seconde fois')
    : nok('⭐ une part remplie pendant un ban fait taire les comptes récents : ' . json_encode($bans214, JSON_UNESCAPED_UNICODE));

// ── 215. ⭐ Au repli, un message à sens unique ne lie personne ──────────────────────
// Dix comptes de la cible, liés deux à deux, font dépasser la borne de lecture ; l'un
// d'eux écrit une fois à chacun des sept critiques, qui ne répondent pas. Le repli lit,
// pour chaque critique, le sens inverse : sans réponse, aucun lien — et un sens sans
// message n'est pas une date illisible —, et les sept critiques bannissent.
$cible215 = membre($pdo, 'cible-215');
poserReputation($pdo, $cible215, 20);
$comptes215 = membres($pdo, 'compte-215', 10);
lierTous($pdo, $comptes215);
$critiques215 = membres($pdo, 'critique-215', 7);
foreach ($critiques215 as $critique) {
    messagePrive($pdo, $comptes215[0], $critique);
}
$journal215 = dirname((string) getenv('LAB_DB_PATH')) . '/erreurs-215.log';
$journalAvant215 = ini_set('error_log', $journal215);
$c215 = sousQuorum(static function () use ($pdo, $cible215, $comptes215, $critiques215): array {
    frapper($pdo, $cible215, $comptes215, 1, '215-comptes');
    frapper($pdo, $cible215, $critiques215, 3, '215');

    return [bilan($pdo, $cible215)['banni'], Moderate::compositionChute($pdo, $cible215, time())];
}, quorumRecherchesMax: 200);
ini_set('error_log', (string) $journalAvant215);
$illisibles215 = str_contains((string) @file_get_contents($journal215), 'date illisible');
$c215[0] && $c215[1]['paires_liees'] === null && $c215[1]['voix_sans_lien'] === 7 && !$illisibles215
    ? ok('⭐ au repli, un message à sens unique d\'un compte de la cible ne lie aucun critique : les sept bannissent, et le sens sans réponse n\'est pas une date illisible')
    : nok('⭐ au repli, un message à sens unique lie, ou se dit illisible : ' . json_encode([$c215, $illisibles215], JSON_UNESCAPED_UNICODE));

// ── 216. ⭐ Des identifiants de `dm` en texte se nomment ─────────────────────────
// Un index range une colonne de texte dans l'ordre du texte (« 10 » avant « 9 ») ; la
// lecture des liens marche dans l'ordre des nombres, et sauterait des votants : une
// meute ferait des voix. Sur une connexion neuve, une `dm` aux identifiants TEXT, puis
// sans type, lève au premier vote une erreur qui nomme la colonne.
$cible216 = membre($pdo, 'cible-216');
poserReputation($pdo, $cible216, 20);
$deux216 = membres($pdo, 'critique-216', 2);
$formes216 = sousQuorum(static function () use ($cible216, $deux216): array {
    $vus = [];
    foreach (['texte' => 'TEXT', 'sans_type' => ''] as $forme => $type) {
        $c = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
        $c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $c->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $c->exec("CREATE TEMP TABLE dm (sender_id $type, recipient_id $type, created_at INTEGER)");
        $c->exec('CREATE INDEX temp.dm_216_paire ON dm(recipient_id, sender_id, created_at)');
        try {
            frapper($c, $cible216, $deux216, 1, "216-$forme");
            $vus[$forme] = 'aucune erreur';
        } catch (Throwable $e) {
            $vus[$forme] = $e->getMessage();
        }
    }

    return $vus;
});
str_contains($formes216['texte'], 'dm.sender_id') && str_contains($formes216['texte'], 'TEXT')
    && str_contains($formes216['sans_type'], 'dm.sender_id') && str_contains($formes216['sans_type'], 'sans type')
    ? ok('⭐ une dm aux identifiants en texte, ou sans type, lève au premier vote une erreur qui nomme la colonne')
    : nok('⭐ une dm aux identifiants en texte se lit dans le mauvais ordre : ' . json_encode($formes216, JSON_UNESCAPED_UNICODE));

// ── 217. ⭐ Un expéditeur qui n'est pas un nombre clôt la boîte ──────────────────
// Une base dont `dm` n'a pas de clé étrangère peut porter un expéditeur écrit en
// lettres : il se range après tous les nombres, aucun votant n'est derrière lui. Le
// lien entre deux critiques se lit en quelques requêtes — le compteur lève au-delà de
// cinq cents, pour qu'une lecture qui repartirait du premier votant rougisse au lieu
// de pendre —, et huit critiques font sept voix exactes.
$f217 = dirname((string) getenv('LAB_DB_PATH')) . '/systeme-217.db';
@unlink($f217);
$a217 = new PDO('sqlite:' . $f217);
$a217->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$a217->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$a217->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));
$cible217 = membre($a217, 'cible-217');
poserReputation($a217, $cible217, 20);
$critiques217 = membres($a217, 'critique-217', 8);
echangePrive($a217, $critiques217[0], $critiques217[1]);
$a217->exec('PRAGMA foreign_keys = OFF');
$a217->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)')
    ->execute(['systeme', $critiques217[1], 'bienvenue', time() - 86400]);
$a217 = null;
$c217 = sousQuorum(static function () use ($f217, $cible217, $critiques217): array {
    $c = new PdoCompteur('sqlite:' . $f217);
    $c->plafond = 500;
    try {
        $lies = Moderate::areLinked($c, $critiques217[0], $critiques217[1]);
    } catch (Throwable $t) {
        $lies = get_class($t) . ' : ' . $t->getMessage();
    }
    $requetes = $c->requetes;
    $c->plafond = null;
    frapper($c, $cible217, $critiques217, 3, '217');

    return [$lies, $requetes, bilan($c, $cible217)['banni'], Moderate::compositionChute($c, $cible217, time())];
});
$c217[0] === true && $c217[1] < 50 && $c217[2] && $c217[3]['paires_liees'] === 1 && $c217[3]['voix_sans_lien'] === 7 && !$c217[3]['indecis']
    ? ok('⭐ un expéditeur écrit en lettres clôt la boîte : areLinked() rend en quelques requêtes, et huit critiques font sept voix exactes')
    : nok('⭐ un expéditeur qui n\'est pas un nombre fait tourner la lecture : ' . json_encode($c217, JSON_UNESCAPED_UNICODE));

// ── 218. ⭐ Une forme de `dm` qui relit les messages lève, une forme qui tient passe ─
// Chaque recherche tient en une ligne de plan, dans un index qui suffit seul. Une vue
// UNION ALL, même à deux branches indexées, relit chaque boîte pour son MIN ; une vue
// filtrée sans index partiel relit les lignes pour le filtre : toutes deux lèvent en
// nommant l'index. Une vue filtrée sous un index partiel du même filtre, et une table
// WITHOUT ROWID dont la clé commence par les trois colonnes, lisent la paire liée — un
// échange supprimé n'en fait pas une —, et huit critiques bannissent.
$critiques218 = membres($pdo, 'critique-218', 8);
$cibles218 = [];
foreach (['union', 'filtree', 'partiel', 'sans_rowid'] as $forme218) {
    $cibles218[$forme218] = membre($pdo, "cible-218-$forme218");
    poserReputation($pdo, $cibles218[$forme218], 20);
}
$monter218 = static function (string $forme) use ($critiques218): PDO {
    $c = new PDO('sqlite:' . getenv('LAB_DB_PATH'));
    $c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $c->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $quand = time() - 3600;
    [$x, $y, $u, $v] = $critiques218;
    if ($forme === 'union') {
        foreach (['dm_218a', 'dm_218b'] as $t) {
            $c->exec("CREATE TEMP TABLE $t (sender_id INTEGER, recipient_id INTEGER, created_at INTEGER)");
            $c->exec("CREATE INDEX temp.{$t}_paire ON $t(recipient_id, sender_id, created_at)");
        }
        $c->exec("INSERT INTO dm_218a VALUES ($x, $y, $quand)");
        $c->exec("INSERT INTO dm_218b VALUES ($y, $x, $quand)");
        $c->exec('CREATE TEMP VIEW dm AS SELECT sender_id, recipient_id, created_at FROM dm_218a
                  UNION ALL SELECT sender_id, recipient_id, created_at FROM dm_218b');
    } elseif ($forme === 'sans_rowid') {
        $c->exec('CREATE TEMP TABLE dm (sender_id INTEGER, recipient_id INTEGER, created_at INTEGER, id INTEGER,
                  PRIMARY KEY (recipient_id, sender_id, created_at, id)) WITHOUT ROWID');
        $c->exec("INSERT INTO dm VALUES ($x, $y, $quand, 1), ($y, $x, $quand, 2)");
    } else {
        $c->exec('CREATE TEMP TABLE dm_218f (sender_id INTEGER, recipient_id INTEGER, created_at INTEGER, supprime INTEGER NOT NULL)');
        $c->exec('CREATE INDEX temp.dm_218f_paire ON dm_218f(recipient_id, sender_id, created_at)' . ($forme === 'partiel' ? ' WHERE supprime = 0' : ''));
        $c->exec("INSERT INTO dm_218f VALUES ($x, $y, $quand, 0), ($y, $x, $quand, 0), ($u, $v, $quand, 1), ($v, $u, $quand, 1)");
        $c->exec('CREATE TEMP VIEW dm AS SELECT sender_id, recipient_id, created_at FROM dm_218f WHERE supprime = 0');
    }

    return $c;
};
$formes218 = sousQuorum(static function () use ($monter218, $cibles218, $critiques218): array {
    $vus = [];
    foreach ($cibles218 as $forme => $cible) {
        $c = $monter218($forme);
        try {
            frapper($c, $cible, $critiques218, 3, "218-$forme");
            $compo = Moderate::compositionChute($c, $cible, time());
            $vus[$forme] = ['banni' => bilan($c, $cible)['banni'], 'paires' => $compo['paires_liees'], 'voix' => $compo['voix_sans_lien']];
        } catch (Throwable $e) {
            $vus[$forme] = $e->getMessage();
        }
    }

    return $vus;
});
$leve218 = static fn (mixed $vu): bool => is_string($vu) && str_contains($vu, 'idx_dm_paire');
$lit218 = static fn (mixed $vu): bool => $vu === ['banni' => true, 'paires' => 1, 'voix' => 7];
$leve218($formes218['union']) && $leve218($formes218['filtree']) && $lit218($formes218['partiel']) && $lit218($formes218['sans_rowid'])
    ? ok('⭐ une vue UNION ALL et une vue filtrée sans index partiel lèvent en nommant l\'index ; l\'index partiel et la table WITHOUT ROWID lisent la paire liée, et huit critiques bannissent')
    : nok('⭐ la forme de dm n\'est pas jugée sur ce qu\'elle relit : ' . json_encode($formes218, JSON_UNESCAPED_UNICODE));

// ── 219. ⭐ Une part remplie pendant un ban ne fait pas taire les récents au plancher ─
// Sept critiques bannissent la cible ; pendant le ban, trois comptes récents de la cible
// votent contre elle, sans effet. La remise la rend à 20, six critiques neufs la mènent
// à 2, un septième, lié au premier, à 0 : sept votants, six voix. Trois comptes récents
// honnêtes votent alors : les votes d'avant la chute soldée n'occupent pas la part,
// même la cible revenue au plancher, et la classe des récents fait la septième voix.
$t219 = time();
Moderate::setHorloge(static function () use (&$t219): int { return $t219; });
$recent219 = static function (string $nom) use ($pdo, &$t219): int {
    $pdo->prepare('INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$nom, 'x', 'x', 'x', $t219 - 600]);
    return (int) $pdo->lastInsertId();
};
$cible219 = membre($pdo, 'cible-219');
poserReputation($pdo, $cible219, 20);
$sept219 = membres($pdo, 'critique-219', 7);
$six219 = membres($pdo, 'neuf-219', 6);
$lie219 = membre($pdo, 'lie-219');
echangePrive($pdo, $lie219, $six219[0]);
$bans219 = sousQuorum(static function (JournalDeBanc $journal) use ($pdo, $cible219, $sept219, $six219, $lie219, $recent219, &$t219): array {
    frapper($pdo, $cible219, $sept219, 3, '219-sept');
    $t219 += 60;
    frapper($pdo, $cible219, array_map(static fn (int $k): int => $recent219("sien-219-$k"), range(1, 3)), 1, '219-siens');
    $t219 += Moderate::config()->dureesBan[0] + Moderate::config()->arrondiFinBanSecondes + 61;
    frapper($pdo, $cible219, $six219, 3, '219-six');
    frapper($pdo, $cible219, [$lie219], 2, '219-lie');
    $auPlancher = bilan($pdo, $cible219);
    $t219 += 60;
    frapper($pdo, $cible219, array_map(static fn (int $k): int => $recent219("honnete-219-$k"), range(1, 3)), 1, '219-honnetes');

    return [count($journal->de($cible219, 'ban_debut')), $auPlancher, bilan($pdo, $cible219)];
});
Moderate::setHorloge(null);
$bans219[0] === 2 && $bans219[1]['rep'] === 0 && !$bans219[1]['banni'] && $bans219[2]['banni']
    ? ok('⭐ des votes sans effet émis pendant un ban n\'occupent pas la part quand la cible revient au plancher : les récents honnêtes font la septième voix')
    : nok('⭐ une part remplie pendant un ban fait taire les récents au plancher : ' . json_encode($bans219, JSON_UNESCAPED_UNICODE));

// ── 220. ⭐ Au repli, une paire dont un sens est vieux ne lie pas ─────────────────
// Dix comptes de la cible, liés deux à deux, font dépasser la borne de lecture. Huit
// critiques : dans deux paires, un sens est récent et l'autre a quatre mois, avant le
// début de la paire. Le repli lit chacun des deux sens à sa date : aucune paire ne lie,
// et les critiques bannissent.
$cible220 = membre($pdo, 'cible-220');
poserReputation($pdo, $cible220, 20);
$comptes220 = membres($pdo, 'compte-220', 10);
lierTous($pdo, $comptes220);
$critiques220 = membres($pdo, 'critique-220', 8);
$dm220 = $pdo->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)');
foreach ([[0, 1], [3, 2]] as [$recent, $vieux]) {
    $dm220->execute([$critiques220[$recent], $critiques220[$vieux], 'chiffre', time() - 3600]);
    $dm220->execute([$critiques220[$vieux], $critiques220[$recent], 'chiffre', time() - 120 * 86400]);
}
$c220 = sousQuorum(static function () use ($pdo, $cible220, $comptes220, $critiques220): array {
    frapper($pdo, $cible220, $comptes220, 1, '220-comptes');
    frapper($pdo, $cible220, $critiques220, 3, '220');

    return [bilan($pdo, $cible220)['banni'], Moderate::compositionChute($pdo, $cible220, time())];
}, quorumRecherchesMax: 200);
$c220[0] && $c220[1]['paires_liees'] === null && $c220[1]['voix_sans_lien'] === 7
    ? ok('⭐ au repli, un sens vieux de quatre mois ne lie pas sa paire, qu\'il soit reçu ou rendu : les critiques bannissent')
    : nok('⭐ au repli, un sens vieux lie sa paire : ' . json_encode($c220, JSON_UNESCAPED_UNICODE));

// ── 221. ⭐ Un compte lu à moitié ne fait pas de voix ─────────────────────────────
// Dix comptes de la cible liés deux à deux, six critiques liés par paires, dont la
// dernière finit sur le plus grand identifiant : aucun votant n'est seul. Pour chaque
// borne de 1 à 300, la lecture exacte compte quatre voix (la clique et les trois
// paires), et le repli n'en compte aucune — un compte dont la lecture s'épuise en plein
// sens ne passe pas pour seul.
$cible221 = membre($pdo, 'cible-221');
poserReputation($pdo, $cible221, 20);
$comptes221 = membres($pdo, 'compte-221', 10);
lierTous($pdo, $comptes221);
$critiques221 = membres($pdo, 'critique-221', 6);
foreach ([0, 2, 4] as $k) {
    echangePrive($pdo, $critiques221[$k], $critiques221[$k + 1]);
}
frapper($pdo, $cible221, [...$comptes221, ...$critiques221], 1, '221');
$lus221 = ['repli' => 0, 'exacte' => 0];
$fausses221 = [];
for ($borne = 1; $borne <= 300; $borne++) {
    $compo221 = sousQuorum(static fn (): array => Moderate::compositionChute($pdo, $cible221, time()), quorumRecherchesMax: $borne);
    $repli221 = $compo221['paires_liees'] === null;
    $lus221[$repli221 ? 'repli' : 'exacte']++;
    if ($compo221['voix_sans_lien'] !== ($repli221 ? 0 : 4)) {
        $fausses221[$borne] = $compo221['voix_sans_lien'];
    }
}
$fausses221 === [] && $lus221['repli'] > 100 && $lus221['exacte'] > 10
    ? ok('⭐ de 1 à 300 recherches, aucun compte lu à moitié ne fait de voix : le repli n\'en lit aucune (' . $lus221['repli'] . ' bornes), la lecture exacte quatre (' . $lus221['exacte'] . ')')
    : nok('⭐ un compte lu à moitié fait une voix, aux bornes : ' . json_encode([array_slice($fausses221, 0, 5, true), $lus221]));

// ── 222. ⭐ La lecture paie les expéditeurs distincts, et le repli sa borne ───────
// Soixante critiques ont reçu un message d'un même compte qui ne vote pas, créé après
// eux : chaque boîte se lit en un saut, et cinq cents recherches lisent tout. Puis
// trente comptes de la cible qui se sont écrit il y a quatre mois, chacun lié au
// dernier par un échange récent : la lecture exacte et le repli s'épuisent chacun à
// deux cents recherches, et le compteur le voit.
$cible222 = membre($pdo, 'cible-222');
poserReputation($pdo, $cible222, 30);
$critiques222 = membres($pdo, 'critique-222', 60);
$tard222 = membre($pdo, 'tard-222');
foreach ($critiques222 as $critique) {
    messagePrive($pdo, $tard222, $critique);
}
frapper($pdo, $cible222, $critiques222, 1, '222');
$sauts222 = sousQuorum(static fn (): array => Moderate::compositionChute($pdo, $cible222, time()), quorumRecherchesMax: 500);
$cible222b = membre($pdo, 'cible-222b');
poserReputation($pdo, $cible222b, 30);
$tournoi222 = membres($pdo, 'tournoi-222', 30);
$dm222 = $pdo->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)');
foreach ($tournoi222 as $i => $x) {
    foreach (array_slice($tournoi222, $i + 1) as $y) {
        $dm222->execute([$x, $y, 'chiffre', time() - 120 * 86400]);
        $dm222->execute([$y, $x, 'chiffre', time() - 120 * 86400]);
    }
}
foreach (array_slice($tournoi222, 0, -1) as $x) {
    echangePrive($pdo, $x, $tournoi222[29]);
}
frapper($pdo, $cible222b, $tournoi222, 1, '222b');
$repli222 = sousQuorum(static function () use ($compteur, $cible222b): array {
    $compteur->requetes = 0;
    $compo = Moderate::compositionChute($compteur, $cible222b, time());

    return [$compo['paires_liees'], $compteur->requetes];
}, quorumRecherchesMax: 200);
$sauts222['paires_liees'] === 0 && $repli222[0] === null && $repli222[1] <= 2 * 200 + 40
    ? ok('⭐ soixante boîtes d\'un expéditeur qui ne vote pas se lisent en un saut chacune, et le repli s\'arrête à sa borne (' . $repli222[1] . ' requêtes)')
    : nok('⭐ la lecture paie chaque votant, ou le repli passe sa borne : ' . json_encode([$sauts222['paires_liees'], $repli222], JSON_UNESCAPED_UNICODE));

// ── 223. ⭐ Une paire illisible ne lève pas quand d'autres sens se lisent ─────────
// Huit critiques : deux s'écrivent en millisecondes, deux en secondes, quatre sont
// seuls. La paire en millisecondes est illisible dans ses deux sens, mais la paire en
// secondes se lit : la première ne lie personne, le journal d'erreurs le dit, et la
// cible est bannie.
$cible223 = membre($pdo, 'cible-223');
poserReputation($pdo, $cible223, 20);
$critiques223 = membres($pdo, 'critique-223', 8);
$dm223 = $pdo->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)');
$dm223->execute([$critiques223[0], $critiques223[1], 'chiffre', (time() - 3600) * 1000]);
$dm223->execute([$critiques223[1], $critiques223[0], 'chiffre', (time() - 3600) * 1000]);
echangePrive($pdo, $critiques223[2], $critiques223[3]);
$journal223 = dirname((string) getenv('LAB_DB_PATH')) . '/erreurs-223.log';
$journalAvant223 = ini_set('error_log', $journal223);
$c223 = sousQuorum(static function () use ($pdo, $cible223, $critiques223): array {
    try {
        frapper($pdo, $cible223, $critiques223, 3, '223');
    } catch (Throwable $t) {
        return [get_class($t) . ' : ' . $t->getMessage()];
    }

    return [bilan($pdo, $cible223)['banni'], Moderate::compositionChute($pdo, $cible223, time())];
});
ini_set('error_log', (string) $journalAvant223);
$illisibles223 = str_contains((string) @file_get_contents($journal223), 'date illisible');
$c223[0] === true && $c223[1]['paires_liees'] === 1 && $c223[1]['voix_sans_lien'] === 7 && $illisibles223
    ? ok('⭐ une paire en millisecondes ne lève pas quand d\'autres sens se lisent : elle ne lie personne, le journal le dit, et la cible est bannie')
    : nok('⭐ une paire illisible gèle la chute, ou se tait : ' . json_encode([$c223, $illisibles223], JSON_UNESCAPED_UNICODE));

// ── 224. ⭐ Une `dm` sans l'index de la paire se nomme au premier vote ────────────
// Une base où `dm` ne porte que l'index de la boîte reçue, une cible à 20 et un seul
// vote, loin de tout seuil : il lève une erreur qui nomme l'index, et l'hôte l'apprend
// avant la première chute.
$f224 = dirname((string) getenv('LAB_DB_PATH')) . '/sans-index-224.db';
@unlink($f224);
$a224 = new PDO('sqlite:' . $f224);
$a224->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$a224->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$a224->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));
$a224->exec('DROP INDEX idx_dm_paire');
$cible224 = membre($a224, 'cible-224');
poserReputation($a224, $cible224, 20);
$un224 = membres($a224, 'critique-224', 1);
$a224 = null;
$erreur224 = sousQuorum(static function () use ($f224, $cible224, $un224): string {
    $b = new PDO('sqlite:' . $f224);
    $b->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $b->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    try {
        frapper($b, $cible224, $un224, 1, '224');
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return 'aucune erreur';
});
str_contains($erreur224, 'idx_dm_paire')
    ? ok('⭐ une dm sans l\'index de la paire lève dès le premier vote, loin de tout seuil, une erreur qui le nomme')
    : nok('⭐ une dm sans l\'index de la paire attend la première chute pour se nommer : ' . $erreur224);

// ── 225. ⭐ Des votes sans effet au plafond quittent la part quand la cible en descend ─
// Au plafond, trois comptes récents votent pour la cible, sans effet : la part des
// votes positifs est pleine, et elle borne les scores. Cinq critiques établis la
// descendent à 25. Aucune chute n'a été soldée ; hors du plafond, les trois votes sans
// effet n'occupent plus la part, et un compte récent honnête qui vote pour elle compte.
$recent225 = static function (string $nom) use ($pdo): int {
    $pdo->prepare('INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$nom, 'x', 'x', 'x', time() - 600]);
    return (int) $pdo->lastInsertId();
};
$cible225 = membre($pdo, 'cible-225');
poserReputation($pdo, $cible225, Moderate::config()->reputationMax);
frapper($pdo, $cible225, array_map(static fn (int $k): int => $recent225("au-plafond-225-$k"), range(1, Moderate::config()->farmingUpvotesMax)), 1, '225-plafond', 1);
frapper($pdo, $cible225, membres($pdo, 'critique-225', 5), 1, '225-critiques');
$avant225 = bilan($pdo, $cible225)['rep'];
frapper($pdo, $cible225, [$recent225('honnete-225')], 1, '225-honnete', 1);
$apres225 = bilan($pdo, $cible225)['rep'];
$avant225 === Moderate::config()->reputationMax - 5 && $apres225 === $avant225 + 1
    ? ok('⭐ des votes sans effet au plafond n\'occupent plus la part quand la cible en est descendue : un compte récent honnête qui vote pour elle compte')
    : nok('⭐ des votes sans effet au plafond occupent la part hors du plafond : ' . json_encode([$avant225, $apres225]));

// ── 226. ⭐ L'hôte déclare sa messagerie et son usage, et le moteur ne tourne pas sans sa config ─
// Ni l'une ni l'autre n'a de valeur par défaut : une config qui ne les nomme pas ne se
// construit pas. Sans `setConfig()`, le moteur refuse en lecture comme en écriture, en
// nommant le geste, et n'écrit rien : il ne prend pas une démonstration à la place.
$declaration226 = [];
foreach ([
    'rien'             => static fn (): Config => new Config(),
    'messagerie seule' => static fn (): Config => new Config(Messagerie::DM),
    'usage seul'       => static fn (): Config => new Config(usage: Usage::SERVICE),
] as $forme226 => $faire226) {
    try {
        $faire226();
        $declaration226[$forme226] = 'construite';
    } catch (ArgumentCountError) {
        $declaration226[$forme226] = 'refusée';
    } catch (Throwable $e226) {
        $declaration226[$forme226] = get_class($e226) . ' : ' . $e226->getMessage();
    }
}
$cible226 = membre($pdo, 'cible-226');
$votant226 = membre($pdo, 'votant-226');
$votes226 = (int) $pdo->query('SELECT COUNT(*) FROM mod_votes')->fetchColumn();
$sansConfig226 = [];
Moderate::setConfig(null);
try {
    foreach ([
        'config'  => static fn (): mixed => Moderate::config(),
        'lecture' => static fn (): mixed => Moderate::getReputation($pdo, $cible226),
        'vote'    => static fn (): mixed => Moderate::applyVote($pdo, $votant226, 'member', $cible226, -1, motif(), 'agressif'),
    ] as $geste226 => $faire226) {
        try {
            $faire226();
            $sansConfig226[$geste226] = 'passé';
        } catch (LogicException $e226) {
            $sansConfig226[$geste226] = str_contains($e226->getMessage(), 'setConfig()') ? 'refusé' : $e226->getMessage();
        } catch (Throwable $e226) {
            $sansConfig226[$geste226] = get_class($e226) . ' : ' . $e226->getMessage();
        }
    }
} finally {
    Moderate::setConfig(configBanc());
}
$ecrits226 = (int) $pdo->query('SELECT COUNT(*) FROM mod_votes')->fetchColumn() - $votes226;
$declaration226 === ['rien' => 'refusée', 'messagerie seule' => 'refusée', 'usage seul' => 'refusée']
    && $sansConfig226 === ['config' => 'refusé', 'lecture' => 'refusé', 'vote' => 'refusé'] && $ecrits226 === 0
    && Moderate::config()->usage === Usage::DEMONSTRATION
    ? ok('⭐ une config sans messagerie ni usage ne se construit pas ; sans setConfig(), le moteur refuse de lire et de voter en nommant le geste, sans rien écrire')
    : nok('⭐ le moteur tourne sans déclaration, ou sans sa config : ' . json_encode(['declaration' => $declaration226, 'sans_config' => $sansConfig226, 'ecrits' => $ecrits226], JSON_UNESCAPED_UNICODE));

// ── 227. ⭐ En service, aucun réglage de sécurité ne descend sous `Config::prod()` ─
// Un plancher plus court d'une unité, un plafond plus large d'une unité, le quorum
// éteint : chacun se refuse, en nommant le réglage. Durcir les dix d'un coup passe ;
// la démonstration garde ses valeurs courtes ; la messagerie hors du moteur a les
// mêmes planchers.
$prod227 = Config::prod(Messagerie::DM);
$planchers227 = ['ageMinPourVoterSecondes', 'ageEtabliSecondes', 'fenetreSalveSecondes', 'arrondiFinBanSecondes', 'fenetreFarmingJours'];
$plafonds227 = ['ciblesParVotantMax', 'farmingDownvotesMax', 'farmingUpvotesMax', 'salveVotantsMin', 'messagesPourVoterAvantAge'];
$essai227 = static function (callable $faire, string $nomme): string {
    try {
        $faire();

        return 'accepté';
    } catch (InvalidArgumentException $e) {
        return str_contains($e->getMessage(), $nomme) ? 'refusé' : 'refusé sans le nommer : ' . $e->getMessage();
    }
};
$affaiblis227 = [];
$durci227 = [];
foreach ($planchers227 as $champ227) {
    $affaiblis227[$champ227] = $essai227(static fn (): Config => configService(...[$champ227 => $prod227->$champ227 - 1]), $champ227);
    $durci227[$champ227] = $prod227->$champ227 + 1;
}
foreach ($plafonds227 as $champ227) {
    $affaiblis227[$champ227] = $essai227(static fn (): Config => configService(...[$champ227 => $prod227->$champ227 + 1]), $champ227);
    $durci227[$champ227] = $prod227->$champ227 - 1;
}
$affaiblis227['quorumAuxSeuils'] = $essai227(static fn (): Config => configService(quorumAuxSeuils: false), 'quorumAuxSeuils');
$affaiblis227['hors du moteur'] = $essai227(static fn (): Config => new Config(...[
    ...get_object_vars(Config::prod(Messagerie::HORS_MOTEUR)), 'ageMinPourVoterSecondes' => 60,
]), 'ageMinPourVoterSecondes');
$autorises227 = [
    'durci'         => $essai227(static fn (): Config => configService(...$durci227), '-'),
    'demonstration' => $essai227(static fn (): Config => new Config(Messagerie::DM, Usage::DEMONSTRATION,
        quorumAuxSeuils: false, ageMinPourVoterSecondes: 0, ageEtabliSecondes: 0, farmingDownvotesMax: 50, ciblesParVotantMax: 500), '-'),
];
$affaiblis227 === array_fill_keys(array_keys($affaiblis227), 'refusé') && count($affaiblis227) === 12
    && $autorises227 === ['durci' => 'accepté', 'demonstration' => 'accepté']
    ? ok('⭐ en service, chaque plancher et chaque plafond de prod() se refuse affaibli d\'une unité, le quorum éteint aussi, hors du moteur comme avec dm ; durcir passe, et la démonstration garde ses valeurs')
    : nok('⭐ un service accepte un réglage plus faible que prod(), ou refuse de durcir : ' . json_encode(['affaiblis' => $affaiblis227, 'autorises' => $autorises227], JSON_UNESCAPED_UNICODE));

// ── 228. ⭐ En démonstration, un palier de ban dure une heure au plus ───────────
// La démonstration ne sert pas de porte vers de vrais bans : 3600 s passent, 3601 se
// refusent en nommant la borne, au premier palier comme au dernier, hors du moteur
// comme avec `dm`. Le ban d'arbitre, signé d'un humain, n'est pas borné ; le service
// non plus.
$essai228 = static function (callable $faire): string {
    try {
        $faire();

        return 'accepté';
    } catch (InvalidArgumentException $e) {
        return str_contains($e->getMessage(), 'une heure') ? 'refusé' : 'refusé sans la borne : ' . $e->getMessage();
    }
};
$paliers228 = [
    'heure'          => $essai228(static fn (): Config => new Config(Messagerie::DM, Usage::DEMONSTRATION, dureesBan: [120, 600, 3600])),
    'dernier'        => $essai228(static fn (): Config => new Config(Messagerie::DM, Usage::DEMONSTRATION, dureesBan: [120, 600, 3601])),
    'premier'        => $essai228(static fn (): Config => new Config(Messagerie::DM, Usage::DEMONSTRATION, dureesBan: [3601, 3601])),
    'hors du moteur' => $essai228(static fn (): Config => new Config(Messagerie::HORS_MOTEUR, Usage::DEMONSTRATION, dureesBan: [3601])),
    'arbitre'        => $essai228(static fn (): Config => new Config(Messagerie::DM, Usage::DEMONSTRATION, dureeBanAdminSecondes: 30 * 86400)),
    'service'        => $essai228(static fn (): Config => Config::prod(Messagerie::HORS_MOTEUR)),
];
Config::PALIER_DEMONSTRATION_MAX === 3600
    && $paliers228 === ['heure' => 'accepté', 'dernier' => 'refusé', 'premier' => 'refusé', 'hors du moteur' => 'refusé', 'arbitre' => 'accepté', 'service' => 'accepté']
    ? ok('⭐ en démonstration, un palier d\'une heure passe et une heure et une seconde se refuse ; le ban d\'arbitre et le service ne sont pas bornés')
    : nok('⭐ la démonstration accepte un palier de plus d\'une heure, ou borne ce qu\'elle ne borne pas : ' . json_encode($paliers228, JSON_UNESCAPED_UNICODE));

/** Le signal d'une cause sur un compte, vu ou non, tel que l'arbitre le lit ; null s'il n'y en a pas. */
function signalDe(PDO $pdo, int $id, string $cause): ?array
{
    foreach (Moderate::getReputation($pdo, $id)['signaux'] as $s) {
        if ($s['cause'] === $cause) {
            return $s;
        }
    }

    return null;
}

/** Ce que l'arbitre a vu d'un signal : ce que `adminClasser()` attend en `$vu`. */
function vuDe(?array $signal): ?array
{
    return $signal === null ? null : ['signal' => $signal['signal'], 'detections' => $signal['detections']];
}

/**
 * En service, des critiques sans lien votent contre un compte posé à `$depart`,
 * `$tours` fois chacun : six font le quorum du vote, sept celui du ban.
 */
function chuteDeService(PDO $pdo, string $nom, int $depart, int $critiques, int $tours): int
{
    $cible = membre($pdo, $nom);
    poserReputation($pdo, $cible, $depart);
    $votants = membres($pdo, "critique-$nom", $critiques);
    sousConfig(configService(), static fn () => frapper($pdo, $cible, $votants, $tours, $nom));

    return $cible;
}

/** Un compte au plancher, en démonstration, dont le journal a refusé le début du ban : `ban_en_attente`. */
function banEnAttente(PDO $pdo, string $nom): int
{
    $cible = membre($pdo, $nom);
    poserReputation($pdo, $cible, 1);
    $avant = Moderate::journal();
    Moderate::setJournal(new class implements \Pierroons\SelfModerate\Journal {
        public function inscrire(array $acte): void
        {
            if ($acte['acte'] === 'ban_debut') {
                throw new RuntimeException('début de ban refusé');
            }
        }
    });
    try {
        Moderate::applyVote($pdo, membre($pdo, "votant-$nom"), 'member', $cible, -1, motif(), 'agressif');
    } catch (Throwable $e) {
        nok('un refus du journal au début du ban remonte au vote : ' . get_class($e) . ' : ' . $e->getMessage());
    } finally {
        Moderate::setJournal($avant);
    }

    return $cible;
}

// ── 229. ⭐ En service, le ban dû que l'arbitre a classé lui revient à la rechute ─
// Sept critiques mènent la cible au plancher : `reputation_zero`, sans ban. L'arbitre le
// classe. Un vote de plus au plancher ne le rouvre pas : la chute ne s'est pas
// aggravée. Une remontée d'un point, puis une rechute, le rouvre : il a jugé une chute,
// pas la suivante.
try {
    $r229 = sousConfig(configService(), static function () use ($pdo): array {
        $cible = membre($pdo, 'cible-229');
        poserReputation($pdo, $cible, 20);
        frapper($pdo, $cible, membres($pdo, 'critique-229', 7), 3, '229');
        $lu = ['chute' => bilan($pdo, $cible)];
        $vu = signalDe($pdo, $cible, 'reputation_zero');
        $lu['classe'] = Moderate::adminClasser($pdo, $cible, idDe($pdo, 'arbitre-banc'), motif(), vuDe($vu))['ok'];
        $lu['classe_vu'] = signalDe($pdo, $cible, 'reputation_zero')['vu_le'] ?? null;
        Moderate::applyVote($pdo, membre($pdo, 'insistant-229'), 'member', $cible, -1, motif(), 'agressif');
        $lu['au_plancher_vu'] = signalDe($pdo, $cible, 'reputation_zero')['vu_le'] ?? null;
        Moderate::applyVote($pdo, membre($pdo, 'soutien-229'), 'member', $cible, 1);
        $lu['remonte'] = reputation($pdo, $cible);
        Moderate::applyVote($pdo, membre($pdo, 'rechute-229'), 'member', $cible, -1, motif(), 'agressif');
        $rouvert = signalDe($pdo, $cible, 'reputation_zero');
        $lu['rechute'] = bilan($pdo, $cible);
        $lu['rouvert'] = $rouvert === null ? null : ['vu_le' => $rouvert['vu_le'], 'plus_de_detections' => $rouvert['detections'] > $vu['detections']];

        return $lu;
    });
} catch (Throwable $t229) {
    $r229 = ['exception' => get_class($t229) . ' : ' . $t229->getMessage()];
}
($r229['chute']['rep'] ?? null) === 0 && !$r229['chute']['banni'] && $r229['chute']['vote']
    && in_array('reputation_zero', $r229['chute']['causes'], true) && $r229['classe'] === true
    && $r229['classe_vu'] !== null && $r229['au_plancher_vu'] !== null && $r229['remonte'] === 1
    && $r229['rechute']['rep'] === 0 && !$r229['rechute']['banni']
    && $r229['rouvert'] === ['vu_le' => null, 'plus_de_detections' => true]
    ? ok('⭐ en service, le plancher se signale sans ban ; classé, le ban dû ne revient pas pour un vote au plancher, et revient à la rechute')
    : nok('⭐ le ban dû classé ne revient pas à la rechute, ou revient sans elle : ' . json_encode($r229, JSON_UNESCAPED_UNICODE));

// ── 230. ⭐ En service, la perte du vote due ne relance pas le quorum, et tombe à la remontée ─
// Six critiques font le quorum du vote : il reste, `perte_du_vote_due` se pose, et
// `canVote()` le laisse voter. Tant que le signal est posé, un vote de plus ne
// recherche pas le quorum, la décision est rendue ; le témoin, à qui on l'a retiré, le
// recherche. Remontée au seuil par des votes de soutien, sans point de convalescence
// échu, la cible garde le signal en table jusqu'au balayage, qui le fait tomber. Classé,
// il revient quand le compte recroise le seuil.
final class PdoQuorum extends PDO
{
    public int $recherches = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        // La lecture des votants d'une chute ouvre toute recherche du quorum.
        if (str_contains($query, 'HAVING SUM(v.value) < 0')) {
            $this->recherches++;
        }

        return parent::prepare($query, $options);
    }
}
$c230 = new PdoQuorum('sqlite:' . getenv('LAB_DB_PATH'));
$c230->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$c230->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$table230 = static fn (int $id): int => (int) $pdo->query("SELECT COUNT(*) FROM mod_signaux WHERE account_id = $id AND cause = 'perte_du_vote_due'")->fetchColumn();
try {
    $r230 = sousConfig(configService(), static function () use ($pdo, $c230, $table230): array {
        $du = membre($pdo, 'du-230');
        $temoin = membre($pdo, 'temoin-230');
        foreach (['du' => $du, 'temoin' => $temoin] as $role => $cible) {
            poserReputation($pdo, $cible, 10);
            frapper($c230, $cible, membres($pdo, "critique-230-$role", 6), 1, "230-$role");
        }
        $lu = ['pendant' => bilan($pdo, $du), 'peut_voter' => Moderate::canVote($pdo, $du)[0]];
        $pdo->prepare("DELETE FROM mod_signaux WHERE account_id = ? AND cause = 'perte_du_vote_due'")->execute([$temoin]);
        recalerCase($pdo, $temoin);
        foreach (['du' => $du, 'temoin' => $temoin] as $role => $cible) {
            $c230->recherches = 0;
            Moderate::applyVote($c230, membre($pdo, "encore-230-$role"), 'member', $cible, -1, motif(), 'agressif');
            $lu['recherches'][$role] = $c230->recherches;
        }
        $lu['classe'] = Moderate::adminClasser($pdo, $du, idDe($pdo, 'arbitre-banc'), motif(), vuDe(signalDe($pdo, $du, 'perte_du_vote_due')))['ok'];
        foreach (['a', 'b'] as $k) {
            Moderate::applyVote($pdo, membre($pdo, "soutien-230-$k"), 'member', $du, 1);
        }
        $lu['remonte'] = [reputation($pdo, $du), $table230($du)];
        try {
            Moderate::balayerBansEchus($pdo);
        } catch (\RuntimeException) {
            // Un refus du journal sur un autre compte remonte en fin de passe, après elle.
        }
        $lu['balaye'] = $table230($du);
        Moderate::applyVote($pdo, membre($pdo, 'rechute-230'), 'member', $du, -1, motif(), 'agressif');
        $revenu = signalDe($pdo, $du, 'perte_du_vote_due');
        $lu['recroise'] = [reputation($pdo, $du), Moderate::getReputation($pdo, $du)['voting_rights'], $revenu === null ? 'absent' : $revenu['vu_le']];

        return $lu;
    });
} catch (Throwable $t230) {
    $r230 = ['exception' => get_class($t230) . ' : ' . $t230->getMessage()];
}
$c230 = null;
($r230['pendant']['rep'] ?? null) === 4 && $r230['pendant']['vote'] && $r230['peut_voter']
    && array_values(array_diff($r230['pendant']['causes'], ['salve_rapide'])) === ['perte_du_vote_due']
    && $r230['recherches']['du'] === 0 && $r230['recherches']['temoin'] >= 1
    && $r230['classe'] === true && $r230['remonte'] === [5, 1] && $r230['balaye'] === 0
    && $r230['recroise'] === [4, true, null]
    ? ok('⭐ en service, sous le seuil du vote, le vote reste et la perte se signale due ; posée, elle ne relance pas le quorum, le balayage la fait tomber à la remontée, et classée elle revient quand le seuil est recroisé')
    : nok('⭐ la perte du vote due relance le quorum, survit à la remontée, ou se perd : ' . json_encode($r230, JSON_UNESCAPED_UNICODE));

// ── 231. ⭐ Le ban de l'arbitre répond aux sanctions que la machine lui signalait dues ─
// En service, un compte au plancher (`reputation_zero`) et un compte sous le seuil du
// vote (`perte_du_vote_due`) ; en démonstration, un ban que le journal a refusé
// (`ban_en_attente`). Le ban de l'arbitre répond à chacun, et l'acte le nomme : le ban
// en attente se ferme ; la chute et la perte due quittent la file et restent en base,
// pour que le compte, à la fin du ban, ne soit pas au plancher sans aucun signal.
$dus231 = [
    'reputation_zero'   => chuteDeService($pdo, 'zero-231', 20, 7, 3),
    'perte_du_vote_due' => chuteDeService($pdo, 'du-231', 10, 6, 1),
    'ban_en_attente'    => banEnAttente($pdo, 'attente-231'),
];
$enFile231 = static function (int $id) use ($pdo): array {
    $s = $pdo->prepare('SELECT cause FROM mod_signaux WHERE account_id = ? AND vu_le IS NULL');
    $s->execute([$id]);

    return $s->fetchAll(PDO::FETCH_COLUMN);
};
$fermes231 = [];
foreach ($dus231 as $cause231 => $id231) {
    $avant231 = causes($pdo, $id231);
    $r231 = Moderate::adminBan($pdo, $id231, idDe($pdo, 'arbitre-banc'), motif(), 7 * 86400);
    $acte231 = $journalC->de($id231, 'ban_debut')[0] ?? [];
    $ferme231 = array_column(liste($acte231['ferme'] ?? null) ?? [], 'cause');
    sort($ferme231);
    $fermes231[$cause231] = [
        'avait' => in_array($cause231, $avant231, true),
        'ok'    => $r231['ok'],
        'reste' => array_values(array_intersect(['reputation_zero', 'ban_en_attente', 'perte_du_vote_due'], causes($pdo, $id231))),
        'file'  => array_values(array_intersect(['reputation_zero', 'ban_en_attente', 'perte_du_vote_due'], $enFile231($id231))),
        'ferme' => $ferme231,
    ];
}
$fermes231 === [
    'reputation_zero'   => ['avait' => true, 'ok' => true, 'reste' => ['reputation_zero', 'perte_du_vote_due'], 'file' => [], 'ferme' => ['perte_du_vote_due', 'reputation_zero']],
    'perte_du_vote_due' => ['avait' => true, 'ok' => true, 'reste' => ['perte_du_vote_due'], 'file' => [], 'ferme' => ['perte_du_vote_due']],
    'ban_en_attente'    => ['avait' => true, 'ok' => true, 'reste' => ['reputation_zero'], 'file' => [], 'ferme' => ['ban_en_attente', 'reputation_zero']],
]
    ? ok('⭐ le ban de l\'arbitre ferme le ban en attente, sort de la file le ban dû et la perte due, qui restent en base, et son acte les nomme')
    : nok('⭐ un ban d\'arbitre laisse ouverte une sanction due, ou ne la nomme pas : ' . json_encode($fermes231, JSON_UNESCAPED_UNICODE));

// ── 232. ⭐ Le ban en attente ne se classe pas, et tombe quand la réputation quitte le plancher ─
// Il attend le journal, pas l'arbitre : le classer le ferait taire alors que le vote
// suivant le retente. La chute, elle, se classe. Un point de convalescence le fait
// tomber, la chute reste.
$attente232 = banEnAttente($pdo, 'attente-232');
$avant232 = causes($pdo, $attente232);
$refus232 = Moderate::adminClasser($pdo, $attente232, idDe($pdo, 'arbitre-banc'), motif(), vuDe(signalDe($pdo, $attente232, 'ban_en_attente')));
$apresRefus232 = signalDe($pdo, $attente232, 'ban_en_attente');
$chuteClassee232 = Moderate::adminClasser($pdo, $attente232, idDe($pdo, 'arbitre-banc'), motif(), vuDe(signalDe($pdo, $attente232, 'reputation_zero')))['ok'];
$pdo->prepare('UPDATE member_moderation SET last_regen_at = last_regen_at - ? WHERE account_id = ?')
    ->execute([Moderate::config()->intervalleConvalescenceSecondes, $attente232]);
rattraper($pdo, $attente232);
$apres232 = [reputation($pdo, $attente232), causes($pdo, $attente232)];
in_array('ban_en_attente', $avant232, true) && !$refus232['ok'] && str_contains($refus232['message'], 'ne se classe pas')
    && $apresRefus232 !== null && $apresRefus232['vu_le'] === null && $chuteClassee232
    && $apres232 === [1, ['reputation_zero']]
    ? ok('⭐ le ban en attente ne se classe pas, la chute si ; un point de convalescence le fait tomber, et la chute reste')
    : nok('⭐ le ban en attente se classe, ou survit à la sortie du plancher : ' . json_encode([$avant232, $refus232, $apresRefus232, $chuteClassee232, $apres232], JSON_UNESCAPED_UNICODE));

// ── 233. ⭐ L'arbitre suspend le vote et le rend, au journal, et rien d'autre ne le fait ─
// Le geste qui répond en service à `perte_du_vote_due`, plus léger que le ban. Il
// refuse comme les autres gestes : sans journal, dans la transaction de l'hôte, quand
// une fin de peine attend le journal, sur un motif vide ou une durée hors bornes. Posé,
// il ferme la perte due, s'inscrit (`vote_retire`, origine `admin`) et `canVote()` le
// dit ; une seconde suspension se refuse. Rendu, il s'inscrit (`vote_rendu`) et le vote
// revient ; rendre deux fois se refuse.
$du233 = chuteDeService($pdo, 'du-233', 10, 6, 1);
$arbitre233 = idDe($pdo, 'arbitre-banc');
$suspendre233 = static fn (int $id, ?int $secondes = 3600, ?string $motif = null): array =>
    Moderate::adminSuspendreVote($pdo, $id, $arbitre233, $motif ?? motif(), $secondes);
$rendre233 = static fn (int $id): array => Moderate::adminRendreVote($pdo, $id, $arbitre233, 'Le vote revient : la suspension n\'a plus d\'objet.');
$muet233 = static fn (int $id): int => (int) etatBan($pdo, $id)['vote_muted_until'];
$refus233 = [];
$journalAvant233 = Moderate::journal();
Moderate::setJournal(null);
try {
    $refus233['sans journal'] = [$suspendre233($du233), $rendre233($du233)];
} finally {
    Moderate::setJournal($journalAvant233);
}
$pdo->beginTransaction();
$refus233['transaction de l\'hôte'] = [$suspendre233($du233), $rendre233($du233)];
$pdo->rollBack();
$journal233 = new class implements \Pierroons\SelfModerate\Journal {
    /** @var list<string> */
    public array $actes = [];

    public function inscrire(array $acte): void
    {
        if ($acte['acte'] === 'ban_fin') {
            throw new RuntimeException('fin refusée');
        }
        $this->actes[] = $acte['acte'];
    }
};
$attente233 = membre($pdo, 'attente-233');
Moderate::ensureRow($pdo, $attente233);
$pdo->prepare("UPDATE member_moderation
                  SET reputation = 0, strikes = 1, voting_rights = 0, banned_until = ?, ban_debut = ?,
                      ban_origine = 'auto', ban_motif = 'reputation_zero'
                WHERE account_id = ?")->execute([time() - 10, time() - 130, $attente233]);
Moderate::setJournal($journal233);
try {
    Moderate::migrerBans($pdo);
    $journal233->actes = [];
    $refus233['fin en attente'] = [$suspendre233($attente233), $rendre233($attente233)];
} catch (Throwable $e) {
    $refus233['fin en attente'] = [['ok' => true, 'message' => get_class($e) . ' : ' . $e->getMessage()]];
} finally {
    Moderate::setJournal($journalAvant233);
}
$refus233['motif vide'] = [$suspendre233($du233, 3600, 'court')];
$refus233['durée nulle'] = [$suspendre233($du233, 0)];
$refus233['durée d\'un siècle et une seconde'] = [$suspendre233($du233, Moderate::DUREE_MAX + 1)];
$intact233 = $muet233($du233) === 0 && $journalC->de($du233, 'vote_retire') === [] && $journal233->actes === []
    && etatBan($pdo, $attente233)['review_reason'] === 'journal_en_panne';
$attendus233 = [
    'sans journal'           => 'journal',
    'transaction de l\'hôte' => 'transaction de l\'hôte',
    'fin en attente'         => 'attend que le journal inscrive sa fin',
];
$refusMal233 = [];
foreach ($refus233 as $cas233 => $reponses233) {
    foreach ($reponses233 as $reponse233) {
        if ($reponse233['ok'] || (isset($attendus233[$cas233]) && !str_contains($reponse233['message'], $attendus233[$cas233]))) {
            $refusMal233[$cas233][] = $reponse233;
        }
    }
}
$ouverts233 = causes($pdo, $du233);
$pose233 = $suspendre233($du233, 3600, '  Ses votes visent toujours les mêmes membres, sans un mot sur leurs messages.  ');
$jusqua233 = $muet233($du233);
$acte233 = $journalC->de($du233, 'vote_retire')[0] ?? [];
$canVote233 = Moderate::canVote($pdo, $du233);
$double233 = $suspendre233($du233);
$defaut233 = membre($pdo, 'defaut-233');
poserReputation($pdo, $defaut233, 15);
$suspendre233($defaut233, null);
$acteDefaut233 = $journalC->de($defaut233, 'vote_retire')[0] ?? [];
$rendu233 = $rendre233($du233);
$acteRendu233 = $journalC->de($du233, 'vote_rendu')[0] ?? [];
$apres233 = [$muet233($du233), Moderate::canVote($pdo, $du233)[0], $rendre233($du233)];
$rendre233($defaut233);
$posee233 = $pose233['ok'] && in_array('perte_du_vote_due', $ouverts233, true)
    && (signalDe($pdo, $du233, 'perte_du_vote_due')['vu_le'] ?? null) !== null
    && ($acte233['origine'] ?? null) === 'admin' && $acte233['arbitre_id'] === $arbitre233 && $acte233['arbitre'] === 'arbitre-banc'
    && $acte233['motif'] === 'Ses votes visent toujours les mêmes membres, sans un mot sur leurs messages.'
    && $acte233['jusqu_a'] === $jusqua233 && $jusqua233 - $acte233['debut'] === 3600 && $acte233['reputation'] === 4
    && array_column(liste($acte233['ferme']) ?? [], 'cause') === ['perte_du_vote_due']
    && $canVote233 === [false, sprintf('Droit de vote suspendu par un arbitre jusqu\'au %s.', date('d/m/Y H:i', $jusqua233))]
    && !$double233['ok'] && str_contains($double233['message'], 'déjà suspendu') && count($journalC->de($du233, 'vote_retire')) === 1
    && ($acteDefaut233['jusqu_a'] ?? 0) - ($acteDefaut233['debut'] ?? 0) === Moderate::config()->dureeBanAdmin();
$rendue233 = $rendu233['ok'] && ($acteRendu233['arbitre_id'] ?? null) === $arbitre233 && $acteRendu233['jusqu_a'] === $jusqua233
    && ($acteRendu233['origine'] ?? null) === 'admin'
    && $acteRendu233['motif'] === 'Le vote revient : la suspension n\'a plus d\'objet.'
    && $apres233[0] === 0 && $apres233[1] === true && !$apres233[2]['ok'] && str_contains($apres233[2]['message'], 'Aucune suspension');
$refusMal233 === [] && $intact233 && $posee233 && $rendue233
    ? ok('⭐ la suspension du vote refuse sans journal, dans l\'hôte, sur une fin en attente ou un motif et une durée invalides ; posée, elle sort la perte due de la file, s\'inscrit et se dit ; rendue, elle s\'inscrit et le vote revient')
    : nok('⭐ une suspension du vote passe sans ses gardes, sans son acte, ou ne se lève pas : ' . json_encode([
        'refus' => $refusMal233, 'intact' => $intact233, 'pose' => [$pose233, $acte233, $canVote233, $double233, $acteDefaut233],
        'rendu' => [$rendu233, $acteRendu233, $apres233],
    ], JSON_UNESCAPED_UNICODE));

// ── 234. ⭐ En démonstration, le vote se retire au journal d'abord, ou reste dû ─
// Journal branché, la perte du vote s'inscrit (`vote_retire`, origine `auto`, motif
// `perte_droit_de_vote`) et le vote tombe. Sans journal, dans la transaction de l'hôte,
// ou sur un refus du journal, le vote reste et `perte_du_vote_due` se pose ; le
// journal revenu, le vote suivant retente le retrait, qui ferme la perte due.
$retire234 = membre($pdo, 'retire-234');
poserReputation($pdo, $retire234, 5);
Moderate::applyVote($pdo, membre($pdo, 'votant-234-a'), 'member', $retire234, -1, motif(), 'agressif');
$avecJournal234 = [bilan($pdo, $retire234), $journalC->de($retire234, 'vote_retire')];
$du234 = membre($pdo, 'du-234');
poserReputation($pdo, $du234, 5);
Moderate::setJournal(null);
try {
    Moderate::applyVote($pdo, membre($pdo, 'votant-234-b'), 'member', $du234, -1, motif(), 'agressif');
} finally {
    Moderate::setJournal($journalC);
}
$sansJournal234 = bilan($pdo, $du234);
Moderate::applyVote($pdo, membre($pdo, 'votant-234-c'), 'member', $du234, -1, motif(), 'agressif');
$retente234 = [bilan($pdo, $du234), $journalC->de($du234, 'vote_retire')];
$hote234 = membre($pdo, 'hote-234');
poserReputation($pdo, $hote234, 5);
$actesAvant234 = count($journalC->actes);
$pdo->beginTransaction();
try {
    Moderate::applyVote($pdo, membre($pdo, 'votant-234-d'), 'member', $hote234, -1, motif(), 'agressif');
    $dansLHote234 = [bilan($pdo, $hote234), count($journalC->actes) - $actesAvant234];
} finally {
    $pdo->rollBack();
}
$refuse234 = membre($pdo, 'refuse-234');
poserReputation($pdo, $refuse234, 5);
Moderate::setJournal(new class implements \Pierroons\SelfModerate\Journal {
    public function inscrire(array $acte): void
    {
        if ($acte['acte'] === 'vote_retire') {
            throw new RuntimeException('retrait refusé');
        }
    }
});
try {
    $voteRefuse234 = Moderate::applyVote($pdo, membre($pdo, 'votant-234-e'), 'member', $refuse234, -1, motif(), 'agressif');
} finally {
    Moderate::setJournal($journalC);
}
$refusJournal234 = bilan($pdo, $refuse234);
$acte234 = $avecJournal234[1][0] ?? [];
$retenteActe234 = $retente234[1][0] ?? [];
$avecJournal234[0]['rep'] === 4 && !$avecJournal234[0]['vote'] && $avecJournal234[0]['causes'] === ['droit_de_vote_perdu']
    && count($avecJournal234[1]) === 1 && ($acte234['origine'] ?? null) === 'auto' && $acte234['motif'] === 'perte_droit_de_vote'
    && $acte234['arbitre'] === null && $acte234['arbitre_id'] === null && $acte234['jusqu_a'] === null && $acte234['reputation'] === 4
    && $sansJournal234['rep'] === 4 && $sansJournal234['vote'] && $sansJournal234['causes'] === ['perte_du_vote_due']
    && $retente234[0]['rep'] === 3 && !$retente234[0]['vote'] && $retente234[0]['causes'] === ['droit_de_vote_perdu']
    && count($retente234[1]) === 1 && array_column(liste($retenteActe234['ferme'] ?? null) ?? [], 'cause') === ['perte_du_vote_due']
    && $dansLHote234[0]['vote'] && $dansLHote234[0]['causes'] === ['perte_du_vote_due'] && $dansLHote234[1] === 0
    && ($voteRefuse234['ok'] ?? false) && $refusJournal234['vote'] && $refusJournal234['causes'] === ['perte_du_vote_due']
    ? ok('⭐ en démonstration, le retrait du vote s\'inscrit avant la base ; sans journal, dans l\'hôte ou sur un refus, le vote reste et la perte se signale due, et le vote suivant retente le retrait')
    : nok('⭐ le vote se retire sans trace, ou la perte due se perd : ' . json_encode([
        'avec_journal' => $avecJournal234, 'sans_journal' => $sansJournal234, 'retente' => $retente234,
        'dans_l_hote' => $dansLHote234, 'refus' => [$voteRefuse234, $refusJournal234],
    ], JSON_UNESCAPED_UNICODE));

// Un vote que la base défait ne laisse pas de retrait au journal. Au plancher, le ban
// qui suit le retrait se refuse sur une peine orpheline (`migrerBans()`, n° 152) : le
// vote lève, sa transaction est défaite, et le journal ne doit pas garder un retrait
// que la base n'a jamais inscrit.
$orph234 = membre($pdo, 'orpheline-234');
poserReputation($pdo, $orph234, 15);
Moderate::adminBan($pdo, $orph234, idDe($pdo, 'arbitre-banc'), motif(), 3600);
$pdo->prepare('UPDATE member_moderation SET banned_until = 0, ban_debut = 0, ban_origine = NULL, ban_motif = NULL WHERE account_id = ?')
    ->execute([$orph234]);
poserReputation($pdo, $orph234, 1);
try {
    Moderate::applyVote($pdo, membre($pdo, 'votant-234-f'), 'member', $orph234, -1, motif(), 'agressif');
    $leve234 = 'aucune erreur';
} catch (\Pierroons\SelfModerate\RepriseManquante) {
    $leve234 = 'nommée';
} catch (Throwable $e234) {
    $leve234 = get_class($e234) . ' : ' . $e234->getMessage();
}
$orphelin234 = [$leve234, Moderate::getReputation($pdo, $orph234)['voting_rights'], count($journalC->de($orph234, 'vote_retire'))];
try {
    Moderate::migrerBans($pdo);
} catch (Throwable) {
}
$orphelin234 === ['nommée', true, 0]
    ? ok('⭐ un vote défait par la base ne laisse aucun retrait du vote au journal')
    : nok('⭐ un retrait du vote reste au journal pour un vote que la base a défait : ' . json_encode($orphelin234, JSON_UNESCAPED_UNICODE));

// ── 235. ⭐ Hors du moteur, la messagerie ne se lit jamais : sans table `dm`, chaque votant fait sa voix ─
// Un hôte dont les messages privés passent ailleurs le déclare : le moteur ne touche
// pas à `dm`, ni pour son index, ni pour un lien. Sur une base qui n'en a pas, sept
// critiques font sept voix et bannissent, `areLinked()` rend faux, la composition dit
// des liens non lus, et l'état du site n'a rien à dire.
$f235 = dirname((string) getenv('LAB_DB_PATH')) . '/hors-moteur-235.db';
@unlink($f235);
$a235 = new PDO('sqlite:' . $f235);
$a235->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$a235->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$a235->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));
$a235->exec('DROP TABLE dm');
$cible235 = membre($a235, 'cible-235');
poserReputation($a235, $cible235, 20);
$sept235 = membres($a235, 'critique-235', 7);
$a235 = null;
try {
    $r235 = sousQuorum(static function (JournalDeBanc $journal) use ($f235, $cible235, $sept235): array {
        $b = new PDO('sqlite:' . $f235);
        $b->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $b->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        frapper($b, $cible235, $sept235, 3, '235');
        $chute = Moderate::compositionChute($b, $cible235, time());

        return [
            'bilan' => bilan($b, $cible235), 'bans' => count($journal->de($cible235, 'ban_debut')),
            'chute' => [$chute['voix_sans_lien'], $chute['votants'], $chute['paires_liees'], $chute['indecis'], $chute['liens']],
            'lies'  => Moderate::areLinked($b, $sept235[0], $sept235[1]), 'site' => Moderate::etatDuSite($b),
        ];
    }, messagerie: Messagerie::HORS_MOTEUR);
} catch (Throwable $t235) {
    $r235 = ['exception' => get_class($t235) . ' : ' . $t235->getMessage()];
}
($r235['bilan']['rep'] ?? null) === 0 && $r235['bilan']['banni'] && $r235['bans'] === 1
    && $r235['chute'] === [7, 7, null, false, 'non_lus'] && $r235['lies'] === false && $r235['site'] === []
    ? ok('⭐ hors du moteur, sans table dm, sept critiques font sept voix et bannissent, personne n\'est lié, et la composition dit des liens non lus')
    : nok('seuil sourd à la config (messagerie) : ' . json_encode($r235, JSON_UNESCAPED_UNICODE));

// ── 236. ⭐ Une `dm` déclarée et vide se dit à l'arbitre, une fois la fenêtre des liens passée ─
// Le quorum n'y lit aucun lien : chaque votant fait sa voix, et la composition d'une
// chute le dit (`muets`). Sur un site neuf, c'est l'état normal : l'état du site ne
// signale `liens_muets` que quand le premier vote a plus de `fenetreMeuteJours` jours.
// Hors du moteur, il n'y a rien à dire ; un seul message le fait taire, et la
// composition lit alors des liens.
$f236 = dirname((string) getenv('LAB_DB_PATH')) . '/liens-muets-236.db';
@unlink($f236);
$b236 = new PDO('sqlite:' . $f236);
$b236->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$b236->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$b236->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));
$cible236 = membre($b236, 'cible-236');
poserReputation($b236, $cible236, 20);
$deux236 = membres($b236, 'critique-236', 2);
frapper($b236, $cible236, $deux236, 1, '236');
$fenetre236 = Moderate::config()->fenetreMeuteJours * 86400;
$premier236 = static function (int $age) use ($b236, $deux236): int {
    $quand = time() - $age;
    $b236->prepare('UPDATE mod_votes SET created_at = ? WHERE voter_id = ?')->execute([$quand, $deux236[0]]);

    return $quand;
};
$lu236 = ['neuf' => [Moderate::etatDuSite($b236), Moderate::compositionChute($b236, $cible236, time())['liens']]];
$premier236($fenetre236 - 3600);
$lu236['en_deca'] = Moderate::etatDuSite($b236);
$depuis236 = $premier236($fenetre236 + 3600);
$lu236['au_dela'] = Moderate::etatDuSite($b236);
$lu236['hors_moteur'] = sousConfig(configBanc(messagerie: Messagerie::HORS_MOTEUR), static fn (): array => Moderate::etatDuSite($b236));
messagePrive($b236, $deux236[0], $deux236[1]);
$lu236['un_message'] = [Moderate::etatDuSite($b236), Moderate::compositionChute($b236, $cible236, time())['liens']];
$b236 = null;
$lu236 === [
    'neuf' => [[], 'muets'], 'en_deca' => [], 'au_dela' => [['cause' => 'liens_muets', 'depuis' => $depuis236]],
    'hors_moteur' => [], 'un_message' => [[], 'lus'],
]
    ? ok('⭐ une dm déclarée et vide fait dire « muets » à la composition, et liens_muets à l\'état du site passé la fenêtre des liens ; hors du moteur ou après un message, rien')
    : nok('⭐ des liens muets passent sous silence, ou se signalent sur un site neuf : ' . json_encode([$lu236, $depuis236], JSON_UNESCAPED_UNICODE));

// ── 237. ⭐ Une suspension répond à la perte due : elle ne revient pas avant que le seuil soit recroisé ─
// En service, six critiques mènent la cible à 4 : la perte du vote se signale due.
// L'arbitre suspend le vote : la perte quitte la file et reste en base, réponse
// rendue. Deux votes de plus ne la remettent pas dans la file et ne recherchent pas le
// quorum ; la suspension échue, un vote de plus non plus. Remontée au seuil par des
// votes de soutien, puis une nouvelle chute : elle revient. Sur un compte suspendu
// d'abord, le quorum atteint pose la perte due hors de la file.
$c237 = new PdoQuorum('sqlite:' . getenv('LAB_DB_PATH'));
$c237->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$c237->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$t237 = time();
Moderate::setHorloge(static function () use (&$t237): int { return $t237; });
$enFile237 = static function (int $id) use ($pdo): bool {
    $s = signalDe($pdo, $id, 'perte_du_vote_due');

    return $s !== null && $s['vu_le'] === null;
};
try {
    // Une fonction fléchée capture par valeur : l'horloge ne verrait pas `$t237` avancer.
    $r237 = sousJournal(static function () use ($pdo, $c237, &$t237, $enFile237): array {
        return sousConfig(configService(), static function () use ($pdo, $c237, &$t237, $enFile237): array {
        $arbitre = idDe($pdo, 'arbitre-banc');
        $du = membre($pdo, 'du-237');
        poserReputation($pdo, $du, 10);
        frapper($pdo, $du, membres($pdo, 'critique-237', 6), 1, '237');
        $lu = ['due' => $enFile237($du)];
        $lu['suspendu'] = Moderate::adminSuspendreVote($pdo, $du, $arbitre, motif(), 600)['ok'];
        $lu['apres_geste'] = [$enFile237($du), signalDe($pdo, $du, 'perte_du_vote_due') !== null];
        $c237->recherches = 0;
        foreach (['a', 'b'] as $k) {
            Moderate::applyVote($c237, membre($pdo, "encore-237-$k"), 'member', $du, -1, motif(), 'agressif');
        }
        $lu['pendant'] = [$c237->recherches, $enFile237($du)];
        $t237 += 700;
        $c237->recherches = 0;
        Moderate::applyVote($c237, membre($pdo, 'encore-237-c'), 'member', $du, -1, motif(), 'agressif');
        $lu['echue'] = [$c237->recherches, $enFile237($du), Moderate::canVote($pdo, $du)[0]];
        foreach (range(1, 4) as $k) {
            Moderate::applyVote($pdo, membre($pdo, "soutien-237-$k"), 'member', $du, 1);
        }
        try {
            Moderate::balayerBansEchus($pdo);
        } catch (\RuntimeException) {
            // Un refus du journal sur un autre compte remonte en fin de passe, après elle.
        }
        $lu['remonte'] = [reputation($pdo, $du), signalDe($pdo, $du, 'perte_du_vote_due') === null];
        Moderate::applyVote($pdo, membre($pdo, 'rechute-237'), 'member', $du, -1, motif(), 'agressif');
        $lu['recroise'] = [reputation($pdo, $du), $enFile237($du)];
        $avant = membre($pdo, 'avant-237');
        poserReputation($pdo, $avant, 10);
        Moderate::adminSuspendreVote($pdo, $avant, $arbitre, motif(), 6000);
        frapper($pdo, $avant, membres($pdo, 'critique-237-avant', 6), 1, '237-avant');
        $pose = signalDe($pdo, $avant, 'perte_du_vote_due');
        $lu['suspendu_avant'] = [$pose !== null, ($pose['vu_le'] ?? null) !== null];

        return $lu;
        });
    });
} catch (Throwable $t237e) {
    $r237 = ['exception' => get_class($t237e) . ' : ' . $t237e->getMessage()];
} finally {
    Moderate::setHorloge(null);
}
$c237 = null;
$r237 === [
    'due' => true, 'suspendu' => true, 'apres_geste' => [false, true], 'pendant' => [0, false], 'echue' => [0, false, true],
    'remonte' => [5, true], 'recroise' => [4, true], 'suspendu_avant' => [true, true],
]
    ? ok('⭐ une suspension sort la perte due de la file : ni les votes suivants ni son échéance ne la lui rendent, une nouvelle chute après la remontée si ; un compte déjà suspendu la reçoit hors de la file')
    : nok('⭐ la perte due revient à l\'arbitre après sa suspension, sans que le seuil soit recroisé : ' . json_encode($r237, JSON_UNESCAPED_UNICODE));

// ── 238. ⭐ Au plancher, en service, une chute répondue ne revient pas à chaque vote ─
// Six critiques sans lien, deux votes chacun : la cible tombe au plancher sous le
// quorum du ban, la chute se signale sans quorum, et la perte du vote, due depuis le
// seuil, reste en base hors de la file. L'arbitre classe la chute : deux votes de plus
// au plancher ne la lui rendent pas. Une autre cible, dont l'arbitre a classé la perte
// due à 4, descend au plancher puis remonte à 2 par la convalescence : un vote de plus
// ne lui rend pas la perte due.
$horsSalve238 = static fn (array $causes): array => array_values(array_diff($causes, ['salve_rapide']));
try {
    $r238 = sousJournal(static fn (): array => sousConfig(configService(), static function () use ($pdo, $horsSalve238): array {
        $arbitre = idDe($pdo, 'arbitre-banc');
        $cible = membre($pdo, 'plancher-238');
        poserReputation($pdo, $cible, 12);
        $six = membres($pdo, 'critique-238', 6);
        frapper($pdo, $cible, $six, 2, '238');
        $chute = bilan($pdo, $cible);
        $lu = ['chute' => [$chute['rep'], $horsSalve238($chute['file']), in_array('perte_du_vote_due', $chute['causes'], true)]];
        $lu['classe'] = Moderate::adminClasser($pdo, $cible, $arbitre, motif(), vuDe(signalDe($pdo, $cible, 'chute_sans_quorum')))['ok'];
        foreach ([0, 1] as $k) {
            Moderate::applyVote($pdo, $six[$k], 'post', message($pdo, $cible, "fil-238-encore-$k"), -1, motif(), 'agressif');
        }
        $lu['apres'] = $horsSalve238(bilan($pdo, $cible)['file']);
        $du = membre($pdo, 'du-238');
        poserReputation($pdo, $du, 10);
        $critiques = membres($pdo, 'critique-238-du', 6);
        frapper($pdo, $du, $critiques, 1, '238-du');
        $lu['classe_du'] = Moderate::adminClasser($pdo, $du, $arbitre, motif(), vuDe(signalDe($pdo, $du, 'perte_du_vote_due')))['ok'];
        frapper($pdo, $du, array_slice($critiques, 0, 4), 1, '238-du-b');
        $pdo->prepare('UPDATE member_moderation SET last_regen_at = last_regen_at - ? WHERE account_id = ?')
            ->execute([2 * Moderate::config()->intervalleConvalescenceSecondes, $du]);
        rattraper($pdo, $du);
        $lu['remonte'] = reputation($pdo, $du);
        Moderate::applyVote($pdo, $critiques[5], 'post', message($pdo, $du, 'fil-238-du-c'), -1, motif(), 'agressif');
        $revote = bilan($pdo, $du);
        $lu['revote'] = [$revote['rep'], in_array('perte_du_vote_due', $revote['file'], true)];

        return $lu;
    }));
} catch (Throwable $t238) {
    $r238 = ['exception' => get_class($t238) . ' : ' . $t238->getMessage()];
}
$r238 === [
    'chute' => [0, ['chute_sans_quorum'], true], 'classe' => true, 'apres' => [],
    'classe_du' => true, 'remonte' => 2, 'revote' => [1, false],
]
    ? ok('⭐ au plancher, en service, la chute classée ne revient pas à chaque vote, et la perte due reste hors de la file jusqu\'à la remontée au seuil')
    : nok('⭐ au plancher, en service, une chute ou une perte due répondues reviennent sans nouveau franchissement : ' . json_encode($r238, JSON_UNESCAPED_UNICODE));

// ── 239. ⭐ Un vote de soutien qui sort le compte du plancher fait tomber le ban en attente ─
// En démonstration, le journal a refusé le début du ban : `ban_en_attente`. Un vote
// de soutien porte la réputation à 1 : le ban dû vit avec la chute au plancher, et
// tombe sans attendre un point de convalescence ; la chute reste.
$attente239 = banEnAttente($pdo, 'attente-239');
$avant239 = causes($pdo, $attente239);
Moderate::applyVote($pdo, membre($pdo, 'soutien-239'), 'member', $attente239, 1);
$apres239 = [reputation($pdo, $attente239), causes($pdo, $attente239)];
in_array('ban_en_attente', $avant239, true) && $apres239[0] === 1
    && !in_array('ban_en_attente', $apres239[1], true) && in_array('reputation_zero', $apres239[1], true)
    ? ok('⭐ un vote de soutien qui sort le compte du plancher fait tomber le ban en attente, et la chute reste')
    : nok('⭐ le ban en attente survit à un vote de soutien qui sort le compte du plancher : ' . json_encode([$avant239, $apres239], JSON_UNESCAPED_UNICODE));

// ── 240. ⭐ En démonstration, un vote retiré revient en une heure de calme au plus ─
// La borne d'une heure tient le retrait du vote comme le ban : `perteDroitDeVoteSous`
// intervalles de convalescence tiennent en `PALIER_DEMONSTRATION_MAX`. Une config qui
// en demande plus lève, en convalescence comme en seuil ; le service n'a pas cette
// borne. Un compte privé de son vote au plancher le retrouve une heure plus tard.
$essai240 = static function (mixed ...$reglages): string {
    try {
        new Config(...$reglages);

        return 'construite';
    } catch (InvalidArgumentException) {
        return 'refusée';
    }
};
$prod240 = get_object_vars(Config::prod(Messagerie::DM));
$retire240 = membre($pdo, 'retire-240');
poserReputation($pdo, $retire240, 0);
$pdo->prepare('UPDATE member_moderation SET voting_rights = 0, convalescent = 1, last_regen_at = ? WHERE account_id = ?')
    ->execute([time() - Config::PALIER_DEMONSTRATION_MAX, $retire240]);
$lu240 = [
    'demo'       => Config::demo()->perteDroitDeVoteSous * Config::demo()->intervalleConvalescenceSecondes <= Config::PALIER_DEMONSTRATION_MAX,
    'trop_lente' => $essai240(Messagerie::DM, Usage::DEMONSTRATION, intervalleConvalescenceSecondes: 721),
    'juste'      => $essai240(Messagerie::DM, Usage::DEMONSTRATION, intervalleConvalescenceSecondes: 720),
    'seuil_haut' => $essai240(Messagerie::DM, Usage::DEMONSTRATION, perteDroitDeVoteSous: 6),
    'service'    => $essai240(...[...$prod240, 'intervalleConvalescenceSecondes' => 31536000]),
    'revient'    => [Moderate::getReputation($pdo, $retire240)['voting_rights'], Moderate::canVote($pdo, $retire240)[0]],
];
// La lecture montre la remontée sans l'inscrire : la base garde le plancher tant
// qu'aucune écriture ne passe, et le banc ne laisse pas une chute sans son signal.
rattraper($pdo, $retire240);
$lu240 === ['demo' => true, 'trop_lente' => 'refusée', 'juste' => 'construite', 'seuil_haut' => 'refusée', 'service' => 'construite', 'revient' => [true, true]]
    ? ok('⭐ en démonstration, un vote retiré revient en une heure de calme au plus : une convalescence ou un seuil qui l\'allongent lèvent, le service n\'a pas cette borne')
    : nok('⭐ la démonstration retire le vote plus d\'une heure : ' . json_encode($lu240, JSON_UNESCAPED_UNICODE));

// ── 241. ⭐ La fenêtre des liens, la convalescence et le balayage refusent un réglage vide ─
// En service, la fenêtre des liens ne descend pas sous celle de `prod()` : plus courte,
// le quorum ne lit plus les liens d'une meute, et rien ne le dit. Partout, une fenêtre,
// un intervalle ou une période à 0 ou négatifs lèvent : à 0, la convalescence et le
// balayage divisent par zéro, et la fenêtre des liens ne lit plus rien.
$lu241 = [
    'meute_service'      => $essai240(...[...$prod240, 'fenetreMeuteJours' => 29]),
    'meute_service_dure' => $essai240(...[...$prod240, 'fenetreMeuteJours' => 45]),
    'meute_zero'         => $essai240(Messagerie::DM, Usage::DEMONSTRATION, fenetreMeuteJours: 0),
    'meute_negative'     => $essai240(Messagerie::DM, Usage::DEMONSTRATION, fenetreMeuteJours: -30),
    'balayage_zero'      => $essai240(Messagerie::DM, Usage::DEMONSTRATION, balayagePeriodeSecondes: 0),
    'convalescence_zero' => $essai240(Messagerie::DM, Usage::DEMONSTRATION, intervalleConvalescenceSecondes: 0),
    'convalescence_neg'  => $essai240(Messagerie::DM, Usage::DEMONSTRATION, intervalleConvalescenceSecondes: -1),
];
$lu241 === [
    'meute_service' => 'refusée', 'meute_service_dure' => 'construite', 'meute_zero' => 'refusée', 'meute_negative' => 'refusée',
    'balayage_zero' => 'refusée', 'convalescence_zero' => 'refusée', 'convalescence_neg' => 'refusée',
]
    ? ok('⭐ la fenêtre des liens tient son plancher de service, et une fenêtre, une convalescence ou un balayage vides lèvent')
    : nok('⭐ un réglage vide ou plus faible que prod() passe : ' . json_encode($lu241, JSON_UNESCAPED_UNICODE));

// ── 242. ⭐ Rendre le vote : un motif contrôlé, et une peine échue refermée une seule fois ─
// Un motif donné se contrôle comme celui d'une levée : « x » ne passe pas. Un ban
// d'arbitre échu et non inscrit se referme avant la transaction du geste : quand le
// journal refuse `vote_rendu`, la fin de peine reste inscrite une fois, et le geste
// rejoué ne la réinscrit pas.
$t242 = time();
Moderate::setHorloge(static function () use (&$t242): int { return $t242; });
$journal242 = new class implements Pierroons\SelfModerate\Journal {
    /** @var list<array<string, mixed>> */
    public array $actes = [];
    public int $refus = 1;

    public function inscrire(array $acte): void
    {
        if ($acte['acte'] === 'vote_rendu' && $this->refus-- > 0) {
            throw new RuntimeException('rendu refusé');
        }
        $this->actes[] = $acte;
    }
};
$journalAvant242 = Moderate::journal();
Moderate::setJournal($journal242);
try {
    $arbitre242 = idDe($pdo, 'arbitre-banc');
    $cible242 = membre($pdo, 'rendu-242');
    poserReputation($pdo, $cible242, 15);
    $lu242 = [
        'ban'      => Moderate::adminBan($pdo, $cible242, $arbitre242, motif(), 60)['ok'],
        'suspendu' => Moderate::adminSuspendreVote($pdo, $cible242, $arbitre242, motif(), 6000)['ok'],
        'motif_x'  => Moderate::adminRendreVote($pdo, $cible242, $arbitre242, 'x')['ok'],
    ];
    $t242 += 3600;
    try {
        Moderate::adminRendreVote($pdo, $cible242, $arbitre242);
        $lu242['premier'] = 'passé';
    } catch (RuntimeException) {
        $lu242['premier'] = 'refusé';
    }
    $lu242['second'] = Moderate::adminRendreVote($pdo, $cible242, $arbitre242)['ok'];
    $lu242['fins'] = count(array_filter($journal242->actes, static fn (array $a): bool => $a['acte'] === 'ban_fin' && $a['compte'] === $cible242));
} catch (Throwable $t242e) {
    $lu242 = ['exception' => get_class($t242e) . ' : ' . $t242e->getMessage()];
} finally {
    Moderate::setJournal($journalAvant242);
    Moderate::setHorloge(null);
}
$lu242 === ['ban' => true, 'suspendu' => true, 'motif_x' => false, 'premier' => 'refusé', 'second' => true, 'fins' => 1]
    ? ok('⭐ rendre le vote contrôle un motif donné, et referme une peine échue avant sa transaction : un refus du journal ne l\'inscrit pas deux fois')
    : nok('⭐ rendre le vote accepte un motif vide de sens, ou inscrit deux fois une fin de peine : ' . json_encode($lu242, JSON_UNESCAPED_UNICODE));

// ── 243. ⭐ En démonstration, la perte du vote due ne se classe pas ────────────────
// Le journal refuse `vote_retire` : le vote reste, la perte se signale due, et le vote
// suivant retentera le retrait. La classer ne l'arrêterait pas : le moteur refuse,
// comme pour le ban en attente.
$journalAvant243 = Moderate::journal();
Moderate::setJournal(new class implements Pierroons\SelfModerate\Journal {
    public function inscrire(array $acte): void
    {
        if ($acte['acte'] === 'vote_retire') {
            throw new RuntimeException('retrait refusé');
        }
    }
});
try {
    $r243 = sousConfig(configBanc(quorumAuxSeuils: true), static function () use ($pdo): array {
        $du = membre($pdo, 'du-243');
        poserReputation($pdo, $du, 10);
        frapper($pdo, $du, membres($pdo, 'critique-243', 6), 1, '243');
        $etat = bilan($pdo, $du);
        $classer = Moderate::adminClasser($pdo, $du, idDe($pdo, 'arbitre-banc'), motif(), vuDe(signalDe($pdo, $du, 'perte_du_vote_due')));

        return [$etat['vote'], in_array('perte_du_vote_due', $etat['file'], true), $classer['ok'], str_contains($classer['message'], 'ne se classe pas')];
    });
} catch (Throwable $t243) {
    $r243 = ['exception' => get_class($t243) . ' : ' . $t243->getMessage()];
} finally {
    Moderate::setJournal($journalAvant243);
}
$r243 === [true, true, false, true]
    ? ok('⭐ en démonstration, la perte du vote due attend le journal et ne se classe pas')
    : nok('⭐ en démonstration, la perte du vote due se classe sans arrêter le retrait : ' . json_encode($r243, JSON_UNESCAPED_UNICODE));

// ── 244. ⭐ Au retrait automatique, une `\Error` du journal est contenue, et le ban qui suit nomme la perte due ─
// Comme au début d'un ban automatique, une `\Error` levée par le journal au retrait du
// vote est contenue comme un refus : le vote reste, la perte se signale due. Un journal
// qui refuse `vote_retire` et accepte `ban_debut` laisse la perte due jusqu'au
// plancher : le ban qui tombe la ferme, et son acte la nomme.
$erreur244 = new class implements Pierroons\SelfModerate\Journal {
    public function inscrire(array $acte): void
    {
        if ($acte['acte'] === 'vote_retire') {
            throw new \Error('défaut du journal');
        }
    }
};
$filtre244 = new class implements Pierroons\SelfModerate\Journal {
    /** @var list<array<string, mixed>> */
    public array $actes = [];

    public function inscrire(array $acte): void
    {
        if ($acte['acte'] === 'vote_retire') {
            throw new RuntimeException('acte inconnu de ce journal');
        }
        $this->actes[] = $acte;
    }
};
$journalAvant244 = Moderate::journal();
try {
    $r244 = sousConfig(configBanc(quorumAuxSeuils: true), static function () use ($pdo, $erreur244, $filtre244): array {
        Moderate::setJournal($erreur244);
        $du = membre($pdo, 'erreur-244');
        poserReputation($pdo, $du, 10);
        frapper($pdo, $du, membres($pdo, 'critique-244', 6), 1, '244');
        $etat = bilan($pdo, $du);
        Moderate::setJournal($filtre244);
        $chute = membre($pdo, 'chute-244');
        poserReputation($pdo, $chute, 7);
        frapper($pdo, $chute, membres($pdo, 'critique-244-ban', 7), 1, '244-ban');
        $debut = array_values(array_filter($filtre244->actes, static fn (array $a): bool => $a['acte'] === 'ban_debut' && $a['compte'] === $chute));

        return [
            'contenue' => [$etat['vote'], in_array('perte_du_vote_due', $etat['causes'], true)],
            'ferme'    => in_array('perte_du_vote_due', array_column(liste($debut[0]['ferme'] ?? null) ?? [], 'cause'), true),
        ];
    });
} catch (Throwable $t244) {
    $r244 = ['exception' => get_class($t244) . ' : ' . $t244->getMessage()];
} finally {
    Moderate::setJournal($journalAvant244);
}
$r244 === ['contenue' => [true, true], 'ferme' => true]
    ? ok('⭐ au retrait automatique, une \\Error du journal est contenue comme un refus, et le ban qui suit nomme la perte due qu\'il ferme')
    : nok('⭐ une \\Error du journal au retrait du vote remonte, ou le ban tait la perte due qu\'il ferme : ' . json_encode($r244, JSON_UNESCAPED_UNICODE));

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
foreach ([...$journal->actes, ...$journalC->actes, ...$journal145->actes] as $acteFin) {
    foreach ($acteFin as $cleFin => $valeurFin) {
        if ($valeurFin !== null && !is_scalar($valeurFin)) {
            $nonScalairesFin[] = ($acteFin['acte'] ?? '?') . '.' . $cleFin;
        }
    }
}
$nonScalairesFin === [] && count($journalC->actes) > 0
    ? ok('⭐ aucune valeur d\'acte n\'est une liste : un journal clé par clé n\'a rien à sérialiser')
    : nok('⭐ un acte porte une valeur non scalaire : ' . json_encode(array_values(array_unique($nonScalairesFin))));

// ── Fin 3. ⭐ Les actes portent les clés que la table du journal leur promet ─────
// `Journal.php` promet `sans_fin` à tout début de ban, `sans_fin` et `plancher` à
// toute levée. Tout acte porte `arbitre` et `arbitre_id`, null quand c'est la
// machine. Les actes relus sont ceux des journaux du banc, et chaque forme doit y
// figurer.
$manquesFin3 = [];
$vusFin3 = [];
foreach ([...$journal->actes, ...$journalC->actes, ...$journal145->actes] as $acteFin3) {
    $promisesFin3 = [...match ($acteFin3['acte'] ?? null) {
        'ban_debut' => ['sans_fin'],
        'ban_leve'  => ['sans_fin', 'plancher'],
        default     => [],
    }, 'arbitre', 'arbitre_id'];
    $formeFin3 = $acteFin3['acte'] . '/' . ($acteFin3['cause'] ?? $acteFin3['origine'] ?? '?');
    $vusFin3[$formeFin3] = true;
    foreach ($promisesFin3 as $cleFin3) {
        if (!array_key_exists($cleFin3, $acteFin3)) {
            $manquesFin3[] = "$formeFin3.$cleFin3";
        }
    }
}
$manquesFin3 === [] && isset($vusFin3['ban_debut/auto'], $vusFin3['ban_debut/admin'], $vusFin3['ban_leve/levee'], $vusFin3['ban_leve/grace'])
    ? ok('⭐ chaque acte porte les clés que la table du journal lui promet, l\'arbitre et son identifiant compris')
    : nok('⭐ un acte omet une clé que la table du journal lui promet : ' . json_encode([
        'manques' => array_values(array_unique($manquesFin3)), 'formes' => array_keys($vusFin3),
    ], JSON_UNESCAPED_UNICODE));

// ── Fin 4. ⭐ Chaque ban en cours a sa peine ouverte, et chaque peine ouverte son ban ─
// Un écrivain du moteur qui changerait l'état d'un ban sans tenir l'historique les
// séparerait. Tout ce que le banc a joué est relu, et les quatre fins y figurent.
$sansPeineFin4 = $pdo->query(
    'SELECT m.account_id FROM member_moderation m
      WHERE m.banned_until > 0
        AND NOT EXISTS (SELECT 1 FROM mod_peines p WHERE p.account_id = m.account_id AND p.fin IS NULL)'
)->fetchAll(PDO::FETCH_COLUMN);
$sansBanFin4 = $pdo->query(
    'SELECT p.account_id FROM mod_peines p JOIN member_moderation m ON m.account_id = p.account_id
      WHERE p.fin IS NULL AND m.banned_until = 0'
)->fetchAll(PDO::FETCH_COLUMN);
$finsFin4 = $pdo->query('SELECT DISTINCT fin_cause FROM mod_peines WHERE fin IS NOT NULL ORDER BY fin_cause')->fetchAll(PDO::FETCH_COLUMN);
$sansAuteurFin4 = $pdo->query("SELECT id FROM mod_peines WHERE origine = 'admin' AND reprise = 0 AND (arbitre_id IS NULL OR decide_par_id IS NULL)")
    ->fetchAll(PDO::FETCH_COLUMN);
$sansPeineFin4 === [] && $sansBanFin4 === [] && $sansAuteurFin4 === []
    && $finsFin4 === ['echeance', 'grace', 'hors_moteur', 'levee']
    ? ok('⭐ en fin de banc, chaque ban en cours a sa peine ouverte, chaque peine ouverte son ban, chaque ban d\'arbitre son auteur et celui de sa dernière décision, et les quatre fins de peine sont inscrites')
    : nok('⭐ l\'historique des peines et l\'état des bans se sont séparés : ' . json_encode([
        'bans_sans_peine' => $sansPeineFin4, 'peines_sans_ban' => $sansBanFin4, 'fins' => $finsFin4, 'sans_auteur' => $sansAuteurFin4,
    ]));

Moderate::setJournal($journalAvantC);

$total = $reussites + $echecs;
echo "\n" . ($echecs === 0
    ? "✅ $total/$total contrôles passés\n"
    : "❌ $echecs échec(s) sur $total\n");
exit($echecs === 0 ? 0 : 1);
