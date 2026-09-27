#!/usr/bin/env php
<?php
/**
 * Le sel que `/api/sel.php` rend à une session est celui du compte connecté.
 *
 * L'enrôlement d'un appareil dérive le mot mémorisé avec ce sel, puis le serveur
 * compare le résultat à l'empreinte posée à l'inscription. Un autre sel produit
 * une autre empreinte : l'enrôlement répond « Compte ou mot mémorisé incorrect »
 * à qui a tapé le bon mot. `sanity_device_client.js` simule le serveur et ne
 * peut pas le voir ; ce banc passe par `Auth` et `Device`, comme les endpoints.
 *
 * Usage : php tests/sanity_sel_session.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/derive_cli.php';
putenv('LAB_SITESALT_PATH=' . sys_get_temp_dir() . '/lab-sel-session-sitesalt-' . getmypid());
register_shutdown_function(static fn () => @unlink((string) getenv('LAB_SITESALT_PATH')));
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/StockageSelfRecover.php';
require_once __DIR__ . '/../lib/device.php';

use Pierroons\MySelfLab\Auth;
use Pierroons\MySelfLab\Device;

$reussites = 0;
$echecs = 0;
function verifier(string $intitule, bool $condition, string $detail = ''): void
{
    global $reussites, $echecs;
    $condition ? $reussites++ : $echecs++;
    echo ($condition ? '  ✓ ' : '  ✗ ') . $intitule . ($detail !== '' ? " — $detail" : '') . "\n";
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));

$HOTE = 'ctf.exemple.test';
$MOT  = 'mot-memorise-du-banc';
$sel  = sr_sel_aleatoire();
$ins  = Auth::register($pdo, 'banc_sel', sr_derive_like_browser($MOT, $sel, $HOTE), $sel, '192.0.2.10');
verifier("l'inscription réussit et ouvre une session", ($ins['ok'] ?? false) && ($ins['token'] ?? '') !== '');

echo "\n→ Sans session\n";
$_COOKIE = [];
$repli = Auth::selDeDerivation($pdo, '');
verifier('un sel de repli, jamais celui du compte', $repli !== $sel && preg_match('/^[0-9a-f]{32}$/', $repli) === 1);

echo "\n→ Avec la session ouverte par l'inscription\n";
$_COOKIE = ['lab_session' => (string) $ins['token']];
$rendu = Auth::selDeDerivation($pdo, '');
verifier('le sel rendu est celui du compte', $rendu === $sel, "rendu $rendu, attendu $sel");

// Le parcours du navigateur : dériver avec le sel rendu, puis enrôler.
$cle = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$pem = openssl_pkey_get_details($cle)['key'];
$spki = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem));
$b64u = rtrim(strtr(base64_encode($spki), '+/', '-_'), '=');
$r = Device::enroll($pdo, 'banc_sel', bin2hex(random_bytes(16)), $b64u, sr_derive_like_browser($MOT, $rendu, $HOTE), '192.0.2.1');
verifier("l'enrôlement accepte le bon mot", ($r['ok'] ?? false) === true, (string) ($r['message'] ?? ''));

$r = Device::enroll($pdo, 'banc_sel', bin2hex(random_bytes(16)), $b64u, sr_derive_like_browser('autre-mot', $rendu, $HOTE), '192.0.2.1');
verifier("l'enrôlement refuse un autre mot", ($r['ok'] ?? true) === false);

$total = $reussites + $echecs;
echo "\n" . ($echecs === 0 ? "OK — $reussites/$total" : "ÉCHEC — $echecs sur $total") . "\n";
exit($echecs === 0 ? 0 : 1);
