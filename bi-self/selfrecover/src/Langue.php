<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover;

/**
 * La langue dans laquelle ce déploiement parle à son titulaire, et dans laquelle
 * il tire ses passphrases.
 *
 * 🔑 **Un seul choix pour les deux**, et c'est voulu : un déploiement qui
 * répondrait en français en remettant une passphrase de mots anglais demanderait
 * à son titulaire de recopier à la main des mots d'une langue qu'il ne lit
 * peut-être pas — sur le seul secret dont une faute de frappe lui coûte l'accès.
 *
 * ── La langue est OBLIGATOIRE, et sans défaut ───────────────────────────────
 *
 * Le même argument que `ProfilDeploiement`, et il n'est pas de symétrie : un
 * défaut serait ce choix imposé à tout le monde sans le dire. Rien ici ne peut
 * deviner la langue des pages qui entourent la bibliothèque — un déploiement
 * anglais répondrait dans une autre langue à chaque échec, sans que personne
 * l'ait décidé.
 *
 * `FR`  messages en français, passphrases tirées dans la liste française.
 * `EN`  messages en anglais, passphrases tirées dans la liste EFF anglaise.
 *
 * ⚠️ **Ce qu'elle ne gouverne pas : la reconnaissance d'une passphrase
 * apportée.** `Wordlist::inAnyList()` réunit les deux listes, à dessein — qui a
 * tiré ses dés sur la liste française doit pouvoir l'apporter à un déploiement
 * anglais, et le classement des essais plausibles vaut pour les deux. Changer la
 * langue d'un déploiement en service ne rend donc **aucun** secret existant
 * invalide : les passphrases déjà émises sont comparées à leur empreinte, et
 * seules les suivantes changent de langue.
 *
 * ⚠️ **Et elle ne traduit jamais une valeur.** Les paliers du faisceau
 * (`souvent`, `parfois`, `rare`) sont des identifiants, pas du texte : ils
 * voyagent tels quels. Un mot seul traduit à la sortie pourrait sortir d'un
 * payload de succès **à la place d'un mot de passphrase** — `rare` appartient
 * aux deux mondes —, et le titulaire noterait un secret qui n'ouvre plus rien.
 * Ce qui se traduit est nommé champ par champ, dans `Messages`.
 */
enum Langue: string
{
    case FR = 'fr';
    case EN = 'en';

    /**
     * Le code de liste de `Wordlist` pour cette langue.
     *
     * 🔑 La valeur de l'enum **est** ce code, et cette méthode existe pour que
     * ce soit une décision lisible plutôt qu'une coïncidence : le jour où une
     * troisième langue arrive sans liste de mots à elle, c'est ici qu'on dira
     * dans laquelle elle tire, et nulle part ailleurs.
     */
    public function listeDiceware(): string
    {
        return $this->value;
    }
}
