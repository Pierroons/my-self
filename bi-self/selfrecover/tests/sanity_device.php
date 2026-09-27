<?php

declare(strict_types=1);

/**
 * Sonde du facteur « cet appareil ».
 *
 * Le scénario 2 rejoue l'attaque du 02/08/2026 : enrôler sa propre clé sur le
 * compte d'autrui, puis signer. Elle doit échouer à la première étape. C'est le
 * seul contrôle de ce fichier qui ait déjà servi.
 *
 * Usage : php tests/sanity_device.php
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/StockageMemoire.php';

use Pierroons\SelfRecover\Crypto\Encoding;
use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\ProfilDeploiement;
use Pierroons\SelfRecover\Titulaire;
use Pierroons\SelfRecover\Device\Device;
use Pierroons\SelfRecover\Tests\StockageMemoire;

$passes = 0;
$echecs = 0;

function verifier(string $intitule, bool $condition, string $detail = ''): void
{
    global $passes, $echecs;
    $condition ? $passes++ : $echecs++;
    echo ($condition ? "  \u{2705} " : "  \u{274C} ") . $intitule . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/** Paire ECDSA P-256, comme WebCrypto en produit. */
function engendrerPaire(): array
{
    $cle = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $spki = base64_decode(implode('', array_slice(
        array_filter(explode("\n", openssl_pkey_get_details($cle)['key']), static fn ($l) => !str_contains($l, '-----')),
        0,
    )));

    return [$cle, Encoding::b64urlEncode($spki)];
}

/** Signature DER d'OpenSSL → P1363 (r||s), ce que rend WebCrypto. */
function signerP1363($clePrivee, string $message): string
{
    openssl_sign($message, $der, $clePrivee, OPENSSL_ALGO_SHA256);
    $o = 4;
    $lr = ord($der[3]);
    $r = ltrim(substr($der, $o, $lr), "\x00");
    $ls = ord($der[$o + $lr + 1]);
    $s = ltrim(substr($der, $o + $lr + 2, $ls), "\x00");

    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

$MOT = str_repeat('a1', 32);                  // 64 hexa = clé dérivée côté client
$SEL = 'sel-de-deploiement-pour-la-sonde';    // le sel qui fabrique les étiquettes
$now = 1_700_000_000;

// ── Scénario 1 : enrôlement légitime, puis récupération ────────────────────
$st = new StockageMemoire();
$st->comptes['alice'] = ['id' => 1, 'empreinte_mot' => Hashing::hash($MOT)];
$dev = new Device($st, ProfilDeploiement::CLEARWEB, $SEL, delaiRefusUs: 0);
[$privee, $publique] = engendrerPaire();
$credId = 'cred' . str_repeat('A', 20);

echo "\n→ Parcours nominal\n";
$r = $dev->enroler('alice', $credId, $publique, $MOT, Titulaire::AUTHENTIFIE, '192.0.2.1', $now);
verifier('enrôlement avec le bon mot', $r['ok'] === true);

$d = $dev->ouvrirDefi($credId, $now);
verifier('un défi est émis', ($d['ok'] ?? false) && strlen($d['challenge']) > 20);

$sig = Encoding::b64urlEncode(signerP1363($privee, $d['challenge']));
$f = $dev->cloreDefi($credId, $d['challenge'], $sig, $now);
verifier('signature valide → compte rendu', $f['ok'] === true && isset($f['mot_de_passe']));
verifier('les sessions ouvertes sont révoquées', $st->sessionsRevoquees === [1]);
verifier('le mot de passe stocké est bien celui rendu',
    Hashing::verify($f['mot_de_passe'], $st->empreintes[1] ?? ''));

// ── Scénario 2 : l'attaque du 02/08/2026 ───────────────────────────────────
echo "\n→ Prise de compte par enrôlement (02/08/2026)\n";
$st2 = new StockageMemoire();
$st2->comptes['victime'] = ['id' => 7, 'empreinte_mot' => Hashing::hash('bb' . str_repeat('cd', 31))];
$dev2 = new Device($st2, ProfilDeploiement::CLEARWEB, $SEL, delaiRefusUs: 0);
[$priveeAtt, $publiqueAtt] = engendrerPaire();

$att = $dev2->enroler('victime', 'cred' . str_repeat('B', 20), $publiqueAtt, $MOT, Titulaire::AUTHENTIFIE, '192.0.2.9', $now);
verifier('enrôlement refusé sans le mot mémorisé', $att['ok'] === false);
verifier('aucun appareil n\'a été posé sur le compte', $st2->appareils === []);
verifier('le mot de passe de la victime est intact', !isset($st2->empreintes[7]));

$inconnu = $dev2->enroler('nexiste-pas', 'cred' . str_repeat('C', 20), $publiqueAtt, $MOT, Titulaire::AUTHENTIFIE, '192.0.2.9', $now);
verifier('compte inconnu et mot faux rendent le même message',
    $inconnu['message'] === $att['message'], $att['message']);
verifier('la trace ne nomme pas le compte inexistant',
    !in_array('enroll:nexiste-pas', array_column($st2->tentatives, 'etiquette'), true));

// ── Scénario 3 : rejeu, expiration, signature étrangère ────────────────────
echo "\n→ Défis\n";
$d2 = $dev->ouvrirDefi($credId, $now);
$sig2 = Encoding::b64urlEncode(signerP1363($privee, $d2['challenge']));
$dev->cloreDefi($credId, $d2['challenge'], $sig2, $now);
$rejeu = $dev->cloreDefi($credId, $d2['challenge'], $sig2, $now);
verifier('un défi ne se rejoue pas', $rejeu['ok'] === false);

$d3 = $dev->ouvrirDefi($credId, $now);
$sig3 = Encoding::b64urlEncode(signerP1363($privee, $d3['challenge']));
$expire = $dev->cloreDefi($credId, $d3['challenge'], $sig3, $now + Device::DEFI_TTL + 1);
verifier('un défi expire', $expire['ok'] === false);

$d4 = $dev->ouvrirDefi($credId, $now);
$sigEtrangere = Encoding::b64urlEncode(signerP1363($priveeAtt, $d4['challenge']));
$mauvaise = $dev->cloreDefi($credId, $d4['challenge'], $sigEtrangere, $now);
verifier('une signature d\'un autre appareil est rejetée', $mauvaise['ok'] === false);

// ── Scénario 4 : formes refusées ───────────────────────────────────────────
echo "\n→ Entrées mal formées\n";
$clair = $dev->enroler('alice', 'cred' . str_repeat('D', 20), $publique, 'mon-mot-en-clair', Titulaire::AUTHENTIFIE, '192.0.2.1', $now);
verifier('un mot non dérivé est refusé', ($clair['error'] ?? '') === 'invalid_derived_key');
verifier('64 hexa sont acceptés comme clé dérivée', Device::estCleDerivee($MOT));
verifier('une chaîne trop courte ne l\'est pas', !Device::estCleDerivee('abcdef'));

// ── Scénario 5 : ce chemin ouvre le compte avec un seul secret ──────────────
echo "\n→ L'exigence du titulaire authentifié\n";
[$stT, $devT] = [new StockageMemoire(), null];
$stT->comptes['alice'] = ['id' => 1, 'empreinte_mot' => Hashing::hash($MOT)];
$devT = new Device($stT, ProfilDeploiement::CLEARWEB, $SEL, delaiRefusUs: 0);
[$privT, $pubT] = engendrerPaire();

$sans = $devT->enroler('alice', 'cred' . str_repeat('E', 20), $pubT, $MOT,
    Titulaire::NON_VERIFIE, '192.0.2.1', $now);
verifier('⭐ sans titulaire authentifié, l\'enrôlement est refusé',
    ($sans['error'] ?? '') === 'titulaire_non_authentifie', (string) ($sans['message'] ?? ''));
verifier('⭐ et rien n\'est enrôlé', $stT->appareils === []);
verifier('🔑 le refus ne trace rien : il précède tout calcul', $stT->tentatives === []);

// ── Scénario 6 : le frein par compte, là où l'adresse ne freine pas ─────────
echo "\n→ Le frein par compte de l'enrôlement\n";
// 🔑 SANS origine, sur un protocole qui le déclare : c'est la configuration où ce
// frein est le seul rempart, et la seule où un vert prouve que ce n'est pas le
// frein par adresse qui a refusé à sa place.
$stF  = new StockageMemoire();
$stF->comptes['alice'] = ['id' => 1, 'empreinte_mot' => Hashing::hash($MOT)];
$devF = new Device($stF, ProfilDeploiement::TOR_ONION, $SEL, delaiRefusUs: 0);
$FAUX = str_repeat('c3', 32);
for ($i = 0; $i < 5; $i++) {
    $devF->enroler('alice', 'cred' . str_repeat('F', 20), $pubT, $FAUX, Titulaire::AUTHENTIFIE, null, $now);
}
$freine = $devF->enroler('alice', 'cred' . str_repeat('G', 20), $pubT, $MOT,
    Titulaire::AUTHENTIFIE, null, $now);
verifier('⭐ au sixième essai, même le bon mot est freiné',
    $freine['ok'] === false && $freine['message'] === 'Trop de tentatives. Réessaie dans 15 minutes.',
    (string) $freine['message']);
verifier('⭐ et aucun appareil n\'a été enrôlé', $stF->appareils === []);
verifier('contre-témoin : hors de la fenêtre, le bon mot enrôle',
    ($devF->enroler('alice', 'cred' . str_repeat('H', 20), $pubT, $MOT,
        Titulaire::AUTHENTIFIE, null, $now + 901)['ok'] ?? false) === true);

$etiquettes = array_column($stF->tentatives, 'etiquette');
verifier('🔑 les échecs sont sous un HMAC, aucun sous le nom du compte',
    !in_array('alice', $etiquettes, true) && !in_array('enroll:alice', $etiquettes, true)
    && in_array($devF->etiquetteEchecsEnrolement('alice'), $etiquettes, true));

// 🔑 Le cas qui justifie le HMAC : la page de connexion écrit le nom soumis dans
// la même table. Une étiquette devinable serait un compteur que n'importe qui
// remplit, sans détenir aucun secret du compte visé.
$stI = new StockageMemoire();
$stI->comptes['alice'] = ['id' => 1, 'empreinte_mot' => Hashing::hash($MOT)];
$devI = new Device($stI, ProfilDeploiement::TOR_ONION, $SEL, delaiRefusUs: 0);
foreach (['enroll:alice', 'alice', 'enroll:' . hash('sha256', 'alice')] as $imitation) {
    for ($i = 0; $i < 6; $i++) { $stI->tracerTentative($imitation, false, null, $now); }
}
verifier('🔑 une étiquette imitée ne freine pas le compte visé',
    ($devI->enroler('alice', 'cred' . str_repeat('I', 20), $pubT, $MOT,
        Titulaire::AUTHENTIFIE, null, $now)['ok'] ?? false) === true);

// 🔑 L'oracle d'existence, mesuré puis fermé. L'étiquette tirée du compte TROUVÉ
// n'existait que pour les comptes réels : le frein ne mordait que sur eux, et le
// sixième essai rendait deux messages différents selon que le nom existe ou non.
// Six requêtes sur un nom choisi suffisaient — sur une méthode dont le message
// unique existe précisément pour qu'aucune n'y suffise.
$refusAuSixieme = static function (bool $existe) use ($MOT, $SEL, $pubT, $now): string {
    $st = new StockageMemoire();
    if ($existe) {
        $st->comptes['cible'] = ['id' => 1, 'empreinte_mot' => Hashing::hash($MOT)];
    }
    $dev  = new Device($st, ProfilDeploiement::TOR_ONION, $SEL, delaiRefusUs: 0);
    $faux = str_repeat('c3', 32);
    for ($i = 0; $i < 6; $i++) {
        $r = $dev->enroler('cible', 'cred' . str_repeat('J', 20), $pubT, $faux,
            Titulaire::AUTHENTIFIE, null, $now);
    }

    return (string) $r['message'];
};
verifier('🔑 au sixième essai, le refus est le MÊME que le compte existe ou non',
    $refusAuSixieme(true) === $refusAuSixieme(false),
    'existe : ' . $refusAuSixieme(true) . ' | inconnu : ' . $refusAuSixieme(false));

// La ligne tracée garde son origine : le frein par adresse continue de la voir.
$stJ = new StockageMemoire();
$devJ = new Device($stJ, ProfilDeploiement::CLEARWEB, $SEL, delaiRefusUs: 0);
$devJ->enroler('nexiste-pas', 'cred' . str_repeat('K', 20), $pubT, $MOT,
    Titulaire::AUTHENTIFIE, '192.0.2.4', $now);
verifier('un compte introuvable est tracé, avec son origine',
    count($stJ->tentatives) === 1 && $stJ->tentatives[0]['ip'] === '192.0.2.4');
verifier('🔑 et son étiquette ne nomme pas ce qui a été soumis',
    $stJ->tentatives[0]['etiquette'] === $devJ->etiquetteEchecsEnrolement('nexiste-pas')
    && !str_contains((string) $stJ->tentatives[0]['etiquette'], 'nexiste-pas'));

echo "\n" . str_repeat('=', 63) . "\n";
printf("  Device SelfRecover — %d passés, %d échoués\n", $passes, $echecs);
// Le compte est écrit ici et repris en intégration continue : un « N passés » dit
// que les cas joués ont réussi, jamais qu'aucun n'a disparu.
printf("OK — %d/%d\n", $passes, $passes + $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
