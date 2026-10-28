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
use Pierroons\SelfDataGuard\Storage\DuplicateIndexException;
use Pierroons\SelfDataGuard\Storage\StorageInterface;
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
    public function loadEscrow(string $u): ?EscrowRecord { return $this->inner->loadEscrow($u); }
    public function saveEscrowFields(VaultGeneration $g, array $f): void { $this->inner->saveEscrowFields($g, $f); }
    public function loadEscrowFields(string $u, array $n = []): array { return $this->inner->loadEscrowFields($u, $n); }
    public function replaceWithArchive(VaultGeneration $g, VaultRecord $r): string { return $this->inner->replaceWithArchive($g, $r); }
    public function listArchives(string $u): array { return $this->inner->listArchives($u); }
    public function loadArchive(string $u, string $id): ?ArchivedVault { return $this->inner->loadArchive($u, $id); }
    public function deleteArchive(VaultGeneration $g, string $id): bool { return $this->inner->deleteArchive($g, $id); }
    public function swapWithArchive(VaultGeneration $g, string $id): string { return $this->inner->swapWithArchive($g, $id); }
    public function restoreArchive(string $u, string $id): void { $this->inner->restoreArchive($u, $id); }
    public function freezeVault(VaultGeneration $g, string $by, \DateTimeImmutable $at): bool { return $this->inner->freezeVault($g, $by, $at); }
    public function unfreezeVault(VaultGeneration $g): bool { return $this->inner->unfreezeVault($g); }
    public function frozenState(string $u): ?array { return $this->inner->frozenState($u); }
    public function eraseAccount(string $u): int { return $this->inner->eraseAccount($u); }
    public function claimSetting(string $n, string $v): string { return $this->inner->claimSetting($n, $v); }
    public function loadSetting(string $n): ?string { return $this->inner->loadSetting($n); }
    public function hasVaults(): bool { return $this->inner->hasVaults(); }
    public function indexedFields(): array { return $this->inner->indexedFields(); }
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
    public function replaceWithArchive(VaultGeneration $g, VaultRecord $r): string { return $this->inner->replaceWithArchive($this->live($g), $r); }
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
    public function freezeVault(VaultGeneration $g, string $by, \DateTimeImmutable $at): bool
    {
        $this->inner->unfreezeVault($g);
        return $this->inner->freezeVault($g, $by, $at);
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

    public function swapWithArchive(VaultGeneration $g, string $id): string
    {
        $setAside = $this->inner->swapWithArchive($g, $id);
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
 * Compares the generation, then swaps whatever vault is live. Alone, it refuses
 * a stale generation; racing, every swap passes the check and the archive is
 * gone for all but one — refused as missing instead of stale.
 */
final class CheckThenSwapStorage extends DelegatingStorage
{
    public function swapWithArchive(VaultGeneration $g, string $id): string
    {
        $live = $this->inner->findVault($g->userId) ?? throw new VaultNotFoundException('none');
        if (!hash_equals($live->userSalt, $g->userSalt)) {
            throw new StaleVaultException('stale');
        }
        usleep(200_000);
        return $this->inner->swapWithArchive($this->inner->loadVault($g->userId)->generation(), $id);
    }
}
