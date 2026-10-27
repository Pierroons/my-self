<?php

declare(strict_types=1);

/**
 * storage-conformance — joue le contrat de StorageInterface contre une implémentation.
 *
 * Une implémentation peut respecter chaque signature et casser le coffre sans
 * un mot : recevoir la génération et ne pas la comparer, insérer par-dessus un
 * coffre existant, trouver « alice » quand on demande « ALICE ». Cette suite
 * est ce qui les distingue ; une implémentation qui ne la passe pas n'est pas
 * conforme, et ne se met pas en service.
 *
 * Usage :
 *   php bin/storage-conformance.php <connexion.php>
 *
 * <connexion.php> charge son autoloader et rend un callable qui ouvre une
 * NOUVELLE connexion à la base à éprouver, par exemple :
 *
 *   <?php
 *   require __DIR__ . '/vendor/autoload.php';
 *   return static fn () => new MonStockage(new PDO('mysql:host=…;dbname=…', $u, $p));
 *
 * La suite écrit dans cette base, sous des userId tirés au hasard
 * (« conformance-<hex>-… »), et les efface à la fin de chaque cas. Les cas de
 * course lancent plusieurs processus PHP qui chargent le même fichier.
 *
 * Code de sortie : 0 si tous les cas passent, 1 sinon, 3 sur une erreur d'usage.
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Storage\Conformance;
use Pierroons\SelfDataGuard\Storage\StorageInterface;

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage : php bin/storage-conformance.php <connexion.php>\n");
    exit(3);
}
$file = (string) realpath($file);
$connect = require $file;
if (!is_callable($connect) || !($connect() instanceof StorageInterface)) {
    fwrite(STDERR, "{$file} doit rendre un callable qui ouvre un StorageInterface\n");
    exit(3);
}

$results = Conformance::run($connect, $file);
$failed = 0;
foreach ($results as $result) {
    $mark = ['pass' => '✅', 'fail' => '❌', 'skip' => '⏭️'][$result['outcome']];
    echo "{$mark} {$result['case']}", $result['detail'] === '' ? '' : " — {$result['detail']}", "\n";
    $failed += $result['outcome'] === 'pass' ? 0 : 1;
}
echo $failed === 0
    ? "\nConforme : les " . count($results) . " cas passent.\n"
    : "\nNon conforme : {$failed} cas sur " . count($results) . " ne passent pas.\n";
exit($failed === 0 ? 0 : 1);
