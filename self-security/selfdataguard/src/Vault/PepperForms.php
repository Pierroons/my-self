<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

/**
 * Which forms of the locks the pepper covers — wrap_pwd and wrap_recov — this
 * façade opens and seals. A configuration value, never a setting in the
 * database: whoever writes the database is whom the end of the upgrade
 * guards against, and would rewrite it.
 *
 * - AcceptBare: during the upgrade. A lock without the pepper still opens; a
 *   new envelope is a layer over one without the pepper, which
 *   `bin/pepper.php unlayer` takes off for a return to 0.9.x.
 * - LayeredOnly: once the upgrade is declared over. A lock without the pepper
 *   is refused, after the same derivation as a wrong secret; a new envelope
 *   takes the pepper into its Argon2id input, and no return to 0.9.x opens it.
 *   A new installation starts here.
 */
enum PepperForms
{
    case AcceptBare;
    case LayeredOnly;
}
