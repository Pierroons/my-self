<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/attack_sim.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\AttackSimulator;

require_method('POST');
require_csrf();
require_auth(Db::pdo());

$body = json_in();
$scenario = (string) ($body['scenario'] ?? '');
if (!in_array($scenario, AttackSimulator::SCENARIOS, true)) {
    json_out(['ok' => false, 'message' => 'Scénario inconnu.'], 400);
}

$result = tc_deep(AttackSimulator::run($scenario));
json_out($result, $result['ok'] ? 200 : 400);
