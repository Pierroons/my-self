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
 * only, and the archive keeps it. They open under the Argon2id profile in
 * force when the archive was made, carried here, because a live vault can
 * be re-sealed after a profile change and an archive cannot — nobody holds
 * its key in between.
 *
 * ⚠️ That is the profile of the code at archiving time, not one read from the
 * vault, which stores none. A vault still sealed under an earlier profile at
 * that moment would not open with it: whoever changes the profile re-seals
 * every live vault first.
 */
final class ArchivedVault
{
    /**
     * @param array<string, array{ciphertext: string, wasIndexed: bool}> $privateFields
     * @param array<string, string>                                      $escrowFields
     */
    public function __construct(
        public readonly string $archiveId,
        public readonly DateTimeImmutable $archivedAt,
        public readonly VaultRecord $record,
        public readonly int $kdfOpslimit,
        public readonly int $kdfMemlimit,
        public readonly ?EscrowRecord $escrow,
        public readonly array $privateFields,
        public readonly array $escrowFields
    ) {
    }
}
