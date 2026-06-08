<?php
/**
 * SelfRecover demo — API router
 */

require __DIR__ . '/db.php';
require __DIR__ . '/selfrecover.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Client-Fingerprint, X-Admin-Token');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    // R9-02 : l'ancien endpoint 'salt' (sel global exposé) est retiré.
    // Remplacé par 'user-salt' (sel par-utilisateur, anti-énumération).
    case 'user-salt':
        handleUserSalt();
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
    // SelfRecover L3 — récupération assistée (chat admin obligatoire, jamais d'auto)
    case 'recover-l3-init':
        handleRecoverL3Init();
        break;
    case 'recover-l3':
        handleRecoverL3();
        break;
    case 'dispute-chat':
        handleDisputeChat();
        break;
    case 'admin-disputes':
        handleAdminDisputes();
        break;
    case 'admin-dispute-decide':
        handleAdminDisputeDecide();
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
