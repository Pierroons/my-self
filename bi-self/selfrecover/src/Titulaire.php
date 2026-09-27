<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover;

/**
 * Ce que l'appelant peut affirmer du titulaire du compte, au moment d'enrôler un
 * appareil.
 *
 * ── Pourquoi ce paramètre existe ────────────────────────────────────────────
 *
 * 🔴 **Enrôler un appareil ouvre le compte avec un seul secret.** Mesuré le
 * 13 août 2026, sur le fil, en trois requêtes : qui connaît le mot mémorisé
 * enrôle **sa propre** clé publique, s'authentifie avec **sa** clé privée, et
 * reçoit un mot de passe neuf en clair, les sessions du titulaire coupées. Les
 * codes de secours, la passphrase et le coffre de la victime n'y servent à rien —
 * le chemin ne passe pas par eux, il les contourne. Le message de succès annonce
 * « cet appareil + mot mémorisé » : l'appareil est celui de l'attaquant.
 *
 * Enrôler n'est donc pas récupérer. **On enrôle une machine quand on est déjà
 * connecté** ; qui a perdu son accès passe par les niveaux 1, 2 ou 3. Et un
 * appareil DÉJÀ enrôlé reste deux facteurs réels — son coffre chiffré et le mot.
 *
 * ── Ce que cette bibliothèque peut, et ce qu'elle ne peut pas ───────────────
 *
 * ⚠️ **Elle ne vérifie pas la session, et elle ne le peut pas.** Son contrat de
 * stockage sait *révoquer* des sessions, jamais en lire une, et son précédent
 * l'assume : `Escalade` ne vérifie jamais qu'un appelant a le droit de trancher.
 * Ce paramètre n'est donc pas une preuve — c'est une **affirmation**, qui devient
 * obligatoire, localisable, et relisible en revue.
 *
 * Ce qui revient à l'intégrateur, et que rien d'ici ne remplacera : **le nom du
 * compte vient de la session ouverte, jamais du corps de la requête.** Une route
 * qui lit ce nom dans ce que l'appelant envoie rouvre la porte du 13 août, quelle
 * que soit la valeur passée ici.
 */
enum Titulaire
{
    /**
     * L'appelant a vérifié que le titulaire est déjà authentifié, et le nom du
     * compte vient de cette session.
     */
    case AUTHENTIFIE;

    /**
     * L'appelant ne peut pas l'affirmer — l'enrôlement est refusé.
     *
     * 🔑 Ce cas existe pour que l'alternative soit nommée. Sans lui, le paramètre
     * serait un passage obligé qu'on remplit sans lire, et « obligatoire » ne
     * ferait réfléchir personne.
     */
    case NON_VERIFIE;
}
