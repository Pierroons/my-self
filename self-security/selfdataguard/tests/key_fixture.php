<?php

declare(strict_types=1);

use Pierroons\SelfDataGuard\Escrow\UnlockedEscrow;
use Pierroons\SelfDataGuard\Vault\UnlockedVault;

/**
 * The key an unlocked object holds, read past its visibility. The library hands
 * it out nowhere; a bench that checks a re-seal kept the same key, or plants a
 * ciphertext the way someone holding the key would, needs it. Fixtures only.
 */
function heldKey(UnlockedVault|UnlockedEscrow $unlocked): string
{
    return $unlocked instanceof UnlockedVault
        ? (fn (): string => $this->masterKey)->call($unlocked)
        : (fn (): string => $this->escrowKey)->call($unlocked);
}
