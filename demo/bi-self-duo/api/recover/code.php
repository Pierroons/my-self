<?php
/**
 * SelfRecover demo — Code viewer (open book).
 *
 * GET /demo/api/recover/code?file=<name>
 *   → { file, content } avec secrets censurés par Redactor
 *
 * Liste blanche stricte : seuls les fichiers du dossier api/recover/
 * et les libs spécifiques (recover_helper, session_manager) sont
 * lisibles. Aucun accès arbitraire au FS.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/session_manager.php';
require_once __DIR__ . '/../../lib/redactor.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$s = DemoSession::current();
if ($s === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'no_session']);
    exit;
}

// 🔑 Les chemins se dérivent du fichier, ils ne se recopient pas.
// Ils étaient écrits en dur vers /var/www/bi-self/ — une adresse qui n'existe
// plus depuis un déménagement. La visionneuse répondait « file_unreadable » à
// chaque appel, sans que rien d'autre ne le signale : une liste blanche qui
// pointe dans le vide protège parfaitement, et ne sert plus à rien.
$BASE = dirname(__DIR__, 2);   // .../demo-backend

$ALLOWED = [
    'register'         => $BASE . '/api/recover/register.php',
    'login'            => $BASE . '/api/recover/login.php',
    'logout'           => $BASE . '/api/recover/logout.php',
    'recover-l1'       => $BASE . '/api/recover/recover-l1.php',
    'recover-l2'       => $BASE . '/api/recover/recover-l2.php',
    'recover-l2-code'  => $BASE . '/api/recover/recover-l2-code.php',
    'phishing-sim'     => $BASE . '/api/recover/phishing-sim.php',
    'me'               => $BASE . '/api/recover/me.php',
    'site-salt'        => $BASE . '/api/recover/site-salt.php',
    'recover_helper'   => $BASE . '/lib/recover_helper.php',
    'session_manager'  => $BASE . '/lib/session_manager.php',
    'logger'           => $BASE . '/lib/logger.php',
    'rate_limit'       => $BASE . '/lib/rate_limit.php',
    'redactor'         => $BASE . '/lib/redactor.php',
];

$file = (string) ($_GET['file'] ?? 'register');
if (!array_key_exists($file, $ALLOWED)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'file_not_whitelisted', 'allowed' => array_keys($ALLOWED)]);
    exit;
}

$path = $ALLOWED[$file];
if (!is_readable($path)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'file_unreadable']);
    exit;
}

$content = (string) file_get_contents($path);
$redacted = Redactor::redactSource($content);

echo json_encode([
    'ok'      => true,
    'file'    => $file,
    'path'    => basename($path),
    'content' => $redacted,
    'note'    => 'Secrets serveur censurés automatiquement (sites salt, paths absolus, tokens).',
]);
