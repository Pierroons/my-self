<?php
/**
 * Promotion admin — déplacée vers `selfrecover-su`.
 *
 * Ce script promouvait et rétrogradait depuis un shell, sans authentification et
 * sans trace : exactement ce que le modèle SU→Admin→User existe pour empêcher.
 * Il ne promeut plus, et il le dit — un chemin qui disparaît en silence laisse
 * quelqu'un devant un outil muet.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$username = trim($argv[1] ?? '');
$off      = (($argv[2] ?? '') === 'off');
$verbe    = $off ? 'revoke-admin' : 'add-admin';
$cible    = $username !== '' ? $username : '<username>';

fwrite(STDERR, <<<TXT
    Ce script ne promeut plus. La promotion passe par le super-utilisateur, qui
    l'authentifie et l'inscrit au journal chaîné avant de l'appliquer :

        ./selfrecover-su $verbe $cible

    Le SU a besoin de deux choses, posées une fois à l'installation, hors de
    l'arborescence servie :

        export SELFRECOVER_STATE_DIR=/var/lib/myself-lab
        export SELFRECOVER_SU_AUDIT_SECRET="\$(openssl rand -hex 32)"
        ./selfrecover-su change-passphrase

    Sans elles, la console refuse de démarrer plutôt que de gouverner sans trace.

    TXT);
exit(1);
