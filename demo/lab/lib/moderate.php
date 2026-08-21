<?php
/**
 * MySelf-Lab — façade vers le moteur SelfModerate.
 *
 * Le moteur vit dans `bi-self/selfmoderate/`, comme SelfRecover et
 * SelfDataGuard vivent dans les leurs. Ce fichier n'existe que pour deux
 * raisons : brancher la traduction du lab sur un moteur qui n'en connaît
 * aucune, et garder le nom `Pierroons\MySelfLab\Moderate` que sept fichiers
 * appellent déjà.
 *
 * Aucune règle de modération ici. Si une décision se prend dans ce fichier,
 * c'est qu'elle est au mauvais endroit.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use Pierroons\SelfModerate\Moderate as Engine;

require_once __DIR__ . '/i18n.php';

// `tc()` est dans le namespace global : i18n.php n'en déclare aucun.
// Posé au chargement de la classe, donc avant tout appel — l'autoload PSR-4
// charge ce fichier dès que `Moderate::` est référencé quelque part.
Engine::setTranslator('tc');

final class Moderate extends Engine
{
}
