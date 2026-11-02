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
 * ends, or once its holder objected, satisfies every signature and leaves the
 * holder blind. These cases are what tells it apart.
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
            'public-keys'                      => ['publicKeys', 0],
            'announce-reads-the-box'           => ['announceReadsTheBox', 0],
            'announce-is-idempotent'           => ['announceIdempotent', self::DELAY],
            'release-hands-back-the-inner-box' => ['releaseHandsBack', 0],
            'release-is-recorded'              => ['releaseRecorded', 0],
            'release-only-announced'           => ['releaseOnlyAnnounced', 0],
            'release-takes-any-head'           => ['releaseTakesAnyHead', 0],
            'release-only-when-due'            => ['releaseOnlyWhenDue', self::DELAY],
            'release-needs-the-announced-box'  => ['releaseNeedsTheAnnouncedBox', 0],
            'new-box-restarts-the-delay'       => ['newBoxRestartsTheDelay', self::DELAY],
            'announcement-lapses'              => ['announcementLapses', self::DELAY],
            'attestation-fresh-and-scoped'     => ['attestationFreshAndScoped', 0],
            'objection-under-the-box-key'      => ['objectionUnderTheBoxKey', self::DELAY],
            'objection-survives-re-announce'   => ['objectionSurvivesReAnnounce', self::DELAY],
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

    private function releaseNeedsTheAnnouncedBox(): void
    {
        $box   = $this->box();
        $after = $this->box($box);
        $this->announce($box, 'K-box');
        $this->expectRefused(
            fn () => $this->keeper->release($box['escrowId'], $after['outer'], 'K-box', ++$this->seq, 'h'),
            'a release of another box than the one announced — what a deposit since sealed'
        );
    }

    private function newBoxRestartsTheDelay(): void
    {
        $box   = $this->box(objection: false);
        $first = $this->announce($box, 'K-new-box');
        $this->now += 1800;
        $after = $this->box($box);
        $second = $this->announce($after, 'K-new-box');
        $this->assert(
            $second >= $this->now + self::DELAY,
            "an announcement made again with a new box kept its deadline ({$first} → {$second}): a deposit since would be read without a full delay"
        );
        $this->keeper->object($box['escrowId'], 'K-new-box', $this->objection($after, 'K-new-box'));
        $this->assert($this->pendingOf($box['escrowId'], 'K-new-box')['objected'] ?? false, 'the objection under the new box\'s key is not shown');
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

    private function objectionUnderTheBoxKey(): void
    {
        $box = $this->box();
        $due = $this->announce($box, 'K-objected');
        $forged = sodium_crypto_sign_detached(
            KeeperSeal::objectionMessage($box['escrowId'], 'K-objected'),
            sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())
        );
        $this->expectRefused(
            fn () => $this->keeper->object($box['escrowId'], 'K-objected', $forged),
            'an objection signed by another key than the one the box carries'
        );
        $this->keeper->object($box['escrowId'], 'K-objected', $this->objection($box, 'K-objected'));
        $this->assert(($this->pendingOf($box['escrowId'], 'K-objected')['objected'] ?? false) === true, 'the attestation does not show the objection');
        $this->now = $due;
        $this->expectRefused(
            fn () => $this->keeper->release($box['escrowId'], $box['outer'], 'K-objected', ++$this->seq, 'h'),
            'the release of a read its holder objected to'
        );
    }

    private function objectionSurvivesReAnnounce(): void
    {
        $box = $this->box();
        $this->announce($box, 'K-held');
        $this->keeper->object($box['escrowId'], 'K-held', $this->objection($box, 'K-held'));
        $after = $this->box($box);
        $this->announce($box, 'K-held');
        $this->announce($after, 'K-held');
        $this->now += self::DELAY + EscrowKeeper::LAPSE + 1;
        $this->announce($after, 'K-held');
        $this->now += self::DELAY;
        $this->assert(
            ($this->pendingOf($box['escrowId'], 'K-held')['objected'] ?? false) === true,
            'an announcement made again cleared the holder\'s objection'
        );
        $this->expectRefused(
            fn () => $this->keeper->release($box['escrowId'], $after['outer'], 'K-held', ++$this->seq, 'h'),
            'the release of a read objected to, once announced again'
        );
    }

    // -- Fixtures -----------------------------------------------------------------

    /**
     * A box of a new escrow; with $of, the box a deposit on $of's escrow would
     * seal: same escrow and account, another inner box and objection key.
     *
     * @param array{escrowId: string, userId: string}|null $of
     * @return array{escrowId: string, userId: string, inner: string, outer: string, objectionSecret: ?string}
     */
    private function box(?array $of = null, bool $objection = true): array
    {
        $escrowId  = $of['escrowId'] ?? KeeperSeal::newEscrowId();
        $userId    = $of['userId'] ?? 'keeper-conformance-' . bin2hex(random_bytes(6));
        $inner     = random_bytes(80);
        $pair      = $objection ? sodium_crypto_sign_keypair() : null;
        $wrapped   = KeeperSeal::wrap($escrowId, $userId, $pair === null ? null : sodium_crypto_sign_publickey($pair), $inner, $this->keys['box']);
        return [
            'escrowId'        => $escrowId,
            'userId'          => $userId,
            'inner'           => $inner,
            'outer'           => KeeperSeal::outerOf($wrapped),
            'objectionSecret' => $pair === null ? null : sodium_crypto_sign_secretkey($pair),
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

    /** @param array{escrowId: string, objectionSecret: ?string} $box */
    private function objection(array $box, string $caseRef): string
    {
        return sodium_crypto_sign_detached(KeeperSeal::objectionMessage($box['escrowId'], $caseRef), (string) $box['objectionSecret']);
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

    /** @return array{releases: list<array{case: string, seq: int, at: int}>, pending: list<array{case: string, due: int, objected: bool}>} */
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
