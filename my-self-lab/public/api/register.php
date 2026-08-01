<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;

require_method('POST');
$body = json_in();
$username = (string) ($body['username'] ?? '');
$recoveryWord = (string) ($body['recovery_word'] ?? '');

$result = Auth::register(Db::pdo(), $username, $recoveryWord, client_ip());
json_out($result, $result['ok'] ? 201 : 400);
