<?php

declare(strict_types=1);

/**
 * pepper — le poivre de SelfDataGuard : un secret hors de la base, que chaque serrure
 * poivrée prend en plus du secret de l'utilisateur.
 *
 * Usage :
 *   php bin/pepper.php new <fichier>
 *   php bin/pepper.php adopt <fichier>
 *   php bin/pepper.php status <fichier>
 *   php bin/pepper.php unlayer <fichier>
 *
 * new  tire 32 octets (random_bytes) et les écrit en « myself-pepper/v1: » suivi de 64 hex,
 *      en mode 0600, dans un fichier qui n'existe pas : il n'en écrase jamais un. Le fichier
 *      vit hors de la racine que le serveur web sert, et s'en garde une copie hors du
 *      serveur : sa perte ferme tout coffre qui n'a pas de passphrase.
 *
 * adopt    la passe de l'exploitant, à la montée depuis 0.9.x : une couche sous le poivre sur
 *          chaque serrure du mot de passe et du mot mémorisé, coffres vivants et archives, sans
 *          aucun secret d'utilisateur, puis le témoin du poivre inscrit. À lancer avant qu'une
 *          façade 0.10.0 serve la base, et pendant que rien d'autre n'y écrit. Relancée, elle
 *          ne pose que ce qui reste nu. Un coffre gelé est sauté et nommé : la relancer après
 *          la clôture.
 * status   le compte des formes, sans rien écrire : la fin de montée (PepperForms::LayeredOnly)
 *          se déclare quand plus rien n'est nu.
 * unlayer  la passe inverse, pour un retour à 0.9.x avant la fin de montée : chaque couche
 *          retirée. Une serrure native n'en a pas sous elle : son coffre est nommé, et reste.
 *          Tant qu'elle n'a pas tourné, la 0.9.x n'ouvre aucune serrure couverte : l'hôte ferme
 *          sa route de récupération tant qu'il sert la 0.9.x.
 *
 * adopt, status et unlayer lisent DATAGUARD_DB (le fichier SQLite) et DATAGUARD_BLINDKEY_PATH
 * (la clé d'index que la base a inscrite) ; <fichier> est le poivre.
 *
 * Sorties : 0 fait (status : plus rien de nu) · 2 refusé, ou ce qui reste à reprendre (status :
 * des serrures nues) · 3 usage, configuration, ou fichier impossible à écrire.
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Pepper\LocalPepperFile;
use Pierroons\SelfDataGuard\Pepper\Pepper;
use Pierroons\SelfDataGuard\Pepper\PepperFileException;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;

$cmd  = $argv[1] ?? '';
$file = $argv[2] ?? '';
if (!in_array($cmd, ['new', 'adopt', 'status', 'unlayer'], true) || $file === '') {
    fwrite(STDERR, "Usage : php bin/pepper.php new|adopt|status|unlayer <fichier>\n");
    exit(3);
}
if ($cmd !== 'new') {
    $db  = (string) getenv('DATAGUARD_DB');
    $key = (string) getenv('DATAGUARD_BLINDKEY_PATH');
    if ($db === '' || !is_file($db) || $key === '' || !is_file($key)) {
        fwrite(STDERR, "⛔ DATAGUARD_DB et DATAGUARD_BLINDKEY_PATH désignent la base et la clé d'index qu'elle a inscrite\n");
        exit(3);
    }
    try {
        $pepper = new LocalPepperFile($file, '');
        $passe  = ['adopt' => 'adoptPepper', 'status' => 'pepperStatus', 'unlayer' => 'unlayPepper'][$cmd];
        $r = SelfDataGuard::{$passe}(new SqliteAdapter('sqlite:' . $db), (string) file_get_contents($key), $pepper);
    } catch (PepperFileException $e) {
        fwrite(STDERR, "⛔ poivre refusé ({$e->reason}) : {$e->getMessage()}\n");
        exit(3);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, "⛔ {$e->getMessage()}\n");
        exit(2);
    }
    if ($cmd === 'status') {
        echo "native {$r['native']} · couche {$r['layer']} · nue {$r['bare']} · illisible {$r['unreadable']}\n";
        foreach ($r['unsealed'] as $u) {
            echo "⚠️ sceau absent ou faux, non compté : {$u}\n";
        }
        exit($r['bare'] === 0 && $r['unreadable'] === 0 && $r['unsealed'] === [] ? 0 : 2);
    }
    echo ($cmd === 'adopt' ? '🧂 couches posées' : '↩️ couches retirées') . " : {$r['vaults']} coffre(s) vivant(s), {$r['archives']} archive(s)\n";
    foreach ($r['frozen'] as $u) {
        echo "⏳ gelé, non touché — à relancer après la clôture : {$u}\n";
    }
    foreach ($r['refused'] as $u) {
        echo "⚠️ non touché : {$u}\n";
    }
    exit($r['frozen'] === [] && $r['refused'] === [] ? 0 : 2);
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
