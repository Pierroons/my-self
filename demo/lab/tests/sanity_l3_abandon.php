#!/usr/bin/env php
<?php
/**
 * L'abandon d'une procédure de niveau 3 ne charge pas le compteur de gel.
 *
 * Ce qui est éprouvé ici est une asymétrie, et c'est elle qui compte : un refus
 * compte sur le compte VISÉ, pas sur le demandeur. Assez de dossiers ouverts
 * par un tiers et refusés par l'arbitre gèlent donc l'ouverture au titulaire,
 * qui perd sa dernière voie de secours sans avoir rien fait. L'abandon est la
 * sortie prévue pour ce cas ; ce banc vérifie qu'il l'est réellement.
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

// ── 1. Le refus arme bien le gel ─────────────────────────────────────────────
echo "1. " . $seuil . " dossiers ouverts par un tiers, puis REFUSÉS\n";
$victime = creer($pdo, 'cible_refus');
for ($i = 1; $i <= $seuil; $i++) {
    // Une IP par tour : le frein par origine n'est pas le sujet de ce banc.
    $num = ouvrirParUnTiers($pdo, 'cible_refus', '203.0.113.' . $i);
    if ($num === null) {
        nok("tour $i : l'ouverture a échoué, le banc ne peut pas conclure");
        break;
    }
    RecoverL3::adminDecide($pdo, $num, 'refuse', 'arbitre');
}
gele($pdo, $victime)
    ? ok("après $seuil refus, l'ouverture est gelée — le compteur fonctionne")
    : nok("après $seuil refus, AUCUN gel : le compteur de gel ne fonctionne plus, "
        . 'et la moitié 2 de ce banc deviendrait vide de sens');

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

// ── 5. Un compte sans procédure refuse l'abandon ─────────────────────────────
echo "\n5. Refus attendus\n";
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
