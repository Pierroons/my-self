<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The EscrowKeeper contract, played against an implementation.
 *
 * A keeper that hands back the box without recording it, before the delay
 * ends, once its holder objected, under the announcement of another escrow,
 * or to a box that carries another objection key than the escrow is named
 * after, or that forgets at a retirement what it released, satisfies every
 * signature and leaves the holder blind; one that takes a retirement meant for
 * another keeper lets a former keeper retire the escrow from it. These cases are what tells them apart. They play one caller at a time: that each call is atomic against the
 * others is the contract's (EscrowKeeper), not played here.
 *
 * It takes a factory, `$make(int $delay, callable $clock): EscrowKeeper`: each
 * call returns a keeper on a store of its own, which the suite fills and
 * throws away after. That keeper waits $delay seconds before a release and
 * reads the time from $clock, so that the suite plays the delay and the lapse
 * without waiting for them.
 *
 * It cannot see where the keeper's store lives. That it lives off the machine
 * that serves, append only and never restored from a backup, is the
 * deployment's to show.
 */
final class KeeperConformance
{
    private const DELAY = 3600;

    private EscrowKeeper $keeper;

    /** @var array{sign: string, box: string} */
    private array $keys;

    private int $now;

    private int $seq = 0;

    /** @var callable(int, callable(): int): EscrowKeeper */
    private $make;

    /** @param callable(int, callable(): int): EscrowKeeper $make */
    private function __construct(callable $make)
    {
        $this->make = $make;
    }

    /**
     * @param callable(int, callable(): int): EscrowKeeper $make
     * @return list<array{case: string, outcome: string, detail: string}> outcome is pass or fail
     */
    public static function run(callable $make): array
    {
        $suite = new self($make);
        $results = [];
        foreach (self::cases() as $case => [$method, $delay]) {
            try {
                $suite->fresh($delay);
                $suite->{$method}();
                $outcome = 'pass';
                $detail  = '';
            } catch (Throwable $e) {
                $outcome = 'fail';
                $detail  = $e->getMessage();
            }
            $results[] = ['case' => $case, 'outcome' => $outcome, 'detail' => $detail];
        }
        return $results;
    }

    /** @return array<string, array{0: string, 1: int}> case name => [method, delay of its keeper] */
    private static function cases(): array
    {
        return [
            'public-keys'                         => ['publicKeys', 0],
            'announce-reads-the-box'              => ['announceReadsTheBox', 0],
            'announce-is-idempotent'              => ['announceIdempotent', self::DELAY],
            'announcement-holds-the-case'         => ['announcementHoldsTheCase', self::DELAY],
            'announce-checks-the-objection-key'   => ['announceChecksTheObjectionKey', 0],
            'keyless-box-refused-once-keyed'      => ['keylessBoxRefusedOnceKeyed', 0],
            'release-hands-back-the-inner-box'    => ['releaseHandsBack', 0],
            'release-is-recorded'                 => ['releaseRecorded', 0],
            'release-only-announced'              => ['releaseOnlyAnnounced', 0],
            'release-takes-any-head'              => ['releaseTakesAnyHead', 0],
            'release-only-when-due'               => ['releaseOnlyWhenDue', self::DELAY],
            'release-within-the-lapse'            => ['releaseWithinTheLapse', self::DELAY],
            'release-repeatable-within-the-window' => ['releaseRepeatable', 0],
            'release-refuses-another-escrow'      => ['releaseRefusesAnotherEscrow', 0],
            'announcement-lapses'                 => ['announcementLapses', self::DELAY],
            'attestation-fresh-and-scoped'        => ['attestationFreshAndScoped', 0],
            'objection-under-the-escrow-key'      => ['objectionUnderTheEscrowKey', self::DELAY],
            'objection-needs-an-announcement'     => ['objectionNeedsAnAnnouncement', self::DELAY],
            'objection-survives-re-announce'      => ['objectionSurvivesReAnnounce', self::DELAY],
            'objection-after-a-release'           => ['objectionAfterARelease', 0],
            'retire-is-final'                     => ['retireIsFinal', 0],
            'retire-names-the-keeper'             => ['retireNamesTheKeeper', 0],
            'retire-is-idempotent'                => ['retireIsIdempotent', 0],
        ];
    }

    private function fresh(int $delay): void
    {
        $this->now = time();
        $keeper = ($this->make)($delay, fn (): int => $this->now);
        if (!$keeper instanceof EscrowKeeper) {
            throw new InvalidArgumentException('the factory does not return an EscrowKeeper');
        }
        $this->keeper = $keeper;
        $this->keys = KeeperSeal::checkKeys($keeper->publicKeys());
    }

    // -- Cases: each throws when it fails. ---------------------------------------

    private function publicKeys(): void
    {
        KeeperSeal::checkKeys($this->keeper->publicKeys());
    }

    private function announceReadsTheBox(): void
    {
        $box = $this->box();
        $this->announce($box, 'K-read');
        $this->expectRefused(
            fn () => $this->keeper->announce(KeeperSeal::newEscrowId(), $box['outer'], $box['userId'], 'K-other-id', $this->seq, 'h'),
            'an announcement naming another escrowId than the box holds'
        );
        $this->expectRefused(
            fn () => $this->keeper->announce($box['escrowId'], $box['outer'], $box['userId'] . '-x', 'K-other-user', $this->seq, 'h'),
            'an announcement naming another account than the box holds — a box moved from one record to another'
        );
        $this->expectRefused(
            fn () => $this->keeper->announce($box['escrowId'], random_bytes(strlen($box['outer'])), $box['userId'], 'K-garbage', $this->seq, 'h'),
            'an announcement of a box that does not open under the keeper'
        );
    }

    private function announceIdempotent(): void
    {
        $box = $this->box();
        $first = $this->announce($box, 'K-twice');
        $this->now += 60;
        $second = $this->announce($box, 'K-twice');
        $this->assert($first === $second, "a second announcement of the same read with the same box moved its deadline ({$first} → {$second})");
    }

    private function announcementHoldsTheCase(): void
    {
        $box   = $this->box();
        $first = $this->announce($box, 'K-held');
        $this->now += 1800;
        $after  = $this->box($box);
        $second = $this->announce($after, 'K-held');
        $this->assert(
            $second === $first,
            "another box of the escrow moved the deadline of the case ({$first} → {$second}): whoever holds one more box would put the read off for ever"
        );
        $this->now = $first;
        $this->keeper->release($after['escrowId'], $after['outer'], 'K-held', ++$this->seq, 'h');
    }

    private function announceChecksTheObjectionKey(): void
    {
        $box    = $this->box();
        $forged = $this->box($box, foreignKey: true);
        $this->expectRefused(
            fn () => $this->announce($forged, 'K-forged'),
            'a box of this escrow carrying another objection key than the escrowId is the fingerprint of'
        );
    }

    private function keylessBoxRefusedOnceKeyed(): void
    {
        $box = $this->box();
        $this->announce($box, 'K-keyed');
        $keyless = $this->box($box, keyless: true);
        $this->expectRefused(
            fn () => $this->announce($keyless, 'K-keyless'),
            'a box without objection key, for an escrow a box with one was announced for'
        );
    }

    private function releaseHandsBack(): void
    {
        $box   = $this->box();
        $inner = $this->releaseOf($box, 'K-release');
        $this->assert(hash_equals($box['inner'], $inner), 'release() did not hand back the inner box the outer one holds');
    }

    private function releaseRecorded(): void
    {
        $box = $this->box();
        $this->releaseOf($box, 'K-recorded');
        $seq   = $this->seq;
        $reads = $this->attested($box['escrowId'])['releases'];
        $this->assert(
            count($reads) === 1 && $reads[0]['case'] === 'K-recorded' && $reads[0]['seq'] === $seq,
            'the attestation does not show the release, its case and its log head: the keeper handed the box back without recording it'
        );
    }

    private function releaseOnlyAnnounced(): void
    {
        $box = $this->box();
        $this->expectRefused(
            fn () => $this->keeper->release($box['escrowId'], $box['outer'], 'K-never-announced', ++$this->seq, 'h'),
            'a release never announced'
        );
    }

    private function releaseTakesAnyHead(): void
    {
        $box = $this->box();
        $this->seq = 1000;
        $this->releaseOf($box, 'K-head-high');
        $this->seq = 0;
        $this->releaseOf($box, 'K-head-low');
        $this->assert(
            array_column($this->attested($box['escrowId'])['releases'], 'seq') === [1001, 1],
            'the releases do not carry the heads they were given'
        );
    }

    private function releaseOnlyWhenDue(): void
    {
        $box = $this->box();
        $due = $this->announce($box, 'K-delayed');
        $this->assert($due >= $this->now + self::DELAY, "the read is due at {$due}, before the delay the keeper was given ends");
        $this->now = $due - 1;
        $this->expectRefused(
            fn () => $this->keeper->release($box['escrowId'], $box['outer'], 'K-delayed', ++$this->seq, 'h'),
            'a release before its announcement is due'
        );
        $this->now = $due;
        $this->keeper->release($box['escrowId'], $box['outer'], 'K-delayed', ++$this->seq, 'h');
    }

    private function releaseWithinTheLapse(): void
    {
        $box = $this->box();
        $due = $this->announce($box, 'K-window-end');
        $this->now = $due + EscrowKeeper::LAPSE - 1;
        $this->keeper->release($box['escrowId'], $box['outer'], 'K-window-end', ++$this->seq, 'h');
        $other = $this->box();
        $this->now = time();
        $due = $this->announce($other, 'K-window-start');
        $this->now = $due + 60;
        $this->keeper->release($other['escrowId'], $other['outer'], 'K-window-start', ++$this->seq, 'h');
    }

    private function releaseRepeatable(): void
    {
        $box = $this->box();
        $this->releaseOf($box, 'K-twice-released');
        $this->keeper->release($box['escrowId'], $box['outer'], 'K-twice-released', ++$this->seq, 'h');
        $this->assert(
            count($this->attested($box['escrowId'])['releases']) === 2,
            'the second release of an announcement still in its window is not recorded'
        );
    }

    private function releaseRefusesAnotherEscrow(): void
    {
        $box   = $this->box();
        $other = $this->box();
        $this->releaseOf($box, 'K-cross');
        $this->expectRefused(
            fn () => $this->keeper->release($box['escrowId'], $other['outer'], 'K-cross', ++$this->seq, 'h'),
            'the release, under an announcement of one escrow, of a box of another escrow'
        );
    }

    private function announcementLapses(): void
    {
        $box = $this->box();
        $due = $this->announce($box, 'K-lapse');
        $this->now = $due + EscrowKeeper::LAPSE + 1;
        $this->expectRefused(
            fn () => $this->keeper->release($box['escrowId'], $box['outer'], 'K-lapse', ++$this->seq, 'h'),
            'a release of an announcement that lapsed'
        );
        $again = $this->announce($box, 'K-lapse');
        $this->assert($again >= $this->now + self::DELAY, "an announcement made again after it lapsed did not start the delay again ({$due} → {$again})");
    }

    private function attestationFreshAndScoped(): void
    {
        $box   = $this->box();
        $other = $this->box();
        $this->releaseOf($other, 'K-other-escrow');
        $this->attested($box['escrowId']);
        $this->releaseOf($box, 'K-fresh');
        $reads = $this->attested($box['escrowId'])['releases'];
        $this->assert(
            array_column($reads, 'case') === ['K-fresh'],
            'the attestation does not list exactly the releases of this escrow — an old one replayed, or another escrow\'s listed'
        );
    }

    private function objectionUnderTheEscrowKey(): void
    {
        $box = $this->box();
        $due = $this->announce($box, 'K-objected');
        $stranger = sodium_crypto_sign_keypair();
        $this->expectRefused(
            fn () => $this->keeper->object($box['escrowId'], 'K-objected', KeeperSeal::token(
                KeeperSeal::objectionMessage($box['escrowId'], 'K-objected'),
                sodium_crypto_sign_secretkey($stranger)
            )),
            'an objection signed under another key than the one the escrowId is the fingerprint of'
        );
        $this->keeper->object($box['escrowId'], 'K-objected', $this->objection($box, 'K-objected'));
        $this->assert(($this->pendingOf($box['escrowId'], 'K-objected')['objected'] ?? false) === true, 'the attestation does not show the objection');
        $after = $this->box($box);
        $this->now = $due;
        $this->expectRefused(
            fn () => $this->keeper->release($after['escrowId'], $after['outer'], 'K-objected', ++$this->seq, 'h'),
            'the release, through another box of the escrow, of a read its holder objected to'
        );
    }

    private function objectionNeedsAnAnnouncement(): void
    {
        $box = $this->box();
        $this->announce($box, 'K-announced');
        $this->expectRefused(
            fn () => $this->keeper->object($box['escrowId'], 'K-never-announced', $this->objection($box, 'K-never-announced')),
            'an objection to a case nothing announced here'
        );
    }

    private function objectionSurvivesReAnnounce(): void
    {
        $box = $this->box();
        $this->announce($box, 'K-kept');
        $this->keeper->object($box['escrowId'], 'K-kept', $this->objection($box, 'K-kept'));
        $after = $this->box($box);
        $this->announce($box, 'K-kept');
        $this->announce($after, 'K-kept');
        $this->now += self::DELAY + EscrowKeeper::LAPSE + 1;
        $this->announce($after, 'K-kept');
        $this->now += self::DELAY;
        $this->assert(
            ($this->pendingOf($box['escrowId'], 'K-kept')['objected'] ?? false) === true,
            'an announcement made again cleared the holder\'s objection'
        );
        $this->expectRefused(
            fn () => $this->keeper->release($box['escrowId'], $after['outer'], 'K-kept', ++$this->seq, 'h'),
            'the release of a read objected to, once announced again'
        );
    }

    private function objectionAfterARelease(): void
    {
        $box = $this->box();
        $this->releaseOf($box, 'K-released');
        $this->keeper->object($box['escrowId'], 'K-released', $this->objection($box, 'K-released'));
        $this->assert(($this->pendingOf($box['escrowId'], 'K-released')['objected'] ?? false) === true, 'the attestation does not show an objection made after a release');
        $this->expectRefused(
            fn () => $this->keeper->release($box['escrowId'], $box['outer'], 'K-released', ++$this->seq, 'h'),
            'a second release of a read its holder objected to after the first'
        );
    }

    private function retireIsFinal(): void
    {
        $box = $this->box();
        $this->releaseOf($box, 'K-released-before');
        $this->announce($box, 'K-before');
        $stranger = sodium_crypto_sign_keypair();
        $this->expectRefused(
            fn () => $this->keeper->retire($box['escrowId'], KeeperSeal::token(
                KeeperSeal::retirementMessage($box['escrowId'], $this->print()),
                sodium_crypto_sign_secretkey($stranger)
            )),
            'a retirement signed under another key than the one the escrowId is the fingerprint of'
        );
        $this->assert(!$this->attested($box['escrowId'])['retired'], 'the attestation says retired before any retirement');
        $this->keeper->retire($box['escrowId'], $this->retirement($box));
        $this->expectRefused(fn () => $this->announce($box, 'K-after'), 'an announcement of an escrow retired here');
        $this->expectRefused(
            fn () => $this->keeper->release($box['escrowId'], $box['outer'], 'K-before', ++$this->seq, 'h'),
            'a release of an escrow retired here'
        );
        $after = $this->attested($box['escrowId']);
        $this->assert($after['retired'], 'the attestation does not say the escrow is retired here');
        $this->assert(
            in_array('K-released-before', array_column($after['releases'], 'case'), true)
                && in_array('K-before', array_column($after['pending'], 'case'), true),
            'the attestation lost, at the retirement, the releases and announcements made before it'
        );
    }

    private function retireNamesTheKeeper(): void
    {
        $box = $this->box();
        $this->announce($box, 'K-elsewhere');
        $this->expectRefused(
            fn () => $this->keeper->retire($box['escrowId'], $this->retirement($box, str_repeat('0', 32))),
            'a retirement signed for another keeper — the one a former keeper would replay here'
        );
        $this->assert(!$this->attested($box['escrowId'])['retired'], 'a retirement for another keeper retired the escrow here');
    }

    private function retireIsIdempotent(): void
    {
        $box = $this->box();
        $this->keeper->retire($box['escrowId'], $this->retirement($box));
        $this->keeper->retire($box['escrowId'], $this->retirement($box));
        $this->assert($this->attested($box['escrowId'])['retired'], 'the attestation does not say the escrow is retired here');
    }

    // -- Fixtures -----------------------------------------------------------------

    /**
     * A box of a new escrow, named after its objection key; with $of, another
     * box of $of's escrow — what a deposit since would seal: same escrowId, key
     * and account, another inner box. $foreignKey: a box of that escrow that
     * carries another key; $keyless: one that carries none.
     *
     * @param array{escrowId: string, userId: string, objectionSecret: string}|null $of
     * @return array{escrowId: string, userId: string, inner: string, outer: string, objectionSecret: string}
     */
    private function box(?array $of = null, bool $foreignKey = false, bool $keyless = false): array
    {
        $secret   = $of['objectionSecret'] ?? sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $escrowId = $of['escrowId'] ?? KeeperSeal::escrowIdFor(sodium_crypto_sign_publickey_from_secretkey($secret));
        $userId   = $of['userId'] ?? 'keeper-conformance-' . bin2hex(random_bytes(6));
        $carried  = $foreignKey ? sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()) : sodium_crypto_sign_publickey_from_secretkey($secret);
        $inner    = random_bytes(80);
        $wrapped  = KeeperSeal::wrap($escrowId, $userId, $keyless ? null : $carried, $inner, $this->keys['box']);
        return [
            'escrowId'        => $escrowId,
            'userId'          => $userId,
            'inner'           => $inner,
            'outer'           => KeeperSeal::outerOf($wrapped),
            'objectionSecret' => $secret,
        ];
    }

    /** @param array{escrowId: string, userId: string, outer: string} $box */
    private function announce(array $box, string $caseRef): int
    {
        return $this->keeper->announce($box['escrowId'], $box['outer'], $box['userId'], $caseRef, $this->seq + 1, 'h');
    }

    /** @param array{escrowId: string, userId: string, outer: string} $box */
    private function releaseOf(array $box, string $caseRef): string
    {
        $due = $this->announce($box, $caseRef);
        $this->assert($due <= $this->now, 'a keeper given no delay holds the read');
        return $this->keeper->release($box['escrowId'], $box['outer'], $caseRef, ++$this->seq, 'h');
    }

    /** @param array{escrowId: string, objectionSecret: string} $box */
    private function objection(array $box, string $caseRef): string
    {
        return KeeperSeal::token(KeeperSeal::objectionMessage($box['escrowId'], $caseRef), $box['objectionSecret']);
    }

    /** @param array{escrowId: string, objectionSecret: string} $box */
    private function retirement(array $box, ?string $keeperPrint = null): string
    {
        return KeeperSeal::token(KeeperSeal::retirementMessage($box['escrowId'], $keeperPrint ?? $this->print()), $box['objectionSecret']);
    }

    private function print(): string
    {
        return KeeperSeal::fingerprint($this->keys);
    }

    /** @return array{case: string, due: int, objected: bool}|null */
    private function pendingOf(string $escrowId, string $caseRef): ?array
    {
        foreach ($this->attested($escrowId)['pending'] as $p) {
            if ($p['case'] === $caseRef) {
                return $p;
            }
        }
        return null;
    }

    /** @return array{releases: list<array{case: string, seq: int, at: int}>, pending: list<array{case: string, due: int, objected: bool}>, retired: bool} */
    private function attested(string $escrowId): array
    {
        $nonce = random_bytes(16);
        try {
            return KeeperSeal::verifyAttestation($this->keeper->attest($escrowId, $nonce), $this->keys['sign'], $escrowId, $nonce);
        } catch (KeeperAttestationException $e) {
            throw new RuntimeException("the attestation does not hold ({$e->reason}): {$e->getMessage()}");
        }
    }

    // -- Assertions ---------------------------------------------------------------

    private function assert(bool $condition, string $failure): void
    {
        if (!$condition) {
            throw new RuntimeException($failure);
        }
    }

    private function expectRefused(callable $call, string $what): void
    {
        try {
            $call();
        } catch (Throwable) {
            return;
        }
        throw new RuntimeException("{$what} was taken");
    }
}
