<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

/**
 * An archive opened with one of its old locks — read-only.
 *
 * A distinct type from UnlockedVault, so that no write method of the façade
 * accepts it. The session inside carries the archive's own user_salt: even
 * handed to a write path by hand, the generation check refuses it, since the
 * live vault has another salt.
 *
 * NEVER serialize, log, or persist this object.
 */
final class UnlockedArchive
{
    public function __construct(
        public readonly ArchivedVault $archive,
        private readonly UnlockedVault $session
    ) {
    }

    public function userId(): string
    {
        return $this->session->userId;
    }

    /**
     * @internal for SelfDataGuard's read paths — decrypting the archived fields.
     */
    public function session(): UnlockedVault
    {
        return $this->session;
    }

    public function lock(): void
    {
        $this->session->lock();
    }

    public function __serialize(): array
    {
        throw new \RuntimeException('UnlockedArchive must not be serialized');
    }
}
