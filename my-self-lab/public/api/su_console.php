<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/su_console.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\SuConsole;

require_method('POST');
require_csrf();
require_auth(Db::pdo());   // auth simple : tout est SANDBOX → pas de require_admin (aucun pouvoir réel)

$body = json_in();
$role = (string) ($body['role'] ?? '');
if (!in_array($role, SuConsole::ROLES, true)) {
    json_out(['ok' => false, 'message' => 'Rôle inconnu.'], 400);
}

$result = SuConsole::run($role);
json_out($result, ($result['ok'] ?? false) ? 200 : 400);
