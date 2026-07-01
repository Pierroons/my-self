<?php

declare(strict_types=1);

/**
 * Bootstrap shared by every demo API endpoint.
 *
 * - Loads the local autoloader
 * - Sets a JSON content-type
 * - Constructs (and caches) a SelfDataGuard façade backed by a local SQLite DB
 * - Generates and persists a stable blindKey on first run
 */

require __DIR__ . '/../../src/autoload.php';

use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// DB SQLite : chemin surchargeable HORS webroot via DATAGUARD_DB_PATH (fallback local).
// define() (et pas const) pour pouvoir lire l'env ; reste une constante utilisable
// par tous les endpoints (inspect_db, etc.).
define('DEMO_DB_PATH', getenv('DATAGUARD_DB_PATH')
    ?: ($_SERVER['DATAGUARD_DB_PATH'] ?? '')
    ?: (__DIR__ . '/../storage/demo.sqlite'));

// blindKey (index aveugle) : chemin surchargeable HORS webroot via l'env
// DATAGUARD_BLINDKEY_PATH ; fallback local pour la démo/dev. En prod, la clé
// vit hors de l'arborescence servie par le serveur web (défense en profondeur).
$blindKeyPath = getenv('DATAGUARD_BLINDKEY_PATH')
    ?: ($_SERVER['DATAGUARD_BLINDKEY_PATH'] ?? '')
    ?: (__DIR__ . '/../storage/blindkey.bin');

if (!is_file($blindKeyPath)) {
    file_put_contents($blindKeyPath, Primitives::randomBytes(32));
    chmod($blindKeyPath, 0600);
}
$blindKey = file_get_contents($blindKeyPath);
if ($blindKey === false || strlen($blindKey) < 32) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load blindKey']);
    exit;
}

$storage     = new SqliteAdapter('sqlite:' . DEMO_DB_PATH);
$dataGuard   = new SelfDataGuard($storage, $blindKey);

/**
 * Decode a JSON body, return [] if absent or malformed.
 *
 * @return array<string, mixed>
 */
function json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function fail(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function ok(array $data): never
{
    echo json_encode(['ok' => true] + $data);
    exit;
}
