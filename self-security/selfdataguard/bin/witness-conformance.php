<?php

declare(strict_types=1);

/**
 * witness-conformance — les devoirs d'HeadWitness, joués contre un témoin.
 *
 * Usage : php bin/witness-conformance.php <temoin.php>
 *
 * <temoin.php> charge son autoloader et RENVOIE une fabrique :
 *
 *   return fn (): array => [new MonTemoin(…), static function (string $acte, string $tete): bool { … }];
 *
 * Chaque appel rend un témoin sur une réserve à lui, et le faussaire de cette réserve. Le
 * faussaire fait à la réserve ce que la machine qui sert peut lui faire SANS la clé de
 * l'instance — écrire chaque fichier que son utilisateur peut écrire, appeler chaque point
 * d'accès qu'elle joint — et rend false quand la réserve est hors de sa portée pour cet acte :
 *
 *   forge   pose $tete (« seq:hmac », ou une ligne qui n'est pas une tête) dans la réserve
 *   replay  présente de nouveau l'entrée que la réserve a gardée pour $tete, une tête plus ancienne
 *   reset   vide la réserve, l'efface ou la recommence
 *
 * Un faussaire qui en fait moins que la machine qui sert fait passer la suite pour rien.
 * À lancer avant que le témoin serve, jamais sur sa réserve de service.
 *
 * Sorties : 0 conforme · 1 au moins un cas en échec · 3 fichier qui ne rend pas une fabrique,
 * ou fabrique qui ne rend pas [HeadWitness, faussaire] · 4 rien à éprouver (NoHeadWitness,
 * qui ne prend aucune tête).
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Escrow\WitnessConformance;

$file = $argv[1] ?? '';
$make = $file !== '' && is_file($file) ? require $file : null;
if (!is_callable($make)) {
    fwrite(STDERR, "Usage : php bin/witness-conformance.php <temoin.php> — le fichier doit renvoyer une fabrique fn (): array => [HeadWitness, faussaire]\n");
    exit(3);
}
try {
    $results = WitnessConformance::run($make);
} catch (Throwable $e) {
    fwrite(STDERR, "La fabrique ne rend pas [HeadWitness, faussaire] : {$e->getMessage()}\n");
    exit(3);
}
if (count($results) === 1 && $results[0]['outcome'] === 'nothing') {
    echo "Rien à éprouver : {$results[0]['detail']}.\n";
    exit(4);
}
$failed = 0;
foreach ($results as $result) {
    echo $result['outcome'] === 'pass' ? '✅' : '❌', " {$result['case']}", $result['detail'] === '' ? '' : " — {$result['detail']}", "\n";
    $failed += $result['outcome'] === 'pass' ? 0 : 1;
}
echo $failed === 0 ? "\nConforme.\n" : "\nNon conforme : {$failed} cas ne passent pas.\n";
exit($failed === 0 ? 0 : 1);
