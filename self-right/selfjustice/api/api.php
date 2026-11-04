<?php
/**
 * SelfJustice — API de consultation légale (lecture seule).
 *
 * Donne accès aux bases juridiques locales :
 *   - LEGI (droit français officiel)
 *   - Conventionnalité (UE + CEDH)
 *   - Judilibre (jurisprudence, métadonnées seules)
 *
 * Volumétrie et dates de synchronisation : GET /api/status.
 *
 * Zéro stockage, zéro tracking, zéro authentification.
 * Uniquement de la lecture en SQLite.
 *
 * Endpoints :
 *   GET /api/legi/article/{ref}
 *   GET /api/legi/search?q={query}&limit={n}
 *   GET /api/eu/article/{source}/{num}
 *   GET /api/eu/search?q={query}&source={src}&limit={n}
 *   GET /api/jurisprudence/verifier/{ref}?jurisdiction={cc|ca|tj|tcom|…}&date={AAAA-MM-JJ}
 *   GET /api/jurisprudence/search?q={query}&champ={summary|text|...}&jurisdiction={cc|ca}
 *   GET /api/jurisprudence/decision/{id}
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
// `define` et non `const` : un chemin surchargeable permet de rejouer le jeu de
// test hors du serveur, sans symlink ni droits root. Les trois bases le sont,
// pour que ce qui les interroge soit également éprouvable.
define('LEGI_DB',  getenv('SELFJUSTICE_LEGI_DB')  ?: '/var/lib/selfjustice/db/legi_selfjustice.sqlite');
define('EU_DB',    getenv('SELFJUSTICE_EU_DB')    ?: '/var/lib/selfjustice/db/conventionnalite.sqlite');
define('JURIS_DB', getenv('SELFJUSTICE_JURIS_DB') ?: '/var/lib/selfjustice/db/judilibre_index.sqlite');
// Ce sur quoi on retombe quand l'index n'est pas lisible — jamais une seconde
// source : la vérité est dans la base, cf. juridictions_servies().
const JURIDICTIONS_SANS_INDEX = ['cc', 'ca'];
// Ce que l'amont Judilibre accepte en filtre, et rien d'autre. Il l'a répondu le
// 10/09/2026 : « Value of the jurisdiction parameter must be in [cc,ca,tj,tcom] ».
// La justice administrative de l'index vient de JADE, pas de lui.
const JURIDICTIONS_AMONT = ['cc', 'ca', 'tj', 'tcom'];
// Ce que le fonds administratif ne porte pas. Deux routes le disent : celle qui
// sert un texte et celle qui juge une absence.
const JADE_SELECTION = "Le fonds JADE ne porte qu'une sélection : les décisions "
    . "publiées au recueil Lebon et une part des inédites. Une absence ici ne dit "
    . "pas que la décision n'existe pas.";
// Au-delà de ce silence, un fonds ne reçoit plus rien : la Cour de discipline
// budgétaire et financière s'arrête en 2000, les tribunaux administratifs en
// 2009. Même règle et même seuil que `_SILENCE_MAX_JOURS` du client MCP.
const JURIS_SILENCE_MAX_JOURS = 365;
// Avant ce délai, une absence de la base amont ne vaut pas pour la Cour de
// cassation : une décision rendue peut ne pas y être encore publiée. Mesuré le
// 04/11/2026 sur l'index servi (décisions `cc` de 2026, date de décision contre
// `update_date`) : 90,8 % publiées sous 15 jours, 96,4 % sous 30, 97,8 % sous 60.
const CC_DELAI_PUBLICATION_JOURS = 60;

// Métadonnées de plus d'un million de décisions, sans leur texte : l'index répond
// « cette référence existe / n'existe pas » hors ligne, le texte intégral et la
// recherche par thème passent par l'API amont.
// Surchargeable pour la même raison que JURIS_DB juste au-dessus : sans cela,
// la sonde amont de `verifier` ne peut être éprouvée que contre la vraie API,
// c'est-à-dire jamais dans un garde-fou. Une sonde qu'on ne peut pas faire
// rougir ne prouve rien quand elle est verte.
define('JUDILIBRE_BASE', getenv('SELFJUSTICE_JUDILIBRE_BASE')
    ?: 'https://api.piste.gouv.fr/cassation/judilibre/v1.0');

// Nombre de décisions ramenées pour un même numéro. Un rôle général courant en
// compte davantage : le total exact est compté à part et rendu sous `count`,
// pour qu'une liste tronquée se voie au lieu de passer pour exhaustive.
const LIMITE_DECISIONS = 50;

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function json_error(string $message, int $status = 400): void {
    json_response(['error' => $message], $status);
}

/**
 * « Je n'ai pas pu vérifier » — à ne jamais confondre avec « cela n'existe pas ».
 *
 * La raison est répétée sous `error` parce que c'est la clé que lisent les
 * clients, MCP compris : sans elle, ils n'affichent qu'un « HTTP 503 » nu.
 */
function json_indetermine(string $raison, array $extra = []): void {
    json_response(array_merge(
        ['etat' => 'indeterminee', 'error' => $raison, 'raison' => $raison],
        $extra
    ), 503);
}

/**
 * Une base ouverte en lecture, ou null si elle manque ou ne s'ouvre pas.
 *
 * 🔑 Elle ne termine pas la requête, là où `open_db()` appelle `json_error()`,
 * qui sort du script : un `try` qui l'entoure ne le voit jamais. `/status`, qui
 * décrit plusieurs bases, passe par elle pour qu'une base absente n'éteigne pas
 * les autres.
 */
function open_db_ou_null(string $path): ?SQLite3 {
    if (!file_exists($path)) {
        return null;
    }
    try {
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);
        $db->busyTimeout(1000);
        return $db;
    } catch (Exception $e) {
        return null;
    }
}

function open_db(string $path): SQLite3 {
    $db = open_db_ou_null($path);
    if ($db === null) {
        json_error(file_exists($path) ? "Erreur d'ouverture de la base"
                                      : "Base introuvable : " . basename($path), 503);
    }
    return $db;
}

/**
 * L'index plein texte est-il présent dans cette base ?
 *
 * Il est construit par la synchronisation ; une base issue d'une version
 * antérieure du script n'en a pas. La recherche par numéro continue alors de
 * répondre seule, au lieu de rendre une erreur.
 */
/**
 * Slug d'un titre de code, tel qu'un appelant l'écrirait.
 *
 * « Code de l'artisanat » → artisanat · « Code du travail » → travail
 * « Code de procédure civile » → procedure_civile
 */
function code_slug(string $titre): string {
    $t = mb_strtolower(trim($titre), 'UTF-8');
    $t = strtr($t, [
        'à'=>'a','â'=>'a','ä'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ÿ'=>'y','œ'=>'oe',
    ]);
    // On retire le mot « code » et l'article qui le suit : c'est ce que
    // l'appelant omet toujours en écrivant ?code=travail.
    $t = preg_replace('/^code\s+(de\s+la\s+|de\s+l\s*\x27|du\s+|des\s+|de\s+|d\s*\x27)?/u', '', $t);
    $t = preg_replace('/[^a-z0-9]+/', '_', $t);
    return trim($t, '_');
}

/**
 * Les codes réellement servis, indexés par slug.
 *
 * 🔑 La table d'alias écrite à la main en portait 16. La base en sert 108,
 * mesuré le 25/08/2026 : 92 codes existaient sans qu'aucun nom ne permette de
 * les atteindre. « Code de l'artisanat » sortait dans les résultats de
 * /legi/search pendant que ?code=artisanat rendait « Code inconnu » — la
 * réponse accusait l'appelant d'une lacune de la table.
 *
 * Un alias tenu à côté de la base diverge d'elle, et la divergence est muette.
 * Les alias se dérivent donc de ce qui est servi. La table manuelle reste en
 * surcouche : ses raccourcis (« construction » pour le code de la construction
 * et de l'habitation) ne se dérivent pas d'un titre, et ils sont entrés dans
 * des configurations clientes qu'on ne casse pas.
 *
 * Coût : une requête d'agrégation sur toute la table. Elle ne s'exécute que si
 * la table manuelle a échoué — la chaîne de `??` chez l'appelant ne l'évalue
 * pas autrement — c'est-à-dire là où le guichet rendait une erreur. Le chemin
 * nominal ne la paie pas.
 */
function codes_en_base(SQLite3 $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    // Seuls les codes. Sur la base élargie, dériver un slug de chaque texte
    // porteur en produirait 148 978 au lieu de 108 — dont « arrete_du_25_juin_1980 »
    // et ses milliers de frères, qui ne sont pas des noms qu'on tape.
    // (148 978 identifiants distincts dans `articles`, mesuré le 27/08/2026 ;
    // à ne pas confondre avec les 149 020 textes de la base amont, dont 42
    // ne portent aucun article.)
    $filtre = legi_a_nature($db) ? "AND nature = 'CODE' " : "";
    $res = @$db->query(
        "SELECT code_id, MIN(code_titre) AS titre FROM articles "
        . "WHERE code_titre != '' $filtre GROUP BY code_id"
    );
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
        $slug = code_slug($r['titre'] ?? '');
        // Premier arrivé, premier servi : une collision de slug ne doit pas
        // faire changer de code un alias qui répondait déjà.
        if ($slug !== '' && !isset($cache[$slug])) $cache[$slug] = $r['code_id'];
    }
    return $cache;
}

/** La base porte-t-elle la nature de ses textes ?
 *
 * La colonne est arrivée avec l'élargissement du périmètre : jusque-là tout
 * était un code, et la question ne se posait pas. Elle se pose maintenant à
 * chaque appel qui doit distinguer un code d'un arrêté — et l'API doit pouvoir
 * être déployée avant la base élargie sans rien casser. D'où cette sonde, sur
 * le modèle de `legi_fts_disponible()` : sans colonne, on retombe exactement
 * sur le comportement d'avant.
 */
function legi_a_nature(SQLite3 $db): bool {
    static $present = null;
    if ($present !== null) return $present;
    $present = false;
    $res = @$db->query("PRAGMA table_info(articles)");
    while ($res && ($col = $res->fetchArray(SQLITE3_ASSOC))) {
        if ($col['name'] === 'nature') { $present = true; break; }
    }
    return $present;
}

// 🔑 L'état LEGI nomme le dernier événement subi par une version — une
// abrogation, une péremption, une annulation —, pas son applicabilité. Seuls ces
// trois-là désignent une position dans le cycle de vie : ce sont les états sans
// contrepartie dans la table des liens de `legi.py` (TYPELIEN_MAP), et sa règle
// d'anomalie exempte ABROGE_DIFF nommément. Tout autre état, y compris un état
// que la DILA ajouterait demain, tombe du côté « non applicable » au lieu de
// casser une table.
const LEGI_ETATS_VIVANTS = ['VIGUEUR', 'VIGUEUR_DIFF', 'ABROGE_DIFF'];

/** Le jour auquel l'applicabilité se juge : aujourd'hui, sauf pour un banc.
 *
 * `SELFJUSTICE_JOUR` fige la date. Un témoin adossé au jour courant se périme —
 * l'art. 8 du CPP cesse d'être applicable le 1er janvier 2029 — et un banc
 * rougirait alors sans qu'aucun défaut soit revenu. Une valeur mal formée est
 * ignorée plutôt que crue.
 *
 * Calculé une fois par requête : appelé à chaque expression, il pouvait changer de
 * valeur en cours de route, et une requête à cheval sur minuit aurait jugé deux
 * jours à la fois. `\z` et non `$`, qui accepterait un saut de ligne final. */
function legi_jour(): string {
    static $jour = null;
    if ($jour !== null) return $jour;
    $fige = getenv('SELFJUSTICE_JOUR') ?: '';
    return $jour = date_iso_valide($fige) ? $fige : date('Y-m-d');
}

/** L'expression SQL « cette version s'applique au jour J ».
 *
 * 🔑 Une seule définition, pour tout ce qui juge la vigueur : le sélecteur de
 * version, la recherche par numéro, le détecteur de numéros recyclés, le compte
 * de /status. Recopiée en quinze endroits du dépôt sous la forme `etat = 'VIGUEUR'`,
 * elle annonçait morts quelque dix mille articles applicables — mesuré le
 * 12/10/2026 sur la base servie : 683 080 tenus pour en vigueur, 693 141 qui le
 * sont réellement.
 *
 * La période décide ; la liste des états écarte les versions mortes. Sans elle,
 * la sentinelle `2999-01-01` d'un article périmé ou annulé se lirait comme une
 * perpétuité : 583 faux « en vigueur ».
 *
 * ⚠️ L'état vide est NULL (241 785 lignes au 12/10/2026), et `etat IN (…)` vaut alors NULL.
 * Dans un WHERE, ce NULL exclut la ligne — ce qui est juste : une version sans
 * état n'est pas applicable. Sa NÉGATION, en revanche, vaut NULL aussi et
 * exclurait la même ligne de « l'ancien droit », qui deviendrait aveugle à 13 %
 * de la base. D'où deux fonctions, une seule définition : la négation passe
 * TOUJOURS par `legi_sql_non_applicable()`, jamais par un `NOT` écrit à la main.
 *
 * `etat IN (…)` est laissé nu plutôt qu'enveloppé dans `ifnull()` parce qu'il
 * garde ainsi l'index sur `etat`. Mesuré le 14/10/2026 sur la base servie, pour
 * le compte de /status : 312 ms ainsi, 501 ms enveloppé, 34 ms pour l'ancien
 * `etat = 'VIGUEUR'` — qui était rapide parce qu'il était faux.
 *
 * La date est interpolée, échappée, après validation de sa forme — comme ce
 * fichier le fait déjà pour un identifiant. Un paramètre lié par chaque appelant
 * est un paramètre qu'on finit par oublier de lier, et un `:jour` non lié vaut
 * NULL : tout tomberait du côté « non applicable », sans une erreur. */
function legi_sql_applicable(string $alias = ''): string {
    $a = $alias !== '' ? "$alias." : '';
    $jour = SQLite3::escapeString(legi_jour());
    $vivants = implode(',', array_map(
        fn(string $e): string => "'" . SQLite3::escapeString($e) . "'", LEGI_ETATS_VIVANTS));
    return "({$a}etat IN ($vivants)"
         . " AND ifnull({$a}date_debut, '') <= '$jour'"
         . " AND (ifnull({$a}date_fin, '') = '' OR {$a}date_fin > '$jour'))";
}

/** L'ordre dans lequel les versions d'un même article se départagent.
 *
 * 🔑 Une seule définition, pour le sélecteur de /legi/article, les alternatives de
 * codes et l'ancien droit de la recherche. Trois ordres y coexistaient le 14/10/2026,
 * et chacun se trompait différemment : l'un rangeait une version sans état SOUS les
 * versions mortes — `applicable` y vaut NULL, plus petite valeur de SQLite —, et
 * servait l'abrogée de 2017 à la place de la version de 2023 ; un autre, trié sur
 * `date_fin`, faisait passer une sentinelle 2999 devant la version abrogée qu'elle
 * masquait.
 *
 * Ce qui s'applique d'abord. À défaut, ce qui a commencé — le plus récent — plutôt
 * que ce qui commencera ; et parmi ce qui commencera, le plus proche, si bien que
 * la sentinelle 2999 → 2999 vient en dernier. `ifnull(…, 0)` met une version sans
 * état au rang des non applicables, pas sous elles. */
function legi_sql_ordre_version(string $alias = ''): string {
    $a = $alias !== '' ? "$alias." : '';
    $jour = SQLite3::escapeString(legi_jour());
    $commence = "(ifnull({$a}date_debut, '') <= '$jour')";
    return "ifnull(" . legi_sql_applicable($alias) . ", 0) DESC, "
         . "$commence DESC, "
         . "CASE WHEN $commence THEN {$a}date_debut END DESC, "
         . "{$a}date_debut ASC";
}

/** La négation de `legi_sql_applicable()`, sûre face à un état NULL.
 *
 * `NOT (NULL)` vaut NULL, et une ligne sans état disparaîtrait de « l'ancien
 * droit » comme de « l'en vigueur ». `ifnull(…, 0)` ramène ce NULL à faux avant
 * la négation : la ligne tombe du côté non applicable, où elle doit être. */
function legi_sql_non_applicable(string $alias = ''): string {
    return "(NOT ifnull(" . legi_sql_applicable($alias) . ", 0))";
}

/** Ce qu'une version est au jour J, en un mot que le client sait présenter.
 *
 * `en_vigueur` ne distingue pas un article qui ne s'applique plus d'un article
 * qui ne s'applique pas encore — le second étant le plus récent, donc le plus
 * consulté. Et un état NULL n'est ni l'un ni l'autre : la DILA n'en a pas
 * déclaré, ce qui ne dit pas que la version est morte. Le dire « plus en
 * vigueur » affirmait une abrogation que la source n'affirme pas.
 *
 * `$row['applicable']` vient de `legi_sql_applicable()` : cette fonction ne
 * rejuge pas l'applicabilité, elle ne fait que nommer le cas. */
function legi_applicabilite(array $row): string {
    if (!empty($row['applicable'])) return 'en_vigueur';
    $etat = $row['etat'] ?? null;
    if ($etat === null || $etat === '') return 'sans_etat';
    if (in_array($etat, LEGI_ETATS_VIVANTS, true) && ($row['date_debut'] ?? '') > legi_jour()) {
        return 'pas_encore';
    }
    return 'plus_en_vigueur';
}

/** Cherche des articles par numéro, en trois marches.
 *
 * 🔑 `num LIKE '%q%'` ne peut utiliser aucun index : c'est un balayage de toute
 * la table, et il était payé par CHAQUE recherche — y compris « désenfumage »,
 * qui n'a aucune chance de ressembler à un numéro d'article. Mesuré à chaud sur
 * la base élargie :
 *
 *     exact    num = 'L1152-1'         index
 *     préfixe  num LIKE 'L1152-1%'     index
 *     contient num LIKE '%L1152-1%'    balayage ← le seul
 *
 * On descend donc marche par marche, et on s'arrête à la première qui rend
 * quelque chose. La passe entière est sautée quand la requête ne porte aucun
 * chiffre : un numéro d'article en contient toujours un.
 *
 * ⚠️ Une requête qui porte un chiffre sans être un numéro — « arrêté du 25 juin
 * 1980 », « loi de 1881 » — descend les trois marches et paie le balayage.
 * L'escalier borne le cas courant, il ne supprime pas le pire.
 *
 * Les deux passes par numéro — le droit applicable, puis l'ancien — partagent
 * cette fonction : la stratégie est écrite une fois, et la seconde ne peut plus
 * rester en arrière de la première sans que rien ne le signale.
 */
function legi_par_numero(SQLite3 $db, string $q, int $limit, bool $en_vigueur): array {
    if (!preg_match('/\d/', $q)) return [];

    // Au-delà de trois mots, ce n'est plus un numéro d'article.
    //
    // « arrêté du 25 juin 1980 » et « loi de 1881 » portent un chiffre, passent
    // donc le filtre ci-dessus, et descendent les trois marches jusqu'au
    // balayage — deux fois, une par passe. Mesuré : 1,2 s rendu par une requête
    // dont aucune marche ne pouvait rien trouver, puisque aucun `num` ne contient
    // une phrase.
    //
    // Trois mots couvrent 664 543 des 665 659 numéros indexés, soit 99,83 %
    // (mesuré le 27/08/2026 : 642 451 à un mot, 18 002 à deux, 4 090 à trois).
    // Les 1 116 restants sont des libellés d'annexes — « Annexe I, II, III » —
    // que personne ne tape dans une barre de recherche. Le plein texte, lui,
    // continue de les chercher.
    $mots = count(preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY));
    if ($mots > 3) return [];

    // Et la marche du balayage ne se descend que sur un mot unique.
    //
    // Elle ne sert qu'à un usage : retrouver un fragment au MILIEU d'un numéro
    // — « 1152 » pour atteindre « L1152-1 », que le préfixe ne trouve pas. Cet
    // usage est toujours un mot seul. « loi de 1881 » tenait dans les trois mots
    // et payait quand même le balayage, deux fois, pour 1,2 s et rien à trouver.
    $marches = ($mots === 1) ? [$q, $q . '%', '%' . $q . '%'] : [$q, $q . '%'];
    $nat   = legi_a_nature($db);
    // `applicable` voyage avec chaque ligne : la recherche le rend à
    // l'appelant au lieu de le laisser déduire « vivant » de l'état.
    $cols  = "num, etat, code_id, code_titre, date_debut, date_fin, "
           . legi_sql_applicable() . " AS applicable" . ($nat ? ", nature" : "");
    // L'ancien droit est la NÉGATION de l'applicable, écrite par sa fonction :
    // `etat <> 'VIGUEUR'` excluait les versions sans état — 241 785 au 12/10/2026 —, et ce
    // `<>` écrit à la main excluait de même tout ce que `NULL` touche.
    $etat  = $en_vigueur ? legi_sql_applicable() : legi_sql_non_applicable();
    // Les codes d'abord. Un numéro peu discriminant est porté par des dizaines
    // de textes — « 100 » vit dans 118 textes en vigueur, dont 3 codes — et
    // `ORDER BY num` seul est une égalité totale : l'ordre rendu était celui du
    // plan de requête, c'est-à-dire aucun.
    $rang  = $nat ? "(nature = 'CODE') DESC, " : "";
    // L'ancien droit est ensuite dédoublonné par numéro et code : sa PREMIÈRE
    // ligne de chaque groupe est celle que l'appelant garde. `date_fin DESC` y
    // plaçait une sentinelle 2999 devant la version abrogée qu'elle masquait.
    $ordre = $en_vigueur ? $rang . "num" : $rang . legi_sql_ordre_version() . ", num";
    foreach ($marches as $i => $valeur) {
        $operateur = ($i === 0) ? '=' : 'LIKE';
        $stmt = $db->prepare("SELECT $cols
                              FROM articles
                              WHERE num $operateur :p AND $etat
                              ORDER BY $ordre
                              LIMIT :limit");
        $stmt->bindValue(':p', $valeur);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $r = $stmt->execute();
        $lignes = [];
        while ($ligne = $r->fetchArray(SQLITE3_ASSOC)) $lignes[] = $ligne;
        if ($lignes) return $lignes;
    }
    return [];
}

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
 * Quel champ chercher, et que faut-il dire à l'appelant.
 *
 * 🔑 Les sommaires sont rédigés par la Cour de cassation pour ses propres
 * arrêts : une décision de cour d'appel n'en a pas. Chercher dans le sommaire
 * avec `jurisdiction=ca` est donc structurellement vide — mesuré le
 * 20/08/2026 : 0 résultat, contre 117 731 sur le même index en `champ=text`.
 * L'amont préfère répondre plutôt que refuser, et rend ce zéro sans réserve ;
 * un client en conclut qu'aucune cour d'appel ne s'est jamais prononcée.
 *
 * Un défaut se remplace, un choix se respecte : sans `champ`, on bascule sur le
 * texte ; avec `champ=summary` écrit à la main, on rend le zéro demandé et on
 * dit pourquoi il était joué d'avance.
 *
 * @return array{0: string, 1: ?string} le champ à interroger, et la réserve.
 */
function champ_juris(?string $champ_demande, ?string $jurisdiction): array {
    $champ = $champ_demande ?? 'summary';
    if (strtolower($jurisdiction ?? '') !== 'ca' || $champ !== 'summary') {
        return [$champ, null];
    }
    if ($champ_demande === null) {
        return ['text', "Recherche basculée sur le texte intégral : les décisions "
            . "de cour d'appel n'ont pas de sommaire, et le champ par défaut "
            . "n'aurait rien pu rendre."];
    }
    return ['summary', "Les décisions de cour d'appel n'ont pas de sommaire : "
        . "cette combinaison ne peut rien rendre. Rappeler avec champ=text pour "
        . "chercher dans le texte intégral."];
}

/**
 * Une requête de recherche contient-elle de quoi chercher ?
 *
 * 🔑 Le contrôle ne vise pas l'injection SQL : les requêtes préparées la
 * repoussent déjà, et `'; DROP TABLE articles;--` rend une liste vide sans que
 * rien ne soit tombé. Il vise ce que ces entrées produisent quand même. FTS5
 * tokenise `' OR 1=1--` en « or » et « 1 », retrouve trois articles réels du
 * Code de la sécurité sociale, et l'appelant reçoit du droit en réponse à une
 * chaîne qui n'en demandait pas. Côté jurisprudence, `1=1` rend 20 921
 * décisions, et `' OR 1=1--` part jusqu'à Judilibre qui le refuse — le 403 de
 * l'amont revient alors en 503, c'est-à-dire « notre service est indisponible »
 * quand c'est la requête qui ne l'était pas.
 *
 * Deux façons légitimes de chercher, donc deux façons de passer : un mot d'au
 * moins trois lettres, ou un nombre d'au moins deux chiffres — la recherche par
 * numéro d'article (« 1240 », « L122-14 ») n'a aucun mot.
 */
/**
 * Un marqueur de l'état de l'instance, ou null s'il n'existe pas.
 *
 * 🔑 **Deux marqueurs par base, et ils ne disent pas la même chose.**
 * `<base>_last_update` porte la date du CONTENU — pour LEGI, celle du dernier
 * diff publié par la DILA, qui précède forcément notre passage et n'avance plus
 * jusqu'au suivant. `<base>_last_sync` porte la date où NOTRE synchronisation a
 * réussi.
 *
 * Les confondre a un coût mesuré : jusqu'au 21/08/2026 les trois bases
 * exposaient leur date sous le même nom, avec deux sémantiques selon la base,
 * et le client MCP — qui ne peut pas trancher — annonçait « RETARD, l'échéance
 * n'a pas été honorée » dans chaque réponse sur une base parfaitement à jour.
 * Seul l'exploitant sait quand son cron a tourné ; c'est donc à l'instance de
 * le dire, pas au client de le deviner.
 *
 * Le chemin reste ici plutôt que dans la réponse : une réponse publique
 * renseignerait sur l'arborescence du serveur sans rien apporter à qui la lit.
 */
function marqueur(string $nom): ?string {
    $c = @file_get_contents("/var/lib/selfjustice/$nom.txt");
    return ($c !== false && trim($c) !== '') ? trim($c) : null;
}

/**
 * Les formes à chercher pour cette requête — un mot par entrée.
 *
 * 🔑 **Raccourcir n'est pas décoratif.** « personnelles » ne se trouve que dans
 * 4 articles de la base de conventionnalité quand « personnel » est dans 93 :
 * les textes écrivent « données à caractère personnel », jamais « données
 * personnelles ». Sans raccourcissement, la requête la plus courante sur le
 * RGPD rendait 3 résultats marginaux — un quasi-zéro qui a l'air d'une réponse.
 *
 * ⚠️ Le plancher de six lettres est mesuré, pas choisi. À cinq, « traitant »
 * devient « trait » et attrape « traitement » : « sous-traitant » passait de 41
 * à 87 articles. À six, « traita » en rend 41, et « personnelles » donne
 * toujours « personnel ».
 *
 * `mb_substr`, jamais `substr` : couper « données » au sixième OCTET
 * tronquerait un caractère accentué en son milieu.
 *
 * Rend des couples `[mot posé, forme cherchée]` : l'appelant doit pouvoir dire
 * à l'utilisateur qu'on a cherché « personnel » là où il a écrit
 * « personnelles ». Rendre les seules formes obligerait à redécouper la requête
 * pour retrouver les mots d'origine — la règle s'écrirait alors deux fois.
 */
function mots_cherchables(string $q): array {
    $mots = [];
    foreach (preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $mot) {
        if (mb_strlen($mot) < 3) { continue; }
        $mots[] = [$mot, mb_substr($mot, 0, max(6, mb_strlen($mot) - 3))];
    }
    return $mots;
}

/**
 * Cherche dans la base de conventionnalité. Rend [total, résultats, formes].
 *
 * 🔑 **Chercher des MOTS, pas la chaîne entière.** Cette recherche faisait
 * `texte LIKE '%<toute la requête>%'` : elle ne trouvait que les citations
 * verbatim. Mesuré le 21/08/2026 sur dix formulations courantes du RGPD, quatre
 * rendaient zéro — dont « données personnelles », la plus employée de toutes,
 * parce que les textes écrivent « données à caractère personnel » et jamais
 * autrement. Le zéro partait sans réserve, et le modèle annonçait alors à
 * l'utilisateur que le règlement ne dit rien sur le sujet.
 *
 * La recherche entière tient dans cette fonction, et non dans la route, pour
 * qu'un garde-fou puisse l'interroger sur une base de contrefaçon — le
 * défaut vivait dans du SQL qu'aucun test ne pouvait atteindre.
 */
function chercher_conventionnalite(SQLite3 $db, string $q, ?string $source, int $limit): array {
    $mots = mots_cherchables($q);

    $conditions = [];
    $valeurs = [];
    foreach ($mots as $i => [, $forme]) {
        $conditions[] = "(titre LIKE :m$i OR texte LIKE :m$i)";
        $valeurs[":m$i"] = '%' . $forme . '%';
    }
    // Un numéro d'article se cherche tel quel : c'est une référence, pas une
    // suite de mots.
    $valeurs[':numPattern'] = $q . '%';
    $ou = $conditions
        ? '((' . implode(' AND ', $conditions) . ') OR num LIKE :numPattern)'
        : '(num LIKE :numPattern)';

    $where = "WHERE $ou";
    if ($source) {
        $where .= " AND source = :source";
        $valeurs[':source'] = $source;
    }

    // ⚠️ Le compte total se demande à part. Sans lui, l'appelant lit
    // « 20 résultats » là où il y en a 83 et croit avoir tout vu — la limite
    // d'affichage se déguisait en réponse.
    $cnt = $db->prepare("SELECT COUNT(*) as n FROM articles $where");
    foreach ($valeurs as $cle => $val) { $cnt->bindValue($cle, $val); }
    $total = (int) ($cnt->execute()->fetchArray(SQLITE3_ASSOC)['n'] ?? 0);

    $stmt = $db->prepare(
        "SELECT source, num, titre, SUBSTR(texte, 1, 200) as apercu, date_debut
         FROM articles $where LIMIT :limit"
    );
    foreach ($valeurs as $cle => $val) { $stmt->bindValue($cle, $val); }
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);

    $results = [];
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $results[] = [
            'source'    => $row['source'],
            'reference' => $row['num'],
            'titre'     => $row['titre'],
            // 🔑 L'aperçu passe par le même nettoyage que le texte entier. Il ne
            // l'avait pas : la route de l'article était traitée, celle de la
            // recherche non — et c'est elle qui rend des extraits de 200
            // caractères dont la moitié pouvait être du blanc de tableau.
            // Un correctif appliqué à un seul exemplaire d'un mécanisme dupliqué.
            'apercu'    => texte_propre($row['apercu']),
        ];
    }
    return [$total, $results, $mots];
}

/**
 * La juridiction normalisée, ou null si l'index ne la couvre pas.
 *
 * 🔑 **Un argument refusé ne doit jamais ressembler à une réponse.** Ce
 * paramètre partait tel quel vers l'amont, qui rendait un 400 — et le client
 * l'affichait « Aucune décision ne correspond ». Mesuré le 21/08/2026 :
 * `jurisdiction=ce` faisait passer une erreur de paramètre pour un constat de
 * fond sur le droit, alors que la même requête sans filtre rendait 37 159
 * décisions.
 *
 * Une fonction plutôt qu'un test en ligne : la règle vit alors à un seul
 * endroit, et un garde-fou peut l'interroger au lieu de la réécrire.
 */
/**
 * Une date `AAAA-MM-JJ` qui existe. Le format seul laissait passer `2026-02-30`,
 * jugé ensuite comme une date couverte ; un tableau en paramètre levait un 500.
 */
function date_iso_valide(mixed $v): bool {
    return is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $v, $m) === 1
        && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

function juridiction_valide(string $brute): ?string {
    $j = strtolower(trim($brute));
    return in_array($j, juridictions_servies(), true) ? $j : null;
}

/**
 * Les juridictions que l'index porte réellement.
 *
 * 🔑 La liste était écrite à la main ici, dans le message d'erreur, et dans le
 * moissonneur — trois endroits pour une seule vérité, quand `juris_couverture()`
 * la lit déjà dans la base. Ajouter une juridiction au moissonnage sans toucher
 * les deux autres aurait rempli la base de décisions que l'API refuse de
 * servir : la donnée présente, et la porte fermée devant elle.
 *
 * Le repli n'est pas une seconde source. Sans base lisible — un déploiement
 * avant le premier moissonnage, la CI — on ne peut rien dériver, et refuser
 * tout ferait passer une absence d'index pour un paramètre invalide. On
 * retombe alors exactement sur le comportement d'avant, comme `legi_a_nature()`
 * le fait pour sa colonne.
 */
/** L'index porte-t-il les colonnes du fonds administratif ?
 *
 * 🔑 **Le code se déploie avant la base, toujours.** Mesuré le 10/09/2026 en
 * production : la route `decision` interrogeait `texte` et `source` sur un index
 * qui ne les avait pas encore — `prepare()` rendait `false`, et l'appel suivant
 * tuait la requête. Un 500 sur une base parfaitement saine, parce que le
 * collecteur n'était pas encore passé.
 *
 * Même sonde que `legi_a_nature()`, même raison : entre le déploiement du code
 * et la construction de la base il s'écoule des heures, et pendant ce temps
 * l'API doit répondre comme avant.
 */
function juris_a_jade(SQLite3 $db): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    $colonnes = [];
    $res = @$db->query("PRAGMA table_info(decisions)");
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
        $colonnes[] = $r['name'];
    }
    $cache = in_array('texte', $colonnes, true)
          && in_array('source', $colonnes, true);
    return $cache;
}

function juridictions_servies(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = JURIDICTIONS_SANS_INDEX;
    if (file_exists(JURIS_DB)) {
        try {
            $db = open_db_ou_null(JURIS_DB);
            $servies = $db ? array_keys(juris_couverture($db)) : [];
            if ($servies) $cache = $servies;
        } catch (Throwable $e) {
            // Une base illisible ne doit pas fermer le guichet : on garde le repli.
        }
    }
    return $cache;
}

/** Le nom qu'on donne à une juridiction, ou son code si on ne le connaît pas. */
function juridiction_libelle(string $code): string {
    return [
        'cc'   => 'Cour de cassation',
        'ca'   => "cours d'appel",
        'tj'   => 'tribunaux judiciaires',
        'tcom' => 'tribunaux de commerce',
        // L'ordre administratif, servi par le fonds JADE de la DILA.
        'ce'   => "Conseil d'État",
        'caa'  => "cours administratives d'appel",
        'ta'   => 'tribunaux administratifs',
        'tc'   => 'Tribunal des conflits',
        'cdbf' => 'Cour de discipline budgétaire et financière',
    ][$code] ?? $code;
}

/**
 * L'index porte-t-il la justice administrative ? Tant que non, la réserve
 * « relève d'ArianeWeb » est vraie et s'affiche. Dès que oui, elle disparaît
 * d'elle-même : une réserve périmée ferait renoncer à une recherche que la base
 * sait servir.
 */
function administratif_servi(): bool {
    return (bool) array_intersect(juridictions_servies(), ['ce', 'caa', 'ta']);
}

/**
 * La phrase de périmètre de la jurisprudence, tirée de ce que l'index sert.
 */
function perimetre_juris(): string {
    $phrase = "Périmètre : "
        . implode(', ', array_map('juridiction_libelle', juridictions_servies()))
        . " — les dates couvertes, juridiction par juridiction, sont dans "
        . "« couverture ».";
    if (!administratif_servi()) {
        $phrase .= " La justice administrative — Conseil d'État, CAA, TA — relève "
            . "d'ArianeWeb et n'y figure pas.";
    }
    return $phrase;
}

/**
 * Le refus nomme les valeurs admises, et l'exclusion de la justice
 * administrative tant que l'index ne la porte pas.
 */
function message_juridiction_inconnue(string $brute): string {
    $servies = juridictions_servies();
    $noms = array_map(
        fn(string $c): string => $c . ' (' . juridiction_libelle($c) . ')',
        $servies
    );
    $message = "Juridiction « " . trim($brute) . " » inconnue. Valeurs acceptées : "
        . implode(', ', $noms) . ".";
    if (!administratif_servi()) {
        $message .= " Cet index ne couvre que la justice judiciaire : la justice "
            . "administrative — Conseil d'État, CAA, TA — relève d'ArianeWeb et "
            . "n'y figure pas.";
    }
    return $message;
}

function requete_cherchable(string $q): bool {
    return preg_match('/\p{L}{3,}/u', $q) === 1
        || preg_match('/\d{2,}/', $q) === 1;
}

/**
 * Transforme une saisie libre en requête FTS5 inoffensive.
 *
 * FTS5 a sa propre syntaxe : `-` exclut, `:` désigne une colonne, `*` tronque,
 * `NEAR()` et `OR` sont des opérateurs. Passée telle quelle, une référence
 * aussi banale que « L1152-1 » lève « no such column: 1 » — une saisie
 * légitime rendrait un 500.
 *
 * Termes séparés = ET implicite : « harcelement moral » trouve les articles
 * portant les deux mots, sans exiger qu'ils soient côte à côte.
 */
function fts5_requete(string $q): string {
    $termes = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $quotes = [];
    foreach ($termes as $terme) {
        $quotes[] = '"' . str_replace('"', '""', $terme) . '"';
    }
    return implode(' ', $quotes);
}

/**
 * Normalisation d'un numéro de décision : `25-10.377` -> `2510377`,
 * `25/01234` -> `2501234`.
 *
 * ⚠️ Miroir exact de `normaliser()` dans tools/build_judilibre_index.py. Une
 * divergence entre les deux ne lèverait aucune erreur : elle rendrait
 * simplement tous les lookups infructueux.
 */
function juris_normaliser(string $ref): string {
    return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $ref));
}

/**
 * Rend lisible un texte extrait d'EUR-Lex ou d'un PDF.
 *
 * 🔑 Le texte servi portait la mise en forme de sa source, pas la sienne : au
 * 22/08/2026, un tiers des articles en base — dont l'article 22 du RGPD, à 49 %
 * de blancs, et l'article P1-1 de la CEDH, terminé par deux numéros de page.
 *
 * Trois artefacts, trois origines distinctes :
 *   · `\xa0` — EUR-Lex sépare le numéro d'alinéa du texte par trois espaces
 *     insécables ; c'est ce qui gonflait le compte des blancs ;
 *   · des lignes de blancs seuls — les cellules vides des tableaux HTML ;
 *   · des nombres isolés en fin de texte — la pagination du PDF.
 *
 * ⚠️ Le nettoyage est volontairement timide. Il ne touche ni aux sauts de ligne
 * qui structurent les alinéas, ni aux chiffres à l'intérieur du texte : un
 * montant, un délai ou un numéro d'article y ressemblent. Seule la dernière
 * ligne est examinée, et seulement si elle ne contient QUE des nombres courts.
 */
function texte_propre(?string $t): ?string {
    if ($t === null || $t === '') {
        return $t;
    }
    // Les insécables d'EUR-Lex deviennent des espaces ordinaires, puis les
    // répétitions se réduisent — sans toucher aux retours à la ligne.
    $t = str_replace("\xc2\xa0", ' ', $t);
    $t = preg_replace('/[ \t]{2,}/u', ' ', $t);
    $t = preg_replace('/[ \t]+$/mu', '', $t);
    // Les cellules vides des tableaux laissent des lignes de rien.
    $t = preg_replace('/\n{3,}/u', "\n\n", $t);

    // La pagination du PDF : une dernière ligne faite de un à quatre nombres de
    // trois chiffres au plus, rien d'autre. Sur un texte trop court, on
    // s'abstient : l'article pourrait n'être qu'un renvoi numéroté.
    if (mb_strlen($t) > 200) {
        $t = preg_replace('/\n\s*\d{1,3}(?:\s+\d{1,3}){0,3}\s*$/u', '', $t);
    }
    return trim($t);
}

/**
 * Un numéro d'article a-t-il changé de contenu, ou son texte a-t-il déménagé ?
 *
 * 🔑 **Sur un article mort le module crie ; sur un numéro recyclé il se
 * taisait.** Un contrôle extérieur l'a mesuré le 21/08/2026 sur l'article 1382
 * du code civil : depuis 2016 ce numéro porte les présomptions judiciaires, et
 * la responsabilité délictuelle qu'on y cherche est passée au 1240. La réponse
 * était en vigueur, exacte, correctement datée — et hors sujet, sans un signal.
 * C'est le cas où l'on est le plus sûr de soi en se trompant le plus, puisque
 * toutes les marques de fiabilité sont réunies.
 *
 * Rien n'est codé à la main ici, et c'est voulu : la recodification de 2016 a
 * déplacé des centaines d'articles, celle du code du travail des milliers. Une
 * table écrite à la main serait fausse par omission le jour de sa naissance. La
 * base sait déjà tout : il suffit de demander si le texte qu'un numéro portait
 * autrefois vit aujourd'hui sous un autre numéro du même code.
 *
 * Le signal est rare — 73 numéros pour tout le code civil, mesuré le 22/08/2026
 * — donc il informe au lieu de bruiter.
 *
 * ⚠️ Ce que cette déduction NE couvre pas : un successeur dont le texte a été
 * réécrit en même temps qu'il changeait de numéro. C'est le cas de L122-14 du
 * code du travail, devenu L1232-2 le 2008-05-01 avec une rédaction retouchée :
 * l'égalité de texte échoue, et rien ici ne le rattrape. Ce raccord-là ne
 * s'établit qu'avec la table de concordance officielle DILA, qui n'est pas dans
 * le dump. Mieux vaut ne rien dire que deviner un renvoi juridique.
 */
function article_renvoi(SQLite3 $db, array $row): ?array {
    // Deux questions selon l'état de l'article rendu, une seule requête : le
    // texte cherché est celui des autres versions du numéro quand l'article
    // rendu est vivant, et le sien propre quand il est mort.
    // « Vivant » au sens du jour : la ligne vient du sélecteur, qui l'a calculé.
    $vivant = !empty($row['applicable']);

    $sql = $vivant
        ? "SELECT anc.date_fin AS bascule, v.num AS ailleurs
             FROM articles anc
             JOIN articles v ON v.code_id = anc.code_id AND " . legi_sql_applicable('v') . "
                            AND v.texte = anc.texte AND v.num <> anc.num
            WHERE anc.num = :ref AND anc.code_id = :code
              AND " . legi_sql_non_applicable('anc') . " AND anc.texte <> :texte
            ORDER BY anc.date_fin DESC LIMIT 1"
        : "SELECT :date_fin AS bascule, v.num AS ailleurs
             FROM articles v
            WHERE v.code_id = :code AND " . legi_sql_applicable('v') . "
              AND v.texte = :texte AND v.num <> :ref
            LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':ref',   $row['num']);
    $stmt->bindValue(':code',  $row['code_id']);
    $stmt->bindValue(':texte', (string) ($row['texte'] ?? ''));
    if (!$vivant) {
        $stmt->bindValue(':date_fin', $row['date_fin']);
    }
    $trouve = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$trouve || !$trouve['ailleurs']) {
        return $vivant ? article_texte_precedent($db, $row) : null;
    }

    return $vivant
        ? [
            'nature'  => 'numero_recycle',
            'article' => $trouve['ailleurs'],
            'depuis'  => $trouve['bascule'],
            'message' => "⚠️ Ce numéro a changé de contenu. Jusqu'au "
                . "{$trouve['bascule']}, l'article {$row['num']} portait le texte "
                . "qui figure aujourd'hui à l'article {$trouve['ailleurs']} du même "
                . "code. Le texte ci-dessous est bien celui qui porte ce numéro "
                . "aujourd'hui, mais une référence tirée d'une source antérieure à "
                . "cette date, ou de mémoire, vise très probablement "
                . "l'article {$trouve['ailleurs']}. Vérifie lequel des deux est "
                . "voulu avant de citer.",
        ]
        : [
            'nature'  => 'texte_deplace',
            'article' => $trouve['ailleurs'],
            'depuis'  => $trouve['bascule'],
            'message' => "Le texte de cet article, qui n'est plus en vigueur sous ce "
                . "numéro, figure aujourd'hui à l'article {$trouve['ailleurs']} du "
                . "même code. Pour les faits postérieurs, c'est celui-là qu'il faut "
                . "citer.",
        ];
}

/**
 * Ce numéro portait-il un autre texte avant, et lequel ?
 *
 * 🔑 **Quand on ne sait pas où le texte est parti, on peut encore montrer d'où
 * il vient.** La déduction par identité ne couvre que les articles transposés
 * mot pour mot — 1382 → 1240. L'ordonnance de 2016 a réécrit la plupart des
 * autres en même temps qu'elle les renumérotait : 1147, 1134, 1315 lui
 * échappent. Un contrôle extérieur l'a mesuré le 22/08/2026 — deux cas couverts
 * sur dix — en concluant qu'un lecteur demandant l'article 1147 reçoit un texte
 * sur l'incapacité de contracter, sans un mot, là où il cherchait la
 * responsabilité contractuelle.
 *
 * ⚠️ La ressemblance des textes ne sert à rien pour trancher : mesurée sur les
 * 1 398 paires du code civil, elle donne 0,63 à l'article 1382 — dont les deux
 * versions n'ont aucun rapport — et 0,22 à 1384. Deux textes juridiques
 * français partagent trop de vocabulaire pour qu'un ratio les sépare.
 *
 * Ce qui tranche, c'est le lecteur : on lui rend le début de l'ancien texte, il
 * reconnaît en une seconde si c'est ce qu'il cherchait. Aucune table, aucun
 * seuil de similarité, rien à deviner.
 *
 * Reste à ne pas crier pour un mot changé. Un amendement ordinaire touche deux
 * ou trois articles le même jour — médiane mesurée sur le code civil comme sur
 * le code du travail ; une réforme en touche vingt à deux cent trente. Le seuil
 * sépare donc les deux, et le nombre est rendu pour que le lecteur juge de
 * l'ampleur lui-même.
 */
const REFORME_MINIMUM = 20;

function article_texte_precedent(SQLite3 $db, array $row): ?array {
    $stmt = $db->prepare(
        "SELECT anc.date_fin AS bascule, anc.texte AS avant,
                (SELECT COUNT(*) FROM articles v2
                  WHERE " . legi_sql_applicable('v2') . " AND v2.code_id = anc.code_id
                    AND v2.date_debut = anc.date_fin
                    AND EXISTS (SELECT 1 FROM articles x
                                 WHERE x.num = v2.num AND x.code_id = v2.code_id
                                   AND " . legi_sql_non_applicable('x') . " AND x.texte <> v2.texte
                                   AND x.date_fin = v2.date_debut)) AS ampleur
           FROM articles anc
          WHERE anc.num = :ref AND anc.code_id = :code AND " . legi_sql_non_applicable('anc') . "
            AND anc.date_fin = :debut AND anc.texte <> :texte
          LIMIT 1"
    );
    $stmt->bindValue(':ref',   $row['num']);
    $stmt->bindValue(':code',  $row['code_id']);
    $stmt->bindValue(':debut', $row['date_debut']);
    $stmt->bindValue(':texte', (string) ($row['texte'] ?? ''));
    $t = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$t || (int) $t['ampleur'] < REFORME_MINIMUM) {
        return null;
    }

    // 🔑 L'extrait est coupé sur une frontière de caractère, pas d'octet :
    // `substr` à 160 tranche « données » au milieu de son « é ». Mesuré le
    // 21/08/2026 sur une autre route du même fichier.
    $extrait = trim((string) $t['avant']);
    if (mb_strlen($extrait) > 160) {
        $extrait = mb_substr($extrait, 0, 160) . '…';
    }

    return [
        'nature'  => 'contenu_remplace',
        'article' => null,
        'depuis'  => $t['bascule'],
        'ampleur' => (int) $t['ampleur'],
        'avant'   => $extrait,
        'message' => "⚠️ Ce numéro portait un AUTRE texte jusqu'au {$t['bascule']}, "
            . "lors d'une réforme qui a modifié {$t['ampleur']} articles de ce code "
            . "le même jour. Le texte d'alors commençait par : « {$extrait} » — si "
            . "c'est celui que tu cherchais, la référence ne vise plus ce numéro et "
            . "l'index ne sait pas dire où il est passé. Demande à l'utilisateur "
            . "d'où il tient sa référence avant de citer.",
    ];
}

/**
 * Les fonds de la couverture qui reçoivent encore des décisions.
 *
 * 🔑 Un fonds arrêté ne borne pas l'index. Pris dans le `min()` des fins, il
 * ramène la borne de toute vérification sans juridiction à sa date d'arrêt, et
 * toute date postérieure passe pour « au-delà de l'index ».
 */
function juris_fonds_vivants(array $couverture): array {
    $fins = array_filter(array_column($couverture, 'fin'));
    if (!$fins) {
        return $couverture;
    }
    $front = new DateTimeImmutable(max($fins));
    return array_filter($couverture, fn(array $b): bool => !empty($b['fin'])
        && $front->diff(new DateTimeImmutable($b['fin']))->days <= JURIS_SILENCE_MAX_JOURS);
}

/**
 * Bornes réelles des données par juridiction.
 *
 * On ne lit pas `intervalles_faits` : cette table enregistre les intervalles
 * *demandés* à l'API — de l'an 0100 à 2027 — et ne dit rien de ce qui a
 * réellement été reçu. Les dates aberrantes sont écartées : la base amont
 * contient une décision de cour d'appel datée du 24 février 0201.
 */
function juris_couverture(SQLite3 $db): array {
    $couverture = [];
    // Une décision datée après aujourd'hui est une erreur de l'amont : prise comme
    // fin, elle annoncerait une couverture que l'index n'a pas.
    $stmt = $db->prepare(
        "SELECT jurisdiction, MIN(decision_date) AS debut, MAX(decision_date) AS fin,
                COUNT(*) AS total
         FROM decisions WHERE date_suspecte = 0 AND decision_date <= :jour
         GROUP BY jurisdiction"
    );
    $stmt->bindValue(':jour', legi_jour(), SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $couverture[$row['jurisdiction']] = [
            'debut'     => $row['debut'],
            'fin'       => $row['fin'],
            'decisions' => (int) $row['total'],
        ];
    }
    return $couverture;
}

/**
 * Appel à l'API Judilibre. `KeyId` en en-tête suffit — pas d'OAuth.
 *
 * Rend le corps décodé, ou une CHAÎNE décrivant l'échec. L'appelant décide de
 * ce qu'il en fait : `judilibre_get()` en meurt, la sonde de `verifier` s'en
 * passe. Il n'y a qu'une implémentation réseau, et donc qu'un endroit où le
 * timeout, l'en-tête et le décodage sont écrits.
 */
function judilibre_tenter(string $chemin, array $params, int $timeout = 15): array|string {
    $cle = getenv('SELFJUSTICE_JUDILIBRE_KEY') ?: '';
    if ($cle === '') {
        return "Clé Judilibre absente de cette instance : la recherche par thème et "
            . "le texte intégral sont indisponibles. La vérification d'une "
            . "référence reste possible pour les juridictions que l'index local moissonne.";
    }

    $url = JUDILIBRE_BASE . $chemin . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['KeyId: ' . $cle],
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $corps  = curl_exec($ch);
    $statut = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erreur = curl_error($ch);
    curl_close($ch);

    if ($corps === false || $statut !== 200) {
        return $erreur !== ''
            ? "API Judilibre injoignable : $erreur"
            : "API Judilibre — HTTP $statut";
    }

    $data = json_decode($corps, true);
    if (!is_array($data)) {
        return "Réponse Judilibre illisible";
    }
    return $data;
}

/**
 * Même appel, mais l'échec est terminal : 503 portant `etat: indeterminee`,
 * jamais une liste vide. Pour les routes dont l'amont EST la réponse.
 */
function judilibre_get(string $chemin, array $params): array {
    $reponse = judilibre_tenter($chemin, $params);
    if (is_string($reponse)) {
        json_indetermine($reponse);
    }
    return $reponse;
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
            'GET /api/jurisprudence/verifier/{ref}'     => 'Cette référence existe-t-elle ? (ex: 25-10.377, ?jurisdiction=cc|ca|tj|tcom…)',
            'GET /api/jurisprudence/search?q={query}'   => 'Recherche de jurisprudence par thème',
            'GET /api/jurisprudence/decision/{id}'      => 'Texte intégral d\'une décision',
            'GET /api/status'                          => 'État des bases : volumétrie, date du contenu (last_update) et de la dernière synchronisation (last_sync)',
            'GET /api/stats/by-ai'                     => 'Statistiques anonymes par famille d\'IA (Claude, OpenAI, etc.)',
            'GET /api/stats/by-endpoint'               => 'Top articles les plus consultés (anonyme, intérêt général)',
            'GET /api/stats/corpus'                    => 'Volumétrie des bases et date de dernière synchronisation',
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
// /api/stats/by-ai, /api/stats/by-endpoint et /api/stats/corpus
// ============================================================
if ($segments[0] === 'stats') {
    if (count($segments) >= 2 && in_array($segments[1], ['by-ai', 'by-endpoint', 'corpus'], true)) {
        // corpus : volumétrie des bases et date de synchronisation, écrites
        // par le cron horaire. Les pages les rendent côté serveur ; cet
        // endpoint les expose pour qui veut la donnée sans la page.
        // Le nom vient de la liste fermée ci-dessus, jamais du chemin brut.
        $file = '/var/lib/selfjustice/stats/' . $segments[1] . '.json';
        if (!file_exists($file)) {
            json_error("Statistiques non encore générées (cron horaire).", 503);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo file_get_contents($file);
        exit;
    }
    json_error("Endpoint stats inconnu. Disponibles : /api/stats/by-ai, /api/stats/by-endpoint, /api/stats/corpus", 404);
}

// ============================================================
// /api/status
// ============================================================
if ($segments[0] === 'status') {
    $result = ['legi' => null, 'eu' => null, 'jurisprudence' => null];

    try {
        $db = open_db_ou_null(LEGI_DB);
        if ($db === null) { throw new Exception('base LEGI absente'); }
        $result['legi'] = [
            'articles' => (int) $db->querySingle('SELECT COUNT(*) FROM articles'),
            // Il valait COUNT(etat = 'VIGUEUR') — 683 080 le 12/10/2026, pour
            // 693 141 articles réellement applicables : sa hausse est une
            // correction, pas une croissance de la base.
            //
            // ⚠️ Il dépend désormais du CALENDRIER : il bouge le jour où une
            // version entre en vigueur ou cesse de s'appliquer, sans que la base
            // ait changé d'un octet. Son lecteur est la sonde de fraîcheur
            // (tools/check_fraicheur.sh), qui l'exclut de l'empreinte par laquelle
            // elle juge qu'une base a été refaite — sinon un tel jour ferait taire
            // l'alarme du marqueur qui avance sur une base inchangée.
            'vigueur'  => (int) $db->querySingle("SELECT COUNT(*) FROM articles WHERE " . legi_sql_applicable()),
        ];
        // Le chemin du marqueur reste ici : une réponse publique renseigne sur
        // l'arborescence du serveur sans rien apporter à celui qui la lit.
        $result['legi']['last_update'] = marqueur('legi_last_update');
        $result['legi']['last_sync']   = marqueur('legi_last_sync');
        $db->close();
    } catch (Exception $e) {}

    try {
        $db = open_db_ou_null(EU_DB);
        if ($db === null) { throw new Exception('base UE absente'); }
        $sources = [];
        $stmt = $db->query('SELECT source, COUNT(*) as n FROM articles GROUP BY source');
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
            $sources[$row['source']] = (int) $row['n'];
        }
        $result['eu'] = [
            'articles' => (int) $db->querySingle('SELECT COUNT(*) FROM articles'),
            'sources'  => $sources,
        ];
        $result['eu']['last_update'] = marqueur('eu_last_update');
        $result['eu']['last_sync']   = marqueur('eu_last_sync');

        // D'où vient chaque texte servi. Une source du Conseil de l'Europe ou
        // d'EUR-Lex peut être injoignable au moment de la construction ; la
        // base reste alors juste, servie depuis une copie déposée à la main.
        // Sans ce champ, rien ne distingue à la lecture un texte confronté à
        // son amont d'un texte qui ne l'a plus été depuis des mois.
        //
        // ⚠️ Que des chaînes ici, jamais un âge en jours. La sonde de
        // fraîcheur aplatit tous les entiers de ce bloc pour décider si la
        // base a bougé : un entier qui s'incrémente chaque matin la rendrait
        // aveugle au trompe-l'œil. L'âge se recalcule depuis `depose_le`.
        //
        // Table absente = base construite avant elle : le champ manque plutôt
        // que d'affirmer une origine qu'on ne connaît pas.
        try {
            $provenance = [];
            $stmt = $db->query('SELECT source, origine, fichier, depose_le, construite_le FROM provenance');
            while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
                $bloc = ['origine' => $row['origine']];
                foreach (['fichier', 'depose_le', 'construite_le'] as $champ) {
                    if ($row[$champ] !== null) { $bloc[$champ] = $row[$champ]; }
                }
                $provenance[$row['source']] = $bloc;
            }
            if ($provenance) {
                ksort($provenance);
                $result['eu']['provenance'] = $provenance;
            }
        } catch (Exception $e) {}

        $db->close();
    } catch (Exception $e) {}

    // La couverture réelle vaut mieux qu'une date de synchronisation : elle dit
    // jusqu'où l'index permet de conclure à une absence.
    if (file_exists(JURIS_DB)) {
        try {
            $db = open_db_ou_null(JURIS_DB);
            if ($db === null) { throw new Exception('index de jurisprudence illisible'); }
            $couverture = juris_couverture($db);
            $result['jurisprudence'] = [
                'decisions'   => array_sum(array_column($couverture, 'decisions')),
                'couverture'  => $couverture,
                'cle_amont'   => getenv('SELFJUSTICE_JUDILIBRE_KEY') ? 'configurée' : 'absente',
                'last_update' => marqueur('judilibre_last_update'),
                'last_sync'   => marqueur('judilibre_last_sync'),
            ];

            // Deux fonds partagent la table : Judilibre pour l'ordre
            // judiciaire, JADE pour l'ordre administratif. `couverture` les
            // mêle, ce qui est juste pour répondre « jusqu'où puis-je
            // conclure à une absence » — mais masque qu'un des deux
            // collecteurs a cessé de tourner. Les compter à part rend chaque
            // panne visible séparément.
            $par_source = [];
            $stmt = juris_a_jade($db) ? @$db->query(
                "SELECT COALESCE(source,'judilibre') AS s, COUNT(*) AS n "
                . "FROM decisions GROUP BY s") : null;
            while ($stmt && ($row = $stmt->fetchArray(SQLITE3_ASSOC))) {
                $par_source[$row['s']] = (int) $row['n'];
            }
            if ($par_source) {
                $result['jurisprudence']['par_source'] = $par_source;
            }

            // Le dernier incrément JADE appliqué. C'est une CHAÎNE et elle ne
            // bouge que si un incrément a réellement été absorbé : un marqueur
            // qui avancerait tout seul rendrait la sonde de fraîcheur aveugle
            // au fonds qui stagne — le défaut de LEGI, treize mois durant.
            $dernier = @$db->querySingle(
                "SELECT valeur FROM jade_etat WHERE cle='dernier_diff'");
            if ($dernier) {
                $result['jurisprudence']['jade'] = ['dernier_increment' => $dernier];
            }
            $db->close();
        } catch (Exception $e) {}
    }

    json_response($result);
}

// ============================================================
// /api/legi/...
// ============================================================
if ($segments[0] === 'legi') {
    $db = open_db(LEGI_DB);

    // /api/legi/article/{ref}
    if (count($segments) >= 3 && $segments[1] === 'article') {
        // Décodé : un numéro d'article porte des espaces — « GN 13 », « 10 GA
        // bis » — et l'URL les transporte encodés. Sans ce décodage, la
        // référence arrive en « GN%2013 » et échoue à la validation ci-dessous.
        $ref = rawurldecode($segments[2]);
        // Un « / » non encodé coupe la référence : « 8/2 » arrivait en « 8 », et
        // l'article 8 était servi sous l'en-tête demandé.
        if (count($segments) > 3) {
            json_error("Référence « " . rawurldecode(implode('/', array_slice($segments, 2)))
                . " » : un « / » dans une référence s'encode %2F.", 400);
        }

        // 🔑 Tous les numéros d'articles ne ressemblent pas à « L1152-1 ».
        //
        // L'ancienne forme — une majuscule optionnelle, puis un chiffre
        // obligatoire, puis de l'alphanumérique — rejetait 14 339 des 117 651
        // numéros en vigueur, soit 12,2 %, et parmi eux TOUS ceux du règlement
        // de sécurité contre l'incendie : « GN 13 », « DF 10 », « R 19 ». La
        // route rendait « Référence d'article invalide » sur des références
        // parfaitement valides, et accusait l'appelant de sa propre étroitesse.
        //
        // Les caractères admis sont ceux réellement présents dans la base,
        // comptés avant d'écrire la règle : espace (25 099 occurrences), point,
        // astérisque, virgule, barre oblique, parenthèses, degré, deux-points,
        // apostrophe, lettres accentuées. Reste 0,03 % de rejets — la chaîne
        // vide et sept « (suite N) », qui ne se demandent pas seuls.
        //
        // Cette forme n'est pas une protection SQL : la valeur est liée en
        // paramètre. Elle borne la longueur et écarte les caractères qu'aucun
        // numéro ne porte.
        if (!preg_match('/^[0-9A-Za-zÀ-ÿ][0-9A-Za-zÀ-ÿ .,*\/()°:\'’-]{0,49}$/u', $ref)) {
            json_error("Référence d'article invalide : « $ref ». Un numéro "
                     . "d'article commence par une lettre ou un chiffre et ne "
                     . "dépasse pas 50 caractères.");
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
        if (legi_a_nature($db)) $columns .= ", nature";

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

        // Un code que ni les alias ni la base ne connaissent est signalé comme
        // tel. Le laisser filtrer produisait « Article introuvable », qui
        // accuse la référence pour la faute du code — et envoie corriger ce
        // qui est juste.
        // Deux préfixes, mesurés le 27/08/2026 : LEGITEXT porte 3 486 textes
        // consolidés « pérennes » — dont les 108 codes — et JORFTEXT les
        // 145 492 autres, arrêtés et décrets en tête. N'accepter que le premier
        // rendait « Code inconnu » sur l'identifiant que la recherche venait
        // elle-même de servir : l'API refusait sa propre réponse.
        if ($code_filter && !preg_match('/^(?:LEGITEXT|JORFTEXT)\d+$/', $code_filter)) {
            $demande = code_slug($code_filter);
            // `??` n'évalue la suite que si la gauche est nulle : un alias de la
            // table manuelle ne déclenche pas l'agrégation sur toute la table.
            $resolu  = $CODE_ALIASES[strtolower($code_filter)]
                    ?? codes_en_base($db)[strtolower($code_filter)]
                    ?? codes_en_base($db)[$demande]
                    ?? null;
            if ($resolu === null) {
                $connus = codes_en_base($db);
                // Suggérer se mesure : on ne propose que des slugs assez
                // proches pour qu'une faute de frappe les explique.
                $proches = [];
                foreach (array_keys($connus) as $slug) {
                    if ($demande !== '' && levenshtein($demande, $slug) <= 3) $proches[] = $slug;
                }
                sort($proches);
                json_error(
                    "Code « $code_filter » inconnu. "
                    . ($proches
                        ? "Voulais-tu : " . implode(', ', array_slice($proches, 0, 5)) . " ? "
                        : "")
                    . count($connus) . " codes sont servis, nommables par leur titre sans "
                    . "le mot « code » (artisanat, travail, procedure_civile…), ou par un "
                    . "identifiant LEGITEXT ou JORFTEXT. La référence « $ref » n'est pas "
                    . "en cause.",
                    400
                );
            }
            $code_filter = $resolu;
        }

        // Sans code désigné, seuls les codes répondent.
        //
        // 🔑 Depuis que la base porte tout le droit national, 147 870 textes
        // portent un article « 1 » quand 108 seulement sont des codes (mesuré
        // le 27/08/2026 ; le 111 écrit ici la veille comptait des LIGNES de
        // `textes_versions`, pas des textes distincts). En
        // choisir un « par date la plus récente » revient à tirer au sort, et
        // les lister tous noie l'appelant sous un écran d'identifiants. Le
        // droit codifié reste donc la réponse par défaut ; un texte non
        // codifié se demande par son identifiant, que la recherche plein
        // texte rend à côté de chaque résultat.
        $aux_codes = ($code_filter || !legi_a_nature($db)) ? '' : " AND nature = 'CODE'";

        // 🔑 Une seule passe, ordonnée par l'applicabilité. Les deux passes
        // d'avant cherchaient `etat = 'VIGUEUR'` puis, à défaut, la
        // `date_debut` la plus récente — qui peut être dans le futur. Mesuré le
        // 12/10/2026 : l'art. 10 du CPP servait sa version de 2027 en
        // l'annonçant morte, et l'art. 13 d'un arrêté de 2012 servait sa ligne
        // sentinelle `2999-01-01` — un article modificateur, « A abrogé les
        // dispositions suivantes… » — à la place du texte en vigueur que la
        // base possédait. Sur les codes seuls, 2 303 groupes recevaient ainsi
        // la mauvaise version ET le mauvais libellé (mesuré le 12/10/2026).
        //
        // L'ordre est celui de `legi_sql_ordre_version()`, partagé avec les
        // alternatives de codes et l'ancien droit de la recherche.
        $sql = "SELECT $columns, " . legi_sql_applicable() . " AS applicable
                  FROM articles WHERE num = :ref";
        if ($code_filter) $sql .= " AND code_id = :code";
        $sql .= $aux_codes . " ORDER BY " . legi_sql_ordre_version() . " LIMIT 1";

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':ref', $ref);
        if ($code_filter) $stmt->bindValue(':code', $code_filter);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$row) {
            // Introuvable dans les codes ne veut pas dire introuvable. Le dire,
            // sinon le 404 accuse la référence pour le périmètre de la requête.
            $indice = '';
            if ($aux_codes !== '') {
                $st = $db->prepare("SELECT COUNT(DISTINCT code_id) FROM articles "
                                 . "WHERE num = :ref AND nature <> 'CODE'");
                $st->bindValue(':ref', $ref);
                $ailleurs = (int) ($st->execute()->fetchArray(SQLITE3_NUM)[0] ?? 0);
                if ($ailleurs > 0) {
                    // Sans nommer de route : ce message est lu aussi bien par un
                    // client HTTP que par le guichet MCP, où « /api/legi/search »
                    // ne désigne rien que l'appelant puisse invoquer.
                    $indice = " Aucun code ne le porte, mais $ailleurs texte(s) non "
                            . "codifié(s) — arrêté, décret, loi — le portent. "
                            . "Retrouve-le par une recherche plein texte, puis "
                            . "redemande cet article en précisant le code : "
                            . "l'identifiant rendu à côté du résultat.";
                }
            }
            json_error("Article introuvable : $ref"
                . ($code_filter ? " (code $code_filter)" : '') . $indice, 404);
        }

        // Si pas de filtre de code ET plusieurs codes existent pour ce num, lister tous
        if (!$code_filter) {
            // 🔑 Aucun filtre de vigueur ici. Le limiter aux articles en vigueur
            // éteignait l'avertissement d'ambiguïté exactement quand il compte :
            // L122-14-3 existe au code de l'urbanisme et au code du travail, où
            // il porte la règle historique sur la régularité de la procédure de
            // licenciement. Les deux étant abrogés, aucun n'était signalé et
            // l'API en servait un sans dire que l'autre existait.
            $stmt2 = $db->prepare("SELECT DISTINCT code_id FROM articles "
                                . "WHERE num = :ref" . $aux_codes);
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
                    // L'état accompagne chaque alternative : choisir entre deux
                    // codes sans savoir lequel est encore applicable n'est pas
                    // un choix.
                    $alt_stmt = $db->prepare(
                        "SELECT SUBSTR(texte, 1, 150) AS apercu, etat, code_titre, date_debut, date_fin,
                                " . legi_sql_applicable() . " AS applicable
                           FROM articles
                          WHERE num = :ref AND code_id = :cid
                       ORDER BY " . legi_sql_ordre_version() . "
                          LIMIT 1"
                    );
                    $alt_stmt->bindValue(':ref', $ref);
                    $alt_stmt->bindValue(':cid', $cid);
                    $alt_row = $alt_stmt->execute()->fetchArray(SQLITE3_ASSOC);
                    $alternatives[] = [
                        'code_id' => $cid,
                        'code'    => $alt_row['code_titre'] ?? '',
                        'etat'    => $alt_row['etat'] ?? '',
                        'en_vigueur' => !empty($alt_row['applicable']),
                        'applicabilite' => legi_applicabilite($alt_row),
                        'date_fin' => $alt_row['date_fin'] ?? null,
                        'apercu'  => $alt_row['apercu'] ?? '',
                        // SERVER_NAME, que nginx fixe, jamais l'en-tête Host, que le client choisit : cette URL part dans une réponse mise en cache.
                        'url'     => (getenv('SELFJUSTICE_BASE_URL') ?: 'https://' . ($_SERVER['SERVER_NAME'] ?? 'your-instance.example')) . "/api/legi/article/$ref?code=$cid",
                    ];
                }
                json_response([
                    'reference' => $ref,
                    'ambiguous' => true,
                    'message'   => "L'article $ref existe dans plusieurs codes. Précise le code via ?code=XXX — le titre du code sans le mot « code » (travail, artisanat, procedure_civile…) ou l'identifiant LEGITEXT rendu ci-dessous.",
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
            'renvoi'     => $has_texte ? article_renvoi($db, $row) : null,
            // Le SENS de ce champ a changé le 14/10/2026 : il valait
            // `etat === 'VIGUEUR'`, et disait faux pour ~10 000 articles (mesuré le 12/10/2026).
            'en_vigueur'    => (bool) $row['applicable'],
            'applicabilite' => legi_applicabilite($row),
            'date_debut' => $row['date_debut'],
            'date_fin'   => $row['date_fin'],
            'code_id'    => $row['code_id'],
            'code_titre' => $code_titre,
            // `code_id` et `code_titre` portent désormais des arrêtés, des
            // décrets et des lois. Leurs noms datent du temps où la base ne
            // servait que des codes ; les renommer casserait l'API publique et
            // le client MCP pour un gain de vocabulaire. `nature` dit ce que
            // c'est réellement.
            'nature'     => $row['nature'] ?? null,
            'texte'      => $has_texte ? ($row['texte'] ?? null) : null,
            'source'     => [
                'base'         => 'LEGI',
                'origine'      => 'Légifrance — dump officiel DILA',
                'last_update'  => marqueur('legi_last_update'),
                'last_sync'    => marqueur('legi_last_sync'),
                // Légifrance range les codes sous /codes/ et les textes non
                // codifiés — lois, ordonnances, décrets, arrêtés — sous /loda/.
                // Servir la première forme pour un arrêté produisait un lien
                // qui ne mène pas au texte qu'on vient de citer.
                //
                // ⚠️ Non vérifié à la mesure : Légifrance rend 403 à toute
                // requête automatisée, quel que soit l'agent. La distinction
                // vient de la structure du site, pas d'un appel réussi.
                'legifrance_url' => sprintf(
                    'https://www.legifrance.gouv.fr/%s/article_lc/%s',
                    (($row['nature'] ?? 'CODE') === 'CODE') ? 'codes' : 'loda',
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

        if (!requete_cherchable($q)) {
            json_error("« $q » ne contient aucun mot cherchable. Il faut un mot "
                . "d'au moins trois lettres, ou un numéro d'article.");
        }

        // Deux recherches, dans cet ordre : par numéro puis dans le texte.
        //
        // Le numéro passe en premier parce qu'il est sans ambiguïté : qui tape
        // « 1240 » veut l'article 1240, pas les vingt articles qui le citent.
        $results = [];
        $vus = [];

        $lignes_num = legi_par_numero($db, $q, $limit, true);

        // 🔑 La passe par numéro ne prend que la moitié de la place.
        //
        // Depuis que la base porte tout le droit national, un numéro peu
        // discriminant est porté par des dizaines de textes : « 100 » existe
        // dans 118 textes en vigueur, dont 3 codes seulement. Sans plafond, la
        // passe remplissait la limite de vingt lignes toutes numérotées pareil,
        // `$reste` tombait à zéro, et la recherche plein texte n'était jamais
        // exécutée. La requête rendait vingt fois le même numéro et rien de ce
        // qu'on cherchait — sur les 108 codes d'hier, le même numéro ne pouvait
        // pas saturer la liste.
        //
        // Le reliquat n'est pas jeté : il complète la fin de liste une fois que
        // le plein texte a eu sa part.
        $reserve = array_splice($lignes_num, (int) ceil($limit / 2));

        $verser = function (array $lignes) use (&$results, &$vus, $limit): void {
            foreach ($lignes as $row) {
                if (count($results) >= $limit) return;
                $cle = $row['num'] . '|' . $row['code_id'];
                if (isset($vus[$cle])) continue;
                $vus[$cle] = true;
                $results[] = [
                    'reference'  => $row['num'],
                    'etat'       => $row['etat'],
                    'en_vigueur' => !empty($row['applicable']),
                    'applicabilite' => legi_applicabilite($row),
                    'code_id'    => $row['code_id'],
                    'code'       => $row['code_titre'],
                    'nature'     => $row['nature'] ?? null,
                    'date_debut' => $row['date_debut'],
                    'date_fin'   => $row['date_fin'],
                    'match'      => 'numero',
                ];
            }
        };
        $verser($lignes_num);

        $reste = $limit - count($results);
        if ($reste > 0 && legi_fts_disponible($db)) {
            $col_nature = legi_a_nature($db) ? ", a.nature" : "";
            $stmt = $db->prepare(
                "SELECT a.num, a.etat, a.code_id, a.date_debut, a.code_titre$col_nature,
                        " . legi_sql_applicable('a') . " AS applicable,
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
            // 🔑 Plusieurs versions d'un même article peuvent répondre ; le
            // classement les ordonne par score, pas par applicabilité. La version
            // applicable prend la place de la mieux classée — sinon un article en
            // vigueur ressortait sous sa version morte ou sans état.
            $rang_texte = [];
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $cle = $row['num'] . '|' . $row['code_id'];
                $entree = [
                    'reference'  => $row['num'],
                    'etat'       => $row['etat'],
                    'en_vigueur' => !empty($row['applicable']),
                    'applicabilite' => legi_applicabilite($row),
                    'code_id'    => $row['code_id'],
                    'date_debut' => $row['date_debut'],
                    'code'       => $row['code_titre'],
                    'nature'     => $row['nature'] ?? null,
                    'extrait'    => trim($row['extrait']),
                    'match'      => 'texte',
                ];
                if (isset($vus[$cle])) {
                    $i = $rang_texte[$cle] ?? null;
                    if ($i !== null && !$results[$i]['en_vigueur'] && $entree['en_vigueur']) {
                        $results[$i] = $entree;
                    }
                    continue;
                }
                $vus[$cle] = true;
                $rang_texte[$cle] = count($results);
                $results[] = $entree;
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        // 🔑 La fenêtre lue ne contient pas toujours la version applicable d'un
        // article dont une autre version a répondu : elle se cherche alors par son
        // numéro. Elle ne prend la place de la version trouvée que si elle porte
        // aussi les mots cherchés, et avec son propre extrait. Sinon les mots ne
        // sont que dans une version qui ne s'applique pas : celle-ci reste ce
        // qu'elle est, et nomme la version applicable. Fusionner les deux
        // présenterait un texte abrogé sous l'état « en vigueur ».
        if (!empty($rang_texte)) {
            $col_nature = legi_a_nature($db) ? ", a.nature" : "";
            $version_applicable = $db->prepare(
                "SELECT a.rowid AS rid, a.num, a.etat, a.code_id, a.date_debut, a.code_titre$col_nature,
                        " . legi_sql_applicable('a') . " AS applicable
                 FROM articles a
                 WHERE a.num = :num AND a.code_id = :code AND " . legi_sql_applicable('a') . "
                 ORDER BY " . legi_sql_ordre_version('a') . "
                 LIMIT 1"
            );
            $repond_aussi = $db->prepare(
                "SELECT snippet(articles_fts, 0, '', '', '…', 12) AS extrait
                 FROM articles_fts f
                 WHERE f.articles_fts MATCH :q AND f.rowid = :rid"
            );
            foreach ($rang_texte as $i) {
                if ($results[$i]['en_vigueur']) {
                    continue;
                }
                $version_applicable->bindValue(':num', $results[$i]['reference'], SQLITE3_TEXT);
                $version_applicable->bindValue(':code', $results[$i]['code_id'], SQLITE3_TEXT);
                $row = $version_applicable->execute()->fetchArray(SQLITE3_ASSOC);
                $version_applicable->reset();
                if (!$row) {
                    continue;
                }
                $repond_aussi->bindValue(':q', fts5_requete($q));
                $repond_aussi->bindValue(':rid', $row['rid'], SQLITE3_INTEGER);
                $extrait = $repond_aussi->execute()->fetchArray(SQLITE3_ASSOC);
                $repond_aussi->reset();
                if ($extrait) {
                    $results[$i] = array_merge($results[$i], [
                        'etat'          => $row['etat'],
                        'en_vigueur'    => true,
                        'applicabilite' => legi_applicabilite($row),
                        'date_debut'    => $row['date_debut'],
                        'code'          => $row['code_titre'],
                        'nature'        => $row['nature'] ?? null,
                        'extrait'       => trim($extrait['extrait']),
                    ]);
                } else {
                    $results[$i]['version_applicable'] = [
                        'etat'          => $row['etat'],
                        'applicabilite' => legi_applicabilite($row),
                        'date_debut'    => $row['date_debut'],
                    ];
                }
            }
        }

        // Le reliquat de la passe par numéro, maintenant que le plein texte a
        // pris sa part.
        $verser($reserve);

        // Troisième passe : les articles qui ne sont plus en vigueur, après les
        // autres et jamais mélangés.
        //
        // 🔑 Sans elle, la moitié de la base était introuvable par
        // la recherche alors que /legi/article les sert. Les deux outils se
        // contredisaient, et le message d'introuvable renvoyait justement vers
        // celui qui ne pouvait pas répondre : un faux négatif présenté comme
        // une vérification.
        //
        // L'ordre porte le sens — le droit applicable d'abord, l'ancien
        // ensuite — et `etat` accompagne chaque ligne : un article abrogé rendu
        // sans mention serait pire que son absence.
        $reste = $limit - count($results);
        if ($reste > 0) {
            foreach (legi_par_numero($db, $q, $reste, false) as $row) {
                $cle = $row['num'] . '|' . $row['code_id'];
                if (isset($vus[$cle])) {
                    continue;
                }
                $vus[$cle] = true;
                $results[] = [
                    'reference'  => $row['num'],
                    'etat'       => $row['etat'],
                    'en_vigueur' => !empty($row['applicable']),
                    'applicabilite' => legi_applicabilite($row),
                    'code_id'    => $row['code_id'],
                    'code'       => $row['code_titre'],
                    'nature'     => $row['nature'] ?? null,
                    'date_debut' => $row['date_debut'],
                    'date_fin'   => $row['date_fin'],
                    'match'      => 'numero_abroge',
                ];
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
        // Décodés comme la référence LEGI : un client qui encode ses segments
        // — c'est ce qui empêche « ../ » de changer de route — doit être lu.
        if (count($segments) > 4) {
            json_error("Référence « " . rawurldecode(implode('/', array_slice($segments, 3)))
                . " » : un « / » dans une référence s'encode %2F.", 400);
        }
        $source = strtoupper(rawurldecode($segments[2]));
        $num = rawurldecode($segments[3]);

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
            'texte'      => texte_propre($row['texte']),
            'etat'       => $row['etat'],
            'date_debut' => $row['date_debut'],
            'url_source' => $row['url_source'],
            'meta'       => [
                'base'        => 'Conventionnalité',
                'origine'     => $row['source'] === 'CEDH' ? 'echr.coe.int' : 'EUR-Lex',
                'last_update' => marqueur('eu_last_update'),
                'last_sync'   => marqueur('eu_last_sync'),
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

        if (!requete_cherchable($q)) {
            json_error("« $q » ne contient aucun mot cherchable. Il faut un mot "
                . "d'au moins trois lettres, ou un numéro d'article.");
        }

        // 🔑 **Chercher des MOTS, pas la chaîne entière.** Cette recherche
        // faisait `texte LIKE '%<toute la requête>%'` : elle ne trouvait que
        // les citations verbatim. Mesuré le 21/08/2026 sur dix formulations
        // courantes du RGPD, quatre rendaient zéro — dont « données
        // personnelles », la plus employée de toutes, parce que les textes
        // écrivent « données à caractère personnel » et jamais autrement. Le
        // zéro partait sans réserve : le modèle annonçait alors à l'utilisateur
        // que le règlement ne dit rien sur le sujet.
        //
        // ⚠️ Le raccourcissement n'est pas décoratif. « personnelles » ne se
        // trouve que dans 4 articles quand « personnel » est dans 93 : sans lui,
        // le ET sur les mots entiers laissait « données personnelles » à 3
        // résultats marginaux — un quasi-zéro qui a l'air d'une réponse.
        //
        // Le plancher de six lettres a été mesuré, pas choisi. À cinq,
        // « traitant » devient « trait » et attrape « traitement » : la
        // recherche « sous-traitant » passait de 41 à 87 articles. À six,
        // « traita » en rend 41, et « personnelles » donne toujours
        // « personnel ».
        if ($source) {
            $allowed = ['CEDH', 'CHARTE_UE', 'TUE', 'TFUE', 'RGPD', 'AI_ACT'];
            if (!in_array($source, $allowed, true)) {
                json_error("Source invalide");
            }
        }
        [$total, $results, $mots] = chercher_conventionnalite($db, $q, $source, $limit);

        json_response([
            'query'   => $q,
            'source'  => $source,
            // `count` est ce qu'on rend, `total` ce qui existe. Les confondre
            // faisait passer une limite d'affichage pour une réponse.
            'count'   => count($results),
            'total'   => $total,
            'limit'   => $limit,
            // Les formes réellement cherchées : l'appelant doit pouvoir dire à
            // l'utilisateur qu'on a cherché « personnel » quand il a écrit
            // « personnelles », sans quoi un résultat inattendu reste
            // inexplicable.
            'mots_cherches' => $mots,
            'results' => $results,
        ]);
    }

    json_error("Endpoint EU inconnu", 404);
}

// ============================================================
// /api/jurisprudence/...
// ============================================================
if ($segments[0] === 'jurisprudence') {

    // /api/jurisprudence/verifier/{ref}?jurisdiction=cc|ca|tj|tcom…
    //
    // Trois états, jamais deux : « trouvee », « absente », « indeterminee ».
    // Confondre les deux derniers reviendrait à affirmer une absence sans
    // l'avoir vérifiée.
    if (count($segments) >= 3 && $segments[1] === 'verifier') {
        // Un RG de cour d'appel porte un slash — `26/00027`. Selon que le
        // serveur décode ou non `%2F`, il arrive soit entier dans un segment,
        // soit découpé en deux : rejoindre puis décoder couvre les deux cas.
        // Sans ça, `%2F` survit à la normalisation en « 2f » et toutes les
        // références de cours d'appel ressortent « absentes ».
        $ref  = rawurldecode(implode('/', array_slice($segments, 2)));
        $norm = juris_normaliser($ref);

        if ($norm === '') {
            json_error("Référence vide après normalisation : « $ref »");
        }

        if (!file_exists(JURIS_DB)) {
            json_indetermine(
                "Index jurisprudence absent de cette instance : la vérification "
                . "est impossible. Ne rien conclure de cette réponse, et ne citer "
                . "aucune décision de mémoire.",
                ['reference' => $ref]
            );
        }

        $db          = open_db(JURIS_DB);
        $couverture  = juris_couverture($db);
        $juridiction = strtolower(trim($_GET['jurisdiction'] ?? '')) ?: null;

        // Date à laquelle l'appelant situe la décision. Facultative, mais c'est
        // elle qui rend l'absence opposable — voir la réserve, plus bas.
        $date_annoncee = isset($_GET['date']) ? trim(is_string($_GET['date']) ? $_GET['date'] : '') : null;
        if ($date_annoncee !== null && !date_iso_valide($date_annoncee)) {
            json_error("Date « $date_annoncee » invalide. Format attendu : 2024-03-12, une date qui existe.");
        }

        // Les tribunaux judiciaires et de commerce ne sont pas moissonnés, mais
        // l'amont les sert : la vérification passe par lui, l'index n'y a rien.
        $hors_index_amont = $juridiction !== null && !isset($couverture[$juridiction])
            && in_array($juridiction, JURIDICTIONS_AMONT, true);
        if ($juridiction !== null && !isset($couverture[$juridiction]) && !$hors_index_amont) {
            $db->close();
            // Une faute de frappe n'est pas une vérification impossible : elle se
            // refuse en 400, comme dans `search`. Seul un code que la table des
            // libellés connaît relève de « hors de l'index ».
            if (juridiction_libelle($juridiction) === $juridiction) {
                json_error(message_juridiction_inconnue($juridiction), 400);
            }
            json_indetermine(
                "Juridiction « $juridiction » hors de l'index (couvertes : "
                . implode(', ', array_keys($couverture)) . ")."
                . (administratif_servi() ? "" : " La justice administrative — "
                    . "Conseil d'État, tribunaux administratifs — n'est pas dans "
                    . "Judilibre mais dans ArianeWeb."),
                ['reference' => $ref, 'couverture' => $couverture]
            );
        }

        // `location` nomme la cour d'appel. Un rôle général n'est unique qu'au
        // sein d'une cour : sans elle, trente-deux lignes « 26/00027 » sont
        // strictement indiscernables et le lecteur ne peut pas retrouver la
        // sienne. La colonne est remplie à la construction de l'index depuis
        // le début — elle n'était simplement jamais lue.
        $sql = "SELECT d.id, d.number, d.decision_date, d.jurisdiction, d.chamber,
                       d.location, d.publication,
                       d.solution, d.ecli, d.type, d.date_suspecte
                FROM numeros n JOIN decisions d ON d.id = n.decision_id
                WHERE n.number_norm = :norm";
        $sql_count = "SELECT COUNT(*)
                      FROM numeros n JOIN decisions d ON d.id = n.decision_id
                      WHERE n.number_norm = :norm";
        if ($juridiction !== null) {
            $sql .= " AND d.jurisdiction = :juri";
            $sql_count .= " AND d.jurisdiction = :juri";
        }
        // 🔑 Avec une date, les décisions de ce jour passent en tête. La limite
        // s'applique avant le filtre par date plus bas : sans ce tri, une
        // décision présente derrière plus d'homonymes récents que la limite
        // n'en retient sortait « absente ».
        $sql .= $date_annoncee === null
            ? " ORDER BY d.decision_date DESC LIMIT " . LIMITE_DECISIONS
            : " ORDER BY d.decision_date = :date DESC, d.decision_date DESC LIMIT " . LIMITE_DECISIONS;

        // Le total se compte à part : `count($decisions)` saturait à la limite
        // ci-dessus sans que rien ne le signale. Un outil dont la fonction est
        // de dire ce qui existe annonçait un chiffre faux comme exact.
        $stmt_count = $db->prepare($sql_count);
        $stmt_count->bindValue(':norm', $norm);
        if ($juridiction !== null) {
            $stmt_count->bindValue(':juri', $juridiction);
        }
        $total = (int) $stmt_count->execute()->fetchArray(SQLITE3_NUM)[0];

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':norm', $norm);
        if ($juridiction !== null) {
            $stmt->bindValue(':juri', $juridiction);
        }
        if ($date_annoncee !== null) {
            $stmt->bindValue(':date', $date_annoncee);
        }
        $res = $stmt->execute();

        $decisions   = [];
        $juridictions = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $juridictions[$row['jurisdiction']] = true;
            $decisions[] = [
                'id'            => $row['id'],
                'numero'        => $row['number'],
                'date'          => $row['date_suspecte'] ? null : $row['decision_date'],
                'date_brute'    => $row['decision_date'],
                'date_suspecte' => (bool) $row['date_suspecte'],
                'juridiction'   => $row['jurisdiction'],
                'cour'          => $row['location'],
                'chambre'       => $row['chamber'],
                'publication'   => $row['publication'],
                'solution'      => $row['solution'],
                'ecli'          => $row['ecli'],
                'type'          => $row['type'],
            ];
        }
        $db->close();

        // Borne prudente : la plus ancienne des fins de couverture retenues.
        // Sans juridiction précisée, c'est le fonds VIVANT qui s'arrête le plus
        // tôt qui commande — une décision plus récente échapperait à l'index.
        $retenues = $juridiction !== null
            ? ($hors_index_amont ? [] : [$couverture[$juridiction]])
            : array_values(juris_fonds_vivants($couverture));
        $fins   = array_column($retenues, 'fin');
        $debuts = array_column($retenues, 'debut');
        $arret  = $fins ? min($fins) : null;
        $ouvre  = $debuts ? max($debuts) : null;
        // Avant le premier fonds, rien ne prouve rien ; entre le premier et le
        // dernier, la sonde et les réserves par juridiction disent le reste.
        $ouvre_tot = $debuts ? min($debuts) : null;

        // 🔑 **Deux corpus portent le même nom, et ils ne s'arrêtent pas au même
        // jour.** Cette route lit l'index local ; `search` et `decision` servent
        // l'amont Judilibre, qui va plus loin dans le temps. Un contrôle
        // extérieur a mesuré le 21/08/2026 trois références sur trois dont
        // `decision` rendait le texte intégral, signé, et que cette route
        // déclarait absentes : l'outil chargé d'empêcher l'invention démentait
        // celui qui venait de servir la pièce.
        //
        // L'index local n'est pas fautif — il s'arrête où il s'arrête et il le
        // dit. Ce qui manquait, c'était d'aller regarder ailleurs quand on sait
        // qu'on regarde trop court. La sonde part donc dès que rien ne correspond
        // localement à une date annoncée : un passage de moisson n'est pas
        // exhaustif — une décision publiée tard lui échappe —, et l'amont sert des
        // juridictions que l'index ne moissonne pas.
        $amont       = null;   // liste filtrée, ou null si la sonde n'a pas eu lieu
        $amont_echec = null;   // raison, si elle a eu lieu sans aboutir

        // 🔑 **« Ce numéro existe » ne répond pas à « CETTE décision existe ».**
        // Un rôle général n'est unique qu'au sein d'une cour : `23/03077` est
        // porté par Montpellier, Toulouse et une douzaine d'autres. Interrogée
        // sur celui de Versailles du 12 août 2026, la route rendait huit
        // décisions — aucune n'étant celle-là — et concluait « trouvée ». Le
        // contrôle du 21/08/2026 l'avait nommé : une liste crédible et bien
        // formée, qui ne dit jamais que la référence soumise n'y figure pas.
        //
        // Quand l'appelant date sa décision, c'est elle qu'il fait vérifier, pas
        // son numéro. L'absence de correspondance vaut donc absence, et c'est
        // elle qui décide de la suite.
        // Sans date, tout ce qui porte le numéro répond. Avec une date, le reste
        // n'est pas la décision cherchée : ce sont des homonymes, et les laisser
        // dans la même liste est ce qui faisait passer une absence pour une
        // trouvaille.
        $a_la_date = $date_annoncee === null ? $decisions : array_values(array_filter(
            $decisions, fn($d) => ($d['date_brute'] ?? null) === $date_annoncee));
        $homonymes = $date_annoncee === null ? [] : array_values(array_filter(
            $decisions, fn($d) => ($d['date_brute'] ?? null) !== $date_annoncee));
        // Le nombre d'homonymes se déduit du total : la liste ci-dessus est
        // bornée par la limite, et c'est ce nombre que lit le modèle.
        $nb_homonymes = $date_annoncee === null ? 0 : $total - count($a_la_date);
        $correspond = (bool) $a_la_date;

        // L'amont Judilibre ne porte que l'ordre judiciaire : il n'est interrogé
        // que sans juridiction ou pour une juridiction judiciaire.
        $amont_competent = $juridiction === null || in_array($juridiction, JURIDICTIONS_AMONT, true);
        // Une absence dans un fonds administratif ne prouve rien : JADE n'en
        // porte qu'une sélection. Sans juridiction, la décision peut en relever.
        $jade_concerne = $juridiction !== null
            ? !in_array($juridiction, JURIDICTIONS_AMONT, true)
            : administratif_servi();
        $jade_aussi = ($jade_concerne && $juridiction === null)
            ? " Pour une décision administrative : " . lcfirst(JADE_SELECTION)
            : "";
        // Sans juridiction, une absence ne vaut pas la même chose partout : ferme
        // pour ce que l'index local moissonne, bornée à ce que l'amont diffuse pour
        // le reste de l'ordre judiciaire.
        $moissonnees = array_values(array_intersect(JURIDICTIONS_AMONT, array_keys($couverture)));
        $hors_locales = $juridiction === null ? array_values(array_diff(JURIDICTIONS_AMONT, $moissonnees)) : [];
        $noms = fn(array $codes): string => $codes
            ? implode(', ', array_map('juridiction_libelle', $codes))
            : "aucune juridiction judiciaire";

        $borne_depassee = $date_annoncee !== null && $arret !== null && $date_annoncee > $arret;
        if (!$correspond && $amont_competent && $date_annoncee !== null) {
            // `search` refuse une requête qui ressemble à un numéro : en plein
            // texte il rendrait des milliers de faux positifs. Ici le numéro ne
            // décide de rien — il borne la requête à un seul jour, et c'est la
            // normalisation qui tranche, sur la règle exacte de l'index.
            $sonde = ['query' => $ref, 'date_start' => $date_annoncee,
                      'date_end' => $date_annoncee, 'page_size' => 50];
            // 🔑 Sans filtre, Judilibre ne cherche que dans la Cour de cassation :
            // le filtre est donc toujours écrit, et la réserve ne conclut que pour
            // la juridiction effectivement interrogée.
            $sonde['jurisdiction'] = $juridiction ?? 'cc';
            // Huit secondes, pas quinze : la réponse locale est déjà acquise et
            // l'appelant attend. Un amont lent rend la réserve prudente, pas une
            // page blanche.
            $reponse = judilibre_tenter('/search', $sonde, 8);

            if (is_string($reponse)) {
                $amont_echec = $reponse;
            } else {
                $amont = [];
                foreach ($reponse['results'] ?? [] as $r) {
                    // Une décision porte son numéro principal et parfois d'autres
                    // — c'est déjà ce que l'index enregistre à la construction.
                    $numeros = array_merge([$r['number'] ?? null],
                        is_array($r['numbers'] ?? null) ? $r['numbers'] : []);
                    foreach ($numeros as $n) {
                        if ($n === null || juris_normaliser((string) $n) !== $norm) {
                            continue;
                        }
                        $amont[] = [
                            'id'            => $r['id'] ?? null,
                            'numero'        => $r['number'] ?? null,
                            'date'          => $r['decision_date'] ?? null,
                            'date_brute'    => $r['decision_date'] ?? null,
                            'date_suspecte' => false,
                            'juridiction'   => $r['jurisdiction'] ?? null,
                            'cour'          => $r['location'] ?? null,
                            'chambre'       => $r['chamber'] ?? null,
                            // L'amont rend `publication` tantôt en chaîne tantôt
                            // en liste. Ne pas la remonter plutôt que la
                            // remonter dans un format que le client lira de
                            // travers : on ne sait pas, on ne prétend pas.
                            'publication'   => null,
                            'solution'      => $r['solution'] ?? null,
                            'ecli'          => $r['ecli'] ?? null,
                            'type'          => $r['type'] ?? null,
                        ];
                        continue 2;
                    }
                }
                // Une page de résultats ne prouve pas l'absence quand l'amont en
                // annonce davantage à cette date.
                if (!$amont && count($reponse['results'] ?? []) < (int) ($reponse['total'] ?? 0)) {
                    $amont = null;
                    $amont_echec = "la base amont rend plus de décisions à cette date qu'une "
                        . "page n'en montre : la correspondance n'a pas pu être établie";
                }
            }
        }

        // 🔑 La réserve de borne haute n'est vraie que si l'appelant n'a pas dit
        // à quelle date il situe la décision. Sur le scénario le plus dangereux
        // de la recette — une référence inventée, présentée comme lue, et datée
        // — « une décision postérieure ne peut pas être exclue » désarme la
        // seule réponse qui devait être ferme : si la décision existait à la
        // date annoncée et que cette date est couverte, elle serait là.
        // 🔑 `$a_la_date`, pas `$decisions` : des homonymes à d'autres dates ne
        // valent pas trouvaille. C'est ce raccourci qui rendait « trouvée » sur
        // huit décisions dont aucune n'était la bonne.
        // 🔑 L'ordre des cas est celui de ce qu'ils prouvent : une absence avant le
        // premier fonds de l'index ne prouve rien, quoi que l'amont réponde. Et une
        // réserve ne conclut que pour la juridiction que la sonde a interrogée :
        // « opposable » ne se dit que de la Cour de cassation, que l'amont diffuse
        // en entier — des autres, il ne diffuse qu'une partie.
        $libelle_j = $juridiction !== null ? juridiction_libelle($juridiction) : null;
        $autres = ($juridiction === null && in_array('ca', $moissonnees, true)
                ? " Cours d'appel : l'index local ne porte que ce que Judilibre en diffuse, "
                  . "et l'absence n'y prouve rien." : "")
            . ($hors_locales ? " " . ucfirst($noms($hors_locales)) . " : la base amont ne "
                  . "les interroge que si la juridiction est précisée." : "")
            . $jade_aussi;
        $echec = $amont_echec !== null ? rtrim($amont_echec, " .") : '';
        // « Opposable » ne se dit que de la Cour de cassation : il se juge sur SA
        // couverture et sur SON délai de publication, pas sur ceux des autres fonds.
        $ouvre_cc = $couverture['cc']['debut'] ?? null;
        $cc_trop_recente = $date_annoncee !== null && $date_annoncee > (new DateTimeImmutable(legi_jour()))
            ->modify('-' . CC_DELAI_PUBLICATION_JOURS . ' days')->format('Y-m-d');

        // Une juridiction que l'index ne porte pas n'a qu'une source, la sonde
        // amont. Sans date elle n'a pas lieu, et elle peut échouer : dans les deux
        // cas rien n'a été consulté, et « absente » affirmerait une absence que
        // personne n'a vérifiée.
        if ($hors_index_amont && $amont === null) {
            $db->close();
            json_indetermine(
                "Non vérifiable : l'index local ne porte pas les $libelle_j, et la base "
                . "amont, seule à les servir, "
                . ($date_annoncee === null
                    ? "n'est interrogée qu'avec la date de la décision."
                    : "ne l'a pas pu — $echec."),
                ['reference' => $ref, 'juridiction' => $juridiction]
            );
        }

        $reserve_borne = ($a_la_date || $amont) ? null : match (true) {
            $arret === null && !$hors_index_amont => "Introuvable, et cet index ne déclare "
                . "aucune couverture : rien ne peut se conclure de cette absence.",
            $date_annoncee === null   => "Introuvable dans un index arrêté au $arret : "
                . "une décision postérieure ne peut pas être exclue."
                . ($juridiction === null && $hors_locales ? " " . ucfirst($noms($hors_locales))
                    . " ne sont pas dans l'index : avec la juridiction et la date de la "
                    . "décision, la base amont est interrogée." : "")
                . ($jade_concerne ? " " . JADE_SELECTION : ""),
            // Une date à venir ne peut porter aucune décision : l'absence y est ferme,
            // quoi que l'amont réponde. C'est le cas d'une référence inventée et datée.
            $date_annoncee > legi_jour() => "Introuvable, et la date annoncée "
                . "($date_annoncee) est postérieure à aujourd'hui : aucune décision ne peut "
                . "porter cette date.",
            $ouvre_tot !== null && $date_annoncee < $ouvre_tot => "Introuvable, mais la date "
                . "annoncée ($date_annoncee) précède le début de l'index ($ouvre_tot) : "
                . "cette absence ne prouve rien.",
            $amont === [] && ($juridiction === null || $juridiction === 'cc')
                && $ouvre_cc === null => "Introuvable dans la base amont Judilibre "
                . "interrogée au $date_annoncee pour la Cour de cassation, mais cet index "
                . "ne déclare pas de couverture pour elle : rien ne se conclut de cette "
                . "absence." . ($juridiction === null ? $autres : ""),
            $amont === [] && $juridiction === null && $ouvre_cc !== null
                && $date_annoncee < $ouvre_cc => "Introuvable, mais la date annoncée "
                . "($date_annoncee) précède le début de l'index pour la Cour de cassation "
                . "($ouvre_cc) : pour elle, cette absence ne prouve rien." . $autres,
            $amont === [] && ($juridiction === null || $juridiction === 'cc')
                && $cc_trop_recente => "Introuvable dans l'index local et dans la base amont "
                . "Judilibre interrogée au $date_annoncee pour la Cour de cassation, mais une "
                . "décision de moins de " . CC_DELAI_PUBLICATION_JOURS . " jours peut ne pas y "
                . "être encore publiée : l'absence n'est pas encore opposable."
                . ($juridiction === null ? $autres : ""),
            $amont === [] && $juridiction === null => "Introuvable dans l'index local, "
                . "arrêté au $arret, et dans la base amont Judilibre interrogée au "
                . "$date_annoncee pour la Cour de cassation : pour elle, l'absence est "
                . "opposable." . $autres,
            $amont === [] && $juridiction === 'cc' => "Introuvable dans l'index local, "
                . "arrêté au $arret. La base amont Judilibre, interrogée directement au "
                . "$date_annoncee, ne rend rien non plus sous ce numéro : l'absence ne tient "
                . "pas au retard de l'index, elle est opposable.",
            $amont === [] => "Introuvable"
                . ($hors_index_amont ? " — l'index local ne porte pas les $libelle_j —"
                    : " dans l'index local, arrêté au $arret,")
                . " et dans la base amont Judilibre interrogée au $date_annoncee pour les "
                . "$libelle_j : l'absence ne vaut que dans les limites de ce que Judilibre "
                . "diffuse de ces juridictions.",
            $amont_echec !== null && $borne_depassee => "Introuvable, et la date annoncée "
                . "($date_annoncee) est postérieure à l'arrêt de l'index local ($arret) : "
                . "cette absence ne prouve rien. La base amont, qui aurait tranché, ne "
                . "l'a pas pu — $echec.",
            $amont_echec !== null && ($ouvre === null || $date_annoncee >= $ouvre) => "Introuvable dans l'index local, qui couvre cette "
                . "date ($ouvre → $arret) : l'absence y est probable sans être établie, car "
                . "une décision publiée tard peut manquer à la moisson, et la base amont, "
                . "qui l'aurait tranché, ne l'a pas pu — $echec." . $autres,
            $amont_echec !== null => "Introuvable dans l'index local, dont tous les fonds "
                . "ne commencent pas avant cette date (le dernier ouvert commence le $ouvre) : "
                . "l'absence n'y est pas établie, et la base amont, qui l'aurait tranché, ne "
                . "l'a pas pu — $echec." . $autres,
            $date_annoncee > $arret => "Introuvable, et la date annoncée ($date_annoncee) "
                . "est postérieure à l'arrêt de l'index pour cette juridiction ($arret) : "
                . "cette absence ne prouve rien, et aucune base amont ne la tranche ici.",
            $jade_concerne && $juridiction !== null => "Introuvable, alors que la date "
                . "annoncée ($date_annoncee) est couverte par l'index ($ouvre → $arret). "
                . JADE_SELECTION,
            // Aucun chemin connu n'arrive ici : chaque juridiction datée passe par la
            // sonde ou par JADE. Le bras reste, parce qu'un `match` sans issue rendrait
            // un 500, et il ne conclut rien de ce qu'il n'a pas vérifié.
            default => "Introuvable, et ce cas n'est pas prévu par la réserve : ne rien "
                . "conclure de cette absence.",
        };

        // Ce qui est rendu vient de l'index local, ou de la sonde amont quand
        // elle a retrouvé ce que l'index n'avait pas encore. Le lecteur doit
        // savoir lequel des deux : ils ne s'arrêtent pas au même jour, et c'est
        // exactement ce qui faisait se contredire deux outils du même module.
        // L'amont prime quand il a trouvé la décision datée : ce que l'index
        // local porte sous le même numéro concerne d'autres affaires, et les
        // mélanger rendrait la vraie introuvable au milieu des homonymes.
        $depuis_amont = (bool) $amont;
        $issues       = $depuis_amont ? $amont : $a_la_date;

        if ($depuis_amont) {
            $juridictions = [];
            foreach ($issues as $d) {
                $juridictions[$d['juridiction']] = true;
            }
        }

        json_response([
            'etat'        => $issues ? 'trouvee' : 'absente',
            'reference'   => $ref,
            'normalisee'  => $norm,
            'juridiction' => $juridiction,
            'source'      => $depuis_amont ? 'amont Judilibre' : 'index local',
            // `count` répond à la question posée. Sans date c'est le total du
            // numéro ; avec une date c'est ce qui porte cette date, et les autres
            // sont comptés à part sous « homonymes ».
            'count'       => ($depuis_amont || $date_annoncee !== null)
                             ? count($issues) : $total,
            'rendues'     => count($issues),
            'tronquee'    => !$depuis_amont && $date_annoncee === null
                             && $total > count($decisions),
            'decisions'   => $issues,
            'couverture'  => $couverture,
            // Trois réserves, une par situation. Trouvée en amont, ce n'est
            // pas une prudence mais un constat : l'index local a du retard, et
            // le dire évite qu'un prochain appel sur la même référence paraisse
            // se contredire tout seul. Absente, il en faut deux — l'index a une
            // borne haute ET un périmètre : une référence du Conseil d'État n'y
            // figurera jamais, quel que soit le rafraîchissement, et la signaler
            // « absente » sans le dire serait trompeur.
            'homonymes'   => $homonymes,
            'reserve'     => $depuis_amont
                ? (array_intersect(array_keys($juridictions), $moissonnees)
                    ? "Cette décision ne figure pas encore dans l'index local, arrêté "
                    . "au $arret : elle a été retrouvée dans la base amont Judilibre à "
                    . "la date annoncée ($date_annoncee). Son existence est établie — "
                    . "c'est l'index qui est en retard, pas la référence qui est fausse."
                    : "Cette décision ne figure pas dans l'index local, qui ne moissonne "
                    . ($moissonnees ? "que " . $noms($moissonnees) : "aucune juridiction judiciaire")
                    . " : elle a été retrouvée dans la base "
                    . "amont Judilibre à la date annoncée ($date_annoncee). Son existence "
                    . "est établie.")
                : ($issues
                ? ($nb_homonymes ? "Les " . $nb_homonymes . " autre(s) décision(s) "
                    . "portant ce numéro à d'autres dates ne sont pas celle-ci : un "
                    . "rôle général n'est unique qu'au sein d'une cour. Voir "
                    . "« homonymes »."
                // 🔑 **Une liste sans date est une liste bornée qui l'ignore.**
                // Sans date annoncée, la sonde amont ne part pas — elle n'a rien
                // sur quoi interroger — et la réponse rend les seules décisions
                // que l'index local connaît. Elle était alors muette : un
                // contrôle extérieur a mesuré le 22/08/2026 que « 24/00627 »
                // rendait 26 homonymes, la plus récente de mai, sans que rien ne
                // dise que celle d'août ne pouvait pas y être.
                //
                // Trouver n'exonère pas de dire jusqu'où on a cherché.
                : ($date_annoncee === null && $arret !== null
                    ? "Cette liste vient du seul index local, arrêté au $arret. Une "
                    . "décision portant ce numéro à une date postérieure n'y figure "
                    . "pas et n'apparaîtra pas ci-dessus. Rappelle l'outil avec la "
                    . "date de la décision cherchée : elle déclenche l'interrogation "
                    . "de la base amont, qui va plus loin."
                    : null))
                : ($nb_homonymes ? "Ce numéro existe — " . $nb_homonymes
                    . " décision(s) le portent — mais aucune n'est datée du "
                    . "$date_annoncee. Un rôle général n'est unique qu'au sein d'une "
                    . "cour : la décision cherchée n'est pas celles-là. Voir "
                    . "« homonymes ». " : "")
                . $reserve_borne . " " . perimetre_juris()
                . " Dire « introuvable », pas « n'existe pas »."),
            'avertissement' => count($juridictions) > 1
                ? "Plusieurs juridictions portent ce même numéro normalisé : un RG "
                . "de cour d'appel (25/10907) et un pourvoi (25-10.907) se "
                . "confondent une fois les séparateurs retirés. Filtrer sur la "
                . "juridiction avant de conclure."
                : null,
        ]);
    }

    // /api/jurisprudence/search?q=... — par thème, via l'API amont
    if (count($segments) >= 2 && $segments[1] === 'search') {
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 3) {
            json_error("Requête trop courte (min 3 caractères)");
        }

        if (!requete_cherchable($q)) {
            json_error("« $q » ne contient aucun mot cherchable. Il faut un mot "
                . "d'au moins trois lettres, ou un numéro d'article.");
        }

        // Une saisie qui ressemble à un numéro partirait en plein texte et
        // rendrait des centaines de milliers de résultats — un faux positif qui
        // ferait conclure « la référence existe ».
        if (preg_match('#^[A-Z]?\s?[0-9]{2}[-/.][0-9]{2}[-./]?[0-9]+$#i', $q)) {
            json_error(
                "« $q » ressemble à un numéro de décision. Utiliser "
                . "/api/jurisprudence/verifier/" . rawurlencode($q) . " : en plein "
                . "texte, ce numéro rendrait des milliers de faux positifs.",
                400
            );
        }

        $params = ['query' => $q, 'page_size' => min(max((int)($_GET['limit'] ?? 10), 1), 50)];

        // Judilibre cherche dans le texte entier par défaut, en pondérant des
        // mots isolés : une question de neuf mots rend des dizaines de milliers
        // de décisions dont aucune ne porte sur le sujet. Restreindre au
        // sommaire — le résumé rédigé par la Cour — divise le bruit par
        // plusieurs dizaines et fait remonter les arrêts de principe.
        //
        // Contrepartie assumée : seules les décisions pourvues d'un sommaire
        // ressortent, c'est-à-dire les arrêts publiés. `champ=text` rétablit la
        // recherche exhaustive quand on cherche une espèce plutôt qu'un principe.
        // Une valeur inconnue est refusée, jamais ignorée : la laisser passer
        // rebasculait la recherche sur le texte entier sans le dire, et le
        // total sautait de 15 273 à 179 540 sans que rien ne l'explique.
        $champs_valides = ['summary', 'themes', 'text', 'motivations', 'dispositif', 'visa'];
        $champ_demande = $_GET['champ'] ?? null;
        if ($champ_demande !== null && !in_array($champ_demande, $champs_valides, true)) {
            json_error(
                "Champ de recherche « $champ_demande » inconnu. Valeurs acceptées : "
                . implode(', ', $champs_valides) . '.',
                400
            );
        }

        foreach (['jurisdiction', 'date_start', 'date_end'] as $option) {
            if (!empty($_GET[$option])) {
                $params[$option] = $_GET[$option];
            }
        }
        // Une date mal formée se refuse ici : l'amont la refuserait en 503, qui se
        // lirait comme une absence de décision. Même format que `verifier`.
        foreach (['date_start', 'date_end'] as $option) {
            if (isset($params[$option]) && !date_iso_valide($params[$option])) {
                $lue = is_string($params[$option]) ? $params[$option] : '(non textuelle)';
                json_error("Date « $lue » invalide. Format attendu : 2024-03-12, une date qui existe.", 400);
            }
        }

        // 🔑 **Un argument refusé ne doit jamais ressembler à une réponse.**
        // `jurisdiction` partait tel quel vers l'amont, qui rendait un 400 — et
        // le client l'affichait « Aucune décision ne correspond ». Mesuré le
        // 21/08/2026 : `jurisdiction=ce` faisait passer une erreur de paramètre
        // pour un constat de fond sur le droit, alors que la même requête sans
        // filtre rendait 37 159 décisions. La route du catalogue nomme déjà ses
        // valeurs acceptées en cas de refus ; celle-ci ne disait rien.
        //
        // 🔑 Une juridiction servie par l'index n'est pas forcément servie par
        // l'amont. Le Conseil d'État est dans l'index par JADE ; transmis à
        // Judilibre, il revenait en « API Judilibre — HTTP 400 », une panne
        // apparente là où il fallait une indication.
        if (isset($params['jurisdiction'])) {
            $normalisee = juridiction_valide($params['jurisdiction']);
            if ($normalisee === null) {
                json_error(message_juridiction_inconnue($params['jurisdiction']), 400);
            }
            if (!in_array($normalisee, JURIDICTIONS_AMONT, true)) {
                json_error("La recherche par thème interroge Judilibre, qui ne sert que "
                    . "la justice judiciaire (" . implode(', ', JURIDICTIONS_AMONT)
                    . "). « $normalisee » (" . juridiction_libelle($normalisee) . ") est "
                    . "dans l'index local, tiré du fonds JADE : une de ses décisions se "
                    . "vérifie par son numéro, avec /jurisprudence/verifier.", 400);
            }
            $params['jurisdiction'] = $normalisee;
        }

        [$champ, $bascule] = champ_juris($champ_demande, $params['jurisdiction'] ?? null);

        if ($champ !== 'text') {
            $params['field'] = $champ;
        }

        $data = judilibre_get('/search', $params);
        json_response([
            'etat'    => 'trouvee',
            'query'   => $q,
            'champ'   => $champ,
            'total'   => $data['total'] ?? null,
            'results' => $data['results'] ?? [],
            'reserve' => $bascule,
            'source'  => 'Judilibre (Cour de cassation) — temps réel',
        ]);
    }

    // /api/jurisprudence/decision/{id} — texte intégral, à la demande
    //
    // Deux fonds, deux provenances du texte. L'ordre judiciaire vient de
    // Judilibre à la demande : l'index n'en garde que l'identité. L'ordre
    // administratif vient du fonds JADE, qui n'a pas d'API — son texte est donc
    // en base, et c'est la seule façon de le rendre. Le préfixe de
    // l'identifiant suffit à les distinguer : tous les identifiants JADE sont
    // « CETATEXT » suivi de douze chiffres (mesuré sur le fonds entier).
    if (count($segments) >= 3 && $segments[1] === 'decision') {
        $id = $segments[2];

        if (preg_match('/^CETATEXT\d{12}$/i', $id)) {
            if (!file_exists(JURIS_DB)) {
                json_error("Index de jurisprudence absent de cette instance.", 503);
            }
            $db  = open_db(JURIS_DB);
            if (!juris_a_jade($db)) {
                json_error(
                    "L'index de jurisprudence administrative n'est pas encore "
                    . "construit sur cette instance. Les décisions de l'ordre "
                    . "judiciaire restent servies normalement.",
                    503
                );
            }
            $req = $db->prepare(
                "SELECT id, number, decision_date, jurisdiction, location, formation,
                        publication, solution, type, texte, date_suspecte
                 FROM decisions WHERE id = :id AND source = 'jade'");
            $req->bindValue(':id', strtoupper($id), SQLITE3_TEXT);
            $ligne = $req->execute()->fetchArray(SQLITE3_ASSOC);
            if (!$ligne) {
                json_error("Décision « $id » absente de l'index administratif. " . JADE_SELECTION, 404);
            }
            $ligne['juridiction_libelle'] = juridiction_libelle($ligne['jurisdiction']);
            $ligne['texte'] = texte_propre($ligne['texte']);
            $ligne['source'] = "JADE (DILA) — jurisprudence administrative";
            if ((int) $ligne['date_suspecte'] === 1) {
                // Une date aberrante se signale au lieu d'être servie comme un
                // fait : le fonds en porte une à l'an 2990.
                $ligne['reserve'] = "La date de cette décision est hors des bornes "
                    . "plausibles telle que la source la publie — ne pas s'y fier.";
            }
            json_response($ligne);
        }

        if (!preg_match('/^[a-f0-9]{16,40}$/i', $id)) {
            json_error(
                "Identifiant de décision invalide : « $id ». Deux formes sont "
                . "acceptées : un identifiant Judilibre (hexadécimal, ordre "
                . "judiciaire) ou un identifiant JADE « CETATEXT » suivi de douze "
                . "chiffres (ordre administratif)."
            );
        }
        json_response(judilibre_get('/decision', ['id' => $id]));
    }

    json_error(
        "Endpoint jurisprudence inconnu. Disponibles : "
        . "/api/jurisprudence/verifier/{ref}, /api/jurisprudence/search?q=, "
        . "/api/jurisprudence/decision/{id}",
        404
    );
}

json_error("Endpoint inconnu : /" . implode('/', $segments), 404);
