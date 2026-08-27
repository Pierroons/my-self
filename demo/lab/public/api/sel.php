<?php

declare(strict_types=1);

/**
 * Le sel de dérivation d'un compte, trouvé par l'un de ses codes de secours.
 *
 * La logique — et surtout la garde anti-oracle qui la rend délicate — vit dans
 * `Auth::selDeDerivation()`. Cette route ne fait que l'exposer : mettre le
 * raisonnement ici l'aurait rendu invisible depuis le reste du protocole, et
 * duplicable par la prochaine route qui en aurait besoin.
 */

require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Auth;
use Pierroons\MySelfLab\Db;

require_method('POST');
$body = json_in();

json_out(['ok' => true, 'sel' => Auth::selDeDerivation(Db::pdo(), (string) ($body['recovery_code'] ?? ''))]);
