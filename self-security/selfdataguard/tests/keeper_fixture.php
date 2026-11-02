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
    /**
     * @var array<string, array{case: string, due: int, objection: ?string, objected: bool, box: string}>
     *      by escrowId hex and case; `box` is the hash of the outer box announced
     */
    public array $pending = [];

    /** @var array<string, list<array{case: string, seq: int, at: int}>> by escrowId hex */
    public array $releases = [];

    /** Throws on every call while true: a keeper that does not answer. */
    public bool $down = false;

    /** The time the keeper reads, when a bench sets it; before the clock it was given. */
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
        $box = KeeperSeal::open($outerBox, $this->boxSecret, $this->boxPublic);
        if (!hash_equals($box['escrowId'], $escrowId) || $box['userId'] !== $userId) {
            throw new RuntimeException('The box names another escrow, or another account, than the read announced');
        }
        $key  = bin2hex($escrowId) . '|' . $caseRef;
        $held = $this->pending[$key] ?? null;
        if ($held !== null && ($held['objected'] || (hash_equals($held['box'], hash('sha256', $outerBox)) && !$this->lapsed($held)))) {
            return $held['due'];
        }
        $this->pending[$key] = [
            'case'      => $caseRef,
            'due'       => $this->time() + $this->delay,
            'objection' => $box['objection'],
            'objected'  => false,
            'box'       => hash('sha256', $outerBox),
        ];
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
        if (!hash_equals($held['box'], hash('sha256', $outerBox))) {
            throw new RuntimeException('This read was announced with another box: a deposit drew a new key since — announce it again');
        }
        if ($this->time() < $held['due']) {
            throw new RuntimeException('This read is not due yet');
        }
        if ($this->lapsed($held)) {
            throw new RuntimeException('This announcement has lapsed: announce it again');
        }
        $box = KeeperSeal::open($outerBox, $this->boxSecret, $this->boxPublic);
        if (!hash_equals($box['escrowId'], $escrowId)) {
            throw new RuntimeException('The box names another escrow than the read announced');
        }
        $this->releases[bin2hex($escrowId)][] = ['case' => $caseRef, 'seq' => $seq, 'at' => $this->time()];
        unset($this->pending[$key]);
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

    public function object(string $escrowId, string $caseRef, string $signature): void
    {
        $this->answer();
        $key = bin2hex($escrowId) . '|' . $caseRef;
        $held = $this->pending[$key] ?? throw new RuntimeException('No read of this escrow is announced for this case');
        if ($held['objection'] === null || !KeeperSeal::verifyObjection($signature, $held['objection'], $escrowId, $caseRef)) {
            throw new RuntimeException('This objection is not signed by the key the escrow carries');
        }
        $this->pending[$key]['objected'] = true;
    }

    /** The box this keeper holds the key of, opened: what the administrator alone cannot do. */
    public function openForBench(string $outerBox): array
    {
        return KeeperSeal::open($outerBox, $this->boxSecret, $this->boxPublic);
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

    private function answer(): void
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

    public function object(string $escrowId, string $caseRef, string $signature): void
    {
        $this->load();
        try {
            parent::object($escrowId, $caseRef, $signature);
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
        $this->pending = array_map(static fn (array $p): array => ['objection' => $p['objection'] === null ? null
            : (string) base64_decode($p['objection'], true)] + $p, $d['pending']);
        $this->releases = $d['releases'];
        $this->delay    = $d['delay'];
    }

    private function save(): void
    {
        file_put_contents($this->file, json_encode([
            'boxPublic'  => base64_encode($this->boxPublic),
            'boxSecret'  => base64_encode($this->boxSecret),
            'signPublic' => base64_encode($this->signPublic),
            'signSecret' => base64_encode($this->signSecret),
            'pending'    => array_map(static fn (array $p): array => ['objection' => $p['objection'] === null ? null
                : base64_encode($p['objection'])] + $p, $this->pending),
            'releases'   => $this->releases,
            'delay'      => $this->delay,
        ], JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
