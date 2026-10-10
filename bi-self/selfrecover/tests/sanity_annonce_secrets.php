<?php

/**
 * Un chemin qui rend un secret neuf le dit-il à celui qui le reçoit ?
 *
 * 🔑 **L'invariant que ce banc fixe.** Quand la bibliothèque remplace un secret
 * que l'utilisateur détient sur papier, le message qu'elle rend doit lui dire de
 * le renoter. Sinon la perte est silencieuse : la personne range son papier, et
 * découvre des mois plus tard — au moment précis où elle en a besoin — qu'il ne
 * vaut plus rien. Aucune erreur ne se produit, aucun journal ne s'en émeut ;
 * c'est ce qui rend ce défaut-là pire qu'une panne.
 *
 * Le cas qui a motivé le contrôle : `parCode()` remplaçait la passphrase ET le
 * mot de passe, et rendait « Accès rendu. Le mot de récupération, lui, ne change
 * pas. » — un message qui nomme ce qui SURVIT et tait ce qui MEURT. Le niveau 3
 * savait le dire depuis toujours (« Note ces codes et cette passphrase »), les
 * deux autres niveaux non.
 *
 * Le contrôle est statique : il lit le source plutôt que d'appeler les chemins,
 * parce qu'il doit attraper aussi ceux qui n'existent pas encore.
 *
 * Usage  : php tests/sanity_annonce_secrets.php
 * Sortie : 0 si chaque retour porteur de secret annonce sa consigne, 1 sinon.
 */

declare(strict_types=1);

$module = dirname(__DIR__);
$racine = getenv('SR_SRC') ?: $module . '/src';   // surchargeable : canari

/** Les clés dont la présence dans un retour signale un secret remis à l'utilisateur. */
const CLES_SECRETES = ['mot_de_passe', 'passphrase', 'codes'];

/**
 * Ce qui vaut consigne : le message doit dire de noter, ou prévenir de l'unique
 * affichage. Une marque par langue du catalogue — un texte anglais ne porte pas
 * « noter », et exiger les marques françaises partout aurait rendu la garde
 * fausse dès la première traduction.
 */
const MARQUES_CONSIGNE = [
    'fr' => ['note', 'noter', 'réaffich', 'reaffich'],
    'en' => ['write', 'shown again', 'not be shown'],
];

/**
 * 🔑 **La garde suit le texte jusqu'au catalogue.**
 *
 * Un retour qui compose son message par `Messages::dire($…, 'cle')` ne porte
 * plus la consigne dans son source : la chercher là rendrait la garde aveugle
 * au moment même où le texte devient traduisible. On remonte donc à l'entrée du
 * catalogue, et on l'exige dans CHAQUE langue — c'est plus que ce que le
 * littéral français garantissait.
 */
function consigneTenue(string $texte): bool
{
    if (preg_match_all("/Messages::dire\([^,]+,\s*'([^']+)'/", $texte, $m) === 0) {
        // Pas de catalogue en jeu : le texte est dans le source, comme avant.
        foreach (MARQUES_CONSIGNE as $marques) {
            foreach ($marques as $marque) {
                if (mb_stripos($texte, $marque) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    // ⚠️ Le catalogue se lit en SOURCE, pas par la classe chargée : ce banc
    // examine un arbre que `$racine` désigne, et sous canari ce n'est pas celui
    // de l'autoload. Charger les classes ferait juger un autre code que celui
    // qu'on lit — et le canari rendrait vert sur le fichier intact.
    $catalogue = catalogueEnSource();

    foreach (array_keys(MARQUES_CONSIGNE) as $langue) {
        $porte = false;
        foreach ($m[1] as $cle) {
            $brut = $catalogue[$cle][$langue] ?? null;
            if ($brut === null) {
                continue;
            }
            foreach (MARQUES_CONSIGNE[$langue] as $marque) {
                if (mb_stripos($brut, $marque) !== false) {
                    $porte = true;
                    break 2;
                }
            }
        }
        if (!$porte) {
            return false;
        }
    }

    return true;
}

/**
 * Le catalogue tel que le source le déclare : `cle => ['fr' => …, 'en' => …]`.
 *
 * Les textes s'écrivent sur plusieurs lignes, concaténées par `.` : on réunit
 * les littéraux d'une même entrée plutôt que de ne lire que le premier, sans
 * quoi une consigne placée en seconde ligne passerait pour absente.
 */
function catalogueEnSource(): array
{
    global $racine;

    $source = @file_get_contents(rtrim($racine, '/') . '/Messages.php');
    if ($source === false) {
        return [];
    }

    $entrees = [];
    if (preg_match_all(
        "/'([a-z0-9_.]+)'\s*=>\s*\[(.+?)\],\n/s",
        $source,
        $blocs,
        PREG_SET_ORDER,
    ) === 0) {
        return [];
    }
    foreach ($blocs as $bloc) {
        foreach (['fr', 'en'] as $langue) {
            if (preg_match("/'" . $langue . "'\s*=>\s*((?:'(?:[^'\\\\]|\\\\.)*'\s*\.?\s*)+)/s", $bloc[2], $m2) === 1) {
                $morceaux = [];
                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m2[1], $m3);
                foreach ($m3[1] as $morceau) {
                    $morceaux[] = str_replace(["\\'", '\\\\'], ["'", '\\'], $morceau);
                }
                $entrees[$bloc[1]][$langue] = implode('', $morceaux);
            }
        }
    }

    return $entrees;
}

$fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine));
$aExaminer = [];
foreach ($fichiers as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $aExaminer[] = $f->getPathname();
    }
}
sort($aExaminer);

$total = 0;
$echecs = [];

foreach ($aExaminer as $chemin) {
    $lignes = file($chemin, FILE_IGNORE_NEW_LINES);
    $n = count($lignes);
    for ($i = 0; $i < $n; $i++) {
        if (!preg_match('/^\s*return \[/', $lignes[$i])) {
            continue;
        }
        // Le tableau court jusqu'à sa fermeture : on s'arrête à la ligne qui
        // referme, sans compter les crochets imbriqués — les retours de cette
        // bibliothèque sont plats.
        $bloc = [];
        for ($j = $i; $j < $n && $j < $i + 20; $j++) {
            $bloc[] = $lignes[$j];
            if (preg_match('/^\s*\];/', $lignes[$j])) {
                break;
            }
        }
        $texte = implode("\n", $bloc);

        $secrets = [];
        foreach (CLES_SECRETES as $cle) {
            if (preg_match("/'" . $cle . "'\s*=>/", $texte)) {
                $secrets[] = $cle;
            }
        }
        if ($secrets === []) {
            continue;
        }
        // Un retour qui ne porte QUE la clé en position de paramètre attendu,
        // sans message, n'est pas un retour d'API : on exige le message.
        $total++;
        if (!consigneTenue($texte)) {
            // Relatif à la racine examinée, et non au module : sous canari la
            // racine est ailleurs, et un chemin absolu nommerait le compte qui
            // lance — une sortie de banc finit parfois collée dans une issue.
            $echecs[] = sprintf(
                '%s:%d — rend %s sans dire de le noter',
                ltrim(str_replace($racine, '', $chemin), '/'),
                $i + 1,
                implode(' + ', $secrets)
            );
        }
    }
}

echo "\n▸ source examinée : ", basename($racine), "\n";
echo "▸ retours porteurs d'un secret : $total\n\n";

if ($total === 0) {
    echo "❌ aucun retour porteur de secret trouvé — le contrôle ne mesure rien.\n";
    exit(1);
}

foreach ($echecs as $e) {
    echo "  ❌ $e\n";
}

if ($echecs === []) {
    echo "✅ $total/$total — chaque secret rendu s'accompagne de sa consigne\n";
    exit(0);
}

echo "\n❌ ", count($echecs), " retour(s) sur $total laissent la perte silencieuse\n";
exit(1);
