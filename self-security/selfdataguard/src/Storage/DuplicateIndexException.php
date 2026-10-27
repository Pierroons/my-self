<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Storage;

use RuntimeException;

/**
 * Another account already holds this blind-index value for this field. The
 * whole batch is refused: nothing of it was written.
 *
 * It tells the caller the value is taken — what an "address already in use"
 * message tells. Index a value once the account has proven it holds it:
 * otherwise whoever indexes the victim's address first keeps it, and the
 * victim's own write is the one refused.
 */
final class DuplicateIndexException extends RuntimeException
{
    /**
     * @param list<string> $fields the fields whose value another account holds
     */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('Another account already holds the indexed value of: ' . implode(', ', $fields));
    }
}
