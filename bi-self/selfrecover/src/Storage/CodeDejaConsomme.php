<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Storage;

use RuntimeException;

/**
 * `consommerCode()` n'a pas trouvé de code encore libre à ce numéro.
 *
 * 🔑 **Un type à elle, et pas une `RuntimeException` nue.** L'appelant doit
 * distinguer ce cas — qui est un refus ordinaire, la course entre deux requêtes
 * portant le même code — d'une panne de la base. `PDOException` étant elle-même
 * une `RuntimeException`, les attraper ensemble rendrait « code incorrect » sur un
 * disque plein.
 */
final class CodeDejaConsomme extends RuntimeException
{
}
