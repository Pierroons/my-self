<?php
/**
 * SelfRecover demo — API router
 */

require __DIR__ . '/db.php';
require __DIR__ . '/selfrecover.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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
    default:
        jsonError('Action not recognized', 404);
}
