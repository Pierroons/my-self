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
 * ⚠️ Elle ne garde pas non plus l'UNIFORMITÉ DES TEMPS de réponse d'`ouvrir()`.
 * Trois refus y portent un `usleep` — « inconnu », « gelé », « déjà ouvert » —
 * et rien ici ne le mesure : ce qui est gardé est la forme des refus.
 *
 * Usage : php tests/sanity_escalade.php
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/StockageMemoire.php';

use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Etiquette;
use Pierroons\SelfRecover\ProfilDeploiement;
use Pierroons\SelfRecover\Recovery\Escalade;
use Pierroons\SelfRecover\Recovery\Litige;
use Pierroons\SelfRecover\Recovery\Recovery;
use Pierroons\SelfRecover\Tests\StockageMemoire;
use Pierroons\SelfRecover\Langue;

// 🔑 Ce banc ne se joue PAS sous un profil unique, contrairement à celui de la
// récupération : ses cas éprouvent les deux mondes exprès — le quota par adresse
// d'un côté, celui par service qui tient quand l'adresse ne dit rien de l'autre.
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
function banc(
    ProfilDeploiement $profil,
    int $now,
    ?int $derniereConnexion = null,
    ?int $connexions = null,
): array {
    $st = new StockageMemoire();
    $st->comptes['alice']     = ['id' => 1, 'empreinte_mot' => Hashing::hash('mot memorise initial')];
    $st->passphrases['alice'] = ['id' => 1, 'empreinte_passphrase' => Hashing::hash('phrase initiale')];
    // Le mot de passe de CONNEXION, distinct du mot mémorisé : ils ne vivent pas
    // au même endroit, et confondre les deux est ce qui rendait le contrôle muet.
    $st->empreintes[1]        = Hashing::hash('mot de passe de connexion');
    // Le marqueur de déploiement d'un compte déjà enrôlé — c'est ce que
    // `reposerSecrets()` devra rafraîchir, et ce que le faisceau montre.
    $st->hotes[1]             = $st->hoteServi;
    $st->faits[1] = [
        'cree_le'            => $now - 400 * 86400,
        'derniere_connexion' => $derniereConnexion,
        'nombre_connexions'  => $connexions,
    ];
    $recovery = new Recovery($st, 'sel-de-la-sonde', $profil, Langue::FR, delaiRefusUs: 0);
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

[$st, $esc] = banc(ProfilDeploiement::TOR_ONION, $now);
$sesame     = bin2hex(random_bytes(32));
$ouv        = $esc->ouvrir('alice', Escalade::empreinteSesame($sesame), maintenant: $now);

verifier('un dossier s\'ouvre pour un compte connu', ($ouv['ok'] ?? false) === true);
verifier('son numéro n\'est pas séquentiel', (bool) preg_match('/^LIT-[0-9A-F]{16}$/', $ouv['numero'] ?? ''),
    $ouv['numero'] ?? '(aucun)');
verifier('trois questions sont posées, aucune ne demande de secret', count($ouv['questions'] ?? []) === 3);
verifier('le dossier expire dans 24 h', ($ouv['expire_le'] ?? 0) === $now + $JOUR);

$inconnu = $esc->ouvrir('mallory', Escalade::empreinteSesame($sesame), maintenant: $now);
verifier('un compte inconnu est refusé', ($inconnu['ok'] ?? true) === false);

$malforme = $esc->ouvrir('alice', 'pas-une-empreinte', maintenant: $now);
verifier('une empreinte de sésame malformée est refusée', ($malforme['ok'] ?? true) === false);

echo "\n→ Un dossier déjà ouvert ne redonne pas son numéro\n";

$autre = $esc->ouvrir('alice', Escalade::empreinteSesame('un autre sésame'), maintenant: $now + 10);
verifier('la seconde ouverture est refusée', ($autre['ok'] ?? true) === false);
verifier('elle ne divulgue aucun numéro', !isset($autre['numero']));
verifier('le demandeur concurrent est compté, c\'est un fait pour l\'arbitre',
    ($st->litiges[0]['demandeurs_concurrents'] ?? 0) === 1);

echo "\n→ Les freins de l'ouverture\n";

// ⭐ Le compteur annoncé doit être le compteur réel. Une version antérieure de
// ce correctif écrivait DEUX lignes par appel sous l'étiquette comptée : le
// frein annoncé à 10 mordait au 6e appel, et rien ici ne le voyait.
[$stI, $_] = banc(ProfilDeploiement::CLEARWEB, $now);
$escIp = new Escalade($stI, new Recovery($stI, 'sel', ProfilDeploiement::CLEARWEB, Langue::FR, delaiRefusUs: 0),
    delaiRefusUs: 0, maxOuverturesIp: 3);

$passes3 = [];
for ($i = 0; $i < 4; $i++) {
    $passes3[] = $escIp->ouvrir("n$i", Escalade::empreinteSesame("s$i"), '10.0.0.1', $now + $i);
}
verifier('contre-témoin : les trois premières passent le frein — le seuil annoncé est le seuil réel',
    array_slice(array_column($passes3, 'error'), 0, 3) === ['ouverture_refusee', 'ouverture_refusee', 'ouverture_refusee']);
verifier('⭐ la 4e est freinée', ($passes3[3]['error'] ?? '') === 'trop_de_demandes');

// ⭐ Ouvrir un dossier ne doit consommer le quota d'AUCUNE autre voie. Toutes
// les surfaces — connexion, niveaux 1 et 2, enrôlement d'appareil — comptent
// les échecs d'une adresse dans la même table, sans regarder d'où ils viennent.
$avecIp = array_filter($stI->tentatives, static fn (array $t): bool => $t['ip'] !== null);
verifier('⭐ aucune ligne d\'ouverture ne porte d\'adresse', $avecIp === [],
    count($avecIp) . ' ligne(s) en portent');
verifier('⭐ le compteur partagé par adresse est intact — l\'échec n\'est pas une arme',
    $stI->compterEchecsIp('10.0.0.1', $now - 3600, Etiquette::PREFIXES) === 0);
verifier('et aucun nom de compte n\'est écrit : l\'ouverture est comptée, pas attribuée',
    array_filter($stI->tentatives, static fn (array $t): bool => str_contains($t['etiquette'], 'n0')) === []);

// ⭐ Les compteurs vivent dans une colonne où les tentatives de connexion
// atterrissent aussi, et un nom soumis y arrive tel quel depuis une route
// publique. Une étiquette devinable serait un compteur que n'importe qui
// remplit : mesuré avant correction, vingt lignes fermaient le service à tous.
[$stF, $escF] = banc(ProfilDeploiement::CLEARWEB, $now);
for ($i = 0; $i < 40; $i++) {
    $stF->tracerTentative('l3:ouvrir:*', false, '198.51.100.4', $now + $i);
    $stF->tracerTentative('l3:ouvrir:@' . substr(hash('sha256', '10.0.0.1'), 0, 32), false, '198.51.100.4', $now + $i);
}
$apresForge = $escF->ouvrir('alice', Escalade::empreinteSesame('la vraie'), '10.0.0.1', $now + 50);
verifier('⭐ quarante lignes forgées sous l\'étiquette devinable ne freinent personne',
    ($apresForge['ok'] ?? false) === true, (string) ($apresForge['error'] ?? ''));

// ⭐ Les seuils LIVRÉS, pas seulement ceux qu'on injecte : ce sont eux que le
// CHANGELOG et l'architecture publient.
[$stD, $escD] = banc(ProfilDeploiement::CLEARWEB, $now);
$suiteD = [];
for ($i = 0; $i < 11; $i++) {
    $suiteD[] = $escD->ouvrir("d$i", Escalade::empreinteSesame("x$i"), '10.0.0.5', $now + $i);
}
verifier('⭐ par défaut, dix ouvertures par adresse passent',
    array_column(array_slice($suiteD, 0, 10), 'error') === array_fill(0, 10, 'ouverture_refusee'));
verifier('⭐ et la onzième est freinée — le chiffre publié est le chiffre livré',
    ($suiteD[10]['error'] ?? '') === 'trop_de_demandes');

// L'énumération vise des noms différents : seul un plafond de service la voit.
[$stS, $__] = banc(ProfilDeploiement::TOR_ONION, $now);
$escServ = new Escalade($stS, new Recovery($stS, 'sel', ProfilDeploiement::TOR_ONION, Langue::FR, delaiRefusUs: 0),
    delaiRefusUs: 0, maxOuverturesService: 2);

$e1 = $escServ->ouvrir('inconnu1', Escalade::empreinteSesame('a'), maintenant: $now);
$e2 = $escServ->ouvrir('inconnu2', Escalade::empreinteSesame('b'), maintenant: $now + 1);
$e3 = $escServ->ouvrir('inconnu3', Escalade::empreinteSesame('c'), maintenant: $now + 2);
verifier('contre-témoin : les deux premiers noms rendent bien le refus, pas le frein',
    ($e1['error'] ?? '') === 'ouverture_refusee' && ($e2['error'] ?? '') === 'ouverture_refusee');
verifier('⭐ le plafond de service arrête l\'énumération, que les noms n\'existent pas n\'y change rien',
    ($e3['error'] ?? '') === 'trop_de_demandes');

$connu = $escServ->ouvrir('alice', Escalade::empreinteSesame('d'), maintenant: $now + 3);
verifier('⭐ une fois le plafond atteint, un compte CONNU et un compte inconnu rendent le même refus',
    ($connu['error'] ?? '') === ($e3['error'] ?? 'x'));

// 🔑 Sans adresse — derrière un service caché, où il n'y a rien à compter — un
// seuil par adresse à zéro ne doit rien freiner : c'est le plafond de service
// qui gouverne seul.
[$stN, $___] = banc(ProfilDeploiement::TOR_ONION, $now);
$escNul = new Escalade($stN, new Recovery($stN, 'sel', ProfilDeploiement::TOR_ONION, Langue::FR, delaiRefusUs: 0),
    delaiRefusUs: 0, maxOuverturesIp: 0);
$sansIp = $escNul->ouvrir('alice', Escalade::empreinteSesame('e'), null, $now);
verifier('⭐ sans adresse, le frein par adresse ne freine pas',
    ($sansIp['ok'] ?? false) === true);

// ⭐ Une chaîne vide n'est pas une adresse. Un intégrateur qui écrit
// `REMOTE_ADDR ?? ''` la passerait, et tous les appelants partageraient alors un
// compteur unique — un plafond global au seuil du client, qui masque celui du
// service. Les deux formes vides doivent se comporter comme `null`.
[$stV, $__v] = banc(ProfilDeploiement::TOR_ONION, $now);
$escV = new Escalade($stV, new Recovery($stV, 'sel', ProfilDeploiement::TOR_ONION, Langue::FR, delaiRefusUs: 0),
    delaiRefusUs: 0, maxOuverturesIp: 1);
$escV->ouvrir('v1', Escalade::empreinteSesame('a'), '', $now);
$vide2 = $escV->ouvrir('v2', Escalade::empreinteSesame('b'), '   ', $now + 1);
verifier('⭐ une adresse vide ou blanche vaut « pas d\'adresse »',
    ($vide2['error'] ?? '') === 'ouverture_refusee', (string) ($vide2['error'] ?? ''));

// ⭐ Aucun frein par compte : un tiers ne doit pas pouvoir fermer l'ouverture au
// titulaire. Ce qui borne le harcèlement est le dossier lui-même, et la
// collision se COMPTE sous les yeux de l'arbitre plutôt que de murer en silence.
[$stH, $escH] = banc(ProfilDeploiement::CLEARWEB, $now);
for ($i = 0; $i < 8; $i++) {
    $escH->ouvrir('alice', Escalade::empreinteSesame("mallory$i"), '10.0.0.9', $now + $i);
}
$victime = $escH->ouvrir('alice', Escalade::empreinteSesame('la vraie'), '10.0.0.2', $now + 20);
verifier('⭐ huit sollicitations d\'un tiers ne murent pas le titulaire : refus du dossier ouvert, pas frein',
    ($victime['error'] ?? '') === 'ouverture_refusee');
verifier('et la collision reste visible à l\'arbitre',
    ($stH->litiges[0]['demandeurs_concurrents'] ?? 0) === 8);

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

// Les faits que seul le déploiement connaît. Ils passent sous `contexte.local`
// et ne se mêlent jamais aux faits calculés : un adaptateur ne doit pas pouvoir
// écrire un « refus_precedents » de son cru sous les yeux de l'arbitre.
verifier('un fait local du déploiement atteint le faisceau',
    ($f['contexte']['local']['hote_derivation'] ?? '?') === 'exemple.test');
// ⭐ Un adaptateur qui rend une clé réservée ne doit pas pouvoir en changer la
// valeur : `refus_precedents` arme le gel, et un arbitre qui lit un zéro forgé
// tranche sur un faux.
[$stMenteur, $escMenteur] = banc(ProfilDeploiement::TOR_ONION, $now);
$stMenteur->faitsLocaux = ['refus_precedents' => 999, 'hote_derivation' => 'imposteur.test'];
$sMenteur = bin2hex(random_bytes(32));
$oMenteur = $escMenteur->ouvrir('alice', Escalade::empreinteSesame($sMenteur), maintenant: $now);
$escMenteur->soumettre((string) $oMenteur['numero'], $sMenteur, ['annee_creation' => '2022'], $now + 7200);
$fMenteur = json_decode((string) $stMenteur->litiges[0]['faisceau'], true);
verifier('⭐ un fait local ne peut pas écraser un fait calculé par la bibliothèque',
    ($fMenteur['contexte']['refus_precedents'] ?? '?') === 0,
    'refus_precedents = ' . var_export($fMenteur['contexte']['refus_precedents'] ?? null, true));
verifier('et la valeur forgée reste visible à sa place, sous `local`',
    ($fMenteur['contexte']['local']['refus_precedents'] ?? '?') === 999);

// Contre-témoin : un adaptateur qui ne rend PAS la case ne casse rien. C'est le
// cas de l'adaptateur du lab, seul adaptateur de production du niveau 3 : sans
// ce contrôle, retirer le `?? []` de `faisceau()` laisserait le banc vert et
// casserait chaque dossier servi.
[$stMuet, $escMuet] = banc(ProfilDeploiement::TOR_ONION, $now);
$stMuet->sansFaitsLocaux = true;
$sMuet = bin2hex(random_bytes(32));
$oMuet = $escMuet->ouvrir('alice', Escalade::empreinteSesame($sMuet), maintenant: $now);
$escMuet->soumettre((string) $oMuet['numero'], $sMuet, ['annee_creation' => '2022'], $now + 7200);
$fMuet = json_decode((string) $stMuet->litiges[0]['faisceau'], true);
verifier('contre-témoin : un adaptateur qui ne rend pas la case ne casse rien',
    is_array($fMuet['contexte']['local'] ?? null) && $fMuet['contexte']['local'] === []);

// ⭐ Un fait illisible ne doit pas fermer la porte : ce niveau s'adresse à qui
// n'a plus rien, et un faisceau qui n'assemble jamais rend le refus définitif.
[$stSale, $escSale] = banc(ProfilDeploiement::TOR_ONION, $now);
$stSale->faitsLocaux = ['hote' => "octet\xE9 hors UTF-8", 'compteur' => INF, 'objet' => new stdClass()];
$sSale = bin2hex(random_bytes(32));
$oSale = $escSale->ouvrir('alice', Escalade::empreinteSesame($sSale), maintenant: $now);
$dSale = $escSale->soumettre((string) $oSale['numero'], $sSale, ['annee_creation' => '2022'], $now + 7200);
verifier('⭐ un fait local illisible n\'empêche pas le dépôt', ($dSale['ok'] ?? false) === true,
    (string) ($dSale['error'] ?? ''));
$fSale = json_decode((string) $stSale->litiges[0]['faisceau'], true);
verifier('et il arrive à l\'arbitre en « on ne sait pas », pas en valeur forgée',
    ($fSale['contexte']['local'] ?? null) === ['hote' => null, 'compteur' => null, 'objet' => null]);

// Contre-témoin : sans lui, un faisceau qui rendrait TOUJOURS « indisponible »
// passerait les deux contrôles ci-dessus. Un faux vert tue une sonde.
[$st2, $esc2] = banc(ProfilDeploiement::TOR_ONION, $now, $now - 30 * 86400, 42);
$sesame2 = bin2hex(random_bytes(32));
$ouv2    = $esc2->ouvrir('alice', Escalade::empreinteSesame($sesame2), maintenant: $now);
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

// Étiquette exacte : deux étiquettes commencent par « l3: », le dépôt et
// l'ouverture. Un filtre par préfixe les compterait ensemble et resterait vert
// même si le dépôt cessait d'être journalisé.
$depot = $esc->etiquetteDepot('alice');
$l3    = array_values(array_filter($st->tentatives, static fn (array $t): bool => $t['etiquette'] === $depot));
verifier('le dépôt est journalisé', count($l3) === 1);
// ⭐ Le nom du compte attendait là en clair, indéfiniment : cette table n'est
// purgée nulle part. Sous HMAC, la ligne se retrouve sans se lire.
verifier('⭐ et son étiquette ne contient pas le nom du compte',
    !str_contains($depot, 'alice'));
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

[$st3, $esc3] = banc(ProfilDeploiement::TOR_ONION, $now);
$empreinteAvant = $st3->comptes['alice']['empreinte_mot'];
$mdpAvant       = $st3->empreintes[1];
$phraseAvant    = $st3->passphrases['alice']['empreinte_passphrase'];
$codesAvant     = count($st3->codes);

$numeros = [];
for ($i = 0; $i < 3; $i++) {
    $s = bin2hex(random_bytes(32));
    $o = $esc3->ouvrir('alice', Escalade::empreinteSesame($s), maintenant: $now + $i * $JOUR);
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

echo "\n→ 🔑 Un TIERS ne ferme pas la dernière porte du titulaire\n";

// LA propriété composée. Les trois refus ci-dessus viennent de dossiers qu'un
// tiers a ouverts avec le seul nom « alice » — l'empreinte du sésame est
// choisie par l'appelant, donc personne n'a eu besoin de posséder le compte.
// C'est ce que ce cas garde : le compteur de refus ne doit pas pouvoir fermer
// la porte de qui n'a rien fait.
$apres = $esc3->ouvrir('alice', Escalade::empreinteSesame('encore un'), maintenant: $now + 3 * $JOUR);
verifier('⭐ trois refus déclenchés par un tiers, et le titulaire ouvre toujours',
    ($apres['ok'] ?? false) === true, (string) ($apres['error'] ?? ''));
verifier('aucun gel n\'a été posé par un compteur', $st3->gels === [], json_encode($st3->gels));

// Le signal, lui, est rendu à l'arbitre : c'est ce qui remplace la décision.
[$st5, $esc5] = banc(ProfilDeploiement::TOR_ONION, $now);
$suggere  = [];
$messages = [];
for ($i = 0; $i < 3; $i++) {
    $o = $esc5->ouvrir('alice', Escalade::empreinteSesame("t$i"), maintenant: $now + $i * $JOUR);
    $t = $esc5->trancher((string) $o['numero'], 'refuse', 'arbitre', $now + $i * $JOUR + 100);
    $suggere[]  = [$t['refus_dans_la_fenetre'] ?? null, $t['gel_suggere'] ?? null, $t['gele'] ?? null];
    $messages[] = (string) ($t['message'] ?? '');
}
verifier('⭐ le refus compte et SUGGÈRE, il ne gèle pas — sous le seuil puis au seuil',
    $suggere === [[1, false, false], [2, false, false], [3, true, false]], json_encode($suggere));
verifier('⭐ au seuil, le message dit le compte ET que rien n\'a été posé',
    str_contains($messages[2], '3 refus') && str_contains($messages[2], 'Aucun gel n\'a été posé'),
    $messages[2]);
verifier('et aucun gel n\'est en base après les trois refus', $st5->gels === [], json_encode($st5->gels));
// Contre-témoin : sous le seuil, le message ne parle pas de gel du tout — sinon
// l'assertion ci-dessus serait vraie pour un texte qui le dit toujours.
verifier('contre-témoin : sous le seuil, le message ne mentionne aucun gel',
    !str_contains($messages[0], 'gel') && !str_contains($messages[0], 'refus sur'),
    $messages[0]);

echo "\n→ Ce qui gèle, c'est la procédure — et c'est un arbitre qui la gèle\n";

// 🔑 La place se libère AVANT de mesurer le gel. Sans ce geste, le dossier que
// le titulaire vient d'ouvrir fait refuser l'ouverture suivante par
// exclusivité, et les assertions du gel passent au vert sans que le gel y soit
// pour quoi que ce soit. Mesuré : un canari qui retirait `poserGel()` de
// `geler()` laissait ce bloc entièrement vert.
$esc3->abandonner('alice', 'arbitre', $now + 3 * $JOUR + 2);
verifier('témoin : la place libérée, l\'ouverture repasse avant tout gel',
    ($esc3->ouvrir('alice', Escalade::empreinteSesame('temoin'), maintenant: $now + 3 * $JOUR + 3)['ok'] ?? false) === true);
$esc3->abandonner('alice', 'arbitre', $now + 3 * $JOUR + 4);

$pose = $esc3->geler('alice', 'arbitre', $now + 3 * $JOUR + 5);
verifier('un arbitre peut geler', ($pose['ok'] ?? false) === true, (string) ($pose['error'] ?? ''));
verifier('le gel annoncé est celui du constructeur',
    ($pose['jusqua'] ?? 0) === $now + 3 * $JOUR + 5 + $esc3->reglesDuGel()['duree']);
verifier('geler un nom inconnu est refusé, et nommé',
    ($esc3->geler('personne', 'arbitre', $now)['error'] ?? '') === 'compte_inconnu');

$gel = $esc3->ouvrir('alice', Escalade::empreinteSesame('apres le gel'), maintenant: $now + 3 * $JOUR + 6);
verifier('une fois gelée, l\'ouverture d\'un nouveau dossier est refusée', ($gel['ok'] ?? true) === false);
verifier('et le refus est celui d\'un nom inconnu : la suspension ne se lit pas de dehors',
    ($gel['error'] ?? '') === 'ouverture_refusee');
verifier('⭐ nom inconnu, dossier déjà ouvert, procédure gelée : une seule réponse, mot pour mot',
    json_encode($e1) === json_encode($victime) && json_encode($victime) === json_encode($gel));
verifier('le refus ne nomme ni le gel ni la date où il tombe',
    !str_contains((string) ($gel['message'] ?? ''), 'gel') && !preg_match('#\d{2}/\d{2}/\d{4}#', (string) ($gel['message'] ?? '')));

$deg = $esc3->degeler('alice', 'arbitre', $now + 3 * $JOUR + 10);
verifier('un arbitre peut dégeler', ($deg['ok'] ?? false) === true);
verifier('la trace du dégel est gardée, pas effacée',
    ($st3->gels[1]['degele_par'] ?? null) === 'arbitre');

// 🔑 Une levée doit tenir : aucun refus ultérieur ne doit reposer un gel.
// ⚠️ Banc à part, sans dossier en cours : l'exclusivité refuserait l'ouverture
// avant qu'on ait mesuré le gel.
[$st6, $esc6] = banc(ProfilDeploiement::TOR_ONION, $now);
for ($i = 0; $i < 3; $i++) {
    $o = $esc6->ouvrir('alice', Escalade::empreinteSesame("u$i"), maintenant: $now + $i * $JOUR);
    $esc6->trancher((string) $o['numero'], 'refuse', 'arbitre', $now + $i * $JOUR + 100);
}
$esc6->geler('alice', 'arbitre', $now + 3 * $JOUR);
verifier('le gel de l\'arbitre ferme bien l\'ouverture',
    ($esc6->ouvrir('alice', Escalade::empreinteSesame('pendant'), maintenant: $now + 3 * $JOUR + 1)['ok'] ?? true) === false);
$esc6->degeler('alice', 'arbitre', $now + 3 * $JOUR + 10);

$o4 = $esc6->ouvrir('alice', Escalade::empreinteSesame('quatrieme'), maintenant: $now + 3 * $JOUR + 20);
verifier('⭐ après un dégel, l\'ouverture marche vraiment', ($o4['ok'] ?? false) === true,
    (string) ($o4['error'] ?? ''));
$t4 = $esc6->trancher((string) $o4['numero'], 'refuse', 'arbitre', $now + 3 * $JOUR + 30);
verifier('⭐ et le 4ᵉ refus ne regèle pas : le dégel tient',
    ($t4['gele'] ?? true) === false && ($st6->gels[1]['jusqua'] ?? 1) === 0
    && ($esc6->ouvrir('alice', Escalade::empreinteSesame('cinquieme'), maintenant: $now + 3 * $JOUR + 40)['ok'] ?? false) === true,
    json_encode($st6->gels[1] ?? null));
verifier('le signal reste lisible après le dégel', ($t4['gel_suggere'] ?? false) === true);

// Contre-témoin : hors de la fenêtre, les refus ne se comptent plus.
$loin = $esc3->ouvrir('alice', Escalade::empreinteSesame('bien plus tard'), maintenant: $now + 400 * $JOUR);
$tLoin = $esc3->trancher((string) $loin['numero'], 'refuse', 'arbitre', $now + 400 * $JOUR + 10);
verifier('contre-témoin : passé la fenêtre, le compteur retombe et ne suggère plus',
    ($tLoin['refus_dans_la_fenetre'] ?? -1) === 1 && ($tLoin['gel_suggere'] ?? true) === false,
    json_encode([$tLoin['refus_dans_la_fenetre'] ?? null, $tLoin['gel_suggere'] ?? null]));

echo "\n→ Accepter ne fabrique aucun secret\n";

[$st5, $esc5] = banc(ProfilDeploiement::TOR_ONION, $now);
$s5  = bin2hex(random_bytes(32));
$o5  = $esc5->ouvrir('alice', Escalade::empreinteSesame($s5), maintenant: $now);
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

// ⭐ Un appareil enrôlé AVANT la reprise. On arrive au niveau 3 après avoir tout
// perdu, et « tout perdu » veut souvent dire « quelqu'un d'autre l'a » : un
// appareil qui survit à la reprise ouvre le compte sur une signature seule,
// `cloreDefi()` ne vérifiant pas le mot mémorisé. Le titulaire croirait avoir
// refermé sa porte.
$st5->enregistrerAppareil(1, 'cred-du-precedent', str_repeat('K', 60), $now);
$st5->enregistrerDefi('defi-en-vol', 'cred-du-precedent', $now);
verifier('contre-témoin : l\'appareil est bien enrôlé avant la reprise',
    $st5->trouverAppareil('cred-du-precedent') !== null);

$re = $esc5->reEnroler($n5, $s5, 'un mot de passe choisi par elle', $MOT, $SEL, $now + 300);
verifier('le ré-enrôlement réussit', ($re['ok'] ?? false) === true, (string) ($re['error'] ?? ''));
// ⚠️ On cherche la VALEUR, pas la clé. Énumérer les clés interdites laissait
// passer le même secret renvoyé sous le nom `password` — mesuré, la sonde
// restait verte. Un secret qui fuit ne demande la permission d'aucun champ.
verifier('⭐ AUCUN mot de passe n\'est rendu — le serveur n\'en fabrique pas',
    !str_contains((string) json_encode($re, JSON_UNESCAPED_UNICODE), 'un mot de passe choisi par elle'));
verifier('une passphrase neuve est rendue', is_string($re['passphrase'] ?? null));
verifier('⭐ les appareils enrôlés sont retirés par la reprise',
    $st5->trouverAppareil('cred-du-precedent') === null);
verifier('et le défi qui les désignait part avec',
    !$st5->defiEnCours('defi-en-vol', 'cred-du-precedent', $now - 1));
verifier('le compte des appareils retirés est rendu à l\'application',
    ($re['appareils_retires'] ?? null) === 1, (string) ($re['appareils_retires'] ?? 'absent'));
verifier('et le message dit de réenrôler',
    str_contains((string) ($re['message'] ?? ''), 'réenrôle'));
verifier('elle porte la longueur du protocole',
    count(explode(' ', (string) ($re['passphrase'] ?? ''))) === Recovery::MOTS_PASSPHRASE);
verifier('un lot de codes est rendu', count($re['codes'] ?? []) === Recovery::CODES_PAR_LOT);
verifier('le mot de passe choisi est bien celui qui est rangé',
    Hashing::verify('un mot de passe choisi par elle', $st5->empreintes[1] ?? ''));
verifier('le mot mémorisé dérivé est rangé', Hashing::verify($MOT, $st5->comptes['alice']['empreinte_mot']));
verifier('le sel du compte est rangé', ($st5->sels[1] ?? '') === $SEL);
verifier('les sessions sont révoquées — qui tenait le compte est éjecté', $st5->sessionsRevoquees === [1]);

// ⭐ Le marqueur de déploiement suit l'empreinte. `reposerSecrets()` est le seul
// endroit du protocole où l'empreinte du mot mémorisé est réécrite : un marqueur
// laissé en place n'y survit qu'en mentant sur l'adresse sous laquelle le compte
// se dérive désormais. Ce contrôle éprouve l'adaptateur de référence, celui que
// les intégrateurs recopient — il ne peut rien dire du leur.
verifier('⭐ le marqueur de déploiement a suivi le ré-enrôlement',
    ($st5->hotes[1] ?? '?') === $st5->hoteServi,
    'hôte = ' . var_export($st5->hotes[1] ?? null, true));

// ⭐ Un dossier ACCEPTÉ reste actif et n'expire pas. Entre l'accord et le retour
// du titulaire, le compte est au plus ouvert : un second dossier ouvert là sans
// être signalé priverait l'arbitre du fait le plus utile du moment. Et
// l'exemption d'expiration vaut aussi : l'horloge ne doit pas annuler son
// travail avant que le titulaire revienne.
[$stA, $escA] = banc(ProfilDeploiement::TOR_ONION, $now);
$sA = bin2hex(random_bytes(32));
$oA = $escA->ouvrir('alice', Escalade::empreinteSesame($sA), maintenant: $now);
$escA->soumettre((string) $oA['numero'], $sA, ['annee_creation' => '2022'], $now + 7200);
$escA->trancher((string) $oA['numero'], 'accepte', 'arbitre', $now + 7300);
verifier('contre-témoin : le dossier est bien accepté et non consommé',
    ($stA->litiges[0]['statut'] ?? '') === Litige::ACCEPTE);

$tiers = $escA->ouvrir('alice', Escalade::empreinteSesame('un tiers'), maintenant: $now + 3 * $JOUR);
verifier('⭐ un accord reste actif bien après le TTL d\'instruction',
    ($tiers['error'] ?? '') === 'ouverture_refusee', (string) ($tiers['error'] ?? ''));
verifier('et la collision est comptée pour l\'arbitre',
    ($stA->litiges[0]['demandeurs_concurrents'] ?? 0) === 1);

// La reprise tardive d'abord : une fois la place libérée, l'ancien litige est
// clos et c'est son sésame annulé qui refuserait, pas son délai.
$reprise = $escA->reEnroler((string) $oA['numero'], $sA, 'un-mot-de-passe-neuf',
    str_repeat('a', 64), str_repeat('b', 32), $now + 25 * $JOUR);
verifier('⭐ reprendre un accord périmé est refusé, et le refus le nomme',
    ($reprise['error'] ?? '') === 'accord_perime', (string) ($reprise['error'] ?? 'aucun refus'));

// ⭐ La sortie immédiate : l'arbitre abandonne, et la place se libère sans
// attendre l'échéance. C'est ce que le message du refus promet à qui a perdu son
// sésame — une promesse qui n'engageait rien tant que la méthode n'existait pas.
[$stB, $escB] = banc(ProfilDeploiement::TOR_ONION, $now);
$sB = bin2hex(random_bytes(32));
$oB = $escB->ouvrir('alice', Escalade::empreinteSesame($sB), maintenant: $now);
$escB->soumettre((string) $oB['numero'], $sB, ['annee_creation' => '2022'], $now + 60);
$escB->trancher((string) $oB['numero'], 'accepte', 'arbitre', $now + 120);
verifier('contre-témoin : sans abandon, la place reste prise',
    ($escB->ouvrir('alice', Escalade::empreinteSesame('x'), maintenant: $now + 200)['error'] ?? '') === 'ouverture_refusee');
$ab = $escB->abandonner('alice', 'arbitre', $now + 300);
verifier('⭐ un arbitre abandonne le litige en cours', ($ab['ok'] ?? false) === true,
    (string) ($ab['error'] ?? ''));
verifier('il rend le statut qu\'avait le litige', ($ab['statut_precedent'] ?? '') === Litige::ACCEPTE);
// L'ordre compte : une fois la place libre, on la reprend en ouvrant, donc les
// deux refus se mesurent AVANT cette réouverture.
verifier('abandonner deux fois est refusé, et nommé',
    ($escB->abandonner('alice', 'arbitre', $now + 350)['error'] ?? '') === 'aucun_litige');
verifier('abandonner sur un compte inconnu est refusé, et nommé',
    ($escB->abandonner('personne', 'arbitre', $now + 350)['error'] ?? '') === 'compte_inconnu');
verifier('et la place est libre AVANT l\'échéance',
    ($escB->ouvrir('alice', Escalade::empreinteSesame('neuf'), maintenant: $now + 400)['ok'] ?? false) === true);

$tard = $escA->ouvrir('alice', Escalade::empreinteSesame('le titulaire, sans son sesame'),
    maintenant: $now + 25 * $JOUR);
verifier('⭐ passé son délai, l\'accord non repris libère la place',
    ($tard['ok'] ?? false) === true, (string) ($tard['error'] ?? 'refus'));
verifier('et l\'accord périmé est clos : son sésame ne rouvre plus rien',
    ($stA->litiges[0]['statut'] ?? '') === 'closed', (string) ($stA->litiges[0]['statut'] ?? ''));

// ⭐ La purge épargne les REFUSÉS : le gel se compte sur eux, sur trente jours,
// alors qu'un dossier expire en vingt-quatre heures. Les effacer viderait le
// compteur avant son seuil, et le gel deviendrait inatteignable sans qu'aucune
// sonde ne rougisse. Elle épargne aussi les ACCEPTÉS, pour la même raison que
// ci-dessus.
[$stP, $escP] = banc(ProfilDeploiement::TOR_ONION, $now);
$stP->litiges[] = ['id' => 900, 'compte_id' => 1, 'numero' => 'LIT-REFUSE', 'empreinte_sesame' => '',
                   'statut' => Litige::REFUSE, 'ouvert_le' => $now, 'expire_le' => $now + 10,
                   'depose_le' => null, 'faisceau' => null, 'demandeurs_concurrents' => 0,
                   'tranche_par' => 'arbitre', 'tranche_le' => $now];
$stP->litiges[] = ['id' => 901, 'compte_id' => 1, 'numero' => 'LIT-PERIME', 'empreinte_sesame' => '',
                   'statut' => Litige::OUVERT, 'ouvert_le' => $now, 'expire_le' => $now + 10,
                   'depose_le' => null, 'faisceau' => null, 'demandeurs_concurrents' => 0,
                   'tranche_par' => null, 'tranche_le' => null];
$escP->purger($now + 100);
$restants = array_column($stP->litiges, 'numero');
verifier('⭐ la purge épargne le dossier refusé — sans lui le gel ne s\'arme jamais',
    in_array('LIT-REFUSE', $restants, true), implode(', ', $restants));
verifier('contre-témoin : elle efface bien le dossier périmé et non tranché',
    !in_array('LIT-PERIME', $restants, true));

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

[$st6, $esc6] = banc(ProfilDeploiement::TOR_ONION, $now);
$s6 = bin2hex(random_bytes(32));
$o6 = $esc6->ouvrir('alice', Escalade::empreinteSesame($s6), maintenant: $now);
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

[$st7, $esc7] = banc(ProfilDeploiement::TOR_ONION, $now);
$s7 = bin2hex(random_bytes(32));
$o7 = $esc7->ouvrir('alice', Escalade::empreinteSesame($s7), maintenant: $now);
$ex = $esc7->etat((string) $o7['numero'], $s7, $now + $JOUR + 1);
verifier('un dossier expiré n\'est plus recevable', ($ex['ok'] ?? true) === false);
verifier('et le refus dit que c\'est l\'expiration', ($ex['error'] ?? '') === 'expire');

verifier('la purge n\'efface rien tant que le dossier court', $esc7->purger($now) === 0);
verifier('elle efface le dossier périmé', $esc7->purger($now + $JOUR + 1) === 1);
verifier('et le dossier a bien disparu', $st7->litiges === []);

echo "\n→ Un instant qui n'en est pas un\n";

// 🔑 L'angle mort que cette section ferme : un banc qui vérifie
// que le DTO se CONSTRUIT, pas que la valeur qu'il porte veut dire quelque chose.
// Un contrôle de forme reste vert pendant qu'une date est perdue — `2026` est un
// entier parfaitement valide, et une colonne restée en TEXT en produit un.
$champsGardes = [
    'creeLe'    => ['creeLe' => 2026],
    'expireLe'  => ['expireLe' => 1970],
    'deposeLe'  => ['deposeLe' => 2026],
    'trancheLe' => ['trancheLe' => 2026],
];
$litigeAvec = static function (array $remplace) use ($now, $JOUR): Litige {
    $champs = [
        'id' => 1, 'numero' => 'SR-TEST', 'compteId' => 1, 'nomCompte' => 'alice',
        'statut' => Litige::OUVERT, 'empreinteSesame' => str_repeat('c3', 32),
        'creeLe' => $now, 'expireLe' => $now + $JOUR, 'deposeLe' => 0,
        'trancheLe' => null, 'tranchePar' => null, 'demandeursConcurrents' => 0,
    ];

    return new Litige(...array_replace($champs, $remplace));
};

foreach ($champsGardes as $champ => $remplace) {
    $leve = false;
    try {
        $litigeAvec($remplace);
    } catch (\InvalidArgumentException $e) {
        // Le message doit ENSEIGNER : sans le nom du champ, on cherche partout.
        $leve = str_contains($e->getMessage(), $champ);
    }
    verifier("⭐ un {$champ} sous le plancher d'époque est refusé, et nommé", $leve,
        'millésime tiré d\'une colonne TEXT');
}

// Contre-témoins : sans eux, un constructeur qui refuserait TOUT rendrait les
// quatre verts ci-dessus.
$recevable = null;
try {
    $recevable = $litigeAvec([]);
} catch (\InvalidArgumentException $e) {
    $recevable = null;
}
verifier('contre-témoin : un dossier aux instants réels se construit', $recevable instanceof Litige);
verifier('contre-témoin : deposeLe = 0 reste légitime — rien n\'a été déposé',
    $recevable instanceof Litige && $recevable->deposeLe === 0);
verifier('contre-témoin : trancheLe = null reste légitime — personne n\'a tranché',
    $recevable instanceof Litige && $recevable->trancheLe === null);

// 🔑 Le plancher doit être franchement au-dessous de tout instant réel, et
// franchement au-dessus de tout millésime. Sans ce contrôle, quelqu'un pourrait
// le monter jusqu'à rejeter des dossiers valides sans qu'une sonde bouge.
// Un écran d'arbitre annonce les règles du gel : il doit lire celles qu'on a
// réglées, pas les valeurs par défaut.
$escGel = new Escalade($stI, new Recovery($stI, 'sel', ProfilDeploiement::CLEARWEB, Langue::FR, delaiRefusUs: 0),
    gelSeuil: 2, gelFenetre: 86400, gelDuree: 3600, delaiRefusUs: 0);
verifier('les règles du gel rendues sont celles du constructeur',
    $escGel->reglesDuGel() === ['seuil' => 2, 'fenetre' => 86400, 'duree' => 3600],
    json_encode($escGel->reglesDuGel()));

verifier('le plancher est sous tout instant réel du protocole',
    Litige::PLANCHER_EPOQUE < $now && Litige::PLANCHER_EPOQUE > 9999,
    date('Y-m-d', Litige::PLANCHER_EPOQUE));

echo "\n→ Les trois refus de l'ouverture tiennent la même échéance\n";
// Un stockage lent là où un seul des trois refus travaille : le dossier déjà
// ouvert compte son demandeur, donc écrit — un disque qui synchronise.
$stT = new class () extends StockageMemoire {
    public function compterDemandeurConcurrent(int $litigeId): void
    {
        usleep(40000);
        parent::compterDemandeurConcurrent($litigeId);
    }
};
$stT->comptes['alice'] = ['id' => 1, 'empreinte_mot' => 'emp'];
$stT->comptes['bob']   = ['id' => 2, 'empreinte_mot' => 'emp'];
$stT->gels[2]          = ['jusqua' => $now + 3600];
$escT = new Escalade($stT, new Recovery($stT, 'sel', ProfilDeploiement::TOR_ONION, Langue::FR, delaiRefusUs: 0),
    delaiRefusUs: 60000);
$escT->ouvrir('alice', Escalade::empreinteSesame('premier'), maintenant: $now);
$durees = [];
foreach (['personne', 'bob', 'alice'] as $nom) {
    $t0 = hrtime(true);
    $r  = $escT->ouvrir($nom, Escalade::empreinteSesame('second'), maintenant: $now + 1);
    $durees[$nom] = intdiv(hrtime(true) - $t0, 1000000);
    verifier("refus d'ouverture pour « $nom »", ($r['error'] ?? '') === 'ouverture_refusee', (string) ($r['error'] ?? 'accepté'));
}
verifier('⭐ nom inconnu, procédure gelée, dossier ouvert : la même durée, à 20 ms près, au moins le délai',
    min($durees) >= 60 && max($durees) - min($durees) < 20, json_encode($durees));

echo "\n→ Le fil n'accepte plus d'écriture sur un dossier tranché contre le demandeur\n";
// Les quatre statuts, sésame bon partout. Un seul doit refuser l'écriture en
// plus de CLOS : un dossier ACCEPTÉ reste ouvert exprès, le fil étant le seul
// canal entre le demandeur et l'arbitre pendant la reprise des secrets.
$ecritureSous = static function (?string $decision, bool $clore) use ($now, $JOUR): array {
    [$st, $esc] = banc(ProfilDeploiement::TOR_ONION, $now);
    $s = 'sesame-du-cas';
    $o = $esc->ouvrir('alice', Escalade::empreinteSesame($s), maintenant: $now);
    $esc->soumettre((string) $o['numero'], $s, ['annee_creation' => '2024'], $now + 2 * 3600);
    if ($decision !== null) {
        $esc->trancher((string) $o['numero'], $decision, 'arbitre', $now + 2 * 3600 + 10);
    }
    if ($clore) {
        $st->cloreLitige($st->litiges[0]['id'], $now + 2 * 3600 + 20);
    }
    $r = $esc->fil((string) $o['numero'], $s, 'je suis encore là', $now + 2 * 3600 + 30);

    return [$st->litiges[0]['statut'], $r['ok'] ?? null, $r['error'] ?? '—', count($st->messages)];
};
verifier('témoin : sur un dossier en cours, l\'écriture passe',
    $ecritureSous(null, false) === [Litige::A_LIRE, true, '—', 1], json_encode($ecritureSous(null, false)));
verifier('⭐ après un REFUS, l\'écriture est refusée et rien n\'est rangé',
    $ecritureSous('refuse', false) === [Litige::REFUSE, false, 'clos', 0], json_encode($ecritureSous('refuse', false)));
verifier('⭐ après un ACCORD, l\'écriture reste possible — le fil est le canal de la reprise',
    $ecritureSous('accepte', false) === [Litige::ACCEPTE, true, '—', 1], json_encode($ecritureSous('accepte', false)));
verifier('un dossier clos par la bibliothèque refuse sur le sésame, plus tôt dans la chaîne',
    $ecritureSous('accepte', true) === [Litige::CLOS, false, 'sesame_invalide', 0], json_encode($ecritureSous('accepte', true)));
// La forme qu'un intégrateur produit en écrivant `closed` sans vider le sésame.
[$stC, $escC] = banc(ProfilDeploiement::TOR_ONION, $now);
$sC = 'sesame-clos-main';
$oC = $escC->ouvrir('alice', Escalade::empreinteSesame($sC), maintenant: $now);
$stC->trancherLitige($stC->litiges[0]['id'], Litige::CLOS, 'route-non-standard', $now + 10);
$rC = $escC->fil((string) $oC['numero'], $sC, 'et moi ?', $now + 20);
verifier('⭐ clos sans vider le sésame : la lecture reste, l\'écriture non',
    ($rC['error'] ?? '') === 'clos'
    && ($escC->fil((string) $oC['numero'], $sC, null, $now + 30)['ok'] ?? false) === true,
    (string) ($rC['error'] ?? ''));

echo "\n→ Les valeurs LIVRÉES sont celles que les textes annoncent\n";
// ⚠️ Les cas ci-dessous écrivent les défauts du constructeur **en dur**. Un banc
// qui lirait `REPONSE_MAXIMUM` ou `reglesDuGel()` suivrait la constante et
// resterait vert pendant que les textes publiés annonceraient l'ancienne valeur.
// Ces cas sont donc les gardiens des chiffres publiés — c'est leur rôle, pas un
// doublon.
verifier('⭐ les plafonds livrés sont ceux des textes — 500 / 2000 / 100 / 10 s',
    Escalade::REPONSE_MAXIMUM === 500 && Escalade::MESSAGE_MAXIMUM === 2000
    && (static function (): array {
        [, $e] = banc(ProfilDeploiement::TOR_ONION, 1_700_000_000);
        $r = new ReflectionClass($e);
        $lire = static function (string $nom) use ($r, $e) {
            $p = $r->getProperty($nom);

            return $p->getValue($e);
        };

        return [$lire('maxMessagesLitige'), $lire('attenteMessage'),
                $lire('maxOuverturesIp'), $lire('maxOuverturesService')];
    })() === [100, 10, 10, 20],
    json_encode([Escalade::REPONSE_MAXIMUM, Escalade::MESSAGE_MAXIMUM]));
verifier('⭐ les règles du gel livrées sont celles des textes — 3 refus / 30 j / 7 j',
    (static function (): array {
        [, $e] = banc(ProfilDeploiement::TOR_ONION, 1_700_000_000);

        return $e->reglesDuGel();
    })() === ['seuil' => 3, 'fenetre' => 2592000, 'duree' => 604800],
    json_encode((static function (): array {
        [, $e] = banc(ProfilDeploiement::TOR_ONION, 1_700_000_000);

        return $e->reglesDuGel();
    })()));

echo "\n→ Les réponses du faisceau sont bornées, comme les messages du fil\n";
[$stR, $escR] = banc(ProfilDeploiement::TOR_ONION, $now, $now - $JOUR, 40);
$sR = 'sesame-reponses';
$oR = $escR->ouvrir('alice', Escalade::empreinteSesame($sR), maintenant: $now);
$trop = $escR->soumettre((string) $oR['numero'], $sR,
    ['annee_creation' => str_repeat('9', Escalade::REPONSE_MAXIMUM + 1)], $now + 2 * 3600);
verifier('⭐ une réponse au-delà du plafond est refusée, et nommée',
    ($trop['error'] ?? '') === 'reponse_trop_longue', (string) ($trop['error'] ?? 'acceptée'));
verifier('contre-témoin : au plafond exactement, elle passe',
    ($escR->soumettre((string) $oR['numero'], $sR,
        ['annee_creation' => str_repeat('9', Escalade::REPONSE_MAXIMUM)], $now + 2 * 3600)['ok'] ?? false) === true);
// Les clés hors questionnaire sont écartées AVANT la validation : sans ça, la
// boucle de contrôle était un levier de calcul qu'une requête remplit seule.
$bruit = [];
for ($i = 0; $i < 50; $i++) {
    $bruit["cle_inventee_$i"] = str_repeat('x', Escalade::REPONSE_MAXIMUM + 1);
}
$bruit['annee_creation'] = '2024';
[$stB, $escB] = banc(ProfilDeploiement::TOR_ONION, $now, $now - $JOUR, 40);
$sB = 'sesame-bruit';
$oB = $escB->ouvrir('alice', Escalade::empreinteSesame($sB), maintenant: $now);
$rB = $escB->soumettre((string) $oB['numero'], $sB, $bruit, $now + 2 * 3600);
verifier('⭐ 50 clés inventées hors-plafond n\'empêchent pas le dépôt : elles sont écartées avant',
    ($rB['ok'] ?? false) === true, (string) ($rB['error'] ?? ''));
verifier('et rien de ce bruit n\'est rangé dans le faisceau de l\'arbitre',
    !str_contains((string) ($stB->litiges[0]['faisceau'] ?? ''), 'cle_inventee'));

echo "\n→ Le fil d'un dossier est borné, et seul le DEMANDEUR l'est\n";
[$stF, $escF] = banc(ProfilDeploiement::TOR_ONION, $now);
$sF = 'sesame-du-fil';
$oF = $escF->ouvrir('alice', Escalade::empreinteSesame($sF), maintenant: $now);
$nF = (string) $oF['numero'];
$premier = $escF->fil($nF, $sF, 'premier message', $now);
$colle   = $escF->fil($nF, $sF, 'tout de suite après', $now + 1);
verifier('un premier message passe', ($premier['ok'] ?? false) === true);
verifier('⭐ deux messages collés : le second est freiné, et le délai est dit en secondes',
    ($colle['error'] ?? '') === 'trop_rapide' && str_contains((string) ($colle['message'] ?? ''), 'secondes'),
    (string) ($colle['error'] ?? '') . ' / ' . (string) ($colle['message'] ?? ''));
verifier('contre-témoin : passé la fenêtre, le message suivant passe',
    ($escF->fil($nF, $sF, 'plus tard', $now + 120)['ok'] ?? false) === true);
// Le plafond : on remplit en respectant la fenêtre, donc avec le temps simulé.
[$stP, $escP] = banc(ProfilDeploiement::TOR_ONION, $now);
$sP = 'sesame-plafond';
$oP = $escP->ouvrir('alice', Escalade::empreinteSesame($sP), maintenant: $now);
$nP = (string) $oP['numero'];
$dernierOk = null;
for ($i = 0; $i < 120; $i++) {
    $r = $escP->fil($nP, $sP, "message $i", $now + $i * 60);
    if (($r['ok'] ?? false) !== true) {
        $dernierOk = [$i, $r['error'] ?? '—'];
        break;
    }
}
verifier('⭐ le fil se ferme au plafond livré, et le motif le dit',
    $dernierOk === [100, 'fil_plein'], json_encode($dernierOk));
verifier('⭐ et l\'arbitre, lui, peut toujours répondre : son canal n\'est pas borné',
    ($escP->repondre($nP, 'je te réponds quand même', $now + 200 * 60)['ok'] ?? false) === true);

// 🔴 Le cran de trop que la garde du REFUS avait : elle ne regardait pas l'auteur,
// donc l'arbitre refusait sans pouvoir expliquer. Sur le dernier recours de qui
// n'a plus aucun secret, un refus muet est pire que le défaut qu'on fermait.
[$stM, $escM] = banc(ProfilDeploiement::TOR_ONION, $now);
$sM = 'sesame-arbitre';
$oM = $escM->ouvrir('alice', Escalade::empreinteSesame($sM), maintenant: $now);
$nM = (string) $oM['numero'];
$escM->trancher($nM, 'refuse', 'arbitre', $now + 10);
verifier('⭐ après avoir refusé, l\'arbitre peut encore EXPLIQUER son refus',
    ($escM->repondre($nM, 'voici pourquoi je refuse', $now + 20)['ok'] ?? false) === true,
    (string) ($escM->repondre($nM, 'x', $now + 21)['error'] ?? ''));
verifier('et le demandeur, lui, n\'écrit plus sur ce dossier refusé',
    ($escM->fil($nM, $sM, 'et moi ?', $now + 30)['error'] ?? '') === 'clos');
verifier('contre-témoin : sur un dossier CLOS, personne n\'écrit plus — arbitre compris',
    (static function () use ($now): bool {
        [$st, $esc] = banc(ProfilDeploiement::TOR_ONION, $now);
        $o = $esc->ouvrir('alice', Escalade::empreinteSesame('s'), maintenant: $now);
        $st->trancherLitige($st->litiges[0]['id'], Litige::CLOS, 'route', $now + 10);

        return ($esc->repondre((string) $o['numero'], 'un mot', $now + 20)['error'] ?? '') === 'clos';
    })());

echo "\n→ Les trois refus de `recevable()` tiennent la même échéance\n";
// `expire` et `accord_perime` ne sont atteintes QUE si le sésame est bon. Elles
// partaient sans délai là où `sesame_invalide` attendait : le chronomètre disait
// donc ce que le message unique tait.
[$stE] = banc(ProfilDeploiement::TOR_ONION, $now);
$escE = new Escalade($stE, new Recovery($stE, 'sel', ProfilDeploiement::TOR_ONION, Langue::FR, delaiRefusUs: 0),
    delaiRefusUs: 60000);
$sE = 'sesame-echeance';
$oE = $escE->ouvrir('alice', Escalade::empreinteSesame($sE), maintenant: $now);
$nE = (string) $oE['numero'];
$mesures = [];
foreach ([
    'sesame_invalide' => [$nE, 'pas le bon sesame', $now + 10],
    'expire'          => [$nE, $sE, $now + 2 * $JOUR],
] as $attendu => [$num, $ses, $quand]) {
    $t0 = hrtime(true);
    $r  = $escE->etat($num, $ses, $quand);
    $mesures[$attendu] = [intdiv(hrtime(true) - $t0, 1000000), $r['error'] ?? '—'];
}
// L'accord périmé : accepté, jamais repris, au-delà de `ttlAccepte`.
$escE->trancher($nE, 'accepte', 'arbitre', $now + 100);
$t0 = hrtime(true);
$rA = $escE->etat($nE, $sE, $now + 100 + 8 * $JOUR);
$mesures['accord_perime'] = [intdiv(hrtime(true) - $t0, 1000000), $rA['error'] ?? '—'];

verifier('les trois motifs sont bien ceux qu\'on voulait mesurer',
    array_map(static fn (array $m): string => $m[1], $mesures)
    === ['sesame_invalide' => 'sesame_invalide', 'expire' => 'expire', 'accord_perime' => 'accord_perime'],
    json_encode($mesures));
$tps = array_map(static fn (array $m): int => $m[0], $mesures);
verifier('⭐ sésame faux, dossier expiré, accord périmé : la même durée, à 20 ms près, au moins le délai',
    min($tps) >= 60 && max($tps) - min($tps) < 20, json_encode($tps));

echo "\n→ Le plafond de service ne travaille que là où l'adresse ne dit rien\n";
// Sous un service caché, c'est le seul frein : il doit mordre au chiffre livré.
[$stO, $escO] = banc(ProfilDeploiement::TOR_ONION, $now);
for ($i = 0; $i < 20; $i++) {
    $escO->ouvrir("inconnu$i", Escalade::empreinteSesame("s$i"), maintenant: $now + $i);
}
$vingtUn = $escO->ouvrir('alice', Escalade::empreinteSesame('la vraie'), maintenant: $now + 21);
verifier('⭐ sous tor-onion, 20 ouvertures sur des noms inconnus ferment le service — le chiffre livré',
    ($vingtUn['error'] ?? '') === 'trop_de_demandes', (string) ($vingtUn['error'] ?? 'accepté'));
verifier('contre-témoin : à 19, le titulaire ouvre encore', (static function () use ($now): bool {
    [, $e] = banc(ProfilDeploiement::TOR_ONION, $now);
    for ($i = 0; $i < 19; $i++) {
        $e->ouvrir("inconnu$i", Escalade::empreinteSesame("s$i"), maintenant: $now + $i);
    }

    return ($e->ouvrir('alice', Escalade::empreinteSesame('la vraie'), maintenant: $now + 20)['ok'] ?? false) === true;
})());
// Sous clearweb, le profil EXIGE une adresse : le frein par adresse mord
// toujours, et le plafond global n'ajoutait qu'un interrupteur général.
[$stW, $escW] = banc(ProfilDeploiement::CLEARWEB, $now);
for ($i = 0; $i < 30; $i++) {
    $escW->ouvrir("inconnu$i", Escalade::empreinteSesame("s$i"), '198.51.100.' . (10 + intdiv($i, 2)), $now + $i);
}
$trenteUn = $escW->ouvrir('alice', Escalade::empreinteSesame('la vraie'), '198.51.100.200', $now + 31);
verifier('⭐ sous clearweb, 30 ouvertures depuis 15 adresses ne ferment PLUS le service',
    ($trenteUn['ok'] ?? false) === true, (string) ($trenteUn['error'] ?? ''));
verifier('contre-témoin : le frein par adresse mord toujours, lui, au chiffre livré',
    (static function () use ($now): bool {
        [, $e] = banc(ProfilDeploiement::CLEARWEB, $now);
        for ($i = 0; $i < 10; $i++) {
            $e->ouvrir("inconnu$i", Escalade::empreinteSesame("s$i"), '198.51.100.201', $now + $i);
        }

        return ($e->ouvrir('alice', Escalade::empreinteSesame('la vraie'), '198.51.100.201', $now + 11)['error'] ?? '')
            === 'trop_de_demandes';
    })());
verifier('et le profil décide bien, enum à la main',
    ProfilDeploiement::CLEARWEB->adresseDiscriminante() === true
    && ProfilDeploiement::TOR_ONION->adresseDiscriminante() === false);

echo "\n→ Deux gardes qu'aucune assertion ne nommait\n";
// `empreinte_invalide` : le cas existant n'assertait que « ça refuse », ce que le
// refus d'exclusivité satisfait aussi. Sur un compte SANS dossier actif, seul
// le contrôle de forme peut refuser.
[, $escI] = banc(ProfilDeploiement::TOR_ONION, $now);
foreach (['pas-une-empreinte', '', str_repeat('g', 64), str_repeat('a', 63), str_repeat('A', 64)] as $mauvaise) {
    verifier('⭐ une empreinte de sésame malformée est refusée, et nommée — « '
        . (($mauvaise === '') ? '(vide)' : substr($mauvaise, 0, 18)) . ' »',
        ($escI->ouvrir('alice', $mauvaise, maintenant: $now)['error'] ?? '') === 'empreinte_invalide');
}
verifier('contre-témoin : la même ouverture avec une empreinte bien formée réussit',
    ($escI->ouvrir('alice', Escalade::empreinteSesame('correcte'), maintenant: $now)['ok'] ?? false) === true);

// `deja_tranche` dans `soumettre()` : garde vivante, aucune assertion.
[, $escJ] = banc(ProfilDeploiement::TOR_ONION, $now, $now - $JOUR, 40);
$sJ = 'sesame-deja-tranche';
$oJ = $escJ->ouvrir('alice', Escalade::empreinteSesame($sJ), maintenant: $now);
$escJ->trancher((string) $oJ['numero'], 'accepte', 'arbitre', $now + 10);
verifier('⭐ on ne dépose plus de faisceau sur un dossier tranché, et le motif le dit',
    ($escJ->soumettre((string) $oJ['numero'], $sJ, ['annee_creation' => '2024'], $now + 2 * 3600)['error'] ?? '')
    === 'deja_tranche');

// ── La règle « dossier actif », écrite deux fois, doit dire la même chose ───
//
// 🔑 `litigeActifDuCompte()` lit et `ouvrirLitige()` refuse d'insérer : deux
// expressions de la même règle, dans deux requêtes que rien ne relie. Si elles
// divergent, soit un compte porte deux dossiers actifs, soit il n'en ouvre plus
// aucun. Ces deux cas sont ceux qui les séparent.
[$stK] = banc(ProfilDeploiement::TOR_ONION, $now, $now - $JOUR, 40);
$sK = 'sesame-accord-des-conditions';
verifier('⭐ sans dossier, la lecture ne rend rien et l\'ouverture passe',
    $stK->litigeActifDuCompte(1, $now) === null
    && $stK->ouvrirLitige(1, 'LIT-K1', Escalade::empreinteSesame($sK), $now, $now + 86400) === true);
verifier('⭐ dossier COURANT : la lecture le rend, l\'ouverture refuse',
    $stK->litigeActifDuCompte(1, $now) !== null
    && $stK->ouvrirLitige(1, 'LIT-K2', Escalade::empreinteSesame($sK), $now, $now + 86400) === false);
verifier('⭐ dossier EXPIRÉ : la lecture ne le rend plus, l\'ouverture passe',
    $stK->litigeActifDuCompte(1, $now + 86401) === null
    && $stK->ouvrirLitige(1, 'LIT-K3', Escalade::empreinteSesame($sK), $now + 86401, $now + 172800) === true);

// Et le fait vu par l'arbitre : une ouverture refusée se compte sur le dossier
// qui occupe la place, par le chemin public autant que par le stockage.
[$stL, $escL] = banc(ProfilDeploiement::TOR_ONION, $now, $now - $JOUR, 40);
$oL1 = $escL->ouvrir('alice', Escalade::empreinteSesame('sesame-premier'), maintenant: $now);
$escL->ouvrir('alice', Escalade::empreinteSesame('sesame-second'), maintenant: $now);
verifier('⭐ une seconde ouverture compte une collision sur le dossier en place',
    ($stL->litigeActifDuCompte(1, $now)?->demandeursConcurrents ?? 0) === 1
    && $stL->litigeActifDuCompte(1, $now)?->numero === $oL1['numero']);

// ── Le sésame que la bibliothèque tire ─────────────────────────────────────
$tirages = [];
for ($t = 0; $t < 200; $t++) {
    $tirages[] = Escalade::engendrerSesame();
}
verifier('⭐ un sésame tiré fait 64 caractères hexadécimaux — 256 bits',
    count(array_filter($tirages, static fn (string $s): bool => preg_match('/^[a-f0-9]{64}$/', $s) === 1)) === 200);
verifier('⭐ et deux cents tirages donnent deux cents valeurs distinctes',
    count(array_unique($tirages)) === 200);
verifier('son empreinte passe le contrôle de forme d\'ouvrir()',
    preg_match('/^[a-f0-9]{64}$/', Escalade::empreinteSesame($tirages[0])) === 1);

echo "\n" . str_repeat('=', 63) . "\n";
printf("  Escalade SelfRecover — %d passés, %d échoués\n", $passes, $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
