#!/usr/bin/env php
<?php
/**
 * Les questions du niveau 3 portent les noms que la page lit.
 *
 * 🔑 Ce banc existe parce que les deux côtés avaient divergé sans un mot : la
 * bibliothèque rend `cle` et `texte`, `public/dispute.php` lit `key`, `label`,
 * `type` et `options`. Aucun des quatre n'existait dans la réponse, et `esc()`
 * rend une chaîne vide sur `undefined` — donc trois libellés **vides**, trois
 * champs `id="q-undefined"`, `dataset.keys` à « ,, », et un formulaire qui
 * partait **sans aucune réponse**. Le faisceau arrivait systématiquement en
 * « diverge » devant l'arbitre, et rien ne le signalait : pas d'erreur, pas de
 * page blanche, juste trois étiquettes vides que personne ne regardait.
 *
 * Un contrat entre deux fichiers qui ne se connaissent pas a besoin d'un
 * contrôle qui les lise tous les deux. C'est ce que fait le dernier cas : il
 * extrait du JavaScript de la page les champs qu'elle consomme réellement, au
 * lieu de les recopier ici — une liste recopiée aurait le même défaut que le
 * code qu'elle garde.
 *
 * Usage : php tests/sanity_questions_l3.php
 */

declare(strict_types=1);

$dir = sys_get_temp_dir() . '/sanity_questions_l3_' . bin2hex(random_bytes(6));
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
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/recover_l3.php';

use Pierroons\MySelfLab\RecoverL3;
use Pierroons\SelfRecover\Recovery\Escalade;

$echecs = 0;
$reussites = 0;
function v(string $quoi, bool $vrai, string $detail = ''): void
{
    global $echecs, $reussites;
    if ($vrai) {
        echo "  ✓ $quoi\n";
        $reussites++;
    } else {
        fwrite(STDERR, "  ✗ $quoi" . ($detail !== '' ? " — $detail" : '') . "\n");
        $echecs++;
    }
}

$questions = RecoverL3::questions();

// ── 1. Le relais rend autant de questions que la bibliothèque ───────────────
echo "1. Rien n'est perdu en route\n";
$amont = Escalade::questions();
v('autant de questions qu\'en amont', count($questions) === count($amont),
    count($questions) . ' contre ' . count($amont));
v('aucun libellé vide', !array_filter($questions, fn($q) => trim((string) ($q['label'] ?? '')) === ''),
    'un libellé vide est le symptôme exact du défaut d\'origine');
v('aucune clé vide', !array_filter($questions, fn($q) => trim((string) ($q['key'] ?? '')) === ''));

// ── 2. Les clés sont celles que le faisceau compare ─────────────────────────
// Si une clé change en amont, les réponses arrivent sous un nom que le faisceau
// ne lit pas : il rend « diverge » sans que personne ne sache pourquoi.
echo "\n2. Les clés correspondent à l'amont\n";
$clesAmont = array_map(fn($q) => (string) $q['cle'], $amont);
$clesRelais = array_map(fn($q) => (string) $q['key'], $questions);
v('les clés sont reprises à l\'identique', $clesRelais === $clesAmont,
    implode(',', $clesRelais) . ' contre ' . implode(',', $clesAmont));

// ── 3. Les libellés sont ceux de l'amont ───────────────────────────────────
echo "\n3. Les libellés viennent de la bibliothèque\n";
$lblAmont = array_map(fn($q) => (string) $q['texte'], $amont);
$lblRelais = array_map(fn($q) => (string) $q['label'], $questions);
v('les libellés sont reprises à l\'identique', $lblRelais === $lblAmont);

// ── 4. La question à choix porte des options, et les bonnes ────────────────
// Le faisceau compare à « souvent », « parfois », « rare » (Escalade : >= 30,
// >= 5, sinon). Une option qui ne serait pas dans ces trois valeurs ferait
// diverger un titulaire qui répond juste.
echo "\n4. Les options du choix sont celles que le faisceau compare\n";
$freq = null;
foreach ($questions as $q) {
    if (($q['key'] ?? '') === 'frequence') {
        $freq = $q;
    }
}
v('la question de fréquence existe', $freq !== null);
if ($freq !== null) {
    v('elle est rendue comme un choix', ($freq['type'] ?? '') === 'select', (string) ($freq['type'] ?? '—'));
    v('ses options sont exactement souvent/parfois/rare',
        ($freq['options'] ?? []) === ['souvent', 'parfois', 'rare'],
        json_encode($freq['options'] ?? null, JSON_UNESCAPED_UNICODE));
}

// ── 5. Chaque question porte un type que la page sait rendre ───────────────
echo "\n5. Tous les types sont rendables\n";
$inconnus = array_filter($questions, fn($q) => !in_array($q['type'] ?? '', ['text', 'year', 'month', 'select'], true));
v('aucun type inconnu', $inconnus === [], json_encode(array_column($inconnus, 'type')));

// ── 6. 🔑 Le contrat avec la page, lu dans la page ─────────────────────────
// On n'énumère pas ici les champs attendus : on les EXTRAIT du JavaScript. Une
// liste recopiée porterait le même défaut que le code qu'elle garde.
echo "\n6. Les champs que la page consomme existent tous\n";
$js = file_get_contents(__DIR__ . '/../public/dispute.php');
$bloc = '';
if (preg_match('/questions\.map\(function\(q\)\{.*?\}\)\.join\(\x27\x27\);/s', $js, $m)) {
    $bloc = $m[0];
}
// Plus `dataset.keys`, hors du bloc map mais sur le même objet.
if (preg_match('/box\.dataset\.keys = questions\.map\([^\n]*/', $js, $m2)) {
    $bloc .= "\n" . $m2[0];
}
v('le bloc d\'affichage des questions a été retrouvé dans la page', $bloc !== '');
preg_match_all('/\bq\.([a-zA-Z_]+)/', $bloc, $m3);
$attendus = array_values(array_unique($m3[1]));
sort($attendus);
echo '     la page lit : ' . implode(', ', $attendus) . "\n";
$manquants = [];
foreach ($attendus as $champ) {
    // `options` n'existe que sur un `select` : on l'exige là où le type le dit.
    $requis = $champ === 'options'
        ? array_filter($questions, fn($q) => ($q['type'] ?? '') === 'select')
        : $questions;
    foreach ($requis as $q) {
        if (!array_key_exists($champ, $q)) {
            $manquants[] = $champ . ' sur « ' . ($q['key'] ?? '?') . ' »';
        }
    }
}
v('aucun champ lu par la page ne manque dans la réponse', $manquants === [],
    implode(' · ', $manquants));

// ── 7. 🔑 Chaque type déclaré est HONORÉ, pas seulement rendable ───────────
// Le cas 5 vérifie qu'un type est connu ; celui-ci qu'une branche le lit.
// `month` était déclaré et ignoré : la page en faisait un champ texte libre, le
// titulaire pouvait y écrire « 03/2026 » là où le faisceau compare « 2026-03 »
// (`Escalade.php:926`), et il divergeait en disant vrai. Un type que personne ne
// consomme ne fait aucun bruit — c'est le défaut du cas 6, un cran plus loin.
echo "\n7. Les types déclarés sont honorés par la page\n";
preg_match_all('/q\.type\s*===?\s*\x27([a-z]+)\x27/', $js, $m4);
$honores = array_values(array_unique($m4[1]));
sort($honores);
echo '     la page distingue : ' . implode(', ', $honores) . "\n";
$ignores = [];
foreach ($questions as $q) {
    $t = (string) ($q['type'] ?? '');
    // `text` est la branche par défaut : elle n'a aucun littéral à elle.
    if ($t !== '' && $t !== 'text' && !in_array($t, $honores, true)) {
        $ignores[] = $t . ' sur « ' . ($q['key'] ?? '?') . ' »';
    }
}
v('aucun type déclaré n\'est ignoré par la page', $ignores === [], implode(' · ', $ignores));

// Et l'option vide du menu : sans elle le navigateur retient la première valeur,
// et le faisceau porte une déclaration que le titulaire n'a pas faite.
v('le menu déroulant porte une option vide en tête',
    (bool) preg_match('/<option value=""[^>]*selected/', $js),
    'sans elle, « souvent » part comme si quelqu\'un l\'avait choisi');

// ── 7. 🔑 Le même contrat, pour la console d'arbitrage ──────────────────────
// La page du demandeur avait divergé de la bibliothèque sans un mot ; sa jumelle
// avait le même défaut et ce banc ne la regardait pas. `admin_disputes.php` lit
// la ligne de litige que rend `adminList()`, et `facts()` rend une chaîne vide
// sur une clé absente : l'arbitre voit alors l'avertissement « ce sont des faits
// bruts, jamais un score » au-dessus d'un bloc vide, et tranche sur rien.
//
// Les champs ne sont pas énumérés ici, ils sont extraits du bloc de rendu — même
// raison qu'au cas 6 : une liste recopiée porterait le défaut qu'elle garde.
echo "\n8. Les champs que la console d'arbitrage consomme existent tous\n";

$jsAdmin   = (string) file_get_contents(__DIR__ . '/../public/admin_disputes.php');
$blocAdmin = '';
if (preg_match('/disputes\.map\(function\(d\)\{.*?\}\)\.join\(/s', $jsAdmin, $mA)) {
    $blocAdmin = $mA[0];
}
v('le bloc de rendu des litiges a été retrouvé dans la console', $blocAdmin !== '');

preg_match_all('/\bd\.([a-zA-Z_]+)/', $blocAdmin, $mB);
$lus = array_values(array_unique($mB[1]));
sort($lus);
echo '     la console lit : ' . implode(', ', $lus) . "\n";

// Un dossier réel et SOUMIS : sans réponses il n'y aurait pas de faisceau, et le
// contrôle passerait sur un cas qui ne prouve rien.
$pdoA = \Pierroons\MySelfLab\Db::pdo();
$nomA = 'arbitre_contrat';
$pdoA->prepare(
    'INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at)
     VALUES (?, \'x\', \'x\', \'x\', ?)'
)->execute([$nomA, time()]);
$sesA = bin2hex(random_bytes(16));
$ouvA = RecoverL3::init($pdoA, $nomA, hash('sha256', $sesA), '203.0.113.77');
RecoverL3::submit($pdoA, (string) ($ouvA['dispute_number'] ?? ''), $sesA, ['mois_connexion' => '2026-05']);

$ligneA = null;
foreach ((RecoverL3::adminList($pdoA)['disputes'] ?? []) as $ligne) {
    if (($ligne['username'] ?? '') === $nomA) {
        $ligneA = $ligne;
        break;
    }
}
v('la console reçoit bien la ligne du dossier soumis', is_array($ligneA));

$absents = [];
foreach ($lus as $champ) {
    if (is_array($ligneA) && !array_key_exists($champ, $ligneA)) {
        $absents[] = $champ;
    }
}
v('⭐ aucun champ lu par la console ne manque dans la réponse', $absents === [],
    'absents : ' . implode(', ', $absents)
    . ' · la réponse porte : ' . implode(', ', array_keys($ligneA ?? [])));

// Le faisceau doit porter ses sections : c'est la seule chose sur laquelle
// l'arbitre a le droit de se fonder, et un bloc vide ne se distingue pas d'un
// dossier sans fait.
v('⭐ le faisceau du dossier porte ses sections',
    is_array($ligneA['faisceau'] ?? null)
    && array_key_exists('contexte', $ligneA['faisceau'])
    && array_key_exists('declaratif', $ligneA['faisceau']),
    json_encode(array_keys($ligneA['faisceau'] ?? [])));

echo "\n" . ($echecs === 0
    ? "OK — $reussites/$reussites contrôles conformes.\n"
    : "ÉCHEC — $echecs sur " . ($echecs + $reussites) . ".\n");
exit($echecs === 0 ? 0 : 1);
