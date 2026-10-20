<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/moderate.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Moderate;

require_method('POST');
require_csrf();
$pdo = Db::pdo();
require_admin($pdo);   // le balayage de tout le site est un geste d'arbitre

$result = Moderate::detectPackVoting($pdo);
$result['flagged_for_review'] = Moderate::flaggedForReview($pdo);
json_out(['ok' => true] + $result);
