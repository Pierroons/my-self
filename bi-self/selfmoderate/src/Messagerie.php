<?php

/**
 * Où passent les messages privés de l'hôte : c'est là que le quorum lit qui est
 * lié à qui. L'hôte le déclare ; le moteur ne le devine pas.
 */

declare(strict_types=1);

namespace Pierroons\SelfModerate;

enum Messagerie
{
    /** La table `dm`, datée par le serveur en secondes : le quorum y lit les liens. */
    case DM;

    /** Une autre messagerie, ou aucune : le moteur ne lit jamais `dm`, et chaque votant fait sa voix. */
    case HORS_MOTEUR;
}
