<?php
/**
 * SelfRecover demo — Login.
 *
 * POST /demo/api/recover/login
 *   body: { "username": "alice", "password": "..." }
 *   → bcrypt_verify → crée app_session → cookie sr_app_session
 *
 * Rate-limit interne par username : après 5 tentatives échouées en 5 min,
 * on simule un lockout de 60 s.
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
$username = is_array($body) ? (string) ($body['username'] ?? '') : '';
$password = is_array($body) ? (string) ($body['password'] ?? '') : '';

$log = $s->logger();
$log->info('login', 'POST /demo/api/recover/login');
$log->info('login', 'Body parsed', ['username' => $username, 'password' => '[HIDDEN ' . strlen($password) . ' chars]']);

if (!preg_match('/^[a-z0-9]{3,20}$/', $username) || $password === '') {
    $log->warning('login', 'Champ manquant ou invalide');
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_fields','message'=>'Identifiant ou mot de passe manquant.']);
    exit;
}

$db = $s->db();

// Check lockout (5 échecs en 5 min)
$stmt = $db->prepare('
    SELECT COUNT(*) as c FROM login_attempts
    WHERE username = :u AND success = 0 AND attempted_at >= :since
');
$stmt->bindValue(':u', $username);
$stmt->bindValue(':since', time() - 300);
$fails = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if (is_array($fails) && (int) $fails['c'] >= 5) {
    $log->warning('login', 'Lockout applicatif — 5 échecs dans les 5 dernières minutes', ['username' => $username]);
    http_response_code(429);
    echo json_encode(['ok'=>false,'error'=>'too_many_attempts','message'=>"Trop de tentatives échouées. Patiente 60s (ou utilise la récupération par passphrase)."]);
    exit;
}

// Fetch account
$stmt = $db->prepare('SELECT id, pw_hash FROM accounts WHERE username = :u');
$stmt->bindValue(':u', $username);
$account = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

$log->info('login', 'SELECT account', ['found' => is_array($account)]);

$success = false;
if (is_array($account) && password_verify($password, $account['pw_hash'])) {
    $success = true;
}

$stmt = $db->prepare('INSERT INTO login_attempts (username, success, attempted_at) VALUES (:u, :s, :t)');
$stmt->bindValue(':u', $username);
$stmt->bindValue(':s', $success ? 1 : 0);
$stmt->bindValue(':t', time());
$stmt->execute();

if (!$success) {
    $log->warning('login', 'bcrypt_verify KO', ['username' => $username]);
    // Delai constant pour éviter timing attack (au moins ~200 ms)
    usleep(200000);
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'bad_credentials','message'=>'Identifiant ou mot de passe incorrect.']);
    exit;
}

$log->crypto('login', 'bcrypt_verify(password, stored_hash) → true');

// Create app session
$token = RecoverHelper::generateSessionToken();
$stmt = $db->prepare('INSERT INTO app_sessions (account_id, token, created_at) VALUES (:a, :t, :at)');
$stmt->bindValue(':a', $account['id']);
$stmt->bindValue(':t', $token);
$stmt->bindValue(':at', time());
$stmt->execute();

$log->info('login', 'App session créée', ['account_id' => $account['id']]);
RecoverHelper::setAppSessionCookie($token);
$log->info('login', 'Cookie sr_app_session posé (HttpOnly, Secure, SameSite=Lax)');
$log->success('login', 'HTTP 200 — logged in');

echo json_encode([
    'ok'         => true,
    'account_id' => (int) $account['id'],
    'username'   => $username,
]);
