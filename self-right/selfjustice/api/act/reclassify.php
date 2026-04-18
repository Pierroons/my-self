<?php
/**
 * SelfAct — Re-classification du catalogue existant avec heuristique améliorée.
 *
 * Usage : php reclassify.php [--dry-run]
 *
 * Ne re-fetche PAS service-public.fr. Relit data/catalog.json, applique la
 * nouvelle classification, réécrit le fichier.
 */

declare(strict_types=1);

$dryRun = in_array('--dry-run', $argv, true);

/**
 * Classification améliorée par règles ordonnées (premier match gagne).
 * L'ordre est important : règles spécifiques en premier, génériques en dernier.
 */
function classify(string $label): string {
    $s = strtolower(strtr($label, [
        'à'=>'a','â'=>'a','ä'=>'a','á'=>'a',
        'ç'=>'c',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'î'=>'i','ï'=>'i',
        'ô'=>'o','ö'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
        'ÿ'=>'y','ñ'=>'n',
    ]));

    // --- Règles ordonnées ---
    $rules = [
        // Famille / enfants (très spécifique, d'abord)
        'famille' => ['enfant', 'mineur', 'parental', 'paternite', 'maternite', 'adoption',
                      'garde d\'enfant', 'pension alimentaire', 'concubin', 'pacs',
                      'fiancaille', 'mariage', 'epoux', 'epouse', 'divorce',
                      'autorite parentale', 'filiation', 'naissance', 'decla. pat'],
        // Santé / médical
        'sante' => ['medecin', 'medical', 'hopital', 'directives anticipees', 'sante publique',
                   'cpam', 'mutuelle', 'ald', 'invalidite', 'pharmacie', 'ordonnance',
                   'dossier medical', 'sante', 'soin'],
        // Associations
        'association' => ['association', 'loi 1901', 'buvette', 'siren asso', 'siret asso',
                         'agrement asso', 'subvention asso'],
        // Travail / emploi (tous statuts : privé, fonction publique)
        'travail' => ['employeur', 'employe', 'salarie', 'salaire', 'demission', 'licencie',
                     'rupture conventionnelle', 'stage', 'apprenti', 'alternance',
                     'conge parental', 'conge paye', 'conge maternite', 'conge paternite',
                     'fonction publique', 'fonctionnaire', 'titularisation', 'corps ', 'cadre d\'emploi',
                     'syndical', 'prud\'homme', 'retraite', 'chomage', 'pole emploi',
                     'heures supplementaires', 'smic', 'licenciement', 'mise a pied',
                     'travail', 'embauche', 'cdd', 'cdi', 'contrat de travail'],
        // Transports (spécifique : avion/train/bateau/auto SEULEMENT si contexte transport)
        'transports' => ['vol aerien', 'avion', 'aerien', 'compagnie aerienne', 'sncf', 'ratp',
                        'bagage', 'retard de vol', 'refus d\'embarquement', 'annulation de vol',
                        'indemnisation voyage', 'billet de train', 'carte grise', 'voyage'],
        // Auto / véhicule (garage, réparation, vice caché)
        'auto' => ['garagiste', 'voiture', 'vehicule', 'automobile', 'permis de conduire',
                  'garage', 'mecanique', 'moto', 'scooter', 'carrosserie'],
        // Logement / immobilier
        'logement' => ['bail', 'locataire', 'proprietaire', 'loyer', 'caution locative',
                      'copropri', 'syndic', 'logement', 'habitation', 'immobili',
                      'residence', 'appartement', 'maison', 'voisin', 'nuisance',
                      'debroussaill', 'urbanisme', 'construction', 'travaux maison',
                      'demenagement', 'depot de garantie', 'etat des lieux'],
        // Consommation (large, après auto/logement/travail)
        'consommation' => ['retractation', 'consommateur', 'garantie', 'vice cache',
                          'vente a distance', 'achat a distance', 'demarchage', 'fournisseur',
                          'operateur', 'telecom', 'internet', 'telephonie', 'abonnement',
                          'dgccrf', 'repression des fraudes', 'teinturier', 'pressing',
                          'depannage', 'devis', 'artisan', 'commercant', 'remboursement',
                          'facture', 'livraison', 'produit non conforme', 'service mal execute',
                          'facture eau', 'fuite d\'eau', 'agence immobiliere honoraires',
                          'facture detaillee', 'honoraires', 'charte', 'renovation',
                          'vente', 'deballage'],
        // Finances / banque / crédit
        'finances' => ['banque', 'compte bancaire', 'cheque', 'virement', 'carte bancaire',
                      'credit', 'pret', 'prelevement', 'decouvert', 'surendettement',
                      'interdit bancaire', 'opposition', 'mediateur banque', 'bct',
                      'bureau central de tarification', 'fichier fcc', 'fichier fnci',
                      'non-paiement', 'certificat de non-paiement', 'saisir paye',
                      'saisie', 'don manuel', 'reconnaissance de dette', 'pret entre particuliers',
                      'taux'],
        // Assurances
        'assurances' => ['assurance', 'assureur', 'sinistre', 'mediateur en assurance',
                        'habitation assurance', 'contrat assurance', 'assurance-vie'],
        // Justice (strict : plainte/saisine/procedure)
        'justice' => ['plainte', 'procureur', 'tribunal', 'juge', 'avocat', 'magistrat',
                     'saisine', 'partie civile', 'huissier', 'commissaire de justice',
                     'conciliateur', 'mediateur de ', 'greffe', 'audience', 'citation',
                     'assignation', 'requete', 'appel ', 'pourvoi', 'cassation'],
        // Citoyenneté / papiers
        'citoyennete' => ['carte d\'identite', 'passeport', 'attestation sur l\'honneur',
                         'nationalite', 'election', 'vote', 'recensement', 'changement de nom',
                         'changement de prenom', 'acte de naissance', 'acte de mariage',
                         'fiche de police', 'certificat de resident', 'covoiturage',
                         'bordereau des pieces'],
        // Administration / fisc
        'administration' => ['prefecture', 'mairie', 'impot', 'fiscal', 'tresor public',
                           'recours gracieux', 'recours contentieux', 'decision administrative',
                           'administration', 'defenseur des droits', 'crpa', 'urssaf', 'caf',
                           'caisse nationale', 'allocation', 'rsa', 'prime'],
        // Étranger / Europe
        'etranger' => ['visa', 'titre de sejour', 'naturalisation', 'etranger', 'schengen',
                      'asile', 'ofpra', 'reconduite'],
        // Interdictions / jeux / sécurité
        'securite' => ['interdit de jeux', 'jeux d\'argent', 'force de l\'ordre',
                      'deontologie de la securite', 'fiche individuelle de police'],
    ];

    // Special pré-routing : "voiture" par seule mention peut être auto, transports ou consommation
    // On laisse la logique de premier match gérer via l'ordre des règles.

    foreach ($rules as $cat => $kws) {
        foreach ($kws as $kw) {
            if (str_contains($s, $kw)) {
                return $cat;
            }
        }
    }
    return 'divers';
}

// --- Main ---
$path = __DIR__ . '/data/catalog.json';
$raw = file_get_contents($path);
$data = json_decode($raw, true);

if (!is_array($data) || !isset($data['models'])) {
    fwrite(STDERR, "catalog.json manquant ou invalide\n");
    exit(1);
}

$before = [];
$after = [];
foreach ($data['models'] as $i => $m) {
    $oldCat = $m['category'] ?? 'inconnu';
    $newCat = classify($m['label']);
    $before[$oldCat] = ($before[$oldCat] ?? 0) + 1;
    $after[$newCat] = ($after[$newCat] ?? 0) + 1;
    $data['models'][$i]['category'] = $newCat;
}

$data['_meta']['categories'] = $after;
$data['_meta']['last_sync']  = date('c');
$data['_meta']['classifier_version'] = 'v2-reclassify';

echo "=== Avant (ancienne heuristique) ===\n";
arsort($before);
foreach ($before as $c => $n) { echo str_pad($c, 18) . ": $n\n"; }

echo "\n=== Après (classifier v2) ===\n";
arsort($after);
foreach ($after as $c => $n) { echo str_pad($c, 18) . ": $n\n"; }

$improvement = ($before['divers'] ?? 0) - ($after['divers'] ?? 0);
echo "\nRéduction 'divers' : $improvement modèles réassignés\n";

if ($dryRun) {
    echo "\n[dry-run : fichier non écrit]\n";
    exit(0);
}

file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "\n✓ catalog.json réécrit avec classification v2\n";
