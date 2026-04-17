<?php
/**
 * SelfModerate demo — Crée l'identité du visiteur humain dans la communauté.
 *
 * POST /demo/api/moderate/create-identity
 *   body: { "username": "visitor123" } (optionnel, sinon auto-généré)
 *   → pose cookie sm_visitor_id, crée une invitation avec chaque bot pour
 *     permettre le vote ensuite.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/session_manager.php';

header('Content-Type: application/json; charset=utf-8');

$s = DemoSession::current();
if ($s === null) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'no_session']); exit; }

if (!RateLimit::checkAndIncrementActions($s->dir)) {
    http_response_code(429);
    echo json_encode(['ok'=>false,'error'=>'quota_exceeded']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
$username = is_array($body) ? (string) ($body['username'] ?? '') : '';
if (!preg_match('/^[a-z0-9_]{3,20}$/', $username)) {
    $username = 'visiteur_' . bin2hex(random_bytes(2));
}

$log = $s->logger();
$db = $s->db();

// Vérif qu'on n'a pas déjà un visiteur humain dans cette session
$row = $db->query('SELECT id, username FROM users WHERE is_bot = 0')->fetchArray(SQLITE3_ASSOC);
if (is_array($row)) {
    $log->info('create-identity', 'Visiteur déjà existant — renvoi identique', ['username' => $row['username']]);
    setcookie('sm_visitor_id', (string) $row['id'], [
        'expires'  => $s->expiresAt,
        'path'     => '/',
        'domain'   => 'bi-self.my-self.fr',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    echo json_encode(['ok' => true, 'visitor_id' => (int) $row['id'], 'username' => $row['username'], 'was_existing' => true]);
    exit;
}

$log->info('create-identity', 'Création du visiteur humain', ['username' => $username]);

$stmt = $db->prepare('INSERT INTO users (username, is_bot, bot_profile, reputation, created_at) VALUES (:u, 0, NULL, 20, :t)');
$stmt->bindValue(':u', '@' . $username);
$stmt->bindValue(':t', time());
$stmt->execute();
$visitorId = $db->lastInsertRowID();
$log->info('create-identity', 'INSERT users (visitor, reputation=20, voting_rights=1)', ['id' => $visitorId]);

// Pour pouvoir voter, le visiteur doit avoir des invitations acceptées avec les bots.
// On crée une invitation bidirectionnelle avec chacun des 5 bots.
$bots = $db->query('SELECT id, username FROM users WHERE is_bot = 1 ORDER BY id');
while ($b = $bots->fetchArray(SQLITE3_ASSOC)) {
    $stmt = $db->prepare('INSERT INTO invitations (from_user, to_user, accepted_at) VALUES (:v, :b, :t)');
    $stmt->bindValue(':v', $visitorId);
    $stmt->bindValue(':b', $b['id']);
    $stmt->bindValue(':t', time());
    $stmt->execute();
    $stmt = $db->prepare('INSERT INTO invitations (from_user, to_user, accepted_at) VALUES (:b, :v, :t)');
    $stmt->bindValue(':b', $b['id']);
    $stmt->bindValue(':v', $visitorId);
    $stmt->bindValue(':t', time());
    $stmt->execute();
    $log->info('create-identity', "Invitation acceptée : {$b['username']} ↔ @{$username}");
}

setcookie('sm_visitor_id', (string) $visitorId, [
    'expires'  => $s->expiresAt,
    'path'     => '/',
    'domain'   => 'bi-self.my-self.fr',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

$log->success('create-identity', 'Visiteur créé, identité active');

echo json_encode(['ok' => true, 'visitor_id' => $visitorId, 'username' => '@' . $username, 'was_existing' => false]);
