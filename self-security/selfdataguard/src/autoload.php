<?php

declare(strict_types=1);

/**
 * Lightweight PSR-4 autoloader for SelfDataGuard, used when running
 * the standalone demo without composer install.
 *
 * Maps namespace `Pierroons\SelfDataGuard\` to `src/` directory.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Pierroons\\SelfDataGuard\\';
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR;

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
