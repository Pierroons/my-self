<?php

declare(strict_types=1);

/**
 * Banc — `Recovery::selDeDerivation()` rend toujours un sel.
 *
 * Un vrai pour un code du compte, consommé ou non, quelle que soit sa casse ; un
 * faux, de la forme d'un vrai et stable, pour un code inconnu ou mal formé ; une
 * erreur bruyante si le stockage ne sait pas retrouver le sel.
 *
 * Run:  php bi-self/selfrecover/tests/sanity_sel_derivation.php
 * Exit: 0 si tout passe.
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/StockageMemoire.php';

use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\ProfilDeploiement;
use Pierroons\SelfRecover\Recovery\Recovery;
use Pierroons\SelfRecover\Storage\StorageInterface;
use Pierroons\SelfRecover\Tests\StockageMemoire;

$passes = 0;
$echecs = 0;
function verifier(string $intitule, bool $condition, string $detail = ''): void
{
    global $passes, $echecs;
    $condition ? $passes++ : $echecs++;
    echo ($condition ? "  \u{2705} " : "  \u{274C} ") . $intitule . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$now     = 1_700_000_000;
$SEL_DEP = 'sel-de-deploiement-pour-la-sonde';
$SEL_A   = str_repeat('a1', 16);

$st = new StockageMemoire();
$st->comptes['alice'] = ['id' => 1, 'empreinte_mot' => Hashing::hash(str_repeat('b2', 32))];
$st->sels[1] = $SEL_A;
$rec   = new Recovery($st, $SEL_DEP, ProfilDeploiement::CLEARWEB, delaiRefusUs: 0);
$codes = $rec->emettreCodes(1, 3, $now);

echo "\n→ Un code du compte\n";
verifier('un code valide rend le sel du compte', $rec->selDeDerivation($codes[0]) === $SEL_A);
verifier('le même code en majuscules et entouré d\'espaces rend le même sel',
    $rec->selDeDerivation('  ' . strtoupper($codes[0]) . ' ') === $SEL_A);
$st->consommerCode((int) $st->trouverCodeParIndex($rec->indexRecherche($codes[1]))['code_id'], $now);
verifier('un code consommé rend encore le vrai sel — sinon la route dirait « compte récupéré »',
    $rec->selDeDerivation($codes[1]) === $SEL_A);

echo "\n→ Un code inconnu\n";
$faux1 = $rec->selDeDerivation('00000-00000');
$faux2 = $rec->selDeDerivation('fffff-fffff');
verifier('un code inconnu reçoit un sel de la forme d\'un vrai', Recovery::estSelCompte($faux1), $faux1);
verifier('ce faux sel n\'est pas celui du compte', $faux1 !== $SEL_A);
verifier('il est stable quand le code est retenté', $rec->selDeDerivation('00000-00000') === $faux1);
verifier('deux codes inconnus reçoivent deux sels différents', $faux1 !== $faux2);
$autre = new Recovery($st, 'un-autre-sel-de-deploiement', ProfilDeploiement::CLEARWEB, delaiRefusUs: 0);
verifier('le faux sel dépend du sel du déploiement : il ne se recalcule pas de dehors',
    $autre->selDeDerivation('00000-00000') !== $faux1);
$mal = $rec->selDeDerivation('pas-un-code');
verifier('une entrée qui n\'a pas la forme d\'un code reçoit aussi un sel, sans erreur', Recovery::estSelCompte($mal), $mal);

echo "\n→ Un stockage qui ne sait pas retrouver le sel\n";
// Un adaptateur qui n'implémente que StorageInterface, fabriqué depuis l'interface
// elle-même : chaque méthode lève, aucune ne doit être appelée.
$methodes = [];
foreach ((new ReflectionClass(StorageInterface::class))->getMethods() as $m) {
    $params = [];
    foreach ($m->getParameters() as $p) {
        $params[] = ($p->hasType() ? $p->getType() . ' ' : '') . '$' . $p->getName()
            . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : '');
    }
    $methodes[] = sprintf("public function %s(%s)%s { throw new \\LogicException('inattendu'); }",
        $m->getName(), implode(', ', $params), $m->hasReturnType() ? ': ' . $m->getReturnType() : '');
}
eval('final class StockageSansSel implements \\' . StorageInterface::class . ' { ' . implode("\n", $methodes) . ' }');
$sans = new Recovery(new StockageSansSel(), $SEL_DEP, ProfilDeploiement::CLEARWEB, delaiRefusUs: 0);
try {
    $sans->selDeDerivation($codes[0]);
    verifier('un stockage sans SelParCodeInterface est refusé bruyamment', false, 'aucune erreur');
} catch (LogicException $e) {
    verifier('un stockage sans SelParCodeInterface est refusé bruyamment',
        str_contains($e->getMessage(), 'SelParCodeInterface'), $e->getMessage());
}

echo "\n" . str_repeat('=', 63) . "\n";
printf("  Route du sel — %d passés, %d échoués\n", $passes, $echecs);
printf("OK — %d/%d\n", $passes, $passes + $echecs);
echo str_repeat('=', 63) . "\n\n";
exit($echecs === 0 ? 0 : 1);
