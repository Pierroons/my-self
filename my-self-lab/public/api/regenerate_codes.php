<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;

require_method('POST'); // self-service : auth par mot mémorisé (rate-limit dans Auth::regenerateCodes)

$body = json_in();
$r = Auth::regenerateCodes(
    Db::pdo(),
    (string) ($body['username'] ?? ''),
    (string) ($body['memorized_derived_key'] ?? ''),
    client_ip()
);
json_out($r, $r['ok'] ? 200 : 400);
