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

// La base et les quatre secrets de ce banc, à lui seul — jamais ceux de `data/`.
require __DIR__ . '/instance_isolee.inc';

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/derive_cli.php';
putenv('LAB_SITESALT_PATH=' . chemin_isole('.sitesalt-session'));
register_shutdown_function(static fn () => @unlink((string) getenv('LAB_SITESALT_PATH')));
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/StockageSelfRecover.php';
require_once __DIR__ . '/../lib/device.php';

use Pierroons\MySelfLab\Auth;
use Pierroons\MySelfLab\Device;
use Pierroons\MySelfLab\StockageSelfRecover;
use Pierroons\SelfRecover\Langue;
use Pierroons\SelfRecover\ProfilDeploiement;
use Pierroons\SelfRecover\Recovery\Escalade;
use Pierroons\SelfRecover\Recovery\Recovery;

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

// Après un niveau 3, les codes du titulaire passent dans les anciens codes de la
// fenêtre, et son mot reste dérivé sous le sel d'avant. Une route qui ne cherchait
// que le lot en place lui rendait un faux sel : son retour par code ne pouvait pas
// aboutir, et aucun banc ne faisait le parcours du navigateur jusque-là.
echo "\n→ Après un niveau 3, le titulaire revient par un code d'avant\n";
$_COOKIE  = [];
$MOT_AVANT = 'mot-memorise-du-titulaire';
$selAvant = sr_sel_aleatoire();
Auth::register($pdo, 'banc_retour', sr_derive_like_browser($MOT_AVANT, $selAvant, $HOTE), $selAvant, '192.0.2.20');
$idRetour = (int) $pdo->query("SELECT id FROM accounts WHERE username = 'banc_retour'")->fetchColumn();
$codesAvant = Auth::generateRecoveryCodes($pdo, $idRetour);
// Le niveau 3 monté comme `RecoverL3::escalade()`, l'horloge reculée d'une heure.
$stockage = new StockageSelfRecover($pdo);
$escalade = new Escalade($stockage, new Recovery($stockage, Auth::siteSalt(), ProfilDeploiement::CLEARWEB, Langue::FR,
    delaiRefusUs: 0), delaiRefusUs: 0);
$t      = time() - 3600;
$sesame = bin2hex(random_bytes(32));
$dossier = $escalade->ouvrir('banc_retour', Escalade::empreinteSesame($sesame), '192.0.2.21', $t);
$escalade->trancher((string) $dossier['numero'], 'accepte', 'arbitre', $t + 10);
$selL3 = sr_sel_aleatoire();
$reprise = $escalade->reEnroler((string) $dossier['numero'], $sesame, 'mot de passe du demandeur',
    sr_derive_like_browser('mot du demandeur', $selL3, $HOTE), $selL3, $t + 20);

$renduAvant = Auth::selDeDerivation($pdo, $codesAvant[0]);
verifier("⭐ la route rend le sel d'avant pour un code d'avant", $renduAvant === $selAvant, "rendu $renduAvant, attendu $selAvant");
verifier('et le sel du demandeur pour son propre lot', Auth::selDeDerivation($pdo, (string) $reprise['codes'][0]) === $selL3);

$avertissements = [];
set_error_handler(static function (int $n, string $m) use (&$avertissements): bool {
    $avertissements[] = $m;

    return true;
});
$retour = Auth::recoverByCode($pdo, $codesAvant[0], sr_derive_like_browser($MOT_AVANT, $renduAvant, $HOTE), '192.0.2.22');
restore_error_handler();
verifier("⭐ le parcours du navigateur rend le compte à son titulaire", ($retour['ok'] ?? false) === true
    && ($retour['username'] ?? '') === 'banc_retour', (string) ($retour['message'] ?? ''));
verifier("⭐ la réponse porte le lot neuf et le message de la bibliothèque, sans avertissement",
    count($retour['codes'] ?? []) === Recovery::CODES_PAR_LOT && ($retour['note'] ?? '') !== '' && $avertissements === [],
    implode(' · ', $avertissements));
verifier("le lot neuf est celui du compte : il rend le sel d'avant",
    Auth::selDeDerivation($pdo, (string) ($retour['codes'][0] ?? '')) === $selAvant);

$total = $reussites + $echecs;
echo "\n" . ($echecs === 0 ? "OK — $reussites/$total" : "ÉCHEC — $echecs sur $total") . "\n";
exit($echecs === 0 ? 0 : 1);
