<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use RuntimeException;

/**
 * The log no longer holds the anchor an append was given: cut before it
 * ($found = 'truncated'), or rewritten up to it ('rewritten'). Nothing was
 * appended.
 */
final class AuditAnchorException extends RuntimeException
{
    public function __construct(string $message, public readonly string $found)
    {
        parent::__construct($message);
    }
}
