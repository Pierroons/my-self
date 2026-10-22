<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use RuntimeException;

/**
 * The userId already has a live vault. Inserting one never replaces another:
 * a re-enrolment goes through replaceWithArchive(), which archives it first.
 */
final class VaultExistsException extends RuntimeException
{
}
