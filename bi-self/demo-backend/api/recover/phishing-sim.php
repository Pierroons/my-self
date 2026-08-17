<?php
/**
 * SelfRecover demo — Simulation anti-phishing.
 *
 * POST /demo/api/recover/phishing-sim
 *   body: { "username": "alice",
 *           "derived_key_legit": "4e7a...",     // HMAC calculé avec bi-self.my-self.fr
 *           "derived_key_phishing": "9f3b..." } // HMAC calculé avec phishing-my-self-fr.local
 *
 *   → Compare les deux clés au stored recovery_hash et renvoie :
 *     { legit_match: true, phishing_match: false, ... }
 *
 * Pédagogie : le visiteur voit en direct que deux HMAC du même mot avec
 * deux domaines différents produisent des clés complètement différentes,
 * et que seule celle qui utilise le bon domaine passe l'argon2id_verify.
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
$username            = is_array($body) ? (string) ($body['username'] ?? '') : '';
$derivedKeyLegit     = is_array($body) ? (string) ($body['derived_key_legit'] ?? '') : '';
$derivedKeyPhishing  = is_array($body) ? (string) ($body['derived_key_phishing'] ?? '') : '';

$log = $s->logger();
$log->info('phishing-sim', 'POST /demo/api/recover/phishing-sim');

if (!preg_match('/^[a-z0-9]{3,20}$/', $username) ||
    !preg_match('/^[a-f0-9]{64}$/', $derivedKeyLegit) ||
    !preg_match('/^[a-f0-9]{64}$/', $derivedKeyPhishing)) {
    $log->warning('phishing-sim', 'Champs invalides');
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_fields']);
    exit;
}

$log->info('phishing-sim', 'Deux derivées reçues — une légitime, une de phishing', [
    'derived_key_legit'    => $derivedKeyLegit,
    'derived_key_phishing' => $derivedKeyPhishing,
    'note'                 => 'Les deux sont le HMAC du même mot, mais l\'un utilise bi-self.my-self.fr comme domaine, l\'autre utilise phishing-my-self-fr.local. Leur output est radicalement différent : c\'est l\'effet domain binding.',
]);

$db = $s->db();
$stmt = $db->prepare('SELECT recovery_hash FROM accounts WHERE username = :u');
$stmt->bindValue(':u', $username);
$account = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

if (!is_array($account)) {
    $log->warning('phishing-sim', 'Compte introuvable', ['username' => $username]);
    // Deux vérifications factices, comme le chemin nominal juste en dessous : le
    // HMAC légitime puis celui du domaine de phishing. Sans elles, un compte
    // absent répond sans avoir rien calculé, et le temps de réponse trie les
    // comptes existants.
    //
    // 🔑 Le code et le message s'alignent aussi sur les autres endpoints du
    // dossier. Un 404 nommant `account_not_found` disait à lui seul ce que le
    // hachage vient de taire — et cet endpoint est servi au visiteur par la
    // visionneuse de code : il enseigne autant qu'il fonctionne.
    // ⚠️ Ce qui subsiste, et qui n'est pas traité ici : le chemin nominal rend
    // 200 avec un verdict, celui-ci rend 401. La distinction est structurelle —
    // une simulation ne peut pas analyser un compte absent — et la fermer
    // demanderait d'exiger que le compte soit celui de la session, ce qui change
    // le contrat de l'endpoint. Sans objet tant que la base est isolée par
    // session : le visiteur n'y trouve que les comptes qu'il a lui-même créés.
    password_verify($derivedKeyLegit, RecoverHelper::dummyHash());
    password_verify($derivedKeyPhishing, RecoverHelper::dummyHash());
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'bad_credentials',
        'message'=>'Mot de récupération incorrect ou compte inconnu.']);
    exit;
}

$legitMatch    = password_verify($derivedKeyLegit, $account['recovery_hash']);
$phishingMatch = password_verify($derivedKeyPhishing, $account['recovery_hash']);

$log->crypto('phishing-sim', 'argon2id_verify(derived_key_legit, recovery_hash)', [
    'result' => $legitMatch ? 'match ✓' : 'no_match',
]);
$log->crypto('phishing-sim', 'argon2id_verify(derived_key_phishing, recovery_hash)', [
    'result' => $phishingMatch ? 'match (PROBLÈME!)' : 'no_match ✓',
]);

$verdict = $legitMatch && !$phishingMatch ? 'expected' : 'unexpected';
$msg = $verdict === 'expected'
    ? "Comportement attendu : le bon domaine passe, le phishing échoue. C'est le domain binding natif de SelfRecover — aucune formation utilisateur nécessaire."
    : "Comportement inattendu — ça ne devrait pas arriver. Bug de la démo ?";

if ($verdict === 'expected') {
    $log->success('phishing-sim', 'Anti-phishing démontré : seul le bon domaine a validé');
} else {
    $log->error('phishing-sim', 'Résultat inattendu', ['legit' => $legitMatch, 'phishing' => $phishingMatch]);
}

echo json_encode([
    'ok'             => true,
    'legit_match'    => $legitMatch,
    'phishing_match' => $phishingMatch,
    'verdict'        => $verdict,
    'message'        => $msg,
]);
