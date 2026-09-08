<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\RecoverL3;

require_method('POST');
$pdo = Db::pdo();
$moi = require_admin($pdo);
require_csrf(); // action admin authentifiée → jeton CSRF requis

$body = json_in();
// 🔑 Qui tranche vient de la SESSION. Le laisser par défaut faisait signer
// « admin » toutes les décisions : la console affiche ce nom à l'arbitre
// suivant, et une trace qui ne distingue personne n'en est pas une.
$r = RecoverL3::adminDecide(
    $pdo,
    (string) ($body['dispute_number'] ?? ''),
    (string) ($body['decision'] ?? ''),
    (string) $moi['username']
);
json_out($r, $r['ok'] ? 200 : (int) ($r['code'] ?? 400));
