<?php
/**
 * SelfRecover demo — État de connexion.
 *
 * GET /demo/api/recover/me
 *   → 200 { logged_in: bool, username?: string, account_id?: int, accounts_count: int }
 *
 * Pas de rate-limit (requête légère, souvent appelée par le frontend pour refresh UI).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/session_manager.php';
require_once __DIR__ . '/../../lib/recover_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$s = DemoSession::current();
if ($s === null) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'no_session']); exit; }

$account = RecoverHelper::getLoggedAccount($s);

$count = 0;
$db = $s->db();
$row = $db->query('SELECT COUNT(*) as c FROM accounts')->fetchArray(SQLITE3_ASSOC);
if (is_array($row)) $count = (int) $row['c'];

echo json_encode([
    'ok'              => true,
    'logged_in'       => $account !== null,
    'username'        => $account['username'] ?? null,
    'account_id'      => $account['id'] ?? null,
    'accounts_count'  => $count,
    'session_expires_s' => max(0, $s->expiresAt - time()),
]);
