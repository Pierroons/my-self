#!/usr/bin/env php
<?php
/**
 * Les quatre scénarios du simulateur d'attaques tournent, et chacun dit le vrai.
 *
 * 🔑 Ce banc existe parce que deux d'entre eux étaient MORTS en service, et que
 * rien ne le disait. `bruteforce` et `csrf` appelaient `sr_sel_aleatoire()` et
 * `sr_derive_like_browser()`, définies dans `lib/derive_cli.php`, que ni
 * `bootstrap.php` ni `attack_sim.php` ne chargeaient : un clic sur leur bouton
 * de `/attacks.php` rendait `Error: Call to undefined function`. Le seul banc
 * qui touchait le simulateur nommait `packvoting` — il passait vert, et deux
 * scénarios sur quatre tombaient.
 *
 * ⚠️ D'où la forme : ce banc PARCOURT `AttackSimulator::SCENARIOS` au lieu d'en
 * citer un. Un scénario ajouté demain est éprouvé sans qu'on y pense ; un
 * scénario nommé à la main se serait tu de la même façon.
 *
 * Et il ne se contente pas de l'absence d'exception. `bruteforce` rendait
 * `verdict => neutralisé` alors que son propre côté légitime affichait un échec
 * de connexion : `register` recevait le mot là où il attend la clé dérivée, donc
 * refusait sans calculer un Argon2id, et la clé du mot de passe manquait au
 * tableau. Une démonstration publique peut mentir sans jamais lever d'erreur —
 * ce banc regarde donc ce que chaque scénario AFFIRME.
 *
 * Usage : php tests/sanity_simulateur.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
putenv('LAB_SITESALT_PATH=' . sys_get_temp_dir() . '/lab-simulateur-sitesalt-' . getmypid());
register_shutdown_function(static fn () => @unlink((string) getenv('LAB_SITESALT_PATH')));
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/attack_sim.php';

use Pierroons\MySelfLab\AttackSimulator;

$reussites = 0;
$echecs = 0;

function verifier(string $quoi, bool $vrai, string $detail = ''): void
{
    global $reussites, $echecs;
    if ($vrai) {
        $reussites++;
        echo "  ✓ $quoi\n";
        return;
    }
    $echecs++;
    echo "  ✗ $quoi" . ($detail !== '' ? " — $detail" : '') . "\n";
}

verifier(
    'la liste des scénarios n\'est pas vide',
    AttackSimulator::SCENARIOS !== [],
    'sans elle, la boucle ci-dessous ne mesurerait rien'
);

foreach (AttackSimulator::SCENARIOS as $scenario) {
    echo "\n▸ $scenario\n";

    $resultat = null;
    $leve = null;
    try {
        $resultat = AttackSimulator::run($scenario);
    } catch (\Throwable $e) {
        $leve = get_class($e) . ' : ' . $e->getMessage();
    }

    // C'est le contrôle qui manquait : une fonction non chargée ne se voit qu'ici.
    verifier("« $scenario » s'exécute sans lever", $leve === null, (string) $leve);
    if ($leve !== null) {
        continue;
    }

    verifier("« $scenario » rend ok = true", ($resultat['ok'] ?? null) === true,
        'rendu : ' . var_export($resultat['ok'] ?? null, true));

    foreach (['titre', 'objectif', 'verdict', 'defense'] as $champ) {
        verifier("« $scenario » porte un $champ non vide",
            is_string($resultat[$champ] ?? null) && trim((string) $resultat[$champ]) !== '');
    }

    $etapes = $resultat['etapes'] ?? [];
    verifier("« $scenario » décrit au moins deux étapes",
        is_array($etapes) && count($etapes) >= 2, 'étapes : ' . count((array) $etapes));

    // 🔑 Chaque étape porte une action ET son résultat. Une étape sans résultat
    // laisse le lecteur conclure lui-même, et c'est précisément ce qu'une
    // démonstration ne doit pas lui demander.
    $incompletes = 0;
    foreach ((array) $etapes as $etape) {
        $a = trim((string) (($etape['action'] ?? '')));
        $r = trim((string) (($etape['resultat'] ?? '')));
        if ($a === '' || $r === '') {
            $incompletes++;
        }
    }
    verifier("« $scenario » : aucune étape sans action ni résultat", $incompletes === 0,
        "$incompletes étape(s) incomplète(s)");

    // Une affirmation de défense ne doit pas cohabiter avec une ligne qui dit
    // l'inverse. `bruteforce` annonçait « neutralisé » en affichant que le
    // titulaire avec son bon mot de passe échouait, parce que l'inscription
    // avait été refusée en amont.
    if (($resultat['verdict'] ?? '') === 'neutralisé') {
        $lignes = [];
        foreach (['cote_attaquant', 'cote_legitime'] as $cote) {
            foreach ((array) ($resultat[$cote]['lignes'] ?? []) as $l) {
                $lignes[] = (string) $l;
            }
        }
        $texte = implode(' | ', $lignes);
        verifier(
            "« $scenario » annonce « neutralisé » sans qu'une ligne contredise la défense",
            $texte === '' || !preg_match('/\b(échec|echec|erreur|undefined|Error)\b/iu', $texte),
            substr($texte, 0, 120)
        );
    }
}

$total = $reussites + $echecs;
echo "\n" . ($echecs === 0 ? "OK — $reussites/$total contrôles conformes." : "ÉCHEC — $echecs sur $total") . "\n";
exit($echecs === 0 ? 0 : 1);
