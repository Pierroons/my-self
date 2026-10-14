<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Storage;

use RuntimeException;

/**
 * `cloreLitige()` n'a pas trouvé ce dossier encore actif.
 *
 * 🔑 **Un type à elle, pour la même raison que `CodeDejaConsomme`.** L'appelant
 * doit distinguer ce cas — deux requêtes qui ont lu le même dossier, et dont une
 * seule peut le clore — d'une panne de la base, que `PDOException` signale déjà
 * comme une `RuntimeException`.
 */
final class LitigeDejaClos extends RuntimeException
{
}
