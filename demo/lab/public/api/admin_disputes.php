<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\RecoverL3;

$pdo = Db::pdo();
require_admin($pdo); // session-compte is_admin, sinon 403
json_out(RecoverL3::adminList($pdo));
