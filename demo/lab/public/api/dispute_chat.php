<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;
use Pierroons\MySelfLab\RecoverL3;

require_method('POST'); // POST uniquement : n° + sésame ne transitent jamais en query-string
$pdo = Db::pdo();
$body = json_in();

// Admin détecté par la session-compte (source de vérité users.is_admin) ; sinon = propriétaire via sésame.
$acc = Auth::currentAccount($pdo);
$isAdmin = !empty($acc['is_admin']);

$r = RecoverL3::chat(
    $pdo,
    (string) ($body['dispute_number'] ?? ''),
    (string) ($body['claim_secret'] ?? ''),
    array_key_exists('message', $body) ? (string) $body['message'] : null, // absence = polling
    $isAdmin,
    client_ip()
);
json_out($r, $r['ok'] ? 200 : (int) ($r['code'] ?? 400));
