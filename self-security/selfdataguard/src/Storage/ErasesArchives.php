<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Storage;

/**
 * A storage that lists and erases, with no session and no live vault, the
 * archives StorageInterface::eraseAccount() kept: the operator's act, once the
 * case they were kept for is closed.
 *
 * Optional. StorageInterface does not require it, and
 * SelfDataGuard::eraseArchive() and keptArchives() refuse on a storage that
 * does not implement it — an archive eraseAccount() kept then stays.
 * Conformance plays it on a storage that implements it.
 */
interface ErasesArchives
{
    /**
     * Delete archive $archiveId of $userId, whatever vault the userId holds
     * live — but a frozen one: then nothing is deleted and it throws
     * VaultFrozenException, compared inside the deletion's own transaction,
     * as eraseAccount() does. The tickets applied to the userId stay recorded,
     * so a replay of one is still refused; once the userId holds no live vault
     * and no archive, they no longer name it nor the archive, as
     * eraseAccount() leaves them.
     *
     * @return bool false if it does not exist or belongs to another userId
     * @throws \Pierroons\SelfDataGuard\Vault\VaultFrozenException if the live vault of the userId is frozen
     */
    public function eraseArchive(string $userId, string $archiveId): bool;

    /**
     * Every archive whose userId holds no live vault — those eraseAccount()
     * kept, and those a deleted vault left —, oldest first; those of $userId
     * only when it is given. Read without opening the archives: one whose
     * package no longer reads is listed too. Never writes.
     *
     * @return list<array{userId: string, id: string, archivedAt: \DateTimeImmutable}>
     */
    public function keptArchives(?string $userId = null): array;
}
