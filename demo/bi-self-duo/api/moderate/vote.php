<?php
/**
 * SelfModerate demo — Vote sur un user via une invitation acceptée.
 *
 * POST /demo/api/moderate/vote
 *   body: { "target_id": <int>, "value": -1|1, "reason": "toxique" }
 *   → utilise l'invitation existante entre le visiteur et la cible
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/session_manager.php';
require_once __DIR__ . '/../../lib/moderate_helper.php';

header('Content-Type: application/json; charset=utf-8');

$s = DemoSession::current();
if ($s === null) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'no_session']); exit; }

if (!RateLimit::checkAndIncrementActions($s->dir)) {
    http_response_code(429);
    echo json_encode(['ok'=>false,'error'=>'quota_exceeded']);
    exit;
}

$visitorId = (int) ($_COOKIE['sm_visitor_id'] ?? 0);
if ($visitorId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'no_identity','message'=>'Crée d\'abord ton identité (étape 1).']); exit; }

$body = json_decode((string) file_get_contents('php://input'), true);
$targetId = (int) ($body['target_id'] ?? 0);
$value    = (int) ($body['value'] ?? 0);
$reason   = (string) ($body['reason'] ?? '');

if ($targetId <= 0 || ($value !== -1 && $value !== 1)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_fields']);
    exit;
}

$log = $s->logger();
$log->info('vote', 'POST /demo/api/moderate/vote', [
    'voter_id' => $visitorId, 'target_id' => $targetId, 'value' => $value, 'reason' => $reason,
]);

// Voter doit avoir des droits de vote
$db = $s->db();
$stmt = $db->prepare('SELECT voting_rights, reputation FROM users WHERE id = :id');
$stmt->bindValue(':id', $visitorId);
$voter = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if (!is_array($voter) || !$voter['voting_rights']) {
    $log->warning('vote', 'Voter sans droit de vote', ['voter_id' => $visitorId]);
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'no_voting_rights','message'=>'Ta réputation est trop basse, tu as perdu ton droit de vote.']);
    exit;
}

// Trouve une invitation active voter↔target
$stmt = $db->prepare('SELECT id FROM invitations WHERE ((from_user = :v AND to_user = :t) OR (from_user = :t AND to_user = :v)) ORDER BY accepted_at DESC LIMIT 1');
$stmt->bindValue(':v', $visitorId);
$stmt->bindValue(':t', $targetId);
$inv = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if (!is_array($inv)) {
    $log->warning('vote', 'Pas d\'invitation active pour voter sur cette cible', ['voter_id' => $visitorId, 'target_id' => $targetId]);
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'no_invitation','message'=>'Pas d\'invitation acceptée entre toi et cette cible.']);
    exit;
}

$result = ModerateHelper::applyVote($s, (int) $inv['id'], $visitorId, $targetId, $value, $reason);
if (!$result['ok']) {
    $log->warning('vote', 'Vote refusé', $result);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}

if (!empty($result['blocked'])) {
    echo json_encode([
        'ok' => true, 'blocked' => true, 'blocked_reason' => $result['blocked_reason'],
        'message' => 'Vote enregistré mais bloqué : ' . $result['blocked_reason'],
    ]);
    exit;
}

$log->success('vote', 'Vote appliqué', ['target_id' => $targetId, 'new_reputation' => $result['new_reputation']]);
echo json_encode(['ok' => true, 'blocked' => false, 'new_reputation' => $result['new_reputation']]);
