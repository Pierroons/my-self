<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/moderate.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Moderate;

require_method('POST');
require_csrf();
$pdo = Db::pdo();
require_admin($pdo);   // R10-LAB-02 : sweep de modération réservé aux admins (était require_auth)

$result = Moderate::detectPackVoting($pdo);
$result['flagged_for_review'] = Moderate::flaggedForReview($pdo);   // R10-LAB-01 : membres à arbitrer (rep≤0 sans pack)
json_out(['ok' => true] + $result);
