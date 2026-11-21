<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use DateTimeImmutable;
use Pierroons\SelfDataGuard\Escrow\EscrowRecord;

/**
 * A vault set aside by a re-enrolment, read back from storage — still
 * encrypted, exactly as it was when archived.
 *
 * Its envelopes still open with its old secrets: the AADs bind the userId
 * only, and the archive keeps it. They open under the Argon2id profile of the
 * vault it was taken from, carried here, because a live vault can be re-sealed
 * after a profile change and an archive cannot — nobody holds its key in
 * between. No derivation runs under a profile outside the floor and the
 * ceiling.
 *
 * The record keeps the vault's profile seal, when it had one: an archive taken
 * before 0.7.0 has none, and nothing vouches for its profile.
 *
 * An escrow the archive keeps and that does not parse — a column that does
 * not read, a column missing, a row that is not one — leaves $escrow null and
 * $escrowUnreadable true; escrow fields that are not a map of ciphertexts set
 * $escrowUnreadable too. The archive still opens and reads, the holder's view
 * reads that escrow as a row rewritten, the ceremony refuses it, and the row
 * comes back live as one that does not parse. $blindIndexes and $sharedIndexes
 * hold the index each private field carried when it was archived; an archive
 * written before 0.8.0 kept none, and a field whose index another account held
 * when it came back live keeps none either.
 */
final class ArchivedVault
{
    /**
     * @param array<string, array{ciphertext: string, wasIndexed: bool}> $privateFields
     * @param array<string, string>                                      $escrowFields
     * @param array<string, string>                                      $blindIndexes  field_name => blind index (base64)
     * @param array<string, string>                                      $sharedIndexes field_name => shared index (base64)
     */
    public function __construct(
        public readonly string $archiveId,
        public readonly DateTimeImmutable $archivedAt,
        public readonly VaultRecord $record,
        public readonly int $kdfOpslimit,
        public readonly int $kdfMemlimit,
        public readonly ?EscrowRecord $escrow,
        public readonly array $privateFields,
        public readonly array $escrowFields,
        public readonly bool $escrowUnreadable = false,
        public readonly array $blindIndexes = [],
        public readonly array $sharedIndexes = []
    ) {
    }
}
