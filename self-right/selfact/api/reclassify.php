<?php
/**
 * SelfAct — Re-classification du catalogue existant avec heuristique améliorée.
 *
 * Usage : php reclassify.php [--dry-run]
 *
 * Ne re-fetche PAS service-public.fr. Relit le catalogue en place, applique la
 * nouvelle classification, réécrit le fichier.
 */

declare(strict_types=1);

$dryRun = in_array('--dry-run', $argv, true);

/**
 * Classification améliorée par règles ordonnées (premier match gagne).
 * L'ordre est important : règles spécifiques en premier, génériques en dernier.
 */
require_once __DIR__ . '/classify.php';
require_once __DIR__ . '/chemins.php';

// --- Main ---
$path = selfact_chemin_catalogue();
$raw = file_get_contents($path);
$data = json_decode($raw, true);

if (!is_array($data) || !isset($data['models'])) {
    fwrite(STDERR, "catalog.json manquant ou invalide\n");
    exit(1);
}

$before = [];
$after = [];
foreach ($data['models'] as $i => $m) {
    $oldCat = $m['category'] ?? 'inconnu';
    $newCat = selfact_classify($m['label']);
    $before[$oldCat] = ($before[$oldCat] ?? 0) + 1;
    $after[$newCat] = ($after[$newCat] ?? 0) + 1;
    $data['models'][$i]['category'] = $newCat;
}

$data['_meta']['categories'] = $after;
$data['_meta']['last_sync']  = date('c');
$data['_meta']['classifier_version'] = 'v2-reclassify';

echo "=== Avant (ancienne heuristique) ===\n";
arsort($before);
foreach ($before as $c => $n) { echo str_pad($c, 18) . ": $n\n"; }

echo "\n=== Après (classifier v2) ===\n";
arsort($after);
foreach ($after as $c => $n) { echo str_pad($c, 18) . ": $n\n"; }

$improvement = ($before['divers'] ?? 0) - ($after['divers'] ?? 0);
echo "\nRéduction 'divers' : $improvement modèles réassignés\n";

if ($dryRun) {
    echo "\n[dry-run : fichier non écrit]\n";
    exit(0);
}

file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "\n✓ catalog.json réécrit avec classification v2\n";
