<?php
/**
 * SelfRecover demo — API router
 */

require __DIR__ . '/db.php';
require __DIR__ . '/selfrecover.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'salt':
        jsonResponse(['salt' => SITE_SALT]);
        break;
    case 'register':
        handleRegister();
        break;
    case 'login':
        handleLogin();
        break;
    case 'recover-l1':
        handleRecoverL1();
        break;
    case 'recover-l2':
        handleRecoverL2();
        break;
    // SelfRecover Lite (V0.1.1) — variante avec SMTP + mot mémorisé
    case 'lite-register':
        handleLiteRegister();
        break;
    case 'lite-reset-request':
        handleLiteResetRequest();
        break;
    case 'lite-reset-info':
        handleLiteResetInfo();
        break;
    case 'lite-reset-confirm':
        handleLiteResetConfirm();
        break;
    default:
        jsonError('Action not recognized', 404);
}
