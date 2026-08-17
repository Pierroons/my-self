<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);

$body      = json_input();
$userId    = (string) ($body['userId']    ?? '');
$password  = (string) ($body['password']  ?? '');
$memorized = isset($body['memorized']) ? (string) $body['memorized'] : null;
$fields    = is_array($body['fields'] ?? null) ? $body['fields'] : [];
$indexed   = is_array($body['indexed'] ?? null) ? $body['indexed'] : ['email'];

if ($userId === '' || $password === '') fail('userId and password are required');
if (strlen($password) < 12) fail('Password must be ≥12 characters (whitepaper §7)');

try {
    $session = $dataGuard->register($userId, $password, $memorized === '' ? null : $memorized);
    if ($fields !== []) {
        // Coerce keys/values to strings
        $clean = [];
        foreach ($fields as $k => $v) {
            $clean[(string) $k] = (string) $v;
        }
        $dataGuard->setFields($session, $clean, $indexed);
    }
    ok(['userId' => $userId]);
} catch (RuntimeException $e) {
    fail($e->getMessage(), 409);
}
