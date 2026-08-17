<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;

require_method('POST');
require_csrf();
$token = $_COOKIE[Auth::cookieName()] ?? '';
if ($token !== '') {
    Auth::logout(Db::pdo(), $token);
}
Auth::clearSessionCookie();
json_out(['ok' => true, 'message' => 'Déconnecté.']);
