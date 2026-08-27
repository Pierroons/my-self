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

echo "\n" . str_repeat('=', 63) . "\n";
printf("  Équivalence lab ⨯ SelfRecover — %d passés, %d échoués\n", $passes, $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
