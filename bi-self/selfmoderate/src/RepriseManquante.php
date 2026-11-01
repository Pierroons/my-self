<?php

/**
 * Un compte porte une case de signalement ouverte et aucun signal dans
 * `mod_signaux` : la reprise des versions d'avant la 0.7.0 n'a pas été faite.
 *
 * C'est une erreur d'intégration, et elle se dit à qui peut la réparer : à la
 * lecture du compte lui-même, et à l'arbitre, dont les listes la lèvent. Le vote
 * d'un tiers dont le balayage croise ce compte ne tombe pas pour autant : le
 * balayage le saute et le consigne, sous le numéro que porte `$compte`. Une
 * classe à part, pour que ce saut ne rattrape aucune autre `LogicException`.
 */

declare(strict_types=1);

namespace Pierroons\SelfModerate;

final class RepriseManquante extends \LogicException
{
    public function __construct(string $message, public readonly int $compte)
    {
        parent::__construct($message);
    }
}
