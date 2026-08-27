<?php

declare(strict_types=1);

/**
 * La bibliothèque tient-elle sur le schéma réel du lab ?
 *
 * Les sondes de `bi-self/selfrecover` tournent sur un stockage en mémoire :
 * elles prouvent que le protocole est correct, pas que l'adaptateur du lab
 * l'est. Ce fichier monte une base SQLite depuis `schema.sql`, la peuple comme
 * l'application le fait, et fait tourner la bibliothèque dessus.
 *
 * Il précède la bascule : remplacer du code servi sans cette preuve reviendrait
 * à supposer l'équivalence plutôt qu'à la vérifier.
 *
 * Usage : php tests/equivalence_selfrecover.php
 */

require __DIR__ . '/../vendor/autoload.php';
// `sr_sel_aleatoire()` — le miroir de `srEngendrerSel()` pour ce qui n'a pas de
// navigateur. Le sel est exigé depuis le 27/08 : sans lui, l'inscription refuse.
require_once __DIR__ . '/../lib/derive_cli.php';
require __DIR__ . '/../lib/StockageSelfRecover.php';

use Pierroons\MySelfLab\StockageSelfRecover;
use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Device\Device;
use Pierroons\SelfRecover\Recovery\Recovery;

$passes = 0;
$echecs = 0;

function verifier(string $intitule, bool $condition, string $detail = ''): void
{
    global $passes, $echecs;
    $condition ? $passes++ : $echecs++;
    echo ($condition ? "  \u{2705} " : "  \u{274C} ") . $intitule . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));

$MOT = str_repeat('a1', 32);
$PHR = 'cheval agrafe batterie correct';
$now = 1_700_000_000;

$pdo->prepare(
    'INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at) VALUES (?, ?, ?, ?, ?)'
)->execute(['alice', Hashing::hash('mdp-initial'), Hashing::hash($PHR), Hashing::hash($MOT), $now]);
$compteId = (int) $pdo->lastInsertId();

$stockage = new StockageSelfRecover($pdo);
$device   = new Device($stockage, delaiRefusUs: 0);
$recovery = new Recovery($stockage, 'sel-du-lab-pour-la-sonde', delaiRefusUs: 0);

echo "\n→ Niveau 1 sur le schéma réel\n";
$r = $recovery->parPassphrase('alice', $PHR, '10.0.0.1', $now);
verifier('la passphrase rend l\'accès', $r['ok'] === true);
$st = $pdo->query('SELECT pw_hash, pass_hash FROM accounts WHERE id = ' . $compteId)->fetch(PDO::FETCH_ASSOC);
verifier('la colonne pw_hash porte le mot de passe rendu', Hashing::verify($r['mot_de_passe'], $st['pw_hash']));
verifier('la colonne pass_hash porte la passphrase neuve', Hashing::verify($r['passphrase'], $st['pass_hash']));

echo "\n→ Niveau 2 sur le schéma réel\n";
$codes = $recovery->emettreCodes($compteId, 10, $now);
verifier('les codes sont écrits dans recovery_codes',
    (int) $pdo->query('SELECT COUNT(*) FROM recovery_codes')->fetchColumn() === 10);
$r2 = $recovery->parCode($codes[0], $MOT, '10.0.0.2', $now);
verifier('code et mot rendent l\'accès', $r2['ok'] === true, $r2['compte'] ?? '');
verifier('le code est marqué consommé',
    (int) $pdo->query('SELECT COUNT(*) FROM recovery_codes WHERE used = 1')->fetchColumn() === 1);

echo "\n→ Appareil sur le schéma réel\n";
$cle  = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$spki = base64_decode(implode('', array_filter(
    explode("\n", openssl_pkey_get_details($cle)['key']),
    static fn ($l) => !str_contains($l, '-----'),
)));
$pub = rtrim(strtr(base64_encode($spki), '+/', '-_'), '=');
$credId = 'cred' . str_repeat('E', 20);

$e = $device->enroler('alice', $credId, $pub, $MOT, '10.0.0.3', $now);
verifier('enrôlement écrit dans device_credentials',
    $e['ok'] === true && (int) $pdo->query('SELECT COUNT(*) FROM device_credentials')->fetchColumn() === 1);

$d = $device->ouvrirDefi($credId, $now);
verifier('le défi est écrit dans device_challenges',
    (int) $pdo->query('SELECT COUNT(*) FROM device_challenges')->fetchColumn() === 1);

openssl_sign($d['challenge'], $der, $cle, OPENSSL_ALGO_SHA256);
$lr = ord($der[3]);
$rr = ltrim(substr($der, 4, $lr), "\x00");
$ls = ord($der[4 + $lr + 1]);
$ss = ltrim(substr($der, 4 + $lr + 2, $ls), "\x00");
$sig = rtrim(strtr(base64_encode(
    str_pad($rr, 32, "\x00", STR_PAD_LEFT) . str_pad($ss, 32, "\x00", STR_PAD_LEFT)
), '+/', '-_'), '=');

$f = $device->cloreDefi($credId, $d['challenge'], $sig, $now);
verifier('signature valide → accès rendu', $f['ok'] === true);
verifier('le défi est consommé',
    (int) $pdo->query('SELECT COUNT(*) FROM device_challenges')->fetchColumn() === 0);

echo "\n→ L'attaque du 02/08 sur le schéma réel\n";
$att = $device->enroler('alice', 'cred' . str_repeat('F', 20), $pub, str_repeat('99', 32), '10.0.0.9', $now);
verifier('enrôlement sans le mot mémorisé refusé', $att['ok'] === false);
verifier('aucun appareil supplémentaire posé',
    (int) $pdo->query('SELECT COUNT(*) FROM device_credentials')->fetchColumn() === 1);

echo "\n→ Contraintes du schéma\n";
verifier('les clés étrangères sont actives',
    (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1);
verifier('les tentatives sont tracées',
    (int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn() > 0);

echo "\n→ Parcours complet par la façade Auth\n";
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$pdo2 = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo2->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));

$insc = \Pierroons\MySelfLab\Auth::register($pdo2, 'bob', $MOT, sr_sel_aleatoire(), '10.0.0.1');
verifier('inscription : dix codes remis une fois',
    ($insc['ok'] ?? false) && count($insc['credentials']['recovery_codes'] ?? []) === 10);

$niv2 = \Pierroons\MySelfLab\Auth::recoverByCode($pdo2, $insc['credentials']['recovery_codes'][0], $MOT, '10.0.0.2');
verifier('niveau 2 : code consommé, neuf restants', ($niv2['codes_restants'] ?? -1) === 9);
verifier('niveau 2 : la forme du retour est préservée',
    isset($niv2['credentials']['password'], $niv2['credentials']['passphrase'], $niv2['note']));

$niv1 = \Pierroons\MySelfLab\Auth::recoverByPassphrase($pdo2, 'bob', $niv2['credentials']['passphrase'], '10.0.0.3');
verifier('niveau 1 : la passphrase rendue au niveau 2 fonctionne', ($niv1['ok'] ?? false) === true);

$conn = \Pierroons\MySelfLab\Auth::login($pdo2, 'bob', $niv1['credentials']['password'], '10.0.0.4');
verifier('connexion avec le mot de passe rendu', ($conn['ok'] ?? false) === true);

echo "\n→ Le sel exigé à l'inscription — les cas de refus\n";
// 🔑 Ces contrôles existent parce que la garde n'en avait aucun : elle était
// lue, pas mesurée. Un `preg_match` que personne ne fait jamais échouer ne se
// distingue pas d'une ligne absente. Chacun des quatre cas ci-dessous a été
// vu rougir en retirant la garde de `Auth::register`.
//
// ⚠️ Ils ne gardent que CETTE couche. Le défaut réellement rencontré le
// 27/08/2026 vivait un étage plus haut : `public/api/register.php` et
// `public/api/recover_l3_reset.php` normalisaient le sel en minuscules AVANT
// d'appeler la garde, si bien que le cas « en majuscules » ci-dessous n'y était
// jamais atteint. Ces quatre contrôles restaient verts.
//
// La route est éprouvée à part, en HTTP, avec `LAB_DB_PATH` sur une base neuve
// — sans quoi le quota d'inscriptions par IP rend des refus qu'on prend pour
// ceux de la garde. Le canari y rougit sur le seul cas « majuscules ».
$avant = (int) $pdo2->query('SELECT COUNT(*) FROM accounts')->fetchColumn();

$refus = [
    'vide'              => '',
    'trop court'        => str_repeat('a', 16),
    'non hexadécimal'   => str_repeat('a', 31) . 'z',
    'en majuscules'     => strtoupper(sr_sel_aleatoire()),
];
$n = 0;
foreach ($refus as $intitule => $sel) {
    $r = \Pierroons\MySelfLab\Auth::register($pdo2, 'refus' . (++$n), $MOT, $sel, null);
    verifier("sel {$intitule} : inscription refusée",
        ($r['ok'] ?? true) === false && ($r['error'] ?? '') === 'invalid_salt',
        'error=' . ($r['error'] ?? 'aucune'));
}

// Le refus doit être total : rendre `ok => false` tout en ayant inséré la
// ligne laisserait un compte dont le mot mémorisé n'est dérivable par personne.
verifier('aucun compte créé par les quatre refus',
    (int) $pdo2->query('SELECT COUNT(*) FROM accounts')->fetchColumn() === $avant);

// Contre-témoin : sans lui, une garde qui refuserait TOUT rendrait les cinq
// contrôles ci-dessus verts. Un faux rouge tue une sonde autant qu'un faux vert.
$ok = \Pierroons\MySelfLab\Auth::register($pdo2, 'carol', $MOT, sr_sel_aleatoire(), null);
verifier('contre-témoin : un sel valide passe toujours', ($ok['ok'] ?? false) === true);

echo "\n→ Le sel de site refuse de servir vide\n";
// 🔑 Ce sel porte deux propriétés : il localise les codes émis et il fabrique
// le faux sel rendu aux codes inconnus. Vide, les index deviennent calculables
// et l'oracle du sel se rouvre — sans qu'aucune erreur ne survienne. Le
// répertoire absent suffisait à produire ce cas, observé le 27/08/2026.
$tmp = sys_get_temp_dir() . '/lab-sitesalt-sonde-' . getmypid();
putenv('LAB_SITESALT_PATH=' . $tmp);
try {
    foreach (['vide' => '', 'tronqué' => 'abcd'] as $intitule => $contenu) {
        file_put_contents($tmp, $contenu);
        $leve = false;
        try { \Pierroons\MySelfLab\Auth::siteSalt(); } catch (\RuntimeException $e) { $leve = true; }
        verifier("sel de site {$intitule} : refus de servir", $leve);
    }
    // Contre-témoin : une garde qui lèverait toujours passerait les deux
    // contrôles ci-dessus sans rien mesurer.
    unlink($tmp);
    $engendre = \Pierroons\MySelfLab\Auth::siteSalt();
    verifier('sel de site absent : engendré, et de longueur pleine', strlen($engendre) === 64);
    verifier('sel de site stable entre deux appels',
        $engendre === \Pierroons\MySelfLab\Auth::siteSalt());
} finally {
    putenv('LAB_SITESALT_PATH');
    @unlink($tmp);
}

echo "\n" . str_repeat('=', 63) . "\n";
printf("  Équivalence lab ⨯ SelfRecover — %d passés, %d échoués\n", $passes, $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
