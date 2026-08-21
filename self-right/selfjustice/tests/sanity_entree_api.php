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
foreach (['requete_cherchable', 'champ_juris'] as $nom) {
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

echo "\n";
if ($echecs > 0) {
    echo "✗ $echecs contrôle(s) en échec\n";
    exit(1);
}
echo "✓ tous les contrôles passent\n";
