<?php
/**
 * SelfAct API — /act/api/draft
 *
 * Produit une page HTML prête à imprimer en PDF (Ctrl+P → Enregistrer en PDF)
 * avec un filigrane SVG diagonal « NON OFFICIEL — IRRECEVABLE » non supprimable
 * par CSS média print. Le filigrane couvre chaque page imprimée du document.
 *
 * Usage :
 *   POST /act/api/draft.php    (Content-Type: application/json)
 *     Body :
 *       {
 *         "type": "mise_en_demeure",
 *         "expediteur": { "nom": "...", "adresse": "..." },
 *         "destinataire": { "nom": "...", "adresse": "..." },
 *         "objet": "...",
 *         "faits": "...",
 *         "articles": ["art. 1344 C. civ."],
 *         "demande": "...",
 *         "delai_jours": 15,
 *         "manques": ["Date précise des faits", "Montant exact"]  // optionnel
 *       }
 *     Response : text/html (à ouvrir dans navigateur → Ctrl+P → PDF)
 *
 *   GET /act/api/draft.php?type=mise_en_demeure (retourne un exemple vide
 *     prêt à être rempli à la main, pour test/démo)
 *
 * Philosophie :
 * - Zéro dépendance externe. Pur PHP + HTML + CSS + SVG.
 * - Le filigrane est un SVG inline avec `@media print { display: block; }`
 *   donc imprimable par tous les navigateurs modernes.
 * - Le filigrane est intentionnellement sur toute la page, rotation -45°,
 *   rouge transparent 35% pour rester lisible tout en marquant clairement
 *   le statut non officiel du document.
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
    $raw = file_get_contents('php://input');
    $parsed = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($parsed)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'invalid_json']);
        exit;
    }
    $data = $parsed;
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

$type_labels = [
    'mise_en_demeure'        => 'Mise en demeure',
    'saisine_conciliateur'   => 'Saisine du conciliateur de justice',
    'plainte_simple'         => 'Dépôt de plainte',
    'saisine_defenseur'      => 'Saisine du Défenseur des droits',
    'recours_gracieux'       => 'Recours gracieux',
    'resiliation'            => 'Résiliation de contrat',
    'document'               => 'Projet de courrier',
];
$type_label = $type_labels[$type] ?? 'Projet de courrier';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($type_label) ?> — SelfAct (NON OFFICIEL)</title>
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

  /* Layout global — padding ÉCRAN only, pas à l'impression (@page gère) */
  .page {
    max-width: 720px;
    margin: 0 auto;
    position: relative;
    background: #fff;
  }

  /* Filigrane SVG — visible à l'écran ET à l'impression */
  .watermark {
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    pointer-events: none;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .watermark svg {
    width: 100%;
    height: 100%;
  }
  .watermark text {
    fill: rgba(200, 30, 30, 0.25);
    font-family: "Arial Black", sans-serif;
    font-weight: 900;
  }

  @media print {
    .toolbar { display: none !important; }
    .watermark { position: fixed !important; }
    body { background: #fff; padding: 0 !important; }
    .page { padding: 0 !important; }
  }
  @media screen {
    body {
      background: #e0e0e0;
      padding: 2rem 1rem;
    }
    /* En écran : padding + ombre pour simuler une feuille A4 */
    .page {
      padding: 2cm 2cm;
      margin-top: 1rem;
      margin-bottom: 2rem;
      box-shadow: 0 4px 20px rgba(0,0,0,0.1);
      border: 1px solid #ccc;
    }
  }

  /* Toolbar d'impression (écran seulement) */
  .toolbar {
    max-width: 720px;
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
  <h2>📄 Document d'aide à la rédaction (NON OFFICIEL)</h2>
  <p>Ce document est marqué « NON OFFICIEL — IRRECEVABLE » par un filigrane non-supprimable.
  Il sert uniquement de <strong>brouillon structuré</strong> à recomposer (ou à faire valider par un
  professionnel) avant tout envoi formel.</p>
  <p><strong>Pour l'imprimer en PDF :</strong> Ctrl+P (ou ⌘+P sur Mac) → « Enregistrer au format PDF ».
  Le filigrane reste visible à l'impression.</p>
  <button type="button" id="btn-print">🖨 Imprimer / Enregistrer en PDF</button>
  <div class="print-hint" style="margin-top:0.5rem">
    ou <span class="kbd">Ctrl</span> + <span class="kbd">P</span>
    <span style="font-size:0.8rem;color:#666;margin-left:0.3rem">(⌘ + P sur Mac)</span>
  </div>
</div>
<script src="/act/api/print.js"></script>

<!-- Filigrane SVG sur toutes les pages (fixed positioning) -->
<div class="watermark" aria-hidden="true">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 1200" preserveAspectRatio="none">
    <g transform="rotate(-35 400 600)">
      <text x="400" y="300" text-anchor="middle" font-size="60">NON OFFICIEL</text>
      <text x="400" y="500" text-anchor="middle" font-size="60">IRRECEVABLE</text>
      <text x="400" y="700" text-anchor="middle" font-size="60">NON OFFICIEL</text>
      <text x="400" y="900" text-anchor="middle" font-size="60">IRRECEVABLE</text>
      <text x="400" y="1100" text-anchor="middle" font-size="60">NON OFFICIEL</text>
    </g>
  </svg>
</div>

<!-- Page du courrier -->
<div class="page">
  <div class="bloc-parties">
    <div class="expe">
      <strong><?= h($expediteur['nom'] ?? '[Nom Prénom de l\'expéditeur]') ?></strong><br>
      <?= nl2br(h($expediteur['adresse'] ?? '[Adresse complète]')) ?>
    </div>
    <div class="dest">
      <?= h($destinataire['nom'] ?? '[Destinataire]') ?><br>
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

    <?php if ($faits): ?>
      <h3>Rappel des faits</h3>
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
    Document généré par SelfAct (<a href="https://justice.my-self.fr/act">justice.my-self.fr/act</a>),
    un outil open-source de formatage d'aide à la rédaction. Ce document n'est PAS OFFICIEL.
    Il ne constitue pas un acte juridique recevable en l'état. Il ne saurait remplacer un
    conseil juridique au sens de la loi 71-1130 du 31 décembre 1971. Pour un acte officiel,
    utilise le modèle service-public.fr correspondant ou consulte un avocat.
  </div>
</div>

</body>
</html>
