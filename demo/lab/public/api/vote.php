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
$reason     = isset($body['reason']) ? (string) $body['reason'] : null;
$reasonCode = isset($body['reason_code']) ? (string) $body['reason_code'] : null;

$result = Moderate::applyVote($pdo, (int) $acc['id'], $targetType, $targetId, $value, $reason, $reasonCode);
// Le score à jour rafraîchit la page sans la recharger — pour un vote qui porte
// seulement : celui d'un vote tombé, inchangé, dirait au votant ce qu'en a fait le
// moteur. La raison reste au moteur et à l'arbitre.
if ($result['ok'] && empty($result['blocked'])) {
    $result['score'] = Moderate::score($pdo, $targetType, $targetId);
}
unset($result['blocked_reason']);
json_out($result, $result['ok'] ? 200 : 400);
