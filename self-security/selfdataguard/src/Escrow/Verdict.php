<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

/**
 * What an arbiter answers about a case. Only Open lets a ceremony unseal; every
 * other answer is a refusal, written to the log as it was given — so that "no
 * case" and "the case table could not be read" do not look alike there.
 */
enum Verdict: string
{
    case Open        = 'open';
    case Closed      = 'closed';
    case Expired     = 'expired';
    case Unknown     = 'unknown';
    case Unavailable = 'unavailable';
}
