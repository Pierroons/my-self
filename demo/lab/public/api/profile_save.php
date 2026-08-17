<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/profile.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Profile;

require_method('POST');
require_csrf();
$pdo = Db::pdo();
$acc = require_auth($pdo);
$body = json_in();

$result = Profile::save($pdo, (int) $acc['id'], [
    'bio'          => (string) ($body['bio'] ?? ''),
    'localisation' => (string) ($body['localisation'] ?? ''),
    'lien'         => (string) ($body['lien'] ?? ''),
]);
json_out($result, $result['ok'] ? 200 : 400);
