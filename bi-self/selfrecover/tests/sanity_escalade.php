<?php

declare(strict_types=1);

/**
 * Le niveau 3 tient-il ce que le protocole promet ?
 *
 * 🔑 **Le contrôle qui compte le plus est le n° 5** : un refus ne touche pas au
 * compte. C'est la propriété pour laquelle ce niveau a été réécrit — le code
 * dont il est issu supprimait le compte au premier refus, ce qui donnait à un
 * attaquant incapable de voler un compte le moyen de le faire effacer. Si
 * quelqu'un « simplifie » un jour `trancher()` en y remettant une suppression,
 * c'est ce contrôle qui doit l'arrêter.
 *
 * ⚠️ Ce que cette sonde NE garde PAS : elle tourne sur un stockage en mémoire,
 * donc elle prouve que le protocole est correct, pas qu'un adaptateur l'est.
 * L'équivalence sur un schéma réel se garde ailleurs —
 * `demo/lab/tests/equivalence_selfrecover.php`. Elle ne garde pas non plus qui
 * a le droit de trancher : `Escalade` ne connaît pas les rôles, c'est
 * l'application qui les porte, et c'est écrit dans son docblock.
 *
 * Usage : php tests/sanity_escalade.php
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/StockageMemoire.php';

use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Recovery\Escalade;
use Pierroons\SelfRecover\Recovery\Litige;
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

$MOT  = str_repeat('a1', 32);          // une clé dérivée valide : 64 hexadécimaux
$SEL  = str_repeat('b2', 16);          // un sel de compte : 32 hexadécimaux
$now  = 1_700_000_000;
$JOUR = 86400;

/**
 * Un banc neuf : un compte, ses faits, et l'escalade câblée dessus.
 *
 * 🔑 **Le compte est POURVU**, et c'est ce qui fait la différence entre une
 * sonde et un décor. Une première version ne posait ni empreinte de mot de
 * passe ni lot de codes : les deux contrôles « un refus n'a purgé aucun code »
 * et « l'empreinte n'a pas bougé » comparaient alors du vide à du vide, et
 * restaient VERTS quand on faisait purger les codes du titulaire par un refus.
 * Mesuré. Une sonde ne vaut que l'état qu'elle met en place.
 */
function banc(int $now, ?int $derniereConnexion = null, ?int $connexions = null): array
{
    $st = new StockageMemoire();
    $st->comptes['alice']     = ['id' => 1, 'empreinte_mot' => Hashing::hash('mot memorise initial')];
    $st->passphrases['alice'] = ['id' => 1, 'empreinte_passphrase' => Hashing::hash('phrase initiale')];
    // Le mot de passe de CONNEXION, distinct du mot mémorisé : ils ne vivent pas
    // au même endroit, et confondre les deux est ce qui rendait le contrôle muet.
    $st->empreintes[1]        = Hashing::hash('mot de passe de connexion');
    $st->faits[1] = [
        'cree_le'            => $now - 400 * 86400,
        'derniere_connexion' => $derniereConnexion,
        'nombre_connexions'  => $connexions,
    ];
    $recovery = new Recovery($st, 'sel-de-la-sonde', delaiRefusUs: 0);
    $esc      = new Escalade($st, $recovery, delaiRefusUs: 0);
    // Un lot posé à la main — ce qu'on vérifie est qu'un refus ne le détruit pas,
    // pas la façon dont il est fabriqué. `emettreCodes()` coûterait dix Argon2id.
    for ($c = 1; $c <= Recovery::CODES_PAR_LOT; $c++) {
        $st->codes[] = ['id' => $c, 'compte_id' => 1, 'index' => "idx$c",
                        'empreinte' => "emp$c", 'utilise' => false];
    }

    return [$st, $esc];
}

echo "\n→ Ouverture d'un dossier\n";

[$st, $esc] = banc($now);
$sesame     = bin2hex(random_bytes(32));
$ouv        = $esc->ouvrir('alice', Escalade::empreinteSesame($sesame), $now);

verifier('un dossier s\'ouvre pour un compte connu', ($ouv['ok'] ?? false) === true);
verifier('son numéro n\'est pas séquentiel', (bool) preg_match('/^LIT-[0-9A-F]{16}$/', $ouv['numero'] ?? ''),
    $ouv['numero'] ?? '(aucun)');
verifier('trois questions sont posées, aucune ne demande de secret', count($ouv['questions'] ?? []) === 3);
verifier('le dossier expire dans 24 h', ($ouv['expire_le'] ?? 0) === $now + $JOUR);

$inconnu = $esc->ouvrir('mallory', Escalade::empreinteSesame($sesame), $now);
verifier('un compte inconnu est refusé', ($inconnu['ok'] ?? true) === false);

$malforme = $esc->ouvrir('alice', 'pas-une-empreinte', $now);
verifier('une empreinte de sésame malformée est refusée', ($malforme['ok'] ?? true) === false);

echo "\n→ Un dossier déjà ouvert ne redonne pas son numéro\n";

$autre = $esc->ouvrir('alice', Escalade::empreinteSesame('un autre sésame'), $now + 10);
verifier('la seconde ouverture est refusée', ($autre['ok'] ?? true) === false);
verifier('elle ne divulgue aucun numéro', !isset($autre['numero']));
verifier('le demandeur concurrent est compté, c\'est un fait pour l\'arbitre',
    ($st->litiges[0]['demandeurs_concurrents'] ?? 0) === 1);

echo "\n→ Le sésame, et rien d'autre, ouvre le dossier\n";

$numero = (string) $ouv['numero'];
$faux   = $esc->etat($numero, 'sésame inventé', $now);
verifier('un mauvais sésame est refusé', ($faux['ok'] ?? true) === false);
verifier('le refus ne dit pas si le numéro existe',
    ($faux['error'] ?? '') === 'sesame_invalide',
    (string) ($faux['error'] ?? '?'));

$numeroInvente = $esc->etat('LIT-0000000000000000', $sesame, $now);
verifier('un numéro inventé rend le MÊME refus qu\'un mauvais sésame',
    ($numeroInvente['message'] ?? '') === ($faux['message'] ?? 'x'));

$vide = $esc->etat($numero, '', $now);
verifier('un sésame vide n\'ouvre rien', ($vide['ok'] ?? true) === false);

$bon = $esc->etat($numero, $sesame, $now);
verifier('le bon sésame ouvre le dossier', ($bon['ok'] ?? false) === true);

echo "\n→ Le faisceau : des faits bruts, et le troisième état\n";

$dep = $esc->soumettre($numero, $sesame, [
    'annee_creation' => gmdate('Y', $now - 400 * 86400),
    'mois_connexion' => '2026-05',
    'frequence'      => 'souvent',
], $now);
verifier('le dépôt est accepté', ($dep['ok'] ?? false) === true);
verifier('le dossier passe en attente de lecture', ($dep['statut'] ?? '') === Litige::A_LIRE);

$f = json_decode((string) $st->litiges[0]['faisceau'], true);
verifier('l\'année déclarée juste concorde', ($f['declaratif']['annee_creation']['etat'] ?? '') === 'concorde');
verifier('⭐ un fait que le serveur n\'enregistre pas rend « indisponible », PAS « diverge »',
    ($f['declaratif']['mois_connexion']['etat'] ?? '') === 'indisponible',
    (string) ($f['declaratif']['mois_connexion']['etat'] ?? '?'));
verifier('idem pour la fréquence, un compteur absent ne vaut pas « rare »',
    ($f['declaratif']['frequence']['etat'] ?? '') === 'indisponible');
verifier('le faisceau ne porte aucun score agrégé',
    !isset($f['score']) && !isset($f['summary']) && !isset($f['confidence']));
verifier('il dit à l\'arbitre ce qu\'il ne prouve pas', isset($f['avertissement']));

// Contre-témoin : sans lui, un faisceau qui rendrait TOUJOURS « indisponible »
// passerait les deux contrôles ci-dessus. Un faux vert tue une sonde.
[$st2, $esc2] = banc($now, $now - 30 * 86400, 42);
$sesame2 = bin2hex(random_bytes(32));
$ouv2    = $esc2->ouvrir('alice', Escalade::empreinteSesame($sesame2), $now);
$esc2->soumettre((string) $ouv2['numero'], $sesame2, [
    'annee_creation' => gmdate('Y', $now - 400 * 86400),
    'mois_connexion' => gmdate('Y-m', $now - 30 * 86400),
    'frequence'      => 'souvent',
], $now);
$f2 = json_decode((string) $st2->litiges[0]['faisceau'], true);
verifier('contre-témoin : quand le serveur SAIT, les trois états sont calculés',
    ($f2['declaratif']['mois_connexion']['etat'] ?? '') === 'concorde'
    && ($f2['declaratif']['frequence']['etat'] ?? '') === 'concorde');

$fauxDire = $esc2->soumettre((string) $ouv2['numero'], $sesame2, ['annee_creation' => '1999'], $now + 7200);
$f3 = json_decode((string) $st2->litiges[0]['faisceau'], true);
verifier('contre-témoin : une réponse fausse diverge',
    ($f3['declaratif']['annee_creation']['etat'] ?? '') === 'diverge');

echo "\n→ Un niveau 3 ne réussit jamais tout seul\n";

$l3 = array_values(array_filter($st->tentatives, static fn (array $t): bool => str_starts_with($t['etiquette'], 'l3:')));
verifier('le dépôt est journalisé', count($l3) === 1);
verifier('⭐ il est journalisé comme un ÉCHEC, sinon L3 effacerait l\'ardoise des tentatives',
    ($l3[0]['succes'] ?? true) === false);

$trop = $esc->soumettre($numero, $sesame, [], $now + 60);
verifier('un second dépôt dans l\'heure est refusé', ($trop['ok'] ?? true) === false,
    (string) ($trop['error'] ?? '?'));

echo "\n→ Le faisceau ne redescend pas au demandeur\n";

$vu = $esc->etat($numero, $sesame, $now);
verifier('l\'état ne porte ni faisceau ni réponses attendues',
    !isset($vu['faisceau']) && !isset($vu['signals']) && !isset($vu['declaratif']));

echo "\n→ Le fil, dans les deux sens\n";

$esc->fil($numero, $sesame, 'Bonjour, c\'est bien mon compte.', $now);
$esc->repondre($numero, 'Bonjour, quelques questions.', $now + 60);
$lu = $esc->fil($numero, $sesame, null, $now + 120);
verifier('les deux messages sont dans le fil', count($lu['messages'] ?? []) === 2);
verifier('leurs auteurs sont distingués',
    ($lu['messages'][0]['auteur'] ?? '') === 'demandeur' && ($lu['messages'][1]['auteur'] ?? '') === 'admin');

$long = $esc->fil($numero, $sesame, str_repeat('x', 2001), $now + 130);
verifier('un message de plus de 2000 caractères est refusé', ($long['ok'] ?? true) === false);

echo "\n→ ⭐ Un refus ne touche JAMAIS au compte\n";

[$st3, $esc3] = banc($now);
$empreinteAvant = $st3->comptes['alice']['empreinte_mot'];
$mdpAvant       = $st3->empreintes[1];
$phraseAvant    = $st3->passphrases['alice']['empreinte_passphrase'];
$codesAvant     = count($st3->codes);

$numeros = [];
for ($i = 0; $i < 3; $i++) {
    $s = bin2hex(random_bytes(32));
    $o = $esc3->ouvrir('alice', Escalade::empreinteSesame($s), $now + $i * $JOUR);
    $numeros[] = [$o['numero'] ?? '', $s];
    $esc3->trancher((string) $o['numero'], 'refuse', 'arbitre', $now + $i * $JOUR + 100);
}

verifier('le compte existe toujours après trois refus', isset($st3->comptes['alice']));
verifier('le mot mémorisé n\'a pas bougé', $st3->comptes['alice']['empreinte_mot'] === $empreinteAvant);
verifier('⭐ le mot de passe de CONNEXION n\'a pas bougé', $st3->empreintes[1] === $mdpAvant);
verifier('⭐ la passphrase n\'a pas bougé', $st3->passphrases['alice']['empreinte_passphrase'] === $phraseAvant);
verifier('aucune session n\'a été révoquée par un refus', $st3->sessionsRevoquees === []);
verifier('⭐ les ' . $codesAvant . ' codes de récupération sont intacts', count($st3->codes) === $codesAvant);
verifier('contre-témoin : le banc en portait bien avant les refus', $codesAvant === Recovery::CODES_PAR_LOT);

echo "\n→ Ce qui gèle, c'est la procédure\n";

$gel = $esc3->ouvrir('alice', Escalade::empreinteSesame('encore un'), $now + 3 * $JOUR);
verifier('au 3ᵉ refus, l\'ouverture d\'un nouveau dossier est gelée', ($gel['ok'] ?? true) === false);
verifier('et le refus le dit', ($gel['error'] ?? '') === 'gele');
verifier('le message précise que le compte fonctionne',
    str_contains((string) ($gel['message'] ?? ''), 'fonctionne normalement'));

// Contre-témoin : deux refus ne gèlent pas. Sans lui, un gel permanent rendrait
// les trois contrôles ci-dessus verts.
[$st4, $esc4] = banc($now);
for ($i = 0; $i < 2; $i++) {
    $o = $esc4->ouvrir('alice', Escalade::empreinteSesame("s$i"), $now + $i * $JOUR);
    $esc4->trancher((string) $o['numero'], 'refuse', 'arbitre', $now + $i * $JOUR + 100);
}
$deux = $esc4->ouvrir('alice', Escalade::empreinteSesame('troisieme'), $now + 2 * $JOUR);
verifier('contre-témoin : deux refus ne gèlent pas', ($deux['ok'] ?? false) === true);

// Contre-témoin : hors de la fenêtre, les refus ne comptent plus.
$vieux = $esc3->ouvrir('alice', Escalade::empreinteSesame('bien plus tard'), $now + 400 * $JOUR);
verifier('contre-témoin : passé la fenêtre et le gel, l\'ouverture rouvre', ($vieux['ok'] ?? false) === true);

$deg = $esc3->degeler('alice', 'arbitre', $now + 3 * $JOUR + 10);
verifier('un arbitre peut dégeler', ($deg['ok'] ?? false) === true);
verifier('la trace du dégel est gardée, pas effacée',
    ($st3->gels[1]['degele_par'] ?? null) === 'arbitre');

echo "\n→ Accepter ne fabrique aucun secret\n";

[$st5, $esc5] = banc($now);
$s5  = bin2hex(random_bytes(32));
$o5  = $esc5->ouvrir('alice', Escalade::empreinteSesame($s5), $now);
$n5  = (string) $o5['numero'];
$acc = $esc5->trancher($n5, 'accepte', 'arbitre', $now + 100);

verifier('l\'acceptation est enregistrée', ($acc['statut'] ?? '') === Litige::ACCEPTE);
verifier('⭐ elle ne rend aucun mot de passe, aucune passphrase, aucun code',
    !isset($acc['mot_de_passe']) && !isset($acc['passphrase']) && !isset($acc['codes']));
verifier('elle porte le nom de qui a tranché', ($st5->litiges[0]['tranche_par'] ?? '') === 'arbitre');

$rejoue = $esc5->trancher($n5, 'refuse', 'arbitre', $now + 200);
verifier('un dossier déjà tranché ne se retranche pas', ($rejoue['ok'] ?? true) === false);

$inconnue = $esc5->trancher($n5, 'peut-être', 'arbitre', $now + 200);
verifier('une décision hors « accepte » / « refuse » est refusée', ($inconnue['ok'] ?? true) === false);

echo "\n→ Le titulaire repose lui-même ses secrets\n";

$re = $esc5->reEnroler($n5, $s5, 'un mot de passe choisi par elle', $MOT, $SEL, $now + 300);
verifier('le ré-enrôlement réussit', ($re['ok'] ?? false) === true, (string) ($re['error'] ?? ''));
// ⚠️ On cherche la VALEUR, pas la clé. Énumérer les clés interdites laissait
// passer le même secret renvoyé sous le nom `password` — mesuré, la sonde
// restait verte. Un secret qui fuit ne demande la permission d'aucun champ.
verifier('⭐ AUCUN mot de passe n\'est rendu — le serveur n\'en fabrique pas',
    !str_contains((string) json_encode($re, JSON_UNESCAPED_UNICODE), 'un mot de passe choisi par elle'));
verifier('une passphrase neuve est rendue', is_string($re['passphrase'] ?? null));
verifier('elle porte la longueur du protocole',
    count(explode(' ', (string) ($re['passphrase'] ?? ''))) === Recovery::MOTS_PASSPHRASE);
verifier('un lot de codes est rendu', count($re['codes'] ?? []) === Recovery::CODES_PAR_LOT);
verifier('le mot de passe choisi est bien celui qui est rangé',
    Hashing::verify('un mot de passe choisi par elle', $st5->empreintes[1] ?? ''));
verifier('le mot mémorisé dérivé est rangé', Hashing::verify($MOT, $st5->comptes['alice']['empreinte_mot']));
verifier('le sel du compte est rangé', ($st5->sels[1] ?? '') === $SEL);
verifier('les sessions sont révoquées — qui tenait le compte est éjecté', $st5->sessionsRevoquees === [1]);

echo "\n→ Le sésame ne sert qu'une fois\n";

// ⚠️ Ce contrôle vérifie le MOTIF, pas seulement le refus, et c'est délibéré :
// un dossier clos n'est plus `accepted`, donc `reEnroler` refuserait de toute
// façon sur `non_accepte`. Mesuré — en retirant l'invalidation du sésame de
// `cloreLitige`, la version qui ne testait que `ok === false` restait VERTE.
// C'est le sésame qui doit cesser d'ouvrir, pas seulement l'état qui change :
// le fil et l'état d'un dossier, eux, ne regardent pas le statut.
$rejeu = $esc5->reEnroler($n5, $s5, 'une seconde reprise', $MOT, $SEL, $now + 400);
verifier('⭐ le sésame ne rouvre plus rien après usage', ($rejeu['error'] ?? '') === 'sesame_invalide',
    (string) ($rejeu['error'] ?? '?'));
$filApres = $esc5->fil($n5, $s5, null, $now + 410);
verifier('⭐ il ne rouvre pas non plus le fil, que le statut ne garde pas',
    ($filApres['error'] ?? '') === 'sesame_invalide', (string) ($filApres['error'] ?? '?'));
verifier('le mot de passe de la seconde tentative n\'a rien écrasé',
    Hashing::verify('un mot de passe choisi par elle', $st5->empreintes[1] ?? ''));

echo "\n→ Ce qu'un dossier non accepté ne permet pas\n";

[$st6, $esc6] = banc($now);
$s6 = bin2hex(random_bytes(32));
$o6 = $esc6->ouvrir('alice', Escalade::empreinteSesame($s6), $now);
$nr = $esc6->reEnroler((string) $o6['numero'], $s6, 'mot de passe', $MOT, $SEL, $now + 100);
verifier('un dossier non tranché ne permet pas de reposer les secrets', ($nr['ok'] ?? true) === false);
verifier('et le refus dit pourquoi', ($nr['error'] ?? '') === 'non_accepte');

$esc6->trancher((string) $o6['numero'], 'accepte', 'arbitre', $now + 110);
$mauvaisMot = $esc6->reEnroler((string) $o6['numero'], $s6, 'mot de passe', 'pas une clé dérivée', $SEL, $now + 120);
verifier('un mot mémorisé non dérivé côté client est refusé',
    ($mauvaisMot['error'] ?? '') === 'invalid_derived_key');
$mauvaisSel = $esc6->reEnroler((string) $o6['numero'], $s6, 'mot de passe long assez', $MOT, 'ZZZ', $now + 130);
verifier('un sel malformé est refusé', ($mauvaisSel['error'] ?? '') === 'sel_invalide');

// ⭐ Le plancher de longueur : c'est le SEUL endroit du protocole où un humain
// choisit son mot de passe. Sans cette garde, `password: ""` range l'empreinte
// de la chaîne vide et le compte s'ouvre ensuite sans rien saisir.
$court = $esc6->reEnroler((string) $o6['numero'], $s6, 'court', $MOT, $SEL, $now + 140);
verifier('⭐ un mot de passe trop court est refusé', ($court['error'] ?? '') === 'mot_de_passe_invalide',
    (string) ($court['error'] ?? '?'));
$vide = $esc6->reEnroler((string) $o6['numero'], $s6, '', $MOT, $SEL, $now + 150);
verifier('⭐ un mot de passe VIDE est refusé', ($vide['error'] ?? '') === 'mot_de_passe_invalide');
$enorme = $esc6->reEnroler((string) $o6['numero'], $s6, str_repeat('x', 5000), $MOT, $SEL, $now + 160);
verifier('un mot de passe démesuré est refusé', ($enorme['error'] ?? '') === 'mot_de_passe_invalide');
// Contre-témoin : sans lui, une garde qui refuserait TOUT rendrait les trois verts.
$bonMdp = $esc6->reEnroler((string) $o6['numero'], $s6, 'un mot de passe recevable', $MOT, $SEL, $now + 170);
verifier('contre-témoin : un mot de passe recevable passe', ($bonMdp['ok'] ?? false) === true);

echo "\n→ Expiration et purge\n";

[$st7, $esc7] = banc($now);
$s7 = bin2hex(random_bytes(32));
$o7 = $esc7->ouvrir('alice', Escalade::empreinteSesame($s7), $now);
$ex = $esc7->etat((string) $o7['numero'], $s7, $now + $JOUR + 1);
verifier('un dossier expiré n\'est plus recevable', ($ex['ok'] ?? true) === false);
verifier('et le refus dit que c\'est l\'expiration', ($ex['error'] ?? '') === 'expire');

verifier('la purge n\'efface rien tant que le dossier court', $esc7->purger($now) === 0);
verifier('elle efface le dossier périmé', $esc7->purger($now + $JOUR + 1) === 1);
verifier('et le dossier a bien disparu', $st7->litiges === []);

echo "\n" . str_repeat('=', 63) . "\n";
printf("  Escalade SelfRecover — %d passés, %d échoués\n", $passes, $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
