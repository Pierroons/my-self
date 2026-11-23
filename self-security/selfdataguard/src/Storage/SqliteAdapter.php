<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Storage;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Escrow\EscrowExistsException;
use Pierroons\SelfDataGuard\Escrow\EscrowNotFoundException;
use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\Escrow\StaleEscrowException;
use Pierroons\SelfDataGuard\RecoveryTickets;
use Pierroons\SelfDataGuard\Vault\ArchivedVault;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\StaleVaultException;
use Pierroons\SelfDataGuard\Vault\VaultExistsException;
use Pierroons\SelfDataGuard\Vault\VaultFrozenException;
use Pierroons\SelfDataGuard\Vault\VaultGeneration;
use Pierroons\SelfDataGuard\Vault\VaultNotFoundException;
use Pierroons\SelfDataGuard\Vault\VaultRecord;
use Pierroons\SelfDataGuard\Vault\VaultTamperedException;
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
 *     wrap_admin  TEXT             (base64, nullable, reserved, always NULL)
 *     created_at  TEXT NOT NULL    (ISO 8601)
 *     updated_at  TEXT NOT NULL    (ISO 8601)
 *     wrap_phrase TEXT             (base64, nullable — added in 0.5.0, hence last)
 *     revision    INTEGER NOT NULL DEFAULT 0  (bumped by every update; added in 0.5.0)
 *     kdf_opslimit / kdf_memlimit  INTEGER NOT NULL  (the vault's Argon2id profile;
 *                 added in 0.6.0, defaulting to the legacy profile of older rows)
 *     profile_seal TEXT            (base64 ProfileSeal, nullable — added in 0.7.0)
 *     frozen_by / frozen_at  TEXT  (who froze the vault, and when in ISO 8601 UTC;
 *                 both NULL while it is not frozen — added in 0.8.0)
 *
 *   selfdataguard_fields
 *     user_id     TEXT NOT NULL
 *     field_name  TEXT NOT NULL
 *     ciphertext  TEXT NOT NULL    (base64 of EncryptedBlob)
 *     blind_index TEXT             (base64 HMAC, nullable — unique per field_name;
 *                 'lost:' and a random suffix on a field an archive brought
 *                 back without the index another account held: see lostIndexes())
 *     updated_at  TEXT NOT NULL    (ISO 8601)
 *     shared_index TEXT            (base64 HMAC, nullable — added in 0.7.0, hence last)
 *     PRIMARY KEY (user_id, field_name)
 *     FOREIGN KEY (user_id) REFERENCES selfdataguard_vaults(user_id) ON DELETE CASCADE
 *
 *   UNIQUE INDEX selfdataguard_fields_blind_unique ON selfdataguard_fields(field_name, blind_index)
 *   INDEX selfdataguard_fields_shared ON selfdataguard_fields(field_name, shared_index)
 *
 *   selfdataguard_escrow          — one row per user
 *     wrap_user   escrow_key wrapped by the master key (base64 EncryptedBlob)
 *     wrap_admin  escrow_key sealed to the admin public key (base64 sealed box)
 *     revision    bumped by every replacement (added in 0.9.0)
 *     admin_key   fingerprint of the admin key wrap_admin is sealed to (added
 *                 in 0.10.0; NULL for an escrow written before)
 *
 *   selfdataguard_escrow_fields   — escrow fields encrypted with escrow_key
 *
 *   selfdataguard_tickets         — recovery tickets applied (added in 0.8.0)
 *     ticket, operation  PRIMARY KEY: a ticket applies once per operation
 *     user_id, archive_id (the archive a re-enrolment set aside), applied_at
 *     — no erasure deletes a row; once its userId holds no live vault and no
 *     archive, user_id is '' and archive_id NULL
 *
 *   selfdataguard_archives        — vaults set aside by a re-enrolment
 *     archive_id  TEXT PRIMARY KEY (random — a counter would tell how many
 *                 re-enrolments the service has seen)
 *     user_id     TEXT NOT NULL    (no foreign key: the archive outlives the
 *                 live vault it was taken from)
 *     archived_at TEXT NOT NULL    (ISO 8601, UTC, so that the order survives a DST change;
 *                 indexed since 0.9.1: it decides which archives reserve their indexes)
 *     locks       TEXT NOT NULL    (the locks that still open it, comma-separated)
 *     package     TEXT NOT NULL    (JSON: the vault row, escrow, fields — all
 *                 still encrypted, their indexes since 0.8.0 —, the Argon2id
 *                 profile, a format version)
 */
final class SqliteAdapter implements StorageInterface, ErasesArchives, TracesLostIndexes
{
    /** Version of the archive package; a reader refuses a newer one. */
    private const ARCHIVE_FORMAT = 1;

    /** Prefix of the blind_index a field carries once it came back without its own; no base64 index starts with it. */
    private const LOST_INDEX = 'lost:';

    /** What a column an archived escrow lacks is put back as: no base64, no date, no integer reads it. */
    private const UNREADABLE = '%unreadable%';

    /** The journal modes that keep a rollback journal on disk: without one, a failed transaction is not undone. */
    private const JOURNAL_MODES = ['delete', 'truncate', 'persist', 'wal'];

    private PDO $pdo;

    /** Savepoints this instance has open inside a transaction. */
    private int $depth = 0;

    /** True while a transaction this instance opened itself is running. */
    private bool $ownTransaction = false;

    /**
     * Opening a database created by an older version migrates it in place.
     * Constructed inside a transaction that the caller then rolls back, the
     * migration rolls back with it: build a new adapter after such a rollback.
     *
     * $timeout is how many seconds a write waits for another connection's
     * lock before it fails; PDO waits 60, longer than most web servers let a
     * request run. $journalMode is SQLite's journal: 'delete' (its default),
     * 'truncate', 'persist' or 'wal'. Both apply to the connection, opened
     * here or given; null leaves it as it is. A journal mode SQLite does not
     * take — 'wal' on an in-memory database — throws.
     */
    public function __construct(string|PDO $dsnOrPdo, ?int $timeout = null, ?string $journalMode = null)
    {
        if ($dsnOrPdo instanceof PDO) {
            $this->pdo = $dsnOrPdo;
        } else {
            $this->pdo = new PDO($dsnOrPdo);
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($timeout !== null) {
            if ($timeout < 0) {
                throw new InvalidArgumentException("The lock timeout must be 0 seconds or more, not {$timeout}");
            }
            $this->pdo->setAttribute(PDO::ATTR_TIMEOUT, $timeout);
        }
        if ($journalMode !== null) {
            $mode = strtolower($journalMode);
            if (!in_array($mode, self::JOURNAL_MODES, true)) {
                throw new InvalidArgumentException(
                    "Unknown or unsafe journal mode '{$journalMode}': one of " . implode(', ', self::JOURNAL_MODES)
                );
            }
            $kept = strtolower((string) $this->pdo->query("PRAGMA journal_mode = {$mode}")->fetchColumn());
            if ($kept !== $mode) {
                throw new RuntimeException("SQLite kept the journal mode '{$kept}', not '{$mode}'");
            }
        }
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->ensureSchema();
    }

    public function saveVault(VaultRecord $record): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO selfdataguard_vaults
             (user_id, user_salt, wrap_pwd, wrap_recov, wrap_admin, created_at, updated_at, wrap_phrase, revision,
              kdf_opslimit, kdf_memlimit, profile_seal)
             VALUES (:uid, :salt, :wp, :wr, :wa, :ca, :ua, :wph, :rev, :ops, :mem, :ps)'
        );
        try {
            $stmt->execute($this->vaultToParamsForInsert($record));
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'PRIMARY KEY')) {
                throw new VaultExistsException("Vault already exists for userId '{$record->userId}'", 0, $e);
            }
            throw $e;
        }
    }

    /**
     * Writes only over the exact state the record was read from.
     *
     * - user_salt: a re-enrolment replaces it. Without it in the WHERE, a
     *   request still holding the old record would seal the old master key
     *   into the new vault, and every field written there since would be lost.
     * - revision: every update bumps it. Two requests that read the same
     *   vault and rewrite it would otherwise lose one update silently — a
     *   password change landing after a passphrase removal puts the revoked
     *   passphrase back.
     */
    public function updateVault(VaultRecord $record): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE selfdataguard_vaults
             SET wrap_pwd  = :wp,
                 wrap_recov = :wr,
                 wrap_admin = :wa,
                 updated_at = :ua,
                 wrap_phrase = :wph,
                 kdf_opslimit = :ops,
                 kdf_memlimit = :mem,
                 profile_seal = :ps,
                 revision = revision + 1
             WHERE user_id = :uid AND user_salt = :salt AND revision = :rev AND frozen_by IS NULL'
        );
        $stmt->execute($this->vaultToParamsForUpdate($record));
        if ($stmt->rowCount() === 0) {
            if ($this->frozenState($record->userId) !== null) {
                throw new VaultFrozenException("The vault of '{$record->userId}' is frozen: it reads, and refuses every write");
            }
            if ($this->vaultExists($record->userId)) {
                throw new StaleVaultException(
                    "Vault for userId '{$record->userId}' changed or was replaced since this record was read — reload it"
                );
            }
            throw new VaultNotFoundException("Vault not found for userId '{$record->userId}'");
        }
        return $record->revision + 1;
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

    public function deleteVault(VaultGeneration $generation): void
    {
        $this->atomic(function () use ($generation): void {
            $this->assertGeneration($generation);
            $this->purgeLive($generation->userId);
            $this->untieTickets($generation->userId);
        });
    }

    public function saveFields(VaultGeneration $generation, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $now = (new DateTimeImmutable())->format('c');
        // A field written without an index keeps the trace of an index it lost;
        // written with one, the trace goes.
        $stmt = $this->pdo->prepare(
            "INSERT INTO selfdataguard_fields (user_id, field_name, ciphertext, blind_index, updated_at, shared_index)
             VALUES (:uid, :fn, :ct, :bi, :ua, :si)
             ON CONFLICT(user_id, field_name) DO UPDATE SET
               ciphertext   = excluded.ciphertext,
               blind_index  = CASE
                                WHEN excluded.blind_index IS NULL AND excluded.shared_index IS NULL
                                     AND substr(selfdataguard_fields.blind_index, 1, " . strlen(self::LOST_INDEX) . ") = '" . self::LOST_INDEX . "'
                                THEN selfdataguard_fields.blind_index
                                ELSE excluded.blind_index
                              END,
               updated_at   = excluded.updated_at,
               shared_index = excluded.shared_index"
        );

        $this->atomic(function () use ($stmt, $generation, $fields, $now): void {
            $this->assertGeneration($generation);
            $taken = [];
            foreach ($fields as $fieldName => $data) {
                if (!isset($data['ciphertext'])) {
                    throw new RuntimeException("Missing ciphertext for field '{$fieldName}'");
                }
                if (isset($data['blindIndex'], $data['sharedIndex'])) {
                    throw new InvalidArgumentException("Field '{$fieldName}' carries both a blind and a shared index");
                }
                if (isset($data['blindIndex']) && $this->reservedByAnother($generation->userId, (string) $fieldName, (string) $data['blindIndex'])) {
                    $taken[] = (string) $fieldName;
                    continue;
                }
                try {
                    $stmt->execute([
                        ':uid' => $generation->userId,
                        ':fn'  => $fieldName,
                        ':ct'  => $data['ciphertext'],
                        ':bi'  => $data['blindIndex'] ?? null,
                        ':ua'  => $now,
                        ':si'  => $data['sharedIndex'] ?? null,
                    ]);
                } catch (PDOException $e) {
                    // A constraint failure undoes this statement only: the
                    // rest of the batch still runs, so that every taken field
                    // is named. Throwing below rolls the batch back.
                    if (!str_contains($e->getMessage(), 'selfdataguard_fields.blind_index')) {
                        throw $e;
                    }
                    $stmt->closeCursor();
                    $taken[] = (string) $fieldName;
                }
            }
            if ($taken !== []) {
                throw new DuplicateIndexException($taken);
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

    public function findUserIdsByIndex(string $fieldName, string $index): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM selfdataguard_fields
             WHERE field_name = :fn AND (blind_index = :bi OR shared_index = :si)
             ORDER BY user_id'
        );
        $stmt->execute([':fn' => $fieldName, ':bi' => $index, ':si' => $index]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Whether an archive of another userId than $userId, one that can still
     * come back (reservingArchives()), keeps $index as the blind index of its
     * field $fieldName — a value $userId does not already hold live under that
     * index. Callers run it inside atomic(), so that no archive is made
     * between the check and the write.
     *
     * The archives are picked by date and freeze first, then their packages
     * are searched as text, then the ones that hold $index are read; one that
     * does not read as JSON holds it.
     */
    private function reservedByAnother(string $userId, string $fieldName, string $index): bool
    {
        $held = $this->pdo->prepare('SELECT 1 FROM selfdataguard_fields WHERE user_id = :uid AND field_name = :fn AND blind_index = :bi');
        $held->execute([':uid' => $userId, ':fn' => $fieldName, ':bi' => $index]);
        $holdsLive = $held->fetchColumn() !== false;
        $held->closeCursor();
        if ($holdsLive) {
            return false;
        }
        [$sql, $params] = $this->reservingArchives('a.user_id <> :other AND (instr(a.package, :bi) > 0 OR instr(a.package, :escaped) > 0)');
        $stmt = $this->pdo->prepare("SELECT package FROM ({$sql})");
        $stmt->execute($params + [':other' => $userId, ':bi' => $index, ':escaped' => str_replace('/', '\\/', $index)]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $package) {
            $read = json_decode((string) $package, true);
            if (!is_array($read) || ($read['fields']['private'][$fieldName]['blind_index'] ?? null) === $index) {
                return true;
            }
        }
        return false;
    }

    /**
     * The archives that reserve their blind indexes: made less than
     * RecoveryTickets::RETURN_WINDOW ago — the holder may still come back —,
     * or of a userId whose live vault is frozen. A query selecting user_id,
     * archive_id, archived_at, package and frozen, for the archives that
     * also match $where (over the alias `a`), and its parameters. The date
     * and the frozen accounts come first — through the index on archived_at,
     * and the one on user_id —, so that no package of an old archive is read.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function reservingArchives(string $where): array
    {
        $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . RecoveryTickets::RETURN_WINDOW . ' seconds')->format('c');
        $columns = 'a.user_id, a.archive_id, a.archived_at, a.package';
        return [
            "SELECT {$columns}, 0 AS frozen FROM selfdataguard_archives a
             WHERE a.archived_at > :since AND NOT EXISTS (
                 SELECT 1 FROM selfdataguard_vaults v WHERE v.user_id = a.user_id AND v.frozen_by IS NOT NULL)
               AND {$where}
             UNION ALL
             SELECT {$columns}, 1 AS frozen FROM selfdataguard_archives a
             WHERE a.user_id IN (SELECT user_id FROM selfdataguard_vaults WHERE frozen_by IS NOT NULL) AND {$where}",
            [':since' => $since],
        ];
    }

    // -- Escrow compartment ---------------------------------------------------

    public function createEscrow(VaultGeneration $generation, EscrowRecord $record): void
    {
        if ($record->userId !== $generation->userId) {
            throw new InvalidArgumentException('The escrow record belongs to another userId than the generation');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO selfdataguard_escrow (user_id, wrap_user, wrap_admin, created_at, updated_at, admin_key)
             VALUES (:uid, :wu, :wa, :ca, :ua, :ak)'
        );
        $this->atomic(function () use ($stmt, $record, $generation): void {
            $this->assertGeneration($generation);
            try {
                $stmt->execute([
                    ':uid' => $record->userId,
                    ':wu'  => $record->wrapUser->toBase64(),
                    ':wa'  => base64_encode($record->wrapAdmin),
                    ':ca'  => $record->createdAt->format('c'),
                    ':ua'  => $record->updatedAt->format('c'),
                    ':ak'  => $record->adminKey,
                ]);
            } catch (PDOException $e) {
                $stmt->closeCursor();
                if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'PRIMARY KEY')) {
                    throw new EscrowExistsException("An escrow already exists for userId '{$record->userId}'", 0, $e);
                }
                throw $e;
            }
        });
    }

    public function replaceEscrow(VaultGeneration $generation, EscrowRecord $record): void
    {
        if ($record->userId !== $generation->userId) {
            throw new InvalidArgumentException('The escrow record belongs to another userId than the generation');
        }
        $this->atomic(function () use ($record, $generation): void {
            $this->assertGeneration($generation);
            $this->writeEscrowOver($record);
        });
    }

    public function rotateEscrow(VaultGeneration $generation, EscrowRecord $record, array $fields): void
    {
        if ($record->userId !== $generation->userId) {
            throw new InvalidArgumentException('The escrow record belongs to another userId than the generation');
        }
        $now = (new DateTimeImmutable())->format('c');
        $this->atomic(function () use ($record, $generation, $fields, $now): void {
            $this->assertGeneration($generation);
            $this->writeEscrowOver($record);
            $this->pdo->prepare('DELETE FROM selfdataguard_escrow_fields WHERE user_id = :uid')
                ->execute([':uid' => $record->userId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO selfdataguard_escrow_fields (user_id, field_name, ciphertext, updated_at) VALUES (:uid, :fn, :ct, :ua)'
            );
            foreach ($fields as $fieldName => $ciphertext) {
                $insert->execute([':uid' => $record->userId, ':fn' => $fieldName, ':ct' => $ciphertext, ':ua' => $now]);
            }
        });
    }

    public function loadEscrow(string $userId): ?EscrowRecord
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, wrap_user, wrap_admin, created_at, updated_at, revision, typeof(revision) AS revision_type, admin_key
             FROM selfdataguard_escrow WHERE user_id = :uid'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->rowToEscrow($row);
    }

    public function saveEscrowFields(VaultGeneration $generation, array $fields): void
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
        $this->atomic(function () use ($stmt, $generation, $fields, $now): void {
            $this->assertGeneration($generation);
            foreach ($fields as $fieldName => $ciphertext) {
                $stmt->execute([
                    ':uid' => $generation->userId,
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

    // -- Archives ---------------------------------------------------------------

    public function replaceWithArchive(VaultGeneration $replacing, VaultRecord $new, ?string $ticket = null): string
    {
        if ($new->userId !== $replacing->userId) {
            throw new InvalidArgumentException('The new vault belongs to another userId than the one it replaces');
        }
        return $this->atomic(function () use ($replacing, $new, $ticket): string {
            $this->assertGeneration($replacing);
            $archiveId = $this->archiveLive($new->userId);
            $this->recordTicket($ticket, 'replace-with-archive', $new->userId, $archiveId);
            $this->purgeLive($new->userId);
            $this->saveVault($new);
            return $archiveId;
        });
    }

    public function claimSetting(string $name, string $value): string
    {
        $held = $this->loadSetting($name);
        if ($held !== null) {
            return $held;
        }
        $insert = $this->pdo->prepare('INSERT OR IGNORE INTO selfdataguard_settings (name, value) VALUES (:n, :v)');
        $read   = $this->pdo->prepare('SELECT value FROM selfdataguard_settings WHERE name = :n');
        return $this->atomic(function () use ($insert, $read, $name, $value): string {
            $insert->execute([':n' => $name, ':v' => $value]);
            $read->execute([':n' => $name]);
            $held = $read->fetchColumn();
            $read->closeCursor();
            if ($held === false) {
                throw new RuntimeException("The setting '{$name}' was written, then is gone");
            }
            return (string) $held;
        });
    }

    public function replaceSetting(string $name, string $expected, string $value): bool
    {
        $stmt = $this->pdo->prepare('UPDATE selfdataguard_settings SET value = :v WHERE name = :n AND value = :e');
        return $this->atomic(function () use ($stmt, $name, $expected, $value): bool {
            $stmt->execute([':v' => $value, ':n' => $name, ':e' => $expected]);
            return $stmt->rowCount() === 1;
        });
    }

    public function loadSetting(string $name): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM selfdataguard_settings WHERE name = :n');
        $stmt->execute([':n' => $name]);
        $held = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $held === false ? null : (string) $held;
    }

    public function hasVaults(): bool
    {
        return (bool) $this->pdo->query(
            'SELECT EXISTS (SELECT 1 FROM selfdataguard_vaults) OR EXISTS (SELECT 1 FROM selfdataguard_archives)'
        )->fetchColumn();
    }

    public function indexedFields(): array
    {
        $out = [];
        foreach ($this->pdo->query(
            'SELECT field_name, MAX(shared_index IS NOT NULL) AS shared FROM selfdataguard_fields
             WHERE blind_index IS NOT NULL OR shared_index IS NOT NULL
             GROUP BY field_name ORDER BY field_name'
        ) as $row) {
            $out[(string) $row['field_name']] = (bool) $row['shared'];
        }
        return $out;
    }

    public function accountIds(string $after = '', int $limit = 500): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("accountIds() takes a limit of at least 1, got {$limit}");
        }
        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM selfdataguard_vaults WHERE user_id > :after
             UNION
             SELECT user_id FROM selfdataguard_archives WHERE user_id > :after
             ORDER BY 1 LIMIT :limit'
        );
        $stmt->bindValue(':after', $after);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function listArchives(string $userId): array
    {
        // The locks are read from the package, the bytes an opening uses and the
        // seal covers — not from the `locks` column beside it, which a write
        // could leave announcing an envelope the package no longer holds.
        $stmt = $this->pdo->prepare(
            'SELECT archive_id, archived_at, package FROM selfdataguard_archives
             WHERE user_id = :uid ORDER BY archived_at, rowid'
        );
        $stmt->execute([':uid' => $userId]);
        $out = [];
        while ($row = $stmt->fetch()) {
            $package = $this->package($row, $userId);
            $out[] = [
                'id'         => (string) $row['archive_id'],
                'archivedAt' => new DateTimeImmutable((string) $row['archived_at']),
                'locks'      => $this->locksOf($package['vault']),
            ];
        }
        return $out;
    }

    public function loadArchive(string $userId, string $archiveId): ?ArchivedVault
    {
        $stmt = $this->pdo->prepare(
            'SELECT archive_id, archived_at, package FROM selfdataguard_archives
             WHERE user_id = :uid AND archive_id = :id'
        );
        $stmt->execute([':uid' => $userId, ':id' => $archiveId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $package = $this->readablePackage($row, $userId);

        $private = [];
        $blind   = [];
        $shared  = [];
        foreach ($package['fields']['private'] as $name => $field) {
            $private[(string) $name] = ['ciphertext' => (string) $field['ciphertext'], 'wasIndexed' => (bool) $field['was_indexed']];
            if (isset($field['blind_index']) && !str_starts_with((string) $field['blind_index'], self::LOST_INDEX)) {
                $blind[(string) $name] = (string) $field['blind_index'];
            }
            if (isset($field['shared_index'])) {
                $shared[(string) $name] = (string) $field['shared_index'];
            }
        }
        // An escrow that does not parse does not close the archive: the façade
        // reads it as a row rewritten, and restorePackage() puts it back.
        $escrow     = null;
        $unreadable = false;
        if (($package['escrow'] ?? null) !== null) {
            try {
                $escrow = $this->rowToEscrow(['user_id' => $userId] + self::keptEscrow($package['escrow']));
            } catch (UnreadableEscrowException) {
                $unreadable = true;
            }
        }
        [$escrowFields, $fieldsRead] = self::keptEscrowFields($package['fields']);
        $unreadable = $unreadable || !$fieldsRead;
        return new ArchivedVault(
            archiveId:        (string) $row['archive_id'],
            archivedAt:       new DateTimeImmutable((string) $row['archived_at']),
            record:           $this->rowToVault(['user_id' => $userId] + $package['vault']),
            kdfOpslimit:      (int) $package['kdf']['opslimit'],
            kdfMemlimit:      (int) $package['kdf']['memlimit'],
            escrow:           $escrow,
            privateFields:    $private,
            escrowFields:     $escrowFields,
            escrowUnreadable: $unreadable,
            blindIndexes:     $blind,
            sharedIndexes:    $shared,
        );
    }

    public function replaceArchiveEscrow(string $userId, string $archiveId, EscrowRecord $record): void
    {
        if ($record->userId !== $userId) {
            throw new InvalidArgumentException('The escrow record belongs to another userId than the archive');
        }
        $this->atomic(function () use ($userId, $archiveId, $record): void {
            $stmt = $this->pdo->prepare(
                'SELECT archive_id, package FROM selfdataguard_archives WHERE user_id = :uid AND archive_id = :id'
            );
            $stmt->execute([':uid' => $userId, ':id' => $archiveId]);
            $row = $stmt->fetch();
            $stmt->closeCursor();
            if ($row === false) {
                throw new VaultNotFoundException("No archive '{$archiveId}' for userId '{$userId}'");
            }
            $package = $this->readablePackage($row, $userId);
            if (!is_array($package['escrow'] ?? null)) {
                throw new EscrowNotFoundException("The archive '{$archiveId}' of '{$userId}' holds no escrow");
            }
            $held = self::packageRevision($package['escrow']['revision'] ?? 0);
            if ($held === null || $held !== $record->revision) {
                throw new StaleEscrowException(
                    "The escrow of archive '{$archiveId}' was replaced since this record was read — reload it"
                );
            }
            $package['escrow'] = [
                'wrap_user'  => $record->wrapUser->toBase64(),
                'wrap_admin' => base64_encode($record->wrapAdmin),
                'updated_at' => $record->updatedAt->format('c'),
                'revision'   => $held + 1,
                'admin_key'  => $record->adminKey,
            ] + $package['escrow'];
            // Decoded as arrays, the field maps are objects again before they are
            // written: a field named "0" would otherwise come back as a JSON list.
            $package['fields']['private'] = (object) $package['fields']['private'];
            $package['fields']['escrow']  = (object) $package['fields']['escrow'];
            $this->pdo->prepare('UPDATE selfdataguard_archives SET package = :pkg WHERE archive_id = :id')->execute([
                ':pkg' => json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ':id'  => $archiveId,
            ]);
        });
    }

    public function replaceArchiveVault(string $userId, string $archiveId, VaultRecord $record): int
    {
        if ($record->userId !== $userId) {
            throw new InvalidArgumentException('The vault record belongs to another userId than the archive');
        }
        return $this->atomic(function () use ($userId, $archiveId, $record): int {
            $stmt = $this->pdo->prepare(
                'SELECT archive_id, package FROM selfdataguard_archives WHERE user_id = :uid AND archive_id = :id'
            );
            $stmt->execute([':uid' => $userId, ':id' => $archiveId]);
            $row = $stmt->fetch();
            $stmt->closeCursor();
            if ($row === false) {
                throw new VaultNotFoundException("No archive '{$archiveId}' for userId '{$userId}'");
            }
            $package = $this->readablePackage($row, $userId);
            $vault   = is_array($package['vault'] ?? null) ? $package['vault'] : [];
            $held    = self::packageRevision($vault['revision'] ?? 0);
            if (
                $held === null || $held !== $record->revision
                || !hash_equals((string) ($vault['user_salt'] ?? ''), base64_encode($record->userSalt))
            ) {
                throw new StaleVaultException(
                    "The vault of archive '{$archiveId}' is another one, or was replaced since this record was read — reload it"
                );
            }
            $package['vault'] = [
                'wrap_pwd'     => $record->wrapPwd->toBase64(),
                'wrap_recov'   => $record->wrapRecov?->toBase64(),
                'wrap_phrase'  => $record->wrapPhrase?->toBase64(),
                'kdf_opslimit' => $record->kdfOpslimit,
                'kdf_memlimit' => $record->kdfMemlimit,
                'profile_seal' => $record->profileSeal === null ? null : base64_encode($record->profileSeal),
                'updated_at'   => $record->updatedAt->format('c'),
                'revision'     => $held + 1,
            ] + $vault;
            $package['kdf'] = ['opslimit' => $record->kdfOpslimit, 'memlimit' => $record->kdfMemlimit];
            $package['fields']['private'] = (object) $package['fields']['private'];
            $package['fields']['escrow']  = (object) $package['fields']['escrow'];
            $this->pdo->prepare('UPDATE selfdataguard_archives SET package = :pkg, locks = :locks WHERE archive_id = :id')->execute([
                ':pkg'   => json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ':locks' => implode(',', array_map(static fn (Lock $l) => $l->value, $this->locksOf($package['vault']))),
                ':id'    => $archiveId,
            ]);
            return $held + 1;
        });
    }

    /**
     * A revision kept in an archive package, when it is one the next write can
     * bump: an integer — a string of digits, as an older PDO fetched it —,
     * never a real or a boolean, which a cast would turn into one.
     */
    private static function packageRevision(mixed $raw): ?int
    {
        if (is_string($raw) && preg_match('/^\d{1,19}$/', $raw) === 1) {
            $raw = filter_var($raw, FILTER_VALIDATE_INT);
        }
        return is_int($raw) && $raw >= 0 && $raw < PHP_INT_MAX ? $raw : null;
    }

    public function deleteArchive(VaultGeneration $generation, string $archiveId): bool
    {
        return $this->atomic(function () use ($generation, $archiveId): bool {
            $this->assertGeneration($generation);
            $stmt = $this->pdo->prepare(
                'DELETE FROM selfdataguard_archives WHERE user_id = :uid AND archive_id = :id'
            );
            $stmt->execute([':uid' => $generation->userId, ':id' => $archiveId]);
            $deleted = $stmt->rowCount() > 0;
            $this->untieTickets($generation->userId);
            return $deleted;
        });
    }

    public function swapWithArchive(VaultGeneration $generation, string $archiveId, ?string $ticket = null): string
    {
        return $this->atomic(function () use ($generation, $archiveId, $ticket): string {
            $this->assertGeneration($generation);
            $this->recordTicket($ticket, 'swap-with-archive', $generation->userId, $archiveId);
            $package = $this->takeArchive($generation->userId, $archiveId);
            $setAside = $this->archiveLive($generation->userId);
            $this->purgeLive($generation->userId);
            $this->restorePackage($generation->userId, $package);
            return $setAside;
        });
    }

    public function restoreArchive(string $userId, string $archiveId): void
    {
        $this->atomic(function () use ($userId, $archiveId): void {
            if ($this->fetchVaultRow($userId) !== null) {
                throw new VaultExistsException("'{$userId}' holds a live vault: bring an archive back with swapWithArchive()");
            }
            $this->restorePackage($userId, $this->takeArchive($userId, $archiveId));
        });
    }

    public function freezeVault(VaultGeneration $generation, string $by, DateTimeImmutable $at, ?string $ticket = null): bool
    {
        return $this->atomic(function () use ($generation, $by, $at, $ticket): bool {
            $this->assertGeneration($generation, evenFrozen: true);
            $this->recordTicket($ticket, 'freeze', $generation->userId, null);
            $stmt = $this->pdo->prepare(
                'UPDATE selfdataguard_vaults SET frozen_by = :by, frozen_at = :at
                 WHERE user_id = :uid AND frozen_by IS NULL'
            );
            $stmt->execute([
                ':by'  => $by,
                ':at'  => $at->setTimezone(new DateTimeZone('UTC'))->format('c'),
                ':uid' => $generation->userId,
            ]);
            return $stmt->rowCount() > 0;
        });
    }

    public function unfreezeVault(VaultGeneration $generation, ?string $ticket = null): bool
    {
        return $this->atomic(function () use ($generation, $ticket): bool {
            $this->assertGeneration($generation, evenFrozen: true);
            $this->recordTicket($ticket, 'unfreeze', $generation->userId, null);
            $stmt = $this->pdo->prepare(
                'UPDATE selfdataguard_vaults SET frozen_by = NULL, frozen_at = NULL
                 WHERE user_id = :uid AND frozen_by IS NOT NULL'
            );
            $stmt->execute([':uid' => $generation->userId]);
            return $stmt->rowCount() > 0;
        });
    }

    public function updateFrozenLocks(VaultRecord $record): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE selfdataguard_vaults
             SET wrap_pwd = :wp, wrap_recov = :wr, wrap_phrase = :wph, profile_seal = :ps, updated_at = :ua,
                 revision = revision + 1
             WHERE user_id = :uid AND user_salt = :salt AND revision = :rev AND frozen_by IS NOT NULL'
        );
        $stmt->execute([
            ':uid'  => $record->userId,
            ':salt' => base64_encode($record->userSalt),
            ':rev'  => $record->revision,
            ':wp'   => $record->wrapPwd->toBase64(),
            ':wr'   => $record->wrapRecov?->toBase64(),
            ':wph'  => $record->wrapPhrase?->toBase64(),
            ':ps'   => $record->profileSeal === null ? null : base64_encode($record->profileSeal),
            ':ua'   => $record->updatedAt->format('c'),
        ]);
        if ($stmt->rowCount() === 0) {
            $row = $this->fetchVaultRow($record->userId)
                ?? throw new VaultNotFoundException("Vault not found for userId '{$record->userId}'");
            if ($row['user_salt'] === base64_encode($record->userSalt) && $this->frozenState($record->userId) === null) {
                throw new \LogicException("The vault of '{$record->userId}' is not frozen: updateVault() writes it");
            }
            throw new StaleVaultException(
                "Vault for userId '{$record->userId}' changed or was replaced since this record was read — reload it"
            );
        }
        return $record->revision + 1;
    }

    public function archiveOfTicket(string $ticket): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT archive_id FROM selfdataguard_tickets WHERE ticket = :t AND operation = 'replace-with-archive'"
        );
        $stmt->execute([':t' => $ticket]);
        $id = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $id === false || $id === null ? null : (string) $id;
    }

    public function frozenState(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT frozen_by, frozen_at FROM selfdataguard_vaults WHERE user_id = :uid AND frozen_by IS NOT NULL'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        $stmt->closeCursor();
        return $row === false ? null : ['by' => (string) $row['frozen_by'], 'at' => new DateTimeImmutable((string) $row['frozen_at'])];
    }

    public function eraseAccount(string $userId): int
    {
        return $this->atomic(function () use ($userId): int {
            $this->refuseWhileFrozen($userId, 'the account is not erased, nothing of it is');
            $live = $this->fetchVaultRow($userId) !== null ? 1 : 0;
            $keep = $this->appliedLevel3($userId);
            $this->purgeLive($userId);
            $archived = 0;
            if (!$keep) {
                $stmt = $this->pdo->prepare('DELETE FROM selfdataguard_archives WHERE user_id = :uid');
                $stmt->execute([':uid' => $userId]);
                $archived = $stmt->rowCount();
            }
            $this->untieTickets($userId);
            return $live + $archived;
        });
    }

    public function eraseArchive(string $userId, string $archiveId): bool
    {
        return $this->atomic(function () use ($userId, $archiveId): bool {
            $this->refuseWhileFrozen($userId, 'no archive of it is erased until the case is closed');
            $stmt = $this->pdo->prepare('DELETE FROM selfdataguard_archives WHERE user_id = :uid AND archive_id = :id');
            $stmt->execute([':uid' => $userId, ':id' => $archiveId]);
            $erased = $stmt->rowCount() > 0;
            $this->untieTickets($userId);
            return $erased;
        });
    }

    public function keptArchives(?string $userId = null): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, archive_id, archived_at FROM selfdataguard_archives a
             WHERE NOT EXISTS (SELECT 1 FROM selfdataguard_vaults v WHERE v.user_id = a.user_id)
               AND (:uid IS NULL OR a.user_id = :auid)
             ORDER BY archived_at, rowid'
        );
        $stmt->execute([':uid' => $userId, ':auid' => $userId]);
        $out = [];
        while ($row = $stmt->fetch()) {
            $out[] = [
                'userId'     => (string) $row['user_id'],
                'id'         => (string) $row['archive_id'],
                'archivedAt' => new DateTimeImmutable((string) $row['archived_at']),
            ];
        }
        return $out;
    }

    public function reservedIndexes(): array
    {
        [$sql, $params] = $this->reservingArchives('1 = 1');
        $stmt = $this->pdo->prepare("SELECT * FROM ({$sql}) ORDER BY archived_at, archive_id");
        $stmt->execute($params);
        $out = [];
        while ($row = $stmt->fetch()) {
            $package = json_decode((string) $row['package'], true);
            $fields  = is_array($package) && is_array($package['fields']['private'] ?? null) ? $package['fields']['private'] : [];
            $until   = (new DateTimeImmutable((string) $row['archived_at']))->modify('+' . RecoveryTickets::RETURN_WINDOW . ' seconds');
            foreach ($fields as $name => $field) {
                $index = is_array($field) ? ($field['blind_index'] ?? null) : null;
                if (is_string($index) && !str_starts_with($index, self::LOST_INDEX)) {
                    $out[] = [
                        'userId'    => (string) $row['user_id'],
                        'archiveId' => (string) $row['archive_id'],
                        'field'     => (string) $name,
                        'until'     => $until,
                        'frozen'    => (bool) $row['frozen'],
                    ];
                }
            }
        }
        return $out;
    }

    public function lostIndexes(?string $userId = null): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, field_name FROM selfdataguard_fields
             WHERE substr(blind_index, 1, length(:lost)) = :prefix AND (:uid IS NULL OR user_id = :fuid)
             ORDER BY user_id, field_name'
        );
        $stmt->execute([':lost' => self::LOST_INDEX, ':prefix' => self::LOST_INDEX, ':uid' => $userId, ':fuid' => $userId]);
        $out = [];
        while ($row = $stmt->fetch()) {
            $out[(string) $row['user_id']][] = (string) $row['field_name'];
        }
        return $out;
    }

    // -------------------------------------------------------------------------

    /**
     * Inside the write's own transaction, the live vault must still be the one
     * $generation names. Checked beforehand only, a re-enrolment committed in
     * between would let an old session write under the old master key into
     * the new vault. A frozen vault refuses the write, unless $evenFrozen.
     */
    private function assertGeneration(VaultGeneration $generation, bool $evenFrozen = false): void
    {
        $stmt = $this->pdo->prepare('SELECT user_salt, frozen_by FROM selfdataguard_vaults WHERE user_id = :uid');
        $stmt->execute([':uid' => $generation->userId]);
        $row = $stmt->fetch();
        $stmt->closeCursor();
        if ($row === false) {
            throw new VaultNotFoundException("Vault not found for userId '{$generation->userId}'");
        }
        if (!hash_equals(base64_encode($generation->userSalt), (string) $row['user_salt'])) {
            throw new StaleVaultException(
                "The live vault of '{$generation->userId}' is not the one this write names — load the current one"
            );
        }
        if ($row['frozen_by'] !== null && !$evenFrozen) {
            throw new VaultFrozenException("The vault of '{$generation->userId}' is frozen: it reads, and refuses every write");
        }
    }

    /**
     * VaultFrozenException when the live vault of $userId is frozen: during
     * the case, nothing of the account is erased. Callers run it inside
     * atomic(), before anything is deleted.
     */
    private function refuseWhileFrozen(string $userId, string $what): void
    {
        if ($this->frozenState($userId) !== null) {
            throw new VaultFrozenException("The vault of '{$userId}' is frozen: {$what}");
        }
    }

    /** Whether a re-enrolment of $userId applied a ticket: the account then keeps every archive through eraseAccount(). */
    private function appliedLevel3(string $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM selfdataguard_tickets WHERE user_id = :uid AND operation = 'replace-with-archive' LIMIT 1"
        );
        $stmt->execute([':uid' => $userId]);
        $applied = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();
        return $applied;
    }

    /**
     * Once $userId holds no live vault and no archive, the tickets applied to
     * it stay — a replay of one is refused — and no longer name it nor an
     * archive: whoever registers the userId next does not inherit them.
     * Callers run it inside atomic().
     */
    private function untieTickets(string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT EXISTS (SELECT 1 FROM selfdataguard_vaults WHERE user_id = :uid)
                 OR EXISTS (SELECT 1 FROM selfdataguard_archives WHERE user_id = :auid)'
        );
        $stmt->execute([':uid' => $userId, ':auid' => $userId]);
        $holds = (bool) $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($holds) {
            return;
        }
        $this->pdo->prepare("UPDATE selfdataguard_tickets SET user_id = '', archive_id = NULL WHERE user_id = :uid")
            ->execute([':uid' => $userId]);
    }

    /**
     * The live vault of $userId, its fields and its escrow — gone.
     *
     * Explicit deletes, not the FK cascade: `PRAGMA foreign_keys` is a no-op
     * on a connection the caller handed over with a transaction open. Callers
     * run it inside atomic().
     */
    private function purgeLive(string $userId): void
    {
        foreach (['selfdataguard_fields', 'selfdataguard_escrow_fields', 'selfdataguard_escrow', 'selfdataguard_vaults'] as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE user_id = :uid")->execute([':uid' => $userId]);
        }
    }

    /**
     * Everything an archive must keep, as stored — nothing is decrypted. The
     * indexes go into the package, where no lookup reaches them: live, they
     * would keep answering for data the user no longer has. A blind index
     * stays reserved to the userId there while the archive can come back
     * (reservedByAnother()), and restorePackage() puts it back. The keys are added to format 1, which
     * 0.7.0 reads by ignoring them.
     *
     * The escrow row goes whole, not as a list of columns: a column added
     * since — the revision, in 0.9.0 — would otherwise be lost to the archive
     * without an error.
     *
     * @param array<string, mixed> $vault the live vault row
     */
    private function archivePackage(array $vault): string
    {
        $uid = [':uid' => $vault['user_id']];

        $stmt = $this->pdo->prepare('SELECT * FROM selfdataguard_escrow WHERE user_id = :uid');
        $stmt->execute($uid);
        $escrow = $stmt->fetch() ?: null;
        if ($escrow !== null) {
            unset($escrow['user_id']);
        }

        $private = [];
        $stmt = $this->pdo->prepare(
            'SELECT field_name, ciphertext, blind_index, shared_index FROM selfdataguard_fields WHERE user_id = :uid'
        );
        $stmt->execute($uid);
        while ($f = $stmt->fetch()) {
            $private[(string) $f['field_name']] = [
                'ciphertext'   => $f['ciphertext'],
                'was_indexed'  => $f['blind_index'] !== null || $f['shared_index'] !== null,
                'blind_index'  => $f['blind_index'],
                'shared_index' => $f['shared_index'],
            ];
        }

        $escrowFields = [];
        $stmt = $this->pdo->prepare('SELECT field_name, ciphertext FROM selfdataguard_escrow_fields WHERE user_id = :uid');
        $stmt->execute($uid);
        while ($f = $stmt->fetch()) {
            $escrowFields[(string) $f['field_name']] = $f['ciphertext'];
        }

        unset($vault['user_id'], $vault['revision_type']);
        return json_encode([
            'format' => self::ARCHIVE_FORMAT,
            'kdf'    => ['opslimit' => (int) $vault['kdf_opslimit'], 'memlimit' => (int) $vault['kdf_memlimit']],
            'vault'  => $vault,
            'escrow' => $escrow,
            'fields' => ['private' => (object) $private, 'escrow' => (object) $escrowFields],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The archive package of $row, refused as tampering when it cannot be read
     * as one — never a JsonException or a TypeError for the caller to meet.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function package(array $row, string $userId): array
    {
        try {
            $package = json_decode((string) $row['package'], true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $package = null;
        }
        if (!is_array($package) || !is_array($package['vault'] ?? null)) {
            throw new VaultTamperedException(
                "The archive '{$row['archive_id']}' of '{$userId}' cannot be read: its package was changed in the database"
            );
        }
        return $package;
    }

    /**
     * $ticket recorded as applied to $operation, inside the caller's atomic():
     * the primary key makes a second application fail, so a replay writes
     * nothing twice. A null ticket records nothing.
     */
    private function recordTicket(?string $ticket, string $operation, string $userId, ?string $archiveId): void
    {
        if ($ticket === null) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO selfdataguard_tickets (ticket, operation, user_id, archive_id, applied_at)
             VALUES (:t, :op, :uid, :aid, :at)'
        );
        try {
            $stmt->execute([
                ':t'   => $ticket,
                ':op'  => $operation,
                ':uid' => $userId,
                ':aid' => $archiveId,
                ':at'  => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
            ]);
        } catch (PDOException $e) {
            $stmt->closeCursor();
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'PRIMARY KEY')) {
                throw new TicketAlreadyAppliedException("The ticket was already applied to {$operation}", 0, $e);
            }
            throw $e;
        }
    }

    /**
     * The package of $row, in a format this version reads.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function readablePackage(array $row, string $userId): array
    {
        $package = $this->package($row, $userId);
        $format = $package['format'] ?? null;
        if ($format !== self::ARCHIVE_FORMAT) {
            throw new RuntimeException(is_int($format) && $format > self::ARCHIVE_FORMAT
                ? "Archive {$row['archive_id']} was written by a newer SelfDataGuard (format {$format}) — upgrade to read it"
                : "Archive {$row['archive_id']} has no readable format version");
        }
        return $package;
    }

    /**
     * The live vault of $userId, with its fields and escrow, written as a new
     * archive. The live rows stay: the caller purges them. Callers run it
     * inside atomic().
     *
     * @return string the archive id
     */
    private function archiveLive(string $userId): string
    {
        $live = $this->fetchVaultRow($userId)
            ?? throw new VaultNotFoundException("Vault not found for userId '{$userId}'");
        $archiveId = bin2hex(random_bytes(16));
        $this->pdo->prepare(
            'INSERT INTO selfdataguard_archives (archive_id, user_id, archived_at, locks, package)
             VALUES (:id, :uid, :at, :locks, :pkg)'
        )->execute([
            ':id'    => $archiveId,
            ':uid'   => $userId,
            ':at'    => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
            // Read by 0.6.2, so that a rollback still lists the archive; 0.7.0
            // reads the locks from the package.
            ':locks' => implode(',', array_map(static fn (Lock $l) => $l->value, $this->locksOf($live))),
            ':pkg'   => $this->archivePackage($live),
        ]);
        return $archiveId;
    }

    /**
     * Archive $archiveId of $userId, read and deleted. Callers run it inside
     * atomic(), so that a failed restoration leaves it in place.
     *
     * @return array<string, mixed> its package
     */
    private function takeArchive(string $userId, string $archiveId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT archive_id, package FROM selfdataguard_archives WHERE user_id = :uid AND archive_id = :id'
        );
        $stmt->execute([':uid' => $userId, ':id' => $archiveId]);
        $row = $stmt->fetch();
        $stmt->closeCursor();
        if ($row === false) {
            throw new VaultNotFoundException("No archive '{$archiveId}' for userId '{$userId}'");
        }
        $package = $this->readablePackage($row, $userId);
        $this->pdo->prepare('DELETE FROM selfdataguard_archives WHERE archive_id = :id')->execute([':id' => $archiveId]);
        return $package;
    }

    /**
     * The vault of $package, live again under $userId: its row as archived,
     * its fields with the indexes the package kept, its escrow row and its
     * escrow fields. A field whose blind index another account holds now comes
     * back with the trace of a lost index instead (lostIndexes()). An escrow
     * row that does not parse comes back as it was kept; a column it lacks or
     * that holds no value comes back as one that does not parse, and a row
     * that is not one as a row of such columns. Callers run it inside atomic(),
     * with no live vault left for $userId.
     *
     * @param array<string, mixed> $package
     */
    private function restorePackage(string $userId, array $package): void
    {
        $this->saveVault($this->rowToVault(['user_id' => $userId] + $package['vault']));

        $field = $this->pdo->prepare(
            'INSERT INTO selfdataguard_fields (user_id, field_name, ciphertext, blind_index, updated_at, shared_index)
             VALUES (:uid, :fn, :ct, :bi, :ua, :si)'
        );
        $now = (new DateTimeImmutable())->format('c');
        foreach ($package['fields']['private'] as $name => $f) {
            $params = [
                ':uid' => $userId,
                ':fn'  => (string) $name,
                ':ct'  => (string) $f['ciphertext'],
                ':bi'  => isset($f['blind_index']) ? (string) $f['blind_index'] : null,
                ':ua'  => $now,
                ':si'  => isset($f['shared_index']) ? (string) $f['shared_index'] : null,
            ];
            try {
                $field->execute($params);
            } catch (PDOException $e) {
                // A constraint failure undoes this statement only: the field
                // is inserted again, without the index another account holds.
                if (!str_contains($e->getMessage(), 'selfdataguard_fields.blind_index')) {
                    throw $e;
                }
                $field->closeCursor();
                $field->execute([':bi' => self::LOST_INDEX . bin2hex(random_bytes(16))] + $params);
            }
        }

        if (($package['escrow'] ?? null) !== null) {
            $row     = ['user_id' => $userId] + self::keptEscrow($package['escrow']);
            $columns = $this->columnsOf('selfdataguard_escrow');
            $unknown = array_diff(array_keys($row), $columns);
            if ($unknown !== []) {
                throw new RuntimeException(
                    "The archive of '{$userId}' carries escrow columns this database does not have ("
                    . implode(', ', $unknown) . '): it was written by a newer SelfDataGuard — upgrade to bring it back'
                );
            }
            foreach ($this->pdo->query('PRAGMA table_info(selfdataguard_escrow)')->fetchAll() as $info) {
                $column = (string) $info['name'];
                if (isset($row[$column]) && !is_scalar($row[$column])) {
                    $row[$column] = self::UNREADABLE;
                }
                if (($row[$column] ?? null) === null && (int) $info['notnull'] === 1) {
                    if ($info['dflt_value'] === null) {
                        $row[$column] = self::UNREADABLE;
                    } else {
                        unset($row[$column]);
                    }
                }
            }
            $kept = array_values(array_intersect($columns, array_keys($row)));
            $this->pdo->prepare(
                'INSERT INTO selfdataguard_escrow (' . implode(', ', $kept) . ') VALUES (:' . implode(', :', $kept) . ')'
            )->execute(array_combine(
                array_map(static fn (string $c): string => ":{$c}", $kept),
                array_map(static fn (string $c): mixed => $row[$c], $kept)
            ));
        }
        $escrowField = $this->pdo->prepare(
            'INSERT INTO selfdataguard_escrow_fields (user_id, field_name, ciphertext, updated_at) VALUES (:uid, :fn, :ct, :ua)'
        );
        foreach (self::keptEscrowFields($package['fields'])[0] as $name => $ciphertext) {
            $escrowField->execute([':uid' => $userId, ':fn' => $name, ':ct' => $ciphertext, ':ua' => $now]);
        }
    }

    /**
     * The columns of the escrow row an archive package keeps; none when what
     * it keeps is not a row — a value, a list.
     *
     * @return array<string, mixed>
     */
    private static function keptEscrow(mixed $kept): array
    {
        return is_array($kept) && !array_is_list($kept) ? $kept : [];
    }

    /**
     * The escrow fields an archive package keeps, and whether they read as a
     * map of ciphertexts: one that is not a map comes as none, a ciphertext
     * that is not a value as one that does not decrypt.
     *
     * @param mixed $fields the package's `fields`
     * @return array{0: array<string, string>, 1: bool}
     */
    private static function keptEscrowFields(mixed $fields): array
    {
        if (!is_array($fields) || !array_key_exists('escrow', $fields)) {
            return [[], true];
        }
        if (!is_array($fields['escrow'])) {
            return [[], false];
        }
        $out  = [];
        $read = true;
        foreach ($fields['escrow'] as $name => $ciphertext) {
            $read = $read && is_scalar($ciphertext);
            $out[(string) $name] = is_scalar($ciphertext) ? (string) $ciphertext : self::UNREADABLE;
        }
        return [$out, $read];
    }

    /**
     * @param array<string, mixed> $vault
     * @return list<Lock>
     */
    private function locksOf(array $vault): array
    {
        return array_values(array_filter([
            Lock::Password,
            $vault['wrap_recov'] !== null ? Lock::Memorized : null,
            $vault['wrap_phrase'] !== null ? Lock::Passphrase : null,
        ]));
    }

    /**
     * Runs $work atomically — inside the caller's transaction if one is open.
     *
     * Its own transaction starts with BEGIN IMMEDIATE, not PDO's deferred
     * BEGIN: every transaction here reads, then writes, and SQLite refuses
     * to turn a read into a write while another connection writes —
     * "database is locked" at once, the busy timeout ignored. IMMEDIATE takes
     * the write lock up front, so a concurrent writer is waited for instead.
     *
     * Opened in SQL, it is closed in SQL, and its state is tracked here, not
     * asked of PDO: before PHP 8.4, PDO does not see a transaction opened by
     * exec() — inTransaction() answers false and commit() throws "There is no
     * active transaction", leaving the transaction open. For the same reason
     * a caller's transaction opened in plain SQL is detected by SQLite's own
     * refusal of a second BEGIN.
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
        if (!$this->ownTransaction && !$this->pdo->inTransaction() && $this->beginImmediate()) {
            $this->ownTransaction = true;
            try {
                $result = $work();
                $this->pdo->exec('COMMIT');
                return $result;
            } catch (Throwable $e) {
                $this->undo('ROLLBACK');
                throw $e;
            } finally {
                $this->ownTransaction = false;
            }
        }

        $point = 'selfdataguard_' . spl_object_id($this) . '_' . ++$this->depth;
        $this->pdo->exec('SAVEPOINT ' . $point);
        try {
            $result = $work();
            $this->pdo->exec('RELEASE SAVEPOINT ' . $point);
            return $result;
        } catch (Throwable $e) {
            $this->undo('ROLLBACK TO SAVEPOINT ' . $point);
            $this->undo('RELEASE SAVEPOINT ' . $point);
            throw $e;
        } finally {
            $this->depth--;
        }
    }

    /**
     * BEGIN IMMEDIATE, or false when SQLite answers that a transaction is
     * already open: the caller's, opened in plain SQL, which PDO before 8.4
     * does not report through inTransaction().
     */
    private function beginImmediate(): bool
    {
        try {
            $this->pdo->exec('BEGIN IMMEDIATE');
            return true;
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'within a transaction')) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * An undo on an error path. SQLite may have rolled the transaction back by
     * itself already (some I/O and disk-full errors do): the undo then fails,
     * and the original error is the one worth reporting.
     */
    private function undo(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (PDOException) {
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
                wrap_phrase TEXT,
                revision    INTEGER NOT NULL DEFAULT 0,
                kdf_opslimit INTEGER NOT NULL DEFAULT ' . Primitives::LEGACY_OPSLIMIT . ',
                kdf_memlimit INTEGER NOT NULL DEFAULT ' . Primitives::LEGACY_MEMLIMIT . ',
                profile_seal TEXT,
                frozen_by   TEXT,
                frozen_at   TEXT
            )'
        );
        $this->addMissingColumn('selfdataguard_vaults', 'wrap_phrase', 'TEXT');
        $this->addMissingColumn('selfdataguard_vaults', 'revision', 'INTEGER NOT NULL DEFAULT 0');
        // A row stored before 0.6.0 was sealed under the legacy profile: that is
        // what the default must say, whatever the current constants become.
        $this->addMissingColumn('selfdataguard_vaults', 'kdf_opslimit', 'INTEGER NOT NULL DEFAULT ' . Primitives::LEGACY_OPSLIMIT);
        $this->addMissingColumn('selfdataguard_vaults', 'kdf_memlimit', 'INTEGER NOT NULL DEFAULT ' . Primitives::LEGACY_MEMLIMIT);
        $this->addMissingColumn('selfdataguard_vaults', 'profile_seal', 'TEXT');
        $this->addMissingColumn('selfdataguard_vaults', 'frozen_by', 'TEXT');
        $this->addMissingColumn('selfdataguard_vaults', 'frozen_at', 'TEXT');
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS selfdataguard_vaults_frozen
             ON selfdataguard_vaults(user_id) WHERE frozen_by IS NOT NULL'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS selfdataguard_settings (
                name   TEXT PRIMARY KEY,
                value  TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS selfdataguard_tickets (
                ticket      TEXT NOT NULL,
                operation   TEXT NOT NULL,
                user_id     TEXT NOT NULL,
                archive_id  TEXT,
                applied_at  TEXT NOT NULL,
                PRIMARY KEY (ticket, operation)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS selfdataguard_archives (
                archive_id  TEXT PRIMARY KEY,
                user_id     TEXT NOT NULL,
                archived_at TEXT NOT NULL,
                locks       TEXT NOT NULL,
                package     TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS selfdataguard_archives_user
             ON selfdataguard_archives(user_id)'
        );
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS selfdataguard_archives_archived_at
             ON selfdataguard_archives(archived_at)'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS selfdataguard_fields (
                user_id     TEXT NOT NULL,
                field_name  TEXT NOT NULL,
                ciphertext  TEXT NOT NULL,
                blind_index TEXT,
                updated_at  TEXT NOT NULL,
                shared_index TEXT,
                PRIMARY KEY (user_id, field_name),
                FOREIGN KEY (user_id) REFERENCES selfdataguard_vaults(user_id) ON DELETE CASCADE
            )'
        );
        $this->addMissingColumn('selfdataguard_fields', 'shared_index', 'TEXT');
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS selfdataguard_fields_shared
             ON selfdataguard_fields(field_name, shared_index)'
        );
        $this->ensureUniqueBlindIndex();
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS selfdataguard_escrow (
                user_id     TEXT PRIMARY KEY,
                wrap_user   TEXT NOT NULL,
                wrap_admin  TEXT NOT NULL,
                created_at  TEXT NOT NULL,
                updated_at  TEXT NOT NULL,
                revision    INTEGER NOT NULL DEFAULT 0,
                admin_key   TEXT,
                FOREIGN KEY (user_id) REFERENCES selfdataguard_vaults(user_id) ON DELETE CASCADE
            )'
        );
        $this->addMissingColumn('selfdataguard_escrow', 'revision', 'INTEGER NOT NULL DEFAULT 0');
        $this->addMissingColumn('selfdataguard_escrow', 'admin_key', 'TEXT');
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
     * The check is repeated inside the write transaction: two processes
     * opening the same old database both see the column missing, and the
     * second ALTER would otherwise fail on "duplicate column name". The write
     * lock (BEGIN IMMEDIATE, see atomic()) makes the second one wait, then
     * find the column there. Columns are added in the order of the CREATE.
     */
    private function addMissingColumn(string $table, string $column, string $definition): void
    {
        if ($this->hasColumn($table, $column)) {
            return;
        }
        $this->atomic(function () use ($table, $column, $definition): void {
            if (!$this->hasColumn($table, $column)) {
                $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        });
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columnsOf($table), true);
    }

    /** @return list<string> the columns of $table, as the database has them */
    private function columnsOf(string $table): array
    {
        return array_map(static fn (array $info): string => (string) $info['name'], $this->pdo->query("PRAGMA table_info({$table})")->fetchAll());
    }

    /**
     * A blind index names one account. A database written before 0.7.0 may hold
     * a value under several accounts — a lookup then picked whichever row came
     * first —, and the unique index cannot be built over them. They are named
     * and refused, not resolved: which account owns a value is not something
     * the storage can tell.
     */
    private function ensureUniqueBlindIndex(): void
    {
        if ($this->hasIndex('selfdataguard_fields_blind_unique')) {
            return;
        }
        $this->atomic(function (): void {
            if ($this->hasIndex('selfdataguard_fields_blind_unique')) {
                return;
            }
            $held = $this->pdo->query(
                'SELECT field_name, COUNT(*) AS n
                 FROM (SELECT field_name FROM selfdataguard_fields
                       WHERE blind_index IS NOT NULL
                       GROUP BY field_name, blind_index HAVING COUNT(*) > 1)
                 GROUP BY field_name ORDER BY field_name'
            )->fetchAll();
            if ($held !== []) {
                throw new RuntimeException(
                    'Blind-index values held by several accounts — '
                    . implode(', ', array_map(static fn (array $h): string => "{$h['field_name']}: {$h['n']}", $held))
                    . '. Since 0.7.0 a blind index names one account, and which one owns each value cannot be'
                    . ' decided here: see "Before upgrading" in the CHANGELOG.'
                );
            }
            $this->pdo->exec(
                'CREATE UNIQUE INDEX selfdataguard_fields_blind_unique
                 ON selfdataguard_fields(field_name, blind_index)'
            );
            $this->pdo->exec('DROP INDEX IF EXISTS selfdataguard_fields_blind');
        });
    }

    private function hasIndex(string $name): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = :name");
        $stmt->execute([':name' => $name]);
        return $stmt->fetchColumn() !== false;
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
            ':rev'  => $record->revision,
            ':ops'  => $record->kdfOpslimit,
            ':mem'  => $record->kdfMemlimit,
            ':ps'   => $record->profileSeal === null ? null : base64_encode($record->profileSeal),
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
            ':rev'  => $record->revision,
            ':ops'  => $record->kdfOpslimit,
            ':mem'  => $record->kdfMemlimit,
            ':ps'   => $record->profileSeal === null ? null : base64_encode($record->profileSeal),
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
        // The live row (`revision_type`): the write compares the revision in
        // SQL, where a text, a blob or a real equals no integer, and bumps it,
        // which the largest integer does not survive.
        if (array_key_exists('revision_type', $row) && (
            $row['revision_type'] !== 'integer'
            || filter_var($row['revision'], FILTER_VALIDATE_INT, ['options' => ['max_range' => PHP_INT_MAX - 1]]) === false
        )) {
            throw new RuntimeException('Corrupted revision in DB (not an integer the next write can bump)');
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
            revision:   (int) ($row['revision'] ?? 0),
            // An archive package written before 0.6.0 carries no profile columns.
            kdfOpslimit: (int) ($row['kdf_opslimit'] ?? Primitives::LEGACY_OPSLIMIT),
            kdfMemlimit: (int) ($row['kdf_memlimit'] ?? Primitives::LEGACY_MEMLIMIT),
            profileSeal: ($row['profile_seal'] ?? null) === null ? null : (string) base64_decode((string) $row['profile_seal'], true),
        );
    }

    /**
     * The revision must be an integer the next write can bump, and, for the
     * live row (`revision_type`), one SQLite stores as an integer: the write
     * compares it in SQL, where a text or a blob equals no integer; one
     * missing reads 0, as for a row written before 0.9.0. Any other column
     * missing, or holding no value — an archived row can lack one —, does not
     * parse.
     *
     * @param array<string, mixed> $row
     * @throws UnreadableEscrowException when a column does not parse
     */
    private function rowToEscrow(array $row): EscrowRecord
    {
        return EscrowRecord::parse((string) $row['user_id'], static function () use ($row): EscrowRecord {
            foreach (['wrap_user', 'wrap_admin', 'created_at', 'updated_at'] as $column) {
                if (!isset($row[$column]) || !is_scalar($row[$column])) {
                    throw new RuntimeException("Missing {$column} in DB");
                }
            }
            $wrapAdmin = base64_decode((string) $row['wrap_admin'], true);
            if ($wrapAdmin === false) {
                throw new RuntimeException('Corrupted wrap_admin in DB (invalid base64)');
            }
            $revision = $row['revision'] ?? 0;
            if (
                filter_var($revision, FILTER_VALIDATE_INT, ['options' => ['max_range' => PHP_INT_MAX - 1]]) === false
                || ($row['revision_type'] ?? 'integer') !== 'integer'
            ) {
                throw new RuntimeException('Corrupted revision in DB (not an integer the next write can bump)');
            }
            $adminKey = $row['admin_key'] ?? null;
            if ($adminKey !== null && !is_string($adminKey)) {
                throw new RuntimeException('Corrupted admin_key in DB (not a fingerprint)');
            }
            return new EscrowRecord(
                userId:    (string) $row['user_id'],
                wrapUser:  EncryptedBlob::fromBase64((string) $row['wrap_user']),
                wrapAdmin: $wrapAdmin,
                createdAt: new DateTimeImmutable((string) $row['created_at']),
                updatedAt: new DateTimeImmutable((string) $row['updated_at']),
                revision:  (int) $revision,
                adminKey:  $adminKey,
            );
        });
    }

    /**
     * $record written over the escrow row of its userId, only at the revision
     * it was read at, which is bumped. Callers run it inside atomic().
     */
    private function writeEscrowOver(EscrowRecord $record): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE selfdataguard_escrow
             SET wrap_user = :wu, wrap_admin = :wa, updated_at = :ua, admin_key = :ak, revision = revision + 1
             WHERE user_id = :uid AND revision = :rev'
        );
        $stmt->execute([
            ':uid' => $record->userId,
            ':wu'  => $record->wrapUser->toBase64(),
            ':wa'  => base64_encode($record->wrapAdmin),
            ':ua'  => $record->updatedAt->format('c'),
            ':ak'  => $record->adminKey,
            ':rev' => $record->revision,
        ]);
        if ($stmt->rowCount() === 0) {
            $held = $this->pdo->prepare('SELECT 1 FROM selfdataguard_escrow WHERE user_id = :uid');
            $held->execute([':uid' => $record->userId]);
            if ($held->fetchColumn() === false) {
                throw new EscrowNotFoundException("No escrow to replace for userId '{$record->userId}'");
            }
            throw new StaleEscrowException(
                "The escrow of '{$record->userId}' was replaced since this record was read — reload it"
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchVaultRow(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, user_salt, wrap_pwd, wrap_recov, wrap_admin, created_at, updated_at, wrap_phrase, revision,
                    kdf_opslimit, kdf_memlimit, profile_seal, typeof(revision) AS revision_type
             FROM selfdataguard_vaults WHERE user_id = :uid'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
