<?php
/**
 * SelfAct API — /act/api/find
 *
 * GET /act/api/find?situation=<slug>
 *   → Renvoie les actes recommandés pour une situation donnée, avec priorité
 *     absolue aux modèles officiels service-public.fr. Si plusieurs options,
 *     elles sont classées par pertinence (officiel > simulateur > info_only).
 *
 * GET /act/api/find?list=1
 *   → Renvoie la liste des situations disponibles (slugs + labels) pour que
 *     une IA puisse proposer à l'utilisateur les catégories à considérer.
 *
 * ## Deux sources, deux niveaux de confiance
 *
 * `acts` vient de situations.json : douze situations curées à la main, avec
 * statut juridique, seuils et articles applicables. C'est vérifié, c'est peu
 * nombreux, et ça prime.
 *
 * `catalog_suggestions` vient de catalog.json : près de deux mille ressources
 * officielles synchronisées automatiquement, rapprochées de la situation par
 * mots-clés. C'est large et non vérifié pièce à pièce.
 *
 * 🔑 **Les deux ne sont pas mélangées, et c'est le point.** Le catalogue était
 * jusqu'ici invisible depuis cet endpoint : douze situations donnaient accès à
 * une trentaine d'actes, et les 1 895 entrées indexées ne servaient à rien.
 * Les fondre dans une liste unique aurait résolu la visibilité en détruisant
 * l'information qui compte — savoir ce qui a été relu par un humain.
 *
 * Sources : api/data/situations.json (curation manuelle, pas de cron)
 *           le catalogue synchronisé les 1er et 15 — hors de l'arbre du code,
 *           voir chemins.php
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

$dataPath = __DIR__ . '/data/situations.json';
if (!is_file($dataPath)) {
    respond(500, ['ok' => false, 'error' => 'data_unavailable']);
}

$raw = @file_get_contents($dataPath);
$json = $raw !== false ? json_decode($raw, true) : null;
if (!is_array($json) || !isset($json['situations'])) {
    respond(500, ['ok' => false, 'error' => 'data_malformed']);
}

$meta = $json['_meta'] ?? [];
$situations = $json['situations'];

// Mode liste : retourne toutes les situations disponibles
if (!empty($_GET['list'])) {
    $out = [];
    foreach ($situations as $slug => $entry) {
        $out[] = [
            'slug'        => $slug,
            'label'       => $entry['label'] ?? $slug,
            'urgency'     => $entry['urgency'] ?? 'normal',
            'acts_count'  => count($entry['acts'] ?? []),
        ];
    }
    respond(200, [
        'ok'         => true,
        'meta'       => $meta,
        'situations' => $out,
    ]);
}

// Mode détail : retourne les actes pour un slug donné
$slug = trim((string) ($_GET['situation'] ?? ''));
if ($slug === '') {
    respond(400, [
        'ok'    => false,
        'error' => 'missing_situation',
        'hint'  => 'Use ?situation=<slug> or ?list=1 to get available slugs',
    ]);
}

if (!isset($situations[$slug])) {
    respond(404, [
        'ok'    => false,
        'error' => 'situation_not_found',
        'slug'  => $slug,
        'hint'  => 'Call ?list=1 for available slugs',
    ]);
}

$entry = $situations[$slug];
$acts = $entry['acts'] ?? [];

/**
 * Rapproche la situation du catalogue synchronisé.
 *
 * Le score compte les mots-clés trouvés dans l'intitulé ; l'appartenance à une
 * catégorie attendue ne vaut qu'un demi-point, parce qu'une catégorie est large
 * et qu'une entrée qui ne doit sa présence qu'à elle est rarement pertinente.
 *
 * ⚠️ Ce rapprochement lit un intitulé, rien de plus. Il ne vérifie ni que la
 * ressource s'applique au cas, ni qu'elle est la bonne voie procédurale — d'où
 * le champ `confidence` rendu au client, et la séparation d'avec `acts`.
 */
/**
 * Les métadonnées du catalogue synchronisé, pour que le client puisse dater les
 * suggestions autrement que par la curation manuelle qui les a demandées.
 */
function selfact_meta_catalogue(): array {
    $path = selfact_chemin_catalogue();
    if (!is_file($path)) { return []; }
    $cat = json_decode((string) @file_get_contents($path), true);
    return is_array($cat) && isset($cat['_meta']) && is_array($cat['_meta'])
        ? $cat['_meta']
        : [];
}

function suggestFromCatalog(array $hints, int $limite = 12): array {
    $path = selfact_chemin_catalogue();
    if (!is_file($path)) { return []; }
    $cat = json_decode((string) @file_get_contents($path), true);
    if (!is_array($cat) || !isset($cat['models'])) { return []; }

    $motsCles   = array_map('selfact_normalize', $hints['keywords'] ?? []);
    $categories = $hints['categories'] ?? [];
    if (!$motsCles && !$categories) { return []; }

    $scored = [];
    foreach ($cat['models'] as $m) {
        $label = selfact_normalize($m['label'] ?? '');
        $score = 0.0;
        $touches = [];
        foreach ($motsCles as $kw) {
            if ($kw !== '' && str_contains($label, $kw)) {
                $score += 1.0;
                $touches[] = $kw;
            }
        }
        if ($categories && in_array($m['category'] ?? '', $categories, true)) {
            $score += 0.5;
        }
        // Un demi-point seul = la seule catégorie a parlé : trop faible.
        if ($score < 1.0) { continue; }
        $scored[] = [
            'id'       => $m['id'] ?? '',
            'label'    => $m['label'] ?? '',
            'url'      => $m['url'] ?? '',
            'type'     => $m['type'] ?? '',
            'category' => $m['category'] ?? '',
            'score'    => $score,
            'matched'  => $touches,
        ];
    }

    // Tri par score décroissant ; à score égal, un modèle de lettre est plus
    // directement actionnable qu'un formulaire, lui-même plus qu'un téléservice.
    $rang = ['modele_lettre' => 0, 'formulaire' => 1, 'teleservice' => 2];
    usort($scored, function ($a, $b) use ($rang) {
        if ($a['score'] !== $b['score']) { return $b['score'] <=> $a['score']; }
        return ($rang[$a['type']] ?? 9) <=> ($rang[$b['type']] ?? 9);
    });

    return array_slice($scored, 0, $limite);
}

require_once __DIR__ . '/classify.php';   // pour selfact_normalize()
require_once __DIR__ . '/chemins.php';    // pour selfact_chemin_catalogue()
$suggestions = suggestFromCatalog($entry['catalog_hints'] ?? []);

// Tri par priorité : official > simulator > info_only > lawyer_required > emergency_phone en premier
$priority = [
    'emergency_phone'  => 0,
    'official'         => 1,
    'simulator'        => 2,
    'info_only'        => 3,
    'lawyer_required'  => 4,
];
usort($acts, function($a, $b) use ($priority) {
    $pa = $priority[$a['status'] ?? 'info_only'] ?? 99;
    $pb = $priority[$b['status'] ?? 'info_only'] ?? 99;
    if (isset($a['priority'])) $pa = (int) $a['priority'] - 10;
    if (isset($b['priority'])) $pb = (int) $b['priority'] - 10;
    return $pa <=> $pb;
});

respond(200, [
    'ok'          => true,
    'slug'        => $slug,
    'label'       => $entry['label'] ?? $slug,
    'urgency'     => $entry['urgency'] ?? 'normal',
    'acts'        => $acts,
    'articles'    => $entry['art_applicable'] ?? null,
    'thresholds'  => $entry['thresholds'] ?? null,
    'prescription'=> $entry['prescription'] ?? null,
    'catalog_suggestions' => [
        'confidence' => 'heuristique',
        'note'       => "Rapprochées du catalogue officiel par mots-clés, sans "
                      . "vérification pièce à pièce. À lire comme des pistes : "
                      . "les entrées de « acts » sont, elles, vérifiées à la main "
                      . "et priment en cas de doute.",
        'count'      => count($suggestions),
        'items'      => $suggestions,
    ],
    // 🔑 Cette route sert deux jeux de données : le rapprochement curé à la main
    // (`situations.json`, sans cadence) et le catalogue synchronisé
    // (`catalog.json`, les 1er et 15). Ne rendre que le premier masquait tout
    // retard du second, alors que les suggestions en viennent — le client
    // annonçait une curation d'avril sans voir qu'un catalogue de trois semaines
    // avait servi à composer sa réponse.
    'meta'        => $meta + ['catalogue' => selfact_meta_catalogue()],
    'fallback'    => [
        'if_no_official_match' => 'Use /act/api/draft to produce an HTML draft with watermark "NON OFFICIEL — IRRECEVABLE" for printing as PDF',
        'draft_url'            => (getenv('SELFJUSTICE_BASE_URL') ?: 'https://' . ($_SERVER['HTTP_HOST'] ?? 'your-instance.example')) . '/act/api/draft',
    ],
]);
