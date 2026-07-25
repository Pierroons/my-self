<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\RecoverL3;

require_method('POST'); // re-enrôlement par le propriétaire (autorisé par le sésame d'init, pas une session)
$body = json_in();
$r = RecoverL3::reset(
    Db::pdo(),
    (string) ($body['dispute_number'] ?? ''),
    (string) ($body['claim_secret'] ?? ''),
    (string) ($body['password'] ?? ''),                 // choisi par l'utilisateur
    (string) ($body['recovery_derived_key'] ?? '')      // nouveau mot mémorisé, dérivé côté client
);
json_out($r, $r['ok'] ? 200 : (int) ($r['code'] ?? 400));
