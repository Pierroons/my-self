<?php
/**
 * Enrôlement d'un appareil de confiance.
 *
 * 🔑 Session obligatoire, et le compte visé est celui de la session.
 *
 * Sans cette double contrainte, la chaîne enroll → auth_begin → auth_finish
 * permettait de prendre n'importe quel compte avec un SEUL secret : le mot
 * mémorisé. L'attaquant fournissait sa propre paire de clés, l'enrôlait sur le
 * compte d'autrui, signait le défi avec sa clé privée, et `authFinish` lui
 * rendait un mot de passe neuf en évinçant le titulaire. Trois requêtes.
 * Démontré sur le fil le 13/08/2026.
 *
 * Le correctif du 02/08 avait fermé la version béante — enrôlement sans aucune
 * preuve — en exigeant le mot mémorisé. Il laissait un chemin à un facteur là
 * où la spec en demande deux, sur la page même d'où la branche « identifiant +
 * mot seul » avait été retirée la veille pour cette raison exacte.
 *
 * ⚠️ Enrôler n'est pas récupérer. On enrôle une machine quand on est déjà
 * connecté ; qui a perdu son accès passe par L1 (passphrase), L2 (code + mot)
 * ou L3 (décision humaine). Les trois voies de la spec restent ouvertes, et
 * aucune ne demande moins de deux facteurs.
 *
 * Le mot mémorisé reste exigé en plus de la session : la session prouve qu'on
 * est connecté, le mot prouve qu'on est bien le titulaire — un poste laissé
 * ouvert ne suffit pas à enrôler un appareil durable.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Device;

require_method('POST');
$pdo = Db::pdo();

$compte = require_auth($pdo);   // 401 si aucune session : l'appel s'arrête ici.

// ⚠️ Pas de `require_csrf()` ici, et c'est un manque assumé : le client de
// l'inscription ne dispose pas encore du jeton, qui n'est exposé que sur les
// pages déjà authentifiées. L'ajouter demande de le publier dans la page et de
// le transmettre depuis `sr-device.js` — un lot à part.
//
// Ce que la requête doit déjà prouver, faute de jeton : une session valide ET
// le mot mémorisé dérivé. Un site tiers qui forgerait cet appel n'a pas le
// second, donc n'obtient rien. La protection manquante est de la défense en
// profondeur, pas le rempart principal. Cf R12-03, même famille.

$body = json_in();

// 🔑 Le nom d'utilisateur ne vient plus de la requête mais de la session.
// Le lire dans le corps laissait viser un compte tiers ; le paramètre est
// désormais ignoré, et un envoi divergent se refuse plutôt que d'être corrigé
// en silence — un client qui se trompe de compte doit l'apprendre.
$demande = trim((string) ($body['username'] ?? ''));
if ($demande !== '' && $demande !== (string) $compte['username']) {
    json_out([
        'ok'      => false,
        'error'   => 'compte_non_correspondant',
        'message' => "Un appareil ne s'enrôle que sur le compte connecté.",
    ], 403);
}

$r = Device::enroll(
    $pdo,
    (string) $compte['username'],                    // jamais l'entrée du client
    (string) ($body['credential_id'] ?? ''),
    (string) ($body['public_key'] ?? ''),            // SPKI DER, base64url
    (string) ($body['memorized_derived_key'] ?? ''), // HMAC du mot, dérivé côté client
    client_ip()
);
json_out($r, $r['ok'] ? 200 : 400);
