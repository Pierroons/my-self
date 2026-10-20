#!/usr/bin/env php
<?php
/**
 * Relie au contexte les blobs écrits avant que le contexte existe.
 *
 * `DataGuard::decrypt()` lit encore un blob nu, pour qu'aucun ne soit perdu avant
 * cette passe. Mais un blob nu reste **déplaçable** : qui obtient une écriture le
 * recopie dans une ligne qu'il a le droit de lire, et l'application le déchiffre.
 * La tolérance de lecture n'est donc pas la correction — celle-ci l'est.
 *
 * ⚠️ **Ce que cette passe ne peut pas réparer.** Elle scelle chaque blob sous le
 * contexte de la ligne **où il se trouve**, et rien ne distingue « ce blob est
 * chez lui » de « ce blob a été recopié ici » : c'est exactement l'information
 * que l'absence de contexte avait détruite. Un blob déplacé avant la passe en
 * ressortirait *certifié* lisible par qui l'a déplacé, et plus jamais revu. Une
 * base dont l'écriture a été compromise ne s'assainit donc pas ici.
 *
 * Ce qui reste détectable l'est : un blob **recopié** laisse deux lignes dont la
 * charge chiffrée est la même. La passe les refuse toutes et les nomme.
 *
 * ⚠️ La comparaison porte sur les **octets décodés**, pas sur la chaîne stockée, et
 * sur **toutes** les lignes, scellées comprises. Deux raisons mesurées, chacune un
 * contournement de la version naïve : le base64 strict de PHP tolère les blancs et
 * les bits de queue non canoniques, si bien qu'une copie retouchée d'un seul
 * caractère décode aux mêmes octets tout en étant une autre chaîne ; et préfixer
 * la ligne **légitime** de `L2.` la sortirait de l'ensemble des nus, laissant la
 * copie seule — donc unique, donc scellée pour qui l'a déplacée.
 *
 * Un blob *déplacé sans copie* ne laisse aucune trace, et aucun outil ne le
 * retrouvera.
 *
 * Idempotent : il ne touche que les blobs sans `DataGuard::MARQUEUR_CONTEXTE`.
 * Relancé, il ne fait rien et le dit.
 *
 * Usage :
 *   php tools/migrer-aad.php --a-blanc    compte sans écrire
 *   php tools/migrer-aad.php              relie, et dit combien
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/dataguard.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\DataGuard;

/**
 * Le prédicat « ce blob ne porte pas le marqueur », et ses deux paramètres.
 *
 * Comparaison par `substr` sur une longueur liée, et non par motif : `LIKE` ignore
 * la casse sous SQLite là où `DataGuard::decrypt()` compare par `str_starts_with`,
 * et `GLOB`, qui la respecte, traiterait `*`, `?` ou `[` du marqueur comme des
 * métacaractères — un marqueur contenant l'un d'eux ferait diverger le SQL de PHP
 * **en silence**, laissant des lignes nues jamais migrées pendant que le compte
 * final tombe à zéro. Le marqueur ne construit donc plus de SQL : il se lie.
 */
const NU = 'substr(ciphertext, 1, ?) <> ?';

/** @return list<int|string> les deux paramètres de `NU`, dans l'ordre. */
function parametresNu(): array
{
    return [strlen(DataGuard::MARQUEUR_CONTEXTE), DataGuard::MARQUEUR_CONTEXTE];
}

$aBlanc = in_array('--a-blanc', $argv, true);

// `Db::pdo()` crée la base absente et y pose le schéma. Un chemin mal tapé
// rendrait donc « 0 sans contexte » sur une base neuve et vide, soit un succès
// annoncé sur la mauvaise cible. Une passe de maintenance ne crée rien.
$base = Db::path();
if (!is_file($base)) {
    fwrite(STDERR, sprintf("Aucune base à %s — rien n'est migré, et rien n'est créé.\n", $base));
    exit(2);
}

$pdo = Db::pdo();


/**
 * L'empreinte de la charge chiffrée, marqueurs retirés et base64 décodé.
 *
 * C'est la seule comparaison qui vaut : le lecteur décode avant de déchiffrer, donc
 * deux chaînes différentes qui décodent aux mêmes octets ouvrent le même clair.
 * Une charge indécodable se compare sur sa chaîne — elle n'ouvrira rien de toute
 * façon, mais sa présence en double reste un signe.
 */
$empreinte = static function (string $blob) {
    $point  = strrpos($blob, '.');
    $b64    = $point === false ? $blob : substr($blob, $point + 1);
    $octets = base64_decode($b64, true);

    // Une charge vide ou trop courte pour porter un nonce et un tag n'ouvrira rien :
    // deux lignes dégénérées se ressemblent sans qu'aucune soit la copie de l'autre,
    // et les accuser de recopie serait une accusation d'intrusion sur un vide.
    if ($octets === false || strlen($octets) < 28) {
        return null;
    }

    return 'octets:' . hash('sha256', $octets);
};

/**
 * Les charges présentes à plus d'un endroit : une copie, donc un déplacement.
 *
 * L'univers est **toutes** les lignes des deux tables, scellées comprises : une
 * ligne scellée dont la charge égale celle d'une ligne nue est le signe qu'un
 * marqueur a été posé à la main pour isoler la copie.
 */
$vus = [];
$toutes = $pdo->query('SELECT ciphertext FROM dm UNION ALL SELECT ciphertext FROM profiles')->fetchAll();
foreach ($toutes as $l) {
    $e = $empreinte((string) $l['ciphertext']);
    if ($e !== null) {
        $vus[$e] = ($vus[$e] ?? 0) + 1;
    }
}
$copies = array_filter($vus, static fn(int $n): bool => $n > 1);

// La clé, mais seulement s'il y a quelque chose à déchiffrer : sans ce test, une
// base qui n'a jamais rien chiffré ferait lever la passe là où elle n'a rien à
// faire. Avec un blob en main, en revanche, un secret manquant ferait compter
// chaque ligne comme illisible et annoncer un diagnostic faux.
if ($toutes !== []) {
    DataGuard::exigerCle();
}

$relie   = 0;
$refus   = 0;
$deplace = 0;

/** @param callable(array<string,mixed>):string $contexteDe */
$passe = static function (string $table, string $cle, callable $contexteDe) use ($pdo, $aBlanc, $copies, $empreinte, &$relie, &$refus, &$deplace): void {
    $lignes = $pdo->prepare("SELECT * FROM $table WHERE " . NU);
    $lignes->execute(parametresNu());
    foreach ($lignes->fetchAll() as $ligne) {
        $id       = (int) $ligne[$cle];
        $blob     = (string) $ligne['ciphertext'];
        $contexte = $contexteDe($ligne);

        $e = $empreinte($blob);
        if ($e !== null && isset($copies[$e])) {
            $deplace++;
            fwrite(STDERR, sprintf(
                "%s #%d : cette charge chiffrée est présente à %d endroits — recopiée, donc laissée nue et à examiner.\n",
                $table,
                $id,
                $copies[$e]
            ));
            continue;
        }

        try {
            $clair = DataGuard::decrypt($blob, $contexte);
        } catch (\Throwable $e) {
            $refus++;
            fwrite(STDERR, sprintf("%s #%d : illisible, laissé tel quel (%s)\n", $table, $id, $e->getMessage()));
            continue;
        }

        if (!$aBlanc) {
            // La valeur relue garde l'écriture : un titulaire qui enregistre son
            // profil entre la lecture et ici ne doit pas voir son travail défait.
            $maj = $pdo->prepare("UPDATE $table SET ciphertext = ? WHERE $cle = ? AND ciphertext = ?");
            $maj->execute([DataGuard::encrypt($clair, $contexte), $id, $blob]);
            if ($maj->rowCount() === 0) {
                fwrite(STDERR, sprintf("%s #%d : modifié pendant la passe, laissé à son écriture la plus récente.\n", $table, $id));
                continue;
            }
        }
        $relie++;
    }
};

$passe('dm', 'id', static fn(array $l): string => DataGuard::contexteDm((int) $l['sender_id'], (int) $l['recipient_id']));
$passe('profiles', 'account_id', static fn(array $l): string => DataGuard::contexteProfil((int) $l['account_id']));

$compte = $pdo->prepare(
    'SELECT (SELECT COUNT(*) FROM dm WHERE ' . NU . ') + (SELECT COUNT(*) FROM profiles WHERE ' . NU . ')'
);
$compte->execute([...parametresNu(), ...parametresNu()]);
$restants = (int) $compte->fetchColumn();

$fini = $restants === 0;
printf(
    "%s %d blob(s) relié(s) · %d recopié(s) laissé(s) à examiner · %d illisible(s) · %d sans contexte après cette passe\n",
    $aBlanc ? '[à blanc]' : ($fini ? '✅' : '⚠️'),
    $relie,
    $deplace,
    $refus,
    $restants
);

if ($aBlanc) {
    exit(0);
}

// Le code de sortie dit l'état de la base, pas le déroulé de la passe : tant
// qu'un blob reste nu, il reste déplaçable, et la passe n'est pas finie.
exit($fini ? 0 : 1);
