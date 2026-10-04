<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Pierroons\SelfDataGuard\Vault\UserVault;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);

$body      = json_input();
$userId    = (string) ($body['userId']    ?? '');
$password  = (string) ($body['password']  ?? '');
$memorized = isset($body['memorized']) ? (string) $body['memorized'] : null;
$fields    = is_array($body['fields'] ?? null) ? $body['fields'] : [];
$indexed   = is_array($body['indexed'] ?? null) ? $body['indexed'] : ['email'];

if ($userId === '' || $password === '') fail('userId and password are required');
if (strlen($password) < UserVault::PASSWORD_MIN_LEN) {
    fail('Password must be ≥' . UserVault::PASSWORD_MIN_LEN . ' characters (whitepaper §7)');
}

$baseNeuve = !$baseExiste;
if ($baseNeuve) {
    $dataGuard = demo_base_neuve();
}

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
} catch (InvalidArgumentException $e) {
    // Un refus de la bibliothèque sur une entrée, pas un conflit : sans ce
    // catch, il sortait en 500 sans JSON.
    $baseNeuve && demo_effacer_base_neuve();
    fail($e->getMessage(), 400);
} catch (RuntimeException $e) {
    $baseNeuve && demo_effacer_base_neuve();
    fail($e->getMessage(), 409);
}
