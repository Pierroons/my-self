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
 * under it. An objection is therefore checked against the escrowId alone, and
 * holds its case whatever box comes. An announcement holds its case and one
 * box (KeeperSeal::boxPrint()): a box sealed by a deposit since, the box an
 * archive keeps, or one put back from a copy of the database is announced on
 * its own, waits a delay of its own, and shows to the holder as a read of its
 * own.
 *
 * $caseRef is shown to the holder, and the keeper attests it to whoever names
 * the escrowId, which stands in clear in the database: a case reference is
 * never a secret, never the capability that opens a case.
 *
 * Each call is atomic against the others: a release is recorded before the
 * box is handed back, an objection or a retirement before the call returns,
 * and two calls at once never lose one another's record.
 *
 * A keeper counts no failure and shuts nothing out after one: a counter here
 * would close the holder's last door to whoever runs the ceremony. The
 * refusals that last are the holder's own: an objection, a retirement.
 *
 * A consent is the holder's too: signed from a session under the same key, it
 * says the holder agrees to the read of a case. The keeper records and attests
 * it, and releases nothing more for it: what asks for a consent is the
 * arbiter (Verdict::OpenWithConsent), and the ceremony reads it in the
 * keeper's attestation, signed off the machine that serves.
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
     * Returns the time (Unix) from which release() hands this box back: now
     * plus the keeper's delay, for every read alike — an archive's box and a
     * live one look the same to the keeper. The announcement holds the case
     * and the box: announced again with the same box, it returns the same
     * time, until it has lapsed — LAPSE after that time —; the next announce
     * then starts the delay again. Another box of the escrow, for the same
     * case, is another announcement, with its own time, and leaves the time of
     * the first as it was: whoever holds one more box neither puts a read off
     * nor reads it without a delay.
     */
    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, int $seq, string $hmac): int;

    /**
     * The inner box of $outerBox, once the announcement of that box for
     * $escrowId and $caseRef is due, has not lapsed, nobody objected to the
     * case, and the escrow is not retired here. Each release is recorded, with
     * the log head $seq:$hmac and the box's fingerprint, before the box is
     * handed back, and does not end the announcement: until it lapses, the
     * box can be released again, each time recorded. The head is recorded, not
     * judged: the head witness keeps the log, and a number the caller states
     * would only let whoever reaches the keeper close it. Throws on a release
     * of a box never announced for that case, objected to, early, lapsed, or
     * of a box announce() refuses.
     */
    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string;

    /**
     * KeeperSeal::attestation() of what it released for $escrowId, of what it
     * holds announced — lapsed announcements left out, objected ones kept —,
     * each naming its box by KeeperSeal::boxPrint(), of the consents it took,
     * and whether $escrowId was retired here, for $nonce.
     */
    public function attest(string $escrowId, string $nonce): string;

    /**
     * Cancel every announcement of $escrowId for $caseRef, for good, when
     * $token verifies under the key $escrowId is the fingerprint of
     * (KeeperSeal::verifyObjection()) — whatever box was announced or comes
     * after, before the read is due or after it was released. Throws when it
     * does not, or when no box is announced for that case.
     */
    public function object(string $escrowId, string $caseRef, string $token): void;

    /**
     * Retire $escrowId from this keeper, when $token verifies under the key
     * $escrowId is the fingerprint of, for this keeper's own fingerprint
     * (KeeperSeal::verifyRetirement()) — a token made for another keeper is
     * refused, so that no keeper can pass one on. Every later announce() and
     * release() of it is refused; its attestation stays — the releases and
     * announcements made before — and says it is retired. A retirement taken
     * again is taken, and so is one for an escrowId this keeper never saw. What
     * a holder's façade sends, at each deposit, every keeper the deployment
     * replaced, before it writes.
     * Throws when the token does not verify.
     */
    public function retire(string $escrowId, string $token): void;

    /**
     * Record the holder's consent to the read of $escrowId for $caseRef, when
     * $token verifies under the key $escrowId is the fingerprint of, for this
     * keeper's own fingerprint (KeeperSeal::verifyConsent()) — a consent made
     * for another keeper is refused. It is taken whether or not a box is
     * announced for that case yet, at the time of its first taking, and taken
     * again changes nothing. It lifts no objection: a case objected to stays
     * refused, consented to or not. Throws when the token does not verify.
     */
    public function consent(string $escrowId, string $caseRef, string $token): void;
}
