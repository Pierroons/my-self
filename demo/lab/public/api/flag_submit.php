<?php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/flags.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Flags;

require_method('POST');

// Pas de session exigée, et c'est voulu : un chercheur qui sort un drapeau n'a
// aucune raison d'avoir créé un compte ici, et l'obliger ajouterait une friction
// au moment précis où il vient de réussir. Le pseudo est libre et facultatif ;
// ce qu'il vaut, c'est l'ordre d'arrivée, pas l'identité.
//
// Sans session, pas de jeton CSRF non plus — il n'y a rien à protéger : forger
// cette requête depuis un site tiers demanderait de connaître le drapeau, donc
// de l'avoir déjà.
$body = json_in();

$r = Flags::soumettre(
    Db::pdo(),
    (string) ($body['flag'] ?? ''),
    (string) ($body['handle'] ?? ''),
    client_ip()
);

json_out($r, $r['ok'] ? 200 : 400);
