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
    /** @var array<string, array{case: string, due: int, objection: ?string, objected: bool}> by escrowId hex and case */
    public array $pending = [];

    /** @var array<string, list<array{case: string, seq: int, at: int}>> by escrowId hex */
    public array $releases = [];

    /** Below the first head a log writes, which is 0. */
    public int $lastSeq = -1;

    /** Throws on every call while true: a keeper that does not answer. */
    public bool $down = false;

    /** The time the keeper reads; a bench moves it. */
    public ?int $now = null;

    protected string $boxPublic;
    protected string $boxSecret;
    protected string $signPublic;
    protected string $signSecret;

    public function __construct(public int $delay = 0)
    {
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

    public function announce(string $escrowId, string $outerBox, string $userId, string $caseRef, bool $archive, int $seq, string $hmac): int
    {
        $this->answer();
        $box = KeeperSeal::open($outerBox, $this->boxSecret, $this->boxPublic);
        if (!hash_equals($box['escrowId'], $escrowId) || $box['userId'] !== $userId) {
            throw new RuntimeException('The box names another escrow, or another account, than the read announced');
        }
        $key = bin2hex($escrowId) . '|' . $caseRef;
        $this->pending[$key] ??= [
            'case'      => $caseRef,
            'due'       => $this->time() + ($archive ? 0 : $this->delay),
            'objection' => $box['objection'],
            'objected'  => false,
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
        if ($this->time() < $held['due']) {
            throw new RuntimeException('This read is not due yet');
        }
        if ($seq <= $this->lastSeq) {
            throw new RuntimeException("The log head {$seq} is not above the last one this keeper took ({$this->lastSeq})");
        }
        $box = KeeperSeal::open($outerBox, $this->boxSecret, $this->boxPublic);
        if (!hash_equals($box['escrowId'], $escrowId)) {
            throw new RuntimeException('The box names another escrow than the read announced');
        }
        $this->lastSeq = $seq;
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
            if (str_starts_with($key, $hex . '|')) {
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

    protected function time(): int
    {
        return $this->now ?? time();
    }

    private function answer(): void
    {
        if ($this->down) {
            throw new RuntimeException('The keeper does not answer');
        }
    }
}
