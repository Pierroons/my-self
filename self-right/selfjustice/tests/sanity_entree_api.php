<?php
/**
 * Garde-fou — ce que l'API accepte de chercher, et où elle le cherche.
 *
 * 🔑 Ce module existe à cause de deux réponses justes en apparence et fausses
 * au fond, mesurées le 20/08/2026 sur l'instance de production.
 *
 * La première : `' OR 1=1--` rendait trois articles réels du Code de la
 * sécurité sociale, en HTTP 200. Aucune injection n'avait pris — les requêtes
 * préparées tiennent, et `'; DROP TABLE articles;--` rend une liste vide sans
 * que rien ne tombe. Mais FTS5 tokenise la chaîne en « or » et « 1 », trouve
 * des correspondances, et l'appelant reçoit du droit en réponse à une entrée
 * qui n'en demandait pas. Côté jurisprudence, `1=1` rendait 20 921 décisions.
 *
 * La seconde : chercher dans le sommaire avec `jurisdiction=ca` rendait 0
 * résultat contre 117 731 sur le même index en texte intégral, parce que les
 * sommaires sont rédigés par la Cour de cassation pour ses propres arrêts et
 * qu'une décision de cour d'appel n'en a pas. Le zéro partait sans réserve.
 *
 * Les deux décisions sont des fonctions pures : ni base, ni clé d'amont, ni
 * réseau. Elles s'éprouvent donc en CI, là où le reste de l'API ne le peut pas.
 *
 *     php tests/sanity_entree_api.php
 */

declare(strict_types=1);

// Charger les deux fonctions sans déclencher le routage : `api.php` répond dès
// qu'il est inclus.
$src = file_get_contents(__DIR__ . '/../api/api.php');
if ($src === false) {
    fwrite(STDERR, "api/api.php illisible\n");
    exit(2);
}
foreach (['requete_cherchable', 'champ_juris', 'mots_cherchables', 'chercher_conventionnalite',
          'juridiction_valide', 'message_juridiction_inconnue'] as $nom) {
    if (!preg_match('/^function ' . $nom . '\(.*?^}$/ms', $src, $m)) {
        fwrite(STDERR, "$nom introuvable dans api/api.php\n");
        exit(2);
    }
    eval($m[0]);
}

$echecs = 0;

function verdict(bool $ok, string $libelle): void {
    global $echecs;
    if (!$ok) {
        $echecs++;
    }
    printf("  %s %s\n", $ok ? '✓' : '✗', $libelle);
}

echo "▸ Une requête contient-elle de quoi chercher ?\n";

// Refusées : rien à chercher dedans.
foreach (["' OR 1=1--", '1=1', '1 2 3', 'a b c', '- - -', '...'] as $q) {
    verdict(!requete_cherchable($q), "refusée : « $q »");
}

// Acceptées. Les quatre premières n'ont aucun mot : la recherche par numéro
// d'article est le cas d'usage principal de LEGI et ne doit pas tomber avec le
// reste. « union » et « select » sont des mots français avant d'être du SQL —
// l'Union européenne se cherche par son nom.
foreach (['1240', 'L122-14', 'R611-21', 'L. 3141-1', 'TVA', '16 bis',
          'harcèlement moral', "%' UNION SELECT 1,2,3--"] as $q) {
    verdict(requete_cherchable($q), "acceptée : « $q »");
}

echo "\n▸ Quel champ chercher, pour quelle juridiction\n";

$attendus = [
    // [champ demandé, juridiction, champ interrogé, réserve attendue]
    [null,      'ca', 'text',    true,  'sans champ + cour d\'appel → bascule sur le texte'],
    [null,      'CA', 'text',    true,  'la casse de la juridiction ne change rien'],
    ['summary', 'ca', 'summary', true,  'sommaire demandé à la main → respecté, et expliqué'],
    ['text',    'ca', 'text',    false, 'texte demandé → rien à signaler'],
    ['themes',  'ca', 'themes',  false, 'un autre champ que le sommaire → intact'],
    [null,      'cc', 'summary', false, 'cassation → le sommaire reste le défaut'],
    ['summary', 'cc', 'summary', false, 'cassation + sommaire → rien à signaler'],
    [null,      null, 'summary', false, 'sans juridiction → le sommaire reste le défaut'],
];
foreach ($attendus as [$demande, $juri, $champ_attendu, $reserve_attendue, $libelle]) {
    [$champ, $reserve] = champ_juris($demande, $juri);
    verdict($champ === $champ_attendu && (($reserve !== null) === $reserve_attendue), $libelle);
}

echo "\n▸ Une juridiction inconnue est refusée, pas filtrée vers le vide\n";

// 🔑 `jurisdiction` partait tel quel vers l'amont, qui rendait un 400 — et le
// client l'affichait « Aucune décision ne correspond ». Une erreur de paramètre
// prenait l'apparence d'un constat de fond, alors que la même requête sans
// filtre rendait 37 159 décisions. « ce » est la tentation naturelle de qui
// cherche le Conseil d'État.
// ⚠️ On interroge la fonction du code, jamais une copie de sa règle : une
// première version rejouait le `in_array` dans le test, et aurait donc été
// verte quel que soit le comportement réel de l'API.
foreach ([['cc', 'cc'], [' CA ', 'ca'], ['ce', null], ['ta', null], ['xx', null]] as [$j, $attendu]) {
    $obtenu = juridiction_valide($j);
    verdict($obtenu === $attendu, "« $j » → " . ($obtenu ?? 'refusée'));
}
$msg = message_juridiction_inconnue('ce');
foreach (['cc', 'ca', "Conseil d'État", 'ArianeWeb'] as $attendu) {
    verdict(str_contains($msg, $attendu), "le refus nomme « $attendu »");
}

echo "\n▸ Quelles formes une requête fait-elle chercher\n";

// 🔑 « personnelles » ne se trouve que dans 4 articles de la base quand
// « personnel » est dans 93 : les textes écrivent « données à caractère
// personnel », jamais « données personnelles ». Le plancher de six lettres est
// mesuré — à cinq, « traitant » devient « trait » et attrape « traitement ».
$formes = [
    ['données personnelles', [['données','donnée'], ['personnelles','personnel']], 'le pluriel féminin retombe sur la forme des textes'],
    ['sous-traitant',        [['sous','sous'], ['traitant','traita']],             'six lettres, pas cinq : « traita » et non « trait »'],
    ['transfert hors UE',    [['transfert','transf'], ['hors','hors']],            'un mot de deux lettres ne porte rien, il tombe'],
    // 11 lettres → max(6, 8) = 8. La coupe compte des CARACTÈRES : « harcèlem »
    // fait bien huit lettres, là où huit octets tomberaient au milieu du « è ».
    ['harcèlement',          [['harcèlement','harcèlem']],                         'la coupe compte les lettres, pas les octets'],
    ['art 8',                [['art','art']],                                      'les chiffres isolés tombent, la référence est cherchée à part'],
    ['portabilité',          [['portabilité','portabil']],                         'un mot long garde huit lettres sur onze'],
];
foreach ($formes as [$q, $attendu, $libelle]) {
    $obtenu = mots_cherchables($q);
    $vu = implode(' · ', array_map(fn($c) => $c[0] === $c[1] ? $c[0] : "{$c[0]}→{$c[1]}", $obtenu));
    verdict($obtenu === $attendu, "« $q » → $vu — $libelle");
}

echo "\n▸ De bout en bout, sur une base de contrefaçon\n";

// Les textes réels de la base emploient « données à caractère personnel ».
// Une recherche qui ne trouve que les citations verbatim les manque tous.
$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE articles (id TEXT, source TEXT, num TEXT, titre TEXT, texte TEXT, date_debut TEXT)');
$inserts = [
    ['RGPD', '4',  'Définitions', 'On entend par « données à caractère personnel » toute information se rapportant à une personne physique identifiée.'],
    ['RGPD', '28', 'Sous-traitant', 'Le traitement par un sous-traitant est régi par un contrat.'],
    ['RGPD', '17', 'Droit à l\'effacement', 'La personne concernée a le droit d\'obtenir l\'effacement, dit droit à l\'oubli.'],
    ['CEDH', '8',  'Vie privée', 'Toute personne a droit au respect de sa vie privée et familiale.'],
];
foreach ($inserts as $i => [$src, $num, $titre, $texte]) {
    $st = $db->prepare('INSERT INTO articles VALUES (:id, :s, :n, :t, :x, "2016-04-27")');
    $st->bindValue(':id', "a$i"); $st->bindValue(':s', $src); $st->bindValue(':n', $num);
    $st->bindValue(':t', $titre); $st->bindValue(':x', $texte);
    $st->execute();
}

$cas = [
    ['données personnelles', null, 1, '« données personnelles » retrouve « données à caractère personnel »'],
    ['sous-traitant',        null, 1, '« sous-traitant » ne ramène pas tout ce qui parle de traitement'],
    ['droit oubli',          null, 1, 'deux mots épars dans la phrase se retrouvent quand même'],
    ['vie privée',           null, 1, 'une locution présente telle quelle se retrouve aussi'],
    ['banane saxophone',     null, 0, 'des mots absents ne ramènent rien — le zéro reste possible'],
    ['données personnelles', 'CEDH', 0, 'le filtre de source s\'applique après la recherche'],
    ['8',                    null, 1, 'un numéro seul passe par la référence, pas par les mots'],
];
foreach ($cas as [$q, $src, $attendu, $libelle]) {
    [$total, $res, $mots] = chercher_conventionnalite($db, $q, $src, 20);
    verdict(
        $total === $attendu && count($res) === min($attendu, 20),
        "$libelle — total=$total (attendu $attendu), formes : "
        . implode(' · ', array_map(fn($c) => $c[1], $mots))
    );
}

// ⚠️ `total` compte ce qui existe, `count` ce qu'on rend. Les confondre faisait
// passer une limite d'affichage pour une réponse.
[$total, $res, ] = chercher_conventionnalite($db, 'personne', null, 2);
verdict($total === 3 && count($res) === 2,
        "la limite borne l'affichage, pas le compte — total=$total, rendus=" . count($res));

echo "\n";
if ($echecs > 0) {
    echo "✗ $echecs contrôle(s) en échec\n";
    exit(1);
}
echo "✓ tous les contrôles passent\n";
