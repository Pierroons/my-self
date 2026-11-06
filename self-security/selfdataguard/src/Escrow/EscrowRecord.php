<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Storage\UnreadableEscrowException;
use TypeError;
use ValueError;

/**
 * Persistent state of a user's escrow compartment (the consented, admin-
 * recoverable sub-vault — cases B' in the integration design).
 *
 * Layout:
 *   - user_id    : opaque application identifier (same as the main vault)
 *   - wrap_user  : escrow_key wrapped by the user's data_master_key
 *                  (XChaCha20-Poly1305, AAD = EscrowVault::wrapAad()) → the user opens it daily
 *   - wrap_admin : escrow_key sealed to the admin recovery PUBLIC key
 *                  (libsodium anonymous sealed box) → the admin opens it during
 *                  a recovery ceremony, and ONLY the escrow_key — never the
 *                  data_master_key. Strict compartmentalisation.
 *   - revision   : bumped by every stored replacement; a write applies only
 *                  over the revision it was read at (0 for a record not yet
 *                  stored, whatever is passed — the storage writes 0)
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
        public readonly DateTimeImmutable $updatedAt,
        public readonly int $revision = 0
    ) {
        if ($userId === '') {
            throw new InvalidArgumentException('userId must not be empty');
        }
        if ($wrapAdmin === '') {
            throw new InvalidArgumentException('wrapAdmin (sealed box) must not be empty');
        }
    }

    /**
     * The record $build makes of the escrow row of $userId, which the storage
     * has already fetched: any Exception $build raises, and any TypeError or
     * ValueError — a value of the wrong shape for a typed parameter, or a key
     * missing from a row spread into a constructor — comes out as
     * UnreadableEscrowException, what StorageInterface::loadEscrow() raises for
     * a row that does not parse, its message naming the class of the cause; an
     * UnreadableEscrowException $build raises itself comes out as it is. Build
     * the record column by column from the row. Fetch the row before calling it: an outage raised inside
     * $build would read as a row rewritten.
     *
     * @param callable(): self $build
     * @throws UnreadableEscrowException
     */
    public static function parse(string $userId, callable $build): self
    {
        try {
            return $build();
        } catch (UnreadableEscrowException $e) {
            throw $e;
        } catch (Exception|TypeError|ValueError $e) {
            throw new UnreadableEscrowException(
                "The escrow row of '{$userId}' does not parse (" . $e::class . "): {$e->getMessage()}",
                0,
                $e
            );
        }
    }
}
