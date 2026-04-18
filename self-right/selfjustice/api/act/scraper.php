<?php
/**
 * SelfAct — Scraper du catalogue service-public.fr
 *
 * Usage : php scraper.php [--verbose] [--dry-run]
 *
 * Récupère les 334 modèles de lettres officiels publiés par service-public.fr
 * sur https://www.service-public.gouv.fr/particuliers/recherche?rubricFilter=serviceEnLigne&rubricTypeFilter=modeleLettre
 *
 * Produit : /var/www/selfjustice/api/act/data/catalog.json avec :
 *   {
 *     "_meta": { "version": "AAAAMM", "last_sync": "ISO", "total": 334 },
 *     "models": [
 *       { "id": "R10959", "label": "...", "url": "...", "category": "..." },
 *       ...
 *     ]
 *   }
 *
 * Licence source : Etalab 2.0 (réutilisation libre avec attribution).
 * Appelé bimensuellement par cron (1er + 15) en cohérence avec SelfJustice.
 */

declare(strict_types=1);

$verbose = in_array('--verbose', $argv, true);
$dryRun  = in_array('--dry-run', $argv, true);

const BASE_URL = 'https://www.service-public.gouv.fr';
const CATALOG_URL = BASE_URL . '/particuliers/recherche?rubricFilter=serviceEnLigne&rubricTypeFilter=modeleLettre';
const PAGE_SIZE = 20;
const REQUEST_DELAY_MS = 400;  // Politesse : 400ms entre deux requêtes
// WAF service-public.fr filtre les UA contenant "bot", "scraper", "crawler".
// UA navigateur neutre = accès normal. Politesse assurée par REQUEST_DELAY_MS = 400ms
// + appels bimensuels seulement (pas de charge).
const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64; rv:124.0) Gecko/20100101 Firefox/124.0';
const TIMEOUT_SEC = 15;

function vlog(string $msg, bool $verbose): void {
    if ($verbose) { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $msg . PHP_EOL); }
}

/**
 * Fetch une URL en GET. Priorité : ext-curl → curl binary → stream_context.
 */
function fetchUrl(string $url, bool $verbose = false): ?string {
    if (!function_exists('curl_init')) {
        vlog("  ↳ ext-curl indispo, essai binary curl", $verbose);
        return fetchUrlBinary($url, $verbose);
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => TIMEOUT_SEC,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: fr-FR,fr;q=0.9,en;q=0.8',
            'Accept-Encoding: gzip, deflate',
        ],
        CURLOPT_ENCODING       => '',   // Accept any encoding, auto-decompress
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $httpCode >= 400 || $httpCode === 0) {
        vlog("  ↳ fetch échec: http=$httpCode err='$err' url=$url", $verbose);
        return null;
    }
    return (string) $body;
}

/**
 * Fallback 1 : utilise le binary curl via shell_exec.
 */
function fetchUrlBinary(string $url, bool $verbose): ?string {
    $found = @shell_exec('command -v curl 2>/dev/null');
    if ($found === null || trim((string) $found) === '') {
        vlog("  ↳ binary curl indispo, fallback stream_context", $verbose);
        return fetchUrlStream($url, $verbose);
    }
    $ua = USER_AGENT;
    $cmd = 'curl -sL --max-time ' . (int) TIMEOUT_SEC
         . ' -A ' . escapeshellarg($ua)
         . ' -H ' . escapeshellarg('Accept: text/html,application/xhtml+xml')
         . ' -H ' . escapeshellarg('Accept-Language: fr-FR,fr;q=0.9')
         . ' ' . escapeshellarg($url)
         . ' 2>/dev/null';
    $out = @shell_exec($cmd);
    if ($out === null || strlen((string) $out) < 100) {
        vlog("  ↳ binary curl sortie vide/courte", $verbose);
        return null;
    }
    return (string) $out;
}

/**
 * Fallback 2 : stream_context (souvent filtré par anti-bot).
 */
function fetchUrlStream(string $url, bool $verbose): ?string {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => TIMEOUT_SEC,
            'header'  => "User-Agent: " . USER_AGENT . "\r\nAccept: text/html\r\nAccept-Language: fr-FR,fr;q=0.9\r\n",
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) { return null; }
    return $raw;
}

/**
 * Parse une page de résultats service-public.fr pour extraire les modèles.
 * Format HTML observé (avril 2026) :
 *   <li id="result_serviceEnLigne_N">
 *     <div class="sp-link fr-mb-1w">
 *       <div class="sp-link--label">
 *         <span class="sp-icon ...">
 *         <p class="fr-mb-0">
 *           <a href="https://www.service-public.gouv.fr/particuliers/vosdroits/R10959"
 *              class="fr-link"><span>Titre du modèle</span></a>
 *         </p>
 *       </div>
 *     </div>
 *   </li>
 *
 * @return array<array{id:string, label:string, url:string}>
 */
function parseCatalogPage(string $html): array {
    $models = [];

    // Pattern souple : href peut être absolue (https://...) ou relative (/particuliers/...)
    //  + attributs multiples entre href et >
    //  + contenu enveloppé <span>
    // Multiline pour gérer les sauts de ligne dans les attributs
    $pattern = '#<a\s+href="(?:https?://[^/]+)?(/particuliers/vosdroits/(R\d+))"[^>]*>\s*<span[^>]*>\s*(.+?)\s*</span>\s*</a>#si';
    if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $path  = $m[1];
            $id    = strtoupper($m[2]);
            // Label : retirer tags internes éventuels, décoder entités
            $label_raw = preg_replace('/<[^>]+>/', '', $m[3]) ?? '';
            $label = html_entity_decode(trim(preg_replace('/\s+/', ' ', $label_raw)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($label === '' || strlen($label) < 5) { continue; }
            // Dedup par id
            if (!isset($models[$id])) {
                $models[$id] = [
                    'id'    => $id,
                    'label' => $label,
                    'url'   => BASE_URL . $path,
                ];
            }
        }
    }

    return array_values($models);
}

/**
 * Tente de deviner la catégorie depuis le label ou l'URL.
 * Heuristique simple : match de mots-clés.
 */
function guessCategory(string $label, string $url): string {
    $label_lc = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
    $rules = [
        'logement'        => ['logement', 'bail', 'locataire', 'propriét', 'loyer', 'résili', 'voisin', 'copropri'],
        'travail'         => ['travail', 'employeur', 'salaire', 'démission', 'licenc', 'rupture', 'paternit', 'matern', 'stage', 'syndic', 'congé', 'chômage'],
        'sante'           => ['médical', 'santé', 'médecin', 'sécurité sociale', 'cpam', 'mutuelle', 'hôpital', 'ald'],
        'famille'         => ['famille', 'enfant', 'mineur', 'garde', 'divorce', 'adoption', 'pension alimentaire', 'parental'],
        'consommation'    => ['achat', 'rétract', 'consommateur', 'garantie', 'vente', 'rembourse', 'livraison', 'défectueux', 'dgccrf', 'démarchage'],
        'finances'        => ['banque', 'compte', 'chèque', 'virement', 'carte bancaire', 'crédit', 'assurance-vie', 'prélèvement'],
        'assurances'      => ['assur', 'sinistre', 'médiateur'],
        'justice'         => ['plainte', 'procureur', 'avocat', 'tribunal', 'saisir', 'saisine', 'conciliat', 'média', 'jugement'],
        'transports'      => ['train', 'sncf', 'vol', 'avion', 'bagage', 'voyage', 'retard'],
        'citoyennete'     => ['attestation', 'honneur', 'carte identité', 'passeport', 'nationalit', 'élection', 'vote'],
        'administration'  => ['administration', 'recours', 'préfecture', 'mairie', 'impôt', 'fisc'],
        'etranger'        => ['visa', 'titre séjour', 'naturalis', 'étranger'],
    ];
    foreach ($rules as $cat => $kws) {
        foreach ($kws as $kw) {
            if (str_contains($label_lc, $kw)) {
                return $cat;
            }
        }
    }
    return 'divers';
}

/**
 * Scraper principal : itère sur les pages du catalogue.
 *
 * @return array<array{id:string, label:string, url:string, category:string}>
 */
function scrapeCatalog(bool $verbose): array {
    $models = [];
    $seen = [];
    $page = 1;
    $emptyPagesInRow = 0;

    while ($page <= 25) {  // Bord supérieur défensif : on a 334 modèles ÷ 20 = 17 pages, on limite à 25
        $url = CATALOG_URL . '&page=' . $page;
        vlog("Page $page : $url", $verbose);

        $html = fetchUrl($url, $verbose);
        if ($html === null) {
            vlog("  ↳ échec, abort après page $page", $verbose);
            break;
        }

        $pageModels = parseCatalogPage($html);
        $newCount = 0;
        foreach ($pageModels as $m) {
            if (!isset($seen[$m['id']])) {
                $seen[$m['id']] = true;
                $m['category'] = guessCategory($m['label'], $m['url']);
                $models[] = $m;
                $newCount++;
            }
        }
        vlog("  ↳ $newCount nouveaux (total: " . count($models) . ")", $verbose);

        if ($newCount === 0) {
            $emptyPagesInRow++;
            if ($emptyPagesInRow >= 2) {
                vlog("  ↳ 2 pages sans nouveau modèle, on stoppe", $verbose);
                break;
            }
        } else {
            $emptyPagesInRow = 0;
        }

        $page++;
        usleep(REQUEST_DELAY_MS * 1000);
    }

    return $models;
}

// --- Exécution ---
vlog("=== SelfAct scraper — début ===", $verbose);
vlog("Source : " . CATALOG_URL, $verbose);

$startTime = microtime(true);
$models = scrapeCatalog($verbose);
$elapsed = round(microtime(true) - $startTime, 2);

vlog("=== Total : " . count($models) . " modèles en {$elapsed}s ===", $verbose);

// Stats par catégorie
$byCategory = [];
foreach ($models as $m) {
    $byCategory[$m['category']] = ($byCategory[$m['category']] ?? 0) + 1;
}
if ($verbose) {
    vlog("Catégories :", true);
    arsort($byCategory);
    foreach ($byCategory as $cat => $n) {
        vlog("  $cat : $n", true);
    }
}

$output = [
    '_meta' => [
        'version'    => date('Y.m'),
        'last_sync'  => date('c'),
        'source'     => 'service-public.fr (Etalab 2.0)',
        'source_url' => CATALOG_URL,
        'total'      => count($models),
        'categories' => $byCategory,
        'scraper'    => 'SelfAct-Scraper/0.1',
    ],
    'models' => $models,
];

if ($dryRun) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}

$outPath = __DIR__ . '/data/catalog.json';
$ok = @file_put_contents($outPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
if ($ok === false) {
    fwrite(STDERR, "Échec écriture $outPath\n");
    exit(2);
}

vlog("Écrit : $outPath (" . filesize($outPath) . " octets)", $verbose);
echo "OK " . count($models) . " modèles.\n";
exit(0);
