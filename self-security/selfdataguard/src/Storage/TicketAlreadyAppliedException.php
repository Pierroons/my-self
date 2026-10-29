<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Storage;

use RuntimeException;

/**
 * The ticket was already applied to this operation: the write it names is
 * done, and nothing was written again. A replay — a response lost, a retry —
 * reads it as "done", and reads the generation again before going on.
 */
final class TicketAlreadyAppliedException extends RuntimeException
{
}
