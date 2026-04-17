<?php
/**
 * SelfRecover demo — Expose le site_salt de cette session (pour le HMAC client).
 *
 * GET /demo/api/recover/site-salt
 *   → { domain, site_salt }
 *
 * Dans le protocole SelfRecover réel, le site_salt est un secret serveur
 * (généré à l'installation, jamais exposé). POUR LA DÉMO, on l'expose
 * délibérément pour que le navigateur puisse calculer le HMAC et montrer à
 * l'utilisateur exactement ce qui se passe côté client. En prod, le client
 * fait le HMAC avec juste le domaine comme sel public, la vraie diversification
 * est faite côté serveur via un salt additionnel avant le bcrypt.
 *
 * Ce compromis est nécessaire pour que la démo soit vraiment pédagogique —
 * l'utilisateur voit le HMAC se calculer dans son navigateur.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/session_manager.php';
require_once __DIR__ . '/../../lib/recover_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$s = DemoSession::current();
if ($s === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'no_session']);
    exit;
}

echo json_encode([
    'ok'        => true,
    'domain'    => 'bi-self.my-self.fr',
    'site_salt' => RecoverHelper::siteSalt($s),
    'note'      => 'En prod, le site_salt reste secret côté serveur. Il est exposé ici délibérément pour que la démo soit transparente et que tu voies le HMAC se calculer dans ton navigateur.',
]);
