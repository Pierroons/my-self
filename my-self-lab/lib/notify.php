<?php
/**
 * Notification sortante — prévenir qu'un rapport red team est arrivé.
 *
 * Sans elle, rien ne signale un rapport : il faut penser à ouvrir /admin.php.
 * Un chercheur qui signale une faille et n'obtient aucun signe de vie pendant
 * plusieurs jours en conclut que le lab est abandonné, et la prochaine faille
 * il la gardera pour lui.
 *
 * 🔑 **Ce lab est une machine qu'on donne à attaquer.** Trois précautions en
 * découlent, et elles expliquent la forme de ce fichier :
 *
 *   1. Les identifiants viennent de l'environnement, jamais du dépôt — qui est
 *      public. Sans eux, la notification est simplement inactive : le lab
 *      fonctionne, il ne prévient pas.
 *   2. Le compte de publication est **write-only** sur son seul topic. Un
 *      attaquant qui prendrait le serveur pourrait donc écrire des messages,
 *      mais ni lire l'historique des alertes, ni toucher aux autres topics.
 *   3. Le message ne contient **rien du rapport** : ni titre, ni description,
 *      ni pseudo. Uniquement le fait qu'il y en a un de plus, et son numéro.
 *      Le contenu reste chiffré en base, lisible seulement dans le panneau.
 *      Une notification qui recopierait la faille l'exposerait au passage.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

final class Notify
{
    /** Délai court : une notification n'a jamais à retarder une réponse HTTP. */
    private const TIMEOUT = 4;

    /**
     * Lit `data/.notify` : une ligne `url`, une ligne `utilisateur:motdepasse`.
     * Le fichier peut être absent — c'est le cas nominal d'un déploiement qui
     * n'a pas de canal, et il ne doit rien casser.
     */
    private static function config(): ?array
    {
        $f = __DIR__ . '/../data/.notify';
        if (!is_readable($f)) {
            return null;
        }
        $l = array_values(array_filter(array_map('trim', file($f) ?: [])));
        return count($l) >= 2 ? [$l[0], $l[1]] : null;
    }

    /**
     * Signale l'arrivée d'un rapport. Ne lève jamais : l'échec d'une alerte ne
     * doit pas faire échouer l'envoi du rapport lui-même, qui compte davantage.
     */
    public static function nouveauRapport(int $id, string $severite): void
    {
        $conf = self::config();
        if ($conf === null) {
            return; // canal non configuré : silence, pas d'erreur
        }
        [$url, $auth] = $conf;

        // Volontairement sans détail : le numéro suffit à aller voir.
        $corps = sprintf('Rapport #%d reçu (sévérité déclarée : %s). À lire dans le panneau.', $id, $severite);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $corps,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_USERPWD        => $auth,
            CURLOPT_HTTPHEADER     => [
                'Title: Nouveau rapport',
                'Priority: default',
                'Tags: mag',
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
