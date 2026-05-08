<?php

declare(strict_types=1);

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
    $fields = $dataGuard->getFields($session);
    ok(['userId' => $userId, 'mode' => $mode, 'fields' => $fields]);
} catch (RuntimeException $e) {
    fail('Authentication failed', 401);
}
