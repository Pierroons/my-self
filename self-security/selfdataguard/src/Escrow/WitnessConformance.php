<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The duties of HeadWitness, played against an implementation.
 *
 * A witness whose store takes a head from anyone who can write to it
 * satisfies both signatures, and one forged head then stops every ceremony.
 * These cases are what tells it apart, with the store reset and the store
 * rewound.
 *
 * It takes a factory, `$make(): array{0: HeadWitness, 1: callable}`: each
 * call returns a witness on a store of its own, and the forger of that store,
 * `$forge(string $act, string $head): bool`. The forger does to the store
 * what the machine that serves can do without this instance's key — write
 * every file its user can write, call every endpoint it reaches — and returns
 * false when the store is out of its reach for that act:
 * - `forge`: put $head ("seq:hmac", or a line that is not a head) in the store;
 * - `replay`: present again the entry the store kept for $head, an older head
 *   of its own;
 * - `reset`: empty the store, delete it, or start it over.
 *
 * It cannot see what the serving machine reaches. A forger that does less
 * than that machine can do makes the suite pass for nothing: what the forger
 * may reach is the deployment's to show.
 */
final class WitnessConformance
{
    /** @var callable(): mixed */
    private $make;

    private HeadWitness $witness;

    /** @var callable(string, string): mixed */
    private $forger;

    /** @var list<string> the acts the forger could not reach, in the case being played */
    private array $unreached = [];

    /** @param callable(): mixed $make */
    private function __construct(callable $make)
    {
        $this->make = $make;
    }

    /**
     * @param callable(): mixed $make
     * @return list<array{case: string, outcome: string, detail: string}> outcome is
     *         pass or fail; a single `nothing` when the witness is NoHeadWitness,
     *         which takes no head
     * @throws InvalidArgumentException when the factory does not return a witness and its forger
     */
    public static function run(callable $make): array
    {
        $suite = new self($make);
        $suite->fresh();
        if ($suite->witness instanceof NoHeadWitness) {
            return [['case' => 'no-head-witness', 'outcome' => 'nothing', 'detail' => 'NoHeadWitness takes no head: there is nothing to play']];
        }
        $results = [];
        foreach (self::cases() as $case => $method) {
            try {
                $suite->fresh();
                $suite->{$method}();
                $outcome = 'pass';
                $detail  = $suite->unreached === []
                    ? ''
                    : 'out of the serving machine\'s reach: ' . implode(', ', array_unique($suite->unreached));
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
            'starts-empty'            => 'startsEmpty',
            'takes-a-head'            => 'takesAHead',
            'refuses-a-seq-not-above' => 'refusesASeqNotAbove',
            'forged-head-not-taken'   => 'forgedHeadNotTaken',
            'store-not-reset'         => 'storeNotReset',
            'no-rewind'               => 'noRewind',
        ];
    }

    private function fresh(): void
    {
        $made = ($this->make)();
        if (!is_array($made) || !(($made[0] ?? null) instanceof HeadWitness) || !is_callable($made[1] ?? null)) {
            throw new InvalidArgumentException('the factory does not return [HeadWitness, forger]');
        }
        [$this->witness, $this->forger] = [$made[0], $made[1]];
        $this->unreached = [];
    }

    // -- Cases: each throws when it fails. ---------------------------------------

    private function startsEmpty(): void
    {
        $this->assertHead(null, 'on a new store');
    }

    private function takesAHead(): void
    {
        $this->assertHead($this->take(1), 'after witness()');
    }

    private function refusesASeqNotAbove(): void
    {
        $held = $this->take(3);
        $this->expectRefused(fn () => $this->witness->witness(3, self::hmac()), 'witness() with the seq it holds');
        $this->expectRefused(fn () => $this->witness->witness(2, self::hmac()), 'witness() with a seq below the one it holds');
        $this->assertHead($held, 'after two seqs it had to refuse');
    }

    private function forgedHeadNotTaken(): void
    {
        $held = $this->take(5);
        $this->forge('forge', '9:' . self::hmac());
        $this->forge('forge', 'not-a-head');
        $this->assertHead($held, 'after a head was forged in its store');
        $this->assertHead($this->take(6), 'after the next witness()');
    }

    private function storeNotReset(): void
    {
        $held = $this->take(5);
        $this->forge('reset', $held);
        $this->assertHead($held, 'after its store was reset');
    }

    private function noRewind(): void
    {
        $older = $this->take(5);
        $held  = $this->take(7);
        $this->forge('replay', $older);
        $this->assertHead($held, 'after an older head of its own was presented again');
        $this->expectRefused(fn () => $this->witness->witness(6, self::hmac()), 'witness() with a seq below the highest head, once the older one was presented again');
    }

    // -- Helpers -------------------------------------------------------------------

    private function take(int $seq): string
    {
        $hmac = self::hmac();
        $this->witness->witness($seq, $hmac);
        return "{$seq}:{$hmac}";
    }

    private function forge(string $act, string $head): void
    {
        $reached = ($this->forger)($act, $head);
        if ($reached === false) {
            $this->unreached[] = $act;
            return;
        }
        if ($reached !== true) {
            throw new RuntimeException("the forger returned neither true nor false for {$act}");
        }
    }

    private function assertHead(?string $expected, string $when): void
    {
        try {
            $got = $this->witness->lastHead();
        } catch (Throwable $e) {
            throw new RuntimeException("lastHead() throws {$when}, and every ceremony stops: {$e->getMessage()}");
        }
        if ($got !== $expected) {
            throw new RuntimeException(sprintf(
                'lastHead() returns %s %s, where %s',
                $got === null ? 'null' : "\"{$got}\"",
                $when,
                $expected === null ? 'it took no head' : "it took \"{$expected}\""
            ));
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

    private static function hmac(): string
    {
        return bin2hex(random_bytes(32));
    }
}
