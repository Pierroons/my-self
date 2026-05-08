<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Storage;

use DateTimeImmutable;
use PDO;
use PDOException;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Vault\VaultRecord;
use RuntimeException;

/**
 * SQLite-backed storage adapter using PDO.
 *
 * Schema is auto-created on first use. Two tables:
 *
 *   selfdataguard_vaults
 *     user_id     TEXT PRIMARY KEY
 *     user_salt   TEXT NOT NULL    (base64)
 *     wrap_pwd    TEXT NOT NULL    (base64 of EncryptedBlob)
 *     wrap_recov  TEXT             (base64, nullable)
 *     wrap_admin  TEXT             (base64, nullable, reserved for v0.2.0+)
 *     created_at  TEXT NOT NULL    (ISO 8601)
 *     updated_at  TEXT NOT NULL    (ISO 8601)
 *
 *   selfdataguard_fields
 *     user_id     TEXT NOT NULL
 *     field_name  TEXT NOT NULL
 *     ciphertext  TEXT NOT NULL    (base64 of EncryptedBlob)
 *     blind_index TEXT             (base64 HMAC, nullable)
 *     updated_at  TEXT NOT NULL    (ISO 8601)
 *     PRIMARY KEY (user_id, field_name)
 *     FOREIGN KEY (user_id) REFERENCES selfdataguard_vaults(user_id) ON DELETE CASCADE
 *
 *   INDEX selfdataguard_fields_blind ON selfdataguard_fields(field_name, blind_index)
 */
final class SqliteAdapter implements StorageInterface
{
    private PDO $pdo;

    public function __construct(string|PDO $dsnOrPdo)
    {
        if ($dsnOrPdo instanceof PDO) {
            $this->pdo = $dsnOrPdo;
        } else {
            $this->pdo = new PDO($dsnOrPdo);
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->ensureSchema();
    }

    public function saveVault(VaultRecord $record): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO selfdataguard_vaults
             (user_id, user_salt, wrap_pwd, wrap_recov, wrap_admin, created_at, updated_at)
             VALUES (:uid, :salt, :wp, :wr, :wa, :ca, :ua)'
        );
        try {
            $stmt->execute($this->vaultToParamsForInsert($record));
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'PRIMARY KEY')) {
                throw new RuntimeException("Vault already exists for userId '{$record->userId}'", 0, $e);
            }
            throw $e;
        }
    }

    public function updateVault(VaultRecord $record): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE selfdataguard_vaults
             SET user_salt = :salt,
                 wrap_pwd  = :wp,
                 wrap_recov = :wr,
                 wrap_admin = :wa,
                 updated_at = :ua
             WHERE user_id = :uid'
        );
        $stmt->execute($this->vaultToParamsForUpdate($record));
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException("Vault not found for userId '{$record->userId}'");
        }
    }

    public function loadVault(string $userId): VaultRecord
    {
        $row = $this->fetchVaultRow($userId);
        if ($row === null) {
            throw new RuntimeException("Vault not found for userId '{$userId}'");
        }
        return $this->rowToVault($row);
    }

    public function findVault(string $userId): ?VaultRecord
    {
        $row = $this->fetchVaultRow($userId);
        return $row === null ? null : $this->rowToVault($row);
    }

    public function vaultExists(string $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM selfdataguard_vaults WHERE user_id = :uid LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    public function deleteVault(string $userId): void
    {
        $this->pdo->beginTransaction();
        try {
            // FK cascade handles the fields, but we delete explicitly for clarity
            $this->pdo->prepare('DELETE FROM selfdataguard_fields WHERE user_id = :uid')
                ->execute([':uid' => $userId]);
            $this->pdo->prepare('DELETE FROM selfdataguard_vaults WHERE user_id = :uid')
                ->execute([':uid' => $userId]);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function saveFields(string $userId, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $now = (new DateTimeImmutable())->format('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO selfdataguard_fields (user_id, field_name, ciphertext, blind_index, updated_at)
             VALUES (:uid, :fn, :ct, :bi, :ua)
             ON CONFLICT(user_id, field_name) DO UPDATE SET
               ciphertext  = excluded.ciphertext,
               blind_index = excluded.blind_index,
               updated_at  = excluded.updated_at'
        );

        $this->pdo->beginTransaction();
        try {
            foreach ($fields as $fieldName => $data) {
                if (!isset($data['ciphertext'])) {
                    throw new RuntimeException("Missing ciphertext for field '{$fieldName}'");
                }
                $stmt->execute([
                    ':uid' => $userId,
                    ':fn'  => $fieldName,
                    ':ct'  => $data['ciphertext'],
                    ':bi'  => $data['blindIndex'] ?? null,
                    ':ua'  => $now,
                ]);
            }
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function loadFields(string $userId, array $fieldNames = []): array
    {
        if ($fieldNames === []) {
            $stmt = $this->pdo->prepare(
                'SELECT field_name, ciphertext FROM selfdataguard_fields WHERE user_id = :uid'
            );
            $stmt->execute([':uid' => $userId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($fieldNames), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT field_name, ciphertext FROM selfdataguard_fields
                 WHERE user_id = ? AND field_name IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$userId], array_values($fieldNames)));
        }
        $out = [];
        while ($row = $stmt->fetch()) {
            $out[(string) $row['field_name']] = (string) $row['ciphertext'];
        }
        return $out;
    }

    public function findUserIdByBlindIndex(string $fieldName, string $blindIndex): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM selfdataguard_fields
             WHERE field_name = :fn AND blind_index = :bi
             LIMIT 1'
        );
        $stmt->execute([':fn' => $fieldName, ':bi' => $blindIndex]);
        $userId = $stmt->fetchColumn();
        return $userId === false ? null : (string) $userId;
    }

    // -------------------------------------------------------------------------

    private function ensureSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS selfdataguard_vaults (
                user_id     TEXT PRIMARY KEY,
                user_salt   TEXT NOT NULL,
                wrap_pwd    TEXT NOT NULL,
                wrap_recov  TEXT,
                wrap_admin  TEXT,
                created_at  TEXT NOT NULL,
                updated_at  TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS selfdataguard_fields (
                user_id     TEXT NOT NULL,
                field_name  TEXT NOT NULL,
                ciphertext  TEXT NOT NULL,
                blind_index TEXT,
                updated_at  TEXT NOT NULL,
                PRIMARY KEY (user_id, field_name),
                FOREIGN KEY (user_id) REFERENCES selfdataguard_vaults(user_id) ON DELETE CASCADE
            )'
        );
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS selfdataguard_fields_blind
             ON selfdataguard_fields(field_name, blind_index)'
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function vaultToParamsForInsert(VaultRecord $record): array
    {
        return [
            ':uid'  => $record->userId,
            ':salt' => base64_encode($record->userSalt),
            ':wp'   => $record->wrapPwd->toBase64(),
            ':wr'   => $record->wrapRecov?->toBase64(),
            ':wa'   => $record->wrapAdmin?->toBase64(),
            ':ca'   => $record->createdAt->format('c'),
            ':ua'   => $record->updatedAt->format('c'),
        ];
    }

    /**
     * UPDATE doesn't touch created_at, so we omit :ca to avoid PDO's
     * "column index out of range" on emulated prepared statements.
     *
     * @return array<string, string|null>
     */
    private function vaultToParamsForUpdate(VaultRecord $record): array
    {
        return [
            ':uid'  => $record->userId,
            ':salt' => base64_encode($record->userSalt),
            ':wp'   => $record->wrapPwd->toBase64(),
            ':wr'   => $record->wrapRecov?->toBase64(),
            ':wa'   => $record->wrapAdmin?->toBase64(),
            ':ua'   => $record->updatedAt->format('c'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToVault(array $row): VaultRecord
    {
        $salt = base64_decode((string) $row['user_salt'], true);
        if ($salt === false) {
            throw new RuntimeException('Corrupted user_salt in DB (invalid base64)');
        }
        return new VaultRecord(
            userId:    (string) $row['user_id'],
            userSalt:  $salt,
            wrapPwd:   EncryptedBlob::fromBase64((string) $row['wrap_pwd']),
            wrapRecov: $row['wrap_recov'] !== null ? EncryptedBlob::fromBase64((string) $row['wrap_recov']) : null,
            wrapAdmin: $row['wrap_admin'] !== null ? EncryptedBlob::fromBase64((string) $row['wrap_admin']) : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchVaultRow(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, user_salt, wrap_pwd, wrap_recov, wrap_admin, created_at, updated_at
             FROM selfdataguard_vaults WHERE user_id = :uid'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
