<?php

declare(strict_types=1);

use Pierroons\SelfDataGuard\Escrow\AdminKey;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\StorageInterface;

/**
 * $publicKey recorded as the administrator key of $storage, as adoptEscrowAdmin()
 * records it once the key is proven — without the Argon2id of the proof, for the
 * benches that test something else than the adoption. Fixtures only.
 */
function benchAdminRecorded(StorageInterface $storage, string $publicKey): void
{
    $storage->claimSetting(SelfDataGuard::SETTING_ESCROW_ADMIN, AdminKey::fingerprint($publicKey));
}
