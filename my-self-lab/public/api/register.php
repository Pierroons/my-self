<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;

require_method('POST');
$body = json_in();
$username = (string) ($body['username'] ?? '');
// Clé dérivée dans le navigateur (HMAC) : le mot mémorisé ne transite jamais.
$recoveryDerivedKey = (string) ($body['recovery_derived_key'] ?? '');

$result = Auth::register(Db::pdo(), $username, $recoveryDerivedKey, client_ip());
json_out($result, $result['ok'] ? 201 : 400);
