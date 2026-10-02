<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

/**
 * The three secrets that can open a vault — one envelope each.
 *
 * The values are the strings integrations already pass around ('password',
 * 'memorized'): `Lock::from()` turns a request field into a case and refuses
 * anything else.
 */
enum Lock: string
{
    case Password   = 'password';
    case Memorized  = 'memorized';
    case Passphrase = 'passphrase';
}
