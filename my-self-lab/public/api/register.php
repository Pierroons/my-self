<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;

require_method('POST');
$body = json_in();
$username = (string) ($body['username'] ?? '');
// Clé dérivée dans le navigateur (HMAC) : le mot mémorisé ne transite jamais.
$recoveryDerivedKey = (string) ($body['recovery_derived_key'] ?? '');

$result = Auth::register(Db::pdo(), $username, $recoveryDerivedKey, client_ip());

// La session est posée côté client, et le jeton retiré de la réponse : il vit
// dans un cookie HttpOnly, pas dans du JSON que le navigateur pourrait relire.
if (!empty($result['ok']) && !empty($result['token'])) {
    Auth::setSessionCookie((string) $result['token']);
    unset($result['token']);
}

json_out($result, $result['ok'] ? 201 : 400);
