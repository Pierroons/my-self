<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Storage;

use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\Vault\ArchivedVault;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\VaultRecord;

/**
 * Persistence contract for SelfDataGuard.
 *
 * Stored entities:
 *
 *   - vaults   : per-user envelope (salt + wraps), one live vault per user
 *   - fields   : per-field encrypted blob + optional blind index, many per user
 *   - escrow   : the consented, admin-recoverable compartment and its fields
 *   - archives : vaults set aside by a re-enrolment, still encrypted, any
 *                number per user
 *
 * Implementations are responsible for SQL safety (prepared statements),
 * transaction atomicity (batch field updates), and schema bootstrapping.
 */
interface StorageInterface
{
    /**
     * Insert a brand-new vault. Throws if the userId already exists.
     */
    public function saveVault(VaultRecord $record): void;

    /**
     * Update the wraps of an existing vault (e.g. after changePassword /
     * changeMemorized). user_salt is never rewritten: it identifies the
     * vault. The write applies only where user_salt AND revision still match
     * the record's, and bumps the revision. Throws StaleVaultException if the
     * vault was replaced or updated since the record was read,
     * VaultNotFoundException if the userId holds none.
     */
    public function updateVault(VaultRecord $record): void;

    /**
     * Load a vault. Throws VaultNotFoundException if not found.
     */
    public function loadVault(string $userId): VaultRecord;

    /**
     * Load a vault if present, null otherwise.
     */
    public function findVault(string $userId): ?VaultRecord;

    public function vaultExists(string $userId): bool;

    /**
     * Delete a vault and ALL its associated fields atomically.
     *
     * Archives of the userId are left alone. Removing them is a separate,
     * explicit decision (purgeArchives): otherwise whoever holds the current
     * vault — after a fraudulent re-enrolment, say — could erase the old
     * ones by deleting the account.
     */
    public function deleteVault(string $userId): void;

    /**
     * Insert or update a batch of encrypted fields for a user. Optional
     * blindIndex per field for lookup support.
     *
     * @param string                                       $userId
     * @param array<string, array{ciphertext: string, blindIndex?: ?string}> $fields
     *        Map field_name => ['ciphertext' => base64, 'blindIndex' => ?base64]
     * @param string|null $vaultSalt If given, the write happens only if the live
     *        vault still has this user_salt, checked inside the write's own
     *        transaction (StaleVaultException otherwise). The same parameter
     *        guards saveEscrow(), saveEscrowFields() and deleteArchive().
     */
    public function saveFields(string $userId, array $fields, ?string $vaultSalt = null): void;

    /**
     * Load encrypted fields for a user.
     *
     * @param string        $userId
     * @param array<string> $fieldNames Empty = load all fields for the user
     * @return array<string, string>    field_name => ciphertext (base64)
     */
    public function loadFields(string $userId, array $fieldNames = []): array;

    /**
     * Lookup a userId by its blind-index value on a given field.
     * Returns null if no match.
     */
    public function findUserIdByBlindIndex(string $fieldName, string $blindIndex): ?string;

    // -- Escrow compartment (recovery-escrow sub-vault) -----------------------

    /**
     * Insert or replace the escrow envelope (wrap_user + wrap_admin) for a user.
     */
    public function saveEscrow(EscrowRecord $record, ?string $vaultSalt = null): void;

    /**
     * Load the escrow envelope for a user, or null if none.
     */
    public function loadEscrow(string $userId): ?EscrowRecord;

    /**
     * Insert or update a batch of escrow field ciphertexts for a user.
     *
     * @param array<string, string> $fields field_name => ciphertext (base64)
     */
    public function saveEscrowFields(string $userId, array $fields, ?string $vaultSalt = null): void;

    /**
     * Load escrow field ciphertexts for a user.
     *
     * @param array<string> $fieldNames Empty = all escrow fields
     * @return array<string, string>    field_name => ciphertext (base64)
     */
    public function loadEscrowFields(string $userId, array $fieldNames = []): array;

    // -- Archives (vaults set aside by a re-enrolment) ------------------------

    /**
     * Atomically set the live vault of $new->userId aside as an archive —
     * envelopes, fields and escrow, still encrypted — then insert $new in its
     * place. Blind indexes are not archived. Nothing changes if any step
     * fails.
     *
     * @return string|null the archive id, or null if the userId had no live
     *                     vault (then $new is simply inserted)
     */
    public function replaceWithArchive(VaultRecord $new): ?string;

    /**
     * @return list<array{id: string, archivedAt: \DateTimeImmutable, locks: list<Lock>}> oldest first
     */
    public function listArchives(string $userId): array;

    /**
     * The archive, if it exists AND belongs to $userId; null otherwise.
     */
    public function loadArchive(string $userId, string $archiveId): ?ArchivedVault;

    /**
     * Delete one archive. False if it does not exist or belongs to another userId.
     */
    public function deleteArchive(string $userId, string $archiveId, ?string $vaultSalt = null): bool;

    /**
     * Delete every archive of $userId. Returns how many were deleted.
     */
    public function purgeArchives(string $userId): int;
}
