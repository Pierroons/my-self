<?php
/**
 * SelfModerate demo — Drain de réputation pour démontrer l'escalade des sanctions.
 *
 * POST /demo/api/moderate/drain-reputation
 *   body: { "target_id": <int> }
 *   → Fait voter tous les bots (sauf la cible) -1 sur la cible jusqu'à rep 0
 *   → Déclenche dans l'ordre : perte voting_rights (rep<5), puis ban 24h (rep=0)
 *
 * Pour éviter le pack-voting trigger, on étale les votes sur plus de 60s
 * en utilisant un timestamp manuel par bot (triche sur created_at).
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

$stmt = $db->prepare('SELECT username, reputation FROM users WHERE id = :id');
$stmt->bindValue(':id', $targetId);
$target = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if (!is_array($target)) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'target_not_found']); exit; }

$log->warning('drain', "Drain de réputation simulé contre {$target['username']}", ['target_id' => $targetId, 'start_rep' => $target['reputation']]);

// Récupère tous les votants potentiels (bots + visiteur humain) sauf la cible
$voters = [];
$res = $db->query('SELECT id, username, voting_rights FROM users WHERE id != ' . $targetId . ' AND voting_rights = 1');
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $voters[] = ['id' => (int) $row['id'], 'username' => $row['username']];
}

if (empty($voters)) {
    $log->error('drain', 'Aucun votant disponible');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'no_voters']);
    exit;
}

$votesApplied = 0;
$finalRep = (int) $target['reputation'];

// On boucle jusqu'à rep=0 ou plus de votants disponibles
// Chaque vote espacé de ~70s en temps simulé (triche sur created_at) pour éviter pack-detect
$simulatedOffset = -7200; // on fait comme si les votes étaient anciens de 2h à -1 × N

for ($iter = 0; $iter < 30 && $finalRep > 0; $iter++) {
    foreach ($voters as $voter) {
        if ($finalRep <= 0) break;

        // Vérifie qu'on n'a pas déjà voté (limite : 1 vote par voter↔target)
        $stmt = $db->prepare('SELECT id FROM invitations WHERE ((from_user = :v AND to_user = :t) OR (from_user = :t AND to_user = :v)) LIMIT 1');
        $stmt->bindValue(':v', $voter['id']);
        $stmt->bindValue(':t', $targetId);
        $inv = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!is_array($inv)) {
            // Crée invitation si absente
            $stmt = $db->prepare('INSERT INTO invitations (from_user, to_user, accepted_at) VALUES (:a, :b, :t)');
            $stmt->bindValue(':a', $voter['id']);
            $stmt->bindValue(':b', $targetId);
            $stmt->bindValue(':t', time() + $simulatedOffset);
            $stmt->execute();
            $invId = $db->lastInsertRowID();
        } else {
            $invId = (int) $inv['id'];
        }

        // Vote déjà existant voter→target ? Si oui, crée nouvelle invitation + nouveau vote
        $stmt = $db->prepare('SELECT id FROM votes WHERE voter_id = :v AND target_id = :t AND value = -1');
        $stmt->bindValue(':v', $voter['id']);
        $stmt->bindValue(':t', $targetId);
        if ($stmt->execute()->fetchArray()) {
            // Nouvelle interaction pour permettre un nouveau vote
            $stmt = $db->prepare('INSERT INTO invitations (from_user, to_user, accepted_at) VALUES (:a, :b, :t)');
            $stmt->bindValue(':a', $voter['id']);
            $stmt->bindValue(':b', $targetId);
            $stmt->bindValue(':t', time() + $simulatedOffset);
            $stmt->execute();
            $invId = $db->lastInsertRowID();
        }

        $res2 = ModerateHelper::applyVote($s, $invId, $voter['id'], $targetId, -1, 'escalade simulée');
        if ($res2['ok'] && empty($res2['blocked'])) {
            $votesApplied++;
            $finalRep = $res2['new_reputation'] ?? $finalRep;
            $simulatedOffset += 90; // ~90s entre chaque vote pour éviter pack-detect
        }
    }
}

// État final de la cible
$stmt = $db->prepare('SELECT reputation, voting_rights, banned_until, strikes FROM users WHERE id = :id');
$stmt->bindValue(':id', $targetId);
$final = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

$log->success('drain', 'Drain terminé', [
    'votes_applied' => $votesApplied,
    'final_reputation' => (int) $final['reputation'],
    'voting_rights' => (bool) $final['voting_rights'],
    'strikes' => (int) $final['strikes'],
    'banned_until' => (int) $final['banned_until'],
]);

echo json_encode([
    'ok' => true,
    'votes_applied' => $votesApplied,
    'final' => [
        'reputation'    => (int) $final['reputation'],
        'voting_rights' => (bool) $final['voting_rights'],
        'banned_until'  => (int) $final['banned_until'],
        'strikes'       => (int) $final['strikes'],
    ],
    'message' => 'Escalade simulée. Regarde les logs pour voir chaque palier : perte vote à rep<5, ban temporaire à rep=0.',
]);
