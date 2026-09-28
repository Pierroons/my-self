<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Pierroons\SelfDataGuard\Vault\UserVault;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);

$body        = json_input();
$userId      = (string) ($body['userId']      ?? '');
$oldPassword = (string) ($body['oldPassword'] ?? '');
$newPassword = (string) ($body['newPassword'] ?? '');

if ($userId === '' || $oldPassword === '' || $newPassword === '') {
    fail('userId, oldPassword and newPassword are required');
}
if (strlen($newPassword) < UserVault::PASSWORD_MIN_LEN) {
    fail('New password must be ≥' . UserVault::PASSWORD_MIN_LEN . ' characters');
}

try {
    $session = $dataGuard->loginWithPassword($userId, $oldPassword);
    $dataGuard->changePassword($session, $newPassword);
    ok(['userId' => $userId]);
} catch (InvalidArgumentException $e) {
    fail($e->getMessage(), 400);
} catch (RuntimeException $e) {
    fail('Authentication failed', 401);
}
