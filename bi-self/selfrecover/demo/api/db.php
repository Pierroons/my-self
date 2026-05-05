<?php
/**
 * SelfRecover demo — SQLite database helper
 */

// Site salt — generated once, never changes (changing invalidates all recovery_derived_hash)
// In production, this MUST be a cryptographically random value stored securely.
define('SITE_SALT', 'demo-site-salt-1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $path = __DIR__ . '/../selfrecover.sqlite';
    $init = !file_exists($path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if ($init) {
        $pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));
    }
    return $pdo;
}

/**
 * Système de traces "transparency live".
 * Chaque endpoint peut empiler des messages via addTrace() qui seront
 * renvoyés au client dans le champ `_trace` de la réponse JSON.
 *
 * RÈGLE D'OR : ne JAMAIS pousser dans les traces une donnée sensible
 * en clair (mot de passe, passphrase brute, mot de récupération brut,
 * hashes Argon2id complets). Seulement des métadonnées (longueurs,
 * durées, indices DB, codes d'erreur structurés).
 */
function addTrace(string $msg): void {
    if (!isset($GLOBALS['_trace'])) {
        $GLOBALS['_trace'] = [];
    }
    $GLOBALS['_trace'][] = '[' . date('H:i:s') . '] ' . $msg;
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    if (!empty($GLOBALS['_trace'])) {
        $data['_trace'] = $GLOBALS['_trace'];
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $code = 400): void {
    if (!isset($GLOBALS['_trace'])) {
        $GLOBALS['_trace'] = [];
    }
    $GLOBALS['_trace'][] = '[' . date('H:i:s') . '] [error] ' . $message;
    jsonResponse(['error' => $message], $code);
}

function getInput(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}
