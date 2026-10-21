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
 * caractère décode aux mêmes octets tout en étant une autre chaîne ; et donner à
 * la ligne **légitime** l'en-tête d'un blob lié la sortirait de l'ensemble des nus,
 * laissant la copie seule — donc unique, donc scellée pour qui l'a déplacée.
 *
 * Un blob *déplacé sans copie* ne laisse aucune trace, et aucun outil ne le
 * retrouvera.
 *
 * Idempotent : il ne touche que les blobs qui ne portent pas leur liaison
 * (`DataGuard::estLie()`, que la bibliothèque tranche par le format). Relancé, il
 * ne fait rien et le dit.
 *
 * 🔑 **Finie, elle ferme la lecture sans contexte.** Quand il ne reste aucun blob
 * nu, la passe pose le témoin de `DataGuard` : un blob nu gardé d'avant elle — un
 * dump, une archive — ne se relira plus nulle part. Et la passe ne scelle plus
 * aucun blob nu : après elle, un blob nu ne vient que d'une écriture hostile ou d'une
 * sauvegarde restaurée, et le sceller le certifierait pour qui l'a planté. Elle les
 * nomme ; une base restaurée d'avant la passe se relit en retirant le témoin, puis
 * en relançant la passe.
 *
 * ⚠️ **Une ligne qui reste nue bloque la fermeture** : recopiée, illisible ou
 * apparue après coup, elle n'est jamais scellée, et la passe ne s'annonce pas finie.
 * `--fermer` pose le témoin malgré elle, après examen : c'est une **décision
 * humaine**, car ces lignes deviennent illisibles pour de bon — y compris la ligne
 * légitime d'une paire recopiée.
 *
 * Usage :
 *   php tools/migrer-aad.php --a-blanc    compte sans écrire (la base s'ouvre en lecture seule)
 *   php tools/migrer-aad.php              relie, et dit combien
 *   php tools/migrer-aad.php --fermer     relie, puis ferme même s'il reste des lignes nues
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/dataguard.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\DataGuard;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;

$aBlanc = in_array('--a-blanc', $argv, true);
$forcer = in_array('--fermer', $argv, true) && !$aBlanc;

// `Db::pdo()` crée la base absente et y pose le schéma. Un chemin mal tapé
// rendrait donc « 0 sans contexte » sur une base neuve et vide, soit un succès
// annoncé sur la mauvaise cible. Une passe de maintenance ne crée rien.
$base = Db::path();
if (!is_file($base)) {
    fwrite(STDERR, sprintf("Aucune base à %s — rien n'est migré, et rien n'est créé.\n", $base));
    exit(2);
}
// Le même défaut sous une autre forme : `Db::pdo()` pose aussi le schéma sur un
// fichier vide ou sur une base étrangère. Les tables se lisent donc avant.
$manquantes = array_diff(['dm', 'profiles'], Db::tables());
if ($manquantes !== []) {
    fwrite(STDERR, sprintf(
        "%s n'est pas une base du lab (%s absente) — rien n'est migré, et rien n'est créé.\n",
        $base,
        implode(', ', $manquantes)
    ));
    exit(2);
}

// À blanc, la base s'ouvre en lecture seule : `Db::pdo()` y appliquerait schéma et
// migrations, et « compte sans écrire » serait faux.
$pdo = $aBlanc ? Db::lectureSeule() : Db::pdo();
// La passe scelle sous le numéro de la base : sans lui, elle s'arrête avant d'écrire,
// au lieu de se dire finie sur une base dont plus rien ne s'ouvre.
if (!$aBlanc) {
    Db::generation();
}

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
    if ($octets === false || strlen($octets) < EncryptedBlob::NONCE_LEN_V1 + EncryptedBlob::TAG_LEN) {
        return null;
    }

    return 'octets:' . hash('sha256', $octets);
};

// La clé, mais seulement s'il y a quelque chose à déchiffrer : sans ce test, une
// base qui n'a jamais rien chiffré ferait lever la passe là où elle n'a rien à
// faire. Avec un blob en main, en revanche, un secret manquant ferait compter
// chaque ligne comme illisible et annoncer un diagnostic faux. Elle se dérive AVANT
// le verrou : un Argon2id de 64 Mio tiendrait sinon toutes les écritures du site.
$aDechiffrer = static fn(): bool => (bool) $pdo->query(
    'SELECT EXISTS (SELECT 1 FROM dm) OR EXISTS (SELECT 1 FROM profiles)'
)->fetchColumn();
if ($aDechiffrer()) {
    DataGuard::exigerCle();
}

// 🔑 **Toute la passe sous un seul verrou d'écriture.** L'inventaire des copies et
// les lignes qu'il juge doivent être la même base : sans verrou, une copie écrite
// après l'inventaire n'y figurait pas, et la passe la scellait pour qui l'avait faite
// — mesuré, 31 à 41 copies sur trois essais, écrites pendant la dérivation de la clé.
// Les écritures du site attendent la fin de la passe (`busy_timeout`) ; aucune n'est
// perdue ni défaite. À blanc, une transaction de lecture suffit à figer la base. Une
// exception en cours de passe termine le script, et SQLite défait à la fermeture de
// la connexion tout ce qui n'a pas été validé.
$pdo->exec($aBlanc ? 'BEGIN' : 'BEGIN IMMEDIATE');

/**
 * Les charges présentes à plus d'un endroit : une copie, donc un déplacement.
 *
 * L'univers est **toutes** les lignes des deux tables, scellées comprises : une
 * ligne liée dont la charge égale celle d'une ligne nue est le signe qu'un
 * en-tête a été posé à la main pour isoler la copie.
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

// Une ligne arrivée entre le test et le verrou : la clé se dérive alors ici.
if ($toutes !== []) {
    DataGuard::exigerCle();
}

$relie   = 0;
$refus   = 0;
$deplace = 0;
$apres   = 0;
$fermee  = DataGuard::lectureSansContexteFermee();

/** Les lignes d'une table dont le blob ne porte pas sa liaison. */
$nues = static fn(string $table): array => array_values(array_filter(
    $pdo->query("SELECT * FROM $table")->fetchAll(),
    static fn(array $l): bool => !DataGuard::estLie((string) $l['ciphertext'])
));

/** @param callable(array<string,mixed>):\Pierroons\SelfDataGuard\Crypto\Aad $contexteDe */
$passe = static function (string $table, string $cle, callable $contexteDe) use ($pdo, $aBlanc, $copies, $empreinte, $fermee, $nues, &$relie, &$refus, &$deplace, &$apres): void {
    foreach ($nues($table) as $ligne) {
        $id   = (int) $ligne[$cle];
        $blob = (string) $ligne['ciphertext'];

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

        if ($fermee) {
            $apres++;
            fwrite(STDERR, sprintf(
                "%s #%d : blob nu alors que la lecture sans contexte est fermée — laissé tel quel, à examiner. "
                . "Si la base vient d'une sauvegarde d'avant la passe : retirer le témoin, puis relancer.\n",
                $table,
                $id
            ));
            continue;
        }

        try {
            $clair = DataGuard::decryptSansContexte($blob);
        } catch (\Throwable $e) {
            $refus++;
            fwrite(STDERR, sprintf("%s #%d : illisible, laissé tel quel (%s)\n", $table, $id, $e->getMessage()));
            continue;
        }

        if (!$aBlanc) {
            // Le contexte porte le numéro de la base : il ne se lit qu'au moment de sceller.
            $pdo->prepare("UPDATE $table SET ciphertext = ? WHERE $cle = ?")
                ->execute([DataGuard::encrypt($clair, $contexteDe($ligne)), $id]);
        }
        $relie++;
    }
};

$passe('dm', 'id', static fn(array $l) => DataGuard::contexteDm((int) $l['sender_id'], (int) $l['recipient_id']));
$passe('profiles', 'account_id', static fn(array $l) => DataGuard::contexteProfil((int) $l['account_id']));

$restants = count($nues('dm')) + count($nues('profiles'));

$pdo->exec($aBlanc ? 'ROLLBACK' : 'COMMIT');

$fini = $restants === 0;
if (($fini || $forcer) && !$aBlanc) {
    DataGuard::fermerLectureSansContexte();
}
if ($forcer && !$fini) {
    fwrite(STDERR, sprintf(
        "--fermer : %d ligne(s) restée(s) nue(s) deviennent illisibles pour de bon.\n",
        $restants
    ));
}
printf(
    "%s %d blob(s) relié(s) · %d recopié(s) laissé(s) à examiner · %d apparu(s) après la fermeture, à examiner · %d illisible(s) · %d sans contexte après cette passe · lecture sans contexte %s\n",
    $aBlanc ? '[à blanc]' : ($fini ? '✅' : '⚠️'),
    $relie,
    $deplace,
    $apres,
    $refus,
    $restants,
    DataGuard::lectureSansContexteFermee() ? 'fermée' : 'encore ouverte'
);

if ($aBlanc) {
    exit(0);
}

// Le code de sortie dit l'état de la base, pas le déroulé de la passe : tant
// qu'un blob reste nu, il reste déplaçable, et la passe n'est pas finie.
exit($fini ? 0 : 1);
