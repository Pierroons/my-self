<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/admin.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Admin;

require_method('POST');
require_csrf();
$pdo = Db::pdo();
require_admin($pdo);

$body = json_in();
$action = (string) ($body['action'] ?? '');

switch ($action) {
    case 'profile':
        $p = Admin::profile($pdo, (int) ($body['account_id'] ?? 0));
        $p ? json_out(['ok' => true, 'profile' => $p]) : json_out(['ok' => false, 'message' => 'Compte introuvable.'], 404);

    case 'report':
        $r = Admin::decryptReport($pdo, (int) ($body['id'] ?? 0));
        $r ? json_out(['ok' => true, 'report' => $r]) : json_out(['ok' => false, 'message' => 'Rapport introuvable.'], 404);

    case 'report_status':
        $ok = Admin::setReportStatus($pdo, (int) ($body['id'] ?? 0), (string) ($body['status'] ?? ''));
        json_out(['ok' => $ok], $ok ? 200 : 400);

    default:
        json_out(['ok' => false, 'message' => 'Action inconnue.'], 400);
}
