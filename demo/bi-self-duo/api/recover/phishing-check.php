<?php
/**
 * SelfRecover demo — démonstration de la résistance au phishing.
 *
 * POST /demo/api/recover/phishing-check
 *   body: { "username": "alice", "derived_key": "4e7a9f…" }
 *   → dit si le HMAC calculé par le navigateur correspond à celui du compte.
 *
 * 🔑 **Cet endpoint ne rend aucun accès, et c'est le point.** Il s'appelait
 * `recover-l2` et réinitialisait le mot de passe sur un seul secret, avec un
 * identifiant en entrée. Or le niveau 2 du protocole est défini par l'inverse :
 * « 2FA sans identifiant », un code de possession et un mot de connaissance, le
 * code localisant le compte par index de recherche. Une démonstration qui
 * contredit la spécification qu'elle illustre enseigne le contraire d'elle-même.
 *
 * Ce qu'il montre, en revanche, mérite d'exister : le mot de récupération n'est
 * jamais envoyé. Seul le `derived_key` arrive au serveur — `HMAC(clé = mot,
 * message = hostname|v2 + sel)`, où le `hostname` est celui que le navigateur a
 * LU. Une page servie sous un autre nom d'hôte fait dériver une autre clé, et
 * la comparaison échoue.
 *
 * ⚠️ **Le serveur ne sait pas quel nom d'hôte a servi, et ne doit pas prétendre
 * le savoir.** Cette route recevait un champ `domain_used` et le contrôlait :
 * un champ que le client déclare, donc qu'une page de hameçonnage remplit
 * comme elle veut. Il a été retiré le 27/08/2026. Ce que le serveur constate
 * est plus simple, et vrai : la clé correspond, ou elle ne correspond pas.
 * Le seul à savoir sous quel nom d'hôte la dérivation s'est faite est le
 * navigateur — c'est lui qui l'affiche.
 *
 * La récupération réelle, elle, passe par `recover-l1` ou `recover-l2-code`.
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

$log = $s->logger();
$log->info('phishing-check', 'POST /demo/api/recover/phishing-check');
$log->info('phishing-check', 'Body parsed', [
    'username'    => $username,
    'derived_key' => $derivedKey,
    'note'        => "Le recovery_word brut n'est pas dans le body — seulement la clé HMAC dérivée par le navigateur. Le serveur n'a aucun moyen de le reconstituer, ni de savoir sous quel nom d'hôte elle a été calculée.",
]);

if (!preg_match('/^[a-z0-9]{3,20}$/', $username)) {
    $log->warning('phishing-check', 'Username invalide');
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_username']);
    exit;
}
if (!preg_match('/^[a-f0-9]{64}$/', $derivedKey)) {
    $log->warning('phishing-check', 'derived_key mal formé (attendu: 64 chars hex SHA-256)', ['received' => $derivedKey]);
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_derived_key']);
    exit;
}

$db = $s->db();
$stmt = $db->prepare('SELECT id, recovery_hash FROM accounts WHERE username = :u');
$stmt->bindValue(':u', $username);
$account = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

if (!is_array($account)) {
    $log->warning('phishing-check', 'Compte introuvable', ['username' => $username]);
    // Voir recover-l1 : on paie le hachage plutôt que d'attendre un délai fixe.
    password_verify($derivedKey, RecoverHelper::dummyHash());
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'bad_credentials',
        'message'=>'Mot de récupération incorrect ou compte inconnu.']);
    exit;
}

$t0 = microtime(true);
$ok = password_verify($derivedKey, $account['recovery_hash']);
$t1 = microtime(true);

$log->crypto('phishing-check', 'argon2id_verify(derived_key_received, stored_recovery_hash)', [
    'duration_ms' => (int) (($t1 - $t0) * 1000),
    'result'      => $ok ? 'match' : 'no_match',
]);

if (!$ok) {
    // Une seule branche, et c'est voulu : le serveur ne peut pas distinguer un
    // mot erroné d'une dérivation faite ailleurs, et s'il le pouvait il ne
    // devrait pas le dire — ce serait un oracle. Le navigateur, lui, sait sous
    // quel nom d'hôte il a calculé, et c'est à lui de l'afficher.
    $log->warning('phishing-check', "La clé dérivée reçue ne correspond pas à celle du compte");
    http_response_code(401);
    echo json_encode([
        'ok'      => false,
        'error'   => 'bad_credentials',
        // Même phrase que sur le chemin « compte inconnu », au mot près.
        'message' => 'Mot de récupération incorrect ou compte inconnu.',
    ]);
    exit;
}

// 🔑 Ici s'arrêtait la démonstration et commençait la récupération : mot de
// passe régénéré, sessions révoquées, accès rendu — sur la foi d'un seul secret
// et d'un identifiant. Ce chemin a été retiré le 19/08/2026. Ce qui suit dit ce
// que la vérification a donné, et rien de plus.
$log->success('phishing-check', 'HTTP 200 — dérivation conforme, aucun accès délivré');

echo json_encode([
    'ok'          => true,
    'match'       => true,
    'username'    => $username,
    'message'     => 'Le HMAC calculé par ton navigateur correspond à celui du compte.',
    'note'        => "Cette page démontre la dérivation, elle ne rend pas l'accès : "
                   . "un seul secret ne suffit pas. Pour récupérer, il faut la passphrase "
                   . "(niveau 1) ou un code de secours accompagné du mot mémorisé (niveau 2).",
], JSON_UNESCAPED_UNICODE);
