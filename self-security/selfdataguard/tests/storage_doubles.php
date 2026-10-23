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
    public function saveEscrow(VaultGeneration $g, EscrowRecord $r): void { $this->inner->saveEscrow($g, $r); }
    public function loadEscrow(string $u): ?EscrowRecord { return $this->inner->loadEscrow($u); }
    public function saveEscrowFields(VaultGeneration $g, array $f): void { $this->inner->saveEscrowFields($g, $f); }
    public function loadEscrowFields(string $u, array $n = []): array { return $this->inner->loadEscrowFields($u, $n); }
    public function replaceWithArchive(VaultGeneration $g, VaultRecord $r): string { return $this->inner->replaceWithArchive($g, $r); }
    public function listArchives(string $u): array { return $this->inner->listArchives($u); }
    public function loadArchive(string $u, string $id): ?ArchivedVault { return $this->inner->loadArchive($u, $id); }
    public function deleteArchive(VaultGeneration $g, string $id): bool { return $this->inner->deleteArchive($g, $id); }
    public function eraseAccount(string $u): int { return $this->inner->eraseAccount($u); }
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
    public function saveEscrow(VaultGeneration $g, EscrowRecord $r): void { $this->inner->saveEscrow($this->live($g), $r); }
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
