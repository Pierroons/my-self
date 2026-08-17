<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);

$body        = json_input();
$userId      = (string) ($body['userId']      ?? '');
$oldPassword = (string) ($body['oldPassword'] ?? '');
$newPassword = (string) ($body['newPassword'] ?? '');

if ($userId === '' || $oldPassword === '' || $newPassword === '') {
    fail('userId, oldPassword and newPassword are required');
}
if (strlen($newPassword) < 12) fail('New password must be ≥12 characters');

try {
    $session = $dataGuard->loginWithPassword($userId, $oldPassword);
    $dataGuard->changePassword($session, $newPassword);
    ok(['userId' => $userId]);
} catch (RuntimeException $e) {
    fail('Authentication failed', 401);
}
