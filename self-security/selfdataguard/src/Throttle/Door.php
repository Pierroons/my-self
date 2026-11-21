<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Throttle;

/**
 * The doors of the façade where a secret from outside costs an Argon2id: the
 * name the throttle receives with the userId.
 */
enum Door: string
{
    case LoginPassword  = 'login-password';
    case LoginMemorized = 'login-memorized';
    case Recover        = 'recover';
    case OpenArchive    = 'open-archive';
}
