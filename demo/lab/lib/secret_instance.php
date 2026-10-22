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
     * Plancher d'un secret de déploiement, en caractères.
     *
     * 🔑 **Le contrôle appartient à la fonction, pas à l'appelant.** `$minLongueur`
     * était laissé à la libre appréciation de chaque appel : les trois du lab
     * demandaient 32, `AuditLog` de SelfDataGuard se contentait de 16, et l'adaptateur
     * d'un intégrateur portait les deux valeurs dans deux fichiers voisins. Une même sorte de
     * secret, trois planchers, et rien qui rende l'écart visible — un secret de 28
     * caractères passait d'un côté et faisait rendre « Service mal configuré » de
     * l'autre, sans que le message nomme la vraie cause (mesuré sur un déploiement
     * intégrateur, 15/09/2026).
     *
     * `lire()` refuse donc un `$minLongueur` inférieur : un appelant peut être plus
     * exigeant que le plancher, jamais moins. Le contrôle ne dépend plus de ce que
     * l'appel demande : une garde qui vit chez l'appelant n'est pas une garde, c'est
     * une convention — et une convention se contourne par le prochain appelant.
     *
     * `scripts/check-plancher-secret.sh` fait rougir la CI si une déclaration dérive.
     */
    public const PLANCHER = 32;

    /** Suffixe du témoin posé à côté d'un secret servi : il a existé ici. */
    public const A_EXISTE = '.existe';

    /**
     * Rend le secret nommé, en le créant à la première demande.
     *
     * @param string      $nom          nom du fichier dans `data/`, point compris
     * @param int         $octets       octets d'aléa tirés à la création — le fichier en
     *                                  porte le double, l'écriture étant hexadécimale
     * @param int         $minLongueur  longueur en dessous de laquelle on refuse de servir
     * @param string|null $env          variable qui déroute le chemin, quand il en existe une
     * @param callable(): ?string $dependances ce que le déploiement a déjà bâti sur ce
     *                                  secret, en une phrase qui le nomme — ou `null` s'il
     *                                  n'y a rien. Interrogé seulement quand le secret manque.
     *
     * 🔑 **Un secret absent n'est une panne que si quelque chose en dépend.** Le poser
     * est juste sur une instance neuve, et destructeur sur une instance qui a déjà
     * chiffré, signé ou indexé sous lui : un secret neuf rendrait tout cela illisible
     * ou introuvable, sans un mot. La question est donc posée ici, au seul point par
     * lequel passent tous les secrets, et non chez chacun de leurs appelants : une
     * garde posée chez l'appelant laisse toujours un voisin qui ne la pose pas. Elle
     * est obligatoire pour la même raison — un secret qui n'a rien à protéger le dit
     * en rendant `null`, il ne le dit pas en se taisant.
     *
     * ⚠️ La question ne voit que la base du lab (`Db::path()`) : un processus qui
     * déroute la base sans dérouter le secret interroge une autre base que celle que
     * le secret sert. D'où un second témoin, qui ne dépend d'aucune base : à côté de
     * chaque secret servi, `<secret>.existe` dit qu'il a existé ici. Un secret absent
     * dont le témoin est là a disparu, et ne se remplace pas sans décision humaine.
     *
     * @throws \RuntimeException à chaque étape qui peut échouer — jamais de valeur de repli.
     */
    public static function lire(string $nom, int $octets, int $minLongueur, ?string $env, callable $dependances): string
    {
        if ($minLongueur < self::PLANCHER) {
            throw new \RuntimeException(
                "Secret d'instance {$nom} : plancher demandé {$minLongueur}, minimum du projet "
                . self::PLANCHER . ' — un appelant peut être plus exigeant, jamais moins.'
            );
        }
        $f = self::chemin($nom, $env);

        $dossier = dirname($f);
        if (!is_dir($dossier) && !@mkdir($dossier, 0700, true) && !is_dir($dossier)) {
            throw new \RuntimeException("Secret d'instance {$nom} : {$dossier} introuvable et non créable.");
        }

        if (!file_exists($f)) {
            // Le témoin ne se pose qu'après le secret : vu, il dit qu'un secret a été
            // publié — à l'instant peut-être, par une requête concurrente. Le secret se
            // relit donc avant de conclure qu'il a disparu (mesuré : sur soixante courses
            // de seize premières requêtes, une en refusait une, à tort).
            clearstatcache(true, $f);
            if (file_exists($f . self::A_EXISTE) && !file_exists($f)) {
                throw new \RuntimeException(
                    "Secret d'instance {$nom} absent ({$f}) alors qu'il a existé ici ({$f}" . self::A_EXISTE . ') : '
                    . 'restaurer le secret. Pour repartir d\'un secret neuf en connaissance de cause, '
                    . 'retirer aussi le témoin — ce qui en dépendait sera perdu.'
                );
            }
        }
        if (!file_exists($f)) {
            $enDepend = $dependances();
            if ($enDepend !== null) {
                throw new \RuntimeException(
                    "Secret d'instance {$nom} absent ({$f}) alors que {$enDepend} en dépend : un secret "
                    . 'neuf le rendrait inutilisable sans le dire. Refus de servir — restaurer le secret.'
                );
            }
            self::poser($nom, $f, $octets);
        }

        // Un fichier présent mais illisible ou tronqué est refusé comme un fichier absent :
        // c'est la longueur qui qualifie le secret, pas son existence. Mais sous son nom —
        // un droit de lecture manquant, la cause la plus probable quand le site et la
        // console tournent sous deux identités, se disait « vide ou trop court » et envoyait
        // vers un fichier qu'on trouvait plein (mesuré par la neuvième passe d'audit).
        if (!is_readable($f)) {
            throw new \RuntimeException(
                "Secret d'instance {$nom} présent mais illisible pour l'identité qui sert ({$f}) — "
                . 'des droits à revoir, pas un secret à remplacer : ce qui en dépend serait perdu.'
            );
        }
        $valeur = trim((string) @file_get_contents($f));
        if (strlen($valeur) < $minLongueur) {
            throw new \RuntimeException(
                "Secret d'instance {$nom} : vide ou trop court ({$f}) — refus de servir."
            );
        }
        // Posé aussi pour un secret d'avant ce témoin : une instance en service le gagne
        // à sa première lecture, et sa protection avec. Jamais bloquant : un secret qui se
        // lit se sert, témoin ou pas. ⚠️ Mais ne pas pouvoir le poser n'est pas sans
        // risque — l'identité qui sert le secret peut ne pas écrire son dossier quand une
        // autre le peut, et celle-là recréerait le secret sans témoin pour l'arrêter
        // (mesuré par la septième passe d'audit). Le manque se dit donc au journal, et
        // le déploiement pose les témoins lui-même (`deploy/ctf-s2/README.md`).
        if (!file_exists($f . self::A_EXISTE) && @touch($f . self::A_EXISTE) === false) {
            error_log(sprintf(
                "Secret d'instance %s : témoin d'existence non posable à côté de %s — un secret disparu "
                . "pourrait être recréé sans bruit par une identité qui écrit ce dossier. Le poser : touch %s",
                $nom,
                $f,
                $f . self::A_EXISTE
            ));
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
    public static function chemin(string $nom, ?string $env): string
    {
        return ($env === null ? null : self::prise($env)) ?? __DIR__ . '/../data/' . $nom;
    }

    /**
     * La valeur d'une prise d'environnement qui déroute un chemin, ou `null` si elle
     * n'est pas posée.
     *
     * 🔑 La règle de toutes les prises du lab, en un seul endroit : posée mais vide
     * n'est pas absente, et retomber sur le chemin par défaut servirait — ou
     * interrogerait — autre chose que ce que l'exploitant a voulu désigner. Écrite
     * prise par prise, elle manquait toujours à la suivante (`LAB_DB_PATH`, puis le
     * journal de modération et ceux de la console SU, relevés par deux passes d'audit).
     */
    public static function prise(string $env): ?string
    {
        $valeur = getenv($env);
        if ($valeur === false) {
            return null;
        }
        if (trim($valeur) === '') {
            throw new \RuntimeException(
                "{$env} est posée mais vide — chemin indécidable, refus de retomber sur le chemin par défaut."
            );
        }

        return $valeur;
    }

    /**
     * Crée le secret : fichier temporaire, droits posés AVANT publication, lien vers le nom définitif.
     *
     * 🔑 **Le `chmod` vient avant que le fichier porte son nom définitif.** Posé après,
     * il laisse une fenêtre pendant laquelle le secret existe en `0664` — mesuré, c'est
     * ce que rend `file_put_contents` sous un umask de 002 — et son échec ne se voyait
     * pas davantage que celui de l'écriture. Deux processus concurrents ne peuvent pas
     * non plus lire un fichier à moitié écrit : ils voient l'ancien nom, ou le nouveau.
     */
    private static function poser(string $nom, string $f, int $octets): void
    {
        // Sans `link()`, il n'existe pas de publication qui refuse un nom déjà pris :
        // renommer écraserait le secret qu'un autre processus sert déjà. Refuser avant
        // d'écrire quoi que ce soit, plutôt que laisser un candidat sur le disque.
        if (!function_exists('link')) {
            throw new \RuntimeException(
                "Secret d'instance {$nom} : link() est désactivée (disable_functions) — aucune "
                . "publication sûre n'existe sans elle. Poser {$f} à la main, en 0600, ou rendre link() à PHP."
            );
        }
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
        // 🔑 `link()` échoue si le nom est pris, en une seule opération ; tester puis
        // renommer laissait la fenêtre ouverte, et `rename()` écrase — mesuré : seize
        // premières requêtes simultanées tiraient jusqu'à six secrets différents.
        try {
            $publie = @link($tmp, $f);
        } finally {
            @unlink($tmp);
        }
        if (!$publie && !file_exists($f)) {
            throw new \RuntimeException("Secret d'instance {$nom} : publication impossible vers {$f}.");
        }
    }
}
