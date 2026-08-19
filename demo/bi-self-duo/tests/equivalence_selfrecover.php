<?php

declare(strict_types=1);

/**
 * La bibliothèque tient-elle sur le schéma de cette démo ?
 *
 * Les sondes de `bi-self/selfrecover` tournent sur un stockage en mémoire, et
 * celles du lab sur PDO. Ici on parle à SQLite par l'extension `SQLite3` : c'est
 * un troisième chemin, et il se vérifie avant de toucher aux endpoints servis.
 *
 * Usage : php tests/equivalence_selfrecover.php
 */

require __DIR__ . '/../lib/StockageSelfRecover.php';

use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Recovery\Recovery;

$passes = 0;
$echecs = 0;

function verifier(string $intitule, bool $condition, string $detail = ''): void
{
    global $passes, $echecs;
    $condition ? $passes++ : $echecs++;
    echo ($condition ? "  \u{2705} " : "  \u{274C} ") . $intitule . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$db = new SQLite3(':memory:');
$db->exec((string) file_get_contents(__DIR__ . '/../schemas/selfrecover.sql'));

$MOT = str_repeat('a1', 32);
$PHR = 'cheval agrafe batterie correct';
$now = 1_700_000_000;

$st = $db->prepare(
    'INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at) VALUES (:u,:pw,:pa,:re,:t)'
);
foreach ([':u' => 'alice', ':pw' => Hashing::hash('initial'), ':pa' => Hashing::hash($PHR),
          ':re' => Hashing::hash($MOT), ':t' => $now] as $k => $v) {
    $st->bindValue($k, $v);
}
$st->execute();
$compteId = (int) $db->lastInsertRowID();

$stockage = new StockageSelfRecover($db);
$recovery = new Recovery($stockage, 'sel-de-la-demo-pour-la-sonde', delaiRefusUs: 0);

echo "\n→ Niveau 1 sur le schéma de la démo\n";
$r = $recovery->parPassphrase('alice', $PHR, null, $now);
verifier('la passphrase rend l\'accès', $r['ok'] === true);
$l = $db->querySingle("SELECT pw_hash, pass_hash FROM accounts WHERE id = {$compteId}", true);
verifier('pw_hash porte le mot de passe rendu', Hashing::verify($r['mot_de_passe'], $l['pw_hash']));
verifier('pass_hash porte la passphrase neuve', Hashing::verify($r['passphrase'], $l['pass_hash']));
verifier('l\'ancienne passphrase ne resert pas',
    $recovery->parPassphrase('alice', $PHR, null, $now)['ok'] === false);

echo "\n→ Niveau 2 — code et mot, sans identifiant\n";
$codes = $recovery->emettreCodes($compteId, 10, $now);
verifier('dix codes écrits', (int) $db->querySingle('SELECT COUNT(*) FROM recovery_codes') === 10);
verifier('aucun code en clair en base',
    (int) $db->querySingle("SELECT COUNT(*) FROM recovery_codes WHERE code_hash = '{$codes[0]}'") === 0);

$r2 = $recovery->parCode($codes[0], $MOT, null, $now);
verifier('code et mot rendent l\'accès sans identifiant', $r2['ok'] === true, $r2['compte'] ?? '');
verifier('le code est consommé', (int) $db->querySingle('SELECT COUNT(*) FROM recovery_codes WHERE used = 1') === 1);
verifier('neuf codes restent', ($r2['codes_restants'] ?? -1) === 9);
verifier('la passphrase est renouvelée aussi', isset($r2['passphrase']));
verifier('un code ne resert pas', $recovery->parCode($codes[0], $MOT, null, $now)['ok'] === false);

echo "\n→ Ce que le refus ne dit pas\n";
$sansMot  = $recovery->parCode($codes[1], str_repeat('b2', 32), null, $now);
$sansCode = $recovery->parCode('00000-00000', $MOT, null, $now);
verifier('code seul refusé', $sansMot['ok'] === false);
verifier('mot seul refusé', $sansCode['ok'] === false);
verifier('le message ne distingue pas les deux',
    $sansMot['message'] === $sansCode['message'], $sansMot['message']);

echo "\n→ Le facteur appareil, absent d'ici\n";
try {
    $stockage->trouverAppareil('x');
    verifier('le stockage refuse ce qu\'il ne sait pas traiter', false);
} catch (RuntimeException $e) {
    verifier('le stockage refuse ce qu\'il ne sait pas traiter', str_contains($e->getMessage(), 'device'));
}

try {
    $stockage->compterEchecsIp('10.0.0.1', 0);
    verifier('le compteur par IP refuse au lieu de rendre 0', false);
} catch (RuntimeException $e) {
    verifier('le compteur par IP refuse au lieu de rendre 0', str_contains($e->getMessage(), 'RateLimit'));
}

echo "\n→ Atomicité, sur SQLite3 réel\n";
$avant = (int) $db->querySingle('SELECT COUNT(*) FROM recovery_codes WHERE used = 1');
$stockage->commencerTransaction();
$stockage->consommerCode((int) $db->querySingle('SELECT id FROM recovery_codes WHERE used = 0'), $now);
$stockage->annulerTransaction();
verifier('une transaction annulée ne laisse rien',
    (int) $db->querySingle('SELECT COUNT(*) FROM recovery_codes WHERE used = 1') === $avant);

$stockage->commencerTransaction();
$id = (int) $db->querySingle('SELECT id FROM recovery_codes WHERE used = 0');
$stockage->consommerCode($id, $now);
$stockage->validerTransaction();
verifier('une transaction validée écrit bien',
    (int) $db->querySingle('SELECT COUNT(*) FROM recovery_codes WHERE used = 1') === $avant + 1);

echo "\n" . str_repeat('=', 63) . "\n";
printf("  Équivalence bi-self-duo ⨯ SelfRecover — %d passés, %d échoués\n", $passes, $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
