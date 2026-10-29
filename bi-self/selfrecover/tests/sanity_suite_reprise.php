<?php

declare(strict_types=1);

/**
 * La suite d'une récupération (`SuiteDeReprise`) : appelée une fois, dans la
 * transaction, sur chaque chemin qui change un secret du compte ou l'état de
 * la fenêtre d'après un niveau 3 — avec le compte sous sa forme rangée, le
 * secret qui a servi et les secrets neufs. Son mot revient dans la réponse.
 * Une suite qui lève annule la récupération.
 *
 * Usage : php bi-self/selfrecover/tests/sanity_suite_reprise.php
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/StockageMemoire.php';

use Pierroons\SelfRecover\Crypto\Encoding;
use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Device\Device;
use Pierroons\SelfRecover\Langue;
use Pierroons\SelfRecover\ProfilDeploiement;
use Pierroons\SelfRecover\Recovery\Escalade;
use Pierroons\SelfRecover\Recovery\Recovery;
use Pierroons\SelfRecover\Recovery\Reprise;
use Pierroons\SelfRecover\Recovery\SuiteDeReprise;
use Pierroons\SelfRecover\Tests\StockageMemoire;

$passes = 0;
$echecs = 0;

function verifier(string $intitule, bool $condition, string $detail = ''): void
{
    global $passes, $echecs;
    $condition ? $passes++ : $echecs++;
    echo ($condition ? "  \u{2705} " : "  \u{274C} ") . $intitule . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/** Enregistre chaque reprise reçue, et si une transaction était ouverte à ce moment. */
final class SuiteEnregistreuse implements SuiteDeReprise
{
    /** @var list<array{reprise: Reprise, enTransaction: bool}> */
    public array $recues = [];
    public ?string $leve = null;

    public function __construct(private readonly StockageMemoire $stockage)
    {
    }

    public function suivre(Reprise $reprise): string
    {
        $this->recues[] = ['reprise' => $reprise, 'enTransaction' => $this->stockage->transactionOuverte()];
        if ($this->leve !== null) {
            throw new RuntimeException($this->leve);
        }
        return 'suivie';
    }

    public function derniere(): ?Reprise
    {
        return $this->recues === [] ? null : $this->recues[array_key_last($this->recues)]['reprise'];
    }
}

$MOT    = str_repeat('a1', 32);
$PHRASE = 'abime abrite acajou acier adage aerer';
$SEL    = str_repeat('5e', 16);
$MOT_L3 = str_repeat('c3', 32);
$SEL_L3 = str_repeat('d4', 16);
$now    = 1_700_000_000;

/** Un compte aux secrets connus, sa récupération et son escalade, la suite branchée. */
$banc = static function (?SuiteEnregistreuse &$suite) use ($MOT, $PHRASE, $SEL, $now): array {
    $st = new StockageMemoire();
    $st->comptes['alice']     = ['id' => 1, 'empreinte_mot' => Hashing::hash($MOT)];
    $st->passphrases['alice'] = ['id' => 1, 'empreinte_passphrase' => Hashing::hash(Recovery::normaliserPassphrase($PHRASE))];
    $st->empreintes[1] = Hashing::hash('mot de passe de connexion');
    $st->sels[1]       = $SEL;
    $st->faits[1]      = ['cree_le' => $now - 400 * 86400, 'derniere_connexion' => null, 'nombre_connexions' => null];
    $suite = new SuiteEnregistreuse($st);
    $rec   = new Recovery($st, 'sel-du-banc', ProfilDeploiement::CLEARWEB, Langue::FR, delaiRefusUs: 0, suite: $suite);
    $esc   = new Escalade($st, $rec, delaiRefusUs: 0);
    return [$st, $rec, $esc];
};

// ── Sans suite ───────────────────────────────────────────────────────────────
echo "\n→ Sans suite branchée, la réponse le dit\n";

$st0 = new StockageMemoire();
$st0->passphrases['alice'] = ['id' => 1, 'empreinte_passphrase' => Hashing::hash(Recovery::normaliserPassphrase($PHRASE))];
$st0->comptes['alice']     = ['id' => 1, 'empreinte_mot' => Hashing::hash($MOT)];
$r0 = (new Recovery($st0, 'sel-du-banc', ProfilDeploiement::CLEARWEB, Langue::FR, delaiRefusUs: 0))
    ->parPassphrase('alice', $PHRASE, '10.0.0.1', $now);
verifier('niveau 1 sans suite : suite = aucune', ($r0['ok'] ?? false) === true && ($r0['suite'] ?? null) === 'aucune');

// ── Niveau 1 ─────────────────────────────────────────────────────────────────
echo "\n→ Niveau 1\n";

$suite = null;
[$st, $rec] = $banc($suite);
$r = $rec->parPassphrase('  ALICE ', $PHRASE, '10.0.0.1', $now);
$p = $suite->derniere();
verifier('la suite est appelée une fois, dans la transaction, et son mot revient',
    count($suite->recues) === 1 && $suite->recues[0]['enTransaction'] && ($r['suite'] ?? null) === 'suivie');
verifier('elle reçoit le chemin, le compte rangé, la passphrase servie et les secrets neufs',
    $p?->chemin === Reprise::NIVEAU_1 && $p->compte === 'alice' && $p->compteId === 1
    && $p->serrureServie === Reprise::SERRURE_PASSPHRASE && $p->secretServi === $PHRASE
    && $p->motDePasse === $r['mot_de_passe'] && $p->passphrase === $r['passphrase']);
verifier('un refus n\'appelle pas la suite',
    ($rec->parPassphrase('alice', 'pas la bonne passphrase du tout', '10.0.0.1', $now)['ok'] ?? true) === false
    && count($suite->recues) === 1);
verifier('le vidage d\'une reprise ne montre aucun secret', !str_contains(print_r($p, true), $PHRASE));

$suite = null;
[$st, $rec] = $banc($suite);
$suite->leve = 'le coffre refuse';
$leve = null;
try {
    $rec->parPassphrase('alice', $PHRASE, '10.0.0.1', $now);
} catch (RuntimeException $e) {
    $leve = $e->getMessage();
}
verifier('⭐ une suite qui lève annule la récupération : l\'exception remonte',
    $leve === 'le coffre refuse', (string) $leve);
verifier('… et la passphrase n\'est pas consommée',
    Hashing::verify(Recovery::normaliserPassphrase($PHRASE), $st->passphrases['alice']['empreinte_passphrase']));

// ── Niveau 2 par code ────────────────────────────────────────────────────────
echo "\n→ Niveau 2 par code\n";

$suite = null;
[$st, $rec] = $banc($suite);
$codes = $rec->emettreCodes(1, 2, $now);
$r = $rec->parCode($codes[0], $MOT, '10.0.0.1', $now);
$p = $suite->derniere();
verifier('la suite reçoit le mot mémorisé servi, le compte rangé et les secrets neufs, dans la transaction',
    ($r['suite'] ?? null) === 'suivie' && $suite->recues[0]['enTransaction']
    && $p?->chemin === Reprise::NIVEAU_2 && $p->compte === 'alice'
    && $p->serrureServie === Reprise::SERRURE_MOT_MEMORISE && $p->secretServi === $MOT
    && $p->motDePasse === $r['mot_de_passe'] && $p->passphrase === $r['passphrase']);

// ── Niveau 2 par appareil ────────────────────────────────────────────────────
echo "\n→ Niveau 2 par appareil\n";

$suite = null;
[$st] = $banc($suite);
$dev = new Device($st, ProfilDeploiement::CLEARWEB, 'sel-du-banc', Langue::FR, delaiRefusUs: 0, suite: $suite);
$cle = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$pem = openssl_pkey_get_details($cle)['key'];
$spki = base64_decode(implode('', array_filter(explode("\n", $pem), static fn ($l) => !str_contains($l, '-----'))));
$st->enregistrerAppareil(1, 'cred-du-banc', Encoding::b64urlEncode($spki), $now);
$st->enregistrerDefi('defi-du-banc', 'cred-du-banc', $now + 1);
openssl_sign('defi-du-banc', $der, $cle, OPENSSL_ALGO_SHA256);
$lr = ord($der[3]);
$ls = ord($der[4 + $lr + 1]);
$p1363 = str_pad(ltrim(substr($der, 4, $lr), "\x00"), 32, "\x00", STR_PAD_LEFT)
       . str_pad(ltrim(substr($der, 4 + $lr + 2, $ls), "\x00"), 32, "\x00", STR_PAD_LEFT);
$r = $dev->cloreDefi('cred-du-banc', 'defi-du-banc', Encoding::b64urlEncode($p1363), $now + 2);
$p = $suite->derniere();
verifier('la suite reçoit le chemin appareil, sans secret servi, avec le mot de passe neuf',
    ($r['ok'] ?? false) === true && ($r['suite'] ?? null) === 'suivie' && $suite->recues[0]['enTransaction']
    && $p?->chemin === Reprise::APPAREIL && $p->compte === 'alice'
    && $p->serrureServie === null && $p->secretServi === null && $p->motDePasse === $r['mot_de_passe']);

// ── Niveau 3, retour du titulaire, clôture ───────────────────────────────────
echo "\n→ Niveau 3, puis le titulaire revient, puis l'arbitre clôt\n";

$suite = null;
[$st, $rec, $esc] = $banc($suite);
$sesame = bin2hex(random_bytes(32));
$o = $esc->ouvrir('alice', Escalade::empreinteSesame($sesame), '10.0.0.7', $now);
$esc->trancher((string) $o['numero'], 'accepte', 'arbitre', $now + 10);
$r3 = $esc->reEnroler((string) $o['numero'], $sesame, 'le mot de passe du demandeur', $MOT_L3, $SEL_L3, $now + 20);
$p = $suite->derniere();
verifier('niveau 3 : la suite reçoit les secrets neufs, le mot mémorisé dérivé et le numéro du dossier',
    ($r3['suite'] ?? null) === 'suivie' && $suite->recues[0]['enTransaction']
    && $p?->chemin === Reprise::NIVEAU_3 && $p->compte === 'alice' && $p->secretServi === null
    && $p->motDePasse === 'le mot de passe du demandeur' && $p->passphrase === $r3['passphrase']
    && $p->motMemorise === $MOT_L3 && $p->numeroLitige === (string) $o['numero']);
$ticketL3 = (string) $p?->ticket;
verifier('niveau 3 : un ticket rangé dans la transaction, de son genre, et que la fenêtre garde',
    ($st->ticketEmis($ticketL3)['genre'] ?? null) === Reprise::NIVEAU_3 && $p->ticketNiveau3 === $ticketL3
    && ($st->fenetreDuCompte(1)['ticket_niveau3'] ?? null) === $ticketL3);

$retour = $rec->parPassphrase('alice', $PHRASE, '10.0.0.8', $now + 100);
$p = $suite->derniere();
verifier('retour : la suite reçoit la passphrase d\'avant et les secrets neufs du titulaire',
    ($retour['titulaire_reprend'] ?? false) === true && ($retour['suite'] ?? null) === 'suivie'
    && $suite->recues[1]['enTransaction'] && $p?->chemin === Reprise::RETOUR_TITULAIRE
    && $p->serrureServie === Reprise::SERRURE_PASSPHRASE && $p->secretServi === $PHRASE
    && $p->motDePasse === $retour['mot_de_passe'] && $p->passphrase === $retour['passphrase']);
verifier('retour : son propre ticket, et celui du niveau 3 sous lequel l\'archive du titulaire attend',
    ($st->ticketEmis((string) $p?->ticket)['genre'] ?? null) === Reprise::RETOUR_TITULAIRE && $p->ticketNiveau3 === $ticketL3);

$clos = $esc->cloreFenetre('alice', 'arbitre', $now + 200);
$p = $suite->derniere();
verifier('clôture : la suite est appelée dans la transaction, sans secret',
    ($clos['suite'] ?? null) === 'suivie' && $suite->recues[2]['enTransaction']
    && $p?->chemin === Reprise::CLOTURE && $p->compte === 'alice' && $p->secretServi === null && $p->motDePasse === null);
verifier('clôture : son ticket, et celui du niveau 3',
    ($st->ticketEmis((string) $p?->ticket)['genre'] ?? null) === Reprise::CLOTURE && $p->ticketNiveau3 === $ticketL3);

// 🔑 Deux niveaux 3 dans une même fenêtre : l'archive du titulaire est celle du
// PREMIER. Le second émet son ticket, mais la fenêtre garde celui du premier.
$suite = null;
[$st, $rec, $esc] = $banc($suite);
foreach (['premier', 'second'] as $i => $qui) {
    $sesameI = bin2hex(random_bytes(32));
    $oI = $esc->ouvrir('alice', Escalade::empreinteSesame($sesameI), '10.0.0.7', $now + 1000 * $i);
    $esc->trancher((string) $oI['numero'], 'accepte', 'arbitre', $now + 1000 * $i + 10);
    $esc->reEnroler((string) $oI['numero'], $sesameI, "le mot de passe du {$qui} demandeur", $MOT_L3, $SEL_L3, $now + 1000 * $i + 20);
}
[$premier, $second] = [$suite->recues[0]['reprise'], $suite->recues[1]['reprise']];
verifier('⭐ un second niveau 3 dans la fenêtre a son ticket, mais la fenêtre garde celui du premier',
    $premier->ticket !== $second->ticket && $second->ticketNiveau3 === $premier->ticket
    && ($st->fenetreDuCompte(1)['ticket_niveau3'] ?? null) === $premier->ticket);

// Une suite qui lève à la clôture : la fenêtre reste reprise, rien n'est clos.
$suite = null;
[$st, $rec, $esc] = $banc($suite);
$o = $esc->ouvrir('alice', Escalade::empreinteSesame($sesame), '10.0.0.7', $now);
$esc->trancher((string) $o['numero'], 'accepte', 'arbitre', $now + 10);
$esc->reEnroler((string) $o['numero'], $sesame, 'le mot de passe du demandeur', $MOT_L3, $SEL_L3, $now + 20);
$rec->parPassphrase('alice', $PHRASE, '10.0.0.8', $now + 100);
$suite->leve = 'le coffre ne dégèle pas';
$leve = null;
try {
    $esc->cloreFenetre('alice', 'arbitre', $now + 200);
} catch (RuntimeException $e) {
    $leve = $e->getMessage();
}
verifier('⭐ une suite qui lève à la clôture l\'annule : la fenêtre reste reprise',
    $leve === 'le coffre ne dégèle pas' && $esc->fenetre('alice', $now + 210)['etat'] === 'reprise');

echo "\n" . str_repeat('=', 63) . "\n";
printf("%s — %d/%d\n", $echecs === 0 ? 'OK' : 'ÉCHEC', $passes, $passes + $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
