<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/admin.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Admin;

require_method('POST');
$pdo = Db::pdo();
$acc = require_admin($pdo);
require_csrf();

$body = json_in();
$r = Admin::requestPromotion(
    $pdo,
    (string) $acc['username'],   // le demandeur vient de la session, jamais du corps
    (string) ($body['target'] ?? ''),
    (string) ($body['reason'] ?? '')
);
json_out($r, $r['ok'] ? 200 : 400);
