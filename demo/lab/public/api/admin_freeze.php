<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\RecoverL3;

require_method('POST');
$pdo = Db::pdo();
$moi = require_admin($pdo);
require_csrf(); // action admin authentifiée → jeton CSRF requis

// 🔑 Qui gèle est pris dans la SESSION, jamais dans le corps de la requête —
// même raison que pour le dégel : un nom que l'appelant choisirait n'apprendrait
// rien à l'arbitre suivant.
//
// Seul chemin qui pose un gel, et pourquoi il doit le rester :
// cf. RecoverL3::adminFreeze().
$body = json_in();
$r = RecoverL3::adminFreeze($pdo, (string) ($body['username'] ?? ''), (string) $moi['username']);
json_out($r, $r['ok'] ? 200 : (int) ($r['code'] ?? 400));
