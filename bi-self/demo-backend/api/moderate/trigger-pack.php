<?php
/**
 * SelfModerate demo — Déclenche une attaque pack-voting simulée.
 *
 * POST /demo/api/moderate/trigger-pack
 *   body: { "target_id": <int> }
 *   → 3 bots (charlie, dave, et un autre) votent -1 coordonnés sur la cible
 *   → appel detect-abuse qui annule les votes
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

$body = json_decode((string) file_get_contents('php://input'), true);
$targetId = (int) ($body['target_id'] ?? 0);
if ($targetId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid_target']); exit; }

$log = $s->logger();
$db = $s->db();

// Cible : on la récupère pour log
$stmt = $db->prepare('SELECT username, reputation FROM users WHERE id = :id');
$stmt->bindValue(':id', $targetId);
$target = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if (!is_array($target)) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'target_not_found']); exit; }

$log->warning('trigger-pack', "Simulation d'attaque pack-voting contre {$target['username']}", ['target_id' => $targetId]);

// Les 3 attaquants : charlie, dave, + un 3e bot quelconque (alice par exemple)
$attackers = ['@charlie_upvoter', '@dave_pack', '@alice_toxique'];
$packVotes = [];

foreach ($attackers as $botName) {
    $stmt = $db->prepare('SELECT id FROM users WHERE username = :u AND is_bot = 1');
    $stmt->bindValue(':u', $botName);
    $bot = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!is_array($bot)) continue;
    $botId = (int) $bot['id'];
    if ($botId === $targetId) continue; // un bot ne vote pas contre lui-même

    // Assure une invitation active bot↔target
    $stmt = $db->prepare('SELECT id FROM invitations WHERE ((from_user = :a AND to_user = :b) OR (from_user = :b AND to_user = :a)) LIMIT 1');
    $stmt->bindValue(':a', $botId);
    $stmt->bindValue(':b', $targetId);
    $inv = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!is_array($inv)) {
        $stmt = $db->prepare('INSERT INTO invitations (from_user, to_user, accepted_at) VALUES (:a, :b, :t)');
        $stmt->bindValue(':a', $botId);
        $stmt->bindValue(':b', $targetId);
        $stmt->bindValue(':t', time() - 60);
        $stmt->execute();
        $invId = $db->lastInsertRowID();
        $log->info('trigger-pack', "Invitation {$botName} ↔ {$target['username']} créée à la volée pour permettre le vote");
    } else {
        $invId = (int) $inv['id'];
    }

    $log->info('trigger-pack', "{$botName} vote -1 sur {$target['username']}");
    $res = ModerateHelper::applyVote($s, $invId, $botId, $targetId, -1, 'vote coordonné simulé');
    if (!$res['ok'] || !empty($res['blocked'])) {
        $log->warning('trigger-pack', "Vote {$botName} non appliqué", $res);
        continue;
    }
    $packVotes[] = ['voter' => $botName, 'new_reputation' => $res['new_reputation']];
    usleep(200000); // 200 ms entre chaque → reste dans la fenêtre pack
}

// Maintenant détection pack-voting
$detection = ModerateHelper::detectPackVoting($s);

echo json_encode([
    'ok'        => true,
    'attackers' => $packVotes,
    'detection' => $detection,
    'message'   => $detection['pack_detected']
        ? 'Pack-voting détecté et neutralisé : ' . $detection['cancelled_votes'] . ' votes annulés, réputation restaurée.'
        : 'Votes appliqués, pas de pack-voting détecté (coïncidence éparpillée ?).',
]);
