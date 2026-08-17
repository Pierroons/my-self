<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/forum.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Forum;

require_method('POST');
require_csrf();
$pdo = Db::pdo();
$acc = require_auth($pdo);
$body = json_in();

$result = Forum::createThread(
    $pdo,
    (int) $acc['id'],
    (string) ($body['titre'] ?? ''),
    (string) ($body['categorie'] ?? 'general'),
    (string) ($body['message'] ?? '')
);
json_out($result, $result['ok'] ? 201 : 400);
