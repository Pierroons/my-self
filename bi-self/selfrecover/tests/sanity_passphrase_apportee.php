<?php

declare(strict_types=1);

/**
 * Banc — la passphrase apportée par l'utilisateur.
 *
 * Le validateur (`Recovery::validerPassphraseApportee()`), puis les trois
 * niveaux qui l'acceptent à la place d'une passphrase tirée par le serveur. Ce
 * qu'il tient : six mots des listes, sous une forme unique ; un refus qui ne
 * cite aucun mot, ne charge aucun frein et ne consomme rien ; l'ancienne
 * passphrase qui ne revient pas.
 *
 * Les phrases sont tirées des listes à l'exécution : écrire des mots de la
 * liste en clair dans le dépôt ferait rougir l'audit des données personnelles,
 * qui en cherche de courants.
 *
 * Run:  php bi-self/selfrecover/tests/sanity_passphrase_apportee.php [--profil=clearweb|tor-onion]
 * Exit: 0 si tout passe.
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/StockageMemoire.php';

use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Diceware\Wordlist;
use Pierroons\SelfRecover\ProfilDeploiement;
use Pierroons\SelfRecover\Recovery\Escalade;
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

$opt    = getopt('', ['profil::']);
$PROFIL = ProfilDeploiement::from($opt['profil'] ?? 'clearweb');
$IP     = $PROFIL === ProfilDeploiement::CLEARWEB ? '192.0.2.7' : null;
echo "\n→ Profil joué : {$PROFIL->value}\n";

$EN  = Wordlist::load('en');
$FR  = Wordlist::load('fr');
$now = 1_700_000_000;
$MOT = str_repeat('a1', 32);
$SEL = 'sel-de-deploiement-pour-la-sonde';

/** `$combien` mots distincts d'au moins six lettres, à partir d'un rang : un mot cité se voit. */
function phrase(array $liste, int $depuis, int $combien = Recovery::MOTS_PASSPHRASE): string
{
    $mots = [];
    for ($i = $depuis; count($mots) < $combien; $i++) {
        if (strlen($liste[$i]) >= 6 && !str_contains($liste[$i], '-')) {
            $mots[] = $liste[$i];
        }
    }

    return implode(' ', $mots);
}

function compte(string $ancienne): array
{
    global $PROFIL, $MOT, $SEL;
    $st = new StockageMemoire();
    $st->comptes['alice']     = ['id' => 1, 'empreinte_mot' => Hashing::hash($MOT)];
    $st->passphrases['alice'] = ['id' => 1, 'empreinte_passphrase' => Hashing::hash($ancienne)];

    return [$st, new Recovery($st, $SEL, $PROFIL, delaiRefusUs: 0)];
}

// ── A. Le validateur ───────────────────────────────────────────────────────
echo "\n→ Le validateur\n";
$pEn = phrase($EN, 1000);
$pFr = phrase($FR, 2000);
$v   = Recovery::validerPassphraseApportee($pEn);
verifier('six mots de la liste anglaise sont acceptés, tels quels', $v === ['ok' => true, 'canonique' => $pEn]);
verifier('six mots de la liste française sont acceptés', (Recovery::validerPassphraseApportee($pFr)['ok'] ?? false) === true);
$melange = implode(' ', array_merge(array_slice(explode(' ', $pEn), 0, 3), array_slice(explode(' ', $pFr), 0, 3)));
verifier('un mélange des deux listes est accepté', (Recovery::validerPassphraseApportee($melange)['ok'] ?? false) === true);
$tirets = 't-shirt drop-down yo-yo felt-tip ' . implode(' ', array_slice(explode(' ', $pFr), 0, 2));
$vT     = Recovery::validerPassphraseApportee($tirets);
verifier('un mot à trait d\'union compte pour un mot, et n\'est pas coupé',
    ($vT['canonique'] ?? '') === $tirets, (string) ($vT['motif'] ?? ''));
$desordre = "  " . strtoupper(str_replace(' ', "\t \r\n", $pEn)) . "  ";
$vD       = Recovery::validerPassphraseApportee($desordre);
verifier('majuscules, tabulations, retours et bords : une seule forme, en minuscules',
    ($vD['canonique'] ?? '') === $pEn);
verifier('la forme rangée est un point fixe : la valider ou la normaliser la rend inchangée',
    (Recovery::validerPassphraseApportee($pEn)['canonique'] ?? '') === $pEn
    && Recovery::normaliserPassphrase($pEn) === $pEn);
$cinq = phrase($EN, 1000, Recovery::MOTS_PASSPHRASE - 1);
verifier('cinq mots sont refusés : trop courte',
    (Recovery::validerPassphraseApportee($cinq)['motif'] ?? '') === 'trop_courte');
verifier('une saisie vide, ou d\'espaces, est refusée : seul null laisse le serveur tirer',
    (Recovery::validerPassphraseApportee('')['motif'] ?? '') === 'trop_courte'
    && (Recovery::validerPassphraseApportee("   \t ")['motif'] ?? '') === 'trop_courte');
$horsListe = $pEn . ' zqxwvkj';
$vH        = Recovery::validerPassphraseApportee($horsListe);
verifier('un mot hors des deux listes est refusé', ($vH['motif'] ?? '') === 'hors_liste');
$json = (string) json_encode([$vH, Recovery::validerPassphraseApportee($pEn . ' ' . $pEn)], JSON_UNESCAPED_UNICODE);
$cite = false;
foreach (array_merge(explode(' ', $pEn), ['zqxwvkj']) as $mot) {
    $cite = $cite || str_contains($json, $mot);
}
verifier('⭐ un refus ne cite aucun mot saisi — il dit des positions', !$cite);
verifier('il dit où : le septième mot', str_contains((string) ($vH['message'] ?? ''), 'n° 7'));
$accent = substr($pFr, 0, (int) strrpos($pFr, ' ')) . ' été';
verifier('un mot accentué n\'est dans aucune liste', (Recovery::validerPassphraseApportee($accent)['motif'] ?? '') === 'hors_liste');
verifier('une espace insécable ne sépare pas deux mots',
    (Recovery::validerPassphraseApportee(str_replace(' ', "\u{00A0}", $pEn) . ' ' . $pEn)['motif'] ?? '') === 'hors_liste');
verifier('les tirets ne séparent pas les mots : il faut des espaces',
    (Recovery::validerPassphraseApportee(str_replace(' ', '-', $pEn))['motif'] ?? '') === 'trop_courte');
$bruit = [];
set_error_handler(static function (int $n, string $m) use (&$bruit): bool { $bruit[] = $m; return true; });
$vNul    = Recovery::validerPassphraseApportee($pEn . " zz\0zz");
$vCasse  = Recovery::validerPassphraseApportee($pEn . " zz\xC3\x28zz");
restore_error_handler();
verifier('un octet nul dans un mot le met hors liste, sans avertissement',
    ($vNul['motif'] ?? '') === 'hors_liste' && $bruit === [], implode(' | ', $bruit));
verifier('un UTF-8 cassé dans un mot le met hors liste, sans avertissement',
    ($vCasse['motif'] ?? '') === 'hors_liste' && $bruit === [], implode(' | ', $bruit));
verifier('au-delà du plafond d\'octets, la saisie est refusée comme trop longue',
    (Recovery::validerPassphraseApportee(str_repeat($pEn . ' ', 200))['motif'] ?? '') === 'trop_longue');
$repete = implode(' ', array_merge(array_slice(explode(' ', $pEn), 0, 5), [explode(' ', $pEn)[1]]));
verifier('un mot qui revient est refusé : on relance le dé', (Recovery::validerPassphraseApportee($repete)['motif'] ?? '') === 'mot_repete');
$tous = true;
foreach (array_merge($EN, $FR) as $mot) {
    $tous = $tous && Wordlist::inAnyList($mot);
}
verifier('chaque mot des deux listes y est, et la réunion compte 14 931 mots',
    $tous && count(array_unique(array_merge($EN, $FR))) === 14931 && !Wordlist::inAnyList(strtoupper($EN[0])));

// ── B. Niveau 1 ────────────────────────────────────────────────────────────
echo "\n→ Niveau 1 — la passphrase qui sert est remplacée par celle qu'on apporte\n";
$ancienne = phrase($EN, 3000);
$neuve    = phrase($FR, 4000);
[$st, $rec] = compte($ancienne);
$r = $rec->parPassphrase('alice', $ancienne, $IP, $now, nouvellePassphrase: strtoupper($neuve));
verifier('une passphrase apportée valide est acceptée, et rendue sous la forme rangée',
    ($r['ok'] ?? false) === true && ($r['passphrase'] ?? '') === $neuve, (string) ($r['message'] ?? ''));
verifier('c\'est la forme rangée qui est hachée, pas la saisie',
    Hashing::verify($neuve, $st->passphrases['alice']['empreinte_passphrase'])
    && !Hashing::verify(strtoupper($neuve), $st->passphrases['alice']['empreinte_passphrase']));
verifier('le message est celui du tirage serveur : il dit de noter',
    str_contains((string) ($r['message'] ?? ''), 'note-les'));
$r2 = $rec->parPassphrase('alice', $neuve, $IP, $now + 10);
verifier('elle ouvre le niveau 1 suivant, et le serveur tire alors (contre-témoin null)',
    ($r2['ok'] ?? false) === true && count(explode(' ', (string) ($r2['passphrase'] ?? ''))) === Recovery::MOTS_PASSPHRASE);

[$st, $rec] = compte($ancienne);
$traces = count($st->tentatives);
$rRefus = $rec->parPassphrase('alice', $ancienne, $IP, $now, nouvellePassphrase: $cinq);
verifier('une apportée invalide est refusée, sans trace, et l\'ancienne ouvre encore',
    ($rRefus['error'] ?? '') === 'passphrase_invalide' && count($st->tentatives) === $traces
    && Hashing::verify($ancienne, $st->passphrases['alice']['empreinte_passphrase']));
$formes = [];
foreach ([[$ancienne], ['une mauvaise passphrase'], ['bob', true]] as $cas) {
    $nom = isset($cas[1]) ? 'bob' : 'alice';
    $formes[] = json_encode($rec->parPassphrase($nom, $cas[0], $IP, $now, nouvellePassphrase: $cinq));
}
verifier('le refus de forme est le même pour la bonne passphrase, une mauvaise, un compte inconnu',
    count(array_unique($formes)) === 1);
for ($i = 0; $i < 10; $i++) {
    $rec->parPassphrase('alice', 'une mauvaise passphrase', $IP, $now + $i, nouvellePassphrase: $horsListe);
}
$apres = $rec->parPassphrase('alice', $ancienne, $IP, $now + 20, nouvellePassphrase: $neuve);
verifier('un refus de la passphrase apportée ne charge pas le frein', ($apres['ok'] ?? false) === true,
    (string) ($apres['message'] ?? ''));

[$st, $rec] = compte($ancienne);
$rDeja = $rec->parPassphrase('alice', $ancienne, $IP, $now, nouvellePassphrase: '  ' . strtoupper($ancienne));
verifier('apporter celle qui sert est refusé, sans trace, rien de consommé',
    ($rDeja['error'] ?? '') === 'passphrase_deja_servie' && $st->tentatives === []
    && Hashing::verify($ancienne, $st->passphrases['alice']['empreinte_passphrase']));
$permutee = implode(' ', array_reverse(explode(' ', $ancienne)));
$rPerm    = $rec->parPassphrase('alice', $ancienne, $IP, $now, nouvellePassphrase: $permutee);
verifier('ses mots dans un autre ordre sont refusés aussi : c\'est le même papier',
    ($rPerm['error'] ?? '') === 'passphrase_deja_servie');

// ── C. Niveau 2 ────────────────────────────────────────────────────────────
echo "\n→ Niveau 2 — code et mot mémorisé, passphrase apportée\n";
[$st, $rec] = compte($ancienne);
$codes = $rec->emettreCodes(1, 3, $now);
$c2    = $rec->parCode($codes[0], $MOT, $IP, $now, nouvellePassphrase: $neuve);
verifier('une apportée valide est acceptée et rangée', ($c2['ok'] ?? false) === true && ($c2['passphrase'] ?? '') === $neuve
    && Hashing::verify($neuve, $st->passphrases['alice']['empreinte_passphrase']), (string) ($c2['message'] ?? ''));

[$st, $rec] = compte($ancienne);
$codes  = $rec->emettreCodes(1, 3, $now);
$traces = count($st->tentatives);
$cR     = $rec->parCode($codes[0], $MOT, $IP, $now, nouvellePassphrase: $horsListe);
$tracesApres = count($st->tentatives);
$encore = $rec->parCode($codes[0], $MOT, $IP, $now + 1, nouvellePassphrase: $neuve);
verifier('une apportée invalide ne consomme pas le code et ne laisse aucune trace',
    ($cR['error'] ?? '') === 'passphrase_invalide' && $tracesApres === $traces && ($encore['ok'] ?? false) === true);
$formes2 = [];
foreach ([['00000-00000', $MOT], [$codes[1], str_repeat('f0', 32)], [$codes[1], $MOT]] as [$code, $mot]) {
    $formes2[] = json_encode($rec->parCode($code, $mot, $IP, $now + 2, nouvellePassphrase: $cinq));
}
verifier('le refus de forme est le même pour un code inconnu, un mauvais mot, les bons facteurs',
    count(array_unique($formes2)) === 1);

[$st, $rec] = compte($ancienne);
$codes = $rec->emettreCodes(1, 3, $now);
$cD    = $rec->parCode($codes[0], $MOT, $IP, $now, nouvellePassphrase: $ancienne);
$cOk   = $rec->parCode($codes[0], $MOT, $IP, $now + 1, nouvellePassphrase: $neuve);
verifier('niveau 2 : la passphrase remplacée ne peut pas revenir',
    ($cD['error'] ?? '') === 'passphrase_deja_servie' && ($cOk['ok'] ?? false) === true,
    (string) ($cD['error'] ?? 'accepté'));
$cMauvais = $rec->parCode($codes[1], str_repeat('f0', 32), $IP, $now + 2, nouvellePassphrase: $neuve);
verifier('avec un mauvais mot, l\'égalité n\'est pas jugée : refus ordinaire',
    ($cMauvais['message'] ?? '') === 'Code ou mot mémorisé incorrect.' && !isset($cMauvais['error']));

// ── D. Niveau 3 ────────────────────────────────────────────────────────────
echo "\n→ Niveau 3 — reprise après accord\n";
$SEL_C = str_repeat('c3', 16);
function dossier(string $ancienne): array
{
    global $PROFIL, $now, $IP;
    $st = new StockageMemoire();
    $st->comptes['alice']     = ['id' => 1, 'empreinte_mot' => Hashing::hash('mot memorise initial')];
    $st->passphrases['alice'] = ['id' => 1, 'empreinte_passphrase' => Hashing::hash($ancienne)];
    $st->empreintes[1]        = Hashing::hash('mot de passe de connexion');
    $st->hotes[1]             = $st->hoteServi;
    $st->faits[1]             = ['cree_le' => $now - 400 * 86400, 'derniere_connexion' => null, 'nombre_connexions' => null];
    $esc    = new Escalade($st, new Recovery($st, 'sel-de-la-sonde', $PROFIL, delaiRefusUs: 0), delaiRefusUs: 0);
    $sesame = bin2hex(random_bytes(32));
    $numero = (string) $esc->ouvrir('alice', Escalade::empreinteSesame($sesame), $IP, $now)['numero'];
    $esc->trancher($numero, 'accepte', 'arbitre', $now + 100);

    return [$st, $esc, $numero, $sesame];
}
$MDP = 'un mot de passe choisi par elle';

[$st, $esc, $n, $s] = dossier($ancienne);
$e = $esc->reEnroler($n, $s, $MDP, $MOT, $SEL_C, $now + 200, nouvellePassphrase: $neuve);
verifier('une apportée valide est rangée sous sa forme, et des codes sont émis',
    ($e['ok'] ?? false) === true && ($e['passphrase'] ?? '') === $neuve
    && Hashing::verify($neuve, $st->passphrases['alice']['empreinte_passphrase'])
    && count($e['codes'] ?? []) === Recovery::CODES_PAR_LOT, (string) ($e['error'] ?? ''));

[$st, $esc, $n, $s] = dossier($ancienne);
$eR = $esc->reEnroler($n, $s, $MDP, $MOT, $SEL_C, $now + 200, nouvellePassphrase: $cinq);
verifier('une apportée invalide est refusée, le dossier reste accepté, rien ne bouge',
    ($eR['error'] ?? '') === 'passphrase_invalide' && ($st->litiges[0]['statut'] ?? '') === 'accepted'
    && Hashing::verify($ancienne, $st->passphrases['alice']['empreinte_passphrase']));
$eD = $esc->reEnroler($n, $s, $MDP, $MOT, $SEL_C, $now + 210, nouvellePassphrase: $ancienne);
verifier('apporter l\'ancienne est refusé', ($eD['error'] ?? '') === 'passphrase_deja_servie');
$pMdp = phrase($EN, 5000);
$eM   = $esc->reEnroler($n, $s, $pMdp, $MOT, $SEL_C, $now + 220, nouvellePassphrase: strtoupper($pMdp));
verifier('une passphrase égale au mot de passe choisi est refusée', ($eM['error'] ?? '') === 'passphrase_egale_mot_de_passe');
$eOk = $esc->reEnroler($n, $s, $MDP, $MOT, $SEL_C, $now + 230, nouvellePassphrase: $neuve);
verifier('après ces refus, une apportée valide passe', ($eOk['ok'] ?? false) === true, (string) ($eOk['error'] ?? ''));

[$st, $esc, $n, $s] = dossier($ancienne);
$eS = $esc->reEnroler($n, 'pas le sésame', $MDP, $MOT, $SEL_C, $now + 200, nouvellePassphrase: $cinq);
verifier('derrière le sésame : un mauvais sésame est refusé avant la passphrase',
    ($eS['error'] ?? '') === 'sesame_invalide', (string) ($eS['error'] ?? ''));

echo "\n" . str_repeat('=', 63) . "\n";
printf("  Passphrase apportée — %d passés, %d échoués\n", $passes, $echecs);
printf("OK — %d/%d\n", $passes, $passes + $echecs);
echo str_repeat('=', 63) . "\n\n";
exit($echecs === 0 ? 0 : 1);
