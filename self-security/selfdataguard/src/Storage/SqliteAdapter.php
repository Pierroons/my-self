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
use Pierroons\SelfDataGuard\Vault\ArchivedVault;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\StaleVaultException;
use Pierroons\SelfDataGuard\Vault\VaultExistsException;
use Pierroons\SelfDataGuard\Vault\VaultGeneration;
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
 *     wrap_admin  TEXT             (base64, nullable, reserved, always NULL)
 *     created_at  TEXT NOT NULL    (ISO 8601)
 *     updated_at  TEXT NOT NULL    (ISO 8601)
 *     wrap_phrase TEXT             (base64, nullable — added in 0.5.0, hence last)
 *     revision    INTEGER NOT NULL DEFAULT 0  (bumped by every update; added in 0.5.0)
 *     kdf_opslimit / kdf_memlimit  INTEGER NOT NULL  (the vault's Argon2id profile;
 *                 added in 0.6.0, defaulting to the legacy profile of older rows)
 *     profile_seal TEXT            (base64 ProfileSeal, nullable — added in 0.7.0)
 *
 *   selfdataguard_fields
 *     user_id     TEXT NOT NULL
 *     field_name  TEXT NOT NULL
 *     ciphertext  TEXT NOT NULL    (base64 of EncryptedBlob)
 *     blind_index TEXT             (base64 HMAC, nullable — unique per field_name)
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
 *
 *   selfdataguard_escrow_fields   — escrow fields encrypted with escrow_key
 *
 *   selfdataguard_archives        — vaults set aside by a re-enrolment
 *     archive_id  TEXT PRIMARY KEY (random — a counter would tell how many
 *                 re-enrolments the service has seen)
 *     user_id     TEXT NOT NULL    (no foreign key: the archive outlives the
 *                 live vault it was taken from)
 *     archived_at TEXT NOT NULL    (ISO 8601, UTC, so that the order survives a DST change)
 *     locks       TEXT NOT NULL    (the locks that still open it, comma-separated)
 *     package     TEXT NOT NULL    (JSON: the vault row, escrow, fields — all
 *                 still encrypted —, the Argon2id profile, a format version)
 */
final class SqliteAdapter implements StorageInterface
{
    /** Version of the archive package; a reader refuses a newer one. */
    private const ARCHIVE_FORMAT = 1;

    private PDO $pdo;

    /** Savepoints this instance has open inside a transaction. */
    private int $depth = 0;

    /** True while a transaction this instance opened itself is running. */
    private bool $ownTransaction = false;

    /**
     * Opening a database created by an older version migrates it in place.
     * Constructed inside a transaction that the caller then rolls back, the
     * migration rolls back with it: build a new adapter after such a rollback.
     */
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
             WHERE user_id = :uid AND user_salt = :salt AND revision = :rev'
        );
        $stmt->execute($this->vaultToParamsForUpdate($record));
        if ($stmt->rowCount() === 0) {
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
        });
    }

    public function saveFields(VaultGeneration $generation, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $now = (new DateTimeImmutable())->format('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO selfdataguard_fields (user_id, field_name, ciphertext, blind_index, updated_at, shared_index)
             VALUES (:uid, :fn, :ct, :bi, :ua, :si)
             ON CONFLICT(user_id, field_name) DO UPDATE SET
               ciphertext   = excluded.ciphertext,
               blind_index  = excluded.blind_index,
               updated_at   = excluded.updated_at,
               shared_index = excluded.shared_index'
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

    // -- Escrow compartment ---------------------------------------------------

    public function createEscrow(VaultGeneration $generation, EscrowRecord $record): void
    {
        if ($record->userId !== $generation->userId) {
            throw new InvalidArgumentException('The escrow record belongs to another userId than the generation');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO selfdataguard_escrow (user_id, wrap_user, wrap_admin, created_at, updated_at)
             VALUES (:uid, :wu, :wa, :ca, :ua)'
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
        $stmt = $this->pdo->prepare(
            'UPDATE selfdataguard_escrow SET wrap_user = :wu, wrap_admin = :wa, updated_at = :ua WHERE user_id = :uid'
        );
        $this->atomic(function () use ($stmt, $record, $generation): void {
            $this->assertGeneration($generation);
            $stmt->execute([
                ':uid' => $record->userId,
                ':wu'  => $record->wrapUser->toBase64(),
                ':wa'  => base64_encode($record->wrapAdmin),
                ':ua'  => $record->updatedAt->format('c'),
            ]);
            if ($stmt->rowCount() === 0 && $this->loadEscrow($record->userId) === null) {
                throw new EscrowNotFoundException("No escrow to replace for userId '{$record->userId}'");
            }
        });
    }

    public function loadEscrow(string $userId): ?EscrowRecord
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, wrap_user, wrap_admin, created_at, updated_at
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

    public function replaceWithArchive(VaultGeneration $replacing, VaultRecord $new): string
    {
        if ($new->userId !== $replacing->userId) {
            throw new InvalidArgumentException('The new vault belongs to another userId than the one it replaces');
        }
        return $this->atomic(function () use ($replacing, $new): string {
            $this->assertGeneration($replacing);
            $live = $this->fetchVaultRow($new->userId);
            $archiveId = bin2hex(random_bytes(16));
            $this->pdo->prepare(
                'INSERT INTO selfdataguard_archives (archive_id, user_id, archived_at, locks, package)
                 VALUES (:id, :uid, :at, :locks, :pkg)'
            )->execute([
                ':id'    => $archiveId,
                ':uid'   => $new->userId,
                ':at'    => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
                ':locks' => implode(',', array_map(static fn (Lock $l) => $l->value, $this->locksOf($live))),
                ':pkg'   => $this->archivePackage($live),
            ]);
            $this->purgeLive($new->userId);
            $this->saveVault($new);
            return $archiveId;
        });
    }

    public function claimSetting(string $name, string $value): string
    {
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

    public function listArchives(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT archive_id, archived_at, locks FROM selfdataguard_archives
             WHERE user_id = :uid ORDER BY archived_at, rowid'
        );
        $stmt->execute([':uid' => $userId]);
        $out = [];
        while ($row = $stmt->fetch()) {
            $out[] = [
                'id'         => (string) $row['archive_id'],
                'archivedAt' => new DateTimeImmutable((string) $row['archived_at']),
                'locks'      => array_map(static fn (string $l) => Lock::from($l), explode(',', (string) $row['locks'])),
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
        $package = json_decode((string) $row['package'], true, flags: JSON_THROW_ON_ERROR);
        $format = $package['format'] ?? null;
        if ($format !== self::ARCHIVE_FORMAT) {
            throw new RuntimeException(is_int($format) && $format > self::ARCHIVE_FORMAT
                ? "Archive {$archiveId} was written by a newer SelfDataGuard (format {$format}) — upgrade to read it"
                : "Archive {$archiveId} has no readable format version");
        }

        $private = [];
        foreach ($package['fields']['private'] as $name => $field) {
            $private[(string) $name] = ['ciphertext' => (string) $field['ciphertext'], 'wasIndexed' => (bool) $field['was_indexed']];
        }
        return new ArchivedVault(
            archiveId:     (string) $row['archive_id'],
            archivedAt:    new DateTimeImmutable((string) $row['archived_at']),
            record:        $this->rowToVault(['user_id' => $userId] + $package['vault']),
            kdfOpslimit:   (int) $package['kdf']['opslimit'],
            kdfMemlimit:   (int) $package['kdf']['memlimit'],
            escrow:        $package['escrow'] === null ? null : $this->rowToEscrow(['user_id' => $userId] + $package['escrow']),
            privateFields: $private,
            escrowFields:  array_map('strval', $package['fields']['escrow']),
        );
    }

    public function deleteArchive(VaultGeneration $generation, string $archiveId): bool
    {
        return $this->atomic(function () use ($generation, $archiveId): bool {
            $this->assertGeneration($generation);
            $stmt = $this->pdo->prepare(
                'DELETE FROM selfdataguard_archives WHERE user_id = :uid AND archive_id = :id'
            );
            $stmt->execute([':uid' => $generation->userId, ':id' => $archiveId]);
            return $stmt->rowCount() > 0;
        });
    }

    public function eraseAccount(string $userId): int
    {
        return $this->atomic(function () use ($userId): int {
            $live = $this->fetchVaultRow($userId) !== null ? 1 : 0;
            $this->purgeLive($userId);
            $stmt = $this->pdo->prepare('DELETE FROM selfdataguard_archives WHERE user_id = :uid');
            $stmt->execute([':uid' => $userId]);
            return $live + $stmt->rowCount();
        });
    }

    // -------------------------------------------------------------------------

    /**
     * Inside the write's own transaction, the live vault must still be the one
     * $generation names. Checked beforehand only, a re-enrolment committed in
     * between would let an old session write under the old master key into
     * the new vault.
     */
    private function assertGeneration(VaultGeneration $generation): void
    {
        $stmt = $this->pdo->prepare('SELECT user_salt FROM selfdataguard_vaults WHERE user_id = :uid');
        $stmt->execute([':uid' => $generation->userId]);
        $salt = $stmt->fetchColumn();
        if ($salt === false) {
            throw new VaultNotFoundException("Vault not found for userId '{$generation->userId}'");
        }
        if (!hash_equals(base64_encode($generation->userSalt), (string) $salt)) {
            throw new StaleVaultException(
                "The live vault of '{$generation->userId}' is not the one this write names — load the current one"
            );
        }
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
     * indexes stay behind: they would keep answering equality lookups for data
     * the user no longer has live, and keep the value from its next holder.
     * `was_indexed` says which fields carried one.
     *
     * @param array<string, mixed> $vault the live vault row
     */
    private function archivePackage(array $vault): string
    {
        $uid = [':uid' => $vault['user_id']];

        $stmt = $this->pdo->prepare(
            'SELECT wrap_user, wrap_admin, created_at, updated_at FROM selfdataguard_escrow WHERE user_id = :uid'
        );
        $stmt->execute($uid);
        $escrow = $stmt->fetch() ?: null;

        $private = [];
        $stmt = $this->pdo->prepare(
            'SELECT field_name, ciphertext, blind_index, shared_index FROM selfdataguard_fields WHERE user_id = :uid'
        );
        $stmt->execute($uid);
        while ($f = $stmt->fetch()) {
            $private[(string) $f['field_name']] = [
                'ciphertext'  => $f['ciphertext'],
                'was_indexed' => $f['blind_index'] !== null || $f['shared_index'] !== null,
            ];
        }

        $escrowFields = [];
        $stmt = $this->pdo->prepare('SELECT field_name, ciphertext FROM selfdataguard_escrow_fields WHERE user_id = :uid');
        $stmt->execute($uid);
        while ($f = $stmt->fetch()) {
            $escrowFields[(string) $f['field_name']] = $f['ciphertext'];
        }

        unset($vault['user_id']);
        return json_encode([
            'format' => self::ARCHIVE_FORMAT,
            'kdf'    => ['opslimit' => (int) $vault['kdf_opslimit'], 'memlimit' => (int) $vault['kdf_memlimit']],
            'vault'  => $vault,
            'escrow' => $escrow,
            'fields' => ['private' => (object) $private, 'escrow' => (object) $escrowFields],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
                profile_seal TEXT
            )'
        );
        $this->addMissingColumn('selfdataguard_vaults', 'wrap_phrase', 'TEXT');
        $this->addMissingColumn('selfdataguard_vaults', 'revision', 'INTEGER NOT NULL DEFAULT 0');
        // A row stored before 0.6.0 was sealed under the legacy profile: that is
        // what the default must say, whatever the current constants become.
        $this->addMissingColumn('selfdataguard_vaults', 'kdf_opslimit', 'INTEGER NOT NULL DEFAULT ' . Primitives::LEGACY_OPSLIMIT);
        $this->addMissingColumn('selfdataguard_vaults', 'kdf_memlimit', 'INTEGER NOT NULL DEFAULT ' . Primitives::LEGACY_MEMLIMIT);
        $this->addMissingColumn('selfdataguard_vaults', 'profile_seal', 'TEXT');
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS selfdataguard_settings (
                name   TEXT PRIMARY KEY,
                value  TEXT NOT NULL
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
        foreach ($this->pdo->query("PRAGMA table_info({$table})") as $info) {
            if ($info['name'] === $column) {
                return true;
            }
        }
        return false;
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
     * @param array<string, mixed> $row
     */
    private function rowToEscrow(array $row): EscrowRecord
    {
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

    /**
     * @return array<string, mixed>|null
     */
    private function fetchVaultRow(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, user_salt, wrap_pwd, wrap_recov, wrap_admin, created_at, updated_at, wrap_phrase, revision,
                    kdf_opslimit, kdf_memlimit, profile_seal
             FROM selfdataguard_vaults WHERE user_id = :uid'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
