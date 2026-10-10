<?php

declare(strict_types=1);

/**
 * Efface ce que la bibliothèque laisse derrière elle : les dossiers de niveau 3
 * périmés, et les lignes d'échec anciennes. À lancer périodiquement.
 *
 * 🔴 **La bibliothèque n'appelle cette purge nulle part.** Elle n'a pas
 * d'horloge, et `Escalade::purger()` n'a aucun appelant dans `src/` : sans une
 * tâche planifiée, les dossiers périmés s'empilent avec leur empreinte de
 * sésame. L'échéance les rend inactifs, elle n'efface rien.
 *
 * 🔑 **Elle ne demande PAS le sel du déploiement**, et c'est voulu. `purger()`
 * ne fait que déléguer à `StorageInterface::purgerLitigesExpires()` ; passer par
 * `Escalade` obligerait à construire un `Recovery`, donc à mettre un secret de
 * service dans l'environnement d'un cron qui n'en a aucun usage. Une purge a
 * besoin d'une connexion, pas d'une identité.
 *
 * ⚠️ Un intégrateur qui a sa propre implémentation de `StorageInterface`
 * remplace le bloc de connexion ci-dessous par le sien. Le reste ne change pas.
 *
 * Configuration, par l'environnement :
 *   SELFRECOVER_DSN    DSN PDO, obligatoire (ex. sqlite:/var/lib/bi-self/recover.db)
 *   SELFRECOVER_USER   identifiant, si le moteur en demande
 *   SELFRECOVER_PASS   mot de passe, si le moteur en demande
 *
 * ⚠️ **Un seul outil pour les deux purges, et c'est voulu.** Le déploiement ne
 * pose pas ce script — l'unité dit pourquoi. Deux outils feraient deux poses à
 * la main, donc deux occasions d'en oublier une, et une purge oubliée ne
 * rougit jamais.
 *
 * Usage :
 *   php tools/purger.php              efface et dit combien
 *   php tools/purger.php --a-blanc    dit combien SANS rien effacer (lecture seule)
 *   php tools/purger.php --jours=90   garde les échecs 90 jours (30 par défaut)
 */

// ⚠️ Cet outil n'est pas autonome : il lui faut `src/` à côté de `tools/`.
// Posé seul hors de son module, `require` lèverait une erreur PHP brute dans le
// journal d'un planificateur, là où ce qui manque est la bibliothèque entière.
$autoload = __DIR__ . '/../src/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "purger.php : bibliothèque introuvable à $autoload.\n"
        . "Posez le MODULE (bi-self/selfrecover/, avec src/ et tools/), pas ce seul fichier.\n");
    exit(2);
}
require $autoload;

use Pierroons\SelfRecover\Etiquette;
use Pierroons\SelfRecover\Storage\StockagePdo;

$aBlanc = in_array('--a-blanc', $argv, true);

// Les freins ne lisent qu'un quart d'heure, mais la suspension du niveau 2 se
// réarme sur une date que rien ne borne : une rétention courte la rouvrirait.
$jours = 30;
foreach ($argv as $argument) {
    if (preg_match('/^--jours=([0-9]+)$/', $argument, $m) === 1) {
        $jours = max(1, (int) $m[1]);
    }
}

$dsn = getenv('SELFRECOVER_DSN');
if (!is_string($dsn) || trim($dsn) === '') {
    fwrite(STDERR, "SELFRECOVER_DSN est vide : rien à purger, et ce n'est pas un succès.\n");
    exit(2);
}

try {
    $pdo = new PDO(
        $dsn,
        (string) (getenv('SELFRECOVER_USER') ?: ''),
        (string) (getenv('SELFRECOVER_PASS') ?: ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
} catch (PDOException $e) {
    // Le message d'un PDO porte le DSN, donc un chemin de disque ou un hôte :
    // il va sur la sortie d'erreur d'un cron, pas dans un journal public.
    fwrite(STDERR, 'connexion impossible : ' . $e->getMessage() . "\n");
    exit(2);
}

$stockage = new StockagePdo($pdo);
$maintenant = time();

$seuilEchecs = $maintenant - $jours * 86400;

if ($aBlanc) {
    // 🔑 **Ce mode LIT, il n'écrit pas — et le contrat est ce qui le rend exact.**
    // `compterLitigesExpires()` et `compterEchecsPurgeables()` partagent la clause
    // de leur purge, elles ne la recopient pas : ce qui est annoncé ici est ce que
    // la purge effacerait, sans qu'aucune suppression ait lieu.
    //
    // ⚠️ **Une première version exécutait les deux suppressions puis les
    // annulait.** Le compte était juste pour la même raison, mais la destruction
    // devenait inconditionnelle et la non-destruction dépendait du moteur : sur un
    // moteur sans transaction réelle, « seraient effacés » s'imprimait sur une base
    // déjà vidée. Et même annulée, la transaction prenait le verrou d'écriture le
    // temps des deux `DELETE`, donc une récupération en cours pouvait échouer
    // pendant une simple estimation.
    try {
        $dossiers = $stockage->compterLitigesExpires($maintenant);
        $lignes   = $stockage->compterEchecsPurgeables($seuilEchecs, Etiquette::PREFIXES);
    } catch (\Throwable $e) {
        // Court et sur stderr, comme le reste : rien n'a été touché, donc le
        // message ne parle que de la lecture.
        fwrite(STDERR, "purger.php : le compte à blanc a échoué, rien n'a été lu — " . $e->getMessage() . "\n");
        exit(2);
    }
    printf("%d dossier(s) seraient effacés.\n", $dossiers);
    printf("%d ligne(s) d'échec seraient effacées (rétention : %d jours).\n", $lignes, $jours);
    exit(0);
}

// 🔑 **Les deux purges dans UNE transaction.** Séparées, la première se valide
// et la seconde peut lever : les dossiers périmés disparaissent chaque jour
// pendant que la table des échecs croît sans fin, et l'unité dépose cet échec au
// journal sans que rien d'autre le dise.
//
// ⚠️ **Les comptes se disent APRÈS la validation**, jamais entre deux
// destructions : annoncés plus tôt, ils portent sur une opération à moitié
// faite.
$stockage->commencerTransaction();
try {
    $effaces = $stockage->purgerLitigesExpires($maintenant);
    $echecs  = $stockage->purgerEchecs($seuilEchecs, Etiquette::PREFIXES);
    $stockage->validerTransaction();
} catch (\Throwable $e) {
    $stockage->annulerTransaction();
    // Court et sur stderr, comme les autres sorties de ce script : une trace de
    // pile en CLI part sur STDOUT, et le journal d'un planificateur n'est pas
    // l'endroit où lire une exception non rattrapée.
    fwrite(STDERR, "purger.php : la purge a échoué, rien n'a été effacé — " . $e->getMessage() . "\n");
    exit(2);
}
printf("%d dossier(s) effacé(s).\n", $effaces);
printf("%d ligne(s) d'échec effacée(s) (rétention : %d jours).\n", $echecs, $jours);

// ⚠️ Les lignes de RÉUSSITE survivent, et le contrat dit pourquoi : la
// suspension du niveau 2 se réarme sur une date que rien ne borne dans le
// temps. En effacer une rouvrirait une suspension déjà levée.

// ⚠️ Les dossiers ACCEPTÉS et REFUSÉS survivent par conception — le premier
// parce qu'un titulaire en retard doit encore trouver son accord, le second
// parce que son compte informe l'arbitre. Leur rétention est une décision de
// déploiement, à prendre avec ce que la loi locale impose : cette purge ne la
// couvre pas, et le taire laisserait croire qu'elle borne la table.
exit(0);
