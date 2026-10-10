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
$nomsRequis = [];
foreach (SURVEILLEES as $fqcn) {
    $c = new ReflectionClass($fqcn);
    $ctor = $c->getConstructor();
    $requis[$fqcn] = $ctor ? $ctor->getNumberOfRequiredParameters() : 0;
    // ⚠️ Les NOMS comptent autant que le nombre : un argument nommé satisfait
    // son paramètre où qu'il soit, et un paramètre requis sauté par un nommé
    // n'est pas satisfait du tout. Compter sans distinguer rendait les deux
    // cas identiques — et le second lève à l'exécution.
    $nomsRequis[$fqcn] = $ctor
        ? array_map(
            static fn (ReflectionParameter $p): string => $p->getName(),
            array_slice($ctor->getParameters(), 0, $requis[$fqcn]),
        )
        : [];
    $court = substr($fqcn, strrpos($fqcn, '\\') + 1);
    $requis[$court] = $requis[$fqcn];
    $nomsRequis[$court] = $nomsRequis[$fqcn];
}

/**
 * Les arguments d'un appel : combien de POSITIONNELS, et le nom des nommés.
 *
 * 🔑 La distinction est tout l'intérêt : `f($a, $b, nomme: 1)` fournit deux
 * paramètres positionnels, pas trois, et le troisième requis n'est satisfait
 * que s'il s'appelle `nomme`.
 *
 * @return array{positionnels: int, nommes: list<string>}|null
 */
function compterArguments(array $jetons, int $depart): ?array
{
    $profondeur = 0;
    $args = 0;
    // ⚠️ On compte les segments NON VIDES, qu'une virgule les ferme ou que la
    // parenthèse les ferme. Compter les virgules et ajouter un donnerait un
    // argument FANTÔME à tout appel à virgule finale : `new Recovery($a, $b,
    // $c,)` compterait quatre positionnels pour trois.
    $segmentNonVide = false;
    $nommes = [];
    $nomEnCours = null;
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
                if ($segmentNonVide) {
                    $args++;
                }

                return ['positionnels' => max(0, $args - count($nommes)), 'nommes' => $nommes];
            }
        }
        if ($profondeur === 1) {
            if ($texte === ',') {
                if ($segmentNonVide) {
                    $args++;
                }
                $segmentNonVide = false;
                $nomEnCours = null;
            } elseif ($texte === ':' && $nomEnCours !== null) {
                // `nom:` ouvre un argument nommé — un `?:` ou un `::` n'arrive
                // pas ici, l'un étant un opérateur et l'autre un seul jeton.
                $nommes[] = $nomEnCours;
                $nomEnCours = null;
            } else {
                $segmentNonVide = true;
                $nomEnCours = (is_array($j) && $j[0] === T_STRING) ? $texte : null;
            }
        }
    }

    return null;   // parenthèse jamais refermée : on ne devine pas
}

$illisibles = [];
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
    $source = (string) file_get_contents($chemin);
    $jetons = @token_get_all($source);
    if (!$jetons) {
        continue;
    }
    // ⚠️ Les ALIAS d'import comptent : `use …\Device\Device as Protocole;` fait
    // de `new Protocole(...)` une construction d'appareil. Sans cette table, la
    // seule construction d'appareil du lab passait inaperçue — et c'est elle qui
    // levait à la première requête.
    $alias = [];
    if (preg_match_all('/^use\s+([\w\\\\]+)\s+as\s+(\w+)\s*;/m', $source, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $pos = strrpos($x[1], '\\');
            $alias[$x[2]] = $pos !== false ? substr($x[1], $pos + 1) : $x[1];
        }
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
        $court = $alias[$court] ?? $court;
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
            // ⚠️ Un appel qu'on ne sait pas lire n'est PAS conforme : il est NON
            // VÉRIFIÉ, et sauté en silence il compterait comme satisfait.
            $illisibles[] = sprintf(
                '%s:%d — %s',
                ltrim(str_replace($racine, '', $chemin), '/'),
                is_array($jetons[$i]) ? $jetons[$i][2] : 0,
                $court,
            );
            continue;
        }
        $manquants = [];
        foreach ($nomsRequis[$court] as $rang => $nom) {
            if ($rang >= $passes['positionnels'] && !in_array($nom, $passes['nommes'], true)) {
                $manquants[] = '$' . $nom;
            }
        }
        if ($manquants !== []) {
            $ligne = is_array($jetons[$i]) ? $jetons[$i][2] : 0;
            $echecs[] = sprintf(
                '%s:%d — %s ne reçoit pas %s (%d positionnel(s)%s)',
                ltrim(str_replace($racine, '', $chemin), '/'),
                $ligne,
                $court,
                implode(', ', $manquants),
                $passes['positionnels'],
                $passes['nommes'] === [] ? '' : ', nommés : ' . implode(', ', $passes['nommes']),
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

if ($illisibles !== []) {
    echo "❌ " . count($illisibles) . " construction(s) illisible(s) — non vérifiées, donc pas conformes :\n";
    foreach ($illisibles as $i) {
        echo "   $i\n";
    }
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
