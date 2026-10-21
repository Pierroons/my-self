#!/usr/bin/env php
<?php
/**
 * Chaque point d'entrée du lab, avec tout ce qu'il charge, trouve chaque classe du lab qu'il nomme.
 *
 * 🔑 Deux fois, une page est tombée sur une classe que rien ne chargeait : le simulateur
 * d'attaques sur `derive_cli.php`, puis l'enregistrement du profil sur `moderate.php` —
 * `Profile::save()` demandait à la modération si le compte était banni, et ni la route ni
 * `profile.php` ne la chargeaient (relevé par la neuvième passe d'audit). L'autoload ne
 * rattrape pas : il cherche `lib/Moderate.php`, et le fichier s'appelle `moderate.php`. Les
 * bancs ne le voyaient pas, parce qu'ils chargent eux-mêmes ce qu'ils appellent.
 *
 * Le contrôle est statique : depuis chaque route, chaque outil et la console, il suit les
 * `require` (y compris ceux d'une fonction, qui charge avant d'appeler), puis vérifie que
 * chaque classe du lab nommée par un fichier de cet ensemble y est déclarée, ou porte le
 * nom de son fichier. ⚠️ Il ne voit pas un nom de classe composé à l'exécution, ni un
 * chemin de `require` qui n'est pas `__DIR__ . '…'`.
 *
 * Usage : php tests/sanity_chargement.php
 */

declare(strict_types=1);

$echecs = 0;
$passes = 0;
function verifier(string $quoi, bool $vrai, string $detail = ''): void
{
    global $echecs, $passes;
    if ($vrai) {
        $passes++;
        echo "  ✓ $quoi\n";
    } else {
        $echecs++;
        echo "  ✗ $quoi" . ($detail !== '' ? " : $detail" : '') . "\n";
    }
}

const ESPACE = 'Pierroons\\MySelfLab\\';

/** @return array<string, string> classe du lab => fichier qui la déclare */
function declarations(string $lib): array
{
    $declare = [];
    foreach (glob("$lib/*.php") ?: [] as $f) {
        $t = token_get_all((string) file_get_contents($f));
        foreach ($t as $i => $j) {
            if (is_array($j) && in_array($j[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                for ($k = $i + 1; is_array($t[$k] ?? null) && $t[$k][0] === T_WHITESPACE; $k++);
                if (is_array($t[$k] ?? null) && $t[$k][0] === T_STRING) {
                    $declare[$t[$k][1]] = (string) realpath($f);
                }
            }
        }
    }

    return $declare;
}

/** @return array<string, true> le fichier et tout ce qu'il charge, hors `vendor/` */
function ensemble(string $f, array &$memo = []): array
{
    if (isset($memo[$f])) {
        return $memo[$f];
    }
    $memo[$f] = [$f => true];
    preg_match_all("~(?:require|include)(?:_once)?\\s*\\(?\\s*__DIR__\\s*\\.\\s*'([^']+)'~", (string) file_get_contents($f), $m);
    foreach ($m[1] as $rel) {
        $g = realpath(dirname($f) . $rel);
        if ($g !== false && !str_contains($g, '/vendor/')) {
            $memo[$f] += ensemble($g, $memo);
        }
    }

    return $memo[$f];
}

/** @return list<string> les classes du lab qu'un fichier nomme (appel statique ou `new`), `use` résolus */
function nommees(string $f): array
{
    $t = array_values(array_filter(
        token_get_all((string) file_get_contents($f)),
        static fn ($j): bool => !is_array($j) || !in_array($j[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
    ));
    $use = [];
    $espace = '';
    foreach ($t as $i => $j) {
        if (is_array($j) && $j[0] === T_NAMESPACE && is_array($t[$i + 1] ?? null)) {
            $espace = $t[$i + 1][1] . '\\';
        }
        if (is_array($j) && $j[0] === T_USE && is_array($t[$i + 1] ?? null)
            && in_array($t[$i + 1][0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
            $plein = ltrim($t[$i + 1][1], '\\');
            if (($t[$i + 2] ?? null) === '{') {
                for ($k = $i + 3; ($t[$k] ?? '}') !== '}'; $k++) {
                    if (is_array($t[$k]) && $t[$k][0] === T_STRING) {
                        $use[$t[$k][1]] = $plein . '\\' . $t[$k][1];
                    }
                }
            } else {
                $alias = is_array($t[$i + 2] ?? null) && $t[$i + 2][0] === T_AS
                    ? $t[$i + 3][1]
                    : substr((string) strrchr('\\' . $plein, '\\'), 1);
                $use[$alias] = $plein;
            }
        }
    }
    $noms = [];
    foreach ($t as $i => $j) {
        if (!is_array($j) || !in_array($j[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }
        $apres = $t[$i + 1] ?? null;
        $statique = is_array($apres) && $apres[0] === T_DOUBLE_COLON
            && !(is_array($t[$i + 2] ?? null) && $t[$i + 2][0] === T_CLASS);
        $neuf = is_array($t[$i - 1] ?? null) && $t[$i - 1][0] === T_NEW;
        if ((!$statique && !$neuf) || in_array(strtolower($j[1]), ['self', 'static', 'parent'], true)) {
            continue;
        }
        $plein = $j[0] === T_NAME_FULLY_QUALIFIED
            ? ltrim($j[1], '\\')
            : ($use[strtok($j[1], '\\')] ?? $espace . $j[1]);
        if (str_starts_with($plein, ESPACE)) {
            $noms[substr($plein, strlen(ESPACE))] = true;
        }
    }

    return array_keys($noms);
}

/** @return list<string> pour un point d'entrée : « Classe (fichier qui la nomme → fichier qui la déclare) » */
function manques(string $entree, array $declare): array
{
    $memo = [];
    $charge = ensemble((string) realpath($entree), $memo);
    $manque = [];
    foreach (array_keys($charge) as $f) {
        foreach (nommees($f) as $c) {
            $source = $declare[$c] ?? null;
            if ($source === null) {
                $manque[] = "$c (nommée par " . basename($f) . ', déclarée nulle part)';
            } elseif (!isset($charge[$source]) && basename($source) !== "$c.php") {
                $manque[] = "$c (" . basename($f) . ' → ' . basename($source) . ')';
            }
        }
    }

    return array_values(array_unique($manque));
}

$lab = (string) realpath(__DIR__ . '/..');
$declare = declarations("$lab/lib");
$entrees = array_merge(
    glob("$lab/public/*.php") ?: [],
    glob("$lab/public/api/*.php") ?: [],
    glob("$lab/tools/*.php") ?: [],
    glob("$lab/*.php") ?: [],
    ["$lab/selfrecover-su"]
);

echo "\n# Le contrôle mesure quelque chose\n";
verifier('les classes du lab sont trouvées', isset($declare['Moderate'], $declare['Profile'], $declare['DataGuard']), implode(', ', array_keys($declare)));
verifier('les points d\'entrée sont trouvés', count($entrees) >= 40, (string) count($entrees));
verifier(
    'un `use` du lab se résout : la console nomme `Db` par le sien',
    in_array('Db', nommees("$lab/selfrecover-su"), true)
);

// Contre-témoin : une route qui charge un fichier, lequel nomme une classe qu'il ne charge pas.
$bac = sys_get_temp_dir() . '/sanity_chargement_' . bin2hex(random_bytes(6));
mkdir("$bac/lib", 0700, true);
register_shutdown_function(static function () use ($bac): void {
    @unlink("$bac/lib/oubli.php");
    @unlink("$bac/route.php");
    @rmdir("$bac/lib");
    @rmdir($bac);
});
file_put_contents("$bac/lib/oubli.php", "<?php\nnamespace Pierroons\\MySelfLab;\nfunction f(): void { Moderate::config(); }\n");
file_put_contents("$bac/route.php", "<?php\nrequire_once __DIR__ . '/lib/oubli.php';\n");
verifier(
    'contre-témoin : une route dont un fichier nomme une classe qu\'il ne charge pas est signalée',
    manques("$bac/route.php", $declare) === ['Moderate (oubli.php → moderate.php)'],
    json_encode(manques("$bac/route.php", $declare), JSON_UNESCAPED_UNICODE)
);

echo "\n# Chaque point d'entrée trouve ce qu'il appelle\n";
$fautifs = [];
foreach ($entrees as $e) {
    $m = manques($e, $declare);
    if ($m !== []) {
        $fautifs[] = substr($e, strlen($lab) + 1) . ' : ' . implode(' · ', $m);
    }
}
verifier(
    '⭐ chaque route, outil et console trouve chaque classe du lab qu\'il nomme',
    $fautifs === [],
    implode(' | ', $fautifs)
);

$total = $passes + $echecs;
echo "\n" . ($echecs === 0 ? "OK — $passes/$total contrôles conformes." : "ÉCHEC — $echecs sur $total") . "\n";
exit($echecs === 0 ? 0 : 1);
