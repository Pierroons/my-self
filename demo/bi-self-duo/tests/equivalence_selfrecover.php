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

echo "\n→ Le sel de dérivation, par compte\n";
// 🔑 Le schéma doit porter la colonne, sinon l'inscription range le sel nulle
// part et le compte devient irrécupérable — sans qu'aucune erreur ne survienne.
$colonnes = [];
$q = $db->query('PRAGMA table_info(accounts)');
while ($c = $q->fetchArray(SQLITE3_ASSOC)) { $colonnes[] = $c['name']; }
verifier('accounts porte recovery_salt', in_array('recovery_salt', $colonnes, true),
    implode(', ', $colonnes));

echo "\n→ La formule de dérivation, confrontée aux vecteurs figés\n";
// Ce miroir PHP existe parce que cette sonde n'a pas de navigateur. Il ne vaut
// que confronté : sans les vecteurs, il validerait sa propre copie.
$fichierVecteurs = __DIR__ . '/../../../bi-self/selfrecover/tests/vecteurs-derivation.json';
$doc = json_decode((string) file_get_contents($fichierVecteurs), true);
verifier('les vecteurs de la bibliothèque sont lisibles', is_array($doc['vecteurs'] ?? null),
    $fichierVecteurs);
$miroir = static function (string $mot, string $sel, string $mode, string $mat): string {
    $m = $mode === 'hostname' ? strtolower($mat) : $mat;

    return hash_hmac('sha256', $m . '|v2' . $sel, $mot);
};
$divergents = 0;
foreach (($doc['vecteurs'] ?? []) as $v) {
    if (!hash_equals($v['empreinte'], $miroir($v['mot'], $v['sel'], $v['mode'], $v['materiel']))) {
        $divergents++;
    }
}
verifier(sprintf('les %d vecteurs sont retrouvés', count($doc['vecteurs'] ?? [])), $divergents === 0,
    $divergents > 0 ? "{$divergents} divergent(s)" : '');
verifier('la version du document est celle que la formule emploie', ($doc['version'] ?? '') === 'v2',
    (string) ($doc['version'] ?? '?'));

echo "\n→ La route de sel ne doit pas devenir un oracle\n";
// Éprouvée par la façade, sur une vraie session de démo : c'est ce chemin-là
// qu'un visiteur emprunte, et la garde y vit.
$racineSessions = sys_get_temp_dir() . '/duo-sonde-' . getmypid();
@mkdir($racineSessions, 0700, true);
putenv('SELFRECOVER_SESSIONS_DIR=' . $racineSessions);
try {
    require_once __DIR__ . '/../lib/recover_helper.php';
    // `create` rend un tableau : la session est dans sa clé, pas à la racine.
    $ouverture = DemoSession::create('selfrecover');
    verifier('une session de démo s\'ouvre', ($ouverture['ok'] ?? false) === true,
        (string) ($ouverture['error'] ?? ''));
    $sess = $ouverture['session'];

    $selVrai = bin2hex(random_bytes(16));
    $ins = $sess->db()->prepare(
        "INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, recovery_salt, created_at)
         VALUES ('carol', 'x', 'y', 'z', :s, :t)"
    );
    $ins->bindValue(':s', $selVrai);
    $ins->bindValue(':t', time());
    $ins->execute();

    verifier('un compte connu rend SON sel',
        RecoverHelper::selDeDerivation($sess, '', 'carol') === $selVrai);

    $faux1 = RecoverHelper::selDeDerivation($sess, '', 'personne');
    $faux2 = RecoverHelper::selDeDerivation($sess, '', 'personne');
    verifier('un compte inconnu rend quand même un sel', strlen($faux1) === 32, $faux1);
    verifier('deux fois le même — donc pas de bruit qui trahirait', $faux1 === $faux2);
    verifier('et distinct du vrai', $faux1 !== $selVrai);

    $c1 = RecoverHelper::selDeDerivation($sess, 'aaaaa-bbbbb');
    $c2 = RecoverHelper::selDeDerivation($sess, 'aaaaa-bbbbb');
    verifier('idem par un code inventé', strlen($c1) === 32 && $c1 === $c2);
    verifier('un code inventé et un compte inconnu ne rendent pas le même sel', $c1 !== $faux1);

    // Contre-témoin : sans lui, une méthode qui rendrait TOUJOURS un faux sel
    // passerait les cinq contrôles ci-dessus.
    verifier('contre-témoin : le vrai sel reste distinct de tous les faux',
        $selVrai !== $faux1 && $selVrai !== $c1);
} finally {
    putenv('SELFRECOVER_SESSIONS_DIR');
    exec('rm -rf ' . escapeshellarg($racineSessions));
}

echo "\n" . str_repeat('=', 63) . "\n";
printf("  Équivalence bi-self-duo ⨯ SelfRecover — %d passés, %d échoués\n", $passes, $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
