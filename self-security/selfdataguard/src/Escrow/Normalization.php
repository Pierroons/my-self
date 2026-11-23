<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

/**
 * How a ceremony puts a declared value and an escrow field in the same form
 * before comparing them. The comparison and the log name it, so that a
 * Differs reads as "not equal under this form".
 *
 *   - Exact: byte for byte;
 *   - CaseAndSpaces: white space trimmed at both ends, each run of it inside
 *     made one space, letters lowercased (mbstring, UTF-8). Accents, hyphens,
 *     punctuation and the format of a date or a number stay as they are. A
 *     value that is not valid UTF-8 is compared byte for byte.
 */
enum Normalization: string
{
    case Exact         = 'exact';
    case CaseAndSpaces = 'case-and-spaces';

    public function apply(#[\SensitiveParameter] string $value): string
    {
        if ($this === self::Exact || !mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
    }
}
