<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

/**
 * What a ceremony answers, field by field, when it compares a declared value
 * with an escrow field instead of handing the field out
 * (EscrowCeremony::compareEscrow()).
 *
 * Differs says no more than "not equal under the normalization the comparison
 * named": a format, an accent or a hyphen the normalization does not fold is
 * enough, so it never tells who declared the value. Unavailable is a field
 * the escrow does not hold, or one that does not open: nothing was compared.
 */
enum Comparison: string
{
    case Matches     = 'matches';
    case Differs     = 'differs';
    case Unavailable = 'unavailable';
}
