<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard;

/**
 * Where the façade asks whether a recovery was accepted before it re-enrols,
 * reinstates, freezes or unfreezes a vault — gestures that take no secret.
 *
 * The recovery library issues a ticket in the transaction that accepts the
 * recovery, and keeps it; this answers which kind of acceptance a ticket is,
 * and for which userId. SelfRecover's arrives with its bridge
 * (`Pierroons\SelfRecover\Pont`). Without one given to the façade, those
 * gestures take no ticket, as before 0.8.0.
 *
 * The kinds are SelfRecover's words: `sanity_couplage_dataguard.php` keeps the
 * two libraries in agreement.
 */
interface RecoveryTickets
{
    /** Level 3: an arbiter granted the recovery; the vault is re-enrolled. */
    public const LEVEL_3 = 'niveau_3';
    /** The holder came back within the window: their archive returns, then the vault freezes. */
    public const HOLDER_RETURN = 'retour_titulaire';
    /** The arbiter closed the dispute of that return: the vault unfreezes. */
    public const CLOSURE = 'cloture';

    /** The kind of $ticket if it was issued for $userId; null when it was not, or for another userId. */
    public function kindOf(string $ticket, string $userId): ?string;
}
