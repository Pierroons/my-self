<?php
/**
 * Bi-Self Demo — Endpoint création / récupération de session.
 *
 * POST /demo/api/session
 *   body: { "module": "selfrecover" | "selfmoderate" | "duo" }
 *   → 200 { ok: true, session: {...} }
 *   → 429 si rate-limit
 *   → 403 si banned
 *   → 503 si capacity_full
 *
 * GET /demo/api/session
 *   → 200 { ok: true, session: {...} } si cookie valide
 *   → 404 sinon
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/session_manager.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $s = DemoSession::current();
    if ($s === null) {
        respond(404, ['ok' => false, 'error' => 'no_session']);
    }
    respond(200, ['ok' => true, 'session' => $s->toArray()]);
}

if ($method === 'POST') {
    $raw = (string) file_get_contents('php://input');
    $body = json_decode($raw, true);
    $module = '';
    if (is_array($body)) {
        $module = (string) ($body['module'] ?? '');
    }
    if (!in_array($module, ['selfrecover', 'selfmoderate', 'duo'], true)) {
        respond(400, ['ok' => false, 'error' => 'invalid_module']);
    }

    $result = DemoSession::getOrCreate($module);

    if (!$result['ok']) {
        $map = [
            'banned'        => [403, "Ton IP est bannie pour 30 jours suite à un usage abusif de la démo."],
            'capacity_full' => [503, "Démo à capacité maximale (10 sessions simultanées). Reviens dans quelques minutes."],
            'abuse_banned'  => [403, "Ton IP vient d'être bannie pour 30 jours — trop de sessions répétées."],
        ];
        [$code, $msg] = $map[$result['error']] ?? [429, 'Rate limit exceeded'];
        respond($code, ['ok' => false, 'error' => $result['error'], 'message' => $msg]);
    }

    /** @var DemoSession $s */
    $s = $result['session'];
    respond(201, [
        'ok'      => true,
        'session' => $s->toArray(),
        'message' => match ($result['warning_level']) {
            0 => null,
            1 => "Tu as ouvert plusieurs sessions récemment. C'est une démo pour comprendre, pas un playground à spammer. Encore 1 avertissement avant blocage de ton IP pour 30 jours.",
            2 => "⚠ DERNIER AVERTISSEMENT — tu as ouvert trop de sessions dans l'heure. Encore une et ton IP est bannie 30 jours.",
        },
    ]);
}

respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
