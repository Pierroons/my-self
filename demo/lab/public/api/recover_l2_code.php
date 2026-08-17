<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;

require_method('POST'); // endpoint public, rate-limit géré dans Auth::recoverL2Code

$body = json_in();
$r = Auth::recoverL2Code(
    Db::pdo(),
    (string) ($body['code'] ?? ''),
    // Clé dérivée du mot mémorisé (HMAC côté client) — le mot brut ne transite jamais.
    (string) ($body['memorized_derived_key'] ?? ''),
    client_ip()
);
$code = $r['ok'] ? 200 : (($r['escalate'] ?? '') ? 429 : 400);
json_out($r, $code);
