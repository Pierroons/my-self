<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Storage;

use RuntimeException;

/**
 * `trancherLitige()` n'a pas trouvé ce dossier encore en cours d'instruction.
 *
 * 🔑 **Un type à elle, comme `LitigeDejaClos`.** L'appelant doit distinguer ce
 * cas — deux arbitres qui ont lu le même dossier, et dont un seul peut le
 * trancher — d'une panne de la base.
 */
final class LitigeDejaTranche extends RuntimeException
{
}
