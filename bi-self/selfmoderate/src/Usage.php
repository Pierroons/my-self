<?php

/**
 * Le régime du moteur, déclaré par l'hôte.
 *
 * Il décide de ce que la machine fait seule. Sur un hôte où un compte ne coûte
 * que de l'attente, une sanction automatique se fabrique avec des comptes : en
 * service, la machine calcule et signale, un arbitre sanctionne.
 */

declare(strict_types=1);

namespace Pierroons\SelfModerate;

enum Usage
{
    /** Ni ban ni perte du vote automatiques ; aucun réglage plus faible que `Config::prod()`. */
    case SERVICE;

    /** Ban et perte du vote automatiques, sur des paliers d'une heure au plus. */
    case DEMONSTRATION;
}
