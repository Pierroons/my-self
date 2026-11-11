<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/dm.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\DM;

// Bloquer ou débloquer les messages privés d'un membre : `{action: "bloquer" | "debloquer", membre: "<identifiant>"}`.
require_method('POST');
require_csrf();
$pdo = Db::pdo();
$acc = require_auth($pdo);
$body = json_in();

$action = (string) ($body['action'] ?? '');
$result = in_array($action, ['bloquer', 'debloquer'], true)
    ? DM::bloquer($pdo, (int) $acc['id'], (string) ($body['membre'] ?? ''), $action === 'bloquer')
    : ['ok' => false, 'error' => 'action_inconnue', 'message' => 'Action inconnue.'];
json_out($result, $result['ok'] ? 200 : 400);
