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

echo "\n→ Atomicité\n";

/** Stockage qui échoue à la dernière écriture d'une récupération réussie. */
final class StockageQuiCasse extends StockageMemoire
{
    public function revoquerSessions(int $compteId): void
    {
        throw new RuntimeException('panne simulée après consommation du code');
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

echo "\n" . str_repeat('=', 63) . "\n";
printf("  Récupération SelfRecover — %d passés, %d échoués\n", $passes, $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
