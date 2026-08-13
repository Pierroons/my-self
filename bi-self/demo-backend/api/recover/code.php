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

$ALLOWED = [
    'register'           => '/var/www/bi-self/api/recover/register.php',
    'login'              => '/var/www/bi-self/api/recover/login.php',
    'logout'             => '/var/www/bi-self/api/recover/logout.php',
    'recover-l1'         => '/var/www/bi-self/api/recover/recover-l1.php',
    'recover-l2'         => '/var/www/bi-self/api/recover/recover-l2.php',
    'phishing-sim'       => '/var/www/bi-self/api/recover/phishing-sim.php',
    'me'                 => '/var/www/bi-self/api/recover/me.php',
    'site-salt'          => '/var/www/bi-self/api/recover/site-salt.php',
    'recover_helper'     => '/var/www/bi-self/lib/recover_helper.php',
    'session_manager'    => '/var/www/bi-self/lib/session_manager.php',
    'logger'             => '/var/www/bi-self/lib/logger.php',
    'rate_limit'         => '/var/www/bi-self/lib/rate_limit.php',
    'redactor'           => '/var/www/bi-self/lib/redactor.php',
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
