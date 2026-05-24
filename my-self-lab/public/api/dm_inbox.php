<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/dm.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\DM;

require_method('GET');
$pdo = Db::pdo();
$acc = require_auth($pdo);

$inbox = DM::inbox($pdo, (int) $acc['id']);
DM::markRead($pdo, (int) $acc['id']);
json_out(['ok' => true, 'inbox' => $inbox]);
