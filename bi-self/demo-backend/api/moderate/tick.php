<?php
/**
 * SelfModerate demo — Avance le temps simulé + purge les bans expirés.
 *
 * POST /demo/api/moderate/tick
 *   body: { "hours": <int> }  (1..8760, défaut 24)
 *   → Avance le simulated_time. Les users dont banned_until < simulated_time
 *     sortent de ban : reputation reset à 20, voting_rights restaurés,
 *     strikes conservés (sauf tick > 90j → reset total des strikes).
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
$hours = (int) ($body['hours'] ?? 24);
$hours = max(1, min(8760, $hours));

$log = $s->logger();
$db = $s->db();

// Avance simulated_time
$db->exec('UPDATE time_state SET simulated_time = simulated_time + ' . ($hours * 3600) . ', tick_count = tick_count + 1 WHERE id = 1');
$row = $db->query('SELECT simulated_time FROM time_state WHERE id = 1')->fetchArray(SQLITE3_ASSOC);
$simTime = (int) ($row['simulated_time'] ?? time());

$log->info('tick', sprintf('Temps avancé de %dh — nouveau now simulé : %s', $hours, date('Y-m-d H:i', $simTime)));

// Purge les bans expirés : ceux dont banned_until <= simTime ET < PHP_INT_MAX (non-permanents)
$unbanned = [];
$stmt = $db->prepare('SELECT id, username, strikes FROM users WHERE banned_until > 0 AND banned_until <= :now AND banned_until < :forever');
$stmt->bindValue(':now', $simTime);
$stmt->bindValue(':forever', PHP_INT_MAX);
$res = $stmt->execute();
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $unbanned[] = ['id' => (int) $r['id'], 'username' => $r['username'], 'strikes' => (int) $r['strikes']];
}

foreach ($unbanned as $u) {
    $stmt = $db->prepare('UPDATE users SET reputation = :rep, voting_rights = 1, banned_until = 0 WHERE id = :id');
    $stmt->bindValue(':rep', ModerateHelper::INITIAL_REPUTATION);
    $stmt->bindValue(':id', $u['id']);
    $stmt->execute();
    $log->info('tick', "Ban purgé pour {$u['username']} — reputation reset à 20, voting_rights restaurés, strikes conservés ({$u['strikes']})");
}

// Si tick >= 90j, reset TOTAL des strikes pour tous (règle "3 mois clean = reset total")
if ($hours >= 90 * 24) {
    $db->exec('UPDATE users SET strikes = 0');
    $log->success('tick', 'Tick >= 90 jours : reset total des strikes pour tous les users (règle "3 mois sans incident")');
}

echo json_encode([
    'ok'                   => true,
    'hours_advanced'       => $hours,
    'simulated_time'       => $simTime,
    'simulated_time_iso'   => date('c', $simTime),
    'unbanned_count'       => count($unbanned),
    'unbanned'             => $unbanned,
    'strikes_reset_global' => $hours >= 90 * 24,
]);
