<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Storage;

use DateTimeImmutable;
use PDO;
use PDOException;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\Vault\StaleVaultException;
use Pierroons\SelfDataGuard\Vault\VaultNotFoundException;
use Pierroons\SelfDataGuard\Vault\VaultRecord;
use RuntimeException;
use Throwable;

/**
 * SQLite-backed storage adapter using PDO.
 *
 * Schema is auto-created on first use, and migrated in place when an older
 * version created it:
 *
 *   selfdataguard_vaults
 *     user_id     TEXT PRIMARY KEY
 *     user_salt   TEXT NOT NULL    (base64)
 *     wrap_pwd    TEXT NOT NULL    (base64 of EncryptedBlob)
 *     wrap_recov  TEXT             (base64, nullable)
 *     wrap_admin  TEXT             (base64, nullable, reserved, never written)
 *     created_at  TEXT NOT NULL    (ISO 8601)
 *     updated_at  TEXT NOT NULL    (ISO 8601)
 *     wrap_phrase TEXT             (base64, nullable — added in 0.5.0, hence last)
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
 *
 *   selfdataguard_escrow          — one row per user
 *     wrap_user   escrow_key wrapped by the master key (base64 EncryptedBlob)
 *     wrap_admin  escrow_key sealed to the admin public key (base64 sealed box)
 *
 *   selfdataguard_escrow_fields   — escrow fields encrypted with escrow_key
 */
final class SqliteAdapter implements StorageInterface
{
    private PDO $pdo;

    /** Savepoints this instance has open inside a caller's transaction. */
    private int $depth = 0;

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
             (user_id, user_salt, wrap_pwd, wrap_recov, wrap_admin, created_at, updated_at, wrap_phrase)
             VALUES (:uid, :salt, :wp, :wr, :wa, :ca, :ua, :wph)'
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

    /**
     * Writes only over the vault the record was read from. The WHERE carries
     * its user_salt, which a re-enrolment replaces: a record read before an
     * archive cannot overwrite the vault created after it. Without this, a
     * request still holding the old record would seal the old master key into
     * the new vault, and every field written there since would be lost.
     */
    public function updateVault(VaultRecord $record): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE selfdataguard_vaults
             SET wrap_pwd  = :wp,
                 wrap_recov = :wr,
                 wrap_admin = :wa,
                 updated_at = :ua,
                 wrap_phrase = :wph
             WHERE user_id = :uid AND user_salt = :salt'
        );
        $stmt->execute($this->vaultToParamsForUpdate($record));
        if ($stmt->rowCount() === 0) {
            if ($this->vaultExists($record->userId)) {
                throw new StaleVaultException(
                    "Vault for userId '{$record->userId}' was replaced since this record was read"
                );
            }
            throw new VaultNotFoundException("Vault not found for userId '{$record->userId}'");
        }
    }

    public function loadVault(string $userId): VaultRecord
    {
        $row = $this->fetchVaultRow($userId);
        if ($row === null) {
            throw new VaultNotFoundException("Vault not found for userId '{$userId}'");
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
        // Explicit deletes, not the FK cascade: `PRAGMA foreign_keys` is a no-op
        // on a connection the caller handed over with a transaction open.
        $this->atomic(function () use ($userId): void {
            $this->pdo->prepare('DELETE FROM selfdataguard_fields WHERE user_id = :uid')
                ->execute([':uid' => $userId]);
            $this->pdo->prepare('DELETE FROM selfdataguard_escrow_fields WHERE user_id = :uid')
                ->execute([':uid' => $userId]);
            $this->pdo->prepare('DELETE FROM selfdataguard_escrow WHERE user_id = :uid')
                ->execute([':uid' => $userId]);
            $this->pdo->prepare('DELETE FROM selfdataguard_vaults WHERE user_id = :uid')
                ->execute([':uid' => $userId]);
        });
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

        $this->atomic(function () use ($stmt, $userId, $fields, $now): void {
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
        });
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

    // -- Escrow compartment ---------------------------------------------------

    public function saveEscrow(EscrowRecord $record): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO selfdataguard_escrow (user_id, wrap_user, wrap_admin, created_at, updated_at)
             VALUES (:uid, :wu, :wa, :ca, :ua)
             ON CONFLICT(user_id) DO UPDATE SET
               wrap_user  = excluded.wrap_user,
               wrap_admin = excluded.wrap_admin,
               updated_at = excluded.updated_at'
        );
        $stmt->execute([
            ':uid' => $record->userId,
            ':wu'  => $record->wrapUser->toBase64(),
            ':wa'  => base64_encode($record->wrapAdmin),
            ':ca'  => $record->createdAt->format('c'),
            ':ua'  => $record->updatedAt->format('c'),
        ]);
    }

    public function loadEscrow(string $userId): ?EscrowRecord
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, wrap_user, wrap_admin, created_at, updated_at
             FROM selfdataguard_escrow WHERE user_id = :uid'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $wrapAdmin = base64_decode((string) $row['wrap_admin'], true);
        if ($wrapAdmin === false) {
            throw new RuntimeException('Corrupted wrap_admin in DB (invalid base64)');
        }
        return new EscrowRecord(
            userId:    (string) $row['user_id'],
            wrapUser:  EncryptedBlob::fromBase64((string) $row['wrap_user']),
            wrapAdmin: $wrapAdmin,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    public function saveEscrowFields(string $userId, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $now = (new DateTimeImmutable())->format('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO selfdataguard_escrow_fields (user_id, field_name, ciphertext, updated_at)
             VALUES (:uid, :fn, :ct, :ua)
             ON CONFLICT(user_id, field_name) DO UPDATE SET
               ciphertext = excluded.ciphertext,
               updated_at = excluded.updated_at'
        );
        $this->atomic(function () use ($stmt, $userId, $fields, $now): void {
            foreach ($fields as $fieldName => $ciphertext) {
                $stmt->execute([
                    ':uid' => $userId,
                    ':fn'  => $fieldName,
                    ':ct'  => $ciphertext,
                    ':ua'  => $now,
                ]);
            }
        });
    }

    public function loadEscrowFields(string $userId, array $fieldNames = []): array
    {
        if ($fieldNames === []) {
            $stmt = $this->pdo->prepare(
                'SELECT field_name, ciphertext FROM selfdataguard_escrow_fields WHERE user_id = :uid'
            );
            $stmt->execute([':uid' => $userId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($fieldNames), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT field_name, ciphertext FROM selfdataguard_escrow_fields
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

    // -------------------------------------------------------------------------

    /**
     * Runs $work atomically — inside the caller's transaction if one is open.
     *
     * PDO cannot nest: a second beginTransaction() throws, and committing
     * "whatever is open" would commit the caller's half-done work. Inside a
     * foreign transaction this uses a SAVEPOINT, so a failure rolls back our
     * writes only and the outcome stays the caller's decision. Any Throwable
     * rolls back, not only PDOException: a validation error raised midway
     * must not leave a transaction open on the connection. The point name
     * carries the instance, so two adapters sharing a connection never
     * release each other's points.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function atomic(callable $work): mixed
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            try {
                $result = $work();
                $this->pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }

        $point = 'selfdataguard_' . spl_object_id($this) . '_' . ++$this->depth;
        $this->pdo->exec('SAVEPOINT ' . $point);
        try {
            $result = $work();
            $this->pdo->exec('RELEASE SAVEPOINT ' . $point);
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $point);
                $this->pdo->exec('RELEASE SAVEPOINT ' . $point);
            }
            throw $e;
        } finally {
            $this->depth--;
        }
    }

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
                updated_at  TEXT NOT NULL,
                wrap_phrase TEXT
            )'
        );
        $this->addMissingVaultColumn('wrap_phrase');
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
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS selfdataguard_escrow (
                user_id     TEXT PRIMARY KEY,
                wrap_user   TEXT NOT NULL,
                wrap_admin  TEXT NOT NULL,
                created_at  TEXT NOT NULL,
                updated_at  TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES selfdataguard_vaults(user_id) ON DELETE CASCADE
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS selfdataguard_escrow_fields (
                user_id     TEXT NOT NULL,
                field_name  TEXT NOT NULL,
                ciphertext  TEXT NOT NULL,
                updated_at  TEXT NOT NULL,
                PRIMARY KEY (user_id, field_name),
                FOREIGN KEY (user_id) REFERENCES selfdataguard_vaults(user_id) ON DELETE CASCADE
            )'
        );
    }

    /**
     * A table created by an older version lacks the columns added since.
     * `CREATE TABLE IF NOT EXISTS` leaves it as it is, and every query naming
     * the new column would then fail with "no such column".
     *
     * The check is repeated under BEGIN IMMEDIATE: two processes opening the
     * same old database both see the column missing, and the second ALTER
     * would otherwise fail on "duplicate column name". The write lock makes the
     * second one wait, then find the column there. Inside a caller's
     * transaction the lock is already the caller's; a savepoint suffices.
     */
    private function addMissingVaultColumn(string $column): void
    {
        if ($this->vaultsHaveColumn($column)) {
            return;
        }
        $add = function () use ($column): void {
            if (!$this->vaultsHaveColumn($column)) {
                $this->pdo->exec("ALTER TABLE selfdataguard_vaults ADD COLUMN {$column} TEXT");
            }
        };
        if ($this->pdo->inTransaction()) {
            $this->atomic($add);
            return;
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $add();
            $this->pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    private function vaultsHaveColumn(string $column): bool
    {
        foreach ($this->pdo->query('PRAGMA table_info(selfdataguard_vaults)') as $info) {
            if ($info['name'] === $column) {
                return true;
            }
        }
        return false;
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
            ':wph'  => $record->wrapPhrase?->toBase64(),
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
            ':wph'  => $record->wrapPhrase?->toBase64(),
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
            wrapPhrase: $row['wrap_phrase'] !== null ? EncryptedBlob::fromBase64((string) $row['wrap_phrase']) : null,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchVaultRow(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, user_salt, wrap_pwd, wrap_recov, wrap_admin, created_at, updated_at, wrap_phrase
             FROM selfdataguard_vaults WHERE user_id = :uid'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
