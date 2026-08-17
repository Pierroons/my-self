<?php
/**
 * Promotion / rétrogradation admin — usage CLI uniquement.
 *   php promote_admin.php <username>        → promeut en admin
 *   php promote_admin.php <username> off     → retire l'admin
 *
 * Le username admin n'est volontairement nulle part dans le code (OPSEC) :
 * il est désigné ici, à la main, sur le déploiement.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/lib/db.php';

use Pierroons\MySelfLab\Db;

$username = strtolower(trim($argv[1] ?? ''));
$off = (($argv[2] ?? '') === 'off');

if ($username === '') {
    fwrite(STDERR, "Usage: php promote_admin.php <username> [off]\n");
    exit(1);
}

$pdo = Db::pdo();
$stmt = $pdo->prepare('UPDATE accounts SET is_admin = ? WHERE username = ?');
$stmt->execute([$off ? 0 : 1, $username]);

if ($stmt->rowCount() > 0) {
    echo $off ? "✓ @$username n'est plus admin.\n" : "✓ @$username est désormais admin.\n";
    exit(0);
}
fwrite(STDERR, "✗ Compte '$username' introuvable.\n");
exit(1);
