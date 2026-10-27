<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Fields;

use RuntimeException;

/**
 * A lookup that must name one account matched several. It is never resolved
 * by picking one: the first row or the latest is whoever wrote last.
 *
 * A storage that keeps blind indexes unique never raises it. A database written
 * before 0.7.0, or a storage that does not enforce the uniqueness, can.
 */
final class AmbiguousLookupException extends RuntimeException
{
}
