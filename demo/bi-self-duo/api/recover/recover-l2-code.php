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

$db = $s->db();

require_once __DIR__ . '/../../lib/StockageSelfRecover.php';
$recovery = new Pierroons\SelfRecover\Recovery\Recovery(new StockageSelfRecover($db), RecoverHelper::siteSalt($s));

// 🔑 Le protocole vit dans `bi-self/selfrecover/src`, partagé avec le lab. Cet
// endpoint n'est plus qu'une porte HTTP. Ce qu'il ne fait plus lui-même :
// calculer l'index de recherche, vérifier les deux facteurs, consommer le code
// et remplacer les secrets dans une même transaction.
$log->crypto('recover-l2-code', 'HMAC-SHA256(code, sel du service) → index de recherche', [
    'note' => "Le code localise le compte sans qu'aucun identifiant soit demandé : il n'existe donc aucun champ où éprouver l'existence d'un compte.",
]);

$t0 = microtime(true);
$r  = $recovery->parCode($code, $derivedKey, null);
$ms = (int) ((microtime(true) - $t0) * 1000);

$log->crypto('recover-l2-code', 'Vérification des DEUX facteurs — possession et connaissance', [
    'duration_ms' => $ms,
    'note'        => "Les deux sont vérifiés quoi qu'il arrive : s'arrêter au premier échoué dirait, par le temps, lequel a échoué.",
]);

if (!$r['ok']) {
    $log->warning('recover-l2-code', 'Refus — le message ne dit pas lequel des deux facteurs a échoué');
    http_response_code(401);
    echo json_encode([
        'ok'      => false,
        'error'   => $r['error'] ?? 'bad_credentials',
        'message' => $r['message'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$reste = $r['codes_restants'];
$log->info('recover-l2-code', 'Code consommé, secrets remplacés et sessions révoquées — dans une seule transaction', [
    'codes_restants' => $reste,
]);
$log->success('recover-l2-code', 'HTTP 200 — nouveaux secrets livrés');

echo json_encode([
    'ok'             => true,
    'username'       => $r['compte'],
    'new_password'   => $r['mot_de_passe'],
    'passphrase'     => $r['passphrase'],
    'codes_restants' => $reste,
    'message'        => 'Mot de passe et passphrase renouvelés. Ce code de secours ne peut plus servir.',
    'note'           => "Le code vient d'être consommé et ne resservira pas. "
                      . ($reste === 0
                          ? "C'était le dernier : sans nouveau lot, il ne reste que la passphrase (L1) ou le niveau 3."
                          : "Il en reste {$reste}."),
], JSON_UNESCAPED_UNICODE);
