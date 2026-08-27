<?php
/**
 * SelfRecover demo — le sel de dérivation d'un compte.
 *
 * POST /demo/api/recover/sel
 *   body: { "recovery_code": "abcde-12345" }  ou  { "username": "alice" }
 *   → { ok, sel }
 *
 * Le navigateur en a besoin AVANT de pouvoir dériver quoi que ce soit : le sel
 * entre dans le message du HMAC, et lui seul distingue deux personnes qui ont
 * choisi le même mot mémorisé.
 *
 * Il n'est pas secret, et cette route le rend à qui le demande. Ce qui l'était
 * — le sel de déploiement, qui indexe les codes — ne sort plus : jusqu'au
 * 27/08/2026 cette route s'appelait `site-salt` et l'exposait, en même temps
 * qu'elle servait au navigateur le nom de domaine avec lequel dériver. C'était
 * l'inverse exact de la propriété que la démo affichait.
 *
 * La logique — et surtout la garde anti-oracle qui la rend délicate — vit dans
 * `RecoverHelper::selDeDerivation()`. Cette route ne fait que l'exposer.
 *
 * ⚠️ **Pas de quota d'actions ici, et c'est délibéré** — c'est la seule route de
 * récupération dans ce cas, alors que c'est celle qu'un énumérateur viserait.
 * Deux raisons : elle doit être appelée avant CHAQUE dérivation, y compris les
 * tentatives légitimes, et lui faire consommer le quota des cinquante actions
 * ferait échouer un parcours normal ; et la garde anti-oracle rend l'énumération
 * sans objet, puisqu'un code inconnu rend un sel comme un autre.
 *
 * Un déploiement réel devrait quand même la compter : là-bas les comptes se
 * partagent une base, et le coût d'une requête indexée finit par se voir.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/session_manager.php';
require_once __DIR__ . '/../../lib/recover_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$s = DemoSession::current();
if ($s === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'no_session']);
    exit;
}

$body     = json_decode((string) file_get_contents('php://input'), true);
$code     = is_array($body) ? (string) ($body['recovery_code'] ?? '') : '';
$username = is_array($body) ? (string) ($body['username'] ?? '') : '';

echo json_encode([
    'ok'  => true,
    'sel' => RecoverHelper::selDeDerivation($s, $code, $username),
], JSON_UNESCAPED_UNICODE);
