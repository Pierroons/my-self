<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use RuntimeException;

/**
 * A façade given RecoveryTickets refuses a re-enrolment, a reinstatement, a
 * freeze or an unfreeze without a ticket of the right kind, issued for this
 * userId — before any Argon2id. With no RecoveryTickets given, it never
 * throws this.
 */
final class RecoveryTicketException extends RuntimeException
{
}
