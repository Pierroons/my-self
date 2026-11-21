<?php

declare(strict_types=1);

/**
 * Storage doubles shared by the benches: a delegating base, and storages that
 * each break one clause of the StorageInterface contract.
 *
 * The broken ones are what sanity_conformance.php plays the conformance suite
 * against: each must make it fail on the clause it breaks. They wrap a real
 * SqliteAdapter, and take its PDO when they need to reach the tables.
 */

use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\Escrow\StaleEscrowException;
use Pierroons\SelfDataGuard\Storage\DuplicateIndexException;
use Pierroons\SelfDataGuard\Storage\ErasesArchives;
use Pierroons\SelfDataGuard\Storage\StorageInterface;
use Pierroons\SelfDataGuard\Storage\UnreadableEscrowException;
use Pierroons\SelfDataGuard\Vault\ArchivedVault;
use Pierroons\SelfDataGuard\Vault\StaleVaultException;
use Pierroons\SelfDataGuard\Vault\VaultGeneration;
use Pierroons\SelfDataGuard\Vault\VaultNotFoundException;
use Pierroons\SelfDataGuard\Vault\VaultRecord;

/** Delegates everything to a real storage; the doubles override one or two methods. */
abstract class DelegatingStorage implements StorageInterface
{
    public function __construct(protected readonly StorageInterface $inner) {}
    public function saveVault(VaultRecord $r): void { $this->inner->saveVault($r); }
    public function updateVault(VaultRecord $r): int { return $this->inner->updateVault($r); }
    public function loadVault(string $u): VaultRecord { return $this->inner->loadVault($u); }
    public function findVault(string $u): ?VaultRecord { return $this->inner->findVault($u); }
    public function vaultExists(string $u): bool { return $this->inner->vaultExists($u); }
    public function deleteVault(VaultGeneration $g): void { $this->inner->deleteVault($g); }
    public function saveFields(VaultGeneration $g, array $f): void { $this->inner->saveFields($g, $f); }
    public function loadFields(string $u, array $n = []): array { return $this->inner->loadFields($u, $n); }
    public function findUserIdsByIndex(string $f, string $i): array { return $this->inner->findUserIdsByIndex($f, $i); }
    public function createEscrow(VaultGeneration $g, EscrowRecord $r): void { $this->inner->createEscrow($g, $r); }
    public function replaceEscrow(VaultGeneration $g, EscrowRecord $r): void { $this->inner->replaceEscrow($g, $r); }
    public function rotateEscrow(VaultGeneration $g, EscrowRecord $r, array $f): void { $this->inner->rotateEscrow($g, $r, $f); }
    public function loadEscrow(string $u): ?EscrowRecord { return $this->inner->loadEscrow($u); }
    public function saveEscrowFields(VaultGeneration $g, array $f): void { $this->inner->saveEscrowFields($g, $f); }
    public function loadEscrowFields(string $u, array $n = []): array { return $this->inner->loadEscrowFields($u, $n); }
    public function replaceWithArchive(VaultGeneration $g, VaultRecord $r, ?string $t = null): string { return $this->inner->replaceWithArchive($g, $r, $t); }
    public function listArchives(string $u): array { return $this->inner->listArchives($u); }
    public function loadArchive(string $u, string $id): ?ArchivedVault { return $this->inner->loadArchive($u, $id); }
    public function replaceArchiveEscrow(string $u, string $id, EscrowRecord $r): void { $this->inner->replaceArchiveEscrow($u, $id, $r); }
    public function deleteArchive(VaultGeneration $g, string $id): bool { return $this->inner->deleteArchive($g, $id); }
    public function swapWithArchive(VaultGeneration $g, string $id, ?string $t = null): string { return $this->inner->swapWithArchive($g, $id, $t); }
    public function restoreArchive(string $u, string $id): void { $this->inner->restoreArchive($u, $id); }
    public function freezeVault(VaultGeneration $g, string $by, \DateTimeImmutable $at, ?string $t = null): bool { return $this->inner->freezeVault($g, $by, $at, $t); }
    public function unfreezeVault(VaultGeneration $g, ?string $t = null): bool { return $this->inner->unfreezeVault($g, $t); }
    public function archiveOfTicket(string $t): ?string { return $this->inner->archiveOfTicket($t); }
    public function frozenState(string $u): ?array { return $this->inner->frozenState($u); }
    public function eraseAccount(string $u): int { return $this->inner->eraseAccount($u); }
    public function claimSetting(string $n, string $v): string { return $this->inner->claimSetting($n, $v); }
    public function replaceSetting(string $n, string $e, string $v): bool { return $this->inner->replaceSetting($n, $e, $v); }
    public function loadSetting(string $n): ?string { return $this->inner->loadSetting($n); }
    public function hasVaults(): bool { return $this->inner->hasVaults(); }
    public function indexedFields(): array { return $this->inner->indexedFields(); }
    public function accountIds(string $a = '', int $l = 500): array { return $this->inner->accountIds($a, $l); }
    public function replaceArchiveVault(string $u, string $id, VaultRecord $r): int { return $this->inner->replaceArchiveVault($u, $id, $r); }
    public function updateFrozenLocks(VaultRecord $r): int { return $this->inner->updateFrozenLocks($r); }
}

/** $record with its revision, or its Argon2id profile, replaced. */
function recordWith(VaultRecord $record, ?int $revision = null, ?int $opslimit = null, ?int $memlimit = null): VaultRecord
{
    return new VaultRecord(
        userId: $record->userId, userSalt: $record->userSalt, wrapPwd: $record->wrapPwd, wrapRecov: $record->wrapRecov,
        wrapAdmin: $record->wrapAdmin, createdAt: $record->createdAt, updatedAt: $record->updatedAt,
        wrapPhrase: $record->wrapPhrase, revision: $revision ?? $record->revision,
        kdfOpslimit: $opslimit ?? $record->kdfOpslimit, kdfMemlimit: $memlimit ?? $record->kdfMemlimit,
        profileSeal: $record->profileSeal
    );
}

// -- Each breaks one clause ----------------------------------------------------

/** saveVault() as an upsert: a second insert replaces the vault. */
final class UpsertingStorage extends DelegatingStorage
{
    public function saveVault(VaultRecord $r): void
    {
        $live = $this->inner->findVault($r->userId);
        if ($live !== null) {
            $this->inner->deleteVault($live->generation());
        }
        $this->inner->saveVault($r);
    }
}

/** user_id compared as a MySQL _ci collation with PAD SPACE does: case and trailing spaces ignored. */
final class CaseInsensitiveStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    private function stored(string $u): string
    {
        $st = $this->pdo->prepare('SELECT user_id FROM selfdataguard_vaults WHERE user_id = ? COLLATE NOCASE');
        $st->execute([rtrim($u)]);
        $found = $st->fetchColumn();
        return $found === false ? $u : (string) $found;
    }

    public function loadVault(string $u): VaultRecord { return $this->inner->loadVault($this->stored($u)); }
    public function findVault(string $u): ?VaultRecord { return $this->inner->findVault($this->stored($u)); }
    public function vaultExists(string $u): bool { return $this->inner->vaultExists($this->stored($u)); }
}

/** Receives the generation and ignores it: every write goes to whatever vault is live. */
final class GenerationBlindStorage extends DelegatingStorage
{
    private function live(VaultGeneration $g): VaultGeneration
    {
        return $this->inner->findVault($g->userId)?->generation() ?? $g;
    }

    public function deleteVault(VaultGeneration $g): void { $this->inner->deleteVault($this->live($g)); }
    public function saveFields(VaultGeneration $g, array $f): void { $this->inner->saveFields($this->live($g), $f); }
    public function createEscrow(VaultGeneration $g, EscrowRecord $r): void { $this->inner->createEscrow($this->live($g), $r); }
    public function replaceEscrow(VaultGeneration $g, EscrowRecord $r): void { $this->inner->replaceEscrow($this->live($g), $r); }
    public function saveEscrowFields(VaultGeneration $g, array $f): void { $this->inner->saveEscrowFields($this->live($g), $f); }
    public function replaceWithArchive(VaultGeneration $g, VaultRecord $r, ?string $t = null): string { return $this->inner->replaceWithArchive($this->live($g), $r, $t); }
    public function deleteArchive(VaultGeneration $g, string $id): bool { return $this->inner->deleteArchive($this->live($g), $id); }
}

/** updateVault() without the revision: the last writer wins. */
final class LastWriterWinsStorage extends DelegatingStorage
{
    public function updateVault(VaultRecord $r): int
    {
        return $this->inner->updateVault(recordWith($r, revision: $this->inner->loadVault($r->userId)->revision));
    }
}

/**
 * Compares the revision, then writes in a second statement. Alone, it refuses
 * a stale record; under concurrency, every writer passes the check before any
 * of them writes.
 */
final class CheckThenWriteStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function updateVault(VaultRecord $r): int
    {
        $current = $this->inner->loadVault($r->userId);
        if (!hash_equals($current->userSalt, $r->userSalt) || $current->revision !== $r->revision) {
            throw new StaleVaultException('stale');
        }
        usleep(200_000);
        $this->pdo->prepare(
            'UPDATE selfdataguard_vaults SET wrap_pwd = ?, kdf_opslimit = ?, kdf_memlimit = ?, profile_seal = ?, revision = ?
             WHERE user_id = ?'
        )->execute([
            $r->wrapPwd->toBase64(), $r->kdfOpslimit, $r->kdfMemlimit,
            $r->profileSeal === null ? null : base64_encode($r->profileSeal), $r->revision + 1, $r->userId,
        ]);
        return $r->revision + 1;
    }
}

/**
 * Compares the generation of saveFields(), then writes in a second
 * transaction. Alone, it refuses a stale generation; racing a re-enrolment,
 * the field lands in the vault that replaced the one it was meant for.
 */
class GenerationCheckThenWriteStorage extends DelegatingStorage
{
    protected const PAUSE_US = 200_000;

    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function saveFields(VaultGeneration $g, array $f): void
    {
        $live = $this->inner->findVault($g->userId);
        if ($live === null) {
            throw new VaultNotFoundException('none');
        }
        if (!hash_equals($live->userSalt, $g->userSalt)) {
            throw new StaleVaultException('stale');
        }
        usleep(static::PAUSE_US);
        $st = $this->pdo->prepare(
            'INSERT INTO selfdataguard_fields (user_id, field_name, ciphertext, blind_index, shared_index, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(user_id, field_name) DO UPDATE SET ciphertext = excluded.ciphertext,
               blind_index = excluded.blind_index, shared_index = excluded.shared_index, updated_at = excluded.updated_at'
        );
        foreach ($f as $name => $d) {
            $st->execute([$g->userId, $name, $d['ciphertext'], $d['blindIndex'] ?? null, $d['sharedIndex'] ?? null, date('c')]);
        }
    }
}

/** The same, without a pause: the window an integrator's storage really leaves. */
final class GenerationCheckThenWriteNoPauseStorage extends GenerationCheckThenWriteStorage
{
    protected const PAUSE_US = 0;
}

/** createEscrow() as the upsert it was before 0.7.0: a second creation replaces the escrow, and its key. */
final class UpsertingEscrowStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function createEscrow(VaultGeneration $g, EscrowRecord $r): void
    {
        $live = $this->inner->findVault($g->userId);
        if ($live === null) {
            throw new VaultNotFoundException('none');
        }
        if (!hash_equals($live->userSalt, $g->userSalt)) {
            throw new StaleVaultException('stale');
        }
        $this->pdo->prepare(
            'INSERT INTO selfdataguard_escrow (user_id, wrap_user, wrap_admin, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(user_id) DO UPDATE SET wrap_user = excluded.wrap_user, wrap_admin = excluded.wrap_admin,
               updated_at = excluded.updated_at'
        )->execute([$r->userId, $r->wrapUser->toBase64(), base64_encode($r->wrapAdmin), $r->createdAt->format('c'), $r->updatedAt->format('c')]);
    }
}

/** claimSetting() as an upsert: the last value claimed replaces the first. */
final class SettingOverwritingStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function claimSetting(string $n, string $v): string
    {
        $this->pdo->prepare('INSERT INTO selfdataguard_settings (name, value) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value')
            ->execute([$n, $v]);
        return $v;
    }
}

/**
 * Reads the setting, then writes it in a second statement when it found none:
 * right alone, wrong under concurrency, where every claimer finds it free.
 */
final class CheckThenClaimStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function claimSetting(string $n, string $v): string
    {
        $st = $this->pdo->prepare('SELECT value FROM selfdataguard_settings WHERE name = ?');
        $st->execute([$n]);
        $held = $st->fetchColumn();
        $st->closeCursor();
        if ($held !== false) {
            return (string) $held;
        }
        usleep(200_000);
        $this->pdo->prepare('INSERT OR REPLACE INTO selfdataguard_settings (name, value) VALUES (?, ?)')->execute([$n, $v]);
        return $v;
    }
}

/** Finds no setting held, though one is: another writer recorded it between the read and the claim. */
final class SettingRecordedMeanwhileStorage extends DelegatingStorage
{
    public function loadSetting(string $n): ?string { return null; }
}

/** hasVaults() that counts live vaults only. */
final class LiveOnlyHasVaultsStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function hasVaults(): bool
    {
        return (bool) $this->pdo->query('SELECT EXISTS (SELECT 1 FROM selfdataguard_vaults)')->fetchColumn();
    }
}

/** indexedFields() that reads the blind indexes and forgets the shared ones. */
final class BlindOnlyIndexedFieldsStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function indexedFields(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT DISTINCT field_name FROM selfdataguard_fields WHERE blind_index IS NOT NULL') as $row) {
            $out[(string) $row['field_name']] = false;
        }
        return $out;
    }
}

/** deleteVault() that takes the archives with it. */
final class ArchiveErasingDeleteStorage extends DelegatingStorage
{
    public function deleteVault(VaultGeneration $g): void
    {
        $this->inner->deleteVault($g);
        $this->inner->eraseAccount($g->userId);
    }
}

/** eraseAccount() that deletes the live vault and leaves the archives. */
final class LiveOnlyEraseStorage extends DelegatingStorage
{
    public function eraseAccount(string $u): int
    {
        $live = $this->inner->findVault($u);
        if ($live === null) {
            return 0;
        }
        $this->inner->deleteVault($live->generation());
        return 1;
    }
}

/** Stores every vault under the current Argon2id constants instead of its own profile. */
final class ProfileFlatteningStorage extends DelegatingStorage
{
    public function saveVault(VaultRecord $r): void
    {
        $this->inner->saveVault(recordWith($r, opslimit: Primitives::ARGON2_OPSLIMIT, memlimit: Primitives::ARGON2_MEMLIMIT));
    }
}

/** Matches blind indexes case-insensitively — they are base64, where case is data. */
final class BlindIndexNoCaseStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function findUserIdsByIndex(string $f, string $i): array
    {
        $st = $this->pdo->prepare(
            'SELECT user_id FROM selfdataguard_fields WHERE field_name = ? AND blind_index = ? COLLATE NOCASE'
        );
        $st->execute([$f, $i]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
}

/** A storage without the unique index: a second account is given a value another holds. */
final class UniqueIndexDroppedStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, PDO $pdo)
    {
        parent::__construct($inner);
        $pdo->exec('DROP INDEX IF EXISTS selfdataguard_fields_blind_unique');
    }
}

/**
 * Looks for another holder, then writes: right alone, wrong under concurrency,
 * where every writer finds the value free before any of them takes it.
 */
final class CheckThenInsertIndexStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
        $pdo->exec('DROP INDEX IF EXISTS selfdataguard_fields_blind_unique');
    }

    public function saveFields(VaultGeneration $g, array $f): void
    {
        $taken = [];
        $st = $this->pdo->prepare(
            'SELECT 1 FROM selfdataguard_fields WHERE field_name = ? AND blind_index = ? AND user_id <> ?'
        );
        foreach ($f as $name => $data) {
            if (isset($data['blindIndex'])) {
                $st->execute([$name, $data['blindIndex'], $g->userId]);
                if ($st->fetchColumn() !== false) {
                    $taken[] = (string) $name;
                }
            }
        }
        if ($taken !== []) {
            throw new DuplicateIndexException($taken);
        }
        usleep(200_000);
        $this->inner->saveFields($g, $f);
    }
}

/** Writes every vault without its profile seal. */
final class SealDroppingStorage extends DelegatingStorage
{
    private function unsealed(VaultRecord $r): VaultRecord
    {
        return new VaultRecord(
            userId: $r->userId, userSalt: $r->userSalt, wrapPwd: $r->wrapPwd, wrapRecov: $r->wrapRecov,
            wrapAdmin: $r->wrapAdmin, createdAt: $r->createdAt, updatedAt: $r->updatedAt,
            wrapPhrase: $r->wrapPhrase, revision: $r->revision, kdfOpslimit: $r->kdfOpslimit, kdfMemlimit: $r->kdfMemlimit
        );
    }

    public function saveVault(VaultRecord $r): void { $this->inner->saveVault($this->unsealed($r)); }
    public function updateVault(VaultRecord $r): int { return $this->inner->updateVault($this->unsealed($r)); }
}

/** updateVault() that writes the wraps and leaves the profile as it was. */
final class ProfileFrozenStorage extends DelegatingStorage
{
    public function updateVault(VaultRecord $r): int
    {
        $stored = $this->inner->loadVault($r->userId);
        return $this->inner->updateVault(recordWith($r, opslimit: $stored->kdfOpslimit, memlimit: $stored->kdfMemlimit));
    }
}

/** Drops the shared index it is given: a shared field is never found. */
final class SharedIndexDroppingStorage extends DelegatingStorage
{
    public function saveFields(VaultGeneration $g, array $f): void
    {
        $this->inner->saveFields($g, array_map(static fn (array $d): array => array_diff_key($d, ['sharedIndex' => true]), $f));
    }
}

/** Writes fields into a frozen vault: lifts the freeze, writes, puts it back. */
final class FreezeSkippingFieldsStorage extends DelegatingStorage
{
    public function saveFields(VaultGeneration $g, array $f): void
    {
        $state = $this->inner->frozenState($g->userId);
        if ($state !== null) {
            $this->inner->unfreezeVault($g);
        }
        try {
            $this->inner->saveFields($g, $f);
        } finally {
            if ($state !== null) {
                $this->inner->freezeVault($g, $state['by'], $state['at']);
            }
        }
    }
}

/** updateVault() that ignores the freeze, the same way. */
final class FreezeSkippingUpdateStorage extends DelegatingStorage
{
    public function updateVault(VaultRecord $r): int
    {
        $state = $this->inner->frozenState($r->userId);
        if ($state !== null) {
            $this->inner->unfreezeVault($r->generation());
        }
        try {
            return $this->inner->updateVault($r);
        } finally {
            if ($state !== null) {
                $this->inner->freezeVault($r->generation(), $state['by'], $state['at']);
            }
        }
    }
}

/** A second freeze replaces the first: who froze the vault is the last caller. */
final class FreezeOverwritingStorage extends DelegatingStorage
{
    public function freezeVault(VaultGeneration $g, string $by, \DateTimeImmutable $at, ?string $t = null): bool
    {
        $this->inner->unfreezeVault($g);
        return $this->inner->freezeVault($g, $by, $at, $t);
    }
}

/** Brings an archive back live without its indexes. */
final class IndexDroppingRestoreStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    private function dropIndexes(string $u): void
    {
        $this->pdo->prepare('UPDATE selfdataguard_fields SET blind_index = NULL, shared_index = NULL WHERE user_id = ?')->execute([$u]);
    }

    public function swapWithArchive(VaultGeneration $g, string $id, ?string $t = null): string
    {
        $setAside = $this->inner->swapWithArchive($g, $id, $t);
        $this->dropIndexes($g->userId);
        return $setAside;
    }

    public function restoreArchive(string $u, string $id): void
    {
        $this->inner->restoreArchive($u, $id);
        $this->dropIndexes($u);
    }
}

/** restoreArchive() that deletes the live vault in its way. */
final class RestoreOverLiveStorage extends DelegatingStorage
{
    public function restoreArchive(string $u, string $id): void
    {
        $live = $this->inner->findVault($u);
        if ($live !== null) {
            $this->inner->deleteVault($live->generation());
        }
        $this->inner->restoreArchive($u, $id);
    }
}

/**
 * Compares the generation, then swaps whatever vault is live when its own write
 * starts: it reads the live generation again inside its transaction, so the
 * comparison the adapter makes there always holds. Alone, it refuses a stale
 * generation; racing, every swap passes the check, the first takes the archive,
 * and the others meet it gone instead of a stale generation.
 */
final class CheckThenSwapStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function swapWithArchive(VaultGeneration $g, string $id, ?string $t = null): string
    {
        $live = $this->inner->findVault($g->userId) ?? throw new VaultNotFoundException('none');
        if (!hash_equals($live->userSalt, $g->userSalt)) {
            throw new StaleVaultException('stale');
        }
        usleep(200_000);
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $setAside = $this->inner->swapWithArchive($this->inner->loadVault($g->userId)->generation(), $id, $t);
            $this->pdo->exec('COMMIT');
            return $setAside;
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }
}

/** Commits whatever transaction is open on its connection before it writes: the caller's half becomes durable. */
final class CallerCommittingStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function saveVault(VaultRecord $r): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
        $this->inner->saveVault($r);
    }
}

/** Takes the ticket and records nothing: a replay re-enrols, and the archive of a ticket is never found. */
final class TicketDroppingStorage extends DelegatingStorage
{
    public function replaceWithArchive(VaultGeneration $g, VaultRecord $r, ?string $t = null): string { return $this->inner->replaceWithArchive($g, $r); }
    public function freezeVault(VaultGeneration $g, string $by, \DateTimeImmutable $at, ?string $t = null): bool { return $this->inner->freezeVault($g, $by, $at); }
}

/** $record again, at the revision $revision. */
function escrowAt(EscrowRecord $record, int $revision): EscrowRecord
{
    return new EscrowRecord($record->userId, $record->wrapUser, $record->wrapAdmin, $record->createdAt, $record->updatedAt, $revision);
}

/** replaceEscrow() that writes over whatever revision is stored: the last writer wins. */
final class EscrowLastWriterWinsStorage extends DelegatingStorage
{
    public function replaceEscrow(VaultGeneration $g, EscrowRecord $r): void
    {
        $held = $this->inner->loadEscrow($r->userId);
        $this->inner->replaceEscrow($g, $held === null ? $r : escrowAt($r, $held->revision));
    }
}

/**
 * rotateEscrow() as two writes: the envelope, then the fields saved over the
 * old ones. A field the rotation does not name stays, under the replaced key.
 */
final class RotateKeepingFieldsStorage extends DelegatingStorage
{
    public function rotateEscrow(VaultGeneration $g, EscrowRecord $r, array $f): void
    {
        $this->inner->replaceEscrow($g, $r);
        $this->inner->saveEscrowFields($g, $f);
    }
}

/**
 * Compares the escrow revision, then rotates in a second statement. Alone, it
 * refuses a stale record; under concurrency, every rotation passes the check.
 */
final class RotateCheckThenWriteStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function rotateEscrow(VaultGeneration $g, EscrowRecord $r, array $f): void
    {
        $held = $this->inner->loadEscrow($r->userId)
            ?? throw new Pierroons\SelfDataGuard\Escrow\EscrowNotFoundException('none');
        if ($held->revision !== $r->revision) {
            throw new Pierroons\SelfDataGuard\Escrow\StaleEscrowException('stale');
        }
        usleep(200_000);
        $this->pdo->prepare('UPDATE selfdataguard_escrow SET wrap_user = ?, wrap_admin = ?, revision = ? WHERE user_id = ?')
            ->execute([$r->wrapUser->toBase64(), base64_encode($r->wrapAdmin), $r->revision + 1, $r->userId]);
        $this->pdo->prepare('DELETE FROM selfdataguard_escrow_fields WHERE user_id = ?')->execute([$r->userId]);
        $this->inner->saveEscrowFields($g, $f);
    }
}

/** replaceArchiveEscrow() that writes over whatever revision the archive holds. */
final class ArchiveEscrowRevisionBlindStorage extends DelegatingStorage
{
    public function replaceArchiveEscrow(string $u, string $id, EscrowRecord $r): void
    {
        $held = $this->inner->loadArchive($u, $id)?->escrow;
        $this->inner->replaceArchiveEscrow($u, $id, $held === null ? $r : escrowAt($r, $held->revision));
    }
}

/** An archive whose package keeps a list of escrow columns: the revision is lost on the way in. */
final class EscrowColumnsNamedStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function replaceWithArchive(VaultGeneration $g, VaultRecord $r, ?string $t = null): string
    {
        $id = $this->inner->replaceWithArchive($g, $r, $t);
        $st = $this->pdo->prepare('SELECT package FROM selfdataguard_archives WHERE archive_id = ?');
        $st->execute([$id]);
        $package = json_decode((string) $st->fetchColumn(), true);
        $st->closeCursor();
        if (is_array($package['escrow'] ?? null)) {
            unset($package['escrow']['revision']);
            $package['fields']['private'] = (object) $package['fields']['private'];
            $package['fields']['escrow']  = (object) $package['fields']['escrow'];
            $this->pdo->prepare('UPDATE selfdataguard_archives SET package = ? WHERE archive_id = ?')
                ->execute([json_encode($package, JSON_UNESCAPED_SLASHES), $id]);
        }
        return $id;
    }
}

/** replaceSetting() that writes whatever the database holds under the name. */
final class SettingReplacingBlindStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function replaceSetting(string $n, string $e, string $v): bool
    {
        $st = $this->pdo->prepare('UPDATE selfdataguard_settings SET value = ? WHERE name = ?');
        $st->execute([$v, $n]);
        return $st->rowCount() === 1;
    }
}

/**
 * Reads the setting, compares it, then writes in a second statement: right
 * alone, wrong under concurrency, where every replacer finds the old value.
 */
final class CheckThenReplaceSettingStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function replaceSetting(string $n, string $e, string $v): bool
    {
        if ($this->inner->loadSetting($n) !== $e) {
            return false;
        }
        usleep(200_000);
        $this->pdo->prepare('UPDATE selfdataguard_settings SET value = ? WHERE name = ?')->execute([$v, $n]);
        return true;
    }
}

/**
 * Counts the rows an UPDATE changed, not those it matched — MySQL without
 * MYSQL_ATTR_FOUND_ROWS: replacing a value with itself returns false.
 */
final class ChangedRowsSettingStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function replaceSetting(string $n, string $e, string $v): bool
    {
        $st = $this->pdo->prepare('UPDATE selfdataguard_settings SET value = ? WHERE name = ? AND value = ? AND value <> ?');
        $st->execute([$v, $n, $e, $v]);
        return $st->rowCount() === 1;
    }
}

/** Answers true for a value replaced with itself without comparing it to what the name holds. */
final class EqualValueShortcutStorage extends DelegatingStorage
{
    public function replaceSetting(string $n, string $e, string $v): bool
    {
        return $e === $v || $this->inner->replaceSetting($n, $e, $v);
    }
}

/** Raises the failure to parse a row as it came, not as UnreadableEscrowException: an adapter of 0.8.x. */
final class BareParseErrorStorage extends DelegatingStorage
{
    public function loadEscrow(string $u): ?EscrowRecord
    {
        try {
            return $this->inner->loadEscrow($u);
        } catch (UnreadableEscrowException $e) {
            throw $e->getPrevious() ?? $e;
        }
    }
}

/** Names only an unreadable wrap_admin; any other column comes out as it came. */
final class WrapAdminOnlyStorage extends DelegatingStorage
{
    public function loadEscrow(string $u): ?EscrowRecord
    {
        try {
            return $this->inner->loadEscrow($u);
        } catch (UnreadableEscrowException $e) {
            if (str_contains($e->getMessage(), 'wrap_admin')) {
                throw $e;
            }
            throw $e->getPrevious() ?? $e;
        }
    }
}

/** replaceEscrow() that reads the row again after a failed compare, as a storage telling a missing row from a stale one would: on a row that no longer parses, the read raises UnreadableEscrowException instead of StaleEscrowException. */
final class ParseInReplaceStorage extends DelegatingStorage
{
    public function replaceEscrow(VaultGeneration $generation, EscrowRecord $record): void
    {
        try {
            $this->inner->replaceEscrow($generation, $record);
        } catch (StaleEscrowException $e) {
            $this->inner->loadEscrow($record->userId);
            throw $e;
        }
    }
}

/** rotateEscrow() that reads the row again after a failed compare: on a row that no longer parses, the read raises UnreadableEscrowException instead of StaleEscrowException. */
final class ParseInRotateStorage extends DelegatingStorage
{
    public function rotateEscrow(VaultGeneration $generation, EscrowRecord $record, array $fields): void
    {
        try {
            $this->inner->rotateEscrow($generation, $record, $fields);
        } catch (StaleEscrowException $e) {
            $this->inner->loadEscrow($record->userId);
            throw $e;
        }
    }
}

/** loadArchive() of 0.9.0: an archive whose escrow does not parse is refused whole, as its escrow row would be. */
final class ArchiveEscrowParsingStorage extends DelegatingStorage
{
    public function loadArchive(string $u, string $id): ?ArchivedVault
    {
        $archive = $this->inner->loadArchive($u, $id);
        if ($archive?->escrowUnreadable) {
            throw new UnreadableEscrowException("The escrow row of '{$u}' does not parse");
        }
        return $archive;
    }
}

/** loadArchive() that reads an escrow that does not parse as no escrow at all. */
final class ArchiveEscrowDroppingStorage extends DelegatingStorage
{
    public function loadArchive(string $u, string $id): ?ArchivedVault
    {
        $a = $this->inner->loadArchive($u, $id);
        return $a === null ? null : new ArchivedVault(
            $a->archiveId, $a->archivedAt, $a->record, $a->kdfOpslimit, $a->kdfMemlimit, $a->escrow, $a->privateFields,
            $a->escrowFields, false, $a->blindIndexes, $a->sharedIndexes
        );
    }
}

/** Brings an archive back live, then deletes its escrow row when that row does not parse. */
final class UnreadableEscrowDroppingRestoreStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    private function dropUnreadable(string $u): void
    {
        try {
            $this->inner->loadEscrow($u);
        } catch (UnreadableEscrowException) {
            $this->pdo->prepare('DELETE FROM selfdataguard_escrow WHERE user_id = ?')->execute([$u]);
        }
    }

    public function swapWithArchive(VaultGeneration $g, string $id, ?string $t = null): string
    {
        $setAside = $this->inner->swapWithArchive($g, $id, $t);
        $this->dropUnreadable($g->userId);
        return $setAside;
    }

    public function restoreArchive(string $u, string $id): void
    {
        $this->inner->restoreArchive($u, $id);
        $this->dropUnreadable($u);
    }
}

/** loadArchive() that hands out no blind index: the façade cannot name a field that came back without one. */
final class BlindIndexesUnreadStorage extends DelegatingStorage
{
    public function loadArchive(string $u, string $id): ?ArchivedVault
    {
        $a = $this->inner->loadArchive($u, $id);
        return $a === null ? null : new ArchivedVault(
            $a->archiveId, $a->archivedAt, $a->record, $a->kdfOpslimit, $a->kdfMemlimit, $a->escrow, $a->privateFields,
            $a->escrowFields, $a->escrowUnreadable
        );
    }
}

/** eraseAccount() of 0.9.0: every archive and every ticket of the account go, the holder's archive and its level-3 ticket included. */
final class KeepNothingEraseStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function eraseAccount(string $u): int
    {
        $erased = $this->inner->eraseAccount($u);
        $st = $this->pdo->prepare('DELETE FROM selfdataguard_archives WHERE user_id = ?');
        $st->execute([$u]);
        $this->pdo->prepare('DELETE FROM selfdataguard_tickets WHERE user_id = ?')->execute([$u]);
        return $erased + $st->rowCount();
    }
}

/** eraseAccount() that keeps the holder's archive, and erases the level-3 ticket: a replay after the erasure goes through. */
final class Level3TicketErasingStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function eraseAccount(string $u): int
    {
        $erased = $this->inner->eraseAccount($u);
        $this->pdo->prepare('DELETE FROM selfdataguard_tickets WHERE user_id = ?')->execute([$u]);
        return $erased;
    }
}

/** saveFields() of 0.9.0: an index an archive of another account keeps is given to whoever writes it. */
final class ReservationIgnoringStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function saveFields(VaultGeneration $g, array $f): void
    {
        try {
            $this->inner->saveFields($g, $f);
        } catch (DuplicateIndexException $e) {
            foreach ($f as $name => $data) {
                if (in_array($name, $e->fields, true) && $this->inner->findUserIdsByIndex((string) $name, (string) $data['blindIndex']) !== []) {
                    throw $e;
                }
            }
            $upsert = $this->pdo->prepare(
                'INSERT INTO selfdataguard_fields (user_id, field_name, ciphertext, blind_index, updated_at, shared_index) VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT(user_id, field_name) DO UPDATE SET ciphertext = excluded.ciphertext, blind_index = excluded.blind_index,
                 updated_at = excluded.updated_at, shared_index = excluded.shared_index'
            );
            foreach ($f as $name => $data) {
                $upsert->execute([$g->userId, $name, $data['ciphertext'], $data['blindIndex'] ?? null, gmdate('c'), $data['sharedIndex'] ?? null]);
            }
        }
    }
}

/** eraseAccount() that keeps only the archives a level-3 ticket names: the one a swap set aside goes, the holder's vault after a second exchange with it. */
final class NamedArchiveOnlyKeepingStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function eraseAccount(string $u): int
    {
        $erased = $this->inner->eraseAccount($u);
        $st = $this->pdo->prepare(
            "DELETE FROM selfdataguard_archives WHERE user_id = ? AND archive_id NOT IN (
                 SELECT archive_id FROM selfdataguard_tickets WHERE operation = 'replace-with-archive' AND archive_id IS NOT NULL)"
        );
        $st->execute([$u]);
        return $erased + $st->rowCount();
    }
}

/** eraseAccount() that does not look at the freeze: a frozen account is erased, the vault the arbiter has not looked at with it. */
final class FrozenErasingStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function eraseAccount(string $u): int
    {
        $this->pdo->prepare('UPDATE selfdataguard_vaults SET frozen_by = NULL, frozen_at = NULL WHERE user_id = ?')->execute([$u]);
        return $this->inner->eraseAccount($u);
    }
}

/** eraseAccount() that refuses a frozen account, after erasing its archives: the refusal is not held in one transaction. */
final class FrozenArchiveErasingStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function eraseAccount(string $u): int
    {
        if ($this->inner->frozenState($u) !== null) {
            $this->pdo->prepare('DELETE FROM selfdataguard_archives WHERE user_id = ?')->execute([$u]);
        }
        return $this->inner->eraseAccount($u);
    }
}

/** eraseAccount() that erases every ticket of the account but the level-3 ones: the freeze and the swap are replayed after it. */
final class OtherTicketsErasingStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    public function eraseAccount(string $u): int
    {
        $this->pdo->prepare("DELETE FROM selfdataguard_tickets WHERE user_id = ? AND operation <> 'replace-with-archive'")->execute([$u]);
        return $this->inner->eraseAccount($u);
    }
}

/** Leaves the tickets naming the userId and its archive once the account holds nothing: whoever registers the name next inherits them. */
final class TicketsNotUntiedStorage extends DelegatingStorage
{
    public function __construct(StorageInterface $inner, private readonly PDO $pdo)
    {
        parent::__construct($inner);
    }

    /** @return list<array{ticket: string, operation: string, archive_id: ?string}> */
    private function ticketsOf(string $u): array
    {
        $st = $this->pdo->prepare('SELECT ticket, operation, archive_id FROM selfdataguard_tickets WHERE user_id = ?');
        $st->execute([$u]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function tieAgain(string $u, array $tickets): void
    {
        $st = $this->pdo->prepare('UPDATE selfdataguard_tickets SET user_id = ?, archive_id = ? WHERE ticket = ? AND operation = ?');
        foreach ($tickets as $t) {
            $st->execute([$u, $t['archive_id'], $t['ticket'], $t['operation']]);
        }
    }

    public function eraseAccount(string $u): int
    {
        $tickets = $this->ticketsOf($u);
        $erased = $this->inner->eraseAccount($u);
        $this->tieAgain($u, $tickets);
        return $erased;
    }
}

// The doubles below implement ErasesArchives: declared only where it exists,
// so that this file loads on a version without it and its benches report what
// that version lacks instead of dying.
if (interface_exists(ErasesArchives::class)) {
    /** eraseArchive() that takes the level-3 ticket with the archive: a replay after it goes through. */
    final class TicketTakingEraseArchiveStorage extends DelegatingStorage implements ErasesArchives
    {
        public function __construct(StorageInterface $inner, private readonly PDO $pdo)
        {
            parent::__construct($inner);
        }

        public function eraseArchive(string $u, string $id): bool
        {
            $this->pdo->prepare('DELETE FROM selfdataguard_tickets WHERE user_id = ? AND archive_id = ?')->execute([$u, $id]);
            return $this->inner instanceof ErasesArchives && $this->inner->eraseArchive($u, $id);
        }

        public function keptArchives(?string $u = null): array
        {
            return $this->inner instanceof ErasesArchives ? $this->inner->keptArchives($u) : [];
        }
    }

    /** eraseArchive() that does not look at the freeze: an archive of a frozen account is erased during the case. */
    final class FrozenArchiveDeletingStorage extends DelegatingStorage implements ErasesArchives
    {
        public function __construct(StorageInterface $inner, private readonly PDO $pdo)
        {
            parent::__construct($inner);
        }

        public function eraseArchive(string $u, string $id): bool
        {
            if ($this->inner->frozenState($u) === null) {
                return $this->inner instanceof ErasesArchives && $this->inner->eraseArchive($u, $id);
            }
            $st = $this->pdo->prepare('DELETE FROM selfdataguard_archives WHERE user_id = ? AND archive_id = ?');
            $st->execute([$u, $id]);
            return $st->rowCount() > 0;
        }

        public function keptArchives(?string $u = null): array
        {
            return $this->inner instanceof ErasesArchives ? $this->inner->keptArchives($u) : [];
        }
    }

    /** keptArchives() that lists every archive, those of an account that holds a live vault included. */
    final class LiveListingKeptStorage extends DelegatingStorage implements ErasesArchives
    {
        public function __construct(StorageInterface $inner, private readonly PDO $pdo)
        {
            parent::__construct($inner);
        }

        public function eraseArchive(string $u, string $id): bool
        {
            return $this->inner instanceof ErasesArchives && $this->inner->eraseArchive($u, $id);
        }

        public function keptArchives(?string $u = null): array
        {
            $st = $this->pdo->prepare('SELECT user_id, archive_id, archived_at FROM selfdataguard_archives WHERE (? IS NULL OR user_id = ?) ORDER BY archived_at, rowid');
            $st->execute([$u, $u]);
            return array_map(
                static fn (array $r): array => ['userId' => $r['user_id'], 'id' => $r['archive_id'], 'archivedAt' => new DateTimeImmutable($r['archived_at'])],
                $st->fetchAll(PDO::FETCH_ASSOC)
            );
        }
    }
}

/** accountIds() of the live vaults only: an account that holds nothing but an archive is never walked. */
final class LiveOnlyAccountIdsStorage extends DelegatingStorage
{
    public function accountIds(string $a = '', int $l = 500): array
    {
        return array_values(array_filter($this->inner->accountIds($a, $l), fn (string $u): bool => $this->inner->vaultExists($u)));
    }
}

/** accountIds() in an order that folds case: "a" comes before "B". */
final class CaseFoldedAccountIdsStorage extends DelegatingStorage
{
    public function accountIds(string $a = '', int $l = 500): array
    {
        $all = array_values(array_filter($this->inner->accountIds('', PHP_INT_MAX), static fn (string $u): bool => strcasecmp($u, $a) > 0));
        usort($all, 'strcasecmp');
        return array_slice($all, 0, $l);
    }
}

/** replaceArchiveVault() over whatever revision the archive holds: a replacement from a stale read wins. */
final class ArchiveVaultRevisionBlindStorage extends DelegatingStorage
{
    public function replaceArchiveVault(string $u, string $id, VaultRecord $r): int
    {
        $held = $this->inner->loadArchive($u, $id)?->record;
        return $this->inner->replaceArchiveVault($u, $id, $held === null ? $r : recordWith($r, revision: $held->revision));
    }
}

/** updateFrozenLocks() as a whole update under a lifted freeze: the profile is written too. */
final class FrozenProfileWritingStorage extends DelegatingStorage
{
    public function updateFrozenLocks(VaultRecord $r): int
    {
        $state = $this->inner->frozenState($r->userId);
        if ($state === null) {
            return $this->inner->updateFrozenLocks($r);
        }
        $this->inner->unfreezeVault($r->generation());
        try {
            return $this->inner->updateVault($r);
        } finally {
            $this->inner->freezeVault($r->generation(), $state['by'], $state['at']);
        }
    }
}

/** updateFrozenLocks() of a vault that is not frozen writes it as updateVault() would. */
final class FrozenLocksOnLiveStorage extends DelegatingStorage
{
    public function updateFrozenLocks(VaultRecord $r): int
    {
        return $this->inner->frozenState($r->userId) === null ? $this->inner->updateVault($r) : $this->inner->updateFrozenLocks($r);
    }
}
