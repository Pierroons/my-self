<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/moderate.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Moderate;

require_method('POST');
require_csrf();
$pdo = Db::pdo();
$acc = require_auth($pdo);
$body = json_in();

$targetType = (string) ($body['target_type'] ?? '');
$targetId   = (int) ($body['target_id'] ?? 0);
$value      = (int) ($body['value'] ?? 0);

$result = Moderate::applyVote($pdo, (int) $acc['id'], $targetType, $targetId, $value);
if ($result['ok']) {
    // Renvoie le score à jour pour rafraîchir l'UI sans recharger
    $result['score'] = Moderate::score($pdo, $targetType, $targetId);
}
json_out($result, $result['ok'] ? 200 : 400);
