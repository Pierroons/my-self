<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Storage;

/**
 * A storage that tells the operator who holds an indexed value it does not
 * answer for by a lookup: the archives that still reserve their blind
 * indexes, and the fields an archive brought back live without theirs.
 *
 * An archive reserves the blind indexes it keeps — saveFields() refuses them
 * to every other userId — while it can still come back: for
 * RecoveryTickets::RETURN_WINDOW after it was made, or while the live vault of
 * its userId is frozen. Past that, a field whose blind index another account
 * took since comes back without it, and the storage keeps a trace of it: a
 * value indexed by both accounts once the reservation ended, or before 0.9.1.
 * The trace stays with the live field until the field is written again with
 * an index, blind or shared, and goes with the account.
 *
 * Optional. StorageInterface does not require it, and
 * SelfDataGuard::lostIndexes(), allLostIndexes() and reservedIndexes() refuse
 * on a storage that does not implement it.
 */
interface TracesLostIndexes
{
    /**
     * The live fields that carry the trace, by userId; those of $userId only
     * when it is given. Never writes.
     *
     * @return array<string, list<string>> userId => field names, sorted
     */
    public function lostIndexes(?string $userId = null): array;

    /**
     * The reservations in force: for each archive that reserves its blind
     * indexes, each field it reserves, and until when — `until` is
     * RETURN_WINDOW after the archive was made; `frozen` true when the
     * reservation holds past it, the live vault of the userId being frozen.
     * No index and no value: names and dates only. Never writes.
     *
     * @return list<array{userId: string, archiveId: string, field: string, until: \DateTimeImmutable, frozen: bool}>
     */
    public function reservedIndexes(): array;
}
