<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/admin.php';
require_once __DIR__ . '/../../lib/moderate.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Admin;
use Pierroons\MySelfLab\Dispute;
use Pierroons\MySelfLab\Moderate;

require_method('POST');
require_csrf();
$pdo = Db::pdo();
$moi = require_admin($pdo);

$body = json_in();
$action = (string) ($body['action'] ?? '');

switch ($action) {
    case 'profile':
        $p = Admin::profile($pdo, (int) ($body['account_id'] ?? 0));
        $p ? json_out(['ok' => true, 'profile' => $p]) : json_out(['ok' => false, 'message' => 'Compte introuvable.'], 404);

    case 'report':
        $r = Admin::readReport($pdo, (int) ($body["id"] ?? 0));
        $r ? json_out(['ok' => true, 'report' => $r]) : json_out(['ok' => false, 'message' => 'Rapport introuvable.'], 404);

    case 'report_status':
        $ok = Admin::setReportStatus($pdo, (int) ($body['id'] ?? 0), (string) ($body['status'] ?? ''));
        json_out(['ok' => $ok], $ok ? 200 : 400);

    case 'moderate': // geste d'arbitre : ban, grâce, maintien — signé du nom de la session
        $id    = (int) ($body['account_id'] ?? 0);
        $op    = (string) ($body['op'] ?? '');
        $motif = (string) ($body['motif'] ?? '');
        if ($id <= 0) { json_out(['ok' => false, 'message' => 'Compte invalide.'], 400); }
        $r = match ($op) {
            'ban'       => Moderate::adminBan($pdo, $id, (string) $moi['username'], $motif),
            'pardon'    => Moderate::adminPardon($pdo, $id, (string) $moi['username'], $motif),
            'maintenir' => Moderate::adminMaintenir($pdo, $id, (string) $moi['username'], $motif),
            default     => ['ok' => false, 'message' => 'Opération inconnue.'],
        };
        json_out($r, $r['ok'] ? 200 : 400);

    // Les litiges passent par leurs points d'entrée dédiés
    // (admin_disputes.php, admin_dispute_decide.php) : le niveau 3 a son propre
    // cycle — faisceau, fil de discussion, ré-enrôlement par le propriétaire —
    // que deux branches génériques ne couvraient pas.

    default:
        json_out(['ok' => false, 'message' => 'Action inconnue.'], 400);
}
