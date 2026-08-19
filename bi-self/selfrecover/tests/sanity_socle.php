<?php

declare(strict_types=1);

/**
 * Sonde du socle SelfRecover — les briques sans stockage.
 *
 * Ce sont des contrôles de garde-fou, pas des tests fonctionnels : rien ne
 * casse quand ils échouent. Un profil de hachage désaligné de son hash factice
 * ne lève aucune erreur, il rend seulement le temps de réponse bavard.
 *
 * Usage : php tests/sanity_socle.php
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfRecover\Crypto\Encoding;
use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Diceware\Wordlist;

$passes = 0;
$echecs = 0;

function verifier(string $intitule, bool $condition, string $detail = ''): void
{
    global $passes, $echecs;
    if ($condition) {
        $passes++;
        echo "  \u{2705} {$intitule}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    } else {
        $echecs++;
        echo "  \u{274C} {$intitule}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

echo "\n→ Profil de hachage\n";
verifier('le hash factice porte le profil ARGON2 courant', Hashing::dummyHashSuitProfil());
verifier('le profil est celui du projet (64 MiB, t=4, p=2)',
    Hashing::ARGON2 === ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2]);
verifier('aucun secret ne valide contre le hash factice',
    !Hashing::verify('', Hashing::dummyHash())
    && !Hashing::verify('motdepasse', Hashing::dummyHash())
    && !Hashing::verify(Hashing::dummyHash(), Hashing::dummyHash()));

$debut = hrtime(true);
Hashing::verify('secret-quelconque', Hashing::dummyHash());
$coutFactice = (hrtime(true) - $debut) / 1e6;
$debut = hrtime(true);
$vrai = Hashing::hash('secret-quelconque');
Hashing::verify('secret-quelconque', $vrai);
$coutVrai = (hrtime(true) - $debut) / 1e6;
verifier('coût comparable entre vrai hash et factice',
    abs($coutVrai / 2 - $coutFactice) < max(20.0, $coutFactice * 0.5),
    sprintf('factice %.1f ms', $coutFactice));

echo "\n→ Encodage appareil (WebCrypto → OpenSSL)\n";
$brut = random_bytes(57);
verifier('base64url fait l\'aller-retour', Encoding::b64urlDecode(Encoding::b64urlEncode($brut)) === $brut);
verifier('base64url sans remplissage', !str_contains(Encoding::b64urlEncode($brut), '='));
$der = Encoding::p1363ToDer(str_repeat("\xFF", 32) . str_repeat("\xFF", 32));
verifier('un INTEGER à octet de tête haut reçoit son 0x00',
    $der !== '' && $der[0] === "\x30" && str_contains($der, "\x02\x21\x00"));
verifier('signature vide rend une chaîne vide', Encoding::p1363ToDer('') === '');
verifier('le PEM porte ses délimiteurs',
    str_starts_with(Encoding::spkiToPem($brut), '-----BEGIN PUBLIC KEY-----'));

echo "\n→ Diceware\n";
foreach (['en', 'fr'] as $langue) {
    verifier("la liste {$langue} compte 7776 mots", count(Wordlist::load($langue)) === Wordlist::LIST_SIZE);
}
$tirage = Wordlist::generate(6, 'en');
verifier('6 mots rendent 77.55 bits', abs($tirage['entropy_bits'] - 77.55) < 0.01,
    sprintf('%.2f bits', $tirage['entropy_bits']));
verifier('deux tirages diffèrent', Wordlist::generate(6)['words'] !== Wordlist::generate(6)['words']);

echo "\n" . str_repeat('=', 63) . "\n";
printf("  Socle SelfRecover — %d passés, %d échoués\n", $passes, $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
