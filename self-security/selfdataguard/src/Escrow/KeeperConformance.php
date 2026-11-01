<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use RuntimeException;
use Throwable;

/**
 * The EscrowKeeper contract, played against an implementation.
 *
 * A keeper that hands back the box without recording it, that signs an old
 * attestation for a new request, or that takes an objection nobody signed,
 * satisfies every signature and leaves the holder blind. These cases are
 * what tells it apart.
 *
 * It writes to the keeper it is given: announcements, releases and
 * objections under escrowIds drawn at random, and log heads from 0 up. Run it
 * against a keeper whose store you throw away, before the keeper serves: a
 * store that took these heads refuses the ones a real log starts at.
 *
 * It cannot see where the keeper's store lives. That it lives off the machine
 * that serves, append only and never restored from a backup, is the
 * deployment's to show.
 */
final class KeeperConformance
{
    private int $seq = 0;

    /** @var array{sign: string, box: string} */
    private array $keys;

    private function __construct(private readonly EscrowKeeper $keeper)
    {
    }

    /** @return list<array{case: string, outcome: string, detail: string}> outcome is pass or fail */
    public static function run(EscrowKeeper $keeper): array
    {
        $suite = new self($keeper);
        $results = [];
        foreach (self::cases() as $case => $method) {
            try {
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

    /** @return array<string, string> case name => method */
    private static function cases(): array
    {
        return [
            'public-keys'                     => 'publicKeys',
            'announce-reads-the-box'          => 'announceReadsTheBox',
            'announce-is-idempotent'          => 'announceIdempotent',
            'archive-read-due-at-once'        => 'archiveDueAtOnce',
            'release-hands-back-the-inner-box' => 'releaseHandsBack',
            'release-is-recorded'             => 'releaseRecorded',
            'release-only-announced'          => 'releaseOnlyAnnounced',
            'release-seq-grows'               => 'releaseSeqGrows',
            'attestation-fresh-and-scoped'    => 'attestationFreshAndScoped',
            'objection-under-the-box-key'     => 'objectionUnderTheBoxKey',
        ];
    }

    // -- Cases: each throws when it fails. ---------------------------------------

    private function publicKeys(): void
    {
        $this->keys = KeeperSeal::checkKeys($this->keeper->publicKeys());
    }

    private function announceReadsTheBox(): void
    {
        $box = $this->box();
        $this->keeper->announce($box['escrowId'], $box['outer'], $box['userId'], 'K-read', true, $this->seq, 'h');
        $this->expectRefused(
            fn () => $this->keeper->announce(KeeperSeal::newEscrowId(), $box['outer'], $box['userId'], 'K-other-id', true, $this->seq, 'h'),
            'an announcement naming another escrowId than the box holds'
        );
        $this->expectRefused(
            fn () => $this->keeper->announce($box['escrowId'], $box['outer'], $box['userId'] . '-x', 'K-other-user', true, $this->seq, 'h'),
            'an announcement naming another account than the box holds — a box moved from one record to another'
        );
        $this->expectRefused(
            fn () => $this->keeper->announce($box['escrowId'], random_bytes(strlen($box['outer'])), $box['userId'], 'K-garbage', true, $this->seq, 'h'),
            'an announcement of a box that does not open under the keeper'
        );
    }

    private function announceIdempotent(): void
    {
        $box = $this->box();
        $first  = $this->keeper->announce($box['escrowId'], $box['outer'], $box['userId'], 'K-twice', false, $this->seq, 'h');
        $second = $this->keeper->announce($box['escrowId'], $box['outer'], $box['userId'], 'K-twice', false, $this->seq, 'h');
        $this->assert($first === $second, "a second announcement of the same read moved its deadline ({$first} → {$second})");
    }

    private function archiveDueAtOnce(): void
    {
        $box = $this->box();
        $due = $this->keeper->announce($box['escrowId'], $box['outer'], $box['userId'], 'K-archive', true, $this->seq, 'h');
        $this->assert($due <= time(), 'the read of an archive is delayed: after a fraudulent level 3, the impostor holds the live vault and would object');
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

    private function releaseSeqGrows(): void
    {
        $box = $this->box();
        $this->releaseOf($box, 'K-seq-1');
        $held = $this->seq;
        $this->keeper->announce($box['escrowId'], $box['outer'], $box['userId'], 'K-seq-2', true, $held, 'h');
        $this->expectRefused(
            fn () => $this->keeper->release($box['escrowId'], $box['outer'], 'K-seq-2', $held, 'h'),
            'a release at a log head that is not above the last one the keeper took'
        );
        $this->keeper->release($box['escrowId'], $box['outer'], 'K-seq-2', $this->seq = $held + 1, 'h');
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
        $this->keeper->announce($box['escrowId'], $box['outer'], $box['userId'], 'K-objected', false, $this->seq, 'h');
        $forged = sodium_crypto_sign_detached(
            KeeperSeal::objectionMessage($box['escrowId'], 'K-objected'),
            sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())
        );
        $this->expectRefused(
            fn () => $this->keeper->object($box['escrowId'], 'K-objected', $forged),
            'an objection signed by another key than the one the box carries'
        );
        $this->keeper->object($box['escrowId'], 'K-objected', sodium_crypto_sign_detached(
            KeeperSeal::objectionMessage($box['escrowId'], 'K-objected'),
            $box['objectionSecret']
        ));
        $pending = array_values(array_filter(
            $this->attested($box['escrowId'])['pending'],
            static fn (array $p): bool => $p['case'] === 'K-objected'
        ));
        $this->assert(($pending[0]['objected'] ?? false) === true, 'the attestation does not show the objection');
        $this->expectRefused(
            fn () => $this->keeper->release($box['escrowId'], $box['outer'], 'K-objected', ++$this->seq, 'h'),
            'the release of a read its holder objected to'
        );
    }

    // -- Fixtures -----------------------------------------------------------------

    /** @return array{escrowId: string, userId: string, inner: string, outer: string, objectionSecret: string} */
    private function box(): array
    {
        $this->keys ??= KeeperSeal::checkKeys($this->keeper->publicKeys());
        $escrowId  = KeeperSeal::newEscrowId();
        $userId    = 'keeper-conformance-' . bin2hex(random_bytes(6));
        $inner     = random_bytes(80);
        $objection = sodium_crypto_sign_keypair();
        $wrapped   = KeeperSeal::wrap($escrowId, $userId, sodium_crypto_sign_publickey($objection), $inner, $this->keys['box']);
        return [
            'escrowId'        => $escrowId,
            'userId'          => $userId,
            'inner'           => $inner,
            'outer'           => KeeperSeal::outerOf($wrapped),
            'objectionSecret' => sodium_crypto_sign_secretkey($objection),
        ];
    }

    /** @param array{escrowId: string, userId: string, outer: string} $box */
    private function releaseOf(array $box, string $caseRef): string
    {
        $due = $this->keeper->announce($box['escrowId'], $box['outer'], $box['userId'], $caseRef, true, $this->seq + 1, 'h');
        $this->assert($due <= time(), 'the read of an archive is delayed');
        return $this->keeper->release($box['escrowId'], $box['outer'], $caseRef, ++$this->seq, 'h');
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
