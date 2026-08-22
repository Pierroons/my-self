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
 *   GET /api/jurisprudence/verifier/{ref}?jurisdiction={cc|ca}&date={AAAA-MM-JJ}
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
            'apercu'    => $row['apercu'],
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
function juridiction_valide(string $brute): ?string {
    $j = strtolower(trim($brute));
    return in_array($j, ['cc', 'ca'], true) ? $j : null;
}

/**
 * Le refus nomme les valeurs admises ET l'exclusion : « ce » est la tentation
 * naturelle de qui cherche le Conseil d'État, et cette base ne couvre pas la
 * justice administrative.
 */
function message_juridiction_inconnue(string $brute): string {
    return "Juridiction « " . trim($brute) . " » inconnue. Valeurs acceptées : "
        . "cc (Cour de cassation), ca (cours d'appel). Cette base ne couvre que "
        . "la justice judiciaire : la justice administrative — Conseil d'État, "
        . "CAA, TA — relève d'ArianeWeb et n'y figure pas.";
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
 * 🔑 Le texte servi portait la mise en forme de sa source, pas la sienne. Mesuré
 * le 22/08/2026 par un contrôle extérieur : l'article 22 du RGPD comptait 49 %
 * de blancs et 38 séquences de dix caractères d'espacement ou plus, la plus
 * longue de trente-six ; l'article P1-1 de la CEDH se terminait par « 34 35 »,
 * deux numéros de page du PDF d'origine. 258 articles sur 793 sont touchés.
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
 * — donc il informe au lieu de bruiter. La requête coûte 7 ms quand elle
 * trouve, 52 ms quand elle ne trouve pas (525 441 articles).
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
    $vivant = $row['etat'] === 'VIGUEUR';

    $sql = $vivant
        ? "SELECT anc.date_fin AS bascule, v.num AS ailleurs
             FROM articles anc
             JOIN articles v ON v.code_id = anc.code_id AND v.etat = 'VIGUEUR'
                            AND v.texte = anc.texte AND v.num <> anc.num
            WHERE anc.num = :ref AND anc.code_id = :code
              AND anc.etat <> 'VIGUEUR' AND anc.texte <> :texte
            ORDER BY anc.date_fin DESC LIMIT 1"
        : "SELECT :date_fin AS bascule, v.num AS ailleurs
             FROM articles v
            WHERE v.code_id = :code AND v.etat = 'VIGUEUR'
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
                  WHERE v2.etat = 'VIGUEUR' AND v2.code_id = anc.code_id
                    AND v2.date_debut = anc.date_fin
                    AND EXISTS (SELECT 1 FROM articles x
                                 WHERE x.num = v2.num AND x.code_id = v2.code_id
                                   AND x.etat <> 'VIGUEUR' AND x.texte <> v2.texte
                                   AND x.date_fin = v2.date_debut)) AS ampleur
           FROM articles anc
          WHERE anc.num = :ref AND anc.code_id = :code AND anc.etat <> 'VIGUEUR'
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
 * Bornes réelles des données par juridiction.
 *
 * On ne lit pas `intervalles_faits` : cette table enregistre les intervalles
 * *demandés* à l'API — de l'an 0100 à 2027 — et ne dit rien de ce qui a
 * réellement été reçu. Les dates aberrantes sont écartées : la base amont
 * contient une décision de cour d'appel datée du 24 février 0201.
 */
function juris_couverture(SQLite3 $db): array {
    $couverture = [];
    $res = $db->query(
        "SELECT jurisdiction, MIN(decision_date) AS debut, MAX(decision_date) AS fin,
                COUNT(*) AS total
         FROM decisions WHERE date_suspecte = 0 GROUP BY jurisdiction"
    );
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
            . "référence dans l'index local reste possible.";
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
            'GET /api/jurisprudence/verifier/{ref}'     => 'Cette référence existe-t-elle ? (ex: 25-10.377, ?jurisdiction=cc|ca)',
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
        $db = open_db(LEGI_DB);
        $result['legi'] = [
            'articles' => (int) $db->querySingle('SELECT COUNT(*) FROM articles'),
            'vigueur'  => (int) $db->querySingle("SELECT COUNT(*) FROM articles WHERE etat='VIGUEUR'"),
        ];
        // Le chemin du marqueur reste ici : une réponse publique renseigne sur
        // l'arborescence du serveur sans rien apporter à celui qui la lit.
        $result['legi']['last_update'] = marqueur('legi_last_update');
        $result['legi']['last_sync']   = marqueur('legi_last_sync');
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
        $result['eu']['last_update'] = marqueur('eu_last_update');
        $result['eu']['last_sync']   = marqueur('eu_last_sync');
        $db->close();
    } catch (Exception $e) {}

    // La couverture réelle vaut mieux qu'une date de synchronisation : elle dit
    // jusqu'où l'index permet de conclure à une absence.
    if (file_exists(JURIS_DB)) {
        try {
            $db = open_db(JURIS_DB);
            $couverture = juris_couverture($db);
            $result['jurisprudence'] = [
                'decisions'   => array_sum(array_column($couverture, 'decisions')),
                'couverture'  => $couverture,
                'cle_amont'   => getenv('SELFJUSTICE_JUDILIBRE_KEY') ? 'configurée' : 'absente',
                'last_update' => marqueur('judilibre_last_update'),
                'last_sync'   => marqueur('judilibre_last_sync'),
            ];
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

        // Un code que ni les alias ni la base ne connaissent est signalé comme
        // tel. Le laisser filtrer produisait « Article introuvable », qui
        // accuse la référence pour la faute du code — et envoie corriger ce
        // qui est juste.
        if ($code_filter && !isset($CODE_ALIASES[strtolower($code_filter)])
            && !preg_match('/^LEGITEXT\d+$/', $code_filter)) {
            json_error(
                "Code « $code_filter » inconnu. Utilise un alias ("
                . implode(', ', array_slice(array_keys($CODE_ALIASES), 0, 8))
                . ", …) ou un identifiant LEGITEXT. La référence « $ref » n'est "
                . "pas en cause.",
                400
            );
        }
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
            // 🔑 Aucun filtre de vigueur ici. Le limiter aux articles en vigueur
            // éteignait l'avertissement d'ambiguïté exactement quand il compte :
            // L122-14-3 existe au code de l'urbanisme et au code du travail, où
            // il porte la règle historique sur la régularité de la procédure de
            // licenciement. Les deux étant abrogés, aucun n'était signalé et
            // l'API en servait un sans dire que l'autre existait.
            $stmt2 = $db->prepare("SELECT DISTINCT code_id FROM articles WHERE num = :ref");
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
                        "SELECT SUBSTR(texte, 1, 150) AS apercu, etat, code_titre, date_fin
                           FROM articles
                          WHERE num = :ref AND code_id = :cid
                       ORDER BY (etat = 'VIGUEUR') DESC
                          LIMIT 1"
                    );
                    $alt_stmt->bindValue(':ref', $ref);
                    $alt_stmt->bindValue(':cid', $cid);
                    $alt_row = $alt_stmt->execute()->fetchArray(SQLITE3_ASSOC);
                    $alternatives[] = [
                        'code_id' => $cid,
                        'code'    => $alt_row['code_titre'] ?? '',
                        'etat'    => $alt_row['etat'] ?? '',
                        'date_fin' => $alt_row['date_fin'] ?? null,
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
            'renvoi'     => $has_texte ? article_renvoi($db, $row) : null,
            'en_vigueur' => $row['etat'] === 'VIGUEUR',
            'date_debut' => $row['date_debut'],
            'date_fin'   => $row['date_fin'],
            'code_id'    => $row['code_id'],
            'code_titre' => $code_titre,
            'texte'      => $has_texte ? ($row['texte'] ?? null) : null,
            'source'     => [
                'base'         => 'LEGI',
                'origine'      => 'Légifrance — dump officiel DILA',
                'last_update'  => marqueur('legi_last_update'),
                'last_sync'    => marqueur('legi_last_sync'),
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

        $pattern = '%' . SQLite3::escapeString($q) . '%';
        $stmt = $db->prepare("SELECT num, etat, code_id, code_titre, date_debut, date_fin
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
                'code'       => $row['code_titre'],
                'date_debut' => $row['date_debut'],
                'date_fin'   => $row['date_fin'],
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

        // Troisième passe : les articles qui ne sont plus en vigueur, après les
        // autres et jamais mélangés.
        //
        // 🔑 Sans elle, 365 477 des 525 092 articles étaient introuvables par
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
            $stmt = $db->prepare("SELECT num, etat, code_id, code_titre, date_debut, date_fin
                                  FROM articles
                                  WHERE num LIKE :pattern
                                    AND etat <> 'VIGUEUR'
                                  ORDER BY date_fin DESC, num
                                  LIMIT :limit");
            $stmt->bindValue(':pattern', $pattern);
            $stmt->bindValue(':limit', $reste, SQLITE3_INTEGER);

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
                    'code'       => $row['code_titre'],
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

    // /api/jurisprudence/verifier/{ref}?jurisdiction=cc|ca
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
        $juridiction = isset($_GET['jurisdiction']) ? strtolower($_GET['jurisdiction']) : null;

        // Date à laquelle l'appelant situe la décision. Facultative, mais c'est
        // elle qui rend l'absence opposable — voir la réserve, plus bas.
        $date_annoncee = isset($_GET['date']) ? trim($_GET['date']) : null;
        if ($date_annoncee !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_annoncee)) {
            json_error("Date « $date_annoncee » invalide. Format attendu : 2024-03-12.");
        }

        if ($juridiction !== null && !isset($couverture[$juridiction])) {
            $db->close();
            json_indetermine(
                "Juridiction « $juridiction » hors de l'index (couvertes : "
                . implode(', ', array_keys($couverture)) . "). La justice "
                . "administrative — Conseil d'État, tribunaux administratifs — "
                . "n'est pas dans Judilibre mais dans ArianeWeb.",
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
        $sql .= " ORDER BY d.decision_date DESC LIMIT " . LIMITE_DECISIONS;

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
        // Sans juridiction précisée, c'est celle qui s'arrête le plus tôt qui
        // commande — une décision plus récente échapperait à l'index.
        $retenues = $juridiction !== null
            ? [$couverture[$juridiction]]
            : array_values($couverture);
        $fins   = array_column($retenues, 'fin');
        $debuts = array_column($retenues, 'debut');
        $arret  = $fins ? min($fins) : null;
        $ouvre  = $debuts ? max($debuts) : null;

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
        // qu'on regarde trop court. La sonde ne part donc que là : rien
        // localement, une date annoncée, et cette date au-delà de la borne.
        // Partout ailleurs l'index local suffit et rien ne change.
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
        $correspond = (bool) $a_la_date;

        if (!$correspond && $date_annoncee !== null && $arret !== null && $date_annoncee > $arret) {
            // `search` refuse une requête qui ressemble à un numéro : en plein
            // texte il rendrait des milliers de faux positifs. Ici le numéro ne
            // décide de rien — il borne la requête à un seul jour, et c'est la
            // normalisation qui tranche, sur la règle exacte de l'index.
            $sonde = ['query' => $ref, 'date_start' => $date_annoncee,
                      'date_end' => $date_annoncee, 'page_size' => 50];
            if ($juridiction !== null) {
                $sonde['jurisdiction'] = $juridiction;
            }
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
        $reserve_borne = ($a_la_date || $amont) ? null : match (true) {
            $amont === [] => "Introuvable dans l'index local, arrêté au $arret. La base "
                . "amont Judilibre, interrogée directement au $date_annoncee, ne rend "
                . "rien non plus sous ce numéro : l'absence ne tient pas au retard de "
                . "l'index, elle est opposable.",
            $amont_echec !== null => "Introuvable, et la date annoncée ($date_annoncee) "
                . "est postérieure à l'arrêt de l'index local ($arret) : cette absence "
                . "ne prouve rien. La base amont, qui aurait tranché, n'a pas répondu — "
                . "$amont_echec",
            $arret === null => "Introuvable, et cet index ne déclare aucune couverture : "
                . "rien ne peut se conclure de cette absence.",
            $date_annoncee === null   => "Introuvable dans un index arrêté au $arret : "
                . "une décision postérieure ne peut pas être exclue.",
            $ouvre !== null && $date_annoncee < $ouvre => "Introuvable, mais la date "
                . "annoncée ($date_annoncee) précède le début de l'index ($ouvre) : "
                . "cette absence ne prouve rien.",
            default => "Introuvable, et la date annoncée ($date_annoncee) est couverte "
                . "par l'index ($ouvre → $arret) : si cette décision existait à cette "
                . "date, elle y figurerait.",
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
                ? "Cette décision ne figure pas encore dans l'index local, arrêté "
                . "au $arret : elle a été retrouvée dans la base amont Judilibre à "
                . "la date annoncée ($date_annoncee). Son existence est établie — "
                . "c'est l'index qui est en retard, pas la référence qui est fausse."
                : ($issues
                ? ($homonymes ? "Les " . count($homonymes) . " autre(s) décision(s) "
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
                : ($homonymes ? "Ce numéro existe — " . count($homonymes)
                    . " décision(s) le portent — mais aucune n'est datée du "
                    . "$date_annoncee. Un rôle général n'est unique qu'au sein d'une "
                    . "cour : la décision cherchée n'est pas celles-là. Voir "
                    . "« homonymes ». " : "")
                . $reserve_borne . " Périmètre limité à la "
                . "Cour de cassation et aux cours d'appel — la justice "
                . "administrative (Conseil d'État, CAA, TA) relève d'ArianeWeb et "
                . "n'y figurera jamais. Dire « introuvable », pas « n'existe pas »."),
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

        // 🔑 **Un argument refusé ne doit jamais ressembler à une réponse.**
        // `jurisdiction` partait tel quel vers l'amont, qui rendait un 400 — et
        // le client l'affichait « Aucune décision ne correspond ». Mesuré le
        // 21/08/2026 : `jurisdiction=ce` faisait passer une erreur de paramètre
        // pour un constat de fond sur le droit, alors que la même requête sans
        // filtre rendait 37 159 décisions. La route du catalogue nomme déjà ses
        // valeurs acceptées en cas de refus ; celle-ci ne disait rien.
        //
        // Le message nomme aussi l'exclusion : « ce » est la tentation
        // naturelle de qui cherche le Conseil d'État, et cette base ne couvre
        // pas la justice administrative.
        if (isset($params['jurisdiction'])) {
            $normalisee = juridiction_valide($params['jurisdiction']);
            if ($normalisee === null) {
                json_error(message_juridiction_inconnue($params['jurisdiction']), 400);
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
    if (count($segments) >= 3 && $segments[1] === 'decision') {
        $id = $segments[2];
        if (!preg_match('/^[a-f0-9]{16,40}$/i', $id)) {
            json_error("Identifiant de décision invalide : « $id »");
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
