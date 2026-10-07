#!/usr/bin/env php
<?php
/**
 * Les compteurs d'échecs de la console, et ce qu'ils doivent voir.
 *
 * `login_attempts` est partagée : la page de connexion y écrit le nom soumis tel
 * quel, et la bibliothèque y écrit ses propres compteurs. La console en écarte les
 * sondes de fréquence du niveau 3 — elles montent à chaque récupération légitime —
 * et doit garder tout le reste.
 *
 * 🔑 Deux familles comptent plus que les autres, et ce sont celles qu'on perd le
 * plus facilement : les échecs du niveau 2 et de l'enrôlement, et **les tentatives
 * sans étiquette**,
 * qui sont les codes de récupération introuvables. C'est le seul chemin qu'aucun
 * frein par compte ne couvre, donc le seul où la console est la dernière alarme.
 * Deux fois déjà, un filtre censé écarter les compteurs de la bibliothèque les a
 * emportées avec — la seconde fois par un `username != '…'`, qui ne rend pas
 * « vrai » sur une valeur absente.
 *
 * Usage : php tests/sanity_console_freins.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
putenv('LAB_SITESALT_PATH=' . sys_get_temp_dir() . '/lab-console-freins-sitesalt-' . getmypid());
register_shutdown_function(static fn () => @unlink((string) getenv('LAB_SITESALT_PATH')));
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/admin.php';
require_once __DIR__ . '/../lib/stats.php';

use Pierroons\MySelfLab\Admin;
use Pierroons\MySelfLab\Auth;
use Pierroons\MySelfLab\Stats;

$reussites = 0;
$echecs    = 0;

function verifier(string $intitule, bool $condition, string $detail = ''): void
{
    global $reussites, $echecs;
    $condition ? $reussites++ : $echecs++;
    echo ($condition ? '  ✅ ' : '  ❌ ') . $intitule . ($detail !== '' ? " — $detail" : '') . "\n";
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));
$now = time();
$ins = $pdo->prepare('INSERT INTO login_attempts (username, success, ip, attempted_at) VALUES (?, 0, ?, ?)');

// Le dernier champ dit si la console doit compter la ligne.
$lignes = [
    ['une connexion ratée',                        'alice',                        '192.0.2.1', true],
    ['un échec du niveau 2, sous son HMAC',        'l2:' . str_repeat('a', 64),    '192.0.2.2', true],
    ['un code de récupération introuvable',        null,                           '192.0.2.3', true],
    ['une sonde du niveau 3 (sans adresse)',       'l3:ouvrir:' . str_repeat('b', 64), null,    false],
    ["l'étiquette interne d'inscription",           '__register__',                 '192.0.2.4', false],
    ['un imposteur « l3:ouvrir:… » AVEC adresse',  'l3:ouvrir:x',                  '192.0.2.5', true],
    ['un échec d\'enrôlement, sous son HMAC',       'enroll:' . str_repeat('c', 64), '192.0.2.6', true],
    // 🔑 La SECONDE famille du niveau 3, et c'est elle qui manquait : le dépôt
    // de faisceau trace `l3:<nom de compte>` sans adresse (`Escalade.php:381`),
    // pas `l3:ouvrir:…`. Le filtre ne visait que la première, donc chaque dépôt
    // légitime comptait pour une attaque repoussée — et ce banc passait au vert
    // sans l'avoir jamais essayé.
    ['un dépôt de faisceau (sans adresse)',        'l3:ctf_gamma',                 null,        false],
    ['un imposteur « l3:… » AVEC adresse',         'l3:ctf_gamma',                 '192.0.2.7', true],
];
$attendus = 0;
foreach ($lignes as [$quoi, $nom, $ip, $compte]) {
    $ins->execute([$nom, $ip, $now]);
    $attendus += $compte ? 1 : 0;
}
echo "\n→ " . count($lignes) . " tentatives posées, $attendus doivent être comptées\n";

$console = (int) Admin::stats($pdo)['echecs_login_24h'];
verifier('le compteur de la console voit exactement celles-là', $console === $attendus,
    "$console comptée(s)");

$liste = Admin::failedLogins($pdo);
verifier('la liste affichée en montre autant', count($liste) === $attendus, count($liste) . ' ligne(s)');

// 🔑 Le libellé du code introuvable est posé à l'affichage. Stocké, il serait un
// nom qu'un compte peut porter, donc un compteur que n'importe qui remplit.
$libelles = array_column($liste, 'username');
verifier('une tentative sans étiquette est affichée avec un libellé, jamais vide',
    in_array('(code de récupération inconnu)', $libelles, true), implode(' · ', $libelles));
verifier("et ce libellé n'est pas en base",
    (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE username LIKE '(code%'")->fetchColumn() === 0);

// Le chiffre public n'écarte pas `__register__` : une inscription refusée par le
// frein EST une attaque repoussée. Il écarte les mêmes sondes du niveau 3.
$public = (int) Stats::lab($pdo)['repoussees'];
verifier('le chiffre public compte les mêmes, plus l\'inscription refusée',
    $public === $attendus + 1, "$public, attendu " . ($attendus + 1));

// ── Ce dont ces filtres DÉPENDENT, et que rien ne disait ─────────────────────
// 🔑 Écarter `l3:%` n'est sûr que parce qu'aucun compte ne peut porter un tel
// nom : `Auth::IDENTIFIANT` borne les identifiants à [a-z0-9_], sans deux-points.
// Si quelqu'un élargit ce jeu un jour — courriels, unicode, tiret —, ces filtres
// deviennent un trou : un compte nommé `l1:victime` disparaîtrait de la console
// avec ses échecs, et aucun autre contrôle ne rougirait. Ce cas attache donc la
// garde à ce qui la rend vraie. Relevé par la conv Recover le 07/10/2026.
foreach (['l3:x', 'l3:ouvrir:x', 'l1:victime', 'l1-liste:x', 'l2:x', 'enroll:x'] as $interdit) {
    $r = Auth::register($pdo, $interdit, str_repeat('a', 64), str_repeat('b', 32));
    verifier("l'inscription refuse « $interdit », dont le filtre dépend",
        ($r['ok'] ?? false) === false, json_encode($r));
}
// Et le symétrique : un nom licite passe, sinon le contrôle ci-dessus se
// contenterait d'un `register()` cassé pour tout le monde.
$r = Auth::register($pdo, 'nom_licite_7', str_repeat('a', 64), str_repeat('b', 32));
// ⚠️ On n'imprime PAS `$r` ici : un succès d'inscription porte mot de passe,
// passphrase et dix codes de récupération, et la sortie d'un banc part dans les
// journaux de la CI, qui sont publics. Même factices, ces valeurs n'ont rien à y
// faire — et un lecteur ne peut pas savoir qu'elles le sont.
verifier("mais un nom licite s'inscrit", ($r['ok'] ?? false) === true,
    'error : ' . (string) ($r['error'] ?? '—'));

$total = $reussites + $echecs;
echo "\n" . ($echecs === 0 ? "OK — $reussites/$total" : "ÉCHEC — $echecs sur $total") . "\n";
exit($echecs === 0 ? 0 : 1);
