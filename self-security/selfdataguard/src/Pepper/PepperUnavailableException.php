<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Pepper;

use RuntimeException;

/**
 * The pepper source cannot answer now — a machine that does not respond, a
 * link that fails. Nothing opens without it: no lock the pepper covers falls
 * back on a form without it.
 */
final class PepperUnavailableException extends RuntimeException
{
}
