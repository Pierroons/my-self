<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

/**
 * The second holder of an escrow, without whom the administrator opens no
 * compartment sealed to it: it holds the key of the outer box (KeeperSeal),
 * hands back the inner one after recording the release, and signs for the
 * holder what it released. Its store lives off the machine that serves, append
 * only, and is never restored from a backup — the model of HeadWitness.
 *
 * SelfDataGuard ships none: what makes a keeper worth trusting is where it
 * runs and who answers for it, which no library decides. KeeperSeal gives an
 * implementation the formats; bin/keeper-conformance.php plays its contract.
 * NoKeeper says by name that there is none.
 *
 * The machine that serves calls it, and the administrator runs that machine:
 * whoever reaches the keeper with what the database holds can announce and,
 * once due, be handed a box. The delay and the objection bind every read, and
 * what the keeper releases, the holder sees; the administrator's key still
 * opens the inner box it was handed.
 *
 * $caseRef is shown to the holder, and the keeper attests it to whoever names
 * the escrowId, which stands in clear in the database: a case reference is
 * never a secret, never the capability that opens a case.
 *
 * A keeper counts no failure and shuts nothing out after one: a counter here
 * would close the holder's last door to whoever runs the ceremony. The one
 * refusal that lasts is the holder's own objection.
 */
interface EscrowKeeper
{
    /** How long an announcement stays releasable once due, in seconds. */
    public const LAPSE = 604800;

    /**
     * Its public keys, raw: `sign` (Ed25519) verifies its attestations, `box`
     * (X25519) is what outer boxes are sealed to. They answer without reaching
     * the keeper's store or service: a façade asks for them as it opens, and a
     * keeper that is down must not close the vaults.
     *
     * @return array{sign: string, box: string}
     */
    public function publicKeys(): array;

    /**
     * Record a request to read escrow $escrowId for case $caseRef, made at the
     * audit log's head $seq:$hmac. It opens $outerBox to read what it holds,
     * and refuses (throws) when the box names another escrowId or another
     * account than $userId. Returns the time (Unix) from which release() hands
     * the inner box: now plus the keeper's delay, for every read alike — an
     * archive's box and a live one look the same to the keeper.
     *
     * The announcement is of this box. A second announce of the same escrow
     * and case with the same box returns the same time; with another — a
     * deposit since, which drew a new key — it starts the delay again and takes
     * the objection key the new box carries. It stays releasable for LAPSE
     * from its time, and no less; one not released by then has lapsed: the
     * next announce starts the delay again. An
     * objection holds for its case with no time limit: no announcement clears it.
     */
    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, int $seq, string $hmac): int;

    /**
     * The inner box of $outerBox, once the announcement of $escrowId and
     * $caseRef made with this same box is due, has not lapsed, and nobody
     * objected to; the release is recorded, with the log head $seq:$hmac, before
     * the box is handed back. The head is recorded, not judged: the head
     * witness keeps the log, and a number the caller states would only let
     * whoever reaches the keeper close it. Throws on a release never announced,
     * announced with another box, objected to, early, or lapsed.
     */
    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string;

    /**
     * KeeperSeal::attestation() of what it released for $escrowId, and of what
     * it holds announced — lapsed announcements left out, objected ones kept —,
     * for $nonce.
     */
    public function attest(string $escrowId, string $nonce): string;

    /**
     * Cancel the announcement of $escrowId for $caseRef, when $signature
     * verifies under the objection key of the box it was last announced with
     * (KeeperSeal::verifyObjection()). Throws when it does not, or when nothing
     * is announced for that case.
     */
    public function object(string $escrowId, string $caseRef, string $signature): void;
}
