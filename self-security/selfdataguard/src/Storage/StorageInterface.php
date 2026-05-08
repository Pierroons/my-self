<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Storage;

use Pierroons\SelfDataGuard\Vault\VaultRecord;

/**
 * Persistence contract for SelfDataGuard.
 *
 * Two logical entities are stored:
 *
 *   - vaults : per-user envelope (salt + wraps), one row per user
 *   - fields : per-field encrypted blob + optional blind index, many per user
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
     * changeMemorized). Throws if the userId does not exist.
     */
    public function updateVault(VaultRecord $record): void;

    /**
     * Load a vault. Throws RuntimeException if not found.
     */
    public function loadVault(string $userId): VaultRecord;

    /**
     * Load a vault if present, null otherwise.
     */
    public function findVault(string $userId): ?VaultRecord;

    public function vaultExists(string $userId): bool;

    /**
     * Delete a vault and ALL its associated fields atomically.
     */
    public function deleteVault(string $userId): void;

    /**
     * Insert or update a batch of encrypted fields for a user. Optional
     * blindIndex per field for lookup support.
     *
     * @param string                                       $userId
     * @param array<string, array{ciphertext: string, blindIndex?: ?string}> $fields
     *        Map field_name => ['ciphertext' => base64, 'blindIndex' => ?base64]
     */
    public function saveFields(string $userId, array $fields): void;

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
}
