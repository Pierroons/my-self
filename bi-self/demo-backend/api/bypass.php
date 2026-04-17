<?php
/**
 * Bi-Self Demo — Endpoint de bypass du rate-limit.
 *
 * GET /bypass/<token>
 *   Si token match /var/lib/selfjustice/admin/bypass_token.txt
 *   → pose le cookie sj_bypass et redirect vers /
 *   Sinon → 404
 *
 * Le cookie bypass permet ensuite de créer des sessions démo sans rate-limit
 * depuis n'importe quelle IP (4G, chez un ami, etc.) pour tester l'admin.
 *
 * Durée du cookie : 90 jours. Rotation = nouveau token + effacement.
 */

declare(strict_types=1);

$provided = $_SERVER['SJ_BYPASS_TOKEN'] ?? '';
$token_file = '/var/lib/selfjustice/admin/bypass_token.txt';
$stored = '';
if (is_readable($token_file)) {
    $stored = trim((string) file_get_contents($token_file));
}

if ($stored === '' || !hash_equals($stored, $provided)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>404</title></head><body><h1>Not Found</h1></body></html>";
    exit;
}

setcookie(
    'sj_bypass',
    $stored,
    [
        'expires'  => time() + 90 * 86400,
        'path'     => '/',
        'domain'   => 'bi-self.my-self.fr',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]
);

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Bi-Self — Bypass activé</title>
  <meta http-equiv="refresh" content="3; url=/recover">
  <style>
    body { font-family: system-ui, sans-serif; background: #0f1419; color: #e8eaed; padding: 3rem; text-align: center; }
    h1 { color: #7ab7ff; }
    p { color: #9aa0a6; }
    a { color: #7ab7ff; }
  </style>
</head>
<body>
  <h1>✓ Bypass rate-limit activé</h1>
  <p>Cookie sj_bypass posé (90 jours). Tu peux maintenant ouvrir autant de sessions que nécessaire.</p>
  <p>Redirection vers la démo SelfRecover dans 3 secondes…</p>
  <p style="margin-top: 2rem; font-size: 0.85rem;">
    <a href="/recover">Lancer la démo maintenant</a>  ·  <a href="/">Retour à la landing Bi-Self</a>
  </p>
</body>
</html>';
