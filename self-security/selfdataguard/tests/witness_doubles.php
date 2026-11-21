<?php

declare(strict_types=1);

/**
 * Witnesses for the conformance suite of HeadWitness: one that keeps its
 * duties, and some that each break one, each with the forger of its store —
 * what the serving machine can do to that store without the instance's key.
 */

use Pierroons\SelfDataGuard\Escrow\HeadWitness;

/**
 * A store off the serving machine: that machine may append a line to it, as
 * through an endpoint that takes appends, and may neither delete nor rewrite one.
 */
class WitnessReserve
{
    /** @var list<string> */
    private array $lines = [];

    public function append(string $line): void
    {
        $this->lines[] = $line;
    }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function reset(): bool
    {
        return false;
    }
}

/** A store the serving machine can start over. */
final class ResettableReserve extends WitnessReserve
{
    private bool $cleared = false;

    public function reset(): bool
    {
        $this->cleared = true;
        return true;
    }

    public function lines(): array
    {
        return $this->cleared ? [] : parent::lines();
    }
}

/**
 * Keeps its duties: each head it takes is signed under the instance's key, and
 * lastHead() is the highest of the heads it signed.
 */
class ReserveWitness implements HeadWitness
{
    public function __construct(
        protected readonly WitnessReserve $reserve,
        #[\SensitiveParameter] protected readonly string $key
    ) {
    }

    public function witness(int $seq, string $hmac): void
    {
        $held = $this->highest();
        if ($held !== null && $seq <= $held[0]) {
            throw new RuntimeException("seq {$seq} is not above {$held[0]}");
        }
        $head = "{$seq}:{$hmac}";
        $this->reserve->append($head . ':' . hash_hmac('sha256', $head, $this->key));
    }

    public function lastHead(): ?string
    {
        $held = $this->highest();
        return $held === null ? null : "{$held[0]}:{$held[1]}";
    }

    /** @return array{0: int, 1: string}|null */
    protected function highest(): ?array
    {
        $best = null;
        foreach ($this->reserve->lines() as $line) {
            $head = $this->verified($line);
            if ($head !== null && ($best === null || $head[0] > $best[0])) {
                $best = $head;
            }
        }
        return $best;
    }

    /** @return array{0: int, 1: string}|null the head a line carries, when this instance signed it */
    protected function verified(string $line): ?array
    {
        if (!preg_match('/^(\d+):([0-9a-f]{64}):([0-9a-f]{64})$/', $line, $m)) {
            return null;
        }
        return hash_equals(hash_hmac('sha256', "{$m[1]}:{$m[2]}", $this->key), $m[3]) ? [(int) $m[1], $m[2]] : null;
    }
}

/** Takes any line shaped as a head: one written by whoever reaches the store counts. */
final class UnsignedReserveWitness extends ReserveWitness
{
    protected function verified(string $line): ?array
    {
        return preg_match('/^(\d+):([0-9a-f]{64})(:|$)/', $line, $m) ? [(int) $m[1], $m[2]] : null;
    }
}

/** Throws on a line it did not sign: one forged line stops every ceremony. */
final class ThrowsOnForgeryWitness extends ReserveWitness
{
    protected function highest(): ?array
    {
        foreach ($this->reserve->lines() as $line) {
            if ($this->verified($line) === null) {
                throw new RuntimeException('the store holds a line this instance did not sign');
            }
        }
        return parent::highest();
    }
}

/** Answers the last head it finds, not the highest: an older head presented again rewinds it. */
final class LastEntryWitness extends ReserveWitness
{
    protected function highest(): ?array
    {
        $last = null;
        foreach ($this->reserve->lines() as $line) {
            $last = $this->verified($line) ?? $last;
        }
        return $last;
    }
}

/** Takes any seq, a lower one included, and still answers the highest head. */
final class AnySeqWitness extends ReserveWitness
{
    public function witness(int $seq, string $hmac): void
    {
        $head = "{$seq}:{$hmac}";
        $this->reserve->append($head . ':' . hash_hmac('sha256', $head, $this->key));
    }
}

/** Says it took the head, and keeps nothing. */
final class DroppingWitness extends ReserveWitness
{
    public function witness(int $seq, string $hmac): void
    {
    }
}

/** Comes with a head in its store before it took any. */
final class PreloadedWitness extends ReserveWitness
{
    public function __construct(WitnessReserve $reserve, string $key)
    {
        parent::__construct($reserve, $key);
        parent::witness(1, str_repeat('0', 64));
    }
}

/**
 * The head in a plain file the serving user writes — what the ceremony bench
 * runs. Whoever can write the file puts any head there.
 */
final class PlainFileWitness implements HeadWitness
{
    public function __construct(public readonly string $store)
    {
    }

    public function witness(int $seq, string $hmac): void
    {
        file_put_contents($this->store, "{$seq}:{$hmac}");
    }

    public function lastHead(): ?string
    {
        return is_file($this->store) ? (string) file_get_contents($this->store) : null;
    }
}

/** What the serving machine can do to a WitnessReserve: append a line, start it over when it allows it. */
function reserveForger(WitnessReserve $reserve): callable
{
    return static function (string $act, string $head) use ($reserve): bool {
        if ($act === 'forge') {
            $reserve->append($head . ':' . bin2hex(random_bytes(32)));
            return true;
        }
        if ($act === 'replay') {
            foreach ($reserve->lines() as $line) {
                if (str_starts_with($line, $head . ':')) {
                    $reserve->append($line);
                    return true;
                }
            }
            return false;
        }
        return $reserve->reset();
    };
}

/** What the serving machine can do to a PlainFileWitness: everything. */
function plainFileForger(PlainFileWitness $witness): callable
{
    return static function (string $act, string $head) use ($witness): bool {
        if ($act === 'reset') {
            @unlink($witness->store);
            return true;
        }
        file_put_contents($witness->store, $head);
        return true;
    };
}

/**
 * The factory the conformance suite takes, for a witness class built on a WitnessReserve.
 *
 * @param class-string<ReserveWitness> $class
 */
function reserveWitnessFactory(string $class = ReserveWitness::class, string $reserveClass = WitnessReserve::class): callable
{
    return static function () use ($class, $reserveClass): array {
        $reserve = new $reserveClass();
        return [new $class($reserve, random_bytes(32)), reserveForger($reserve)];
    };
}

/** The factory for a PlainFileWitness, on a file of its own in $dir. */
function plainFileWitnessFactory(string $dir): callable
{
    return static function () use ($dir): array {
        $witness = new PlainFileWitness($dir . '/head-' . bin2hex(random_bytes(4)));
        return [$witness, plainFileForger($witness)];
    };
}
