<?php

declare(strict_types=1);

use Pierroons\SelfDataGuard\Pepper\LocalPepperFile;
use Pierroons\SelfDataGuard\Pepper\Pepper;

/**
 * One pepper for the whole bench process, drawn at random: every façade the
 * bench builds on one database must be given the same one.
 */
function benchPepper(): LocalPepperFile
{
    static $pepper = null;
    if ($pepper === null) {
        $path = sys_get_temp_dir() . '/sdg-bench-pepper-' . bin2hex(random_bytes(6));
        file_put_contents($path, Pepper::TAG . ':' . bin2hex(random_bytes(32)) . "\n");
        chmod($path, 0o600);
        $pepper = new LocalPepperFile($path, '');
        unlink($path);
    }
    return $pepper;
}

/**
 * The bench pepper recorded in a database that holds vaults, as the
 * operator's pass records it — for the benches that play another upgrade
 * than the pepper's, on locks left bare (PepperForms::AcceptBare).
 */
function benchPepperRecorded(Pierroons\SelfDataGuard\Storage\StorageInterface $storage): void
{
    $storage->claimSetting(
        Pierroons\SelfDataGuard\SelfDataGuard::SETTING_PEPPER,
        Pierroons\SelfDataGuard\Pepper\Pepper::check(benchPepper())
    );
}
