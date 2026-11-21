<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Throttle;

/** What an admitted attempt came to. */
enum Outcome
{
    /** The secret opened the vault. */
    case Opened;

    /**
     * The secret did not open it: a wrong secret, a lock the vault does not
     * hold, an account or an archive that does not exist — the four answer
     * alike, and after the same derivation.
     */
    case Refused;

    /**
     * The attempt ended without telling whether the secret holds: the pepper
     * out of reach, the storage, a row that does not match its seal, a session
     * on a vault no longer live. Counted as a refusal, it would lock the holder
     * out over an outage.
     */
    case Unavailable;
}
