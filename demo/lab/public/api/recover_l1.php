<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;

require_method('POST'); // endpoint public, rate-limit géré dans Auth::recoverL1

$body = json_in();
$r = Auth::recoverL1(
    Db::pdo(),
    (string) ($body['username'] ?? ''),
    (string) ($body['passphrase'] ?? ''),
    client_ip()
);
// 429 quand on propose l'escalade (trop de tentatives à ce niveau), 400 sinon.
$code = $r['ok'] ? 200 : (($r['escalate'] ?? '') ? 429 : 400);
json_out($r, $code);
