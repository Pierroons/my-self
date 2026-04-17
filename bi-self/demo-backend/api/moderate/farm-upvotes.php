<?php
/**
 * SelfModerate demo — Simulation upvote farming.
 *
 * POST /demo/api/moderate/farm-upvotes
 *   body: { "target_id": <int> }
 *   → Fait le visiteur upvoter la cible 4 fois : les 3 premiers passent,
 *     le 4e est bloqué avec blocked_reason='upvote_farming'.
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
if ($targetId <= 0 || $targetId === $visitorId) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_target']);
    exit;
}

$log = $s->logger();
$db = $s->db();

$stmt = $db->prepare('SELECT username FROM users WHERE id = :id');
$stmt->bindValue(':id', $targetId);
$target = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if (!is_array($target)) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'target_not_found']); exit; }

$log->warning('farm-upvotes', "Simulation upvote farming : visiteur tente 4 upvotes successifs sur {$target['username']}", ['target_id' => $targetId]);

$attempts = [];
for ($i = 1; $i <= 4; $i++) {
    // Crée une nouvelle invitation pour chaque tentative (interactions successives)
    $stmt = $db->prepare('INSERT INTO invitations (from_user, to_user, accepted_at) VALUES (:v, :t, :at)');
    $stmt->bindValue(':v', $visitorId);
    $stmt->bindValue(':t', $targetId);
    $stmt->bindValue(':at', time() - (5 - $i) * 300); // étale sur les 25 dernières minutes
    $stmt->execute();
    $invId = $db->lastInsertRowID();

    $log->info('farm-upvotes', "Tentative #{$i} : upvote +1", ['invitation_id' => $invId]);

    $res = ModerateHelper::applyVote($s, $invId, $visitorId, $targetId, 1, "upvote farming #{$i}");

    $blocked = !empty($res['blocked']);
    $attempts[] = [
        'attempt' => $i,
        'ok' => $res['ok'],
        'blocked' => $blocked,
        'blocked_reason' => $res['blocked_reason'] ?? null,
        'new_reputation' => $res['new_reputation'] ?? null,
    ];
}

echo json_encode([
    'ok'       => true,
    'attempts' => $attempts,
    'message'  => 'Les 3 premiers upvotes passent. Le 4e est bloqué : upvote farming détecté (plus de 3 upvotes mutuels en 60 jours).',
]);
