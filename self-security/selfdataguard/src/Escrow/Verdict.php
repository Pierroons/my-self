<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

/**
 * What an arbiter answers about a case. Only Open and OpenWithConsent let a
 * ceremony unseal; every other answer is a refusal, written to the log as it
 * was given — so that "no case" and "the case table could not be read" do not
 * look alike there.
 *
 * OpenWithConsent asks for the holder's consent besides: the ceremony opens a
 * compartment sealed to a keeper only once that keeper attests the holder's
 * consent to the case (SelfDataGuard::consentToEscrowRead()), checked off the
 * machine that serves, and opens no compartment sealed to the administrator
 * alone, for which nothing checks it. Without the holder, the ceremony does
 * not read it. Code changed on the machine that serves skips the check: the
 * keeper then still holds the read to its delay and to the holder's
 * objection, and the holder sees the release.
 */
enum Verdict: string
{
    case Open            = 'open';
    case OpenWithConsent = 'open-with-consent';
    case Closed          = 'closed';
    case Expired         = 'expired';
    case Unknown         = 'unknown';
    case Unavailable     = 'unavailable';
}
