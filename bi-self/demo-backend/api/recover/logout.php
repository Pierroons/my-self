<?php
/**
 * SelfRecover demo — Logout.
 *
 * POST /demo/api/recover/logout
 *   → supprime la session app, efface le cookie
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/session_manager.php';
require_once __DIR__ . '/../../lib/recover_helper.php';

header('Content-Type: application/json; charset=utf-8');

$s = DemoSession::current();
if ($s === null) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'no_session']); exit; }

if (!RateLimit::checkAndIncrementActions($s->dir)) {
    http_response_code(429);
    echo json_encode(['ok'=>false,'error'=>'quota_exceeded']);
    exit;
}

$log = $s->logger();
$log->info('logout', 'POST /demo/api/recover/logout');

$token = $_COOKIE['sr_app_session'] ?? '';
if (preg_match('/^[a-f0-9]{48}$/', $token)) {
    $stmt = $s->db()->prepare('DELETE FROM app_sessions WHERE token = :t');
    $stmt->bindValue(':t', $token);
    $stmt->execute();
    $log->info('logout', 'DELETE FROM app_sessions', ['changes' => $s->db()->changes()]);
}

RecoverHelper::clearAppSessionCookie();
$log->info('logout', 'Cookie effacé');
$log->success('logout', 'HTTP 200 — logged out');

echo json_encode(['ok' => true]);
