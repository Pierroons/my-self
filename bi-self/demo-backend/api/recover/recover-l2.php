<?php
/**
 * SelfRecover demo — Recovery L2 (mot de récupération + HMAC client-side).
 *
 * POST /demo/api/recover/recover-l2
 *   body: { "username": "alice", "derived_key": "4e7a9f...", "domain_used": "bi-self.my-self.fr" }
 *   → bcrypt_verify(derived_key, stored recovery_hash)
 *
 * Le recovery_word N'EST JAMAIS ENVOYÉ. Seul le derived_key (HMAC-SHA256
 * calculé par le navigateur) arrive au serveur. Si un phishing site pousse
 * le client à calculer le HMAC avec son propre domaine, le derived_key
 * sera complètement différent et le bcrypt_verify échouera → auth rejetée.
 *
 * On log domain_used en clair pour la pédagogie : tu vois que c'est bien
 * le domaine qu'a vu le navigateur qui a été utilisé pour le HMAC.
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
$username    = is_array($body) ? (string) ($body['username'] ?? '') : '';
$derivedKey  = is_array($body) ? (string) ($body['derived_key'] ?? '') : '';
$domainUsed  = is_array($body) ? (string) ($body['domain_used'] ?? '') : '';

$log = $s->logger();
$log->info('recover-l2', 'POST /demo/api/recover/recover-l2');
$log->info('recover-l2', 'Body parsed', [
    'username'    => $username,
    'derived_key' => $derivedKey,
    'domain_used' => $domainUsed,
    'note'        => "Le recovery_word brut n'est pas dans le body — seulement la clé HMAC dérivée par le navigateur. Le serveur n'a aucun moyen de le reconstituer.",
]);

if (!preg_match('/^[a-z0-9]{3,20}$/', $username)) {
    $log->warning('recover-l2', 'Username invalide');
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_username']);
    exit;
}
if (!preg_match('/^[a-f0-9]{64}$/', $derivedKey)) {
    $log->warning('recover-l2', 'derived_key mal formé (attendu: 64 chars hex SHA-256)', ['received' => $derivedKey]);
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_derived_key']);
    exit;
}

$db = $s->db();
$stmt = $db->prepare('SELECT id, recovery_hash FROM accounts WHERE username = :u');
$stmt->bindValue(':u', $username);
$account = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

if (!is_array($account)) {
    $log->warning('recover-l2', 'Compte introuvable', ['username' => $username]);
    usleep(200000);
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'bad_credentials','message'=>'Mot de récupération incorrect ou compte inconnu.']);
    exit;
}

$t0 = microtime(true);
$ok = password_verify($derivedKey, $account['recovery_hash']);
$t1 = microtime(true);

$log->crypto('recover-l2', 'bcrypt_verify(derived_key_received, stored_recovery_hash)', [
    'duration_ms' => (int) (($t1 - $t0) * 1000),
    'result'      => $ok ? 'match' : 'no_match',
    'legit_domain' => 'bi-self.my-self.fr',
    'domain_used_by_client' => $domainUsed,
]);

if (!$ok) {
    $hint = '';
    if ($domainUsed !== 'bi-self.my-self.fr') {
        $hint = " Le navigateur a calculé le HMAC avec le domaine '" . $domainUsed . "' au lieu de 'bi-self.my-self.fr' → la clé dérivée est donc complètement différente de celle stockée. C'est exactement comme ça que SelfRecover bloque le phishing.";
        $log->warning('recover-l2', "Domain mismatch — phishing bloqué" . $hint);
    } else {
        $log->warning('recover-l2', 'derived_key KO même avec le bon domaine → mot de récupération incorrect');
    }
    http_response_code(401);
    echo json_encode([
        'ok'      => false,
        'error'   => 'bad_credentials',
        'message' => 'Mot de récupération incorrect.' . $hint,
    ]);
    exit;
}

// Génère nouveau password + reset sessions app
$newPassword = RecoverHelper::generatePassword(16);
$log->info('recover-l2', 'Nouveau password généré (remplace l\'ancien)');

$t2 = microtime(true);
$newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
$t3 = microtime(true);
$log->crypto('recover-l2', 'bcrypt(new_password, cost=12)', ['duration_ms' => (int) (($t3 - $t2) * 1000)]);

$stmt = $db->prepare('UPDATE accounts SET pw_hash = :h WHERE id = :id');
$stmt->bindValue(':h', $newHash);
$stmt->bindValue(':id', $account['id']);
$stmt->execute();

$stmt = $db->prepare('DELETE FROM app_sessions WHERE account_id = :id');
$stmt->bindValue(':id', $account['id']);
$stmt->execute();
$log->info('recover-l2', 'Toutes les app_sessions ont été invalidées', ['revoked' => $db->changes()]);

$log->success('recover-l2', 'HTTP 200 — compte récupéré via L2 (HMAC client)');

echo json_encode([
    'ok'           => true,
    'username'     => $username,
    'new_password' => $newPassword,
    'note'         => "Le mot de récupération n'a jamais quitté ton navigateur. Seule la clé HMAC dérivée est arrivée au serveur. Même si quelqu'un avait sniffé cette requête, il aurait juste un hash spécifique à bi-self.my-self.fr — inutilisable ailleurs.",
]);
