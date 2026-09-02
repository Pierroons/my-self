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

$MOT = str_repeat('a1', 32);                    // 64 hexa = clé dérivée côté client
$now = 1_700_000_000;

// ── Scénario 1 : enrôlement légitime, puis récupération ────────────────────
$st = new StockageMemoire();
$st->comptes['alice'] = ['id' => 1, 'empreinte_mot' => Hashing::hash($MOT)];
$dev = new Device($st, delaiRefusUs: 0);
[$privee, $publique] = engendrerPaire();
$credId = 'cred' . str_repeat('A', 20);

echo "\n→ Parcours nominal\n";
$r = $dev->enroler('alice', $credId, $publique, $MOT, '192.0.2.1', $now);
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
$dev2 = new Device($st2, delaiRefusUs: 0);
[$priveeAtt, $publiqueAtt] = engendrerPaire();

$att = $dev2->enroler('victime', 'cred' . str_repeat('B', 20), $publiqueAtt, $MOT, '192.0.2.9', $now);
verifier('enrôlement refusé sans le mot mémorisé', $att['ok'] === false);
verifier('aucun appareil n\'a été posé sur le compte', $st2->appareils === []);
verifier('le mot de passe de la victime est intact', !isset($st2->empreintes[7]));

$inconnu = $dev2->enroler('nexiste-pas', 'cred' . str_repeat('C', 20), $publiqueAtt, $MOT, '192.0.2.9', $now);
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
$clair = $dev->enroler('alice', 'cred' . str_repeat('D', 20), $publique, 'mon-mot-en-clair', null, $now);
verifier('un mot non dérivé est refusé', ($clair['error'] ?? '') === 'invalid_derived_key');
verifier('64 hexa sont acceptés comme clé dérivée', Device::estCleDerivee($MOT));
verifier('une chaîne trop courte ne l\'est pas', !Device::estCleDerivee('abcdef'));

echo "\n" . str_repeat('=', 63) . "\n";
printf("  Device SelfRecover — %d passés, %d échoués\n", $passes, $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
