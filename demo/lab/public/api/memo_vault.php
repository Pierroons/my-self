<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/memo_vault.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\MemoVault;

$pdo = Db::pdo();

// GET : renvoie le coffre opaque de l'utilisateur connecté (pour déchiffrement local).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $acc = require_auth($pdo);
    $vault = MemoVault::get($pdo, (int) $acc['id']);
    json_out(['ok' => true, 'exists' => $vault !== null, 'vault' => $vault]);
}

// POST : enregistre le coffre (création/réinitialisation) ou met à jour le mémo seul.
require_method('POST');
require_csrf();
$acc = require_auth($pdo);
$body = json_in();

if (($body['action'] ?? '') === 'update_memo') {
    $r = MemoVault::updateMemo($pdo, (int) $acc['id'], (string) ($body['memo_iv'] ?? ''), (string) ($body['memo_ct'] ?? ''));
} else {
    $r = MemoVault::save($pdo, (int) $acc['id'], $body);
}
json_out($r, $r['ok'] ? 200 : 400);
