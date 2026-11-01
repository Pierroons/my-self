<?php

declare(strict_types=1);

/**
 * keeper-conformance — le contrat d'EscrowKeeper, joué contre un gardien.
 *
 * Usage : php bin/keeper-conformance.php <gardien.php>
 *
 * <gardien.php> charge son autoloader et RENVOIE l'EscrowKeeper à éprouver. La suite
 * écrit dans sa réserve — annonces, remises, objections, têtes de journal à partir de
 * 0 : à lancer sur un gardien dont la réserve se jette, avant qu'il serve. Une réserve
 * qui a pris ces têtes refuse celles d'un vrai journal.
 *
 * Sorties : 0 conforme · 1 au moins un cas en échec · 3 fichier qui ne rend pas un gardien.
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Escrow\EscrowKeeper;
use Pierroons\SelfDataGuard\Escrow\KeeperConformance;

$file = $argv[1] ?? '';
$keeper = $file !== '' && is_file($file) ? require $file : null;
if (!$keeper instanceof EscrowKeeper) {
    fwrite(STDERR, "Usage : php bin/keeper-conformance.php <gardien.php> — le fichier doit renvoyer un EscrowKeeper\n");
    exit(3);
}
$failed = 0;
foreach (KeeperConformance::run($keeper) as $result) {
    echo $result['outcome'] === 'pass' ? '✅' : '❌', " {$result['case']}", $result['detail'] === '' ? '' : " — {$result['detail']}", "\n";
    $failed += $result['outcome'] === 'pass' ? 0 : 1;
}
echo $failed === 0 ? "\nConforme.\n" : "\nNon conforme : {$failed} cas ne passent pas.\n";
exit($failed === 0 ? 0 : 1);
