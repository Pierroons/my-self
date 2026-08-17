<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\RecoverL3;

require_method('POST');
$pdo = Db::pdo();
require_admin($pdo);
require_csrf(); // action admin authentifiée → jeton CSRF requis

$body = json_in();
$r = RecoverL3::adminDecide(
    $pdo,
    (string) ($body['dispute_number'] ?? ''),
    (string) ($body['decision'] ?? '')
);
json_out($r, $r['ok'] ? 200 : (int) ($r['code'] ?? 400));
