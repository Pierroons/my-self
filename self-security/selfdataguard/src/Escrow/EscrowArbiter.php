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
 */
interface EscrowArbiter
{
    public function verdict(string $userId, string $caseRef, ?string $archiveId): Verdict;
}
