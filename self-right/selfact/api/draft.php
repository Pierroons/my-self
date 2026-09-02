<?php
/**
 * SelfAct API — /act/api/draft
 *
 * Produit une page HTML prête à imprimer en PDF (Ctrl+P → Enregistrer en PDF).
 *
 * L'avertissement a deux étages, et ils n'ont pas la même portée :
 *   · la mention « NON OFFICIEL » dans le CORPS, en tête et au milieu, sur les
 *     seuls gabarits de cas C — ceux qui imitent la forme d'un acte juridique ;
 *   · le rappel en PIED (`.disclaimer`), sur TOUS les documents, qui porte la
 *     loi 71-1130 et dit ce que le document n'est pas.
 *
 * La classe vient de la clé `cas` du gabarit, jamais de la requête.
 *
 * Usage :
 *   GET /act/api/draft.php?type=mise_en_demeure
 *     Rend le gabarit à trous. Les crochets sont rendus éditables dans le
 *     navigateur par remplir.js : la personne complète sur place, imprime, et
 *     rien ne quitte sa machine.
 *
 * 🔑 POST refusé (405), volontairement.
 *   Le remplissage se fait dans le navigateur, et nulle part ailleurs. Recevoir
 *   ces données — identité, adresse, récit d'un litige — ferait de SelfAct un
 *   traitement de données personnelles sensibles, avec base légale,
 *   minimisation, conservation et sous-traitance à porter. Le corps de la
 *   requête n'est même pas lu : une route qui accepte finit par recevoir.
 *
 *   Ce que le service fait : mettre en forme ce que la personne fournit.
 *   Ce qu'il ne fait pas : deviner un champ, suggérer un article, formuler une
 *   demande à partir d'un récit. C'est la frontière de la loi 71-1130.
 *
 * Philosophie :
 * - Zéro dépendance externe. Pur PHP + HTML + CSS + SVG.
 * - La mention est du texte, pas une image : elle survit à la copie, au
 *   copier-coller et aux lecteurs d'écran, ce qu'un filigrane SVG ne fait pas.
 * - Elle est répétée trois fois — tête, milieu, pied — parce qu'un lecteur
 *   qui n'a lu qu'un tiers de la page en a quand même croisé une.
 * - Section "informations insuffisantes" mise en évidence si l'array
 *   `manques` est non-vide.
 */

declare(strict_types=1);

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Récupération des données : GET (exemple vide) ou POST (JSON)
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = [];

if ($method === 'POST') {
    // 🔑 Le remplissage se fait dans le navigateur, et nulle part ailleurs.
    // Accepter des données ici ferait de SelfAct un traitement de données
    // personnelles sensibles — identité, adresse, récit d'un litige — avec base
    // légale, minimisation, conservation et sous-traitance à porter. Le corps
    // n'est même pas lu : une route qui accepte finit par recevoir.
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: GET');
    echo json_encode([
        'ok'    => false,
        'error' => 'remplissage_local_uniquement',
        'detail' => "Ce service ne reçoit aucune donnée. Ouvre le gabarit en GET "
                  . "et complète-le dans ton navigateur : rien n'est transmis, "
                  . "rien n'est conservé.",
    ], JSON_UNESCAPED_UNICODE);
    exit;
} elseif ($method === 'GET') {
    $data = [
        'type' => $_GET['type'] ?? 'document',
        'expediteur' => ['nom' => '[Nom Prénom]', 'adresse' => '[Adresse complète]'],
        'destinataire' => ['nom' => '[Destinataire]', 'adresse' => '[Adresse]'],
        'objet' => '[Objet de la lettre]',
        'faits' => '[Chronologie des faits]',
        'articles' => [],
        'demande' => '[Action demandée]',
    ];

    // Mode démo "long" pour visualiser un document sur 2 pages
    if (($_GET['demo'] ?? '') === 'long') {
        $data = [
            'type' => $_GET['type'] ?? 'mise_en_demeure',
            'expediteur' => [
                'nom'     => 'Mme Sophie MARTIN',
                'adresse' => "17 rue des Lilas\n75011 Paris\ntél. : 06 12 34 56 78\nemail : sophie.martin.exemple@proton.me",
            ],
            'destinataire' => [
                'nom'     => "Monsieur le Directeur\nSARL BATIMENTS RAPIDES",
                'adresse' => "128 avenue des Artisans\nZAC du Clos Noir\n77100 Meaux",
            ],
            'objet' => "Mise en demeure de terminer les travaux de rénovation de toiture, de remettre en état les surfaces dégradées par vos équipes, et de régler les dommages consécutifs aux infiltrations d'eau constatées depuis le 14 mars 2026",
            'faits' => "Le 2 février 2026, j'ai signé avec votre société le devis n° 2026-DV-0412 (copie ci-jointe, pièce n° 1) pour la rénovation complète de la toiture de mon habitation principale, pour un montant de 14 800 € TTC. Le chantier devait débuter le 10 février et s'achever au plus tard le 10 mars 2026 selon l'article 4 du devis.\n\nLes équipes sont effectivement arrivées le 12 février avec deux jours de retard, et ont entamé le démontage de la couverture existante sans mettre en place les bâches de protection pourtant prévues au devis (point 3.2). Dès le 14 février, une forte pluie a occasionné des infiltrations dans les combles, dégradant l'isolation, les plafonds des deux chambres de l'étage, et du matériel de musique stocké dans le grenier (constat ci-joint, pièce n° 2).\n\nJ'ai immédiatement alerté votre conducteur de travaux, M. DURAND, qui a promis l'intervention sous 48h de l'entreprise d'assainissement partenaire. Cette intervention n'a jamais eu lieu malgré trois relances téléphoniques (les 15, 18 et 22 février), toutes consignées par écrit par SMS horodatés.\n\nLe chantier a été abandonné le 6 mars 2026 alors que seules 40% des tuiles avaient été posées. Aucune explication ne m'a été fournie ni par M. DURAND ni par la direction de votre société malgré deux courriels recommandés électroniques (les 7 et 14 mars, pièces n° 4 et 5).\n\nDepuis cette date, la maison n'est plus étanche et chaque épisode pluvieux aggrave les dégâts. Mon assureur habitation (contrat n° H2024-0991) menace de refuser toute prise en charge si je ne démontre pas la responsabilité de votre société d'ici le 20 mai 2026.\n\nJ'ai dû engager des travaux d'urgence (bâchage provisoire et évacuation d'eau) pour 1 280 € (facture ci-jointe, pièce n° 6). Les dégâts structurels sur l'isolation en laine minérale et les plafonds BA13 sont estimés par un artisan concurrent à 5 850 € (devis comparatif pièce n° 7). Le matériel de musique endommagé (un piano droit acheté 3 400 € en 2018, une guitare classique de luthier) est en cours d'expertise par un facteur d'instruments agréé.\n\nAu-delà du préjudice matériel, le stress généré par cette situation (trois enfants en bas âge, chambres inhabitables, hébergement temporaire chez mes parents pendant 12 nuits) constitue un trouble manifeste de jouissance dont il sera demandé réparation en cas de procédure contentieuse.",
            'articles' => [
                'art. 1103 C. civ. (force obligatoire du contrat)',
                'art. 1217 C. civ. (inexécution — droits du créancier)',
                'art. 1231-1 C. civ. (dommages-intérêts pour inexécution)',
                'art. 1344 C. civ. (mise en demeure)',
                'art. 1792 C. civ. (garantie décennale — dommages compromettant solidité)',
            ],
            'demande' => "achever les travaux conformément au devis signé, procéder aux réparations des dégâts consécutifs à votre négligence (plafonds, isolation, matériel endommagé, dont le détail figure en pièce n° 3), et me régler la somme de 8 430 € au titre des préjudices immédiatement chiffrables",
            'delai_jours' => 15,
            'manques' => [
                'Devis original signé non encore scanné (seule une copie photo brouillée est disponible)',
                'Expertise indépendante de chiffrage des dégâts (devis d\'un contre-expert pas encore obtenu)',
                'Relevés météo officiels de Meteo France pour les 14 et 15 février 2026 (à télécharger pour preuve)',
                'Confirmation écrite du refus de l\'assureur (pour l\'instant uniquement oral au téléphone)',
                'Photos datées des dégâts avant/après (3 photos existent mais non-horodatées par un tiers)',
            ],
        ];
    }
} else {
    http_response_code(405);
    exit;
}

$type          = $data['type']          ?? 'document';
$expediteur    = $data['expediteur']    ?? [];
$destinataire  = $data['destinataire']  ?? [];
$objet         = $data['objet']         ?? '';
$faits         = $data['faits']         ?? '';
$articles      = $data['articles']      ?? [];
$demande       = $data['demande']       ?? '';
$delai_jours   = $data['delai_jours']   ?? null;
$manques       = $data['manques']       ?? [];
$date          = $data['date']          ?? date('d F Y');
$mois_fr = [
    'January' => 'janvier', 'February' => 'février', 'March' => 'mars',
    'April' => 'avril', 'May' => 'mai', 'June' => 'juin',
    'July' => 'juillet', 'August' => 'août', 'September' => 'septembre',
    'October' => 'octobre', 'November' => 'novembre', 'December' => 'décembre',
];
$date = strtr($date, $mois_fr);

// 🔑 Les intitulés viennent de `data/gabarits.json`, comme ceux que sert
// `/act/api/gabarits` et que lit l'outil MCP. Ils étaient écrits ici ET là-bas :
// deux tables pour une seule vérité, qui divergeaient déjà d'une entrée — « Dépôt
// de plainte » d'un côté, « Dépôt de plainte simple » de l'autre.
//
// Le repli couvre le cas où le fichier manque : un gabarit sans intitulé vaut
// mieux qu'une page blanche, et le seul type alors accepté est le neutre.
$table = json_decode((string) @file_get_contents(__DIR__ . '/data/gabarits.json'), true);
$type_labels = [];
$type_cas    = [];
$type_titre_faits = [];
// Sept gabarits sur huit mettent des faits dans le champ `faits` ; les
// directives post-mortem y font lister des comptes. Le titre par défaut vaut
// donc pour la règle, et le gabarit dit l'exception.
$titre_faits_defaut = 'Rappel des faits';
foreach (($table['gabarits'] ?? []) as $cle => $g) {
    $type_labels[$cle] = $g['label'] ?? $cle;
    // 🔑 La classe A/B/C vient du GABARIT, jamais de la requête. Un `?cas=B`
    // accepté ici ferait de l'avertissement une option du demandeur : il
    // suffirait de le réclamer pour obtenir un document sans mention. Le défaut
    // est « C » — un gabarit non classé est traité comme le plus exposé, pas
    // comme le moins.
    $type_cas[$cle] = ($g['cas'] ?? 'C') === 'B' ? 'B' : 'C';
    // 🔑 Le titre vient du GABARIT, comme la classe — et pour la même raison :
    // ce que le document imprime doit être ce que le gabarit annonce. `champs`
    // est exposé au modèle par `gabarits.php`, donc l'écart traverse l'API
    // avant de se voir à l'impression.
    $type_titre_faits[$cle] = $g['titre_faits'] ?? $titre_faits_defaut;
}
if (!$type_labels) {
    $type_labels = ['document' => 'Projet de courrier'];
}
// 🔑 Le refus d'un type inconnu existait, mais dans l'outil MCP seulement.
// L'URL, elle, est publiée à l'utilisateur : appelée avec `type=inexistant_xyz`
// elle rendait un 200 et un « Projet de courrier » vide, c'est-à-dire un
// document d'apparence normale pour une demande qui n'avait pas de sens. Un
// garde-fou posé chez l'appelant ne protège que l'appelant qui le porte.
// Mesuré le 22/08/2026 par un contrôle extérieur.
//
// Absent vaut défaut, présent et inconnu vaut refus — c'est déjà le régime de
// `distance` dans deadline.php, pour la même raison : une valeur mal
// orthographiée ne doit pas retomber en silence sur autre chose.
if (isset($_GET['type']) && !isset($type_labels[$_GET['type']])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'ok'       => false,
        'error'    => 'type_inconnu',
        'detail'   => "Type de document « {$_GET['type']} » inconnu. Rien n'a été "
                    . "produit : un gabarit générique aurait ressemblé à une réponse.",
        'acceptes' => array_keys($type_labels),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$type_label = $type_labels[$type];

// 🔑 La mention « NON OFFICIEL » ne couvre que le cas C — les documents qui
// imitent la forme d'un acte juridique. Un courrier amiable n'imite rien : la
// signaler « non officielle » ne répond à aucune confusion possible, et un
// avertissement qui se répète là où il n'a pas lieu d'être finit par ne plus
// être lu là où il compte. Le bloc `.disclaimer` du pied, lui, reste sur TOUS
// les documents : c'est lui qui porte le fond sur les cas B.
$porte_mention = ($type_cas[$type] ?? 'C') === 'C';
$titre_faits   = $type_titre_faits[$type] ?? $titre_faits_defaut;

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($type_label) ?> — SelfAct<?= $porte_mention ? ' (NON OFFICIEL)' : '' ?></title>
<style>
  @page {
    size: A4;
    margin: 1.8cm 1.8cm;
  }

  html, body {
    margin: 0;
    padding: 0;
    font-family: "Georgia", "Times New Roman", serif;
    font-size: 10.5pt;
    line-height: 1.45;
    color: #000;
    background: #fff;
  }

  /* Layout global — dimensions A4 réelles en écran, auto en impression */
  .page {
    margin: 0 auto;
    position: relative;
    background: #fff;
  }

  /* 🔑 La mention remplace le filigrane SVG depuis le 02/09/2026. Le filigrane
     couvrait le texte qu'il protégeait : il rendait le corps du document
     difficile à lire, et un avertissement illisible n'avertit personne.
     La mention dit la même chose en toutes lettres, en tête et au milieu du
     corps — deux passages obligés du regard, sur les cas C. Le pied de page
     est un bloc à part, `.disclaimer`, qui reste sur tous les documents. */
  .mention {
    margin: 0.3cm 0;
    padding: 0.15cm 0.3cm;
    border-left: 3px solid #c81e1e;
    background: #fdf2f2;
    color: #7a1c1c;
    font-size: 8pt;
    line-height: 1.45;
  }
  .mention strong { color: #a01414; }

  /* Remplissage local — voir remplir.js. Le bandeau guide à l'écran et
     disparaît à l'impression ; les champs perdent leur fond mais gardent leur
     texte, pour que le document imprimé soit propre. */
  .bandeau-remplissage {
    max-width: 21cm; margin: 12px auto; padding: 12px 16px;
    background: #eef4ff; border: 1px solid #b9cdf0; border-radius: 6px;
    font: 14px/1.5 system-ui, sans-serif; color: #12325c;
  }
  .champ-a-remplir {
    background: #fff6d5; outline: 1px dashed #c9a227; padding: 0 2px;
    border-radius: 2px; cursor: text; min-width: 3em; display: inline-block;
  }
  .champ-a-remplir:focus { background: #fffdf0; outline: 2px solid #c9a227; }

  @media print {
    .bandeau-remplissage { display: none !important; }
    .champ-a-remplir { background: none !important; outline: none !important; }
  }

  @media print {
    .toolbar { display: none !important; }
    body { background: #fff; padding: 0 !important; }
    .page { padding: 0 !important; }
  }
  @media screen {
    body {
      background: #e0e0e0;
      padding: 2rem 1rem;
      overflow-x: auto;  /* scroll horizontal si viewport < 21cm */
    }
    /* En écran : vraies dimensions A4, avec indicateur visuel de coupure
       toutes les 29.7 cm (là où l'impression va casser en page suivante) */
    .page {
      width: 21cm;              /* A4 largeur */
      min-height: 29.7cm;       /* A4 hauteur minimum */
      padding: 1.8cm 1.8cm;     /* mêmes marges que @page */
      margin: 1rem auto 2rem;
      box-shadow: 0 4px 20px rgba(0,0,0,0.15);
      border: 1px solid #ccc;
      box-sizing: border-box;

      /* Ligne pointillée tous les 29.7cm pour matérialiser les coupures
         A4 à l'impression. Non imprimée (cachée par @media print). */
      background-image: repeating-linear-gradient(
        to bottom,
        transparent 0,
        transparent calc(29.7cm - 2px),
        #b81e1e calc(29.7cm - 2px),
        #b81e1e 29.7cm,
        transparent 29.7cm,
        transparent calc(29.7cm + 18px),
        #999 calc(29.7cm + 18px),
        #999 calc(29.7cm + 19px),
        transparent calc(29.7cm + 19px)
      );
      background-repeat: repeat-y;
      background-size: 100% calc(29.7cm + 20px);
    }
  }
  @media print {
    .page {
      background-image: none !important;  /* supprime la ligne de coupure à l'impression */
    }
  }

  /* Toolbar d'impression (écran seulement) */
  .toolbar {
    width: 21cm;
    max-width: 100%;
    margin: 0 auto 1.5rem;
    padding: 1rem 1.5rem;
    background: #fff8dc;
    border: 2px solid #d4a017;
    border-radius: 6px;
    font-family: sans-serif;
  }
  .toolbar h2 { margin: 0 0 0.5rem; font-size: 1rem; color: #8a6010; }
  .toolbar p { margin: 0.3rem 0; font-size: 0.9rem; color: #333; }
  .toolbar .print-hint {
    margin-top: 0.8rem;
    padding: 0.6rem 1rem;
    background: rgba(255,255,255,0.5);
    border: 1px solid #d4a017;
    border-radius: 4px;
    font-size: 0.95rem;
    color: #333;
    display: inline-block;
  }
  .toolbar .kbd {
    display: inline-block;
    padding: 0.15rem 0.5rem;
    background: #333;
    color: #fff;
    border-radius: 3px;
    font-family: "Courier New", monospace;
    font-size: 0.85rem;
    font-weight: bold;
    box-shadow: 0 2px 0 #222;
    vertical-align: baseline;
  }
  .toolbar button {
    background: #d4a017;
    color: #fff;
    border: none;
    padding: 0.6rem 1.4rem;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.95rem;
    font-weight: 600;
    margin-top: 0.6rem;
  }
  .toolbar button:hover { background: #b08a14; }

  /* Contenu */
  h1 {
    font-size: 14pt;
    margin: 0 0 0.4cm;
    text-align: center;
    color: #000;
  }
  .ref { text-align: right; font-size: 9pt; color: #666; margin-bottom: 0.3cm; }
  .bloc-parties {
    display: flex;
    justify-content: space-between;
    gap: 1cm;
    margin-bottom: 0.3cm;
  }
  .bloc-parties .expe,
  .bloc-parties .dest { flex: 1; }
  .bloc-parties .dest { text-align: right; }
  .date-lieu { margin: 0.2cm 0; }
  .objet { font-weight: bold; margin: 0.3cm 0; }
  .corps p { text-align: justify; margin: 0.15cm 0; }
  .corps h3 {
    font-size: 10.5pt;
    margin-top: 0.2cm;
    margin-bottom: 0.1cm;
    font-weight: bold;
  }
  .signature { margin-top: 0.5cm; text-align: right; }
  .articles-cites {
    margin: 0.2cm 0;
    padding: 0.15cm 0.4cm;
    background: #f9f9f9;
    border-left: 3px solid #888;
    font-size: 9.5pt;
  }

  /* Section manques (checklist informations insuffisantes) */
  .manques {
    margin-top: 0.4cm;
    padding: 0.25cm 0.5cm;
    border: 2px solid #c72525;
    background: #fff0f0;
    page-break-inside: avoid;
  }
  .manques h3 {
    color: #c72525;
    font-size: 10.5pt;
    margin: 0 0 0.15cm;
  }
  .manques ul { margin: 0; padding-left: 1.5em; font-size: 9.5pt; }
  .manques li { margin: 0.05cm 0; }
  .manques li::marker { content: "☐  "; color: #c72525; }

  /* Disclaimer bas de page */
  .disclaimer {
    margin-top: 0.4cm;
    padding: 0.15cm 0.3cm;
    font-size: 7.5pt;
    color: #666;
    font-style: italic;
    border-top: 1px solid #ccc;
    line-height: 1.4;
  }
</style>
</head>
<body>

<!-- Toolbar d'impression (écran seulement) -->
<div class="toolbar">
  <h2>📄 Document d'aide à la rédaction<?= $porte_mention ? ' (NON OFFICIEL)' : '' ?></h2>
<?php if ($porte_mention): ?>
  <p>Ce document porte la mention « NON OFFICIEL » en tête et au milieu, et le rappel en pied.
  Il sert uniquement de <strong>brouillon structuré</strong> à recomposer (ou à faire valider par un
  professionnel) avant tout envoi formel.</p>
<?php else: ?>
  <p>Ce document est un <strong>brouillon structuré</strong> de courrier, à recomposer (ou à faire
  valider par un professionnel) avant tout envoi formel. Le rappel en pied de page dit ce qu'il
  n'est pas.</p>
<?php endif; ?>
  <p><strong>Pour l'imprimer en PDF :</strong> Ctrl+P (ou ⌘+P sur Mac) → « Enregistrer au format PDF ».
  Ce qui est affiché reste visible à l'impression.</p>
  <button type="button" id="btn-print">🖨 Imprimer / Enregistrer en PDF</button>
  <div class="print-hint" style="margin-top:0.5rem">
    ou <span class="kbd">Ctrl</span> + <span class="kbd">P</span>
    <span style="font-size:0.8rem;color:#666;margin-left:0.3rem">(⌘ + P sur Mac)</span>
  </div>
</div>
<script src="/act/api/print.js"></script>
<script src="/act/api/remplir.js"></script>

<!-- Page du courrier -->
<div class="page">
<?php if ($porte_mention): ?>
  <div class="mention"><strong>Document non officiel.</strong> Produit par un outil de mise en forme, il n'est pas un acte juridique et n'est recevable nulle part en l'état. Il ne remplace ni un conseil juridique au sens de la loi 71-1130 du 31 décembre 1971, ni un modèle officiel de service-public.gouv.fr.</div>
<?php endif; ?>

  <div class="bloc-parties">
    <div class="expe">
      <strong><?= nl2br(h($expediteur['nom'] ?? '[Nom Prénom de l\'expéditeur]')) ?></strong><br>
      <?= nl2br(h($expediteur['adresse'] ?? '[Adresse complète]')) ?>
    </div>
    <div class="dest">
      <?= nl2br(h($destinataire['nom'] ?? '[Destinataire]')) ?><br>
      <?= nl2br(h($destinataire['adresse'] ?? '[Adresse]')) ?>
    </div>
  </div>

  <div class="date-lieu">
    [Ville], le <?= h($date) ?>
  </div>

  <div class="objet">
    <strong>Objet :</strong> <?= h($objet ?: '[Objet du courrier]') ?>
    <?php if ($type === 'mise_en_demeure'): ?>
      <br><strong>Lettre recommandée avec accusé de réception</strong>
    <?php endif; ?>
  </div>

  <div class="corps">
    <p>Madame, Monsieur,</p>

    <?php if ($type === 'mise_en_demeure'): ?>
      <p>Par la présente, <strong>je vous mets en demeure</strong>, au sens de l'article 1344
      du Code civil, de <strong><?= h($demande ?: '[action attendue]') ?></strong>
      <?php if ($delai_jours): ?>
        dans un délai de <strong><?= (int) $delai_jours ?> jours</strong> à compter de la réception de la présente.
      <?php else: ?>
        dans les meilleurs délais.
      <?php endif; ?>
      </p>
    <?php endif; ?>

    <?php if ($type === 'directives_donnees_post_mortem'): ?>
      <p>En application de l'article 85 de la loi n° 78-17 du 6 janvier 1978, je définis
      ci-après mes directives relatives à la conservation, à l'effacement et à la
      communication de mes données à caractère personnel après mon décès.</p>

      <p>Ces directives sont <strong>particulières</strong> : elles concernent le traitement
      mis en œuvre par <strong>[Service concerné]</strong>. Elles font l'objet de mon
      consentement spécifique et ne résultent pas de la seule approbation des conditions
      générales d'utilisation.</p>

      <p>Sort que je demande pour ces données : <strong>[Sort demandé pour les données]</strong>.</p>

      <p>Je désigne <strong>[Personne chargée de l'exécution]</strong> pour prendre
      connaissance de ces directives à mon décès et en demander la mise en œuvre. À défaut,
      mes héritiers ont cette qualité.</p>

      <p>Je peux modifier ou révoquer ces directives à tout moment.</p>

      <div class="articles-cites">
        <strong>Ce document n'est pas un testament.</strong> L'article 970 du code civil exige
        qu'un testament olographe soit écrit en entier, daté et signé <em>de la main</em> du
        testateur : un document dactylographié signé à la main est nul comme testament. Si tu
        veux désigner par testament la personne chargée de l'exécution, recopie ce passage
        à la main sur une feuille séparée. Les directives de l'article 85, elles, ne sont
        soumises à aucune condition de forme et peuvent rester dactylographiées.
      </div>
    <?php endif; ?>

    <?php if ($faits): ?>
      <h3><?= h($titre_faits) ?></h3>
      <p><?= nl2br(h($faits)) ?></p>
    <?php endif; ?>

    <?php if (!empty($articles)): ?>
      <div class="articles-cites">
        <strong>Fondement juridique :</strong>
        <?= h(implode(' ; ', (array) $articles)) ?>
      </div>
    <?php endif; ?>

    <?php if ($type === 'mise_en_demeure'): ?>
      <p>À défaut de régularisation dans le délai imparti, je me réserve le droit de saisir
      la juridiction compétente pour obtenir l'exécution de mes droits, ainsi que tous
      dommages-intérêts et intérêts moratoires au taux légal à compter du jour de la
      présente mise en demeure (art. 1231-6 C. civ.).</p>
    <?php endif; ?>

    <p>Je vous prie d'agréer, Madame, Monsieur, l'expression de mes salutations distinguées.</p>
  </div>

<?php if ($porte_mention): ?>
  <div class="mention"><strong>Document non officiel.</strong> Produit par un outil de mise en forme, il n'est pas un acte juridique et n'est recevable nulle part en l'état. Il ne remplace ni un conseil juridique au sens de la loi 71-1130 du 31 décembre 1971, ni un modèle officiel de service-public.gouv.fr.</div>
<?php endif; ?>

  <div class="signature">
    [Signature]<br>
    <?= h($expediteur['nom'] ?? '[Nom Prénom]') ?>
  </div>

  <?php if (!empty($manques)): ?>
    <div class="manques">
      <h3>⚠ Informations insuffisantes — à compléter avant envoi</h3>
      <p style="font-size:10pt;margin:0 0 0.2cm">
        Ce document ne peut pas être envoyé en l'état. Les éléments suivants manquent
        pour qu'il soit juridiquement solide :
      </p>
      <ul>
        <?php foreach ($manques as $m): ?>
          <li><?= h($m) ?></li>
        <?php endforeach; ?>
      </ul>
      <p style="font-size:10pt;margin:0.3cm 0 0">
        Quand ces éléments seront collectés, recompose le courrier à la main sur ton papier
        personnel, ou fais relire par un avocat / permanence gratuite
        (<a href="https://www.point-justice.gouv.fr">point-justice.gouv.fr</a>).
      </p>
    </div>
  <?php endif; ?>

  <div class="disclaimer">
    Document généré par <strong>SelfAct</strong>,
    un outil open-source de formatage d'aide à la rédaction. Ce document n'est PAS OFFICIEL.
    Il ne constitue pas un acte juridique recevable en l'état. Il ne saurait remplacer un
    conseil juridique au sens de la loi 71-1130 du 31 décembre 1971. Pour un acte officiel,
    utilise le modèle service-public.fr correspondant ou consulte un avocat.
    <br><br>
    <strong>SelfAct est indépendant et n'est affilié à aucun organisme public ou
    gouvernemental.</strong> Les formulaires, modèles de lettres et démarches officiels
    sont disponibles <strong>gratuitement</strong> sur
    <a href="https://www.service-public.gouv.fr">service-public.gouv.fr</a> :
    tu n'as jamais besoin de cet outil pour y accéder.
  </div>
</div>

</body>
</html>
