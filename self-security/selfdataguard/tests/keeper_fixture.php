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
    /** @var array<string, array{case: string, due: int, box: string}> by keyOf(): escrowId hex, case and box */
    public array $pending = [];

    /** @var array<string, true> the cases objected to, by escrowId hex and case */
    public array $objections = [];

    /** @var array<string, list<array{case: string, seq: int, at: int, box: string}>> by escrowId hex */
    public array $releases = [];

    /** @var array<string, list<array{case: string, at: int}>> the consents taken, by escrowId hex */
    public array $consents = [];

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
        $key  = $this->keyOf($escrowId, $caseRef, $outerBox);
        $held = $this->pending[$key] ?? null;
        if ($held !== null && ($this->objected($escrowId, $caseRef) || !$this->lapsed($held))) {
            return $held['due'];
        }
        $this->pending[$key] = ['case' => $caseRef, 'due' => $this->time() + $this->delay, 'box' => KeeperSeal::boxPrint($outerBox)];
        return $this->pending[$key]['due'];
    }

    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string
    {
        $this->answer();
        $held = $this->pending[$this->keyOf($escrowId, $caseRef, $outerBox)] ?? throw new RuntimeException('This box was never announced for this read');
        if ($this->objected($escrowId, $caseRef)) {
            throw new RuntimeException('The holder objected to this read');
        }
        if ($this->time() < $held['due']) {
            throw new RuntimeException('This read is not due yet');
        }
        if ($this->lapsed($held)) {
            throw new RuntimeException('This announcement has lapsed: announce it again');
        }
        $box = $this->releasedBox($escrowId, $outerBox);
        $this->releases[bin2hex($escrowId)][] = ['case' => $caseRef, 'seq' => $seq, 'at' => $this->time(), 'box' => KeeperSeal::boxPrint($outerBox)];
        return $box['inner'];
    }

    public function attest(string $escrowId, string $nonce): string
    {
        $this->answer();
        $hex = bin2hex($escrowId);
        $pending = [];
        foreach ($this->pending as $key => $held) {
            $objected = isset($this->objections[$hex . '|' . $held['case']]);
            if (str_starts_with($key, $hex . '|') && ($objected || !$this->lapsed($held))) {
                $pending[] = ['case' => $held['case'], 'due' => $held['due'], 'objected' => $objected, 'box' => $held['box']];
            }
        }
        return KeeperSeal::attestation($escrowId, $nonce, $this->releases[$hex] ?? [], $pending, $this->signSecret, isset($this->retired[$hex]), $this->consents[$hex] ?? []);
    }

    public function object(string $escrowId, string $caseRef, string $token): void
    {
        $this->answer();
        if (!$this->caseHeld($escrowId, $caseRef)) {
            throw new RuntimeException('No read of this escrow is announced for this case');
        }
        if (!KeeperSeal::verifyObjection($token, $escrowId, $caseRef)) {
            throw new RuntimeException('This objection is not signed by the key this escrow is named after');
        }
        $this->objections[bin2hex($escrowId) . '|' . $caseRef] = true;
    }

    public function retire(string $escrowId, string $token): void
    {
        $this->answer();
        if (!KeeperSeal::verifyRetirement($token, $escrowId, KeeperSeal::fingerprint($this->publicKeys()))) {
            throw new RuntimeException('This retirement is not signed, for this keeper, by the key this escrow is named after');
        }
        $this->retired[bin2hex($escrowId)] = true;
    }

    public function consent(string $escrowId, string $caseRef, string $token): void
    {
        $this->answer();
        $hex = bin2hex($escrowId);
        if (!$this->consentVerifies($token, $escrowId, $caseRef)) {
            throw new RuntimeException('This consent is not signed, for this keeper, by the key this escrow is named after');
        }
        if (!in_array($caseRef, array_column($this->consents[$hex] ?? [], 'case'), true)) {
            $this->consents[$hex][] = ['case' => $caseRef, 'at' => $this->time()];
        }
    }

    /** Bench: whether the holder consented to $caseRef of the escrow $escrowIdHex. */
    public function consentedTo(string $escrowIdHex, string $caseRef): bool
    {
        return in_array($caseRef, array_column($this->consents[$escrowIdHex] ?? [], 'case'), true);
    }

    /** Bench: every box announced for $caseRef of the escrow $escrowIdHex falls due. Throws when none is. */
    public function dueNow(string $escrowIdHex, string $caseRef): void
    {
        $keys = $this->keysOfCase($escrowIdHex, $caseRef);
        if ($keys === []) {
            throw new LogicException("No box of {$escrowIdHex} is announced for {$caseRef}: the bench sets nothing due");
        }
        foreach ($keys as $key) {
            $this->pending[$key]['due'] = time() - 1;
        }
    }

    /** Bench: whether the holder objected to $caseRef of the escrow $escrowIdHex. */
    public function objectedTo(string $escrowIdHex, string $caseRef): bool
    {
        return isset($this->objections[$escrowIdHex . '|' . $caseRef]);
    }

    /** Bench: whether a box of the escrow $escrowIdHex was announced for $caseRef. */
    public function announced(string $escrowIdHex, string $caseRef): bool
    {
        return $this->keysOfCase($escrowIdHex, $caseRef) !== [];
    }

    /** Bench: the keeper forgets every announcement of $caseRef, and its objection. */
    public function forget(string $escrowIdHex, string $caseRef): void
    {
        foreach ($this->keysOfCase($escrowIdHex, $caseRef) as $key) {
            unset($this->pending[$key]);
        }
        unset($this->objections[$escrowIdHex . '|' . $caseRef]);
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

    /** @return array{escrowId: string, userId: string, objection: ?string, inner: string} the box a release hands back */
    protected function releasedBox(string $escrowId, string $outerBox): array
    {
        return $this->boxOf($escrowId, $outerBox);
    }

    protected function consentVerifies(string $token, string $escrowId, string $caseRef): bool
    {
        return KeeperSeal::verifyConsent($token, $escrowId, $caseRef, KeeperSeal::fingerprint($this->publicKeys()));
    }

    /** Where the announcement of $outerBox for $caseRef of $escrowId is held. */
    protected function keyOf(string $escrowId, string $caseRef, string $outerBox): string
    {
        return bin2hex($escrowId) . '|' . $caseRef . '|' . KeeperSeal::boxPrint($outerBox);
    }

    protected function objected(string $escrowId, string $caseRef): bool
    {
        return isset($this->objections[bin2hex($escrowId) . '|' . $caseRef]);
    }

    /** Whether a box of $escrowId is announced for $caseRef and still held: objected to, or not lapsed. */
    protected function caseHeld(string $escrowId, string $caseRef): bool
    {
        if ($this->objected($escrowId, $caseRef)) {
            return $this->keysOfCase(bin2hex($escrowId), $caseRef) !== [];
        }
        foreach ($this->keysOfCase(bin2hex($escrowId), $caseRef) as $key) {
            if (!$this->lapsed($this->pending[$key])) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> the keys of every announcement of $caseRef of the escrow $escrowIdHex */
    protected function keysOfCase(string $escrowIdHex, string $caseRef): array
    {
        $keys = [];
        foreach ($this->pending as $key => $held) {
            if (str_starts_with((string) $key, $escrowIdHex . '|') && $held['case'] === $caseRef) {
                $keys[] = (string) $key;
            }
        }
        return $keys;
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
        $this->locked(static fn () => null);
    }

    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, int $seq, string $hmac): int
    {
        return $this->locked(fn (): int => parent::announce($escrowId, $outerBox, $userId, $caseRef, $seq, $hmac));
    }

    public function release(string $escrowId, string $outerBox, string $caseRef, int $seq, string $hmac): string
    {
        return $this->locked(fn (): string => parent::release($escrowId, $outerBox, $caseRef, $seq, $hmac));
    }

    public function attest(string $escrowId, string $nonce): string
    {
        return $this->locked(fn (): string => parent::attest($escrowId, $nonce));
    }

    public function object(string $escrowId, string $caseRef, string $token): void
    {
        $this->locked(fn () => parent::object($escrowId, $caseRef, $token));
    }

    public function retire(string $escrowId, string $token): void
    {
        $this->locked(fn () => parent::retire($escrowId, $token));
    }

    public function consent(string $escrowId, string $caseRef, string $token): void
    {
        $this->locked(fn () => parent::consent($escrowId, $caseRef, $token));
    }

    /** $op on the state read from the file, written back, under one lock: two processes never lose one another's record. */
    private function locked(callable $op): mixed
    {
        $lock = fopen($this->file . '.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            if (is_file($this->file)) {
                $this->load();
            }
            try {
                return $op();
            } finally {
                $this->save();
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function load(): void
    {
        $d = json_decode((string) file_get_contents($this->file), true, flags: JSON_THROW_ON_ERROR);
        [$this->boxPublic, $this->boxSecret, $this->signPublic, $this->signSecret] = array_map(
            static fn (string $k): string => (string) base64_decode($k, true),
            [$d['boxPublic'], $d['boxSecret'], $d['signPublic'], $d['signSecret']]
        );
        $this->pending    = $d['pending'];
        $this->objections = $d['objections'];
        $this->consents   = $d['consents'];
        $this->releases   = $d['releases'];
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
            'objections' => (object) $this->objections,
            'consents'   => (object) $this->consents,
            'releases'   => (object) $this->releases,
            'keyed'      => (object) $this->keyed,
            'retired'    => (object) $this->retired,
            'delay'      => $this->delay,
        ], JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
