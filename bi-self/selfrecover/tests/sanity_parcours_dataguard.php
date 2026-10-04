<?php

declare(strict_types=1);

/**
 * Un coffre SelfDataGuard traverse chaque récupération de SelfRecover.
 *
 * Les deux bibliothèques ne s'appellent pas l'une l'autre : c'est
 * l'intégrateur qui, après chaque récupération acceptée, rouvre le coffre avec
 * le secret que le serveur tient à ce moment-là et le re-scelle avec ceux que
 * SelfRecover vient d'émettre. Ce banc joue ce branchement sur les vrais
 * chemins — niveau 1, niveau 2 par code, renouvellement des codes, niveau 2
 * par appareil, niveau 3 — et vérifie à chaque fois qu'une donnée écrite au
 * départ se relit avec le nouveau mot de passe.
 *
 * Usage : php bi-self/selfrecover/tests/sanity_parcours_dataguard.php
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/../../../self-security/selfdataguard/src/autoload.php';
require __DIR__ . '/StockageMemoire.php';

use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\WrongSecretException;
use Pierroons\SelfRecover\Crypto\Encoding;
use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Device\Device;
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

/**
 * Le secret ouvre-t-il encore le coffre par cette serrure ? Passe par recover(),
 * qui re-scelle le mot de passe sur celui donné : on lui passe toujours le mot
 * de passe courant, pour que la question n'écrive rien d'autre.
 */
function ouvre(SelfDataGuard $dg, Lock $serrure, string $secret, string $motDePasse): bool
{
    try {
        $dg->recover('alice', $serrure, $secret, $motDePasse);
        return true;
    } catch (WrongSecretException) {
        return false;
    }
}

function note(SelfDataGuard $dg, string $motDePasse): ?string
{
    try {
        return $dg->getFields($dg->loginWithPassword('alice', $motDePasse))['note'] ?? null;
    } catch (WrongSecretException) {
        return null;
    }
}

$PROFIL = ProfilDeploiement::TOR_ONION;
$SELD   = 'sel-de-deploiement-du-parcours';
$now    = 1_700_000_000;
$MOT    = str_repeat('a1', 32);     // l'empreinte du mot mémorisé, telle que le navigateur l'envoie
$PHR    = 'cheval agrafe batterie correct moulin ivoire';
$MDP    = 'mot de passe de connexion';
$NOTE   = 'écrite avant toute récupération';

$st = new StockageMemoire();
$st->comptes['alice']     = ['id' => 1, 'empreinte_mot' => Hashing::hash($MOT)];
$st->passphrases['alice'] = ['id' => 1, 'empreinte_passphrase' => Hashing::hash($PHR)];
$st->empreintes[1]        = Hashing::hash($MDP);
$st->hotes[1]             = $st->hoteServi;
$st->faits[1]             = ['cree_le' => $now - 400 * 86400, 'derniere_connexion' => null, 'nombre_connexions' => null];

$rec = new Recovery($st, $SELD, $PROFIL, delaiRefusUs: 0);
$dev = new Device($st, $PROFIL, $SELD, delaiRefusUs: 0);
$esc = new Escalade($st, $rec, delaiRefusUs: 0);

$dg = new SelfDataGuard(new SqliteAdapter('sqlite::memory:'), Primitives::randomBytes(32));
$dg->setFields($dg->register('alice', $MDP, $MOT, $PHR), ['note' => $NOTE]);

// ── Niveau 1 ───────────────────────────────────────────────────────────────
echo "\n→ Niveau 1 — le serveur tient l'ancienne passphrase\n";

$r = $rec->parPassphrase('alice', $PHR, null, $now);
verifier('SelfRecover rend un mot de passe et une passphrase neufs',
    ($r['ok'] ?? false) === true && isset($r['mot_de_passe'], $r['passphrase']));
$dg->recover('alice', Lock::Passphrase, $PHR, $r['mot_de_passe'], $r['passphrase']);
verifier('le nouveau mot de passe relit la note', note($dg, $r['mot_de_passe']) === $NOTE);
verifier('la passphrase consommée n\'ouvre plus le coffre',
    !ouvre($dg, Lock::Passphrase, $PHR, $r['mot_de_passe']));
verifier('… ni le compte', ($rec->parPassphrase('alice', $PHR, null, $now)['ok'] ?? true) === false);
[$MDP, $PHR] = [$r['mot_de_passe'], $r['passphrase']];

// ── Niveau 2 par code ─────────────────────────────────────────────────────
echo "\n→ Niveau 2 par code — le serveur tient l'empreinte du mot\n";

$codes = $rec->emettreCodes(1, 2, $now);
$r = $rec->parCode($codes[0], $MOT, null, $now);
verifier('SelfRecover rend un mot de passe et une passphrase neufs',
    ($r['ok'] ?? false) === true && isset($r['mot_de_passe'], $r['passphrase']));
$dg->recover('alice', Lock::Memorized, $MOT, $r['mot_de_passe'], $r['passphrase']);
verifier('le nouveau mot de passe relit la note', note($dg, $r['mot_de_passe']) === $NOTE);
verifier('la passphrase remplacée n\'ouvre plus le coffre',
    !ouvre($dg, Lock::Passphrase, $PHR, $r['mot_de_passe']));
verifier('la nouvelle l\'ouvre', ouvre($dg, Lock::Passphrase, $r['passphrase'], $r['mot_de_passe']));
[$MDP, $PHR] = [$r['mot_de_passe'], $r['passphrase']];

// ── Renouvellement des codes ──────────────────────────────────────────────
echo "\n→ Renouvellement des codes — le coffre n'a rien à faire\n";

$neufs = $rec->emettreCodes(1, 2, $now + 60);
verifier('un code du nouveau lot ouvre le compte',
    ($rec->parCode($neufs[0], $MOT, null, $now + 60)['ok'] ?? false) === true);
$r = $rec->parCode($neufs[1], $MOT, null, $now + 61);
$dg->recover('alice', Lock::Memorized, $MOT, $r['mot_de_passe'], $r['passphrase']);
verifier('l\'empreinte du mot n\'a pas changé : elle ouvre toujours le coffre',
    note($dg, $r['mot_de_passe']) === $NOTE);
[$MDP, $PHR] = [$r['mot_de_passe'], $r['passphrase']];

// ── Passphrase apportée ───────────────────────────────────────────────────
echo "\n→ Passphrase apportée — c'est la forme rangée qui scelle le coffre\n";

$fr       = Wordlist::load('fr');
$apportee = implode(' ', array_slice($fr, 3000, Recovery::MOTS_PASSPHRASE));
$desordre = '  ' . strtoupper(str_replace(' ', "\t ", $apportee)) . ' ';
$r = $rec->parPassphrase('alice', $PHR, null, $now + 70, nouvellePassphrase: $desordre);
$rescelle = ($r['ok'] ?? false) === true;
try {
    $dg->recover('alice', Lock::Passphrase, $PHR, $r['mot_de_passe'], $r['passphrase']);
} catch (\Throwable) {
    $rescelle = false;
}
verifier('niveau 1 : une passphrase apportée en désordre re-scelle le coffre sans lever', $rescelle);
[$MDP, $PHR] = [$r['mot_de_passe'], $r['passphrase']];
verifier('la forme rendue ouvre le coffre et le compte',
    $PHR === $apportee && ouvre($dg, Lock::Passphrase, $PHR, $MDP));

$union = array_values(array_unique(array_merge(Wordlist::load('en'), $fr)));
usort($union, static fn (string $a, string $b): int => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
$courte = implode(' ', array_slice($union, 0, Recovery::MOTS_PASSPHRASE));
$lot    = $rec->emettreCodes(1, 1, $now + 80);
$r = $rec->parCode($lot[0], $MOT, null, $now + 80, nouvellePassphrase: $courte);
$rescelle = ($r['ok'] ?? false) === true;
try {
    $dg->recover('alice', Lock::Memorized, $MOT, $r['mot_de_passe'], $r['passphrase']);
} catch (\Throwable) {
    $rescelle = false;
}
verifier('niveau 2 : la plus courte passphrase apportable re-scelle le coffre',
    $rescelle && ouvre($dg, Lock::Passphrase, $r['passphrase'], $r['mot_de_passe']));
[$MDP, $PHR] = [$r['mot_de_passe'], $r['passphrase']];

// ── Niveau 2 par appareil ─────────────────────────────────────────────────
echo "\n→ Niveau 2 par appareil — le serveur ne tient aucun secret du coffre\n";

$cle = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$pem = openssl_pkey_get_details($cle)['key'];
$spki = base64_decode(implode('', array_filter(explode("\n", $pem), static fn ($l) => !str_contains($l, '-----'))));
$st->enregistrerAppareil(1, 'cred-telephone', Encoding::b64urlEncode($spki), $now);
$st->enregistrerDefi('defi-du-parcours', 'cred-telephone', $now + 100);
openssl_sign('defi-du-parcours', $der, $cle, OPENSSL_ALGO_SHA256);
$lr = ord($der[3]);
$ls = ord($der[4 + $lr + 1]);
$p1363 = str_pad(ltrim(substr($der, 4, $lr), "\x00"), 32, "\x00", STR_PAD_LEFT)
       . str_pad(ltrim(substr($der, 4 + $lr + 2, $ls), "\x00"), 32, "\x00", STR_PAD_LEFT);
$r = $dev->cloreDefi('cred-telephone', 'defi-du-parcours', Encoding::b64urlEncode($p1363), $now + 100);
verifier('SelfRecover rend un mot de passe neuf, et aucune passphrase',
    ($r['ok'] ?? false) === true && isset($r['mot_de_passe']) && !isset($r['passphrase']));
verifier('le coffre ne suit pas : le nouveau mot de passe ne l\'ouvre pas encore',
    note($dg, $r['mot_de_passe']) === null);
$dg->recover('alice', Lock::Passphrase, $PHR, $r['mot_de_passe'], $r['passphrase'] ?? null);
verifier('rattrapé par la passphrase : le nouveau mot de passe relit la note',
    note($dg, $r['mot_de_passe']) === $NOTE);
verifier('sans passphrase neuve, la serrure passphrase est restée',
    ouvre($dg, Lock::Passphrase, $PHR, $r['mot_de_passe']));
$MDP = $r['mot_de_passe'];

// ── Niveau 3 ──────────────────────────────────────────────────────────────
echo "\n→ Niveau 3 — aucun ancien secret : l'ancien coffre est archivé\n";

$sesame = bin2hex(random_bytes(32));
$o = $esc->ouvrir('alice', Escalade::empreinteSesame($sesame), maintenant: $now + 200);
$esc->trancher((string) $o['numero'], 'accepte', 'arbitre', $now + 300);
$MOT3 = str_repeat('c3', 32);
$MDP3 = 'un mot de passe choisi par elle';
$r = $esc->reEnroler((string) $o['numero'], $sesame, $MDP3, $MOT3, str_repeat('d4', 16), $now + 400);
verifier('SelfRecover reprend le compte et émet une passphrase',
    ($r['ok'] ?? false) === true && isset($r['passphrase']), (string) ($r['message'] ?? ''));

$l3 = $dg->reEnroll('alice', $MDP3, $MOT3, $r['passphrase']);
verifier('le coffre neuf est vide, l\'ancien est archivé',
    $dg->getFields($l3['unlocked']) === [] && is_string($l3['archiveId']));
$ouverte = $dg->openArchive($l3['unlocked'], $l3['archiveId'], Lock::Passphrase, $PHR);
verifier('l\'ancienne passphrase, retrouvée plus tard, rouvre l\'archive',
    ($dg->readArchive($ouverte)['private']['note'] ?? null) === $NOTE);
verifier('… alors qu\'elle n\'ouvre plus le compte',
    ($rec->parPassphrase('alice', $PHR, null, $now + 500)['ok'] ?? true) === false);

// ─────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 63) . "\n";
printf("%s — %d/%d\n", $echecs === 0 ? 'OK' : 'ÉCHEC', $passes, $passes + $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
