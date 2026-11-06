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
 *   php bin/storage-conformance.php <connexion.php> <transaction-appelant.php> <ligne-illisible.php>
 *
 * <connexion.php> charge son autoloader et rend un callable qui ouvre une
 * NOUVELLE connexion à la base à éprouver, par exemple :
 *
 *   <?php
 *   require __DIR__ . '/vendor/autoload.php';
 *   return static fn () => new MonStockage(new PDO('mysql:host=…;dbname=…', $u, $p));
 *
 * <transaction-appelant.php> charge son autoloader et rend un callable qui ouvre
 * une transaction sur une NOUVELLE connexion, passe le stockage bâti sur elle à
 * son argument, puis annule la transaction, par exemple :
 *
 *   <?php
 *   require __DIR__ . '/vendor/autoload.php';
 *   return static function (callable $travail): void {
 *       $pdo = new PDO('mysql:host=…;dbname=…', $u, $p);
 *       $pdo->beginTransaction();
 *       $travail(new MonStockage($pdo));
 *       $pdo->rollBack();
 *   };
 *
 * Sans lui, le cas « joins-callers-transaction » est sauté, et un cas sauté
 * n'est pas un cas passé : l'implémentation n'est pas déclarée conforme.
 *
 * <ligne-illisible.php> rend un callable qui reçoit un userId et un nom de
 * colonne — wrap_admin, wrap_user, created_at, updated_at, revision — et
 * réécrit, dans la base à éprouver, cette colonne de sa ligne de séquestre pour
 * qu'elle ne se lise plus, puis rend true. Il peut rendre false pour une colonne
 * de date (created_at, updated_at), quand son type n'y laisse tenir que des
 * dates que PHP lit ; s'il y admet une valeur que PHP ne lit pas ('infinity'
 * sous PostgreSQL), il l'y écrit. Toute autre colonne peut tenir une valeur
 * illisible — pour la révision, le plus grand entier que la colonne tient
 * (PHP_INT_MAX sur 64 bits), que l'écriture suivante ne peut plus monter :
 * l'adaptateur doit le refuser, et décliner la colonne fait échouer le cas,
 * comme tout retour qui n'est ni true ni false. Par exemple, pour une base où
 * les dates sont typées :
 *
 *   <?php
 *   return static function (string $userId, string $colonne): bool {
 *       $illisible = ['wrap_admin' => 'n0t-base64!!', 'wrap_user' => 'n0t-base64!!',
 *                     'revision' => PHP_INT_MAX][$colonne] ?? null;
 *       if ($illisible === null) {
 *           return false;
 *       }
 *       $pdo = new PDO('mysql:host=…;dbname=…', $u, $p);
 *       $pdo->prepare("UPDATE selfdataguard_escrow SET {$colonne} = ? WHERE user_id = ?")
 *           ->execute([$illisible, $userId]);
 *       return true;
 *   };
 *
 * Le cas « escrow-unreadable-row-named » exige alors, pour chaque colonne
 * réécrite, que loadEscrow() lève UnreadableEscrowException, et, pour la
 * révision, que replaceEscrow() sur le record lu avant lève
 * StaleEscrowException. Il ne relit pas la base : il croit que le fichier
 * réécrit la colonne qu'on lui donne. Sans ce fichier, ce cas est sauté de
 * même.
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
    fwrite(STDERR, "Usage : php bin/storage-conformance.php <connexion.php> <transaction-appelant.php> <ligne-illisible.php>\n");
    exit(3);
}
$file = (string) realpath($file);
$connect = require $file;
if (!is_callable($connect) || !($connect() instanceof StorageInterface)) {
    fwrite(STDERR, "{$file} doit rendre un callable qui ouvre un StorageInterface\n");
    exit(3);
}

$callerFile = $argv[2] ?? null;
$withCaller = null;
if ($callerFile !== null) {
    if (!is_file($callerFile) || !is_callable($withCaller = require $callerFile)) {
        fwrite(STDERR, "{$callerFile} doit rendre un callable qui exécute son argument dans une transaction qu'il annule\n");
        exit(3);
    }
}

$unparseFile = $argv[3] ?? null;
$unparse = null;
if ($unparseFile !== null) {
    if (!is_file($unparseFile) || !is_callable($unparse = require $unparseFile)) {
        fwrite(STDERR, "{$unparseFile} doit rendre un callable qui rend illisible une colonne de la ligne de séquestre du userId qu'il reçoit\n");
        exit(3);
    }
}
$results = Conformance::run($connect, $file, $withCaller, $unparse);
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
