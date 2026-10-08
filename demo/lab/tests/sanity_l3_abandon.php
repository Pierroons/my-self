#!/usr/bin/env php
<?php
/**
 * L'abandon d'une procédure de niveau 3 ne charge pas le compteur de gel.
 *
 * Ce qui est éprouvé ici est une asymétrie, et c'est elle qui compte : un refus
 * compte sur le compte VISÉ, pas sur le demandeur. Assez de dossiers ouverts
 * par un tiers et refusés par l'arbitre font donc remonter contre le titulaire
 * un compteur qu'il n'a pas rempli. L'abandon est la sortie prévue pour ce cas ;
 * ce banc vérifie qu'il l'est réellement.
 *
 * ⚠️ Ce compteur ne pose aucun gel : il le **suggère** à l'arbitre, qui décide
 * par `adminFreeze()`. Ce banc éprouve donc deux choses — « le refus signale et
 * ne gèle pas », puis « le geste de l'arbitre gèle et se lève ». L'invariant qui
 * fait exister ce fichier : un compteur remplissable par un tiers ne peut pas
 * porter une décision.
 *
 * Le seuil et la durée du gel ne sont pas écrits ici : ils sont lus par
 * `RecoverL3::reglesDuGel()`, parce que la bibliothèque les expose en défauts
 * de constructeur surchargeables — un banc qui les recopierait passerait au
 * vert sur un seuil qui a changé.
 *
 * Usage : php tests/sanity_l3_abandon.php
 */

declare(strict_types=1);

$dir = sys_get_temp_dir() . '/sanity_l3_abandon_' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir): void {
    foreach (glob("$dir/*") ?: [] as $f) {
        unlink($f);
    }
    @rmdir($dir);
});
putenv('LAB_DB_PATH=' . $dir . '/lab.db');
putenv('LAB_STATE_DIR=' . $dir);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/db.php';
// `RecoverL3::escalade()` lit le sel de site et les seuils de freinage chez `Auth`.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/recover_l3.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\RecoverL3;

$echecs = 0;
$reussites = 0;
function ok(string $m): void
{
    global $reussites;
    echo "  ✓ $m\n";
    $reussites++;
}
function nok(string $m): void
{
    global $echecs;
    fwrite(STDERR, "  ✗ $m\n");
    $echecs++;
}

$pdo = Db::pdo();

function creer(PDO $pdo, string $nom): int
{
    $pdo->prepare(
        'INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at)
         VALUES (?, \'x\', \'x\', \'x\', ?)'
    )->execute([$nom, time()]);

    return (int) $pdo->lastInsertId();
}

function gele(PDO $pdo, int $id): bool
{
    $r = $pdo->prepare('SELECT gele_jusqu_a FROM l3_gel WHERE account_id = ? AND degele_le IS NULL');
    $r->execute([$id]);
    $q = $r->fetchColumn();

    return $q !== false && (int) $q > time();
}

/** Un tiers ouvre un dossier : il fournit son propre sésame, le compte visé n'en sait rien. */
function ouvrirParUnTiers(PDO $pdo, string $nom, string $ip): ?string
{
    $sesame = bin2hex(random_bytes(16));
    $r = RecoverL3::init($pdo, $nom, hash('sha256', $sesame), $ip);

    return $r['dispute_number'] ?? null;
}

$regles = RecoverL3::reglesDuGel($pdo);
$seuil = (int) $regles['seuil'];
echo "Règles du gel lues dans la bibliothèque : seuil $seuil refus.\n\n";

// ── 1. Le refus signale, et ne gèle pas ──────────────────────────────────────
echo "1. " . $seuil . " dossiers ouverts par un tiers, puis REFUSÉS\n";
$victime = creer($pdo, 'cible_refus');
$dernier = [];
for ($i = 1; $i <= $seuil; $i++) {
    // Une IP par tour : le frein par origine n'est pas le sujet de ce banc.
    $num = ouvrirParUnTiers($pdo, 'cible_refus', '203.0.113.' . $i);
    if ($num === null) {
        nok("tour $i : l'ouverture a échoué, le banc ne peut pas conclure");
        break;
    }
    $dernier = RecoverL3::adminDecide($pdo, $num, 'refuse', 'arbitre');
}

// Un tiers remplit le compteur, et la porte reste ouverte.
gele($pdo, $victime)
    ? nok("après $seuil refus déposés par un TIERS, l'ouverture est gelée — un tiers "
        . 'ferme donc le dernier recours du titulaire')
    : ok("après $seuil refus, aucun gel posé — la porte du titulaire tient");

// Le signal, lui, doit remonter : sans lui l'arbitre ne verrait rien et le
// retrait du gel automatique se solderait par une perte sèche.
($dernier['gel_suggere'] ?? null) === true
    ? ok("le $seuil" . "ᵉ refus remonte `gel_suggere` — l'arbitre est informé, il décide")
    : nok('`gel_suggere` ne remonte pas au seuil : l\'arbitre a perdu le gel '
        . 'automatique sans recevoir le signal qui le remplace — '
        . json_encode($dernier['gel_suggere'] ?? null));

// Contre-témoin du signal : sous le seuil, rien ne doit être suggéré.
$sous = creer($pdo, 'cible_un_refus');
$numSous = ouvrirParUnTiers($pdo, 'cible_un_refus', '203.0.113.240');
$rSous = $numSous === null ? [] : RecoverL3::adminDecide($pdo, $numSous, 'refuse', 'arbitre');
($rSous['gel_suggere'] ?? null) === false
    ? ok('contre-témoin : un seul refus ne suggère rien')
    : nok('un seul refus suggère déjà le gel — le seuil ne sert à rien : '
        . json_encode($rSous['gel_suggere'] ?? null));

// ── 1 bis. Le geste de l'arbitre, lui, gèle — et se lève ─────────────────────
echo "\n1 bis. Le gel est désormais un geste d'arbitre\n";
$g = RecoverL3::adminFreeze($pdo, 'cible_refus', 'arbitre_nomme');
(($g['ok'] ?? false) === true) && gele($pdo, $victime)
    ? ok('`adminFreeze()` pose le gel — le geste existe et aboutit')
    : nok('`adminFreeze()` n\'a pas posé de gel : le gel automatique est retiré et '
        . 'rien ne le remplace, donc l\'arbitre est désarmé — ' . ($g['message'] ?? '?'));

// La trace de qui a fermé la porte : `poserGel()` ne reçoit pas d'auteur, donc
// c'est le lab qui la range — et sans contrôle elle repartirait au premier
// remaniement, comme celle de l'abandon avant elle.
$traceGel = $pdo->prepare(
    'SELECT gele_par FROM l3_gel WHERE account_id = (SELECT id FROM accounts WHERE username = ?)'
);
$traceGel->execute(['cible_refus']);
((string) ($traceGel->fetchColumn() ?: '')) === 'arbitre_nomme'
    ? ok('⭐ et la ligne dit QUI a gelé — pas « admin » pour tout le monde')
    : nok('le gel ne range pas son auteur : une porte fermée sans nom');

$l = RecoverL3::adminUnfreeze($pdo, 'cible_refus', 'arbitre_nomme');
(($l['ok'] ?? false) === true) && !gele($pdo, $victime)
    ? ok('et `adminUnfreeze()` le lève — la porte est rendue')
    : nok('le gel posé à la main ne se lève pas — ' . ($l['message'] ?? '?'));

// 🔑 Un compte inconnu ne doit pas se distinguer d'un compte sans gel par un
// succès : le chemin est réservé à un arbitre, mais il ne fabrique pas de
// compte au passage.
$inconnu = RecoverL3::adminFreeze($pdo, 'compte_qui_nexiste_pas', 'arbitre_nomme');
(($inconnu['ok'] ?? true) === false) && ($inconnu['code'] ?? 0) === 404
    ? ok('geler un compte inconnu est refusé en 404, sans rien créer')
    : nok('geler un compte inconnu ne rend pas un refus 404 : '
        . json_encode([$inconnu['ok'] ?? null, $inconnu['code'] ?? null]));

// ── 2. L'abandon ne l'arme pas ───────────────────────────────────────────────
echo "\n2. " . $seuil . " dossiers ouverts par un tiers, puis ABANDONNÉS\n";
$epargnee = creer($pdo, 'cible_abandon');
for ($i = 1; $i <= $seuil; $i++) {
    $num = ouvrirParUnTiers($pdo, 'cible_abandon', '198.51.100.' . $i);
    if ($num === null) {
        nok("tour $i : l'ouverture a échoué, le banc ne peut pas conclure");
        break;
    }
    $r = RecoverL3::adminAbandon($pdo, 'cible_abandon', 'arbitre');
    if (($r['ok'] ?? false) !== true) {
        nok("tour $i : l'abandon a été refusé — " . ($r['message'] ?? '?'));
    }
}
gele($pdo, $epargnee)
    ? nok("après $seuil abandons, l'ouverture est GELÉE : l'abandon charge le compteur de refus")
    : ok("après $seuil abandons, aucun gel — le titulaire garde sa voie de secours");

// ── 3. Après abandon, le titulaire peut rouvrir tout de suite ───────────────
echo "\n3. La place est bien libérée\n";
$num = ouvrirParUnTiers($pdo, 'cible_abandon', '198.51.100.200');
$num !== null
    ? ok('un nouveau dossier s\'ouvre après les abandons')
    : nok('plus aucun dossier ne s\'ouvre : l\'abandon n\'a pas libéré la place');

// ── 4. L'abandon ne décide rien ──────────────────────────────────────────────
echo "\n4. L'abandon n'accorde aucun accès\n";
$r = RecoverL3::adminAbandon($pdo, 'cible_abandon', 'arbitre');
$apres = $pdo->prepare('SELECT status FROM disputes WHERE account_id = ? ORDER BY id DESC LIMIT 1');
$apres->execute([$epargnee]);
$statut = (string) $apres->fetchColumn();
$statut !== 'accepted' && $statut !== 'accepte'
    ? ok("le dossier clos n'est pas au statut accepté (statut : $statut)")
    : nok("le dossier abandonné est au statut « $statut » : l'abandon vaut accord, ce qui ouvrirait le compte");

// ── 5. 🔑 L'abandon laisse une trace, et elle nomme son auteur ──────────────
// La trace de l'abandon est posée par le lab, pas par la bibliothèque
// (cf. `RecoverL3::adminAbandon`). Elle compte doublement ici : devant un dossier
// ouvert par un tiers, l'abandon est le seul geste qui ne punit pas le
// titulaire — donc celui qui se répète, donc celui dont l'arbitre suivant a
// besoin de connaître l'histoire.
echo "\n5. L'abandon laisse une trace nominative\n";
$numTrace = ouvrirParUnTiers($pdo, 'cible_abandon', '198.51.100.201');
$r = RecoverL3::adminAbandon($pdo, 'cible_abandon', 'arbitre_zeta');
$tr = $pdo->prepare('SELECT abandonne_par, abandonne_le FROM disputes WHERE dispute_number = ?');
$tr->execute([(string) $numTrace]);
$ligne = $tr->fetch(PDO::FETCH_ASSOC) ?: [];
((string) ($ligne['abandonne_par'] ?? '')) === 'arbitre_zeta'
    ? ok('le nom de l\'arbitre qui abandonne est rangé')
    : nok('aucun auteur rangé sur le dossier abandonné : obtenu '
        . json_encode($ligne['abandonne_par'] ?? null));
((int) ($ligne['abandonne_le'] ?? 0)) > 0
    ? ok('et la date de l\'abandon avec lui')
    : nok('abandonne_le vaut ' . json_encode($ligne['abandonne_le'] ?? null));

// La console la LIT : une colonne que la liste ne sélectionne pas est une trace
// que l'arbitre suivant ne verra jamais.
$liste = RecoverL3::adminList($pdo)['disputes'] ?? [];
$vu = null;
foreach ($liste as $d) {
    if (($d['dispute_number'] ?? '') === (string) $numTrace) {
        $vu = $d;
    }
}
is_array($vu) && array_key_exists('abandonne_par', $vu)
    ? ok('la console d\'arbitrage porte la trace dans sa liste')
    : nok('la liste de la console ne porte pas « abandonne_par » : la trace est écrite et invisible');

// ── 6. Un compte sans procédure refuse l'abandon ─────────────────────────────
echo "\n6. Refus attendus\n";
creer($pdo, 'sans_dossier');
$r = RecoverL3::adminAbandon($pdo, 'sans_dossier', 'arbitre');
($r['ok'] ?? true) === false && (int) ($r['code'] ?? 0) === 409
    ? ok('aucune procédure en cours → 409')
    : nok('aucune procédure en cours : attendu un refus 409, obtenu ' . json_encode($r));

$r = RecoverL3::adminAbandon($pdo, 'personne_de_ce_nom', 'arbitre');
($r['ok'] ?? true) === false && (int) ($r['code'] ?? 0) === 404
    ? ok('compte inconnu → 404')
    : nok('compte inconnu : attendu un refus 404, obtenu ' . json_encode($r));

echo "\n" . ($echecs === 0
    ? "OK — $reussites/$reussites contrôles conformes.\n"
    : "ÉCHEC — $echecs contrôle(s) en échec sur " . ($echecs + $reussites) . ".\n");
exit($echecs === 0 ? 0 : 1);
