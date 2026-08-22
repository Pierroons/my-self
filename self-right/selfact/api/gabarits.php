<?php
/**
 * SelfAct API — /act/api/gabarits
 *
 * Liste les gabarits de courrier, et pour chacun les ressources OFFICIELLES du
 * catalogue qui couvrent la même démarche.
 *
 * 🔑 **Pourquoi cette route existe.** Le pied de chaque gabarit disait « pour un
 * acte officiel, utilise le modèle service-public.fr correspondant » — sans
 * jamais dire lequel, alors que le module en indexe 1 895. Il envoyait chercher
 * ce qu'il avait sous la main.
 *
 * ⚠️ Le rapprochement est curé à la main dans `data/gabarits.json`, jamais
 * deviné. Un rapprochement par mots-clés rend « attestation sur l'honneur »
 * pour « conciliateur » et rien du tout pour « Défenseur des droits » : un
 * renvoi juridique faux coûte plus qu'un renvoi absent.
 *
 * Le fichier ne porte que des identifiants. Libellé, URL et type sont résolus au
 * catalogue à chaque appel — recopiés ici, ils vieilliraient sans qu'on le voie.
 * Un identifiant que le catalogue ne connaît plus est signalé sous `inconnus`
 * plutôt que tu, sans quoi une ressource disparue passerait pour absente de la
 * démarche.
 *
 * Usage :
 *   GET /act/api/gabarits            → les sept gabarits
 *   GET /act/api/gabarits?type=plainte_simple → un seul
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function repondre(int $code, array $corps): void {
    http_response_code($code);
    echo json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    repondre(405, ['ok' => false, 'error' => 'methode_non_autorisee']);
}

require_once __DIR__ . '/chemins.php';

$chemin = __DIR__ . '/data/gabarits.json';
$brut   = @file_get_contents($chemin);
$table  = $brut !== false ? json_decode($brut, true) : null;
if (!is_array($table) || !isset($table['gabarits'])) {
    repondre(500, ['ok' => false, 'error' => 'table_gabarits_illisible']);
}

// Le catalogue vit hors de l'arbre du code — voir chemins.php.
$cat = [];
$rawCat = @file_get_contents(selfact_chemin_catalogue());
$jsonCat = $rawCat !== false ? json_decode($rawCat, true) : null;
foreach (($jsonCat['models'] ?? []) as $m) {
    if (isset($m['id'])) { $cat[strtoupper((string) $m['id'])] = $m; }
}

/** Résout les identifiants curés contre le catalogue, et nomme ceux qu'il ignore. */
function resoudre(array $officiels, array $cat): array {
    $resolus = $inconnus = [];
    foreach ($officiels as $o) {
        $id = strtoupper((string) ($o['id'] ?? ''));
        if (!isset($cat[$id])) { $inconnus[] = $id; continue; }
        $m = $cat[$id];
        $resolus[] = [
            'id'    => $m['id'],
            'titre' => $m['label'] ?? '',
            'type'  => $m['type'] ?? '',
            'url'   => $m['url'] ?? '',
            'quand' => $o['quand'] ?? '',
        ];
    }
    return [$resolus, $inconnus];
}

$demande = trim((string) ($_GET['type'] ?? ''));
$sortie  = [];
foreach ($table['gabarits'] as $cle => $g) {
    if ($demande !== '' && $cle !== $demande) { continue; }
    [$resolus, $inconnus] = resoudre($g['officiels'] ?? [], $cat);
    $sortie[$cle] = [
        'label'     => $g['label'] ?? $cle,
        'officiels' => $resolus,
        // Les champs viennent de la table, et la table est confrontée au document
        // réel par le garde-fou : l'outil MCP les écrivait à la main, les mêmes
        // pour les sept, dont un qui n'existait que dans la mise en demeure.
        'champs'    => $g['champs'] ?? [],
        'date_prerenseignee' => (bool) ($g['date_prerenseignee'] ?? false),
        'reserve'   => $g['reserve'] ?? null,
    ];
    if ($inconnus) {
        // 🔑 Un renvoi que le catalogue ne connaît plus doit faire du bruit. Tu
        // le supprimer rendrait « aucune ressource officielle » pour une
        // démarche qui en a, et rien ne le signalerait jamais.
        $sortie[$cle]['inconnus'] = $inconnus;
    }
}

if ($demande !== '' && !$sortie) {
    repondre(404, [
        'ok'       => false,
        'error'    => 'gabarit_inconnu',
        'detail'   => "Gabarit « $demande » inconnu. Rien n'a été produit.",
        'acceptes' => array_keys($table['gabarits']),
    ]);
}

repondre(200, [
    'ok'       => true,
    'gabarits' => $sortie,
    'meta'     => $table['_meta'] ?? [],
    'catalogue_lu' => $cat !== [],
]);
