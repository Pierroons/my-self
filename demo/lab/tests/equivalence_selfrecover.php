<?php

declare(strict_types=1);

/**
 * La bibliothèque tient-elle sur le schéma réel du lab ?
 *
 * Les sondes de `bi-self/selfrecover` tournent sur un stockage en mémoire :
 * elles prouvent que le protocole est correct, pas que l'adaptateur du lab
 * l'est. Ce fichier monte une base SQLite depuis `schema.sql`, la peuple comme
 * l'application le fait, et fait tourner la bibliothèque dessus.
 *
 * Il précède la bascule : remplacer du code servi sans cette preuve reviendrait
 * à supposer l'équivalence plutôt qu'à la vérifier.
 *
 * Usage : php tests/equivalence_selfrecover.php
 */

require __DIR__ . '/../vendor/autoload.php';
// `sr_sel_aleatoire()` — le miroir de `srEngendrerSel()` pour ce qui n'a pas de
// navigateur. Le sel est exigé depuis le 27/08 : sans lui, l'inscription refuse.
require_once __DIR__ . '/../lib/derive_cli.php';
require __DIR__ . '/../lib/StockageSelfRecover.php';
// L'adaptateur applicatif, pour éprouver le chemin que les endpoints empruntent
// et non une reconstitution. Son sel de site est détourné vers un fichier
// jetable : celui de l'instance ne doit pas bouger, un remplacement rendrait
// introuvables tous les codes déjà émis.
putenv('LAB_SITESALT_PATH=' . sys_get_temp_dir() . '/lab-sonde-sitesalt-' . getmypid());
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/recover_l3.php';

use Pierroons\MySelfLab\StockageSelfRecover;
use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Device\Device;
use Pierroons\SelfRecover\Recovery\Recovery;

$passes = 0;
$echecs = 0;

function verifier(string $intitule, bool $condition, string $detail = ''): void
{
    global $passes, $echecs;
    $condition ? $passes++ : $echecs++;
    echo ($condition ? "  \u{2705} " : "  \u{274C} ") . $intitule . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));

$MOT = str_repeat('a1', 32);
$PHR = 'cheval agrafe batterie correct';
$now = 1_700_000_000;

$pdo->prepare(
    'INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at) VALUES (?, ?, ?, ?, ?)'
)->execute(['alice', Hashing::hash('mdp-initial'), Hashing::hash($PHR), Hashing::hash($MOT), $now]);
$compteId = (int) $pdo->lastInsertId();

$stockage = new StockageSelfRecover($pdo);
$device   = new Device($stockage, delaiRefusUs: 0);
$recovery = new Recovery($stockage, 'sel-du-lab-pour-la-sonde', delaiRefusUs: 0);

echo "\n→ Niveau 1 sur le schéma réel\n";
$r = $recovery->parPassphrase('alice', $PHR, '192.0.2.1', $now);
verifier('la passphrase rend l\'accès', $r['ok'] === true);
$st = $pdo->query('SELECT pw_hash, pass_hash FROM accounts WHERE id = ' . $compteId)->fetch(PDO::FETCH_ASSOC);
verifier('la colonne pw_hash porte le mot de passe rendu', Hashing::verify($r['mot_de_passe'], $st['pw_hash']));
verifier('la colonne pass_hash porte la passphrase neuve', Hashing::verify($r['passphrase'], $st['pass_hash']));

echo "\n→ Niveau 2 sur le schéma réel\n";
$codes = $recovery->emettreCodes($compteId, 10, $now);
verifier('les codes sont écrits dans recovery_codes',
    (int) $pdo->query('SELECT COUNT(*) FROM recovery_codes')->fetchColumn() === 10);
$r2 = $recovery->parCode($codes[0], $MOT, '192.0.2.2', $now);
verifier('code et mot rendent l\'accès', $r2['ok'] === true, $r2['compte'] ?? '');
verifier('le code est marqué consommé',
    (int) $pdo->query('SELECT COUNT(*) FROM recovery_codes WHERE used = 1')->fetchColumn() === 1);

echo "\n→ Appareil sur le schéma réel\n";
$cle  = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$spki = base64_decode(implode('', array_filter(
    explode("\n", openssl_pkey_get_details($cle)['key']),
    static fn ($l) => !str_contains($l, '-----'),
)));
$pub = rtrim(strtr(base64_encode($spki), '+/', '-_'), '=');
$credId = 'cred' . str_repeat('E', 20);

$e = $device->enroler('alice', $credId, $pub, $MOT, '192.0.2.3', $now);
verifier('enrôlement écrit dans device_credentials',
    $e['ok'] === true && (int) $pdo->query('SELECT COUNT(*) FROM device_credentials')->fetchColumn() === 1);

$d = $device->ouvrirDefi($credId, $now);
verifier('le défi est écrit dans device_challenges',
    (int) $pdo->query('SELECT COUNT(*) FROM device_challenges')->fetchColumn() === 1);

openssl_sign($d['challenge'], $der, $cle, OPENSSL_ALGO_SHA256);
$lr = ord($der[3]);
$rr = ltrim(substr($der, 4, $lr), "\x00");
$ls = ord($der[4 + $lr + 1]);
$ss = ltrim(substr($der, 4 + $lr + 2, $ls), "\x00");
$sig = rtrim(strtr(base64_encode(
    str_pad($rr, 32, "\x00", STR_PAD_LEFT) . str_pad($ss, 32, "\x00", STR_PAD_LEFT)
), '+/', '-_'), '=');

$f = $device->cloreDefi($credId, $d['challenge'], $sig, $now);
verifier('signature valide → accès rendu', $f['ok'] === true);
verifier('le défi est consommé',
    (int) $pdo->query('SELECT COUNT(*) FROM device_challenges')->fetchColumn() === 0);

echo "\n→ L'attaque du 02/08 sur le schéma réel\n";
$att = $device->enroler('alice', 'cred' . str_repeat('F', 20), $pub, str_repeat('99', 32), '192.0.2.9', $now);
verifier('enrôlement sans le mot mémorisé refusé', $att['ok'] === false);
verifier('aucun appareil supplémentaire posé',
    (int) $pdo->query('SELECT COUNT(*) FROM device_credentials')->fetchColumn() === 1);

echo "\n→ Contraintes du schéma\n";
verifier('les clés étrangères sont actives',
    (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1);
verifier('les tentatives sont tracées',
    (int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn() > 0);

echo "\n→ Parcours complet par la façade Auth\n";
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$pdo2 = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo2->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));

$insc = \Pierroons\MySelfLab\Auth::register($pdo2, 'bob', $MOT, sr_sel_aleatoire(), '192.0.2.1');
verifier('inscription : dix codes remis une fois',
    ($insc['ok'] ?? false) && count($insc['credentials']['recovery_codes'] ?? []) === 10);

$niv2 = \Pierroons\MySelfLab\Auth::recoverByCode($pdo2, $insc['credentials']['recovery_codes'][0], $MOT, '192.0.2.2');
verifier('niveau 2 : code consommé, neuf restants', ($niv2['codes_restants'] ?? -1) === 9);
verifier('niveau 2 : la forme du retour est préservée',
    isset($niv2['credentials']['password'], $niv2['credentials']['passphrase'], $niv2['note']));

$niv1 = \Pierroons\MySelfLab\Auth::recoverByPassphrase($pdo2, 'bob', $niv2['credentials']['passphrase'], '192.0.2.3');
verifier('niveau 1 : la passphrase rendue au niveau 2 fonctionne', ($niv1['ok'] ?? false) === true);

$conn = \Pierroons\MySelfLab\Auth::login($pdo2, 'bob', $niv1['credentials']['password'], '192.0.2.4');
verifier('connexion avec le mot de passe rendu', ($conn['ok'] ?? false) === true);

echo "\n→ Le sel exigé à l'inscription — les cas de refus\n";
// 🔑 Ces contrôles existent parce que la garde n'en avait aucun : elle était
// lue, pas mesurée. Un `preg_match` que personne ne fait jamais échouer ne se
// distingue pas d'une ligne absente. Chacun des quatre cas ci-dessous a été
// vu rougir en retirant la garde de `Auth::register`.
//
// ⚠️ Ils ne gardent que CETTE couche. Le défaut réellement rencontré le
// 27/08/2026 vivait un étage plus haut : `public/api/register.php` et
// `public/api/recover_l3_reset.php` normalisaient le sel en minuscules AVANT
// d'appeler la garde, si bien que le cas « en majuscules » ci-dessous n'y était
// jamais atteint. Ces quatre contrôles restaient verts.
//
// La route est éprouvée à part, en HTTP, avec `LAB_DB_PATH` sur une base neuve
// — sans quoi le quota d'inscriptions par IP rend des refus qu'on prend pour
// ceux de la garde. Le canari y rougit sur le seul cas « majuscules ».
$avant = (int) $pdo2->query('SELECT COUNT(*) FROM accounts')->fetchColumn();

$refus = [
    'vide'              => '',
    'trop court'        => str_repeat('a', 16),
    'non hexadécimal'   => str_repeat('a', 31) . 'z',
    'en majuscules'     => strtoupper(sr_sel_aleatoire()),
];
$n = 0;
foreach ($refus as $intitule => $sel) {
    $r = \Pierroons\MySelfLab\Auth::register($pdo2, 'refus' . (++$n), $MOT, $sel, null);
    verifier("sel {$intitule} : inscription refusée",
        ($r['ok'] ?? true) === false && ($r['error'] ?? '') === 'invalid_salt',
        'error=' . ($r['error'] ?? 'aucune'));
}

// Le refus doit être total : rendre `ok => false` tout en ayant inséré la
// ligne laisserait un compte dont le mot mémorisé n'est dérivable par personne.
verifier('aucun compte créé par les quatre refus',
    (int) $pdo2->query('SELECT COUNT(*) FROM accounts')->fetchColumn() === $avant);

// Contre-témoin : sans lui, une garde qui refuserait TOUT rendrait les cinq
// contrôles ci-dessus verts. Un faux rouge tue une sonde autant qu'un faux vert.
$ok = \Pierroons\MySelfLab\Auth::register($pdo2, 'carol', $MOT, sr_sel_aleatoire(), null);
verifier('contre-témoin : un sel valide passe toujours', ($ok['ok'] ?? false) === true);

echo "\n→ Le sel de site refuse de servir vide\n";
// 🔑 Ce sel porte deux propriétés : il localise les codes émis et il fabrique
// le faux sel rendu aux codes inconnus. Vide, les index deviennent calculables
// et l'oracle du sel se rouvre — sans qu'aucune erreur ne survienne. Le
// répertoire absent suffisait à produire ce cas, observé le 27/08/2026.
$tmp = sys_get_temp_dir() . '/lab-sitesalt-sonde-' . getmypid();
putenv('LAB_SITESALT_PATH=' . $tmp);
try {
    foreach (['vide' => '', 'tronqué' => 'abcd'] as $intitule => $contenu) {
        file_put_contents($tmp, $contenu);
        $leve = false;
        try { \Pierroons\MySelfLab\Auth::siteSalt(); } catch (\RuntimeException $e) { $leve = true; }
        verifier("sel de site {$intitule} : refus de servir", $leve);
    }
    // Contre-témoin : une garde qui lèverait toujours passerait les deux
    // contrôles ci-dessus sans rien mesurer.
    unlink($tmp);
    $engendre = \Pierroons\MySelfLab\Auth::siteSalt();
    verifier('sel de site absent : engendré, et de longueur pleine', strlen($engendre) === 64);
    verifier('sel de site stable entre deux appels',
        $engendre === \Pierroons\MySelfLab\Auth::siteSalt());
} finally {
    putenv('LAB_SITESALT_PATH');
    @unlink($tmp);
}

// ── Le niveau 3 sur le schéma réel ──────────────────────────────────────────
//
// La sonde `bi-self/selfrecover/tests/sanity_escalade.php` prouve que le
// protocole est correct sur un stockage en mémoire. Ici on vérifie que
// l'adaptateur du lab le sert : `disputes.dispute_number` porte bien le numéro,
// `init_collisions` les demandeurs concurrents, et la table `l3_gel` le gel.
//
// ⚠️ Ces contrôles ne gardent que CETTE couche. Ce que les endpoints
// `public/api/recover_l3*.php` font des paramètres avant d'appeler la
// bibliothèque n'est pas mesuré ici — c'est exactement l'étage où le défaut du
// 27/08 vivait, quand `register.php` normalisait le sel avant la garde.

echo "\n→ Le niveau 3 sur le schéma réel du lab\n";

$pdo3 = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo3->exec('PRAGMA foreign_keys = ON');
$pdo3->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));

$now3 = 1_700_000_000;
$pdo3->prepare(
    'INSERT INTO accounts (id, username, pw_hash, pass_hash, recovery_hash, recovery_salt,
                           created_at, last_login_at, login_count)
     VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    'alice', Hashing::hash('x'), Hashing::hash('y'), Hashing::hash($MOT), sr_sel_aleatoire(),
    $now3 - 400 * 86400, $now3 - 30 * 86400, 42,
]);

$stock3 = new StockageSelfRecover($pdo3);
$esc3   = new \Pierroons\SelfRecover\Recovery\Escalade(
    $stock3,
    new Recovery($stock3, 'sel-du-lab-pour-la-sonde', delaiRefusUs: 0),
    delaiRefusUs: 0,
);

$ses3 = bin2hex(random_bytes(32));
$ouv3 = $esc3->ouvrir('alice', \Pierroons\SelfRecover\Recovery\Escalade::empreinteSesame($ses3), maintenant: $now3);
verifier('un dossier s\'ouvre sur le schéma réel', ($ouv3['ok'] ?? false) === true);

$col = $pdo3->query('SELECT dispute_number, status, claim_hash FROM disputes')->fetch(PDO::FETCH_ASSOC);
verifier('la colonne dispute_number porte le numéro rendu', ($col['dispute_number'] ?? '') === ($ouv3['numero'] ?? 'x'));
verifier('la colonne claim_hash porte l\'empreinte, jamais le sésame',
    ($col['claim_hash'] ?? '') === hash('sha256', $ses3) && ($col['claim_hash'] ?? '') !== $ses3);

$esc3->ouvrir('alice', \Pierroons\SelfRecover\Recovery\Escalade::empreinteSesame('autre'), maintenant: $now3 + 5);
verifier('init_collisions compte le demandeur concurrent',
    (int) $pdo3->query('SELECT init_collisions FROM disputes')->fetchColumn() === 1);

$dep3 = $esc3->soumettre((string) $ouv3['numero'], $ses3, [
    'annee_creation' => gmdate('Y', $now3 - 400 * 86400),
    'mois_connexion' => gmdate('Y-m', $now3 - 30 * 86400),
    'frequence'      => 'souvent',
], $now3);
$fs = json_decode((string) $pdo3->query('SELECT signals_json FROM disputes')->fetchColumn(), true);
verifier('le faisceau est rangé en base', is_array($fs));
verifier('⭐ les traces d\'usage que le lab écrit désormais rendent des états calculés',
    ($fs['declaratif']['mois_connexion']['etat'] ?? '') === 'concorde'
    && ($fs['declaratif']['frequence']['etat'] ?? '') === 'concorde');

// Contre-témoin : un compte jamais connecté rend « indisponible », pas « diverge ».
$pdo3->exec('UPDATE accounts SET last_login_at = NULL, login_count = 0 WHERE id = 1');
$ses4 = bin2hex(random_bytes(32));
$pdo3->exec("UPDATE disputes SET status = 'closed'");
$ouv4 = $esc3->ouvrir('alice', \Pierroons\SelfRecover\Recovery\Escalade::empreinteSesame($ses4), maintenant: $now3 + 100);
$esc3->soumettre((string) $ouv4['numero'], $ses4, ['mois_connexion' => '2026-05'], $now3 + 100);
$st4 = $pdo3->query('SELECT signals_json FROM disputes ORDER BY id DESC LIMIT 1')->fetchColumn();
verifier('contre-témoin : un compte non tracé rend « indisponible », pas « diverge »',
    (json_decode((string) $st4, true)['declaratif']['mois_connexion']['etat'] ?? '') === 'indisponible');

echo "\n→ ⭐ Sur le schéma réel non plus, un refus ne touche pas au compte\n";

$avant3 = $pdo3->query('SELECT pw_hash, pass_hash, recovery_hash FROM accounts WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
for ($i = 0; $i < 3; $i++) {
    $pdo3->exec("UPDATE disputes SET status = 'closed' WHERE status IN ('open','awaiting_admin')");
    $s = bin2hex(random_bytes(32));
    $o = $esc3->ouvrir('alice', \Pierroons\SelfRecover\Recovery\Escalade::empreinteSesame($s), maintenant: $now3 + 200 + $i * 86400);
    $esc3->trancher((string) $o['numero'], 'refuse', 'arbitre', $now3 + 300 + $i * 86400);
}
$apres3 = $pdo3->query('SELECT pw_hash, pass_hash, recovery_hash FROM accounts WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

verifier('la ligne du compte existe toujours après trois refus',
    (int) $pdo3->query('SELECT COUNT(*) FROM accounts WHERE id = 1')->fetchColumn() === 1);
verifier('ses trois empreintes sont inchangées', $avant3 === $apres3);
verifier('le gel est posé dans la table l3_gel, et pas ailleurs',
    (int) $pdo3->query('SELECT COUNT(*) FROM l3_gel WHERE account_id = 1')->fetchColumn() === 1);
verifier('banned_until n\'a PAS été posé — c\'est la procédure qui gèle, pas le compte',
    (int) ($pdo3->query('SELECT COALESCE(banned_until, 0) FROM accounts WHERE id = 1')->fetchColumn()) === 0);

$gele3 = $esc3->ouvrir('alice', \Pierroons\SelfRecover\Recovery\Escalade::empreinteSesame('encore'), maintenant: $now3 + 400 + 2 * 86400);
verifier('et l\'ouverture est bien refusée pendant le gel', ($gele3['error'] ?? '') === 'gele');

echo "\n→ ⭐ La date d'émission de la passphrase, sur le schéma réel\n";

// Trois endroits émettent une passphrase : l'inscription, la récupération L1, et
// le ré-enrôlement L3. Un seul oublié, et un papier imprimé à l'instant
// s'afficherait avec l'âge de celui qu'il remplace — devant quelqu'un qui sort
// précisément d'une perte totale.

$pdoP = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdoP->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));
$T0   = 1_700_000_000;
$QUATRE_ANS = 4 * 365 * 86400;
$pdoP->prepare('INSERT INTO accounts (id, username, pw_hash, pass_hash, recovery_hash, recovery_salt,
                                      created_at, pass_emise_le) VALUES (1, ?, ?, ?, ?, ?, ?, ?)')
     ->execute(['alice', 'x', Hashing::hash('cheval agrafe batterie correct'), 'x',
                str_repeat('b', 32), $T0 - $QUATRE_ANS, $T0 - $QUATRE_ANS]);

$stP  = new StockageSelfRecover($pdoP);
$recP = new Recovery($stP, 'sel-de-la-sonde', delaiRefusUs: 0);

$lu = $stP->trouverComptePourPassphrase('alice');
verifier('la date d\'émission remonte de la base', ($lu['emise_le'] ?? null) === $T0 - $QUATRE_ANS);

$rP = $recP->parPassphrase('alice', 'cheval agrafe batterie correct', null, $T0);
verifier('⭐ une passphrase de quatre ans ouvre encore, sur le schéma réel',
    ($rP['ok'] ?? false) === true, (string) ($rP['message'] ?? ''));
verifier('son âge est rendu', ($rP['age_jours'] ?? null) === 1460, var_export($rP['age_jours'] ?? null, true));

$apresP = (int) $pdoP->query('SELECT pass_emise_le FROM accounts WHERE id = 1')->fetchColumn();
verifier('⭐ la neuve est estampillée d\'aujourd\'hui, pas de l\'âge de l\'ancienne',
    $apresP > $T0 - 86400, gmdate('Y-m-d', $apresP));

// ⚠️ Un compte antérieur à la colonne rend `null`, jamais zéro : zéro se lirait
// « émise en 1970 » et afficherait cinquante-six ans à qui vient de s'inscrire.
$pdoP->exec('UPDATE accounts SET pass_emise_le = NULL WHERE id = 1');
$muetP = $stP->trouverComptePourPassphrase('alice');
verifier('contre-témoin : sans date, la lecture rend null et non zéro',
    array_key_exists('emise_le', $muetP) && $muetP['emise_le'] === null);

// Le faisceau du niveau 3 porte le fait, pour l'arbitre.
$faitsP = $stP->faitsDuCompte(1);
// ⚠️ `array_key_exists`, pas `??` : l'opérateur avale le `null` et rendrait la
// valeur par défaut, si bien que le contrôle testerait l'inverse de son intitulé.
verifier('le faisceau reçoit « on ne sait pas » quand la date manque',
    array_key_exists('passphrase_emise_le', $faitsP['faits_locaux'] ?? [])
    && $faitsP['faits_locaux']['passphrase_emise_le'] === null);
$pdoP->prepare('UPDATE accounts SET pass_emise_le = ? WHERE id = 1')->execute([$T0 - $QUATRE_ANS]);
$faitsP2 = $stP->faitsDuCompte(1);
verifier('⭐ et la date quand elle existe — un arbitre voit depuis quand le secours dormait',
    ($faitsP2['faits_locaux']['passphrase_emise_le'] ?? null) === gmdate('Y-m-d', $T0 - $QUATRE_ANS),
    (string) ($faitsP2['faits_locaux']['passphrase_emise_le'] ?? '—'));

echo "\n→ ⭐ Le dégel est atteignable, et il rend la porte\n";

// Trois textes du module promettent qu'un arbitre lève le gel. Jusqu'ici
// `degeler()` n'avait aucun appelant : la promesse était vraie dans la
// bibliothèque et fausse partout où quelqu'un aurait pu s'en servir.

$gelAvant = (int) $pdo3->query('SELECT gele_jusqu_a FROM l3_gel WHERE account_id = 1')->fetchColumn();
verifier('contre-témoin : le gel court bien avant qu\'on y touche', $gelAvant > $now3);

$deg = \Pierroons\MySelfLab\RecoverL3::adminUnfreeze($pdo3, 'alice', 'arbitre-nommé');
verifier('⭐ le dégel passe par l\'adaptateur et réussit', ($deg['ok'] ?? false) === true,
    (string) ($deg['error'] ?? ''));

$apres = $pdo3->query('SELECT gele_jusqu_a, degele_par FROM l3_gel WHERE account_id = 1')
              ->fetch(PDO::FETCH_ASSOC);
verifier('le gel est levé', (int) $apres['gele_jusqu_a'] === 0);
verifier('⭐ et la ligne dit QUI a levé — pas « admin » pour tout le monde',
    $apres['degele_par'] === 'arbitre-nommé', (string) $apres['degele_par']);

$rouvre = $esc3->ouvrir('alice', \Pierroons\SelfRecover\Recovery\Escalade::empreinteSesame('apres degel'),
    maintenant: $now3 + 500 + 2 * 86400);
verifier('⭐ la porte est rendue : l\'ouverture repasse', ($rouvre['ok'] ?? false) === true,
    (string) ($rouvre['error'] ?? ''));

// ⚠️ Un gel ÉCHU ne doit pas se présenter comme un gel : la console afficherait
// « gelée jusqu\'au <date passée> » et proposerait de lever ce qui n\'existe plus.
$pdo3->prepare('UPDATE l3_gel SET gele_jusqu_a = ? WHERE account_id = 1')->execute([time() - 3600]);
$liste = (new \Pierroons\MySelfLab\StockageSelfRecover($pdo3))->listerLitiges(10);
verifier('⭐ un gel échu n\'est pas remonté à la console',
    array_sum(array_map(static fn (array $l): int => (int) ($l['gele_jusqu_a'] ?? 0), $liste)) === 0);

$pdo3->prepare('UPDATE l3_gel SET gele_jusqu_a = ? WHERE account_id = 1')->execute([time() + 86400]);
$liste2 = (new \Pierroons\MySelfLab\StockageSelfRecover($pdo3))->listerLitiges(10);
verifier('contre-témoin : un gel qui court, lui, est bien remonté',
    array_sum(array_map(static fn (array $l): int => (int) ($l['gele_jusqu_a'] ?? 0), $liste2)) > 0);

echo "\n→ Les gardes des endpoints d'arbitrage\n";

// Un endpoint d'arbitrage sans garde est une console ouverte. Contrôle
// structurel : la bibliothèque ne connaît pas les rôles, c'est ici que ça tient.
foreach (['admin_unfreeze.php' => ['require_method', 'require_admin', 'require_csrf'],
          'admin_dispute_decide.php' => ['require_method', 'require_admin', 'require_csrf']] as $f => $gardes) {
    $src = (string) file_get_contents(__DIR__ . '/../public/api/' . $f);
    foreach ($gardes as $g) {
        verifier("{$f} pose {$g}()", str_contains($src, $g . '('));
    }
    verifier("{$f} ne prend pas l'identité de l'arbitre dans le corps de la requête",
        !preg_match('/\$body\[.(par|admin|auteur|username_admin).\]/', $src));
}

echo "\n" . str_repeat('=', 63) . "\n";
printf("  Équivalence lab ⨯ SelfRecover — %d passés, %d échoués\n", $passes, $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
