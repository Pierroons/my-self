<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/dm.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\DM;

require_method('POST');
require_csrf();
$pdo = Db::pdo();
$acc = require_auth($pdo);
$body = json_in();

$result = DM::send(
    $pdo,
    (int) $acc['id'],
    (string) ($body['destinataire'] ?? ''),
    (string) ($body['message'] ?? '')
);
json_out($result, $result['ok'] ? 201 : 400);
