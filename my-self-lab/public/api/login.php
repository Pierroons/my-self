<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;

require_method('POST');
$body = json_in();
$username = (string) ($body['username'] ?? '');
$password = (string) ($body['password'] ?? '');

$res = Auth::login(Db::pdo(), $username, $password, client_ip());
if (!$res['ok']) {
    // 429 si bloqué, 401 si mauvais identifiants (avec compteur restant)
    $code = ($res['status'] ?? '') === 'locked' ? 429 : 401;
    json_out([
        'ok' => false,
        'error' => $res['status'] ?? 'invalid_credentials',
        'remaining' => $res['remaining'] ?? 0,
        'message' => $res['message'],
    ], $code);
}
Auth::setSessionCookie($res['token']);
json_out(['ok' => true, 'message' => 'Connecté.']);
