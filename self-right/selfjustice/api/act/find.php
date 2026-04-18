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
 * Source : api/act/data/situations.json (mise à jour bimensuelle via cron).
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
    'meta'        => $meta,
    'fallback'    => [
        'if_no_official_match' => 'Use /act/api/draft to produce an HTML draft with watermark "NON OFFICIEL — IRRECEVABLE" for printing as PDF',
        'draft_url'            => 'https://justice.my-self.fr/act/api/draft',
    ],
]);
