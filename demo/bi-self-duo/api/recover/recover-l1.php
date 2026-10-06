<?php
/**
 * SelfRecover demo — Recovery L1 (passphrase).
 *
 * POST /demo/api/recover/recover-l1
 *   body: { "username": "alice", "passphrase": "…", "new_passphrase": "…" (facultatif) }
 *   → argon2id_verify(passphrase, pass_hash) → nouveau password ; nouvelle passphrase
 *     apportée (tirée aux dés) ou tirée par la bibliothèque → update
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
// Absente ou nulle : la bibliothèque tire la nouvelle. Un texte, même vide, est jugé.
$nouvelle   = is_array($body) ? ($body['new_passphrase'] ?? null) : null;
if ($nouvelle !== null && !is_string($nouvelle)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'passphrase_invalide', 'motif' => 'trop_courte',
                      'message' => 'La passphrase apportée doit être un texte.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$log = $s->logger();
$log->info('recover-l1', 'POST /demo/api/recover/recover-l1');
$log->info('recover-l1', 'Body parsed', [
    'username'   => $username,
    'passphrase' => '[HIDDEN ' . strlen($passphrase) . ' chars, ' . str_word_count($passphrase) . ' words]',
    'nouvelle'   => $nouvelle === null ? 'tirée par la bibliothèque' : '[HIDDEN — apportée]',
]);

if (!RecoverHelper::estIdentifiant($username) || strlen($passphrase) < 4) {
    $log->warning('recover-l1', 'Champs invalides');
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_fields']);
    exit;
}

// Monté par la fabrique du helper, jamais à la main : c'est elle qui porte le
// profil de déploiement, obligatoire depuis la 0.7.0. Construire ici avec deux
// arguments compilait sans broncher et levait un ArgumentCountError à l'appel.
$recovery = RecoverHelper::protocole($s);

// 🔑 Le protocole n'est pas réimplémenté ici : il vit dans
// `bi-self/selfrecover/src`, avec le lab pour second consommateur. Cet endpoint
// n'est plus qu'une porte HTTP — c'est ce qui garantit qu'un correctif de
// récupération ne peut plus s'appliquer à une démo et pas à l'autre.
//
// L'origine n'est pas transmise : cette démo freine par session (RateLimit,
// plus haut), et sa table `login_attempts` ne porte pas de colonne `ip`.
//
// ⚠️ Ce frein de session est donc la SEULE borne du niveau 1 ici. Sans origine,
// la bibliothèque n'en a aucune — et son frein par compte fermait la voie du
// titulaire à qui voulait la fermer. Le retirer d'au-dessus laisserait la porte
// sans compteur.
$log->info('recover-l1', 'Vérification déléguée à Pierroons\SelfRecover\Recovery::parPassphrase');

$t0 = microtime(true);
$r  = $recovery->parPassphrase($username, $passphrase, null, nouvellePassphrase: $nouvelle);
$ms = (int) ((microtime(true) - $t0) * 1000);

$log->crypto('recover-l1', 'argon2id — ' . \Pierroons\SelfRecover\Crypto\Hashing::profilEnClair() . ', exécuté même sur compte inconnu', [
    'duration_ms' => $ms,
    'note'        => "C'est le coût du hachage qui égalise le temps de réponse, jamais un délai fixe : celui-ci se distinguerait d'un Argon2id, qui varie.",
]);

// Un refus de la passphrase apportée se dit : il ne dépend que de ce qu'elle
// contient, jamais du compte.
if (!$r['ok'] && str_starts_with((string) ($r['error'] ?? ''), 'passphrase_')) {
    $log->warning('recover-l1', 'Passphrase apportée refusée', ['erreur' => $r['error'], 'motif' => $r['motif'] ?? null]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $r['error'], 'motif' => $r['motif'] ?? null,
                      'message' => $r['message']], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!$r['ok']) {
    $log->warning('recover-l1', 'Refus — le message ne dit pas si le compte existe');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'bad_credentials', 'message' => $r['message']]);
    exit;
}

$log->info('recover-l1', 'Mot de passe ET passphrase renouvelés, sessions révoquées', [
    'note' => "La passphrase est consommée par son usage : la laisser valable ferait d'un papier volé une porte permanente.",
]);
$log->success('recover-l1', 'HTTP 200 — nouveaux secrets livrés à l\'user');

echo json_encode([
    'ok'           => true,
    'username'     => $username,
    'new_password' => $r['mot_de_passe'],
    'passphrase'   => $r['passphrase'],
    'note'         => "Note ton nouveau password ET ta nouvelle passphrase : l'ancienne vient d'être consommée et ne fonctionnera plus. Toutes tes sessions ouvertes ont été déconnectées.",
]);
