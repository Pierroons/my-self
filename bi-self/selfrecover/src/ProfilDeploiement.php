<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover;

use InvalidArgumentException;

/**
 * Ce que l'origine d'un appelant vaut, dans ce déploiement, comme clé de frein.
 *
 * 🔑 **Le profil ne décrit pas un réseau, il décrit une clé.** La question n'est
 * pas « où est-ce servi » mais « deux appelants différents arrivent-ils sous deux
 * origines différentes, et cette bibliothèque peut-elle les lire ». Un service
 * caché répond non. Une base qui ne porte pas de colonne d'adresse répond non
 * aussi, et se déclare donc de la même façon, quel que soit son transport.
 *
 * ── Le profil est OBLIGATOIRE, et sans défaut ───────────────────────────────
 *
 * Les freins de cette bibliothèque comptent par adresse et par compte. Ce que
 * vaut une adresse ne se déduit pas du code : il dépend de la façon dont le
 * service est servi, donc de l'intégrateur. Un défaut serait ce choix imposé à
 * tout le monde sans le dire — c'est déjà l'argument du `mode` obligatoire de
 * `client/sr-derive.js`, et c'est le même ici.
 *
 * `CLEARWEB`   chaque appelant a son adresse, et le frein par adresse mord.
 *              Une adresse absente y est une erreur d'intégration : le frein
 *              devient inerte, en silence, et rien ne le dit.
 *
 * `TOR_ONION`  aucune origine exploitable par appelant : tous partagent la même,
 *              ou le déploiement n'en tient pas. Le frein par adresse n'y a aucun
 *              sens — appliqué, il refuse tout le monde ensemble dès les premiers
 *              échecs de n'importe qui. Le frein par compte est le seul rempart,
 *              voir `Recovery::etiquetteEchecsL2()`.
 *              ⚠️ Ce nom dit le cas qui l'a fait écrire, pas une condition de
 *              transport. `demo/bi-self-duo` le déclare en étant servi sur le web
 *              ordinaire : sa table ne porte pas d'adresse, et son frein est par
 *              session. Le nommer autrement ferait croire à un choix de réseau.
 *
 * 🔑 **Ce profil n'est pas déclaratif** : `verifierOrigine()` refuse l'argument
 * qui le contredit, aux points d'entrée.
 */
enum ProfilDeploiement: string
{
    case CLEARWEB = 'clearweb';
    case TOR_ONION = 'tor-onion';

    /**
     * L'origine reçue est-elle cohérente avec le profil ?
     *
     * ⚠️ **Lève plutôt que corriger en silence.** Forcer l'origine à `null`, ou
     * en inventer une, laisserait un service qui a l'air de fonctionner — c'est ce
     * que les deux cas décrits plus haut coûtent.
     *
     * Trois appelants : la récupération, l'enrôlement d'un appareil et l'ouverture
     * d'un dossier. Ils écrivent dans les mêmes compteurs, d'où un seul profil pour
     * les trois.
     */
    public function verifierOrigine(?string $ip): void
    {
        // Une chaîne vide n'est pas une adresse : la bibliothèque la traite déjà
        // comme une absence, ici comme ailleurs. La refuser ferait de ce profil un
        // changement de sens, et non un garde-fou.
        if ($this === self::TOR_ONION && $ip !== null && trim($ip) !== '') {
            throw new InvalidArgumentException(
                'Profil tor-onion : ne transmets aucune origine. Celle que le serveur voit est la même '
                . 'pour tout le monde, et le frein par adresse refuserait tous les visiteurs ensemble.'
            );
        }
        if ($this === self::CLEARWEB && ($ip === null || trim($ip) === '')) {
            throw new InvalidArgumentException(
                "Profil clearweb : transmets l'origine de l'appelant. Sans elle le frein par adresse ne "
                . 'compte rien, sans que rien ne le dise. Derrière un service caché, choisis tor-onion.'
            );
        }
    }
}
