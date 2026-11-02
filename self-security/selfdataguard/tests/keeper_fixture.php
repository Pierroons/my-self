<?php

declare(strict_types=1);

/**
 * A keeper for the benches: in memory, its keys drawn at construction, a delay
 * and a clock that a bench sets. It keeps the contract EscrowKeeper states —
 * the benches that break it on purpose extend it.
 */

use Pierroons\SelfDataGuard\Escrow\EscrowKeeper;
use Pierroons\SelfDataGuard\Escrow\KeeperSeal;

class BenchKeeper implements EscrowKeeper
{
    /** @var array<string, array{case: string, due: int, objected: bool}> by escrowId hex and case */
    public array $pending = [];

    /** @var array<string, list<array{case: string, seq: int, at: int}>> by escrowId hex */
    public array $releases = [];

    /** @var array<string, true> escrowIds (hex) a box with an objection key was seen for */
    public array $keyed = [];

    /** @var array<string, true> escrowIds (hex) retired here */
    public array $retired = [];

    /** Throws on every call while true: a keeper that does not answer. */
    public bool $down = false;

    /** The time the keeper reads, when a bench sets it — it takes precedence over the clock the keeper was given. */
    public ?int $now = null;

    protected string $boxPublic;
    protected string $boxSecret;
    protected string $signPublic;
    protected string $signSecret;

    /** @var (callable(): int)|null */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(public int $delay = 0, ?callable $clock = null)
    {
        $this->clock = $clock;
        $box = sodium_crypto_box_keypair();
        $this->boxPublic = sodium_crypto_box_publickey($box);
        $this->boxSecret = sodium_crypto_box_secretkey($box);
        $sign = sodium_crypto_sign_keypair();
        $this->signPublic = sodium_crypto_sign_publickey($sign);
        $this->signSecret = sodium_crypto_sign_secretkey($sign);
    }

    public function publicKeys(): array
    {
        return ['sign' => $this->signPublic, 'box' => $this->boxPublic];
    }

    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, int $seq, string $hmac): int
    {
        $this->answer();
        $box = $this->boxOf($escrowId, $outerBox);
        if ($box['userId'] !== $userId) {
            throw new RuntimeException('The box names another account than the read announced');
        }
        $key  = bin2hex($escrowId) . '|' . $caseRef;
        $held = $this->pending[$key] ?? null;
        if ($held !== null && ($held['objected'] || !$this->lapsed($held))) {
            return $held['due'];
        }
        $this->pending[$key] = ['case' => $caseRef, 'due' => $this->time() + $this->delay, 'objected' => false];
        return $this->pending[$key]['due'];
    }

    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string
    {
        $this->answer();
        $key = bin2hex($escrowId) . '|' . $caseRef;
        $held = $this->pending[$key] ?? throw new RuntimeException('This read was never announced');
        if ($held['objected']) {
            throw new RuntimeException('The holder objected to this read');
        }
        if ($this->time() < $held['due']) {
            throw new RuntimeException('This read is not due yet');
        }
        if ($this->lapsed($held)) {
            throw new RuntimeException('This announcement has lapsed: announce it again');
        }
        $box = $this->boxOf($escrowId, $outerBox);
        $this->releases[bin2hex($escrowId)][] = ['case' => $caseRef, 'seq' => $seq, 'at' => $this->time()];
        return $box['inner'];
    }

    public function attest(string $escrowId, string $nonce): string
    {
        $this->answer();
        $hex = bin2hex($escrowId);
        $pending = [];
        foreach ($this->pending as $key => $held) {
            if (str_starts_with($key, $hex . '|') && ($held['objected'] || !$this->lapsed($held))) {
                $pending[] = ['case' => $held['case'], 'due' => $held['due'], 'objected' => $held['objected']];
            }
        }
        return KeeperSeal::attestation($escrowId, $nonce, $this->releases[$hex] ?? [], $pending, $this->signSecret);
    }

    public function object(string $escrowId, string $caseRef, string $token): void
    {
        $this->answer();
        $key = bin2hex($escrowId) . '|' . $caseRef;
        $held = $this->pending[$key] ?? null;
        if ($held === null || (!$held['objected'] && $this->lapsed($held))) {
            throw new RuntimeException('No read of this escrow is announced for this case');
        }
        if (!KeeperSeal::verifyObjection($token, $escrowId, $caseRef)) {
            throw new RuntimeException('This objection is not signed by the key this escrow is named after');
        }
        $this->pending[$key]['objected'] = true;
    }

    public function retire(string $escrowId, string $token): void
    {
        $this->answer();
        if (!KeeperSeal::verifyRetirement($token, $escrowId)) {
            throw new RuntimeException('This retirement is not signed by the key this escrow is named after');
        }
        $this->retired[bin2hex($escrowId)] = true;
    }

    /** The box this keeper holds the key of, opened: what the administrator alone cannot do. */
    public function openForBench(string $outerBox): array
    {
        return KeeperSeal::open($outerBox, $this->boxSecret, $this->boxPublic);
    }

    /**
     * $outerBox opened, when it may stand for $escrowId here: not retired, the
     * box names it, its objection key is the one the escrowId names, and a box
     * without one comes for an escrow no box with one was seen for.
     *
     * @return array{escrowId: string, userId: string, objection: ?string, inner: string}
     */
    protected function boxOf(string $escrowId, string $outerBox): array
    {
        $hex = bin2hex($escrowId);
        if (isset($this->retired[$hex])) {
            throw new RuntimeException('This escrow was retired from this keeper');
        }
        $box = KeeperSeal::open($outerBox, $this->boxSecret, $this->boxPublic);
        if (!$this->fits($box, $escrowId)) {
            throw new RuntimeException('The box names another escrow, or carries another objection key, than the read announced');
        }
        if ($box['objection'] === null && $this->refusesKeyless($hex)) {
            throw new RuntimeException('A box without objection key, for an escrow sealed with one');
        }
        if ($box['objection'] !== null) {
            $this->keyed[$hex] = true;
        }
        return $box;
    }

    /** @param array{escrowId: string, objection: ?string} $box */
    protected function fits(array $box, string $escrowId): bool
    {
        return KeeperSeal::boxFits($box, $escrowId);
    }

    protected function refusesKeyless(string $escrowIdHex): bool
    {
        return isset($this->keyed[$escrowIdHex]);
    }

    /** @param array{due: int} $held */
    protected function lapsed(array $held): bool
    {
        return $this->time() > $held['due'] + self::LAPSE;
    }

    protected function time(): int
    {
        return $this->now ?? ($this->clock !== null ? ($this->clock)() : time());
    }

    protected function answer(): void
    {
        if ($this->down) {
            throw new RuntimeException('The keeper does not answer');
        }
    }
}

/**
 * BenchKeeper whose keys and records live in a JSON file, so that the
 * ceremony's processes share one keeper, as a store off the machine would be.
 */
final class FileBenchKeeper extends BenchKeeper
{
    public function __construct(private readonly string $file, int $delay = 0)
    {
        parent::__construct($delay);
        is_file($file) ? $this->load() : $this->save();
    }

    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, int $seq, string $hmac): int
    {
        $this->load();
        try {
            return parent::announce($escrowId, $outerBox, $userId, $caseRef, $seq, $hmac);
        } finally {
            $this->save();
        }
    }

    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string
    {
        $this->load();
        try {
            return parent::release($escrowId, $outerBox, $caseRef, $seq, $hmac);
        } finally {
            $this->save();
        }
    }

    public function attest(string $escrowId, string $nonce): string
    {
        $this->load();
        return parent::attest($escrowId, $nonce);
    }

    public function object(string $escrowId, string $caseRef, string $token): void
    {
        $this->load();
        try {
            parent::object($escrowId, $caseRef, $token);
        } finally {
            $this->save();
        }
    }

    public function retire(string $escrowId, string $token): void
    {
        $this->load();
        try {
            parent::retire($escrowId, $token);
        } finally {
            $this->save();
        }
    }

    private function load(): void
    {
        $d = json_decode((string) file_get_contents($this->file), true, flags: JSON_THROW_ON_ERROR);
        [$this->boxPublic, $this->boxSecret, $this->signPublic, $this->signSecret] = array_map(
            static fn (string $k): string => (string) base64_decode($k, true),
            [$d['boxPublic'], $d['boxSecret'], $d['signPublic'], $d['signSecret']]
        );
        $this->pending  = $d['pending'];
        $this->releases = $d['releases'];
        $this->keyed    = $d['keyed'];
        $this->retired  = $d['retired'];
        $this->delay    = $d['delay'];
    }

    private function save(): void
    {
        file_put_contents($this->file, json_encode([
            'boxPublic'  => base64_encode($this->boxPublic),
            'boxSecret'  => base64_encode($this->boxSecret),
            'signPublic' => base64_encode($this->signPublic),
            'signSecret' => base64_encode($this->signSecret),
            'pending'    => (object) $this->pending,
            'releases'   => (object) $this->releases,
            'keyed'      => (object) $this->keyed,
            'retired'    => (object) $this->retired,
            'delay'      => $this->delay,
        ], JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
