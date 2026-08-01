<?php
/**
 * MySelf-Lab — bootstrap commun (autoload, DB, helpers HTTP).
 * Inclus par tous les endpoints API et pages publiques.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/security.php';
// Chiffres publics du lab — agrégés, sans mesure d'audience ajoutée.
require_once __DIR__ . '/stats.php';
// Niveau 3 de SelfRecover : escalade humaine. Faisceau de faits soumis à un
// administrateur — aucun score, et sa décision n'ouvre pas le compte : le
// propriétaire se ré-enrôle lui-même ensuite.
require_once __DIR__ . '/recover_l3.php';
// Appareil de confiance : facteur de possession alternatif au code, en L2.
// ⚠️ Une classe non chargée ici ne lève pas d'erreur visible — elle produit
// une réponse HTTP vide, silencieuse et pénible à diagnostiquer.
require_once __DIR__ . '/device.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;
use Pierroons\MySelfLab\Security;

// Headers de sécurité sur toute réponse (HTML par défaut ; les endpoints API
// repassent en non-HTML via json_out qui n'émet pas de CSP page).
Security::sendHeaders(true);

/** Réponse JSON + exit. */
function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Lit le body JSON d'une requête POST. */
function json_in(): array
{
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        return [];                       // corps vide = legitime (POST sans payload)
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        // R11-INFO-01 : rejeter un corps non-JSON au lieu du no-op silencieux.
        json_out(['ok' => false, 'error' => 'Corps JSON invalide.'], 400);
    }
    return $data;
}

/** Exige une méthode HTTP, sinon 405. */
function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        json_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }
}

/** Retourne le compte connecté ou 401. */
function require_auth(\PDO $pdo): array
{
    $acc = Auth::currentAccount($pdo);
    if ($acc === null) {
        json_out(['ok' => false, 'error' => 'unauthorized', 'message' => 'Connecte-toi pour cette action.'], 401);
    }
    return $acc;
}

/** Retourne le compte admin connecté, sinon 403 (auth requise au préalable). */
function require_admin(\PDO $pdo): array
{
    $acc = require_auth($pdo);
    if (empty($acc['is_admin'])) {
        json_out(['ok' => false, 'error' => 'forbidden', 'message' => 'Réservé à l\'administration.'], 403);
    }
    return $acc;
}

/** Token de session courant (cookie), ou null. */
function session_token(): ?string
{
    $t = $_COOKIE[Auth::cookieName()] ?? '';
    return preg_match('/^[a-f0-9]{48}$/', $t) ? $t : null;
}

/** Vérifie le token CSRF d'une action authentifiée, sinon 403. */
function require_csrf(): void
{
    if (!Security::verifyCsrf(session_token())) {
        json_out(['ok' => false, 'error' => 'csrf_failed',
                  'message' => 'Jeton de sécurité invalide ou expiré. Recharge la page.'], 403);
    }
}

function client_ip(): ?string
{
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

/** Échappe le HTML pour affichage sûr. */
function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Nonce CSP de la requête courante, pour <script nonce="<?= nonce() ?>">. */
function nonce(): string
{
    return \Pierroons\MySelfLab\Security::nonce();
}
