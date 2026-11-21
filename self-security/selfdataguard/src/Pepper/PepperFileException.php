<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Pepper;

use RuntimeException;

/**
 * The pepper file is refused, for the reason $reason names: absent,
 * unreadable, not-regular, under-document-root, too-open, malformed or
 * uniform.
 */
final class PepperFileException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
