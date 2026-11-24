<?php

/**
 * La déclaration du lab au moteur de modération : une démonstration, dont les
 * messages privés passent par `dm`. Chargée par `db.php`, parce que tout ce qui
 * ouvre la base — pages, bancs, seed, outils — fait migrer le moteur, qui
 * refuse de tourner sans sa configuration.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

\Pierroons\SelfModerate\Moderate::setConfig(\Pierroons\SelfModerate\Config::demo());
