#!/usr/bin/env php
<?php
/**
 * La traduction récursive ne touche que du texte d'écran — jamais un secret.
 *
 * 🔑 Le danger est mesuré, pas théorique. `tc()` est indexé par le texte
 * français source : il réécrit toute chaîne qui matche une de ses clés. Or cinq
 * clés du dictionnaire sont aussi des mots de la liste Diceware française
 * (`moyen`, `nouveau`, `intensif`, `jour`, `minute`), et `rare` l'est dans les
 * DEUX listes — et il faudra bien le traduire un jour, puisque c'est une valeur
 * de faisceau affichée à l'arbitre.
 *
 * Une passphrase rangée **mot à mot** dans un tableau — ce que rend
 * `Wordlist::generate()` — en ressortait avec quatre mots sur six réécrits. Le
 * titulaire noterait alors un secret qui ne correspond plus à son empreinte, et
 * perdrait ce facteur de récupération sans comprendre pourquoi.
 *
 * ⚠️ Ce qui protège aujourd'hui n'est PAS une garde : `engendrerPassphrase()`
 * assemble ses mots en une seule chaîne, qui ne matche aucune clé. C'est un
 * hasard de style. Ce banc vérifie que la garde existe désormais vraiment.
 *
 * Usage : php tests/sanity_tc_deep.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/i18n.php';

// La langue doit être l'anglais pour que le dictionnaire soit chargé : sans ça
// `dictionnaireContenu()` rend un tableau vide et tous les cas passeraient pour
// la mauvaise raison.
$_GET['lang'] = 'en';
// `lang()` pose un cookie au premier appel : on le déclenche AVANT toute sortie,
// sinon PHP avertit « headers already sent » et le banc salit son propre journal.
dictionnaireContenu();

$echecs = 0;
$reussites = 0;
function v(string $quoi, bool $vrai, string $detail = ''): void
{
    global $echecs, $reussites;
    if ($vrai) {
        echo "  ✓ $quoi\n";
        $reussites++;
    } else {
        fwrite(STDERR, "  ✗ $quoi" . ($detail !== '' ? " — $detail" : '') . "\n");
        $echecs++;
    }
}

// ── 0. Le dictionnaire est bien chargé, sinon tout ce qui suit est vide ─────
echo "0. Les conditions du banc\n";
$dico = dictionnaireContenu();
v('le dictionnaire anglais est chargé', count($dico) > 100, count($dico) . ' entrée(s)');
$pieges = array_values(array_filter(['moyen', 'nouveau', 'intensif', 'jour', 'minute'],
    fn($m) => isset($dico[$m])));
v('au moins un mot-piège est bien une clé du dictionnaire', $pieges !== [],
    'sans ça, les cas de corruption ne prouveraient rien');
echo '     mots-pièges présents : ' . implode(', ', $pieges) . "\n";

// ── 1. LE cas : une passphrase mot à mot ne doit pas bouger ────────────────
echo "\n1. Une passphrase rangée mot à mot\n";
$mots = ['moyen', 'nouveau', 'intensif', 'jour', 'minute', 'zzzqqq'];
$apres = tc_deep(['credentials' => ['passphrase_mots' => $mots]]);
v('aucun mot de la passphrase n\'est réécrit',
    $apres['credentials']['passphrase_mots'] === $mots,
    implode(' ', $apres['credentials']['passphrase_mots']));

// Et sous une clé qui ressemble à du texte mais n'en est pas.
$apres = tc_deep(['mots' => $mots, 'words' => $mots]);
v('ni sous « mots », ni sous « words »',
    $apres['mots'] === $mots && $apres['words'] === $mots);

// ── 2. Un identifiant technique ne doit pas bouger non plus ───────────────
// `role` vaut `user` dans la console SU. Si un jour `user` devient une clé du
// dictionnaire, une liste noire l'aurait laissé passer.
echo "\n2. Les identifiants techniques\n";
$apres = tc_deep(['role' => 'moyen', 'statut' => 'nouveau', 'cle_technique' => 'intensif']);
v('« role » n\'est pas traduit',          $apres['role'] === 'moyen');
v('« statut » n\'est pas traduit',        $apres['statut'] === 'nouveau');
v('« cle_technique » n\'est pas traduit', $apres['cle_technique'] === 'intensif');

// Les champs qui portent une VALEUR, et jamais du texte d'écran. La liste est
// énumérée ici parce que rien dans un nom de champ ne distingue une valeur d'un
// libellé : `reel` et `dit` sont le faisceau tel que le titulaire l'a déclaré,
// et les traduire réécrirait sa déclaration sous les yeux de l'arbitre. Le cas 7
// garde le sens inverse — un libellé oublié — et il le garde structurellement ;
// celui-ci garde le sens dangereux, et il a besoin de la liste.
foreach (['reel', 'dit', 'declare', 'numero', 'empreinte', 'username', 'passphrase'] as $champ) {
    v("« $champ » ne peut pas entrer dans la liste blanche",
        !in_array($champ, TC_CLES_TEXTE, true),
        'il porte une valeur : traduite, elle devient fausse sans bruit');
}

// ── 3. Mais le texte d'écran DOIT être traduit ─────────────────────────────
// Sans ce bloc, une garde qui ne traduit plus rien passerait pour un succès.
echo "\n3. Le texte d'écran est bien traduit\n";
$source = 'Ce qu\'un user PEUT';
$attendu = $dico[$source] ?? null;
v('la phrase témoin est au dictionnaire', $attendu !== null, $source);
if ($attendu !== null) {
    $apres = tc_deep(['label' => $source]);
    v('une valeur sous « label » est traduite', $apres['label'] === $attendu, $apres['label']);
    $apres = tc_deep(['peut' => ['label' => $source]]);
    v('même imbriquée', $apres['peut']['label'] === $attendu);
}

// ── 4. Une liste hérite de la clé de son parent ───────────────────────────
echo "\n4. Les listes de phrases\n";
$l1 = 'Modérer, bannir, gracier';
if (isset($dico[$l1])) {
    $apres = tc_deep(['lignes' => [$l1, 'zzzqqq']]);
    v('les phrases de « lignes » sont traduites', $apres['lignes'][0] === $dico[$l1]);
    v('et celles qui manquent restent en français', $apres['lignes'][1] === 'zzzqqq');
} else {
    v('phrase témoin de liste trouvée', false, $l1 . ' absente du dictionnaire');
}
$apres = tc_deep(['recovery_codes' => ['moyen', 'nouveau']]);
v('une liste sous une clé non listée est intacte',
    $apres['recovery_codes'] === ['moyen', 'nouveau'],
    implode(',', $apres['recovery_codes']));

// ── 5. Les clés ne sont jamais touchées ───────────────────────────────────
echo "\n5. Les clés restent les clés\n";
$apres = tc_deep(['jour' => ['label' => 'zzzqqq']]);
v('une clé qui est un mot-piège n\'est pas réécrite', array_key_exists('jour', $apres),
    implode(',', array_keys($apres)));

// ── 6. Les trois payloads réels du lab passent toujours ───────────────────
// Si la garde avait cassé la traduction des consoles, c'est ici que ça se voit.
echo "\n6. Les payloads réels du lab\n";
putenv('LAB_DB_PATH=' . sys_get_temp_dir() . '/tc_deep_' . bin2hex(random_bytes(4)) . '.db');
putenv('LAB_STATE_DIR=' . sys_get_temp_dir());
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/su_console.php';
$su = tc_deep(\Pierroons\MySelfLab\SuConsole::run('user'));
v('la console SU traduit encore son titre',
    $su['titre'] !== '' && $su['titre'] !== null);
v('mais son « role » reste l\'identifiant', ($su['role'] ?? '') === 'user',
    (string) ($su['role'] ?? '—'));
$traduit = 0;
foreach (($su['peut']['lignes'] ?? []) as $l) {
    $traduit += (int) in_array($l, $dico, true);
}
v('et ses listes de phrases sont traduites', $traduit > 0, "$traduit ligne(s) traduite(s)");

// ── 7. 🔑 La liste blanche se confronte au code, pas à ma mémoire ──────────
// Une liste blanche tenue à la main échoue dans les deux sens : un nom de trop
// réécrit un identifiant, un nom qui manque laisse du français à l'écran. Le
// second est silencieux — c'est arrivé à la première version de cette garde,
// qui avait cessé de traduire `verdict` et `defense` du simulateur d'attaques,
// six chaînes déjà présentes au dictionnaire. Personne ne l'aurait vu sans
// lire la page en anglais.
//
// Le contrôle ne recopie donc aucune liste : il cherche dans le code tout
// `'champ' => 'littéral'` dont le littéral est une clé du dictionnaire, et
// exige que le champ soit dans `TC_CLES_TEXTE` **ou** que la ligne appelle
// `tc()` elle-même — les deux façons légitimes de traduire une chaîne.
echo "\n7. La liste blanche couvre tout le texte traduisible du lab\n";
$racine = __DIR__ . '/..';
$fichiers = array_merge(
    glob("$racine/lib/*.php") ?: [],
    glob("$racine/public/*.php") ?: [],
    glob("$racine/public/api/*.php") ?: [],
);
$orphelins = [];
$examines = 0;
foreach ($fichiers as $f) {
    if (basename($f) === 'i18n.php') {
        continue; // le dictionnaire lui-même : ses clés ne sont pas du contenu
    }
    foreach (file($f) ?: [] as $no => $ligne) {
        if (str_contains($ligne, 'tc(')) {
            continue; // déjà traduit sur place
        }
        if (!preg_match_all('/\x27([a-z_]+)\x27\s*=>\s*\x27((?:[^\x27\\\\]|\\\\.){4,})\x27/', $ligne, $mm, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($mm as $x) {
            $valeur = str_replace("\\'", "'", $x[2]);
            if (!isset($dico[$valeur])) {
                continue;
            }
            $examines++;
            if (!in_array($x[1], TC_CLES_TEXTE, true)) {
                $orphelins[] = basename($f) . ':' . ($no + 1) . ' « ' . $x[1] . ' »';
            }
        }
    }
}
v('des champs traduisibles ont bien été trouvés', $examines > 5, "$examines occurrence(s)");
v('aucun champ porteur de texte n\'est hors de la liste', $orphelins === [],
    implode(' · ', array_slice(array_unique($orphelins), 0, 8)));

// ── 8. 🔑 Les paliers du faisceau ne sont pas du texte ─────────────────────
// La conv Recover a posé la question avant d'écrire sa 0.12.0 : son §5 affirme
// que `souvent`/`parfois`/`rare` sont des IDENTIFIANTS de palier et que rien ne
// les traduit — donc qu'aucun mot seul n'entre dans un catalogue, donc qu'une
// passphrase ne peut pas être corrompue par une traduction.
//
// ⚠️ Cette propriété ne vit pas dans sa bibliothèque : elle vit ICI, dans ce
// qui traverse `tc_deep()`. Une promesse suffit jusqu'au jour où quelqu'un
// ajoute une clé à la liste blanche. Ces cas la gardent.
echo "\n8. Les paliers de fréquence survivent à la traduction\n";

$paliers = ['souvent', 'parfois', 'rare'];

v('`options` n\'est pas dans la liste blanche',
    !in_array('options', TC_CLES_TEXTE, true),
    'si elle y entrait, les paliers deviendraient du texte');

$apres = tc_deep(['options' => $paliers]);
v('⭐ sous `options`, les trois paliers sortent intacts',
    ($apres['options'] ?? []) === $paliers,
    json_encode($apres['options'] ?? null));

$apres = tc_deep(['frequence' => 'rare', 'declare' => 'rare', 'reel' => 'rare']);
v('⭐ et partout où le faisceau les porte',
    ($apres['frequence'] ?? '') === 'rare'
    && ($apres['declare'] ?? '') === 'rare'
    && ($apres['reel'] ?? '') === 'rare',
    json_encode($apres));

// 🔑 LE cas redouté, celui qui a motivé la question : `rare` est à la fois une
// valeur du faisceau ET un mot de la liste EFF anglaise. Si une traduction le
// touchait dans un payload de succès, la passphrase rendue une seule fois
// deviendrait fausse — et le compte irrécupérable, sans erreur pour le dire.
$mots = ['rare', 'challenge', 'expire', 'status', 'message'];
$apres = tc_deep(['ok' => true, 'credentials' => ['passphrase' => implode(' ', $mots),
                                                  'passphrase_mots' => $mots]]);
v('⭐⭐ une passphrase faite de mots-pièges traverse sans une lettre changée',
    ($apres['credentials']['passphrase'] ?? '') === implode(' ', $mots)
    && ($apres['credentials']['passphrase_mots'] ?? []) === $mots,
    json_encode($apres['credentials'] ?? null));

// ── 9. Rien de la bibliothèque n'entre dans tc_deep ────────────────────────
// Le §5 ne tient que si aucune réponse de SelfRecover ne traverse la traduction
// récursive. Ce cas le lit dans le code plutôt que de le croire : il extrait les
// arguments réels des appelants.
echo "\n9. Seules les classes du lab traversent la traduction récursive\n";

$autorisees = ['AttackSimulator', 'SuConsole'];
$appelants = [];
foreach (glob(__DIR__ . '/../public/api/*.php') ?: [] as $f) {
    if (preg_match_all('/tc_deep\(\s*\\\\?([A-Za-z\\\\]*?([A-Za-z]+))::/', (string) file_get_contents($f), $m)) {
        foreach ($m[2] as $classe) {
            $appelants[] = basename($f) . ' → ' . $classe;
            if (!in_array($classe, $autorisees, true)) {
                $appelants[] = '⚠️ ' . $classe;
            }
        }
    }
}
$intrus = array_values(array_filter($appelants, static fn (string $a): bool => str_starts_with($a, '⚠️')));

v('des appelants ont été trouvés', count($appelants) > 0, implode(' · ', $appelants));
v('⭐ aucun n\'y passe une réponse de la bibliothèque', $intrus === [],
    implode(' · ', $intrus) . ' — une structure de SelfRecover porterait `cle`, '
    . 'qui EST dans la liste blanche : son identifiant serait réécrit en silence');

echo "\n" . ($echecs === 0
    ? "OK — $reussites/$reussites contrôles conformes.\n"
    : "ÉCHEC — $echecs sur " . ($echecs + $reussites) . ".\n");
exit($echecs === 0 ? 0 : 1);
