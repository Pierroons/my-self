<?php
/**
 * MySelf-Lab — seed de démonstration (comptes + threads souveraineté numérique).
 * Usage : php seed.php
 */

declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/forum.php';
// Le navigateur dérive le mot mémorisé ; un script CLI n'en a pas, il refait
// donc le même calcul (cf. lib/derive_cli.php).
require_once __DIR__ . '/lib/derive_cli.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;
use Pierroons\MySelfLab\Forum;

$pdo = Db::pdo();

$comptes = [
    ['libriste', 'tuxroot'],
    ['vieprivee', 'rgpd2024'],
    ['autohebergeur', 'yunohost'],
];
$ids = [];
foreach ($comptes as [$u, $rw]) {
    $sel = sr_sel_aleatoire();
    $r = Auth::register($pdo, $u, sr_derive_like_browser($rw, $sel), $sel);
    if ($r['ok']) {
        $ids[$u] = $r['account_id'];
        echo "Compte $u créé (#{$r['account_id']})\n";
    } else {
        $row = $pdo->prepare('SELECT id FROM accounts WHERE username=?');
        $row->execute([$u]);
        $ids[$u] = (int) $row->fetchColumn();
        echo "Compte $u déjà présent (#{$ids[$u]})\n";
    }
}

$threads = [
    ['libriste', 'Pourquoi AGPL plutôt que MIT pour un SaaS souverain ?', 'libre',
     "La licence AGPL impose la réciprocité même pour les services en réseau. Contrairement à MIT, un acteur ne peut pas prendre votre code, le mettre derrière une API et fermer ses modifications. Pour un projet de souveraineté numérique, c'est le bouclier anti-capture par excellence."],
    ['vieprivee', 'Recovery sans email : comment ça tient face au phishing ?', 'crypto',
     "Le protocole dérive la clé de récupération via HMAC(clé = mot_de_récup, message = nom d'hôte + \"|v2\" + sel du compte), le nom d'hôte étant lu par le navigateur. Le sel n'est pas secret et un faux site peut obtenir le même : c'est l'adresse qui diffère → la clé dérivée ne correspondrait pas. Pas d'email = pas de vecteur de reset détourné."],
    ['autohebergeur', "Retour d'expérience : 2 ans d'auto-hébergement sur Raspberry Pi", 'autohebergement',
     "Nextcloud + Vaultwarden + un reverse proxy, le tout derrière un tunnel. La vraie difficulté n'est pas technique mais la résilience : sauvegardes chiffrées hors-site et plan de récupération. Sans ça, un disque mort = tout perdu."],
    ['vieprivee', 'RGPD : minimisation des données, on en parle ?', 'rgpd',
     "La meilleure donnée personnelle est celle qu'on ne collecte pas. Si une appli chiffre tout côté client et ne stocke que des blobs illisibles côté serveur, le risque en cas de fuite tombe à presque zéro. C'est l'approche que je voudrais voir généralisée."],
];
foreach ($threads as [$auteur, $titre, $cat, $msg]) {
    $r = Forum::createThread($pdo, $ids[$auteur], $titre, $cat, $msg);
    echo ($r['ok'] ? "Thread créé : " : "Échec : ") . $titre . "\n";
}

echo "\nSeed terminé. " . count($threads) . " sujets, " . count($comptes) . " comptes.\n";
