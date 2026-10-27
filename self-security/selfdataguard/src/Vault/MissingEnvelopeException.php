<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

/**
 * This vault was never sealed for the lock asked — no secret can open it that
 * way.
 *
 * To whoever asks, it is a wrong secret: a WrongSecretException, with the same
 * message, after the same derivation. A third party who only knows the name
 * would otherwise learn which locks an account carries, from the answer or from
 * its time. The class tells it apart for the integrator's own log; a reply that
 * differs on it gives the oracle back.
 */
final class MissingEnvelopeException extends WrongSecretException
{
}
