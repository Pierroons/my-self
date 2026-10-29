<?php

/**
 * Un écrivain du moteur a lu l'état par la lecture publique.
 *
 * C'est une erreur d'intégration — le plus souvent un journal qui rappelle le
 * moteur depuis `inscrire()` —, et elle remonte telle quelle là où le moteur
 * contient les refus du journal : rangée en panne du journal, elle tiendrait le
 * compte hors du vote et accuserait un journal qui fonctionne. Une classe à
 * part, parce qu'un journal conforme peut refuser un acte par n'importe quelle
 * autre exception, `LogicException` comprise.
 */

declare(strict_types=1);

namespace Pierroons\SelfModerate;

final class LectureDansUneEcriture extends \LogicException
{
}
