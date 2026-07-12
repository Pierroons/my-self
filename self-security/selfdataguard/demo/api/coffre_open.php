<?php

declare(strict_types=1);

/**
 * Ouvre le coffre d'un user : renvoie la ZONE PRIVÉE (E2E) + la ZONE ESCROW
 * (récup assistée), en une seule authentification (le master_key ne persiste
 * jamais côté serveur).
 */

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);

$body   = json_input();
$userId = (string) ($body['userId'] ?? '');
$secret = (string) ($body['secret'] ?? '');
$mode   = (string) ($body['mode']   ?? 'password');

if ($userId === '' || $secret === '') fail('userId and secret are required');

try {
    $session = match ($mode) {
        'password'  => $dataGuard->loginWithPassword($userId, $secret),
        'memorized' => $dataGuard->loginWithMemorized($userId, $secret),
        default     => fail('mode must be "password" or "memorized"'),
    };
    ok([
        'userId'    => $userId,
        'mode'      => $mode,
        'private'   => $dataGuard->getFields($session),
        'escrow'    => $dataGuard->getEscrowFieldsAsUser($session),
        'hasEscrow' => $dataGuard->hasEscrow($userId),
    ]);
} catch (RuntimeException $e) {
    fail('Authentication failed', 401);
}
