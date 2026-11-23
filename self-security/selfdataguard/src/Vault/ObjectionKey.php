<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Escrow\KeeperSeal;
use RuntimeException;

/**
 * The objection key of a vault, or of one of its archives — and nothing else.
 * SelfDataGuard::openForObjection() opens it from one of its secrets, without
 * a session and whatever the seal says, then wipes the master key.
 *
 * It signs an objection to a read a keeper holds announced. It reads no field,
 * signs no retirement, and no method of the façade that reads or writes a
 * field accepts it; escrowReads() and objectToEscrowRead() do.
 *
 * It keeps what the escrow row said when it was opened — the keeper the row
 * pins under the master key, or why it pins none —, since the master key is
 * no longer there to open the row again.
 *
 * Built by the façade alone: its constructor is private.
 *
 * NEVER serialize, log, or persist this object.
 */
final class ObjectionKey
{
    private string $seed;
    private bool $locked = false;

    /**
     * @param ?array{sign: string, box: string} $pinned the keeper the escrow row pinned when it was opened
     * @param ?string $row why the row pinned none: absent, not-keeper-sealed, not-pinned, escrow-replaced, unreadable
     */
    private function __construct(
        public readonly string $userId,
        public readonly ?string $archiveId,
        #[\SensitiveParameter] string $seed,
        private readonly ?array $pinned,
        private readonly ?string $row
    ) {
        $this->seed = $seed;
    }

    /** The escrowId of this escrow under a keeper: the fingerprint of the objection key. */
    public function escrowId(): string
    {
        return KeeperSeal::escrowIdOfSeed($this->seed());
    }

    /** The holder's objection to the read of this escrow for $caseRef (KeeperSeal::verifyObjection()). */
    public function signEscrowObjection(string $caseRef): string
    {
        return KeeperSeal::tokenOfSeed(KeeperSeal::objectionMessage($this->escrowId(), $caseRef), $this->seed());
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function lock(): void
    {
        if (!$this->locked) {
            Primitives::zeroize($this->seed);
            $this->locked = true;
        }
    }

    public function __destruct()
    {
        $this->lock();
    }

    private function seed(): string
    {
        if ($this->locked) {
            throw new RuntimeException('ObjectionKey is locked — open it again from a secret');
        }
        return $this->seed;
    }

    public function __serialize(): array
    {
        throw new RuntimeException('ObjectionKey must not be serialized');
    }

    public function __unserialize(array $data): void
    {
        throw new RuntimeException('ObjectionKey must not be unserialized');
    }

    public function __clone()
    {
        throw new RuntimeException('ObjectionKey must not be cloned');
    }

    /** @return array{userId: string, archiveId: ?string, locked: bool} */
    public function __debugInfo(): array
    {
        return ['userId' => $this->userId, 'archiveId' => $this->archiveId, 'locked' => $this->locked];
    }
}
