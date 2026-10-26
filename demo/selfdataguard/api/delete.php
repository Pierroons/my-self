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
    $session = $dataGuard->loginWithPassword($userId, $password);
} catch (RuntimeException $e) {
    fail_vault($e);
}

$dataGuard->delete($session->generation());
ok(['userId' => $userId, 'deleted' => true]);
