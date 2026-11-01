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
 * A keeper never refuses for good, and counts no failure: a counter here would
 * close the holder's last door to whoever runs the ceremony.
 */
interface EscrowKeeper
{
    /**
     * Its public keys, raw: `sign` (Ed25519) verifies its attestations, `box`
     * (X25519) is what outer boxes are sealed to.
     *
     * @return array{sign: string, box: string}
     */
    public function publicKeys(): array;

    /**
     * Record a request to read escrow $escrowId for case $caseRef, made at the
     * audit log's head $seq:$hmac. It opens $outerBox to read what it holds,
     * and refuses (throws) when the box names another escrowId or another
     * account than $userId. Returns the time (Unix) from which release() hands
     * the inner box: now when no delay applies — an archive read, or a delay of
     * zero. A second announce of the same escrow and case returns the same
     * time.
     */
    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, bool $archive, int $seq, string $hmac): int;

    /**
     * The inner box of $outerBox, once the announcement of $escrowId and
     * $caseRef is due and nobody objected; the release is recorded before the
     * box is handed back. Throws on a release never announced, objected,
     * early, or at a $seq that is not above the last one it took.
     */
    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string;

    /** KeeperSeal::attestation() of what it released and holds announced for $escrowId, for $nonce. */
    public function attest(string $escrowId, string $nonce): string;

    /**
     * Cancel the announcement of $escrowId for $caseRef, when $signature
     * verifies under the objection key its outer box carried
     * (KeeperSeal::verifyObjection()). Throws when it does not.
     */
    public function object(string $escrowId, string $caseRef, string $signature): void;
}
