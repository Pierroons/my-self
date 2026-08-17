<?php
/**
 * SelfModerate demo — Code viewer (open book).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/session_manager.php';
require_once __DIR__ . '/../../lib/redactor.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$s = DemoSession::current();
if ($s === null) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'no_session']); exit; }

$ALLOWED = [
    'me'               => '/var/www/bi-self/api/moderate/me.php',
    'users'            => '/var/www/bi-self/api/moderate/users.php',
    'create-identity'  => '/var/www/bi-self/api/moderate/create-identity.php',
    'vote'             => '/var/www/bi-self/api/moderate/vote.php',
    'trigger-pack'     => '/var/www/bi-self/api/moderate/trigger-pack.php',
    'drain-reputation' => '/var/www/bi-self/api/moderate/drain-reputation.php',
    'farm-upvotes'     => '/var/www/bi-self/api/moderate/farm-upvotes.php',
    'tick'             => '/var/www/bi-self/api/moderate/tick.php',
    'moderate_helper'  => '/var/www/bi-self/lib/moderate_helper.php',
    'session_manager'  => '/var/www/bi-self/lib/session_manager.php',
    'schema'           => '/var/www/bi-self/schemas/selfmoderate.sql',
];

$file = (string) ($_GET['file'] ?? 'vote');
if (!array_key_exists($file, $ALLOWED)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'file_not_whitelisted']);
    exit;
}
$content = (string) file_get_contents($ALLOWED[$file]);
echo json_encode(['ok' => true, 'file' => $file, 'content' => Redactor::redactSource($content)]);
