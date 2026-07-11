<?php
/**
 * SelfRecover demo — API router
 */

require __DIR__ . '/db.php';
require __DIR__ . '/selfrecover.php';
require __DIR__ . '/device_handlers.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Client-Fingerprint, X-Session-Token');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Purge amortie de la DB démo (~8% des requêtes) pour borner la taille sous test de masse (DEMO-ONLY)
if (DEMO_AUTOPURGE) { try { if (random_int(1, 12) === 1) purgeOldDemoData(getDB()); } catch (Throwable $e) { /* non bloquant */ } }

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
    case 'logout':
        handleLogout();
        break;
    case 'recover-l1':
        handleRecoverL1();
        break;
    case 'recover-l2':
        handleRecoverL2();
        break;
    // L2 renforcé (2FA sans identifier) : mot mémorisé + recovery code
    case 'recover-l2-code':
        handleRecoverL2Code();
        break;
    case 'regenerate-codes':
        handleRegenerateCodes();
        break;
    // Facteur possession « cet appareil » (device-bound, enveloppe par le mot mémorisé)
    case 'device-enroll':
        handleDeviceEnroll();
        break;
    case 'device-auth-begin':
        handleDeviceAuthBegin();
        break;
    case 'device-auth-finish':
        handleDeviceAuthFinish();
        break;
    // SelfRecover L3 — récupération assistée (chat admin obligatoire, jamais d'auto)
    case 'recover-l3-init':
        handleRecoverL3Init();
        break;
    case 'recover-l3':
        handleRecoverL3();
        break;
    case 'l3-reset': // R9-01b : re-enrôlement par le propriétaire après accord admin
        handleL3Reset();
        break;
    case 'dispute-chat':
        handleDisputeChat();
        break;
    case 'admin-disputes':
        handleAdminDisputes();
        break;
    case 'admin-l2-signals':
        handleAdminL2Signals();
        break;
    case 'admin-dispute-decide':
        handleAdminDisputeDecide();
        break;
    case 'admin-request-promotion':
        handleAdminRequestPromotion();
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
        jsonError('Action non reconnue', 404);
}
