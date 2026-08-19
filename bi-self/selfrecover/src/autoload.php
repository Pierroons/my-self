<?php

declare(strict_types=1);

/**
 * Autoload PSR-4 sans Composer, pour les consommateurs qui n'en ont pas.
 *
 * Composer reste la voie normale (cf. composer.json) ; ce fichier existe pour
 * qu'un `require` suffise depuis une démo qui n'installe rien.
 */
spl_autoload_register(static function (string $classe): void {
    $prefixe = 'Pierroons\\SelfRecover\\';
    if (!str_starts_with($classe, $prefixe)) {
        return;
    }
    $relatif = substr($classe, strlen($prefixe));
    $chemin  = __DIR__ . '/' . str_replace('\\', '/', $relatif) . '.php';
    if (is_file($chemin)) {
        require $chemin;
    }
});
