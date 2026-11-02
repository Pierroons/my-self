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
 * Anyone can seal a box to the keeper's public key. What a box cannot fake is
 * the escrow's objection key: the escrowId of an escrow its holder sealed is the
 * fingerprint of that key (KeeperSeal::escrowIdFor()), and only the holder signs
 * under it. A read is therefore held by its case, never by a box, and an
 * objection is checked against the escrowId alone.
 *
 * $caseRef is shown to the holder, and the keeper attests it to whoever names
 * the escrowId, which stands in clear in the database: a case reference is
 * never a secret, never the capability that opens a case.
 *
 * A keeper counts no failure and shuts nothing out after one: a counter here
 * would close the holder's last door to whoever runs the ceremony. The
 * refusals that last are the holder's own: an objection, a retirement.
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
     * audit log's head $seq:$hmac. It opens $outerBox and refuses (throws) a box
     * that names another escrowId or another account than $userId, one whose
     * objection key is not the one $escrowId is the fingerprint of
     * (KeeperSeal::boxFits()), one without an objection key for an escrowId it
     * already saw a box with one for, and any box of an escrow retired here.
     *
     * Returns the time (Unix) from which release() hands the inner box: now
     * plus the keeper's delay, for every read alike — an archive's box and a
     * live one look the same to the keeper. The announcement holds the case:
     * announced again, with this box or another of the escrow, it returns the
     * same time, until it has lapsed — LAPSE after that time —; the next
     * announce then starts the delay again.
     */
    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, int $seq, string $hmac): int;

    /**
     * The inner box of $outerBox — any box of the escrow that announce() would
     * take — once the announcement of $escrowId and $caseRef is due, has not
     * lapsed, nobody objected to it, and the escrow is not retired here. Each
     * release is recorded, with the log head $seq:$hmac, before the box is handed
     * back, and does not end the announcement: until it lapses, the read can be
     * released again, each time recorded. The head is recorded, not judged: the
     * head witness keeps the log, and a number the caller states would only let
     * whoever reaches the keeper close it. Throws on a release never announced,
     * objected to, early, lapsed, or of a box announce() refuses.
     */
    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string;

    /**
     * KeeperSeal::attestation() of what it released for $escrowId, and of what
     * it holds announced — lapsed announcements left out, objected ones kept —,
     * for $nonce.
     */
    public function attest(string $escrowId, string $nonce): string;

    /**
     * Cancel the announcement of $escrowId for $caseRef, for good, when $token
     * verifies under the key $escrowId is the fingerprint of
     * (KeeperSeal::verifyObjection()) — whatever box was announced or comes
     * after. Throws when it does not, or when nothing is announced for that
     * case.
     */
    public function object(string $escrowId, string $caseRef, string $token): void;

    /**
     * Retire $escrowId from this keeper, when $token verifies under the key
     * $escrowId is the fingerprint of (KeeperSeal::verifyRetirement()): every
     * later announce() and release() of it is refused, and its attestation
     * stays. What a holder's façade sends a keeper the deployment replaced,
     * before it re-seals the escrow to the new one: if the re-sealing then
     * fails, the escrow stays sealed to a keeper that no longer serves it until
     * the holder's next deposit. Throws when the token does not verify.
     */
    public function retire(string $escrowId, string $token): void;
}
