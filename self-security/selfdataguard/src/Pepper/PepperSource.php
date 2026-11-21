<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Pepper;

/**
 * Where the pepper lives: a file out of the database on this machine
 * (LocalPepperFile), or another machine that holds it and answers
 * computations only. Either way the database alone opens no lock the pepper
 * covers.
 */
interface PepperSource
{
    /**
     * HMAC-SHA256 under the pepper, 32 raw bytes, of Pepper::message($usage,
     * $data). A source on another machine is sent $usage and $data — a
     * pre-image of 32 bytes, never a secret of the user — and returns the
     * computation; it never falls back on anything when it cannot answer.
     *
     * @throws PepperUnavailableException when the source cannot answer now
     */
    public function mac(string $usage, string $data): string;
}
