<?php

declare(strict_types=1);

/**
 * pepper — le poivre de SelfDataGuard : un secret hors de la base, que chaque serrure
 * poivrée prend en plus du secret de l'utilisateur.
 *
 * Usage :
 *   php bin/pepper.php new <fichier>
 *
 * new  tire 32 octets (random_bytes) et les écrit en « myself-pepper/v1: » suivi de 64 hex,
 *      en mode 0600, dans un fichier qui n'existe pas : il n'en écrase jamais un. Le fichier
 *      vit hors de la racine que le serveur web sert, et s'en garde une copie hors du
 *      serveur : sa perte ferme tout coffre qui n'a pas de passphrase.
 *
 * Sorties : 0 écrit · 2 refusé (le fichier existe) · 3 usage ou dossier impossible à écrire.
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Pepper\LocalPepperFile;
use Pierroons\SelfDataGuard\Pepper\Pepper;

$cmd  = $argv[1] ?? '';
$file = $argv[2] ?? '';
if ($cmd !== 'new' || $file === '') {
    fwrite(STDERR, "Usage : php bin/pepper.php new <fichier>\n");
    exit(3);
}
if (file_exists($file) || is_link($file)) {
    fwrite(STDERR, "⛔ {$file} existe : un poivre ne s'écrase jamais. Le remplacer fermerait chaque serrure qu'il couvre.\n");
    exit(2);
}
$old = umask(0o077);
$handle = @fopen($file, 'x');
umask($old);
if ($handle === false) {
    fwrite(STDERR, "⛔ impossible de créer {$file} : le dossier existe-t-il, et ce processus peut-il y écrire ?\n");
    exit(3);
}
$pepper = random_bytes(32);
$contents = Pepper::TAG . ':' . bin2hex($pepper) . "\n";
sodium_memzero($pepper);
$written = fwrite($handle, $contents) === strlen($contents) && fflush($handle) && @fsync($handle);
fclose($handle);
if (!$written || !chmod($file, 0o600)) {
    @unlink($file);
    fwrite(STDERR, "⛔ l'écriture de {$file} a échoué : rien n'est laissé\n");
    exit(3);
}
$check = Pepper::check(new LocalPepperFile($file, ''));
echo "✅ poivre écrit : {$file} (0600), témoin {$check}\n";
echo "   Copiez-le hors du serveur : sa perte ferme tout coffre qui n'a pas de passphrase.\n";
exit(0);
