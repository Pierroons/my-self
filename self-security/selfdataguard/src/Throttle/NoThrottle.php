<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Throttle;

/**
 * No brake, chosen by name: the host brakes every door before it calls the
 * façade, or not at all. Each attempt then costs the server an Argon2id, and
 * nothing here counts it.
 */
final class NoThrottle implements Throttle
{
    public function admit(Door $door, string $userId): void
    {
    }

    public function settle(Door $door, string $userId, Outcome $outcome): void
    {
    }
}
