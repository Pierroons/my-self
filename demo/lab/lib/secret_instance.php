<?php
/**
 * MySelf-Lab — les secrets propres à ce déploiement, et le seul endroit qui les pose.
 *
 * 🔑 **Un secret qui ne s'écrit pas doit se faire entendre.** Trois fonctions du lab
 * généraient chacune leur fichier sans lire le retour de `file_put_contents` : sur un
 * répertoire non inscriptible, l'écriture échouait, la relecture rendait `false`, et
 * `(string) false` donnait la chaîne vide. Le secret CSRF tombait alors à une constante
 * que n'importe quel lecteur du dépôt pouvait recalculer — sans erreur, sans trace, avec
 * une application qui continuait de servir.
 *
 * Le remède n'est pas de corriger les trois : c'est qu'il n'y en ait plus qu'un.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

final class SecretInstance
{
    /**
     * Rend le secret nommé, en le créant à la première demande.
     *
     * @param string      $nom          nom du fichier dans `data/`, point compris
     * @param int         $octets       octets d'aléa tirés à la création — le fichier en
     *                                  porte le double, l'écriture étant hexadécimale
     * @param int         $minLongueur  longueur en dessous de laquelle on refuse de servir
     * @param string|null $env          variable qui déroute le chemin, quand il en existe une
     *
     * @throws \RuntimeException à chaque étape qui peut échouer — jamais de valeur de repli.
     */
    public static function lire(string $nom, int $octets, int $minLongueur, ?string $env = null): string
    {
        $f = self::chemin($nom, $env);

        $dossier = dirname($f);
        if (!is_dir($dossier) && !@mkdir($dossier, 0700, true) && !is_dir($dossier)) {
            throw new \RuntimeException("Secret d'instance {$nom} : {$dossier} introuvable et non créable.");
        }

        if (!file_exists($f)) {
            self::poser($nom, $f, $octets);
        }

        // Un fichier présent mais illisible ou tronqué doit rendre le même refus qu'un
        // fichier absent : c'est la longueur qui qualifie le secret, pas son existence.
        $valeur = trim((string) @file_get_contents($f));
        if (strlen($valeur) < $minLongueur) {
            throw new \RuntimeException(
                "Secret d'instance {$nom} : vide ou trop court ({$f}) — refus de servir."
            );
        }

        return $valeur;
    }

    /**
     * Le chemin du secret, surcharge comprise.
     *
     * ⚠️ Une variable **posée mais vide** n'est pas une variable absente. `getenv` rend
     * `false` dans le premier cas et `''` dans le second — une unit systemd qui déclare
     * `Environment=LAB_SITESALT_PATH=` sans substituer la valeur tombe là. Retomber en
     * silence sur le chemin par défaut ferait tirer un sel neuf et rendrait introuvables
     * tous les codes déjà émis : c'est le seul repli silencieux qui restait, et il porte
     * sur le chemin plutôt que sur la valeur.
     */
    private static function chemin(string $nom, ?string $env): string
    {
        if ($env === null) {
            return __DIR__ . '/../data/' . $nom;
        }

        $surcharge = getenv($env);
        if ($surcharge === false) {
            return __DIR__ . '/../data/' . $nom;
        }
        if (trim($surcharge) === '') {
            throw new \RuntimeException(
                "Secret d'instance {$nom} : {$env} est posée mais vide — chemin indécidable,"
                . ' refus de retomber sur le chemin par défaut.'
            );
        }

        return $surcharge;
    }

    /**
     * Crée le secret : fichier temporaire, droits posés AVANT publication, renommage.
     *
     * 🔑 **Le `chmod` vient avant que le fichier porte son nom définitif.** Posé après,
     * il laisse une fenêtre pendant laquelle le secret existe en `0664` — mesuré, c'est
     * ce que rend `file_put_contents` sous un umask de 002 — et son échec ne se voyait
     * pas davantage que celui de l'écriture. Deux processus concurrents ne peuvent pas
     * non plus lire un fichier à moitié écrit : ils voient l'ancien nom, ou le nouveau.
     */
    private static function poser(string $nom, string $f, int $octets): void
    {
        $tmp = $f . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($tmp, bin2hex(random_bytes($octets))) === false) {
            throw new \RuntimeException("Secret d'instance {$nom} : écriture impossible dans {$f}.");
        }
        if (!@chmod($tmp, 0600)) {
            @unlink($tmp);
            throw new \RuntimeException(
                "Secret d'instance {$nom} : droits 0600 non posables sur {$f} — non publié."
            );
        }

        // Quelqu'un d'autre l'a posé pendant qu'on préparait le nôtre : le sien fait foi.
        // Écraser reviendrait à voler son secret à un processus qui l'a déjà en mémoire.
        if (file_exists($f)) {
            @unlink($tmp);

            return;
        }
        if (!@rename($tmp, $f)) {
            @unlink($tmp);
            throw new \RuntimeException("Secret d'instance {$nom} : publication impossible vers {$f}.");
        }
    }
}
