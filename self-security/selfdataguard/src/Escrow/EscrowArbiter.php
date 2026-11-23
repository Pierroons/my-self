<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

/**
 * Says whether a case authorizes reading an account's escrow — its live one
 * ($archiveId null), or that of one archive.
 *
 * SelfDataGuard ships none: an arbiter written for every deployment would answer
 * "yes" to all of them, and the one a deployment needs reads its own cases.
 * SelfRecover ships none either: an arbiter can read whether its window after a
 * level 3 lets the escrow be read (`Escalade::fenetre()`, `lecture_admin_permise`).
 * The ceremony writes the arbiter's class and its verdict to the log, so that one
 * that always says Open shows there.
 *
 * $fieldNames are the escrow fields the act reads or compares, as the
 * ceremony was given them — never empty for a read or a comparison, empty
 * for a listing of archives, which reads no field. A case that authorizes
 * one field does not authorize them all: the arbiter is asked about these.
 */
interface EscrowArbiter
{
    /** @param list<string> $fieldNames */
    public function verdict(string $userId, string $caseRef, ?string $archiveId, array $fieldNames): Verdict;
}
