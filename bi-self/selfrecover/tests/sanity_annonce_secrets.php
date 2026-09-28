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

/** Ce qui vaut consigne : le message doit dire de noter, ou prévenir de l'unique affichage. */
const MARQUES_CONSIGNE = ['note', 'noter', 'réaffich', 'reaffich'];

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
        $annonce = false;
        foreach (MARQUES_CONSIGNE as $marque) {
            if (mb_stripos($texte, $marque) !== false) {
                $annonce = true;
                break;
            }
        }
        if (!$annonce) {
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
