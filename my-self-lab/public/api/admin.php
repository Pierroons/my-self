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

    case 'moderate': // action manuelle admin (« squizz ») : ban / grâce
        $id = (int) ($body['account_id'] ?? 0);
        $op = (string) ($body['op'] ?? '');
        if ($id <= 0) { json_out(['ok' => false, 'message' => 'Compte invalide.'], 400); }
        if ($op === 'ban')    { Moderate::adminBan($pdo, $id);    json_out(['ok' => true, 'message' => 'Compte banni.']); }
        if ($op === 'pardon') { Moderate::adminPardon($pdo, $id); json_out(['ok' => true, 'message' => 'Compte gracié (réputation restaurée).']); }
        json_out(['ok' => false, 'message' => 'Opération inconnue.'], 400);

    case 'dispute':
        $d = Dispute::reveal($pdo, (int) ($body['id'] ?? 0));
        $d ? json_out(['ok' => true, 'dispute' => $d]) : json_out(['ok' => false, 'message' => 'Litige introuvable.'], 404);

    case 'dispute_decide':
        // Accepter n'ouvre AUCUN accès : la décision est enregistrée, la remise
        // en main se fait hors ligne. Brancher une régénération ici recréerait
        // le chemin automatique que le niveau 3 existe pour éviter.
        $ok = Dispute::decide(
            $pdo,
            (int) ($body['id'] ?? 0),
            ($body['verdict'] ?? '') === 'accepte',
            (string) ($body['note'] ?? '')
        );
        json_out(['ok' => $ok], $ok ? 200 : 400);

    default:
        json_out(['ok' => false, 'message' => 'Action inconnue.'], 400);
}
