<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\RecoverL3;

require_method('POST');
$body = json_in();
$answers = (isset($body['answers']) && is_array($body['answers'])) ? $body['answers'] : [];
$r = RecoverL3::submit(
    Db::pdo(),
    (string) ($body['dispute_number'] ?? ''),
    (string) ($body['claim_secret'] ?? ''),
    $answers,
    client_ip()
);
json_out($r, $r['ok'] ? 200 : (int) ($r['code'] ?? 400));
