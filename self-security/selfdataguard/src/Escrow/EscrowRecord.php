<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use DateTimeImmutable;
use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;

/**
 * Persistent state of a user's escrow compartment (the consented, admin-
 * recoverable sub-vault — cases B' in the le service hote design).
 *
 * Layout:
 *   - user_id    : opaque application identifier (same as the main vault)
 *   - wrap_user  : escrow_key wrapped by the user's data_master_key
 *                  (AES-256-GCM, AAD = "userId|escrow") → the user opens it daily
 *   - wrap_admin : escrow_key sealed to the admin recovery PUBLIC key
 *                  (libsodium anonymous sealed box) → the admin opens it during
 *                  a recovery ceremony, and ONLY the escrow_key — never the
 *                  data_master_key. Strict compartmentalisation.
 *
 * Immutable value object.
 */
final class EscrowRecord
{
    public function __construct(
        public readonly string $userId,
        public readonly EncryptedBlob $wrapUser,
        public readonly string $wrapAdmin,           // raw sealed-box bytes
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt
    ) {
        if ($userId === '') {
            throw new InvalidArgumentException('userId must not be empty');
        }
        if ($wrapAdmin === '') {
            throw new InvalidArgumentException('wrapAdmin (sealed box) must not be empty');
        }
    }
}
