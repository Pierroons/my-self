<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Throttle;

use RuntimeException;

/**
 * The throttle refused the attempt, before anything was read or derived. The
 * host throws it from Throttle::admit(), a subclass of its own if it carries a
 * delay; the façade lets it through unchanged.
 */
class ThrottledException extends RuntimeException
{
}
