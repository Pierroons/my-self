<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/redteam.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Redteam;

require_method('POST');

$body = json_in();

// Honeypot : un champ invisible que seuls les bots remplissent. Succès silencieux.
if (trim((string) ($body['website'] ?? '')) !== '') {
    json_out(['ok' => true]);
}

$result = Redteam::submit(Db::pdo(), $body, client_ip());
json_out($result, $result['ok'] ? 200 : 400);
