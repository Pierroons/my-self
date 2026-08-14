<?php
/**
 * SelfRecover demo — Recovery L2 par code de secours (2FA sans identifiant).
 *
 * POST /demo/api/recover/recover-l2-code
 *   body: { "recovery_code": "a1b2c-3d4e5", "memorized_derived": "4e7a9f…",
 *           "new_password": "…" }
 *
 * 🔑 Aucun identifiant n'est demandé, et c'est le point de tout le mécanisme.
 * Le code de secours LOCALISE le compte — par un HMAC qui sert d'index — et le
 * mot mémorisé l'AUTORISE. Comme il n'existe aucun champ où saisir un nom
 * d'utilisateur, il n'existe aucun endroit où tester si un compte existe :
 * l'énumération disparaît, elle n'est pas seulement rendue difficile.
 *
 * ⚠️ Deux facteurs, tous les deux exigés. Un code de secours trouvé sur un
 * papier ne suffit pas ; le mot mémorisé seul non plus. C'est la définition du
 * niveau 2 dans la spec, et le message d'erreur ne dit jamais lequel des deux a
 * échoué — le préciser rendrait chaque facteur attaquable séparément.
 *
 * Les traces de ce fichier sont écrites pour être lues : ce sont elles qui
 * permettent de voir, après coup, comment un compte a changé de main.
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
$code        = is_array($body) ? strtolower(trim((string) ($body['recovery_code'] ?? ''))) : '';
$derivedKey  = is_array($body) ? (string) ($body['memorized_derived'] ?? '') : '';
$newPassword = is_array($body) ? (string) ($body['new_password'] ?? '') : '';

$log = $s->logger();
$log->info('recover-l2-code', 'POST /demo/api/recover/recover-l2-code');
$log->info('recover-l2-code', 'Body parsed', [
    'recovery_code'     => $code,
    'memorized_derived' => $derivedKey,
    'note'              => "Aucun identifiant n'est transmis : le code retrouve le compte à lui seul.",
]);

if (!preg_match('/^[a-f0-9]{5}-[a-f0-9]{5}$/', $code)) {
    $log->warning('recover-l2-code', 'Code de secours mal formé (attendu : xxxxx-xxxxx en hexadécimal)');
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_code_format']);
    exit;
}
if (!preg_match('/^[a-f0-9]{64}$/', $derivedKey)) {
    $log->warning('recover-l2-code', 'memorized_derived mal formé (attendu : 64 caractères hexadécimaux)');
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_derived_key']);
    exit;
}
if (strlen($newPassword) < 8) {
    $log->warning('recover-l2-code', 'Nouveau mot de passe trop court');
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'password_too_short']);
    exit;
}

$db     = $s->db();
$lookup = hash_hmac('sha256', $code, RecoverHelper::siteSalt($s));

$log->crypto('recover-l2-code', 'HMAC-SHA256(code, sel du service) → index de recherche', [
    'lookup'  => $lookup,
    'pourquoi' => "Le HMAC sert d'index : il retrouve la ligne en une requête, sans nom d'utilisateur. "
                . "Stocker le code en clair permettrait la même recherche, mais une fuite de la base "
                . "livrerait tous les codes — le hachage Argon2id ci-dessous couvre ce risque, et le "
                . "HMAC couvre la recherche. Aucun des deux ne remplace l'autre.",
]);

$stmt = $db->prepare(
    'SELECT rc.id AS code_id, rc.code_hash, rc.used,
            a.id AS account_id, a.username, a.recovery_hash
       FROM recovery_codes rc JOIN accounts a ON a.id = rc.account_id
      WHERE rc.code_lookup = :l'
);
$stmt->bindValue(':l', $lookup);
$row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

// ⚠️ Code inconnu et code déjà consommé donnent la même réponse, au même
// rythme : les distinguer dirait à un attaquant qu'il a trouvé un vrai code.
if (!is_array($row) || (int) $row['used'] === 1) {
    $log->warning('recover-l2-code', is_array($row)
        ? 'Code déjà consommé — refus (usage unique)'
        : 'Aucun code ne correspond à cet index');
    usleep(400000);
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'bad_credentials',
        'message'=>'Code de secours ou mot mémorisé incorrect.']);
    exit;
}

$t0 = microtime(true);
$codeOk = password_verify($code, (string) $row['code_hash']);            // possession
$wordOk = password_verify($derivedKey, (string) $row['recovery_hash']);  // connaissance
$t1 = microtime(true);

$log->crypto('recover-l2-code', 'Vérification des DEUX facteurs', [
    'duration_ms'  => (int) (($t1 - $t0) * 1000),
    'code'         => $codeOk ? 'match' : 'no_match',
    'mot_memorise' => $wordOk ? 'match' : 'no_match',
    'compte'       => $row['username'],
    'note'         => "Les deux sont vérifiés même si le premier échoue : s'arrêter au premier "
                    . "raté donnerait, par le temps de réponse, l'information que le message refuse de dire.",
]);

if (!$codeOk || !$wordOk) {
    $log->warning('recover-l2-code', 'Refus — au moins un facteur incorrect', [
        'compte' => $row['username'],
        'note'   => "Le message rendu ne dit pas lequel : le préciser rendrait chaque facteur "
                  . "attaquable séparément, et ferait du code de secours un oracle.",
    ]);
    usleep(400000);
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'bad_credentials',
        'message'=>'Code de secours ou mot mémorisé incorrect.']);
    exit;
}

$db->exec('BEGIN IMMEDIATE');
$up = $db->prepare('UPDATE accounts SET pw_hash = :h WHERE id = :i');
$up->bindValue(':h', password_hash($newPassword, PASSWORD_BCRYPT));
$up->bindValue(':i', (int) $row['account_id'], SQLITE3_INTEGER);
$up->execute();

// Usage unique : consommé dans la même transaction que le changement de mot de
// passe. Séparer les deux laisserait une fenêtre où le code sert deux fois.
$used = $db->prepare('UPDATE recovery_codes SET used = 1, used_at = :t WHERE id = :i');
$used->bindValue(':t', time(), SQLITE3_INTEGER);
$used->bindValue(':i', (int) $row['code_id'], SQLITE3_INTEGER);
$used->execute();
$db->exec('COMMIT');

$reste = (int) $db->querySingle(
    'SELECT COUNT(*) FROM recovery_codes WHERE account_id = ' . (int) $row['account_id'] . ' AND used = 0'
);

$log->success('recover-l2-code', 'Mot de passe réinitialisé — niveau 2 par code de secours', [
    'compte'         => $row['username'],
    'codes_restants' => $reste,
    'note'           => "Le code vient d'être consommé et ne resservira pas. "
                      . ($reste === 0 ? "C'était le dernier : sans nouveau lot, il ne reste que la passphrase (L1) ou le niveau 3."
                                      : "Il en reste $reste."),
]);

echo json_encode([
    'ok'             => true,
    'username'       => $row['username'],
    'codes_restants' => $reste,
    'message'        => 'Mot de passe réinitialisé. Ce code de secours ne peut plus servir.',
], JSON_UNESCAPED_UNICODE);
