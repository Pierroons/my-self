<?php

declare(strict_types=1);

/**
 * Sonde des niveaux 1 et 2 de récupération.
 *
 * Deux propriétés y comptent plus que le parcours nominal : un secret consommé
 * ne resert pas, et aucun refus ne dit lequel des deux facteurs a échoué.
 *
 * Usage : php tests/sanity_recovery.php
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/StockageMemoire.php';

use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Recovery\Recovery;
use Pierroons\SelfRecover\Tests\StockageMemoire;

$passes = 0;
$echecs = 0;

function verifier(string $intitule, bool $condition, string $detail = ''): void
{
    global $passes, $echecs;
    $condition ? $passes++ : $echecs++;
    echo ($condition ? "  \u{2705} " : "  \u{274C} ") . $intitule . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$MOT  = str_repeat('a1', 32);
$PHR  = 'cheval agrafe batterie correct';
$SEL  = 'sel-de-deploiement-pour-la-sonde';
$now  = 1_700_000_000;

function neuf(string $mot, string $phrase, string $sel): array
{
    $st = new StockageMemoire();
    $st->comptes['alice']     = ['id' => 1, 'empreinte_mot' => Hashing::hash($mot)];
    $st->passphrases['alice'] = ['id' => 1, 'empreinte_passphrase' => Hashing::hash($phrase)];

    return [$st, new Recovery($st, $sel, delaiRefusUs: 0)];
}

// ── Niveau 1 ───────────────────────────────────────────────────────────────
echo "\n→ Niveau 1 — passphrase\n";
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$r = $rec->parPassphrase('alice', $PHR, '192.0.2.1', $now);
verifier('la bonne passphrase rend l\'accès', $r['ok'] === true && isset($r['mot_de_passe']));
verifier('une passphrase neuve est émise',
    isset($r['passphrase']) && $r['passphrase'] !== $PHR, $r['passphrase'] ?? '—');
verifier('l\'ancienne passphrase ne resert pas',
    $rec->parPassphrase('alice', $PHR, '192.0.2.1', $now)['ok'] === false);
verifier('les sessions sont révoquées', $st->sessionsRevoquees === [1]);

[$st, $rec] = neuf($MOT, $PHR, $SEL);
verifier('les espaces surnuméraires sont tolérés',
    $rec->parPassphrase('alice', '  cheval   agrafe batterie  correct ', null, $now)['ok'] === true);

[$st, $rec] = neuf($MOT, $PHR, $SEL);
$mauvaise = $rec->parPassphrase('alice', 'mauvaise phrase ici maintenant', null, $now);
$inconnu  = $rec->parPassphrase('personne', 'mauvaise phrase ici maintenant', null, $now);
verifier('compte inconnu et passphrase fausse : même message',
    $mauvaise['message'] === $inconnu['message'], $mauvaise['message']);

echo "\n→ ⭐ La date d'émission informe, elle n'expire rien\n";

// La question posée en séance était : faut-il faire expirer une passphrase L1 ?
// Non — elle sert quand tout le reste est perdu, parfois des années après, et
// l'expiration la tuerait au moment précis où elle sert, sans que personne
// puisse le savoir avant d'essayer. Ce qui borne le vol d'un papier est l'usage
// unique, pas une échéance. Ces contrôles gardent cette décision.

$QUATRE_ANS = 4 * 365 * 86400;
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$st->passphrases['alice']['emise_le'] = $now - $QUATRE_ANS;
$st->horloge = $now;

$vieille = $rec->parPassphrase('alice', $PHR, null, $now);
verifier('⭐ une passphrase de quatre ans ouvre encore — rien n\'expire',
    ($vieille['ok'] ?? false) === true, (string) ($vieille['message'] ?? ''));
verifier('et son âge est rendu, pour informer', ($vieille['age_jours'] ?? null) === 1460,
    var_export($vieille['age_jours'] ?? null, true));
verifier('⭐ la neuve repart à zéro — un papier imprimé aujourd\'hui n\'a pas l\'âge de celui qu\'il remplace',
    ($st->passphrases['alice']['emise_le'] ?? null) === $now);

// ⚠️ Un déploiement qui ne tient pas la date rend `null`, jamais zéro : zéro se
// lirait « émise en 1970 » et afficherait cinquante-six ans à qui vient de
// s'inscrire — le même piège que `derniere_connexion` dans le faisceau du L3.
[$st2, $rec2] = neuf($MOT, $PHR, $SEL);
$muet = $rec2->parPassphrase('alice', $PHR, null, $now);
verifier('contre-témoin : sans date tenue, l\'âge vaut null et l\'accès passe quand même',
    ($muet['ok'] ?? false) === true && array_key_exists('age_jours', $muet) && $muet['age_jours'] === null);

// ── Niveau 2 ───────────────────────────────────────────────────────────────
echo "\n→ Niveau 2 — code de récupération et mot mémorisé\n";
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$codes = $rec->emettreCodes(1, 10, $now);
verifier('un lot de 10 codes est émis', count($codes) === 10);
verifier('les codes sont tous différents', count(array_unique($codes)) === 10);
verifier('aucun code n\'est stocké en clair',
    !in_array($codes[0], array_column($st->codes, 'empreinte'), true)
    && !in_array($codes[0], array_column($st->codes, 'index'), true));

$r2 = $rec->parCode($codes[0], $MOT, '192.0.2.2', $now);
verifier('code et mot corrects rendent l\'accès', $r2['ok'] === true && isset($r2['mot_de_passe']));
verifier('aucun identifiant n\'a été demandé', ($r2['compte'] ?? '') === 'alice');
verifier('il reste neuf codes', ($r2['codes_restants'] ?? -1) === 9);
verifier('la passphrase est renouvelée aussi',
    isset($r2['passphrase']) && $r2['passphrase'] !== $PHR);
verifier('l\'ancienne passphrase ne resert pas après un niveau 2',
    $rec->parPassphrase('alice', $PHR, null, $now)['ok'] === false);
verifier('la passphrase rendue fonctionne',
    $rec->parPassphrase('alice', $r2['passphrase'], null, $now)['ok'] === true);
verifier('un code ne resert pas', $rec->parCode($codes[0], $MOT, null, $now)['ok'] === false);

[$st, $rec] = neuf($MOT, $PHR, $SEL);
$codes = $rec->emettreCodes(1, 10, $now);
$sansMot  = $rec->parCode($codes[1], str_repeat('b2', 32), null, $now);
$sansCode = $rec->parCode('00000-00000', $MOT, null, $now);
verifier('code seul refusé', $sansMot['ok'] === false);
verifier('mot seul refusé', $sansCode['ok'] === false);
verifier('un code mal formé est refusé sans chercher',
    $rec->parCode('pas-un-code', $MOT, null, $now)['ok'] === false);
verifier('le refus ne dit pas lequel a échoué',
    $sansMot['message'] === $sansCode['message'], $sansMot['message']);
verifier('un mot non dérivé est refusé pour sa forme',
    ($rec->parCode($codes[2], 'mot-en-clair', null, $now)['error'] ?? '') === 'invalid_derived_key');

echo "\n→ Émission\n";
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$rec->emettreCodes(1, 10, $now);
$second = $rec->emettreCodes(1, 10, $now);
verifier('une régénération périme le lot précédent', $st->compterCodesRestants(1) === 10);
verifier('le nouveau lot fonctionne', $rec->parCode($second[0], $MOT, null, $now)['ok'] === true);

echo "\n→ Freins\n";
[$st, $rec] = neuf($MOT, $PHR, $SEL);
for ($i = 0; $i < 5; $i++) { $rec->parPassphrase('alice', 'faux faux faux faux', '192.0.2.3', $now); }
verifier('cinq échecs bloquent le compte',
    str_contains($rec->parPassphrase('alice', $PHR, '192.0.2.3', $now)['message'], 'Trop de tentatives'));

echo "\n→ Le frein par compte du niveau 2\n";

// Les essais passent SANS origine : derrière un service caché il n'y en a pas,
// et c'est la configuration où le frein par compte est le seul rempart. Si ces
// cas passaient avec une adresse, le frein par origine suffirait à les expliquer.
$FAUX = str_repeat('c3', 32);
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$codes = $rec->emettreCodes(1, 10, $now);
for ($i = 0; $i < 5; $i++) { $rec->parCode($codes[0], $FAUX, null, $now); }
$r = $rec->parCode($codes[0], $MOT, null, $now);
verifier('⭐ au sixième essai, même le bon mot est freiné', $r['ok'] === false
    && str_contains($r['message'], 'Trop de tentatives'), $r['message']);
// 🔑 Le refus du frein par fenêtre est celui du frein par origine, au mot près :
// nommer le compte dirait à qui détient un code que ce code en vise un vrai.
verifier('🔑 et son refus est indiscernable de celui du frein par origine',
    $r['message'] === 'Trop de tentatives. Réessaie dans 15 minutes.'
    && !isset($r['error']), $r['message']);
verifier('⭐ un AUTRE code du même compte est freiné aussi',
    str_contains($rec->parCode($codes[1], $MOT, null, $now)['message'], 'Trop de tentatives'));
verifier('⭐ un essai freiné ne consomme aucun code', $st->compterCodesRestants(1) === 10);
verifier('contre-témoin : hors de la fenêtre, le bon mot passe',
    $rec->parCode($codes[0], $MOT, null, $now + 901)['ok'] === true);

// 🔑 Le cas qui justifie le HMAC. La table des tentatives est partagée avec la
// page de connexion, qui y écrit le nom SAISI. Une étiquette devinable serait un
// compteur que n'importe qui remplit sans détenir aucun code du compte visé.
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$codes = $rec->emettreCodes(1, 10, $now);
foreach (['l2:alice', 'code:alice', 'alice', $rec->indexRecherche('alice')] as $imitation) {
    for ($i = 0; $i < 6; $i++) { $st->tracerTentative($imitation, false, null, $now); }
}
verifier('🔑 une étiquette imitée ne remplit pas le compteur du compte',
    $rec->parCode($codes[0], $MOT, null, $now)['ok'] === true);

[$st, $rec] = neuf($MOT, $PHR, $SEL);
$rec->parCode('00000-00000', $MOT, '192.0.2.9', $now);
verifier('🔑 un code introuvable ne porte aucune étiquette de compte',
    count($st->tentatives) === 1 && $st->tentatives[0]['etiquette'] === null);
verifier('mais il garde son origine, pour le frein par origine',
    $st->tentatives[0]['ip'] === '192.0.2.9');

echo "\n→ La suspension du niveau 2, et son réarmement\n";
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$codes = $rec->emettreCodes(1, 10, $now);
// Les échecs sont étalés hors de la fenêtre courte : c'est la suspension qu'on
// éprouve, pas le frein par fenêtre.
for ($i = 0; $i < 19; $i++) { $rec->parCode($codes[0], $FAUX, null, $now + $i * 1000); }
$t = $now + 19 * 1000 + 901;
verifier('à dix-neuf échecs, le niveau 2 répond encore',
    $rec->parCode($codes[0], $MOT, null, $t)['ok'] === true);

[$st, $rec] = neuf($MOT, $PHR, $SEL);
$codes = $rec->emettreCodes(1, 10, $now);
for ($i = 0; $i < 20; $i++) { $rec->parCode($codes[0], $FAUX, null, $now + $i * 1000); }
$t = $now + 20 * 1000 + 901;
$r = $rec->parCode($codes[1], $MOT, null, $t);
verifier('⭐ à vingt, le niveau 2 est suspendu, bon mot compris',
    ($r['error'] ?? '') === 'l2_suspendu', $r['message']);
verifier('⭐ la suspension ne consomme aucun code', $st->compterCodesRestants(1) === 10);
verifier('⭐ elle ne touche pas au niveau 1',
    $rec->parPassphrase('alice', $PHR, null, $t)['ok'] === true);
$neufs = $rec->emettreCodes(1, 10, $t);
verifier('⭐ un lot neuf réarme le compteur et lève la suspension',
    $rec->parCode($neufs[0], $MOT, null, $t)['ok'] === true);

// L'autre réarmement : une récupération réussie. Dix-neuf échecs, une réussite,
// puis dix-neuf de plus — trente-huit depuis l'émission, dix-neuf depuis la
// réussite : pas de suspension.
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$codes = $rec->emettreCodes(1, 10, $now);
for ($i = 0; $i < 19; $i++) { $rec->parCode($codes[0], $FAUX, null, $now + $i * 1000); }
$t = $now + 19 * 1000 + 901;
$rec->parCode($codes[0], $MOT, null, $t);
for ($i = 0; $i < 19; $i++) { $rec->parCode($codes[1], $FAUX, null, $t + 1 + $i * 1000); }
$t2 = $t + 19 * 1000 + 902;
verifier('⭐ une récupération réussie réarme aussi le compteur',
    $rec->parCode($codes[1], $MOT, null, $t2)['ok'] === true);

// 🔑 Un déploiement qui ne tient AUCUNE des trois dates de réarmement ne doit pas
// suspendre : prendre zéro pour point de départ compterait les échecs depuis 1970,
// et la date qui lèverait la suspension est précisément celle qui manque.
final class StockageSansDates extends StockageMemoire
{
    public function dateDernierCodeEmis(int $compteId): ?int
    {
        return null;
    }

    public function dateDerniereReussite(string $etiquette): ?int
    {
        return null;
    }
}

$stD = new StockageSansDates();
$stD->comptes['alice']     = ['id' => 1, 'empreinte_mot' => Hashing::hash($MOT)];
$stD->passphrases['alice'] = ['id' => 1, 'empreinte_passphrase' => Hashing::hash($PHR)];
$recD  = new Recovery($stD, $SEL, delaiRefusUs: 0);
$codesD = $recD->emettreCodes(1, 10, $now);
for ($i = 0; $i < 25; $i++) { $recD->parCode($codesD[0], $FAUX, null, $now + $i * 1000); }
$rD = $recD->parCode($codesD[0], $MOT, null, $now + 25 * 1000 + 901);
verifier('🔑 sans aucune date de réarmement, on ne suspend pas', $rD['ok'] === true,
    (string) ($rD['error'] ?? $rD['message']));

// L'autre sortie promise par le message de suspension : le niveau 1.
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$codes = $rec->emettreCodes(1, 10, $now);
for ($i = 0; $i < 20; $i++) { $rec->parCode($codes[0], $FAUX, null, $now + $i * 1000); }
$t = $now + 20 * 1000 + 901;
verifier('⭐ suspendu, comme attendu',
    ($rec->parCode($codes[1], $MOT, null, $t)['error'] ?? '') === 'l2_suspendu');
verifier('🔑 une récupération par PASSPHRASE réussie lève la suspension',
    $rec->parPassphrase('alice', $PHR, null, $t)['ok'] === true
    && $rec->parCode($codes[1], $MOT, null, $t + 1)['ok'] === true);

echo "\n→ Atomicité\n";

/** Stockage qui échoue à la dernière écriture d'une récupération réussie. */
final class StockageQuiCasse extends StockageMemoire
{
    public function revoquerSessions(int $compteId): void
    {
        throw new RuntimeException('panne simulée après consommation du code');
    }
}

/** Stockage dont la consommation arrive toujours trop tard : la course est perdue. */
final class StockagePerdLaCourse extends StockageMemoire
{
    public function consommerCode(int $codeId, int $quand): void
    {
        throw new \Pierroons\SelfRecover\Storage\CodeDejaConsomme('une autre requête est passée');
    }
}

$stC = new StockageQuiCasse();
$stC->comptes['alice']     = ['id' => 1, 'empreinte_mot' => Hashing::hash($MOT)];
$stC->passphrases['alice'] = ['id' => 1, 'empreinte_passphrase' => Hashing::hash($PHR)];
$recC  = new Recovery($stC, $SEL, delaiRefusUs: 0);
$codesC = $recC->emettreCodes(1, 3, $now);

$leve = false;
try {
    $recC->parCode($codesC[0], $MOT, null, $now);
} catch (RuntimeException $e) {
    $leve = true;
}
verifier('une panne en cours de récupération remonte', $leve);
verifier('le code n\'est pas consommé si la suite échoue', $stC->compterCodesRestants(1) === 3);
verifier('aucune empreinte n\'a été laissée à moitié écrite', !isset($stC->empreintes[1]));

// 🔑 `parCode()` lit `deja_utilise`, puis joue deux Argon2id, puis consomme. Une
// seconde requête portant le même code passe pendant ce temps : la garde ne peut
// vivre que dans l'écriture. On rejoue ici la consommation elle-même, puisque
// c'est ce que deux requêtes simultanées finissent par faire.
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$codes = $rec->emettreCodes(1, 3, $now);
$idCode = $st->codes[0]['id'];
$st->consommerCode($idCode, $now);
$deuxFois = false;
try {
    $st->consommerCode($idCode, $now);
} catch (\Pierroons\SelfRecover\Storage\CodeDejaConsomme $e) {
    $deuxFois = true;
}
verifier('🔑 un code déjà consommé refuse de l\'être une seconde fois', $deuxFois);
$inconnu = false;
try {
    $st->consommerCode(999999, $now);
} catch (\Pierroons\SelfRecover\Storage\CodeDejaConsomme $e) {
    $inconnu = true;
}
verifier('🔑 et un numéro inconnu lève aussi, comme les adaptateurs SQL', $inconnu);

// La course arrive jusqu'à `parCode()` : elle doit y devenir un refus, pas une
// erreur qui remonte — on n'atteint ce point qu'avec les DEUX facteurs bons. Le
// double rejoue l'effet de la course : la lecture voit un code libre, l'écriture
// ne trouve plus rien à consommer parce qu'une autre requête est passée entre les
// deux.
$stR = new StockagePerdLaCourse();
$stR->comptes['alice']     = ['id' => 1, 'empreinte_mot' => Hashing::hash($MOT)];
$stR->passphrases['alice'] = ['id' => 1, 'empreinte_passphrase' => Hashing::hash($PHR)];
$recR   = new Recovery($stR, $SEL, delaiRefusUs: 0);
$codesR = $recR->emettreCodes(1, 3, $now);
$rCourse = $recR->parCode($codesR[0], $MOT, null, $now);
verifier('🔑 une course sur la consommation devient un refus ordinaire',
    $rCourse['ok'] === false && $rCourse['message'] === 'Code ou mot mémorisé incorrect.',
    $rCourse['message']);

echo "\n" . str_repeat('=', 63) . "\n";
printf("  Récupération SelfRecover — %d passés, %d échoués\n", $passes, $echecs);
// Le compte est écrit ici et repris en intégration continue : un `N passés` dit
// que les cas joués ont réussi, jamais qu'aucun n'a disparu.
printf("OK — %d/%d\n", $passes, $passes + $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
