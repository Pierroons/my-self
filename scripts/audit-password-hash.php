<?php

declare(strict_types=1);

/**
 * Les appels à `password_hash()` portent-ils tous un profil explicite ?
 *
 * 🔑 **Pourquoi ce fichier existe plutôt qu'un `grep`.** Le contrôle précédent
 * cherchait `password_hash(<un argument>, PASSWORD_ARGON2ID)` sur une ligne.
 * Trois formes passaient au travers, chacune vérifiée avant d'être fermée :
 *
 *   password_hash($s, PASSWORD_ARGON2ID, [])   options vides = défauts de PHP
 *   password_hash(\n  $s,\n  PASSWORD_ARGON2ID\n)   `git grep` lit une ligne
 *   $algo = PASSWORD_ARGON2ID; password_hash($s, $algo)   l'algorithme se cache
 *
 * Un analyseur de jetons voit les trois : il ne lit pas des lignes mais des
 * appels, et les commentaires n'y sont pas du code — documenter le défaut ne le
 * fait donc pas rougir, sans avoir à filtrer les lignes qui commencent par `//`.
 *
 * ⚠️ **Fail-closed sur ce qu'il ne peut pas lire.** Un algorithme passé par
 * variable est signalé, non parce qu'il est fautif, mais parce que rien ici ne
 * peut prouver qu'il ne l'est pas. Une sonde qui laisse passer ce qu'elle ne
 * comprend pas rend le même vert qu'une sonde qui a compris.
 *
 * Usage : php scripts/audit-password-hash.php <fichier>...
 * Sortie : une ligne `fichier:ligne: motif` par appel douteux. Code 1 s'il y en a.
 */

/** Les jetons qui ne portent pas de code. */
function significatif(array|string $jeton): bool
{
    if (is_string($jeton)) {
        return true;
    }

    return !in_array($jeton[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
}

/**
 * Les arguments d'un appel, découpés à la virgule de premier niveau.
 *
 * @param  list<array|string> $jetons  jetons significatifs, à partir de la parenthèse ouvrante
 * @return list<list<array|string>>
 */
function arguments(array $jetons, int $debut): array
{
    $profondeur = 0;
    $args       = [];
    $courant    = [];

    for ($i = $debut, $n = count($jetons); $i < $n; $i++) {
        $j = $jetons[$i];
        $c = is_string($j) ? $j : '';

        if ($c === '(' || $c === '[' || $c === '{') {
            $profondeur++;
            if ($profondeur === 1) {
                continue; // la parenthèse de l'appel elle-même
            }
        } elseif ($c === ')' || $c === ']' || $c === '}') {
            $profondeur--;
            if ($profondeur === 0) {
                if ($courant !== []) {
                    $args[] = $courant;
                }

                return $args;
            }
        } elseif ($c === ',' && $profondeur === 1) {
            $args[]  = $courant;
            $courant = [];
            continue;
        }
        $courant[] = $j;
    }

    return $args; // parenthèse non fermée : le fichier ne compile pas, php -l le dira
}

/** La forme littérale d'un argument, pour la reconnaître. */
function texte(array $jetons): string
{
    return implode('', array_map(static fn ($j): string => is_string($j) ? $j : $j[1], $jetons));
}

$fichiers = array_slice($argv, 1);
$constats = [];

foreach ($fichiers as $fichier) {
    $source = @file_get_contents($fichier);
    if ($source === false) {
        continue;
    }
    $tous = token_get_all($source);
    $sig  = array_values(array_filter($tous, 'significatif'));

    foreach ($sig as $i => $jeton) {
        if (is_string($jeton) || $jeton[0] !== T_STRING || strtolower($jeton[1]) !== 'password_hash') {
            continue;
        }
        // Ni une méthode, ni une déclaration : un appel de fonction.
        $avant = $sig[$i - 1] ?? null;
        if (is_array($avant)
            && in_array($avant[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
            continue;
        }
        if (($sig[$i + 1] ?? null) !== '(') {
            continue;
        }
        $ligne = $jeton[2];
        $args  = arguments($sig, $i + 1);

        if (count($args) < 2) {
            continue; // `password_hash($p)` ne compile pas ; ce n'est pas notre sujet
        }
        $algo = texte($args[1]);

        // L'algorithme n'est pas une constante lisible : on ne peut rien prouver.
        if (!preg_match('/^\\\\?PASSWORD_[A-Z0-9_]+$/', trim($algo))) {
            $constats[] = "{$fichier}:{$ligne}: algorithme non lisible statiquement — « {$algo} »";
            continue;
        }
        if (!str_contains($algo, 'ARGON2')) {
            continue; // bcrypt et consorts n'ont pas le profil de ce module
        }
        if (count($args) < 3) {
            $constats[] = "{$fichier}:{$ligne}: Argon2id sans troisième argument — PHP impose ses défauts";
            continue;
        }
        $options = preg_replace('/\s+/', '', texte($args[2]));
        if ($options === '[]' || strtolower((string) $options) === 'array()') {
            $constats[] = "{$fichier}:{$ligne}: Argon2id avec des options VIDES — équivaut à ne rien passer";
        }
    }
}

foreach ($constats as $c) {
    echo $c, "\n";
}

exit($constats === [] ? 0 : 1);
