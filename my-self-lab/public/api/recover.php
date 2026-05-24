<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;

require_method('POST'); // endpoint public (comme login/register), rate-limit géré dans Auth::recover

$body = json_in();
$r = Auth::recover(
    Db::pdo(),
    (string) ($body['username'] ?? ''),
    (string) ($body['recovery_word'] ?? ''),
    client_ip()
);
json_out($r, $r['ok'] ? 200 : 400);
