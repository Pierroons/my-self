<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Pepper;

/**
 * A pepper source on another machine. The façade checks it against the
 * database at its first use, not when it is built: building a façade asks
 * nothing of the network.
 */
interface RemotePepperSource extends PepperSource
{
}
