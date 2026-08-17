<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Device;

require_method('POST');
$body = json_in();
$r = Device::authFinish(
    Db::pdo(),
    (string) ($body['credential_id'] ?? ''),
    (string) ($body['challenge'] ?? ''),
    (string) ($body['signature'] ?? '')  // P1363 (r||s), base64url
);
json_out($r, $r['ok'] ? 200 : 400);
