<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\RecoverL3;

require_method('POST');
$body = json_in();
$r = RecoverL3::init(
    Db::pdo(),
    (string) ($body['username'] ?? ''),
    (string) ($body['claim_hash'] ?? ''), // SHA-256 du sésame généré côté client
    client_ip()
);
json_out($r, $r['ok'] ? 200 : (int) ($r['code'] ?? 400));
