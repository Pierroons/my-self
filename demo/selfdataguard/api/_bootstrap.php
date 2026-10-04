<?php

declare(strict_types=1);

/**
 * Bootstrap shared by every demo API endpoint.
 *
 * - Loads the SelfDataGuard library autoloader
 * - Sets a JSON content-type
 * - Constructs (and caches) a SelfDataGuard façade backed by a local SQLite DB
 * - Generates and persists a stable blindKey on first run
 */

require __DIR__ . '/../../../self-security/selfdataguard/src/autoload.php';

use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Une base par visiteur, désignée par un cookie : personne ne voit, ne cherche ni
// n'ouvre les coffres d'un autre. Les bases vivent dans `sessions/`, à côté de
// DATAGUARD_DB_PATH. Une base naît d'une inscription valide seulement
// (demo_base_neuve()) ; tout ce que contient `sessions/` s'efface après
// DEMO_TTL_SECONDES sans action, par la première requête venue et par le minuteur
// de l'instance (deploy/selfdataguard/demo-sessions-purge.service, qui porte la durée).
const DEMO_TTL_SECONDES       = 30 * 60;
const DEMO_BASES_MAX          = 1000;
const DEMO_BASES_PAR_ADRESSE  = 5;

// ⚠️ Secure par défaut : une détection de HTTPS se trompe derrière un frontal et
// retirerait l'attribut sans que personne le voie. DATAGUARD_DEMO_HTTP=1 ne sert
// qu'aux essais locaux en clair. En HTTPS, le préfixe __Host- interdit à un
// sous-domaine voisin d'imposer son cookie à la démo.
$enClair   = (getenv('DATAGUARD_DEMO_HTTP') ?: ($_SERVER['DATAGUARD_DEMO_HTTP'] ?? '')) === '1';
$nomCookie = $enClair ? 'sdg_demo' : '__Host-sdg_demo';

$repertoireInstance = dirname(getenv('DATAGUARD_DB_PATH')
    ?: ($_SERVER['DATAGUARD_DB_PATH'] ?? '')
    ?: (__DIR__ . '/../storage/demo.sqlite'));
$repertoireBases = $repertoireInstance . '/sessions';
if (!is_dir($repertoireBases) && !@mkdir($repertoireBases, 0700, true) && !is_dir($repertoireBases)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create the demo sessions directory']);
    exit;
}

$maintenant = time();
foreach (glob($repertoireBases . '/*') ?: [] as $fichier) {
    if (is_file($fichier) && @filemtime($fichier) < $maintenant - DEMO_TTL_SECONDES) {
        @unlink($fichier);
    }
}

$idVisiteur = $_COOKIE[$nomCookie] ?? '';
if (!is_string($idVisiteur) || preg_match('/^[a-f0-9]{32}\z/', $idVisiteur) !== 1) {
    $idVisiteur = bin2hex(random_bytes(16));
}
$baseVisiteur = $repertoireBases . '/' . $idVisiteur . '.sqlite';
$baseExiste   = is_file($baseVisiteur);
setcookie($nomCookie, $idVisiteur, [
    'expires'  => $maintenant + DEMO_TTL_SECONDES,
    'path'     => '/',
    'secure'   => !$enClair,
    'httponly' => true,
    'samesite' => 'Strict',
]);
if ($baseExiste) {
    // Une lecture compte comme une action : sans elle, la base expirerait après la
    // dernière écriture, au milieu d'une visite qui inspecte.
    @touch($baseVisiteur);
}

// define() (et pas const) : la valeur se calcule ; reste une constante utilisable
// par tous les endpoints (inspect_db, etc.).
define('DEMO_DB_PATH', $baseVisiteur);

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

// Sans base, une lecture passe par une base vide en mémoire : rien n'est écrit, et
// chaque page vue n'occupe pas une place jusqu'à son expiration.
$storage     = new SqliteAdapter($baseExiste ? 'sqlite:' . DEMO_DB_PATH : 'sqlite::memory:');
$dataGuard   = new SelfDataGuard($storage, $blindKey);

// Une action sur un coffre suppose une base : sans elle, le dire, plutôt qu'un
// « mauvais mot de passe » qui enverrait chercher une faute de frappe.
if (!$baseExiste && in_array(basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')),
        ['login.php', 'coffre_open.php', 'escrow_set.php', 'change_password.php', 'delete.php'], true)) {
    http_response_code(404);
    echo json_encode(['error' => sprintf(
        'No demo database for you yet — register first. It is erased after %d minutes without action.',
        intdiv(DEMO_TTL_SECONDES, 60)
    )]);
    exit;
}

/**
 * Ouvre la base du visiteur pour une inscription déjà validée, et la crée. Refuse
 * au plafond global (503) et au quota par adresse (429), compté sous un HMAC de
 * l'adresse pour ne pas l'écrire en clair.
 */
function demo_base_neuve(): SelfDataGuard
{
    global $repertoireBases, $baseVisiteur, $blindKey, $maintenant;
    if (count(glob($repertoireBases . '/*.sqlite') ?: []) >= DEMO_BASES_MAX) {
        fail('The demo is full — come back in a few minutes', 503);
    }
    $quota = $repertoireBases . '/adresse-' . substr(hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), $blindKey), 0, 32);
    $creations = array_filter(
        array_map('intval', is_file($quota) ? (file($quota, FILE_IGNORE_NEW_LINES) ?: []) : []),
        static fn (int $quand): bool => $quand > $maintenant - DEMO_TTL_SECONDES
    );
    if (count($creations) >= DEMO_BASES_PAR_ADRESSE) {
        fail('Too many demo databases from your address — come back later', 429);
    }
    file_put_contents($quota, implode("\n", [...$creations, $maintenant]) . "\n", LOCK_EX);
    $GLOBALS['storage'] = new SqliteAdapter('sqlite:' . $baseVisiteur);
    return new SelfDataGuard($GLOBALS['storage'], $blindKey);
}

/** Efface la base qu'une inscription refusée vient de créer. */
function demo_effacer_base_neuve(): void
{
    global $baseVisiteur;
    foreach (['', '-journal', '-wal', '-shm'] as $suffixe) {
        @unlink($baseVisiteur . $suffixe);
    }
}

// Clé de récupération ADMIN pour le compartiment escrow (démo). La clé PUBLIQUE
// scelle l'escrow au dépôt ; la clé privée SCELLÉE par passphrase ne sert qu'à
// la cérémonie de récup (bin/escrow-ceremony.php). Générée une fois, persistée
// hors DB. En prod, elle vit sur le serveur de déploiement (VPS/NAS), scellée.
$adminPubPath  = dirname($blindKeyPath) . '/admin-recovery.pub';
$adminSealPath = dirname($blindKeyPath) . '/admin-recovery.sealed';
if (!is_file($adminPubPath)) {
    // Passphrase de démo (documentée dans README). En prod : dans la tête de l'admin.
    $ar = SelfDataGuard::generateAdminRecoveryKey('demo-admin-recovery-passphrase-2026');
    // Les DEUX moitiés, ou aucune. Si la publique s'écrit et que la scellée échoue,
    // l'escrow scellera vers une clé dont la partie privée n'existe nulle part : chaque
    // scellement produirait alors une donnée définitivement irrécupérable, sans un mot.
    $ecrites = @file_put_contents($adminPubPath, $ar['publicKey']) !== false
        && @file_put_contents($adminSealPath, $ar['sealedSecret']) !== false;
    if (!$ecrites) {
        @unlink($adminPubPath);
        @unlink($adminSealPath);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to write admin recovery key pair']);
        exit;
    }
    chmod($adminSealPath, 0600);
}
// Même garde que pour la blindKey ci-dessus : une écriture qui a échoué rend la
// chaîne vide, et une clé publique vide n'échoue nulle part en aval — l'escrow
// accepterait de sceller vers un destinataire qui n'existe pas.
$adminRecoveryPubKey = trim((string) @file_get_contents($adminPubPath));
// Longueur exigée et non « non vide » : `file_put_contents` rend un entier court sur
// une écriture partielle, jamais `false` — un fichier tronqué passe un test de vacuité.
if (strlen($adminRecoveryPubKey) < 32 || !is_file($adminSealPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load admin recovery public key']);
    exit;
}

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
