<?php
/**
 * SelfModerate demo — État de l'user humain + stats communauté.
 *
 * GET /demo/api/moderate/me
 *   → { visitor_id?, visitor_username?, visitor_reputation?, community_size, ... }
 *
 * Le visiteur est identifié par le cookie sm_visitor_id (posé par create-identity).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/session_manager.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$s = DemoSession::current();
if ($s === null) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'no_session']); exit; }

$visitorId = $_COOKIE['sm_visitor_id'] ?? '';
if (!ctype_digit((string) $visitorId)) $visitorId = '';

$db = $s->db();
$visitor = null;
if ($visitorId !== '') {
    $stmt = $db->prepare('SELECT id, username, reputation, voting_rights, banned_until, strikes FROM users WHERE id = :id AND is_bot = 0');
    $stmt->bindValue(':id', (int) $visitorId);
    $visitor = $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
}

$count = 0;
$row = $db->query('SELECT COUNT(*) as c FROM users')->fetchArray(SQLITE3_ASSOC);
if (is_array($row)) $count = (int) $row['c'];

echo json_encode([
    'ok'              => true,
    'visitor'         => $visitor ? [
        'id'            => (int) $visitor['id'],
        'username'      => $visitor['username'],
        'reputation'    => (int) $visitor['reputation'],
        'voting_rights' => (bool) $visitor['voting_rights'],
        'banned_until'  => (int) $visitor['banned_until'],
        'strikes'       => (int) $visitor['strikes'],
    ] : null,
    'community_size'    => $count,
    'session_expires_s' => max(0, $s->expiresAt - time()),
]);
