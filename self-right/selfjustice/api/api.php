<?php
/**
 * SelfJustice — API de consultation légale (lecture seule).
 *
 * Donne accès aux bases juridiques locales :
 *   - LEGI (droit français officiel, 488k+ articles)
 *   - Conventionnalité (UE + CEDH, 705 articles)
 *
 * Zéro stockage, zéro tracking, zéro authentification.
 * Uniquement de la lecture en SQLite.
 *
 * Endpoints :
 *   GET /api/legi/article/{ref}
 *   GET /api/legi/search?q={query}&limit={n}
 *   GET /api/eu/article/{source}/{num}
 *   GET /api/eu/search?q={query}&source={src}&limit={n}
 *   GET /api/status
 */

declare(strict_types=1);

// Headers — JSON + CORS ouvert pour permettre aux IA de fetch
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

// Config — bases via symlinks dans /var/lib/selfjustice/db/
// (accessible au user www-data de PHP-FPM)
const LEGI_DB = '/var/lib/selfjustice/db/legi_selfjustice.sqlite';
const EU_DB   = '/var/lib/selfjustice/db/conventionnalite.sqlite';

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function json_error(string $message, int $status = 400): void {
    json_response(['error' => $message], $status);
}

function open_db(string $path): SQLite3 {
    if (!file_exists($path)) {
        json_error("Base introuvable : " . basename($path), 503);
    }
    try {
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);
        $db->busyTimeout(1000);
        return $db;
    } catch (Exception $e) {
        json_error("Erreur d'ouverture de la base", 503);
    }
}

/**
 * L'index plein texte est-il présent dans cette base ?
 *
 * Il est construit par la synchronisation ; une base issue d'une version
 * antérieure du script n'en a pas. La recherche par numéro continue alors de
 * répondre seule, au lieu de rendre une erreur.
 */
function legi_fts_disponible(SQLite3 $db): bool {
    static $present = null;
    if ($present === null) {
        $r = $db->querySingle(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name='articles_fts'"
        );
        $present = ($r == 1);
    }
    return $present;
}

/**
 * Transforme une saisie libre en requête FTS5 inoffensive.
 *
 * FTS5 a sa propre syntaxe : `-` exclut, `:` désigne une colonne, `*` tronque,
 * `NEAR()` et `OR` sont des opérateurs. Passée telle quelle, une référence
 * aussi banale que « L1152-1 » lève « no such column: 1 » — une saisie
 * légitime rendrait un 500.
 *
 * Chaque terme est donc entouré de guillemets, où plus rien n'a de sens
 * spécial, les guillemets internes étant doublés. Termes séparés = ET
 * implicite : « harcelement moral » trouve les articles portant les deux mots,
 * sans exiger qu'ils soient côte à côte.
 */
function fts5_requete(string $q): string {
    $termes = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $quotes = [];
    foreach ($termes as $terme) {
        $quotes[] = '"' . str_replace('"', '""', $terme) . '"';
    }
    return implode(' ', $quotes);
}

// Router minimaliste
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim(preg_replace('#^/api#', '', $uri), '/');
$segments = array_values(array_filter(explode('/', $uri)));

if (empty($segments)) {
    json_response([
        'name' => 'SelfJustice API',
        'version' => '1.0',
        'description' => 'Consultation légale temps réel — droit français + conventionnalité',
        'endpoints' => [
            'GET /api/legi/article/{ref}?code={alias}' => 'Article français (ex: L1152-1 avec ?code=travail)',
            'GET /api/legi/search?q={query}'           => 'Recherche plein texte LEGI',
            'GET /api/eu/article/{source}/{num}'       => 'Article européen (CEDH, CHARTE_UE, TUE, TFUE, RGPD, AI_ACT)',
            'GET /api/eu/search?q={query}'             => 'Recherche dans conventionnalité',
            'GET /api/status'                          => 'État des bases (nombre articles, last_update)',
            'GET /api/stats/by-ai'                     => 'Statistiques anonymes par famille d\'IA (Claude, OpenAI, etc.)',
            'GET /api/stats/by-endpoint'               => 'Top articles les plus consultés (anonyme, intérêt général)',
        ],
        'sources' => [
            'legi' => 'Légifrance (dump LEGI officiel DILA, MAJ bimensuelle)',
            'eu'   => 'EUR-Lex + echr.coe.int (Charte UE, TUE, TFUE, RGPD, AI Act, CEDH)',
        ],
        'license' => 'AGPL-3.0-or-later',
        'github'  => 'https://github.com/Pierroons/my-self/tree/main/self-right/selfjustice',
    ]);
}

// ============================================================
// /api/stats/by-ai et /api/stats/by-endpoint
// ============================================================
if ($segments[0] === 'stats') {
    if (count($segments) >= 2 && in_array($segments[1], ['by-ai', 'by-endpoint'], true)) {
        $file = $segments[1] === 'by-ai'
            ? '/var/lib/selfjustice/stats/by-ai.json'
            : '/var/lib/selfjustice/stats/by-endpoint.json';
        if (!file_exists($file)) {
            json_error("Statistiques non encore générées (cron horaire).", 503);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo file_get_contents($file);
        exit;
    }
    json_error("Endpoint stats inconnu. Disponibles : /api/stats/by-ai, /api/stats/by-endpoint", 404);
}

// ============================================================
// /api/status
// ============================================================
if ($segments[0] === 'status') {
    $result = ['legi' => null, 'eu' => null];

    try {
        $db = open_db(LEGI_DB);
        $result['legi'] = [
            'articles' => (int) $db->querySingle('SELECT COUNT(*) FROM articles'),
            'vigueur'  => (int) $db->querySingle("SELECT COUNT(*) FROM articles WHERE etat='VIGUEUR'"),
            'last_update_file' => '/var/lib/selfjustice/legi_last_update.txt',
        ];
        $file = '/var/lib/selfjustice/legi_last_update.txt';
        $result['legi']['last_update'] = file_exists($file) ? trim(file_get_contents($file)) : null;
        $db->close();
    } catch (Exception $e) {}

    try {
        $db = open_db(EU_DB);
        $sources = [];
        $stmt = $db->query('SELECT source, COUNT(*) as n FROM articles GROUP BY source');
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
            $sources[$row['source']] = (int) $row['n'];
        }
        $result['eu'] = [
            'articles' => (int) $db->querySingle('SELECT COUNT(*) FROM articles'),
            'sources'  => $sources,
        ];
        $file = '/var/lib/selfjustice/eu_last_update.txt';
        $result['eu']['last_update'] = file_exists($file) ? trim(file_get_contents($file)) : null;
        $db->close();
    } catch (Exception $e) {}

    json_response($result);
}

// ============================================================
// /api/legi/...
// ============================================================
if ($segments[0] === 'legi') {
    $db = open_db(LEGI_DB);

    // /api/legi/article/{ref}
    if (count($segments) >= 3 && $segments[1] === 'article') {
        $ref = $segments[2];
        if (!preg_match('/^[A-Z]?[0-9][0-9A-Za-z\-]*$/', $ref)) {
            json_error("Référence d'article invalide");
        }

        // Vérifier si la colonne texte existe (nouveau schéma)
        $has_texte = false;
        $check = $db->query("PRAGMA table_info(articles)");
        while ($col = $check->fetchArray(SQLITE3_ASSOC)) {
            if ($col['name'] === 'texte') { $has_texte = true; break; }
        }
        $columns = $has_texte
            ? "id, num, etat, date_debut, date_fin, code_id, texte"
            : "id, num, etat, date_debut, date_fin, code_id";

        // Filtre optionnel par code (code_id ou nom clair)
        // Ex : ?code=LEGITEXT000006072050 ou ?code=travail
        $code_filter = $_GET['code'] ?? null;
        $CODE_ALIASES = [
            'travail'              => 'LEGITEXT000006072050',
            'code_du_travail'      => 'LEGITEXT000006072050',
            'civil'                => 'LEGITEXT000006070721',
            'code_civil'           => 'LEGITEXT000006070721',
            'penal'                => 'LEGITEXT000006070719',
            'code_penal'           => 'LEGITEXT000006070719',
            'consommation'         => 'LEGITEXT000006069565',
            'sante_publique'       => 'LEGITEXT000006072665',
            'assurances'           => 'LEGITEXT000006073984',
            'urbanisme'            => 'LEGITEXT000006074075',
            'construction'         => 'LEGITEXT000006074096',
            'route'                => 'LEGITEXT000006074228',
            'environnement'        => 'LEGITEXT000006074220',
            'education'            => 'LEGITEXT000006071191',
            'securite_sociale'     => 'LEGITEXT000006073189',
            'rural'                => 'LEGITEXT000006071367',
            'propriete_intellectuelle' => 'LEGITEXT000006069414',
            'procedure_civile'     => 'LEGITEXT000006070716',
            'procedure_penale'     => 'LEGITEXT000006071154',
        ];

        if ($code_filter && isset($CODE_ALIASES[strtolower($code_filter)])) {
            $code_filter = $CODE_ALIASES[strtolower($code_filter)];
        }

        // Chercher d'abord la version en VIGUEUR
        $sql = "SELECT $columns FROM articles WHERE num = :ref AND etat = 'VIGUEUR'";
        if ($code_filter) $sql .= " AND code_id = :code";
        $sql .= " ORDER BY date_debut DESC LIMIT 1";

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':ref', $ref);
        if ($code_filter) $stmt->bindValue(':code', $code_filter);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$row) {
            // Fallback : toutes les versions
            $sql2 = "SELECT $columns FROM articles WHERE num = :ref";
            if ($code_filter) $sql2 .= " AND code_id = :code";
            $sql2 .= " ORDER BY date_debut DESC LIMIT 1";
            $stmt = $db->prepare($sql2);
            $stmt->bindValue(':ref', $ref);
            if ($code_filter) $stmt->bindValue(':code', $code_filter);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        }

        if (!$row) {
            json_error("Article introuvable : $ref" . ($code_filter ? " (code $code_filter)" : ''), 404);
        }

        // Si pas de filtre de code ET plusieurs codes existent pour ce num, lister tous
        if (!$code_filter) {
            $stmt2 = $db->prepare("SELECT DISTINCT code_id FROM articles WHERE num = :ref AND etat = 'VIGUEUR'");
            $stmt2->bindValue(':ref', $ref);
            $codes_found = [];
            $r2 = $stmt2->execute();
            while ($c = $r2->fetchArray(SQLITE3_ASSOC)) {
                $codes_found[] = $c['code_id'];
            }
            if (count($codes_found) > 1) {
                // Article ambigu : plusieurs codes possibles — retourner la liste
                $alternatives = [];
                foreach ($codes_found as $cid) {
                    $alt_stmt = $db->prepare("SELECT SUBSTR(texte, 1, 150) as apercu FROM articles WHERE num = :ref AND code_id = :cid AND etat = 'VIGUEUR' LIMIT 1");
                    $alt_stmt->bindValue(':ref', $ref);
                    $alt_stmt->bindValue(':cid', $cid);
                    $alt_row = $alt_stmt->execute()->fetchArray(SQLITE3_ASSOC);
                    $alternatives[] = [
                        'code_id' => $cid,
                        'apercu'  => $alt_row['apercu'] ?? '',
                        'url'     => (getenv('SELFJUSTICE_BASE_URL') ?: 'https://' . ($_SERVER['HTTP_HOST'] ?? 'your-instance.example')) . "/api/legi/article/$ref?code=$cid",
                    ];
                }
                json_response([
                    'reference' => $ref,
                    'ambiguous' => true,
                    'message'   => "L'article $ref existe dans plusieurs codes. Précisez le code via ?code=XXX (alias : travail, civil, penal, consommation, sante_publique, assurances, urbanisme, etc.).",
                    'alternatives' => $alternatives,
                ]);
            }
        }

        // Lookup du titre de code (depuis la table articles elle-même)
        $code_titre = $db->querySingle(
            sprintf("SELECT code_titre FROM articles WHERE code_id = '%s' AND code_titre != '' LIMIT 1",
                SQLite3::escapeString($row['code_id'])
            )
        ) ?: null;

        $result = [
            'reference'  => $row['num'],
            'etat'       => $row['etat'],
            'en_vigueur' => $row['etat'] === 'VIGUEUR',
            'date_debut' => $row['date_debut'],
            'date_fin'   => $row['date_fin'],
            'code_id'    => $row['code_id'],
            'code_titre' => $code_titre,
            'texte'      => $has_texte ? ($row['texte'] ?? null) : null,
            'source'     => [
                'base'         => 'LEGI',
                'origine'      => 'Légifrance — dump officiel DILA',
                'last_update'  => (function() { $c = @file_get_contents('/var/lib/selfjustice/legi_last_update.txt'); return $c ? trim($c) : null; })(),
                'legifrance_url' => sprintf(
                    'https://www.legifrance.gouv.fr/codes/article_lc/%s',
                    substr($row['id'], 0, 30)
                ),
            ],
        ];

        json_response($result);
    }

    // /api/legi/search?q=...
    if (count($segments) >= 2 && $segments[1] === 'search') {
        $q = trim($_GET['q'] ?? '');
        $limit = min(max((int)($_GET['limit'] ?? 20), 1), 100);

        if (strlen($q) < 3) {
            json_error("Requête trop courte (min 3 caractères)");
        }

        // Deux recherches, dans cet ordre : par numéro puis dans le texte.
        //
        // Cet endpoint ne regardait que la colonne `num`, alors qu'il s'annonce
        // « plein texte ». Une requête ordinaire — « licenciement », « chanvre »
        // — rendait donc zéro sur 159 615 articles en vigueur, et le client en
        // concluait que le droit était muet sur la question. Une absence
        // affirmée sans avoir été vérifiée : la même famille d'erreur qu'un 404
        // d'infrastructure lu comme « article introuvable ».
        //
        // Le numéro passe en premier parce qu'il est sans ambiguïté : qui tape
        // « 1240 » veut l'article 1240, pas les vingt articles qui le citent.
        $results = [];
        $vus = [];

        $pattern = '%' . SQLite3::escapeString($q) . '%';
        $stmt = $db->prepare("SELECT num, etat, code_id, date_debut
                              FROM articles
                              WHERE num LIKE :pattern
                                AND etat = 'VIGUEUR'
                              ORDER BY num
                              LIMIT :limit");
        $stmt->bindValue(':pattern', $pattern);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);

        $res = $stmt->execute();
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $cle = $row['num'] . '|' . $row['code_id'];
            $vus[$cle] = true;
            $results[] = [
                'reference'  => $row['num'],
                'etat'       => $row['etat'],
                'code_id'    => $row['code_id'],
                'date_debut' => $row['date_debut'],
                'match'      => 'numero',
            ];
        }

        $reste = $limit - count($results);
        if ($reste > 0 && legi_fts_disponible($db)) {
            $stmt = $db->prepare(
                "SELECT a.num, a.etat, a.code_id, a.date_debut, a.code_titre,
                        snippet(articles_fts, 0, '', '', '…', 12) AS extrait
                 FROM articles_fts f
                 JOIN articles a ON a.rowid = f.rowid
                 WHERE f.articles_fts MATCH :q
                 ORDER BY bm25(articles_fts, 10.0, 1.0, 1.0)
                 LIMIT :limit"
            );
            // Chaque terme est mis entre guillemets et les guillemets internes
            // doublés : sans cela, un tiret, un deux-points ou une parenthèse
            // dans la requête est lu comme un opérateur FTS5 et la requête
            // échoue — « L1152-1 » rendait « no such column: 1 », soit un 500
            // sur une saisie parfaitement légitime.
            $stmt->bindValue(':q', fts5_requete($q));
            $stmt->bindValue(':limit', $reste + count($results), SQLITE3_INTEGER);

            $res = $stmt->execute();
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $cle = $row['num'] . '|' . $row['code_id'];
                if (isset($vus[$cle])) {
                    continue;
                }
                $vus[$cle] = true;
                $results[] = [
                    'reference'  => $row['num'],
                    'etat'       => $row['etat'],
                    'code_id'    => $row['code_id'],
                    'date_debut' => $row['date_debut'],
                    'code'       => $row['code_titre'],
                    'extrait'    => trim($row['extrait']),
                    'match'      => 'texte',
                ];
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        json_response([
            'query'   => $q,
            'count'   => count($results),
            'limit'   => $limit,
            'results' => $results,
        ]);
    }

    json_error("Endpoint LEGI inconnu", 404);
}

// ============================================================
// /api/eu/...
// ============================================================
if ($segments[0] === 'eu') {
    $db = open_db(EU_DB);

    // /api/eu/article/{source}/{num}
    if (count($segments) >= 4 && $segments[1] === 'article') {
        $source = strtoupper($segments[2]);
        $num = $segments[3];

        $allowed = ['CEDH', 'CHARTE_UE', 'TUE', 'TFUE', 'RGPD', 'AI_ACT'];
        if (!in_array($source, $allowed, true)) {
            json_error("Source invalide. Sources autorisées : " . implode(', ', $allowed));
        }

        $stmt = $db->prepare("SELECT id, source, num, titre, texte, etat, date_debut, url_source
                              FROM articles
                              WHERE source = :source AND num = :num
                              LIMIT 1");
        $stmt->bindValue(':source', $source);
        $stmt->bindValue(':num', $num);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$row) {
            // Essayer aussi avec préfixe P (pour les protocoles CEDH)
            $stmt = $db->prepare("SELECT id, source, num, titre, texte, etat, date_debut, url_source
                                  FROM articles
                                  WHERE source = :source AND id = :id
                                  LIMIT 1");
            $stmt->bindValue(':source', $source);
            $stmt->bindValue(':id', $source . '-' . $num);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        }

        if (!$row) {
            json_error("Article introuvable : $source art. $num", 404);
        }

        json_response([
            'source'     => $row['source'],
            'reference'  => $row['num'],
            'titre'      => $row['titre'],
            'texte'      => $row['texte'],
            'etat'       => $row['etat'],
            'date_debut' => $row['date_debut'],
            'url_source' => $row['url_source'],
            'meta'       => [
                'base'        => 'Conventionnalité',
                'origine'     => $row['source'] === 'CEDH' ? 'echr.coe.int' : 'EUR-Lex',
                'last_update' => (function() { $c = @file_get_contents('/var/lib/selfjustice/eu_last_update.txt'); return $c ? trim($c) : null; })(),
            ],
        ]);
    }

    // /api/eu/search?q=...&source=CEDH
    if (count($segments) >= 2 && $segments[1] === 'search') {
        $q = trim($_GET['q'] ?? '');
        $source = isset($_GET['source']) ? strtoupper($_GET['source']) : null;
        $limit = min(max((int)($_GET['limit'] ?? 20), 1), 100);

        if (strlen($q) < 3) {
            json_error("Requête trop courte (min 3 caractères)");
        }

        $sql = "SELECT source, num, titre, SUBSTR(texte, 1, 200) as apercu, date_debut
                FROM articles
                WHERE (titre LIKE :pattern OR texte LIKE :pattern OR num LIKE :numPattern)";
        $pattern = '%' . SQLite3::escapeString($q) . '%';
        $numPattern = SQLite3::escapeString($q) . '%';

        if ($source) {
            $allowed = ['CEDH', 'CHARTE_UE', 'TUE', 'TFUE', 'RGPD', 'AI_ACT'];
            if (!in_array($source, $allowed, true)) {
                json_error("Source invalide");
            }
            $sql .= " AND source = :source";
        }
        $sql .= " LIMIT :limit";

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':pattern', $pattern);
        $stmt->bindValue(':numPattern', $numPattern);
        if ($source) $stmt->bindValue(':source', $source);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);

        $results = [];
        $res = $stmt->execute();
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $results[] = [
                'source'    => $row['source'],
                'reference' => $row['num'],
                'titre'     => $row['titre'],
                'apercu'    => $row['apercu'],
            ];
        }

        json_response([
            'query'   => $q,
            'source'  => $source,
            'count'   => count($results),
            'limit'   => $limit,
            'results' => $results,
        ]);
    }

    json_error("Endpoint EU inconnu", 404);
}

json_error("Endpoint inconnu : /" . implode('/', $segments), 404);
