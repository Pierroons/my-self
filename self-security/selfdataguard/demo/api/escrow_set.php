<?php

declare(strict_types=1);

/**
 * Dépose / met à jour les champs ESCROW (récup assistée, consentie) du user.
 * Ces champs sont scellés pour la clé publique admin en plus du master_key user
 * → récupérables par un admin en cas de litige, jamais lisibles par le serveur.
 */

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);

$body   = json_input();
$userId = (string) ($body['userId'] ?? '');
$secret = (string) ($body['secret'] ?? '');
$mode   = (string) ($body['mode']   ?? 'password');
$fields = is_array($body['fields'] ?? null) ? $body['fields'] : [];

if ($userId === '' || $secret === '') fail('userId and secret are required');

// Whitelist des champs escrow autorisés (le reste du coffre reste privé E2E).
$allowed = ['contact_secours', 'indice_recup'];
$clean   = [];
foreach ($fields as $k => $v) {
    $k = (string) $k;
    $v = trim((string) $v);
    if (in_array($k, $allowed, true) && $v !== '') {
        $clean[$k] = $v;
    }
}
if ($clean === []) fail('Aucun champ escrow valide (contact_secours, indice_recup)');

try {
    $session = match ($mode) {
        'password'  => $dataGuard->loginWithPassword($userId, $secret),
        'memorized' => $dataGuard->loginWithMemorized($userId, $secret),
        default     => fail('mode must be "password" or "memorized"'),
    };
    $dataGuard->setEscrowFields($session, $adminRecoveryPubKey, $clean);
    ok(['userId' => $userId, 'saved' => array_keys($clean)]);
} catch (RuntimeException $e) {
    fail('Authentication failed', 401);
}
