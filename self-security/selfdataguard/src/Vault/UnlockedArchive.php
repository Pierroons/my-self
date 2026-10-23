<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\Escrow\UnlockedEscrow;
use Pierroons\SelfDataGuard\Fields\FieldCrypter;

/**
 * An archive opened with one of its old locks — read-only.
 *
 * A distinct type from UnlockedVault, so that no write method of the façade
 * accepts it, and it hands out no session to write with: it only decrypts what
 * the archive holds.
 *
 * It also keeps the generation of the current vault it was opened from: the
 * archive is deleted under that generation, so a vault replaced since cannot
 * delete it.
 *
 * NEVER serialize, log, or persist this object.
 */
final class UnlockedArchive
{
    public function __construct(
        public readonly ArchivedVault $archive,
        private readonly UnlockedVault $session,
        public readonly VaultGeneration $openedFrom
    ) {
    }

    public function userId(): string
    {
        return $this->session->userId;
    }

    /**
     * The archived private fields, decrypted under the archive's own key.
     *
     * @param array<string, string> $ciphertexts field_name => ciphertext (base64)
     * @return array<string, string>
     */
    public function decryptFields(array $ciphertexts): array
    {
        return FieldCrypter::decryptBatch($this->session, $ciphertexts);
    }

    /** The archived escrow, opened with the archive's own key. */
    public function openEscrow(EscrowRecord $record): UnlockedEscrow
    {
        return $this->session->openEscrow($record);
    }

    public function lock(): void
    {
        $this->session->lock();
    }

    public function __serialize(): array
    {
        throw new \RuntimeException('UnlockedArchive must not be serialized');
    }
}
