<?php

/**
 * Les appelants de la bibliothèque lui passent-ils ce qu'elle exige ?
 *
 * 🔑 **L'invariant que ce banc fixe.** PHP ne vérifie le nombre d'arguments
 * qu'à l'exécution. Un constructeur qui gagne un paramètre obligatoire laisse
 * donc tous ses appelants compiler, passer `php -l`, passer une revue — et
 * lever un `ArgumentCountError` à la première requête réelle. Les bancs du
 * module ne les attrapent pas : ils construisent leurs propres instances.
 *
 * Le cas qui a motivé le contrôle : la 0.7.0 a rendu `ProfilDeploiement`
 * obligatoire en troisième position de `Recovery`. Les deux portes HTTP de
 * `demo/bi-self-duo` — les seules routes de récupération de la démo publique —
 * ont continué d'en passer deux. Rien n'a rougi, et les deux chemins de
 * récupération de la vitrine étaient morts.
 *
 * Le contrôle lit le source au lieu d'appeler : il doit couvrir les fichiers
 * que personne n'exécute en intégration continue, et c'est justement là que le
 * défaut vivait.
 *
 * Usage  : php tests/sanity_constructions.php
 * Sortie : 0 si chaque construction satisfait son constructeur, 1 sinon.
 */

declare(strict_types=1);

$module = dirname(__DIR__);
$depot  = dirname(dirname($module));
$racine = getenv('SR_APPELANTS') ?: $depot;   // surchargeable : canari

require_once $module . '/src/autoload.php';

/** Les classes dont une construction incomplète casse à l'exécution. */
const SURVEILLEES = [
    'Pierroons\SelfRecover\Recovery\Recovery',
    'Pierroons\SelfRecover\Recovery\Escalade',
    'Pierroons\SelfRecover\Device\Device',
];

/** Ce qu'on ne lit pas : la bibliothèque elle-même, et les copies de déploiement. */
const IGNORES = ['/bi-self/selfrecover/src/', '/deploy/', '/node_modules/', '/vendor/'];

$requis = [];
foreach (SURVEILLEES as $fqcn) {
    $c = new ReflectionClass($fqcn);
    $ctor = $c->getConstructor();
    $requis[$fqcn] = $ctor ? $ctor->getNumberOfRequiredParameters() : 0;
    $court = substr($fqcn, strrpos($fqcn, '\\') + 1);
    $requis[$court] = $requis[$fqcn];
}

/** Compte les arguments d'un appel, en ignorant les virgules imbriquées. */
function compterArguments(array $jetons, int $depart): ?int
{
    $profondeur = 0;
    $args = 0;
    $vu = false;
    for ($i = $depart, $n = count($jetons); $i < $n; $i++) {
        $j = $jetons[$i];
        $texte = is_array($j) ? $j[1] : $j;
        if (is_array($j) && in_array($j[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if (in_array($texte, ['(', '[', '{'], true)) {
            $profondeur++;
            if ($profondeur === 1) {
                continue;
            }
        } elseif (in_array($texte, [')', ']', '}'], true)) {
            $profondeur--;
            if ($profondeur === 0) {
                return $vu ? $args + 1 : 0;
            }
        }
        if ($profondeur === 1) {
            if ($texte === ',') {
                $args++;
            } else {
                $vu = true;
            }
        }
    }

    return null;   // parenthèse jamais refermée : on ne devine pas
}

$fichiers = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $chemin = str_replace('\\', '/', $f->getPathname());
    foreach (IGNORES as $ign) {
        if (str_contains($chemin, $ign)) {
            continue 2;
        }
    }
    $fichiers[] = $f->getPathname();
}
sort($fichiers);

$vues = 0;
$echecs = [];

foreach ($fichiers as $chemin) {
    $jetons = @token_get_all((string) file_get_contents($chemin));
    if (!$jetons) {
        continue;
    }
    for ($i = 0, $n = count($jetons); $i < $n; $i++) {
        if (!is_array($jetons[$i]) || $jetons[$i][0] !== T_NEW) {
            continue;
        }
        // Le nom de classe : une suite de jetons de nom, éventuellement qualifiée.
        $nom = '';
        $j = $i + 1;
        while ($j < $n) {
            $t = $jetons[$j];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                $j++;
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                $nom .= $t[1];
                $j++;
                continue;
            }
            break;
        }
        if ($nom === '') {
            continue;
        }
        $court = substr($nom, strrpos($nom, '\\') !== false ? strrpos($nom, '\\') + 1 : 0);
        if (!isset($requis[$court])) {
            continue;
        }
        // L'appel doit ouvrir sur une parenthèse, sinon ce n'est pas une construction.
        while ($j < $n && is_array($jetons[$j]) && $jetons[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $n || $jetons[$j] !== '(') {
            continue;
        }
        $vues++;
        $passes = compterArguments($jetons, $j);
        if ($passes === null) {
            continue;
        }
        if ($passes < $requis[$court]) {
            $ligne = is_array($jetons[$i]) ? $jetons[$i][2] : 0;
            $echecs[] = sprintf(
                '%s:%d — %s reçoit %d argument(s), %d requis',
                ltrim(str_replace($racine, '', $chemin), '/'),
                $ligne,
                $court,
                $passes,
                $requis[$court]
            );
        }
    }
}

echo "\n▸ classes surveillées : ", implode(', ', array_map(
    static fn ($c) => substr($c, strrpos($c, '\\') + 1) . ' (' . $requis[$c] . ' requis)',
    SURVEILLEES
)), "\n";
echo "▸ constructions trouvées hors de la bibliothèque : $vues\n\n";

if ($vues === 0) {
    echo "❌ aucune construction trouvée — le contrôle ne mesure rien.\n";
    exit(1);
}

foreach ($echecs as $e) {
    echo "  ❌ $e\n";
}

if ($echecs === []) {
    echo "✅ $vues/$vues — chaque construction satisfait son constructeur\n";
    exit(0);
}

echo "\n❌ ", count($echecs), " construction(s) sur $vues lèveront à l'exécution\n";
exit(1);
