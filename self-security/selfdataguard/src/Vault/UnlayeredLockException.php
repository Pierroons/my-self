<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

/**
 * A lock the pepper covers holds an envelope without it, and the upgrade was
 * declared over (PepperForms::LayeredOnly): a row put back from a copy taken
 * before the operator's pass, or written by an older version since.
 *
 * To whoever asks, it is a wrong secret — same message, after the same
 * derivation. The class tells it apart for the operator's log: the row is an
 * incident to look at, and `bin/pepper.php status` names the others.
 */
final class UnlayeredLockException extends WrongSecretException
{
}
