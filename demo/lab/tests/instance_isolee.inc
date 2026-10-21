<?php
/**
 * Une instance du lab à soi, pour un banc : la base ET les quatre secrets, dans un
 * dossier jetable — et la preuve, à la sortie, que `data/` n'a pas été touché.
 *
 * 🔑 Un banc qui ne déroutait que sa base écrivait les secrets réels de l'instance
 * locale, dans `demo/lab/data/`. Pire : quand un secret y manquait, il le recréait
 * en interrogeant SA base, qui ne porte rien — un sel neuf à côté d'une base qui
 * porte des codes de récupération (mesuré par la sixième passe d'audit du lot AAD).
 * Et un banc qui trouvait un secret posé par un autre ne passait que dans cet
 * ordre-là, ce que la CI respectait par hasard.
 *
 * À inclure en tête de banc, avant toute autre prise. Son extension n'est pas `.php` :
 * tout `tests/*.php` du lab est un banc que la CI doit lancer (`scripts/check-portes.sh`),
 * et ceci n'en est pas un. Une prise que le banc pose
 * ensuite l'emporte : celle-ci n'est qu'un défaut sûr.
 *
 * @return array<string, string> les prises posées, pour un sous-processus lancé avec
 *                               un environnement explicite (sans quoi il retombe sur `data/`)
 */

declare(strict_types=1);

function instance_isolee(): array
{
    static $prises = null;
    if ($prises !== null) {
        return $prises;
    }

    $dossier = sys_get_temp_dir() . '/lab-banc-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($dossier, 0700, true);
    $prises = [
        'LAB_DB_PATH'           => $dossier . '/lab.db',
        'LAB_BLINDKEY_PATH'     => $dossier . '/.blindkey',
        'LAB_SITESALT_PATH'     => $dossier . '/.sitesalt',
        'LAB_SERVERSECRET_PATH' => $dossier . '/.serversecret',
        'LAB_MODAUDIT_PATH'     => $dossier . '/.modaudit',
        'LAB_MODERATION_LOG'    => $dossier . '/moderation.log',
    ];
    foreach ($prises as $nom => $valeur) {
        putenv($nom . '=' . $valeur);
    }

    $data  = __DIR__ . '/../data';
    $avant = etat_data($data);
    register_shutdown_function(static function () use ($data, $avant, $dossier): void {
        foreach (glob($dossier . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dossier);
        $apres = etat_data($data);
        if ($apres !== $avant) {
            $touches = array_keys(array_diff_assoc($apres, $avant) + array_diff_key($avant, $apres));
            echo "\n  ✗ ce banc a écrit dans demo/lab/data/ : " . implode(', ', $touches)
                . " — une prise manque à son isolement\n";
            exit(1);
        }
    });

    return $prises;
}

/**
 * Un chemin dans le dossier jetable de ce banc, effacé à sa sortie, témoins compris.
 *
 * Un secret de banc rangé dans `/tmp` sous le numéro du processus y laissait son témoin
 * d'existence, et quand ce numéro revenait, le banc suivant refusait à tort (mesuré
 * par la septième passe d'audit).
 */
function chemin_isole(string $nom): string
{
    return dirname(instance_isolee()['LAB_DB_PATH']) . '/' . $nom;
}

/** @return array<string, string> fichier => taille et date, de `data/` tel qu'il est */
function etat_data(string $data): array
{
    clearstatcache();
    $etat = [];
    foreach (glob($data . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $f) {
        $etat[basename($f)] = @filesize($f) . ':' . @filemtime($f);
    }

    return $etat;
}

instance_isolee();
