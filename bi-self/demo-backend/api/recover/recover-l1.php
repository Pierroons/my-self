<?php
/**
 * SelfRecover demo — Recovery L1 (passphrase).
 *
 * POST /demo/api/recover/recover-l1
 *   body: { "username": "alice", "passphrase": "four words here plz" }
 *   → bcrypt_verify(passphrase, pass_hash) → génère nouveau password → update
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

$body = json_decode((string) file_get_contents('php://input'), true);
$username   = is_array($body) ? (string) ($body['username'] ?? '') : '';
$passphrase = is_array($body) ? (string) ($body['passphrase'] ?? '') : '';

$log = $s->logger();
$log->info('recover-l1', 'POST /demo/api/recover/recover-l1');
$log->info('recover-l1', 'Body parsed', [
    'username'   => $username,
    'passphrase' => '[HIDDEN ' . strlen($passphrase) . ' chars, ' . str_word_count($passphrase) . ' words]',
]);

if (!preg_match('/^[a-z0-9]{3,20}$/', $username) || strlen($passphrase) < 4) {
    $log->warning('recover-l1', 'Champs invalides');
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_fields']);
    exit;
}

$db = $s->db();
$stmt = $db->prepare('SELECT id, pass_hash FROM accounts WHERE username = :u');
$stmt->bindValue(':u', $username);
$account = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

if (!is_array($account)) {
    $log->warning('recover-l1', 'Compte introuvable', ['username' => $username]);
    usleep(200000); // timing defense
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'bad_credentials','message'=>'Passphrase incorrecte ou compte inconnu.']);
    exit;
}

$t0 = microtime(true);
$ok = password_verify($passphrase, $account['pass_hash']);
$t1 = microtime(true);
$log->crypto('recover-l1', 'bcrypt_verify(passphrase, stored_pass_hash)', [
    'duration_ms' => (int) (($t1 - $t0) * 1000),
    'result'      => $ok ? 'match' : 'no_match',
]);

if (!$ok) {
    $log->warning('recover-l1', 'Passphrase KO', ['username' => $username]);
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'bad_credentials','message'=>'Passphrase incorrecte.']);
    exit;
}

// Génère un nouveau password
$newPassword = RecoverHelper::generatePassword(16);
$log->info('recover-l1', 'Nouveau password généré (remplace l\'ancien)');

$t2 = microtime(true);
$newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
$t3 = microtime(true);
$log->crypto('recover-l1', 'bcrypt(new_password, cost=12)', ['duration_ms' => (int) (($t3 - $t2) * 1000)]);

$stmt = $db->prepare('UPDATE accounts SET pw_hash = :h WHERE id = :id');
$stmt->bindValue(':h', $newHash);
$stmt->bindValue(':id', $account['id']);
$stmt->execute();
$log->info('recover-l1', 'UPDATE accounts SET pw_hash = ? WHERE id = ?', ['account_id' => $account['id']]);

// Invalider les app_sessions existantes (tokens actifs révoqués)
$stmt = $db->prepare('DELETE FROM app_sessions WHERE account_id = :id');
$stmt->bindValue(':id', $account['id']);
$stmt->execute();
$log->info('recover-l1', 'Toutes les app_sessions précédentes ont été invalidées', ['revoked' => $db->changes()]);

$log->success('recover-l1', 'HTTP 200 — nouveau password livré à l\'user');

echo json_encode([
    'ok'           => true,
    'username'     => $username,
    'new_password' => $newPassword,
    'note'         => "Note ton nouveau password. Tu dois te reconnecter explicitement avec. Toutes tes anciennes sessions ouvertes ont été déconnectées automatiquement.",
]);
