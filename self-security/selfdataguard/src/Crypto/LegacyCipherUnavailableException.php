<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Crypto;

use RuntimeException;

/**
 * A v1 blob (AES-256-GCM) that this machine has no way to decrypt.
 *
 * Distinct from a failed authentication: the key may be right. Callers that
 * turn a decryption failure into "wrong password" must let this one through,
 * or a machine limitation reads as a forgotten secret.
 */
final class LegacyCipherUnavailableException extends RuntimeException
{
}
