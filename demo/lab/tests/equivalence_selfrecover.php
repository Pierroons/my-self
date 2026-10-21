<?php

declare(strict_types=1);

// La base et les quatre secrets de ce banc, à lui seul — jamais ceux de `data/`.
require __DIR__ . '/instance_isolee.inc';

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
putenv('LAB_SITESALT_PATH=' . chemin_isole('.sitesalt-sonde'));
// La base qu'un secret absent consulte avant de se poser : celle de ce banc est en
// mémoire, la règle n'en voit donc aucune — et ne lit pas celle de l'instance locale.
putenv('LAB_DB_PATH=' . sys_get_temp_dir() . '/lab-sonde-sans-base-' . getmypid() . '.db');
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/recover_l3.php';

use Pierroons\MySelfLab\StockageSelfRecover;
use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Device\Device;
use Pierroons\SelfRecover\Langue;
use Pierroons\SelfRecover\ProfilDeploiement;
use Pierroons\SelfRecover\Titulaire;
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
$device   = new Device($stockage, ProfilDeploiement::CLEARWEB, 'sel-du-lab-pour-la-sonde', Langue::FR, delaiRefusUs: 0);
$recovery = new Recovery($stockage, 'sel-du-lab-pour-la-sonde', ProfilDeploiement::CLEARWEB, Langue::FR, delaiRefusUs: 0);

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

echo "\n→ Le frein par compte du niveau 2, sur le schéma réel\n";
$compter = static fn (string $ou): int => (int) $pdo->query(
    'SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND ' . $ou)->fetchColumn();
$FAUX  = str_repeat('c3', 32);
// 🔑 Ce frein-ci se mesure SANS origine, sur un protocole qui le déclare : c'est
// la configuration où il est le seul rempart, et la seule où un vert prouve que
// ce n'est pas le frein par adresse qui a refusé à sa place.
$recSansIp = new Recovery($stockage, 'sel-du-lab-pour-la-sonde',
    ProfilDeploiement::TOR_ONION, Langue::FR, delaiRefusUs: 0);
$neufs = $recSansIp->emettreCodes($compteId, 10, $now);
// La colonne accepte l'étiquette absente : la base du lab porte la contrainte
// relâchée, et c'est ici qu'on le voit, pas dans le schéma lu.
$nomAvant = $compter("username = 'alice'");
for ($i = 0; $i < 5; $i++) { $recSansIp->parCode($neufs[0], $FAUX, null, $now); }
$rF = $recSansIp->parCode($neufs[0], $MOT, null, $now);
verifier('⭐ au sixième essai, même le bon mot est freiné',
    $rF['ok'] === false && $rF['message'] === 'Trop de tentatives. Réessaie dans 15 minutes.',
    $rF['message']);
verifier('⭐ aucun code n\'a été consommé par les essais freinés',
    (int) $pdo->query('SELECT COUNT(*) FROM recovery_codes WHERE used = 1')->fetchColumn() === 0);
verifier('🔑 les cinq échecs sont sous un HMAC, aucun sous le nom du compte',
    $compter("username = 'alice'") === $nomAvant
    && $compter("username = '" . $recSansIp->etiquetteEchecsL2('alice') . "'") === 5);
$recSansIp->parCode('11111-11111', $MOT, null, $now + 901);
// 🔑 Deux propriétés distinctes, mesurées séparément : le comportement du code
// ici, la nullabilité de la colonne juste après. Un échec non rattaché porte une
// étiquette fixe — sous `NULL`, aucun filtre de préfixe ne le compte, donc le
// frein par origine ne voit pas ce chemin et la purge ne l'efface jamais.
verifier('🔑 un code introuvable s\'écrit sous une étiquette qui ne désigne aucun compte',
    $compter("username = '" . \Pierroons\SelfRecover\Etiquette::PREFIXE_L2_INCONNU . "'") === 1
    && $compter("username = '" . $recSansIp->etiquetteEchecsL2('alice') . "'") === 5);
// La colonne reste nullable, et la bibliothèque ne le prouve pas : un
// intégrateur peut y écrire NULL, le schéma doit l'accepter. Éprouvé pour
// lui-même, sinon la garantie disparaît avec le comportement qui la porte.
$pdo->prepare('INSERT INTO login_attempts (username, success, ip, attempted_at) VALUES (NULL, 0, NULL, ?)')
    ->execute([$now + 902]);
verifier('🔑 et la colonne accepte toujours l\'étiquette absente',
    $compter('username IS NULL') === 1);
// 🔑 La table est partagée avec la page de connexion du lab, qui écrit le nom
// soumis tel quel (`Auth::login`). C'est ce qui rend une étiquette en clair
// dangereuse, et ce cas-ci mesure que le HMAC la met hors d'atteinte.
$aveugle = $pdo->prepare('INSERT INTO login_attempts (username, success, ip, attempted_at) VALUES (?, 0, NULL, ?)');
foreach (['l2:alice', 'code:alice', 'l2:' . hash('sha256', 'alice')] as $imitation) {
    for ($i = 0; $i < 6; $i++) { $aveugle->execute([$imitation, $now + 902]); }
}
verifier('🔑 une étiquette imitée depuis la page de connexion ne freine personne',
    $recSansIp->parCode($neufs[1], $MOT, null, $now + 903)['ok'] === true);

echo "\n→ Appareil sur le schéma réel\n";
$cle  = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$spki = base64_decode(implode('', array_filter(
    explode("\n", openssl_pkey_get_details($cle)['key']),
    static fn ($l) => !str_contains($l, '-----'),
)));
$pub = rtrim(strtr(base64_encode($spki), '+/', '-_'), '=');
$credId = 'cred' . str_repeat('E', 20);

$e = $device->enroler('alice', $credId, $pub, $MOT, Titulaire::AUTHENTIFIE, '192.0.2.3', $now);
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
$att = $device->enroler('alice', 'cred' . str_repeat('F', 20), $pub, str_repeat('99', 32), Titulaire::AUTHENTIFIE, '192.0.2.9', $now);
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
verifier('inscription : un lot entier de codes remis une fois',
    ($insc['ok'] ?? false) && count($insc['credentials']['recovery_codes'] ?? []) === Recovery::CODES_PAR_LOT);

$niv2 = \Pierroons\MySelfLab\Auth::recoverByCode($pdo2, $insc['credentials']['recovery_codes'][0], $MOT, '192.0.2.2');
verifier('niveau 2 : code consommé, le reste du lot restant', ($niv2['codes_restants'] ?? -1) === Recovery::CODES_PAR_LOT - 1);
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

// La session que l'inscription ouvre : le cookie porte le jeton, la base son
// empreinte seule — rangé en clair, une base volée ouvrait toutes les sessions.
$rangesEnClair = $pdo2->prepare('SELECT COUNT(*) FROM app_sessions WHERE token = ?');
$rangesEnClair->execute([(string) ($ok['token'] ?? '')]);
$rangesSousEmpreinte = $pdo2->prepare('SELECT COUNT(*) FROM app_sessions WHERE token = ?');
$rangesSousEmpreinte->execute([\Pierroons\MySelfLab\Db::empreinteJeton((string) ($ok['token'] ?? ''))]);
verifier('⭐ la session ouverte à l\'inscription n\'est rangée que sous l\'empreinte de son jeton',
    ($ok['token'] ?? '') !== '' && (int) $rangesEnClair->fetchColumn() === 0 && (int) $rangesSousEmpreinte->fetchColumn() === 1);

echo "\n→ Le sel de site refuse de servir vide\n";
// 🔑 Ce sel porte deux propriétés : il localise les codes émis et il fabrique
// le faux sel rendu aux codes inconnus. Vide, les index deviennent calculables
// et l'oracle du sel se rouvre — sans qu'aucune erreur ne survienne. Le
// répertoire absent suffisait à produire ce cas, observé le 27/08/2026.
$tmp = chemin_isole('.sitesalt-vide');
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
    putenv('LAB_SITESALT_PATH=' . instance_isolee()['LAB_SITESALT_PATH']);   // rendue à l'isolement, jamais à `data/`
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
    new Recovery($stock3, 'sel-du-lab-pour-la-sonde', ProfilDeploiement::TOR_ONION, Langue::FR, delaiRefusUs: 0),
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
verifier('banned_until n\'a PAS été posé — c\'est la procédure qui gèle, pas le compte',
    (int) ($pdo3->query('SELECT COALESCE(banned_until, 0) FROM accounts WHERE id = 1')->fetchColumn()) === 0);

// 🔑 Ces trois refus sont déposés sur le compte VISÉ par un demandeur qui n'a
// fourni qu'un nom : s'ils gelaient, un tiers fermerait d'ici la dernière porte
// d'alice.
verifier('⭐ trois refus ne posent AUCUN gel — un tiers ne ferme plus la porte du titulaire',
    (int) $pdo3->query('SELECT COUNT(*) FROM l3_gel WHERE account_id = 1')->fetchColumn() === 0,
    'lignes de gel : ' . $pdo3->query('SELECT COUNT(*) FROM l3_gel WHERE account_id = 1')->fetchColumn());

// Ce qui reste du compteur, c'est un signal — et il doit traverser la façade du
// lab, sans quoi l'arbitre ne l'apprend jamais.
$pdo3->exec("UPDATE disputes SET status = 'closed' WHERE status IN ('open','awaiting_admin')");
$sigS = bin2hex(random_bytes(32));
$ouvS = $esc3->ouvrir('alice', \Pierroons\SelfRecover\Recovery\Escalade::empreinteSesame($sigS),
    maintenant: $now3 + 350 + 3 * 86400);
// ⚠️ L'horloge simulée se passe JUSQU'AU BOUT : sans elle la façade retombait
// sur `time()`, les trois refus d'avant tombaient hors de la fenêtre de trente
// jours, et `refus_dans_la_fenetre` rendait 1 là où le seuil attend 3.
$trS = \Pierroons\MySelfLab\RecoverL3::adminDecide($pdo3, (string) $ouvS['numero'], 'refuse', 'arbitre',
    $now3 + 400 + 3 * 86400);
verifier('⭐ la façade du lab relaie `gel_suggere` au seuil — sans lui, la console reste muette',
    ($trS['gel_suggere'] ?? null) === true, var_export($trS['gel_suggere'] ?? null, true));
// La façade ne relaie pas `gele` : la clé vaut toujours `false` côté
// bibliothèque, donc une console qui s'y fierait n'afficherait jamais rien, sans
// une erreur pour le dire. `gel_suggere` la remplace.
verifier('⭐ et `gele` n\'est pas relayée : une console qui s\'y fierait resterait muette sans erreur',
    !array_key_exists('gele', $trS), 'clés rendues : ' . implode(', ', array_keys($trS)));
verifier('le compte des refus dans la fenêtre est rendu, pour que l\'arbitre le lise',
    is_int($trS['refus_dans_la_fenetre'] ?? null) && $trS['refus_dans_la_fenetre'] >= 3,
    var_export($trS['refus_dans_la_fenetre'] ?? null, true));

// Contre-témoin : rien ne s'est fermé tout seul, la porte répond encore.
$libre3 = $esc3->ouvrir('alice', \Pierroons\SelfRecover\Recovery\Escalade::empreinteSesame('toujours ouverte'),
    maintenant: $now3 + 400 + 4 * 86400);
verifier('⭐ contre-témoin : après quatre refus, l\'ouverture du titulaire repasse',
    ($libre3['ok'] ?? false) === true, (string) ($libre3['error'] ?? ''));

// Et c'est l'arbitre qui ferme, nommément — le seul chemin qui reste.
$pdo3->exec("UPDATE disputes SET status = 'closed' WHERE status IN ('open','awaiting_admin')");
$gelA = \Pierroons\MySelfLab\RecoverL3::adminFreeze($pdo3, 'alice', 'arbitre-nommé',
    $now3 + 450 + 4 * 86400);
verifier('⭐ `adminFreeze` pose le gel — le lab a enfin le geste que la bibliothèque offrait',
    ($gelA['ok'] ?? false) === true, (string) ($gelA['error'] ?? ''));
$poseA = $pdo3->query('SELECT gele_jusqu_a, gele_par FROM l3_gel WHERE account_id = 1')
              ->fetch(PDO::FETCH_ASSOC);
verifier('⭐ et la ligne dit QUI a gelé — pas « admin » pour tout le monde',
    ($poseA['gele_par'] ?? null) === 'arbitre-nommé', var_export($poseA['gele_par'] ?? null, true));

$gele3 = $esc3->ouvrir('alice', \Pierroons\SelfRecover\Recovery\Escalade::empreinteSesame('encore'),
    maintenant: $now3 + 500 + 4 * 86400);
verifier('et l\'ouverture est alors refusée, sous le refus unique', ($gele3['error'] ?? '') === 'ouverture_refusee',
    (string) ($gele3['error'] ?? ''));

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
$recP = new Recovery($stP, 'sel-de-la-sonde', ProfilDeploiement::CLEARWEB, Langue::FR, delaiRefusUs: 0);

$lu = $stP->trouverComptePourPassphrase('alice');
verifier('la date d\'émission remonte de la base', ($lu['emise_le'] ?? null) === $T0 - $QUATRE_ANS);

$rP = $recP->parPassphrase('alice', 'cheval agrafe batterie correct', '192.0.2.4', $T0);
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

echo "\n→ ⭐ La sanction en cours de SelfModerate, dans les faits que lit l'arbitre du niveau 3\n";

// Moderate informe SelfRecover, il ne lui interdit rien : une sanction est un
// fait du faisceau, jamais une condition d'ouverture. Trois codes, jamais le
// motif d'un arbitre de modération — un texte libre, lu tel quel dans une autre
// console. En cours seulement : une peine purgée ne dit rien de qui demande.
$sanctionLue = static fn (): array => array_intersect_key(
    $stP->faitsDuCompte(1)['faits_locaux'] ?? [],
    array_flip(['sanction', 'sanction_jusqu_au', 'sanction_origine'])
);
$aucune = ['sanction' => null, 'sanction_jusqu_au' => null, 'sanction_origine' => null];
verifier('sans ligne de modération, les trois faits sont là, et vides', $sanctionLue() === $aucune,
    json_encode($sanctionLue()));

$jusqua = time() + 3 * 86400;
// OR REPLACE : une ligne posée avant ne doit pas faire planter le décor au lieu de faire rougir le contrôle.
$pdoP->prepare("INSERT OR REPLACE INTO member_moderation (account_id, banned_until, ban_debut, ban_origine, ban_motif, updated_at)
                VALUES (1, ?, ?, 'admin', ?, ?)")
     ->execute([$jusqua, time(), 'Insultes répétées envers trois membres, malgré deux avertissements.', time()]);
verifier('⭐ un ban d\'arbitre en cours se lit : banni, au jour, origine « arbitre »',
    $sanctionLue() === ['sanction' => 'banni', 'sanction_jusqu_au' => gmdate('Y-m-d', $jusqua), 'sanction_origine' => 'arbitre'],
    json_encode($sanctionLue(), JSON_UNESCAPED_UNICODE));
verifier('⭐ et le motif de l\'arbitre n\'en sort pas, nulle part dans les faits',
    !str_contains((string) json_encode($stP->faitsDuCompte(1), JSON_UNESCAPED_UNICODE), 'Insultes'));

$pdoP->exec("UPDATE member_moderation SET ban_origine = 'auto', ban_motif = 'reputation_zero' WHERE account_id = 1");
verifier('un ban automatique porte son code machine', ($sanctionLue()['sanction_origine'] ?? null) === 'reputation_zero');
$pdoP->exec("UPDATE member_moderation SET ban_motif = 'un texte inattendu' WHERE account_id = 1");
verifier('un motif automatique hors de la liste fermée ne sort pas : l\'origine reste vide',
    array_key_exists('sanction_origine', $sanctionLue()) && $sanctionLue()['sanction_origine'] === null);

// 🔑 Lire n'écrit pas, et c'est ici qu'on le mesure : une peine automatique échue
// d'une minute, que le moteur n'a pas encore constatée. `Moderate::getReputation()`
// la clôturerait en base avant de répondre ; appelée d'ici, une consultation de
// l'arbitre modifierait l'état qu'elle consulte. Ailleurs, la mesure serait
// verte pour rien — rien n'y serait à écrire.
$pdoP->prepare('UPDATE member_moderation SET banned_until = ? WHERE account_id = 1')->execute([time() - 60]);
$ecrituresAvant = (int) $pdoP->query('SELECT total_changes()')->fetchColumn();
$purgee = $sanctionLue();
$ecrituresApres = (int) $pdoP->query('SELECT total_changes()')->fetchColumn();
verifier('⭐ une peine purgée ne se lit plus — en cours seulement', $purgee === $aucune, json_encode($purgee));
verifier('lire les faits n\'écrit rien en base, même sur une peine échue que le moteur constaterait',
    $ecrituresApres === $ecrituresAvant, ($ecrituresApres - $ecrituresAvant) . ' écriture(s)');
$pdoP->prepare('UPDATE member_moderation SET vote_muted_until = ? WHERE account_id = 1')->execute([$jusqua]);
verifier('une suspension de vote encore en base se lit, avec la seule origine qui en ait posé',
    $sanctionLue() === ['sanction' => 'vote_suspendu', 'sanction_jusqu_au' => gmdate('Y-m-d', $jusqua), 'sanction_origine' => 'meute_detectee'],
    json_encode($sanctionLue()));

echo "\n→ ⭐ Le dégel est atteignable, et il rend la porte\n";

// Les textes du module promettent qu'un arbitre lève le gel. Une méthode de
// bibliothèque sans appelant est une promesse non tenue : les deux gestes
// s'éprouvent donc par l'adaptateur, pas par la bibliothèque seule.

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

echo "\n→ ⭐ Un second gel n'efface pas la décision de celui qui a dégelé\n";

// 🔑 Le scénario que le bouton de gel rend atteignable, et qu'aucun banc ne
// jouait : l'arbitre A gèle, l'arbitre B conteste et dégèle, A regèle. Si le
// re-gel remet `degele_par` à NULL, la décision de B disparaît de la base — sans
// trace, sans erreur, et c'est l'arbitre suivant qui en est privé.
$pdo3->exec("UPDATE disputes SET status = 'closed' WHERE status IN ('open','awaiting_admin')");
$regel = \Pierroons\MySelfLab\RecoverL3::adminFreeze($pdo3, 'alice', 'arbitre-A',
    $now3 + 600 + 4 * 86400);
verifier('le second gel est bien posé, par un autre arbitre',
    ($regel['ok'] ?? false) === true, (string) ($regel['error'] ?? ''));

$trace = $pdo3->query('SELECT gele_par, degele_par FROM l3_gel WHERE account_id = 1')
              ->fetch(PDO::FETCH_ASSOC);
verifier('⭐ il porte SON nom', ($trace['gele_par'] ?? null) === 'arbitre-A',
    var_export($trace['gele_par'] ?? null, true));
verifier('⭐⭐ et la décision de celui qui avait dégelé SURVIT au re-gel',
    ($trace['degele_par'] ?? null) === 'arbitre-nommé',
    var_export($trace['degele_par'] ?? null, true));

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
// 🔑 La liste s'ÉNUMÈRE, elle ne se recopie pas. Deux fichiers étaient nommés à
// la main, et `admin_freeze.php` est né en dehors de ce contrôle : un endpoint
// d'arbitrage neuf ne doit pas avoir à être inscrit quelque part pour être gardé.
$endpoints = glob(__DIR__ . '/../public/api/admin_*.php') ?: [];
$noms      = array_map('basename', $endpoints);
verifier('les endpoints d\'arbitrage sont énumérés — un glob vide ne prouverait rien',
    $endpoints !== [] && in_array('admin_unfreeze.php', $noms, true),
    implode(', ', $noms));
foreach ($endpoints as $chemin) {
    $f   = basename($chemin);
    $src = (string) file_get_contents($chemin);
    // Qui prend un corps écrit, et porte les trois gardes ; qui n'en prend pas
    // lit, et `require_admin` suffit. Le critère est un fait du fichier, pas une
    // déclaration qu'on lui ferait confirmer.
    $gardes = str_contains($src, 'json_in(')
        ? ['require_method', 'require_admin', 'require_csrf']
        : ['require_admin'];
    foreach ($gardes as $g) {
        verifier("{$f} pose {$g}()", str_contains($src, $g . '('));
    }
    verifier("{$f} ne prend pas l'identité de l'arbitre dans le corps de la requête",
        !preg_match('/\$body\[.(par|admin|auteur|username_admin).\]/', $src));
}

echo "\n→ ⭐ La purge, la clôture et la décision tiennent le contrat de la 0.12.4, sur le schéma réel\n";

// Les cas de référence sont ceux de `bi-self/selfrecover/tests/banc_stockage_pdo.php`
// (la purge, propriété 2 ; la clôture, propriété 22 ; la décision, propriété 23),
// rejoués contre l'adaptateur
// du lab. Sa clause est une RECOPIE de celle de la bibliothèque : le SQL appartient
// à chaque adaptateur, la règle est commune, et c'est ce banc qui dit qu'elle est
// tenue. Sans lui, le lab est resté sur l'ancienne clause sans qu'un contrôle rougisse.
$TL = 1_700_000_000;
$baseLitiges = static function (): PDO {
    $p = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $p->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));
    $p->exec("INSERT INTO accounts (id, username, pw_hash, pass_hash, recovery_hash, created_at)
              VALUES (1, 'carol', 'x', 'x', 'x', 0)");
    return $p;
};
$poserLitige = static function (PDO $p, string $numero, string $statut, int $echeance, int $depose) use ($TL): void {
    $p->prepare('INSERT INTO disputes (dispute_number, account_id, status, expires_at, submitted_at, created_at, updated_at)
                 VALUES (?, 1, ?, ?, ?, ?, ?)')->execute([$numero, $statut, $echeance, $depose, $TL - 100, $TL - 100]);
};
$numeros = static fn (PDO $p): array => $p->query('SELECT dispute_number FROM disputes ORDER BY dispute_number')
    ->fetchAll(PDO::FETCH_COLUMN);

// La purge épargne ce qui arme le gel et ce qui rend un accord.
$pdoL = $baseLitiges();
$stL  = new StockageSelfRecover($pdoL);
foreach (['open', 'refused', 'accepted', 'closed'] as $i => $statut) {
    $poserLitige($pdoL, "LIT-{$i}-{$statut}", $statut, $TL - 10, 0);
}
$annonceL = $stL->compterLitigesExpires($TL);
$effaceL  = $stL->purgerLitigesExpires($TL);
verifier('la purge emporte les périmés ordinaires, épargne refused et accepted',
    $effaceL === 2 && $numeros($pdoL) === ['LIT-1-refused', 'LIT-2-accepted'], implode(', ', $numeros($pdoL)));
verifier('son compte à blanc annonçait exactement ce qu\'elle a effacé', $annonceL === $effaceL,
    "annoncé {$annonceL}, effacé {$effaceL}");

// Un dossier DÉPOSÉ, ou qui porte un message, survit à son échéance : l'échéance
// mesure le temps qu'un arbitre a mis à venir, pas ce que le titulaire a écrit.
$pdoL2 = $baseLitiges();
$stL2  = new StockageSelfRecover($pdoL2);
$poserLitige($pdoL2, 'LIT-MUET', 'open', $TL - 10, 0);
$poserLitige($pdoL2, 'LIT-DEPOSE', 'awaiting_admin', $TL - 10, $TL - 50);
$poserLitige($pdoL2, 'LIT-ECRIT', 'open', $TL - 10, 0);
$ecritL = $stL2->trouverLitigeParNumero('LIT-ECRIT');
$stL2->ajouterMessageLitige($ecritL->id, 'user', 'je suis le titulaire', $TL - 90);
$annonceL2 = $stL2->compterLitigesExpires($TL);
$effaceL2  = $stL2->purgerLitigesExpires($TL);
verifier('⭐ un dossier déposé survit à son échéance — ses réponses attendent l\'arbitre',
    in_array('LIT-DEPOSE', $numeros($pdoL2), true), implode(', ', $numeros($pdoL2)));
verifier('⭐ un dossier qui porte un message survit aussi, et son fil avec lui',
    in_array('LIT-ECRIT', $numeros($pdoL2), true) && count($stL2->messagesDuLitige($ecritL->id)) === 1);
verifier('le dossier muet part, et le compte à blanc l\'annonçait',
    $effaceL2 === 1 && $annonceL2 === 1 && !in_array('LIT-MUET', $numeros($pdoL2), true),
    "annoncé {$annonceL2}, effacé {$effaceL2}");
verifier('l\'échéance libère quand même la place', $stL2->litigeActifDuCompte(1, $TL) === null);
// La rétention se lit dans le contrat, hérité par l'adaptateur : aucun chiffre ici.
$finRetention = $TL - 10 + StockageSelfRecover::RETENTION_DOSSIER_DEPOSE;
verifier('la veille de la fin de rétention, rien ne part',
    $stL2->compterLitigesExpires($finRetention - 1) === 0 && $stL2->purgerLitigesExpires($finRetention - 1) === 0);
$annonceR = $stL2->compterLitigesExpires($finRetention);
$effaceR  = $stL2->purgerLitigesExpires($finRetention);
verifier('⭐ la rétention passée, le dossier déposé et celui qui porte un message partent',
    $effaceR === 2 && $annonceR === 2 && $numeros($pdoL2) === [], "annoncé {$annonceR}, effacé {$effaceR}");

// La clôture CONSOMME le dossier : de deux reprises concurrentes du même accord,
// seule la première le clôt, et la seconde le sait.
$pdoK = $baseLitiges();
$stK  = new StockageSelfRecover($pdoK);
$stK->ouvrirLitige(1, 'LIT-UNE-FOIS', hash('sha256', 'sesame'), $TL, $TL + 86400);
$lk = $stK->trouverLitigeParNumero('LIT-UNE-FOIS');
$stK->cloreLitige($lk->id, $TL + 1);
$clore = static function (StockageSelfRecover $st, int $id, int $quand): string {
    try {
        $st->cloreLitige($id, $quand);
        return 'rien';
    } catch (\Pierroons\SelfRecover\Storage\LitigeDejaClos) {
        return 'LitigeDejaClos';
    } catch (\Throwable $e) {
        return get_class($e);
    }
};
$secondeK = $clore($stK, $lk->id, $TL + 2);
verifier('⭐ clore un dossier déjà clos lève LitigeDejaClos', $secondeK === 'LitigeDejaClos', $secondeK);
verifier('et ne réécrit rien',
    (int) $pdoK->query("SELECT updated_at FROM disputes WHERE id = {$lk->id}")->fetchColumn() === $TL + 1);
$poserLitige($pdoK, 'LIT-REFUSE', 'refused', $TL + 86400, 0);
$refuseK = $stK->trouverLitigeParNumero('LIT-REFUSE');
$surRefus = $clore($stK, $refuseK->id, $TL + 3);
verifier('un dossier refusé ne se clôt pas non plus — il n\'est plus actif', $surRefus === 'LitigeDejaClos', $surRefus);
verifier('et il reste refusé, compté pour le gel',
    $pdoK->query("SELECT status FROM disputes WHERE id = {$refuseK->id}")->fetchColumn() === 'refused');

// Trancher CONSOMME l'instruction : deux arbitres ont lu le même dossier en
// cours, la décision du second ne doit ni écraser celle du premier, ni rouvrir
// un dossier clos par la reprise. Escalade relit l'état avant d'écrire ; seule
// la garde de l'adaptateur tient quand les deux lectures précèdent les deux
// écritures, d'où ces cas au niveau du stockage.
$attraper = static function (callable $geste): string {
    try {
        $geste();
        return 'rien';
    } catch (\Pierroons\SelfRecover\Storage\LitigeDejaTranche) {
        return 'LitigeDejaTranche';
    } catch (\Throwable $e) {
        return get_class($e);
    }
};
$pdoT = $baseLitiges();
$stT  = new StockageSelfRecover($pdoT);
$stT->ouvrirLitige(1, 'LIT-TRANCHE', hash('sha256', 'sesame'), $TL, $TL + 86400);
$lt = $stT->trouverLitigeParNumero('LIT-TRANCHE');
$stT->enregistrerFaisceau($lt->id, '{}', $TL + 1);
verifier('contre-témoin : un dossier en attente de lecture se tranche',
    $attraper(static fn () => $stT->trancherLitige($lt->id, 'accepted', 'arbitre-1', $TL + 2)) === 'rien');
$secondT = $attraper(static fn () => $stT->trancherLitige($lt->id, 'refused', 'arbitre-2', $TL + 3));
verifier('⭐ trancher un dossier déjà tranché lève LitigeDejaTranche', $secondT === 'LitigeDejaTranche', $secondT);
$apresT = $pdoT->query("SELECT status, decided_by, decided_at FROM disputes WHERE id = {$lt->id}")->fetch(PDO::FETCH_ASSOC);
verifier('et la première décision reste, avec son auteur et sa date',
    $apresT === ['status' => 'accepted', 'decided_by' => 'arbitre-1', 'decided_at' => $TL + 2], json_encode($apresT));
verifier('contre-témoin : la reprise clôt l\'accord',
    $attraper(static fn () => $stT->cloreLitige($lt->id, $TL + 4)) === 'rien');
$surClos = $attraper(static fn () => $stT->trancherLitige($lt->id, 'refused', 'arbitre-2', $TL + 5));
verifier('⭐ un dossier clos par la reprise ne se tranche plus', $surClos === 'LitigeDejaTranche', $surClos);
verifier('et aucun refus n\'est compté contre le titulaire', $stT->compterRefusRecents(1, $TL) === 0);

// Le dépôt des réponses écrit le même statut : un dépôt parti avant la décision
// ne doit pas la défaire en repassant le dossier en attente de lecture.
$stT->ouvrirLitige(1, 'LIT-DEPOT', hash('sha256', 'sesame-depot'), $TL + 10, $TL + 86400);
$ld = $stT->trouverLitigeParNumero('LIT-DEPOT');
$deposer = static fn (int $quand): string =>
    $attraper(static fn () => $stT->enregistrerFaisceau($ld->id, '{"apres":1}', $quand));
verifier('contre-témoin : un dossier ouvert reçoit un dépôt, puis un second',
    $deposer($TL + 11) === 'rien' && $deposer($TL + 12) === 'rien');
$stT->trancherLitige($ld->id, 'refused', 'arbitre-1', $TL + 13);
$surDecide = $deposer($TL + 14);
verifier('⭐ un dépôt sur un dossier tranché lève LitigeDejaTranche', $surDecide === 'LitigeDejaTranche', $surDecide);
$apresD = $pdoT->query("SELECT status, signals_json FROM disputes WHERE id = {$ld->id}")->fetch(PDO::FETCH_ASSOC);
verifier('et le refus reste, compté pour le gel, avec le faisceau d\'avant',
    $apresD === ['status' => 'refused', 'signals_json' => '{"apres":1}'] && $stT->compterRefusRecents(1, $TL) === 1,
    json_encode($apresD));

// Un dossier périmé ne se tranche plus, et la console le DIT à l'arbitre : le
// refus vient de la bibliothèque, il doit traverser la console avec son code et
// son message, pas se perdre en erreur générique.
$pdoX = $baseLitiges();
$stX  = new StockageSelfRecover($pdoX);
$stX->ouvrirLitige(1, 'LIT-PERIME', hash('sha256', 'sesame'), $TL - 200, $TL - 10);
$rX = \Pierroons\MySelfLab\RecoverL3::adminDecide($pdoX, 'LIT-PERIME', 'grant', 'arbitre', $TL);
verifier('⭐ trancher un dossier périmé est refusé, et la console relaie le refus à l\'arbitre',
    ($rX['ok'] ?? null) === false && ($rX['error'] ?? '') === 'expire' && ($rX['code'] ?? 0) === 410
    && trim((string) ($rX['message'] ?? '')) !== '', json_encode($rX, JSON_UNESCAPED_UNICODE));

echo "\n" . str_repeat('=', 63) . "\n";
printf("  Équivalence lab ⨯ SelfRecover — %d passés, %d échoués\n", $passes, $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
