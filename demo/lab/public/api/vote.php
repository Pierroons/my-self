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
// La réponse est la même pour tout vote qui passe : `ok` et le message. `blocked`,
// sa raison ou le score à jour diraient au votant ce qu'en a fait le moteur — et,
// pour un vote croisé, que sa cible venait de voter contre lui. Seuls les plafonds
// du votant ont leur message : ils portent sur sa propre conduite. Le score se lit
// au rechargement.
json_out(['ok' => (bool) $result['ok'], 'message' => (string) $result['message']], $result['ok'] ? 200 : 400);
