<?php

declare(strict_types=1);

/**
 * keeper-conformance — le contrat d'EscrowKeeper, joué contre un gardien.
 *
 * Usage : php bin/keeper-conformance.php <gardien.php>
 *
 * <gardien.php> charge son autoloader et RENVOIE une fabrique :
 *
 *   return fn (int $delay, callable $clock): EscrowKeeper => new MonGardien(…);
 *
 * Chaque appel rend un gardien sur une réserve à lui, que la suite remplit puis jette :
 * il attend $delay secondes avant une remise et lit l'heure dans $clock, pour que la suite
 * joue le délai et l'expiration d'une annonce sans les attendre. À lancer avant que le
 * gardien serve, jamais sur sa réserve de service.
 *
 * Sorties : 0 conforme · 1 au moins un cas en échec · 3 fichier qui ne rend pas une fabrique.
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Escrow\KeeperConformance;

$file = $argv[1] ?? '';
$make = $file !== '' && is_file($file) ? require $file : null;
if (!is_callable($make)) {
    fwrite(STDERR, "Usage : php bin/keeper-conformance.php <gardien.php> — le fichier doit renvoyer une fabrique fn (int \$delay, callable \$clock): EscrowKeeper\n");
    exit(3);
}
$failed = 0;
foreach (KeeperConformance::run($make) as $result) {
    echo $result['outcome'] === 'pass' ? '✅' : '❌', " {$result['case']}", $result['detail'] === '' ? '' : " — {$result['detail']}", "\n";
    $failed += $result['outcome'] === 'pass' ? 0 : 1;
}
echo $failed === 0 ? "\nConforme.\n" : "\nNon conforme : {$failed} cas ne passent pas.\n";
exit($failed === 0 ? 0 : 1);
