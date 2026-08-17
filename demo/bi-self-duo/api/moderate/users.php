<?php
/**
 * SelfModerate demo — Liste des users (bots + visitor) avec scores.
 *
 * GET /demo/api/moderate/users
 *   → [{ id, username, is_bot, bot_profile, reputation, strikes, voting_rights, banned_until }, ...]
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/session_manager.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$s = DemoSession::current();
if ($s === null) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'no_session']); exit; }

$res = $s->db()->query('SELECT id, username, is_bot, bot_profile, reputation, strikes, voting_rights, banned_until FROM users ORDER BY id');
$users = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $users[] = [
        'id'            => (int) $row['id'],
        'username'      => $row['username'],
        'is_bot'        => (bool) $row['is_bot'],
        'bot_profile'   => $row['bot_profile'],
        'reputation'    => (int) $row['reputation'],
        'strikes'       => (int) $row['strikes'],
        'voting_rights' => (bool) $row['voting_rights'],
        'banned_until'  => (int) $row['banned_until'],
    ];
}

echo json_encode(['ok' => true, 'users' => $users]);
