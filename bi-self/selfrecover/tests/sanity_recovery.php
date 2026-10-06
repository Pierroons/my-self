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
use Pierroons\SelfRecover\Duree;
use Pierroons\SelfRecover\ProfilDeploiement;
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

// Le banc se joue sous les DEUX profils : l'intégration continue le lance deux
// fois. L'adresse découle du profil — en tor-onion il n'y en a aucune — donc le
// même corps de banc éprouve les deux mondes au lieu d'en supposer un.
$opt    = getopt('', ['profil::']);
$PROFIL = ProfilDeploiement::from($opt['profil'] ?? 'clearweb');
$IP     = $PROFIL === ProfilDeploiement::CLEARWEB ? '192.0.2.7' : null;
echo "\n→ Profil joué : {$PROFIL->value}\n";

$MOT  = str_repeat('a1', 32);
$PHR  = 'cheval agrafe batterie correct';
$SEL  = 'sel-de-deploiement-pour-la-sonde';
$now  = 1_700_000_000;

function neuf(string $mot, string $phrase, string $sel): array
{
    global $PROFIL;
    $st = new StockageMemoire();
    $st->comptes['alice']     = ['id' => 1, 'empreinte_mot' => Hashing::hash($mot)];
    $st->passphrases['alice'] = ['id' => 1, 'empreinte_passphrase' => Hashing::hash($phrase)];

    return [$st, new Recovery($st, $sel, $PROFIL, delaiRefusUs: 0)];
}

// ── Niveau 1 ───────────────────────────────────────────────────────────────
echo "\n→ Niveau 1 — passphrase\n";
[$st, $rec] = neuf($MOT, $PHR, $SEL);
// ⭐ Contre-témoin de la révocation d'appareils : seul le NIVEAU 3 les retire.
// Aux niveaux 1 et 2, la personne a présenté un papier — ou un code ET son mot
// mémorisé : elle a prouvé quelque chose, et effacer ses appareils y serait une
// punition sans motif. La garde vit ici, dans le banc du niveau qui ne doit pas
// le faire ; une révocation glissée dans `parPassphrase()` ou `parCode()` la
// ferait rougir.
$st->enregistrerAppareil(1, 'cred-de-son-telephone', str_repeat('K', 60), $now);
$r = $rec->parPassphrase('alice', $PHR, $IP, $now);
verifier('la bonne passphrase rend l\'accès', $r['ok'] === true && isset($r['mot_de_passe']));
verifier('⭐ le niveau 1 NE retire PAS les appareils enrôlés',
    $st->trouverAppareil('cred-de-son-telephone') !== null);
verifier('une passphrase neuve est émise',
    isset($r['passphrase']) && $r['passphrase'] !== $PHR, $r['passphrase'] ?? '—');
verifier('l\'ancienne passphrase ne resert pas',
    $rec->parPassphrase('alice', $PHR, $IP, $now)['ok'] === false);
verifier('les sessions sont révoquées', $st->sessionsRevoquees === [1]);

[$st, $rec] = neuf($MOT, $PHR, $SEL);
verifier('les espaces surnuméraires sont tolérés',
    $rec->parPassphrase('alice', '  cheval   agrafe batterie  correct ', $IP, $now)['ok'] === true);

[$st, $rec] = neuf($MOT, $PHR, $SEL);
$mauvaise = $rec->parPassphrase('alice', 'mauvaise phrase ici maintenant', $IP, $now);
$inconnu  = $rec->parPassphrase('personne', 'mauvaise phrase ici maintenant', $IP, $now);
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

$vieille = $rec->parPassphrase('alice', $PHR, $IP, $now);
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
$muet = $rec2->parPassphrase('alice', $PHR, $IP, $now);
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

$r2 = $rec->parCode($codes[0], $MOT, $IP, $now);
verifier('code et mot corrects rendent l\'accès', $r2['ok'] === true && isset($r2['mot_de_passe']));
verifier('aucun identifiant n\'a été demandé', ($r2['compte'] ?? '') === 'alice');
verifier('il reste neuf codes', ($r2['codes_restants'] ?? -1) === 9);
verifier('la passphrase est renouvelée aussi',
    isset($r2['passphrase']) && $r2['passphrase'] !== $PHR);
verifier('l\'ancienne passphrase ne resert pas après un niveau 2',
    $rec->parPassphrase('alice', $PHR, $IP, $now)['ok'] === false);
verifier('la passphrase rendue fonctionne',
    $rec->parPassphrase('alice', $r2['passphrase'], $IP, $now)['ok'] === true);
verifier('un code ne resert pas', $rec->parCode($codes[0], $MOT, $IP, $now)['ok'] === false);

[$st, $rec] = neuf($MOT, $PHR, $SEL);
$codes = $rec->emettreCodes(1, 10, $now);
$sansMot  = $rec->parCode($codes[1], str_repeat('b2', 32), $IP, $now);
$sansCode = $rec->parCode('00000-00000', $MOT, $IP, $now);
verifier('code seul refusé', $sansMot['ok'] === false);
verifier('mot seul refusé', $sansCode['ok'] === false);
verifier('un code mal formé est refusé sans chercher',
    $rec->parCode('pas-un-code', $MOT, $IP, $now)['ok'] === false);
verifier('le refus ne dit pas lequel a échoué',
    $sansMot['message'] === $sansCode['message'], $sansMot['message']);
verifier('un mot non dérivé est refusé pour sa forme',
    ($rec->parCode($codes[2], 'mot-en-clair', $IP, $now)['error'] ?? '') === 'invalid_derived_key');

echo "\n→ Émission\n";
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$rec->emettreCodes(1, 10, $now);
$second = $rec->emettreCodes(1, 10, $now);
verifier('une régénération périme le lot précédent', $st->compterCodesRestants(1) === 10);
verifier('le nouveau lot fonctionne', $rec->parCode($second[0], $MOT, $IP, $now)['ok'] === true);

echo "\n→ Freins du niveau 1 — ce qu'ils ferment, et ce qu'ils ne ferment plus\n";

// Six mots qui existent tous dans les listes, et qui n'ouvrent rien — la saisie
// de qui connaît ses mots sans retrouver leur ordre. Et six qui n'existent
// nulle part : celle de qui tape au hasard. Les deux sont mesurées, pas supposées.
$PLAUSIBLE = 'maison chien voiture pantoufle garden pencil';
$INCONNUS  = 'zzqxv wmgptk qjfbhz vxntlr kdwspq bhzmfj';

// ⭐ Le défaut que cette version ferme : le compteur du niveau 1 était écrit
// sous le nom en clair, dans la table que l'intégrateur partage avec sa page de
// connexion. Vingt échecs sous le nom d'un tiers fermaient sa seule voie de
// secours autonome.
[$st, $rec] = neuf($MOT, $PHR, $SEL);
for ($i = 0; $i < 20; $i++) { $st->tracerTentative('alice', false, null, $now); }
verifier('⭐ vingt échecs plantés sous le NOM EN CLAIR ne ferment rien',
    $rec->parPassphrase('alice', $PHR, $IP, $now)['ok'] === true);

// Et le titulaire ne s'enferme pas lui-même : le frein ne refuse jamais le bon
// secret. Rien ne levait ce blocage — ni une connexion réussie, ni aucun geste.
[$st, $rec] = neuf($MOT, $PHR, $SEL);
for ($i = 0; $i < 10; $i++) { $rec->parPassphrase('alice', $INCONNUS, $IP, $now); }
verifier('⭐ dix de ses propres échecs lui laissent sa passphrase',
    $rec->parPassphrase('alice', $PHR, $IP, $now)['ok'] === true);

// 🔑 Le frein par ORIGINE, lui, ferme — et c'est le seul qui reste. Aucun banc
// ne l'éprouvait : sa branche pouvait disparaître sans qu'un seul cas rougisse.
if ($PROFIL === ProfilDeploiement::CLEARWEB) {
    [$st, $rec] = neuf($MOT, $PHR, $SEL);
    for ($i = 0; $i < 12; $i++) { $rec->parPassphrase('bob', $INCONNUS, $IP, $now); }
    $ro = $rec->parPassphrase('alice', $PHR, $IP, $now);
    verifier('⭐ douze échecs depuis la même origine ferment, eux', $ro['ok'] === false
        && $ro['message'] === 'Trop de tentatives. Réessaie dans 15 minutes.', $ro['message']);

    // Chaque contre-témoin repart d'un stockage neuf : une récupération réussie
    // remplace la passphrase, donc deux succès ne s'enchaînent pas sur le même
    // compte. Les douze échecs y sont plantés — le cas ci-dessus a déjà montré
    // que `parPassphrase()` remplit bien le compteur qu'il relit.
    foreach ([['depuis une AUTRE origine, le bon mot passe', '192.0.2.8', $now],
              ['hors de la fenêtre, la même origine passe', $IP, $now + 901]] as [$quoi, $origine, $quand]) {
        [$st, $rec] = neuf($MOT, $PHR, $SEL);
        for ($i = 0; $i < 12; $i++) {
            $st->tracerTentative($rec->etiquetteEchecsL1('bob'), false, $IP, $now);
        }
        verifier("contre-témoin : {$quoi}",
            $rec->parPassphrase('alice', $PHR, $origine, $quand)['ok'] === true);
    }
} else {
    // ⚠️ **La limite assumée du profil, mesurée plutôt que promise.** Derrière un
    // service caché il n'y a pas d'origine à compter, donc plus rien ne ferme le
    // niveau 1 : lisser le débit de la route revient au déploiement. Un banc le
    // dit, sinon personne ne sait que cette limite existe.
    [$st, $rec] = neuf($MOT, $PHR, $SEL);
    $rt = null;
    for ($i = 0; $i < 50; $i++) { $rt = $rec->parPassphrase('alice', $PLAUSIBLE, null, $now); }
    verifier('⭐ sous tor-onion, cinquante échecs ne ferment rien — rien ne borne ici',
        $rec->parPassphrase('alice', $PHR, null, $now)['ok'] === true);
    verifier('🔑 le signalement est alors le seul garde qui reste, et il répond',
        ($rt['signalement'] ?? null) === 'essais_plausibles');
    // Une origine glissée dans cette table consommerait le quota que la connexion
    // ordinaire, le niveau 2 et l'enrôlement se partagent — pour tout le monde à
    // la fois, puisque le service n'en voit qu'une.
    verifier('🔑 et aucune ligne tracée ne porte d\'origine',
        array_filter($st->tentatives, static fn (array $t): bool => $t['ip'] !== null) === []);
}

echo "\n→ Le classement : des mots qui existent tous, une porte qui ne s'ouvre pas\n";
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$r1 = $rec->parPassphrase('alice', $PLAUSIBLE, $IP, $now);
$r2 = $rec->parPassphrase('alice', $PLAUSIBLE, $IP, $now);
verifier('🔑 les deux premiers essais plausibles ne réveillent personne',
    !isset($r1['signalement']) && !isset($r2['signalement']));
$r3 = $rec->parPassphrase('alice', $PLAUSIBLE, $IP, $now);
verifier('⭐ le troisième signale', ($r3['signalement'] ?? null) === 'essais_plausibles');
// 🔑 Le signalement ne change rien de ce que voit l'utilisateur : même message,
// même issue. Il informe le déploiement, il ne décide d'aucun accès.
verifier('🔑 et son refus reste celui des autres, au mot près',
    $r3['ok'] === false && $r3['message'] === 'Identifiant ou passphrase incorrect.', $r3['message']);
verifier('🔑 un essai signalé ne ferme pas la porte au bon mot',
    $rec->parPassphrase('alice', $PHR, $IP, $now)['ok'] === true);

// ⭐ Qui tape des mots qui n'existent pas ne déclenche rien : ce n'est pas un
// essai qu'on puisse confondre avec celui d'un titulaire.
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$ri = null;
for ($i = 0; $i < 6; $i++) { $ri = $rec->parPassphrase('alice', $INCONNUS, $IP, $now); }
verifier('⭐ six essais aux mots inconnus ne signalent rien', !isset($ri['signalement']));
// Contre-témoin de la mesure elle-même : le compteur du classement est bien le
// sien, et il est resté vide pendant que l'autre se remplissait.
verifier('contre-témoin : rien ne s\'est rangé sous le compteur du classement',
    $st->compterEchecsCompte($rec->etiquetteSuspicionL1('alice'), $now - 900) === 0);

// Le plafond d'octets, seul refus que la saisie permette sans comparaison : une
// passphrase rangée n'a aucune forme garantie, sa taille a un plafond public.
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$enorme  = str_repeat('chien ', 200_000);
$debut   = hrtime(true);
$rg      = $rec->parPassphrase('alice', $enorme, $IP, $now);
$msEnorme = intdiv(hrtime(true) - $debut, 1_000_000);
$debut   = hrtime(true);
$rec->parPassphrase('alice', $INCONNUS, $IP, $now);
$msNormal = intdiv(hrtime(true) - $debut, 1_000_000);
verifier('⭐ une saisie au-delà du plafond est refusée', $rg['ok'] === false
    && $rg['message'] === 'Identifiant ou passphrase incorrect.');
verifier('⭐ et refusée SANS payer la comparaison lente',
    $msEnorme < $msNormal, "{$msEnorme} ms contre {$msNormal} ms");
verifier('🔑 elle n\'entre pas dans le classement non plus',
    !isset($rg['signalement']));

echo "\n→ Le frein par compte du niveau 2\n";

// Les essais passent SANS origine : derrière un service caché il n'y en a pas,
// et c'est la configuration où le frein par compte est le seul rempart. Si ces
// cas passaient avec une adresse, le frein par origine suffirait à les expliquer.
$FAUX = str_repeat('c3', 32);
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$codes = $rec->emettreCodes(1, 10, $now);
for ($i = 0; $i < 5; $i++) { $rec->parCode($codes[0], $FAUX, $IP, $now); }
$r = $rec->parCode($codes[0], $MOT, $IP, $now);
verifier('⭐ au sixième essai, même le bon mot est freiné', $r['ok'] === false
    && str_contains($r['message'], 'Trop de tentatives'), $r['message']);
// 🔑 Le refus du frein par fenêtre est celui du frein par origine, au mot près :
// nommer le compte dirait à qui détient un code que ce code en vise un vrai.
verifier('🔑 et son refus est indiscernable de celui du frein par origine',
    $r['message'] === 'Trop de tentatives. Réessaie dans 15 minutes.'
    && !isset($r['error']), $r['message']);
verifier('⭐ un AUTRE code du même compte est freiné aussi',
    str_contains($rec->parCode($codes[1], $MOT, $IP, $now)['message'], 'Trop de tentatives'));
verifier('⭐ un essai freiné ne consomme aucun code', $st->compterCodesRestants(1) === 10);
verifier('contre-témoin : hors de la fenêtre, le bon mot passe',
    $rec->parCode($codes[0], $MOT, $IP, $now + 901)['ok'] === true);

// Le délai annoncé est celui de la fenêtre RÉGLÉE : un intégrateur qui la
// raccourcit ne doit pas lire un « 15 minutes » recopié.
$st10 = new StockageMemoire();
$st10->comptes['alice'] = ['id' => 1, 'empreinte_mot' => Hashing::hash($MOT)];
$rec10   = new Recovery($st10, $SEL, $PROFIL, fenetreEchecs: 600, delaiRefusUs: 0);
$codes10 = $rec10->emettreCodes(1, 10, $now);
for ($i = 0; $i < 5; $i++) { $rec10->parCode($codes10[0], $FAUX, $IP, $now); }
$r10 = $rec10->parCode($codes10[0], $MOT, $IP, $now);
verifier('le refus annonce la fenêtre réglée, pas un délai recopié',
    $r10['message'] === 'Trop de tentatives. Réessaie dans 10 minutes.', $r10['message']);
verifier('une durée se dit comme un message la dit, arrondie au-dessus',
    Duree::enClair(900) === '15 minutes' && Duree::enClair(3600) === '1 heure'
    && Duree::enClair(604800) === '7 jours' && Duree::enClair(90) === '2 minutes'
    && Duree::enClair(1) === '1 minute');

// 🔑 Le cas qui justifie le HMAC. La table des tentatives est partagée avec la
// page de connexion, qui y écrit le nom SAISI. Une étiquette devinable serait un
// compteur que n'importe qui remplit sans détenir aucun code du compte visé.
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$codes = $rec->emettreCodes(1, 10, $now);
foreach (['l2:alice', 'code:alice', 'alice', $rec->indexRecherche('alice')] as $imitation) {
    for ($i = 0; $i < 6; $i++) { $st->tracerTentative($imitation, false, null, $now); }
}
verifier('🔑 une étiquette imitée ne remplit pas le compteur du compte',
    $rec->parCode($codes[0], $MOT, $IP, $now)['ok'] === true);

[$st, $rec] = neuf($MOT, $PHR, $SEL);
$rec->parCode('00000-00000', $MOT, $IP, $now);
verifier('🔑 un code introuvable ne porte aucune étiquette de compte',
    count($st->tentatives) === 1 && $st->tentatives[0]['etiquette'] === null);
verifier('mais il garde son origine, pour le frein par origine',
    $st->tentatives[0]['ip'] === $IP);

echo "\n→ La suspension du niveau 2, et son réarmement\n";
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$codes = $rec->emettreCodes(1, 10, $now);
// Les échecs sont étalés hors de la fenêtre courte : c'est la suspension qu'on
// éprouve, pas le frein par fenêtre.
for ($i = 0; $i < 19; $i++) { $rec->parCode($codes[0], $FAUX, $IP, $now + $i * 1000); }
$t = $now + 19 * 1000 + 901;
verifier('à dix-neuf échecs, le niveau 2 répond encore',
    $rec->parCode($codes[0], $MOT, $IP, $t)['ok'] === true);

[$st, $rec] = neuf($MOT, $PHR, $SEL);
$codes = $rec->emettreCodes(1, 10, $now);
for ($i = 0; $i < 20; $i++) { $rec->parCode($codes[0], $FAUX, $IP, $now + $i * 1000); }
$t = $now + 20 * 1000 + 901;
$r = $rec->parCode($codes[1], $MOT, $IP, $t);
verifier('⭐ à vingt, le niveau 2 est suspendu, bon mot compris',
    ($r['error'] ?? '') === 'l2_suspendu', $r['message']);
verifier('⭐ la suspension ne consomme aucun code', $st->compterCodesRestants(1) === 10);
verifier('⭐ elle ne touche pas au niveau 1',
    $rec->parPassphrase('alice', $PHR, $IP, $t)['ok'] === true);
$neufs = $rec->emettreCodes(1, 10, $t);
verifier('⭐ un lot neuf réarme le compteur et lève la suspension',
    $rec->parCode($neufs[0], $MOT, $IP, $t)['ok'] === true);

// L'autre réarmement : une récupération réussie. Dix-neuf échecs, une réussite,
// puis dix-neuf de plus — trente-huit depuis l'émission, dix-neuf depuis la
// réussite : pas de suspension.
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$codes = $rec->emettreCodes(1, 10, $now);
for ($i = 0; $i < 19; $i++) { $rec->parCode($codes[0], $FAUX, $IP, $now + $i * 1000); }
$t = $now + 19 * 1000 + 901;
$rec->parCode($codes[0], $MOT, $IP, $t);
for ($i = 0; $i < 19; $i++) { $rec->parCode($codes[1], $FAUX, $IP, $t + 1 + $i * 1000); }
$t2 = $t + 19 * 1000 + 902;
verifier('⭐ une récupération réussie réarme aussi le compteur',
    $rec->parCode($codes[1], $MOT, $IP, $t2)['ok'] === true);

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
$recD  = new Recovery($stD, $SEL, $PROFIL, delaiRefusUs: 0);
$codesD = $recD->emettreCodes(1, 10, $now);
for ($i = 0; $i < 25; $i++) { $recD->parCode($codesD[0], $FAUX, $IP, $now + $i * 1000); }
$rD = $recD->parCode($codesD[0], $MOT, $IP, $now + 25 * 1000 + 901);
verifier('🔑 sans aucune date de réarmement, on ne suspend pas', $rD['ok'] === true,
    (string) ($rD['error'] ?? $rD['message']));

// L'autre sortie promise par le message de suspension : le niveau 1.
[$st, $rec] = neuf($MOT, $PHR, $SEL);
$codes = $rec->emettreCodes(1, 10, $now);
for ($i = 0; $i < 20; $i++) { $rec->parCode($codes[0], $FAUX, $IP, $now + $i * 1000); }
$t = $now + 20 * 1000 + 901;
verifier('⭐ suspendu, comme attendu',
    ($rec->parCode($codes[1], $MOT, $IP, $t)['error'] ?? '') === 'l2_suspendu');
verifier('🔑 une récupération par PASSPHRASE réussie lève la suspension',
    $rec->parPassphrase('alice', $PHR, $IP, $t)['ok'] === true
    && $rec->parCode($codes[1], $MOT, $IP, $t + 1)['ok'] === true);

echo "\n→ Le profil de déploiement, et ce qu'il refuse\n";

// 🔑 Les deux refus se jouent ici quel que soit le profil sous lequel le banc
// tourne, en construisant le profil contraire — sinon ils ne s'éprouveraient
// qu'une fois sur deux.
[$stP, $_] = neuf($MOT, $PHR, $SEL);
$recOnion = new Recovery($stP, $SEL, ProfilDeploiement::TOR_ONION, delaiRefusUs: 0);
$recClair = new Recovery($stP, $SEL, ProfilDeploiement::CLEARWEB, delaiRefusUs: 0);

$leve = static function (callable $appel): bool {
    try {
        $appel();

        return false;
    } catch (\InvalidArgumentException) {
        return true;
    }
};

verifier('⭐ en tor-onion, une origine transmise est refusée',
    $leve(static fn () => $recOnion->parPassphrase('alice', $PHR, '192.0.2.8', $now)));
verifier('⭐ et sur la récupération par code aussi',
    $leve(static fn () => $recOnion->parCode('00000-00000', $MOT, '192.0.2.8', $now)));
verifier('en tor-onion, aucune origine passe',
    !$leve(static fn () => $recOnion->parCode('00000-00000', $MOT, null, $now)));

verifier('⭐ en clearweb, une origine absente est refusée',
    $leve(static fn () => $recClair->parPassphrase('alice', $PHR, null, $now)));
verifier('⭐ et une origine vide aussi — elle ne distingue personne',
    $leve(static fn () => $recClair->parCode('00000-00000', $MOT, '   ', $now)));
verifier('en clearweb, une origine passe',
    !$leve(static fn () => $recClair->parCode('00000-00000', $MOT, '192.0.2.8', $now)));

// 🔑 Le refus tombe AVANT toute écriture : une erreur d'intégration ne doit pas
// laisser de trace qui chargerait un compteur.
$avant = count($stP->tentatives);
$leve(static fn () => $recOnion->parPassphrase('alice', $PHR, '192.0.2.8', $now));
verifier('🔑 un appel refusé par le profil ne trace rien', count($stP->tentatives) === $avant,
    sprintf('%d avant, %d après', $avant, count($stP->tentatives)));

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
$recC  = new Recovery($stC, $SEL, $PROFIL, delaiRefusUs: 0);
$codesC = $recC->emettreCodes(1, 3, $now);

$leve = false;
try {
    $recC->parCode($codesC[0], $MOT, $IP, $now);
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
$recR   = new Recovery($stR, $SEL, $PROFIL, delaiRefusUs: 0);
$codesR = $recR->emettreCodes(1, 3, $now);
$rCourse = $recR->parCode($codesR[0], $MOT, $IP, $now);
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
