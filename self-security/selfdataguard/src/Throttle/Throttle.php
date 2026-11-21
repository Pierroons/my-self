<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Throttle;

/**
 * The brake the host puts before every attempt to open a vault with a secret
 * that comes from outside. The façade requires one: a host that brakes
 * elsewhere, or not at all, says so by giving NoThrottle.
 *
 * For each attempt the façade calls admit() before it reads anything — an
 * account that does not exist is admitted or refused as one that does —, then
 * settle() once with what the attempt came to.
 *
 * A brake keyed on the userId alone lets whoever knows the userId lock its
 * holder out. Key it on what the host tells apart and the façade does not
 * see — the address, the session. Since admit() comes before the read, it
 * also receives userIds that exist nowhere: a table keyed on them grows
 * without bound.
 *
 * The brake guards the doors of the façade. UserVault::unlock() is public and
 * keeps no state: a caller that derives through it directly passes no brake,
 * as it passes no seal.
 */
interface Throttle
{
    /**
     * Before anything is read for $userId at $door.
     *
     * @throws ThrottledException to refuse the attempt: nothing is read, nothing is derived
     */
    public function admit(Door $door, string $userId): void;

    /** What an attempt admit() let through came to — called once for each. */
    public function settle(Door $door, string $userId, Outcome $outcome): void;
}
