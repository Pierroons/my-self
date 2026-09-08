<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\RecoverL3;

require_method('POST');
$pdo = Db::pdo();
$moi = require_admin($pdo);
require_csrf(); // action admin authentifiée → jeton CSRF requis

// 🔑 Qui dégèle est pris dans la SESSION, jamais dans le corps de la requête.
// La colonne `degele_par` sert à l'arbitre suivant : un nom que l'appelant
// choisirait n'apprendrait rien à personne.
$body = json_in();
$r = RecoverL3::adminUnfreeze($pdo, (string) ($body['username'] ?? ''), (string) $moi['username']);
json_out($r, $r['ok'] ? 200 : (int) ($r['code'] ?? 400));
