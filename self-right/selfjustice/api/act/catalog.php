<?php
/**
 * SelfAct API — /act/api/catalog
 *
 * Expose le catalogue indexé des ressources officielles service-public.gouv.fr :
 * modèles de lettres, formulaires (CERFA) et démarches en ligne.
 *
 * GET /act/api/catalog
 *   → Retourne le catalogue complet (meta + models)
 *
 * GET /act/api/catalog?category=<cat>
 *   → Filtrer par catégorie : logement, travail, sante, famille,
 *     consommation, finances, assurances, justice, transports,
 *     citoyennete, administration, association, etranger, auto,
 *     securite, divers
 *
 * GET /act/api/catalog?type=<type>
 *   → Filtrer par nature du document : modele_lettre, formulaire, teleservice.
 *     C'est ce filtre qui distingue « la lettre que je rédige » du « CERFA que
 *     je dépose » — une distinction qui porte la procédure, pas seulement la
 *     présentation.
 *
 * GET /act/api/catalog?q=<keyword>
 *   → Recherche full-text dans les labels (case insensitive, accent insensitive)
 *
 * GET /act/api/catalog?id=<RXXXX>
 *   → Détail d'un modèle précis par sa référence R-xxxx
 *
 * Combinaisons possibles : category + type + q
 *
 * Source : api/act/data/catalog.json (mise à jour bimensuelle via cron).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');

function respond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function normalize(string $s): string {
    // Minuscules + retrait des accents pour matching
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $trans = [
        'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','å'=>'a',
        'ç'=>'c','č'=>'c',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ñ'=>'n',
        'ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
        'ý'=>'y','ÿ'=>'y',
    ];
    return strtr($s, $trans);
}

$dataPath = __DIR__ . '/data/catalog.json';
if (!is_file($dataPath)) {
    respond(503, [
        'ok'    => false,
        'error' => 'catalog_not_yet_synced',
        'hint'  => 'Run cron/update_catalog.sh to populate the catalog. See the SelfAct documentation.',
    ]);
}

$raw = @file_get_contents($dataPath);
$json = $raw !== false ? json_decode($raw, true) : null;
if (!is_array($json) || !isset($json['models'])) {
    respond(500, ['ok' => false, 'error' => 'catalog_malformed']);
}

$meta   = $json['_meta'] ?? [];
$models = $json['models'] ?? [];

// --- Filtre par ID ---
if (!empty($_GET['id'])) {
    $id = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $_GET['id']));
    foreach ($models as $m) {
        if (strtoupper($m['id']) === $id) {
            respond(200, ['ok' => true, 'model' => $m, 'meta' => $meta]);
        }
    }
    respond(404, ['ok' => false, 'error' => 'model_not_found', 'id' => $id]);
}

// --- Filtre catégorie + recherche ---
$category = trim((string) ($_GET['category'] ?? ''));
$type     = trim((string) ($_GET['type'] ?? ''));
$query    = trim((string) ($_GET['q'] ?? ''));
$queryNorm = $query !== '' ? normalize($query) : '';

// Un type inconnu rendrait une liste vide, indiscernable d'un « rien ne
// correspond » légitime. Mieux vaut nommer les valeurs acceptées.
$typesConnus = ['modele_lettre', 'formulaire', 'teleservice'];
if ($type !== '' && !in_array($type, $typesConnus, true)) {
    respond(400, [
        'ok'    => false,
        'error' => 'unknown_type',
        'hint'  => 'Types acceptés : ' . implode(', ', $typesConnus),
    ]);
}

$filtered = [];
foreach ($models as $m) {
    if ($category !== '' && ($m['category'] ?? '') !== $category) {
        continue;
    }
    if ($type !== '' && ($m['type'] ?? '') !== $type) {
        continue;
    }
    if ($queryNorm !== '') {
        if (!str_contains(normalize($m['label'] ?? ''), $queryNorm)) {
            continue;
        }
    }
    $filtered[] = $m;
}

respond(200, [
    'ok'       => true,
    'meta'     => $meta,
    'filters'  => [
        'category' => $category ?: null,
        'type'     => $type ?: null,
        'query'    => $query ?: null,
    ],
    'total'    => count($filtered),
    'models'   => $filtered,
]);
