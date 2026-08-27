<?php

declare(strict_types=1);

/**
 * Éprouve la dérivation du mot mémorisé contre les vecteurs figés.
 *
 * Usage : php tests/derivation.php
 * Sort 0 si tout passe, 1 sinon. Aucune dépendance.
 *
 * ⚠️ **CE FICHIER EST UN ORACLE DE TEST, JAMAIS UN COMPOSANT DE PRODUCTION.**
 *
 * `derivation()` ci-dessous reproduit en PHP ce que le navigateur calcule. Elle
 * n'a pas sa place dans `src/`, et il ne faut pas l'y déplacer « pour la
 * réutiliser ». La propriété centrale du protocole est que le mot mémorisé
 * n'arrive JAMAIS au serveur — `Device::estCleDerivee()` existe précisément pour
 * refuser un mot en clair, en disant « la dérivation doit se faire dans le
 * navigateur ». Livrer cette fonction à côté de ce refus, ce serait poser
 * l'outil du contournement contre la porte qu'il ouvre : un intégrateur pressé
 * écrirait `derivation($_POST['mot'], ...)` parce que c'est plus simple que de
 * faire tourner du JavaScript, et le protocole entier s'effondrerait sans qu'une
 * seule ligne ait l'air fautive.
 *
 * Elle vit ici parce qu'aucune intégration continue ne lance un navigateur, et
 * qu'il faut bien quelque chose qui sache calculer la réponse attendue.
 *
 * 🔑 On ne compare pas deux textes de code. Une comparaison textuelle rougit
 * quand quelqu'un reformate le JavaScript — faux rouge, et un contrôle qui crie
 * à tort se fait désactiver — et reste muette quand deux formules se ressemblent
 * en s'appliquant différemment — faux vert, le pire des deux. Chaque
 * implémentation est donc confrontée aux mêmes vecteurs, écrits une fois.
 */

$echecs = 0;
function verdict(string $quoi, bool $ok, string $detail = ''): void
{
    global $echecs;
    echo ($ok ? '  ok     ' : '  RATE   ') . $quoi . ($detail !== '' ? " — $detail" : '') . "\n";
    if (!$ok) {
        $echecs++;
    }
}

/**
 * L'oracle. Le mot en CLÉ, `<matériel>|v<N>` puis le sel en MESSAGE.
 *
 * Le mode décide de la normalisation, et c'est une vraie différence : un nom
 * d'hôte est insensible à la casse par nature, un label est une chaîne que
 * l'intégrateur choisit et sur laquelle nous n'avons rien à dire.
 */
function derivation(string $mot, string $mode, string $materiel, string $sel, string $version = 'v2'): string
{
    $m = $mode === 'hostname' ? strtolower($materiel) : $materiel;

    return hash_hmac('sha256', $m . '|' . $version . $sel, $mot);
}

$brut = file_get_contents(__DIR__ . '/vecteurs-derivation.json');
if ($brut === false) {
    fwrite(STDERR, "vecteurs-derivation.json introuvable\n");
    exit(1);
}
$doc = json_decode($brut, true, 512, JSON_THROW_ON_ERROR);

echo "── L'oracle PHP retrouve-t-il les vecteurs ? ──────────\n";
foreach ($doc['vecteurs'] as $v) {
    $obtenu = derivation($v['mot'], $v['mode'], $v['materiel'], $v['sel'], $doc['version']);
    verdict($v['quoi'], $obtenu === $v['empreinte'],
        $obtenu === $v['empreinte'] ? '' : substr($obtenu, 0, 16) . '… attendu ' . substr($v['empreinte'], 0, 16) . '…');
}

echo "\n── Ce que les vecteurs doivent prouver entre eux ──────\n";

// 🔑 Sans ceci, un jeu de vecteurs tous identiques passerait au vert. On vérifie
// donc que les vecteurs se distinguent là où le protocole l'exige.
$par = [];
foreach ($doc['vecteurs'] as $v) {
    $par[$v['quoi']] = $v['empreinte'];
}
$hote     = $par['mode hostname, sel ordinaire'] ?? '';
$imitateur = $par["🔑 le MÊME mot sur un autre hôte — doit différer du premier"] ?? '';
$majuscule = $par["🔑 un hôte en MAJUSCULES rend l'empreinte du minuscule"] ?? '';

verdict('🔑 deux hôtes différents donnent deux empreintes', $hote !== '' && $hote !== $imitateur,
    'la propriété entière du mode hostname');
verdict("🔑 la casse de l'hôte ne change rien", $hote !== '' && $hote === $majuscule,
    "sinon EXEMPLE.TEST et exemple.test seraient deux comptes");

$labels = array_values(array_filter($doc['vecteurs'], static fn ($v) => $v['mode'] === 'label'));
verdict("un label garde sa casse", count($labels) === 2
    && $labels[0]['empreinte'] !== $labels[1]['empreinte'],
    "l'intégrateur le choisit, nous n'y touchons pas");

echo "\n── Les longueurs et les formes ────────────────────────\n";
$toutes = array_column($doc['vecteurs'], 'empreinte');
verdict('toutes les empreintes font 64 hexadécimaux',
    count(array_filter($toutes, static fn ($e) => (bool) preg_match('/^[0-9a-f]{64}$/', $e))) === count($toutes));
// 🔑 UN doublon est attendu, et exigé : le vecteur en majuscules doit rendre
// EXACTEMENT l'empreinte du minuscule, c'est ce que « la casse ne change rien »
// veut dire. Écrire ici « aucun doublon » contredirait le contrôle d'au-dessus,
// et l'un des deux mentirait forcément — le premier jet le faisait. On compte
// donc les doublons INATTENDUS, en retirant la paire de normalisation.
$attendus = 1;
$doublons = count($toutes) - count(array_unique($toutes));
verdict('aucun doublon inattendu entre les vecteurs',
    $doublons === $attendus,
    "$doublons doublon(s), $attendus attendu (la paire de casse)");

echo "\n";
if ($echecs === 0) {
    echo "  " . count($doc['vecteurs']) . " vecteurs, l'oracle PHP les retrouve tous.\n";
    exit(0);
}
echo "  $echecs contrôle(s) en échec.\n";
exit(1);
