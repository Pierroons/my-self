#!/usr/bin/env php
<?php
/**
 * Contrôle du hash factice qui égalise les temps de réponse.
 *
 * 🔑 `RecoverHelper::dummyHash()` est un littéral figé, écrit à quelques lignes
 * du tableau `ARGON2` dont il doit reproduire le coût. Rien dans le langage ne
 * relie les deux : ce sont deux syntaxes différentes pour les mêmes nombres, et
 * une relecture ne montre pas leur divergence.
 *
 * ⚠️ Le jour où le profil OWASP est révisé — `memory_cost` doublé, par exemple —
 * les vrais hachages coûtent deux fois plus, le factice reste au tarif ancien, et
 * l'écart de temps que ce hash existe pour supprimer réapparaît sur tous les
 * endpoints à la fois. Aucun test ne rougirait, aucun journal ne le dirait.
 *
 * Le premier contrôle ci-dessous est donc le seul qui compte vraiment : il rend
 * la contrainte exécutable au lieu de la laisser écrite dans un commentaire.
 *
 * Usage : php tests/sanity_timing.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/recover_helper.php';

$echecs = 0;
function ok(string $m): void  { echo "  ✓ $m\n"; }
function nok(string $m): void { global $echecs; fwrite(STDERR, "  ✗ $m\n"); $echecs++; }

// ── 1. Le factice porte-t-il le profil courant ? ────────────────────────────
$dummy = RecoverHelper::dummyHash();
if (password_needs_rehash($dummy, PASSWORD_ARGON2ID, RecoverHelper::ARGON2)) {
    $info = password_get_info($dummy);
    nok(sprintf(
        "profil divergent — le factice porte %s, ARGON2 demande m=%d,t=%d,p=%d",
        json_encode($info['options']),
        RecoverHelper::ARGON2['memory_cost'],
        RecoverHelper::ARGON2['time_cost'],
        RecoverHelper::ARGON2['threads']
    ));
} else {
    ok('le hash factice porte bien le profil ARGON2 courant');
}

// ── 2. Est-il seulement calculable ? ────────────────────────────────────────
// Une chaîne malformée ferait rendre `false` à password_verify en quelques
// microsecondes : le correctif serait entièrement décoratif et le test n°1,
// qui ne lit que les paramètres annoncés, ne le verrait pas.
$t0 = microtime(true);
$r  = password_verify('peu importe', $dummy);
$dt = (microtime(true) - $t0) * 1000;

if ($r !== false) {
    nok('password_verify rend vrai contre le hash factice — inattendu');
} elseif ($dt < 10.0) {
    nok(sprintf('vérification en %.2f ms — trop rapide pour un Argon2id, hash probablement malformé', $dt));
} else {
    ok(sprintf('hash factice calculable — vérification en %.1f ms', $dt));
}

// ── 3. Coûte-t-il autant qu'un vrai ? ───────────────────────────────────────
// C'est la propriété que tout le dispositif vise : le chemin « compte inconnu »
// doit prendre le même temps que le chemin « compte connu ».
$vrai = RecoverHelper::hash('un secret de test, jamais employé ailleurs');

$mesure = static function (string $hash): float {
    $t = [];
    for ($i = 0; $i < 5; $i++) {
        $d = microtime(true);
        password_verify('mauvaise valeur', $hash);
        $t[] = (microtime(true) - $d) * 1000;
    }
    sort($t);
    return $t[2];                       // médiane, moins sensible qu'une moyenne
};

$mVrai  = $mesure($vrai);
$mDummy = $mesure($dummy);
$ecart  = abs($mVrai - $mDummy);
$seuil  = max(5.0, $mVrai * 0.20);      // 20 % du coût réel, plancher à 5 ms

if ($ecart > $seuil) {
    nok(sprintf('écart de %.1f ms entre vrai (%.1f) et factice (%.1f) — seuil %.1f ms',
        $ecart, $mVrai, $mDummy, $seuil));
} else {
    ok(sprintf('coûts équivalents — vrai %.1f ms, factice %.1f ms, écart %.1f ms',
        $mVrai, $mDummy, $ecart));
}

echo "\n";
if ($echecs === 0) {
    echo "OK — 3/3 contrôles conformes.\n";
    exit(0);
}
fwrite(STDERR, "ÉCHEC — $echecs contrôle(s) sur 3.\n");
exit(1);
