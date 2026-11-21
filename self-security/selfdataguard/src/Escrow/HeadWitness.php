<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

/**
 * Takes the head of the audit log out of this machine — called after the
 * ceremony has written its intention, before anything is unsealed. It throws
 * when the head did not get there, and the ceremony stops: whoever could cut
 * the network, read, then rewrite the log, finds the intention already outside.
 *
 * Three duties no signature here can carry. WitnessConformance plays them
 * (`bin/witness-conformance.php`):
 * 1. Its store takes a head only from whoever holds this instance's key. The
 *    key, and how the store checks it, belong to the implementation: witness()
 *    takes no signature. A store that takes a head from anyone who can write
 *    to it lets one forged head stop every ceremony — the log does not hold
 *    the head lastHead() returns (`log-behind-witness`), and seedWitness()
 *    leaves alone a witness that holds a head.
 * 2. Nothing resets its store: no call, no file, no endpoint the serving
 *    machine can reach.
 * 3. lastHead() never returns less than the highest head it took, whatever
 *    was presented to its store since, an older head of its own included.
 *
 * When a witness returns a head the log does not hold, nothing here tells a
 * forged head from a cut log, and nothing here reopens the ceremony. The
 * operator checks the log against the anchor written down off the machine
 * (`bin/escrow-ceremony.php verify-log --ancre`), gives the ceremony a witness
 * on a new store, and seeds it on that anchor (`seed-witness`).
 */
interface HeadWitness
{
    public function witness(int $seq, string $hmac): void;

    /**
     * The last head this witness took, as "seq:hmac", or null when it never
     * took one. The ceremony verifies the log against it before writing
     * anything: a log cut or rewritten since the last ceremony stops the next
     * one, instead of being chained on and handed back to the witness as a lower
     * head. It throws when the head cannot be read back, and the ceremony stops.
     *
     * Once it has taken a head, it never answers null — the ceremony refuses one
     * that does, when the log shows a witness took one — and witness() refuses
     * a seq that is not above the last it took. Its store lives off this
     * machine, append-only, and is never restored from a backup: put back to an
     * older head, it vouches for a log cut back to that head, and nothing here
     * can tell. A witness that holds no head on a log a witness already served
     * — a new one, or one that lost its store — is seeded by the operator with
     * EscrowCeremony::seedWitness().
     */
    public function lastHead(): ?string;
}
