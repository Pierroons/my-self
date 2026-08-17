<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);

$body     = json_input();
$userId   = (string) ($body['userId']   ?? '');
$password = (string) ($body['password'] ?? '');

if ($userId === '' || $password === '') fail('userId and password are required');

try {
    // Verify ownership before deletion
    $dataGuard->loginWithPassword($userId, $password);
} catch (RuntimeException) {
    fail('Authentication failed', 401);
}

$dataGuard->delete($userId);
ok(['userId' => $userId, 'deleted' => true]);
