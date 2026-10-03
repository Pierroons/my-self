<?php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/redteam.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Redteam;

require_method('POST');

// POST et non GET : le jeton de suivi ne doit pas finir dans les journaux du
// serveur ni dans l'historique du navigateur, ce qu'une query-string garantit.
//
// Ni session ni CSRF : le jeton EST l'autorisation, et celui qui le détient est
// celui qui a déposé le rapport. C'est le même raisonnement que le sésame des
// litiges.
$body = json_in();

$r = Redteam::etat(
    Db::pdo(),
    (int) ($body['id'] ?? 0),
    (string) ($body['suivi'] ?? '')
);

json_out($r, $r['ok'] ? 200 : 403);
