<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Storage;

use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\Vault\ArchivedVault;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\VaultGeneration;
use Pierroons\SelfDataGuard\Vault\VaultRecord;

/**
 * Persistence contract for SelfDataGuard.
 *
 * Stored entities:
 *
 *   - vaults   : per-user envelope (salt + wraps + Argon2id profile), one live
 *                vault per user
 *   - fields   : per-field encrypted blob + optional blind index, many per user
 *   - escrow   : the consented, admin-recoverable compartment and its fields
 *   - archives : vaults set aside by a re-enrolment, still encrypted, any
 *                number per user
 *
 * 🔑 Every write to a live vault names the generation it is meant for
 * (VaultGeneration: userId + user_salt). The implementation compares it inside
 * the write's own transaction, or in the same statement, and throws
 * VaultNotFoundException when the userId has no live vault, StaleVaultException
 * when it has another one. A generation received and not compared breaks
 * nothing visible: the write lands in a vault whose key cannot read it.
 *
 * userIds compare byte for byte: a case-insensitive collation on the user_id
 * column hands one account's vault to another spelling of its name.
 *
 * Implementations are responsible for SQL safety (prepared statements),
 * transaction atomicity (batch field updates), and schema bootstrapping.
 *
 * An implementation is conformant when Conformance passes against it —
 * `php bin/storage-conformance.php <connection.php>` — and not otherwise.
 */
interface StorageInterface
{
    /**
     * Insert a brand-new vault, Argon2id profile included (kdfOpslimit,
     * kdfMemlimit): its envelopes open under that profile only — and its
     * profileSeal, which vouches for it. Never an upsert: throws
     * VaultExistsException if the userId has a live vault.
     */
    public function saveVault(VaultRecord $record): void;

    /**
     * Update an existing vault to the record: its wraps (e.g. after
     * setLock / removeLock), its profile and its profileSeal.
     * user_salt is never rewritten: it identifies the vault. The write applies only where user_salt AND revision still match
     * the record's, and bumps the revision. Throws StaleVaultException if the
     * vault was replaced or updated since the record was read,
     * VaultNotFoundException if the userId holds none.
     *
     * @return int the revision now stored, the record's plus one
     */
    public function updateVault(VaultRecord $record): int;

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
     * Delete the live vault of this generation and ALL its fields and escrow,
     * atomically.
     *
     * Archives of the userId are left alone. Removing them is a separate,
     * explicit decision (eraseAccount): otherwise whoever holds the current
     * vault — after a fraudulent re-enrolment, say — could erase the old
     * ones by deleting the account.
     */
    public function deleteVault(VaultGeneration $generation): void;

    /**
     * Insert or update a batch of encrypted fields of the vault $generation
     * names. A field written again replaces its row, index included. Each
     * field carries at most one index, for lookups:
     *
     *   - blindIndex  : held by one account at most, per field_name. Another
     *                   account holding the same value → DuplicateIndexException
     *                   naming every such field of the batch, and nothing of the
     *                   batch is written. Enforced by the storage itself (a
     *                   unique constraint): a check before the write lets two
     *                   concurrent writers both through.
     *   - sharedIndex : any number of accounts may hold the same value.
     *
     * @param array<string, array{ciphertext: string, blindIndex?: ?string, sharedIndex?: ?string}> $fields
     *        Map field_name => ['ciphertext' => base64, 'blindIndex' => ?base64, 'sharedIndex' => ?base64]
     */
    public function saveFields(VaultGeneration $generation, array $fields): void;

    /**
     * Load encrypted fields for a user.
     *
     * @param string        $userId
     * @param array<string> $fieldNames Empty = load all fields for the user
     * @return array<string, string>    field_name => ciphertext (base64)
     */
    public function loadFields(string $userId, array $fieldNames = []): array;

    /**
     * Every userId whose field $fieldName carries $index, as a blindIndex or a
     * sharedIndex. The index is base64: compared byte for byte, case included.
     *
     * @return list<string>
     */
    public function findUserIdsByIndex(string $fieldName, string $index): array;

    // -- Escrow compartment (recovery-escrow sub-vault) -----------------------

    /**
     * Insert the escrow envelope (wrap_user + wrap_admin) of the vault
     * $generation names. Never an upsert: throws EscrowExistsException if the
     * userId has one, enforced by the storage itself — two first deposits that
     * both find none would each bring their own escrow key, and the fields
     * sealed under the replaced one would never open again.
     * $record->userId must be $generation's.
     */
    public function createEscrow(VaultGeneration $generation, EscrowRecord $record): void;

    /**
     * Replace the escrow envelope the userId holds with $record, which wraps
     * the same escrow key again (wrap_admin re-sealed). Throws
     * EscrowNotFoundException if the userId has none.
     * $record->userId must be $generation's.
     */
    public function replaceEscrow(VaultGeneration $generation, EscrowRecord $record): void;

    /**
     * Load the escrow envelope for a user, or null if none.
     */
    public function loadEscrow(string $userId): ?EscrowRecord;

    /**
     * Insert or update a batch of escrow field ciphertexts of the vault
     * $generation names.
     *
     * @param array<string, string> $fields field_name => ciphertext (base64)
     */
    public function saveEscrowFields(VaultGeneration $generation, array $fields): void;

    /**
     * Load escrow field ciphertexts for a user.
     *
     * @param array<string> $fieldNames Empty = all escrow fields
     * @return array<string, string>    field_name => ciphertext (base64)
     */
    public function loadEscrowFields(string $userId, array $fieldNames = []): array;

    // -- Archives (vaults set aside by a re-enrolment) ------------------------

    /**
     * Atomically set the live vault of $replacing aside as an archive —
     * envelopes, fields and escrow, still encrypted — then insert $new in its
     * place. Blind indexes are not archived. Nothing changes if any step
     * fails.
     *
     * The vault archived is exactly the one $replacing names: a second call
     * with the same generation finds the vault the first one inserted and
     * throws StaleVaultException, instead of archiving it in turn.
     * $new->userId must be $replacing's.
     *
     * @return string the archive id
     */
    public function replaceWithArchive(VaultGeneration $replacing, VaultRecord $new): string;

    /**
     * @return list<array{id: string, archivedAt: \DateTimeImmutable, locks: list<Lock>}> oldest first
     */
    public function listArchives(string $userId): array;

    /**
     * The archive, if it exists AND belongs to $userId; null otherwise.
     */
    public function loadArchive(string $userId, string $archiveId): ?ArchivedVault;

    /**
     * Delete one archive, while the live vault is still the one $generation
     * names. False if it does not exist or belongs to another userId.
     */
    public function deleteArchive(VaultGeneration $generation, string $archiveId): bool;

    // -- Settings of the database ---------------------------------------------

    /**
     * The value the database holds under $name, after writing $value there if
     * it held none. The first value written stays: two callers that claim
     * different values at once both get back the one stored first, which is
     * enforced by the storage itself (a unique name). A database that already
     * holds the value is read, not written to — a read-only copy answers. A
     * setting is never replaced through this contract.
     */
    public function claimSetting(string $name, string $value): string;

    /** The value the database holds under $name, or null. Never writes. */
    public function loadSetting(string $name): ?string;

    /** Whether the database holds any vault, live or archived. */
    public function hasVaults(): bool;

    /**
     * The fields one of whose values carries an index, by name: true when one
     * of them carries a shared index. Never writes.
     *
     * @return array<string, bool>
     */
    public function indexedFields(): array;

    /**
     * Erase the account: the live vault, its fields and escrow, and every
     * archive of the userId, in one transaction. Works on a userId that has
     * archives and no live vault.
     *
     * @return int how many vaults were erased, live and archived
     */
    public function eraseAccount(string $userId): int;
}
