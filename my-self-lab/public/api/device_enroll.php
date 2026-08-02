<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Device;

require_method('POST');
$body = json_in();
$r = Device::enroll(
    Db::pdo(),
    (string) ($body['username'] ?? ''),
    (string) ($body['credential_id'] ?? ''),
    (string) ($body['public_key'] ?? ''),           // SPKI DER, base64url
    (string) ($body['memorized_derived_key'] ?? ''), // HMAC du mot mémorisé, dérivé côté client
    client_ip()
);
json_out($r, $r['ok'] ? 200 : 400);
