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

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function jsonError(string $message, int $code = 400): void {
    jsonResponse(['error' => $message], $code);
}

function getInput(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}
