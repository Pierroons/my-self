<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;

require_method('POST'); // endpoint public, rate-limit géré dans Auth

$body = json_in();

/*
 * Deux niveaux, deux secrets, deux compteurs distincts.
 *
 * L1 — passphrase diceware : secret généré, fort, à conserver.
 * L2 — code de récupération + mot mémorisé : possession ET connaissance.
 *      Aucun identifiant n'est demandé : le code retrouve le compte par lookup
 *      HMAC, ce qui supprime toute possibilité d'énumération.
 *
 * Le chemin « identifiant + mot seul » a été retiré le 01/08 : il n'exigeait
 * qu'un seul facteur, alors que la spécification SelfRecover définit L2 comme
 * « code ET mot ». Le conserver revenait à publier une porte plus faible que
 * celle qu'on documente. Tous les comptes disposent désormais d'un lot.
 *
 * Le niveau est déduit des champs transmis, jamais d'un paramètre : un client
 * ne doit pas pouvoir choisir contre quel secret il est comparé.
 */
if (isset($body['passphrase']) && $body['passphrase'] !== '') {
    $r = Auth::recoverByPassphrase(
        Db::pdo(),
        (string) ($body['username'] ?? ''),
        (string) $body['passphrase'],
        client_ip()
    );
} else {
    $r = Auth::recoverByCode(
        Db::pdo(),
        (string) ($body['recovery_code'] ?? ''),
        (string) ($body['recovery_word'] ?? ''),
        client_ip()
    );
}

json_out($r, $r['ok'] ? 200 : 400);
