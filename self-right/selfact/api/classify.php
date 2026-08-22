<?php
/**
 * SelfAct — classification d'une ressource officielle en catégorie.
 *
 * ## Pourquoi ce fichier existe
 *
 * La règle de classement vivait en deux exemplaires : `guessCategory()` dans
 * `scraper.php`, appelée à l'indexation, et `classify()` dans `reclassify.php`,
 * censée être « la v2 ». Les deux ont divergé, et c'est la copie la plus
 * pauvre qui gagnait selon le script lancé. Une seule définition, incluse par
 * les deux, supprime la question.
 *
 * ## Ce qui a changé avec l'élargissement du catalogue
 *
 * Les règles d'origine ont été écrites pour des modèles de lettres, rédigés
 * du point de vue de l'usager : « contester », « résilier », « demander un
 * remboursement ». Les formulaires et les démarches en ligne emploient la
 * langue de l'administration : « autorisation », « agrément », « déclaration
 * préalable », « attestation ». Sans ce vocabulaire, 39 % du catalogue élargi
 * tombait en « divers », ce qui vidait le filtre par catégorie de son sens.
 *
 * ⚠️ Le classement reste une heuristique sur un intitulé. Il oriente une
 * navigation ; il ne qualifie rien juridiquement, et « divers » demeure une
 * réponse honnête quand l'intitulé ne tranche pas.
 */

declare(strict_types=1);

/**
 * Retire les accents et met en minuscules, pour un matching insensible.
 */
function selfact_normalize(string $s): string {
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    return strtr($s, [
        'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','å'=>'a',
        'ç'=>'c','č'=>'c',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ñ'=>'n',
        'ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
        'ý'=>'y','ÿ'=>'y',
        '’'=>"'",
    ]);
}

/**
 * Classe un intitulé dans l'une des catégories de SelfAct.
 *
 * 🔑 **L'ordre des règles porte le sens.** Le premier mot-clé trouvé gagne, et
 * plusieurs termes sont ambigus hors contexte : « permis » vaut conduire ou
 * construire, « carte » vaut identité, grise ou vitale. Les expressions
 * complètes sont donc placées avant les termes courts qui les contiennent,
 * et les catégories les plus spécifiques avant les plus larges.
 */
function selfact_classify(string $label, string $url = ''): string {
    $s = selfact_normalize($label);

    // --- Levées d'ambiguïté, avant tout le reste --------------------------
    // Ces expressions contiennent un mot qui, seul, mènerait ailleurs.
    $desambiguation = [
        'logement'       => ['permis de construire', 'permis d\'amenager', 'permis de demolir',
                             'declaration prealable de travaux', 'certificat d\'urbanisme',
                             'maprimerenov', 'ma prime renov', 'renovation energetique',
                             'aide a la renovation', 'anah', 'apl ', 'aide au logement',
                             'allocation logement', 'taxe d\'habitation', 'taxe fonciere'],
        'auto'           => ['permis de conduire', 'carte grise', 'certificat d\'immatriculation',
                             'controle technique', 'permis a points', 'plaque d\'immatriculation'],
        'sante'          => ['carte vitale', 'feuille de soins', 'affection de longue duree',
                             'complementaire sante', 'arret de travail', 'accident du travail'],
        'citoyennete'    => ['carte nationale d\'identite', 'carte d\'identite'],
        'travail'        => ['formation professionnelle', 'compte personnel de formation',
                             'validation des acquis', 'demandeur d\'emploi'],
    ];

    foreach ($desambiguation as $cat => $kws) {
        foreach ($kws as $kw) {
            if (str_contains($s, $kw)) { return $cat; }
        }
    }

    // --- Règles par catégorie --------------------------------------------
    $rules = [
        'famille'         => ['enfant', 'mineur', 'parental', 'paternite', 'maternite', 'adoption',
                              'garde d\'enfant', 'pension alimentaire', 'concubin', 'pacs',
                              'fiancaille', 'mariage', 'epoux', 'epouse', 'divorce',
                              'autorite parentale', 'filiation', 'naissance', 'decla. pat',
                              // vocabulaire administratif
                              'livret de famille', 'succession', 'tutelle', 'curatelle',
                              'famille d\'accueil', 'assistant maternel', 'creche', 'nourrice',
                              'obseques', 'deces', 'veuvage', 'orphelin'],
        'sante'           => ['medecin', 'medical', 'hopital', 'directives anticipees', 'sante publique',
                              'cpam', 'mutuelle', 'ald', 'invalidite', 'pharmacie', 'ordonnance',
                              'dossier medical', 'sante', 'soin',
                              'handicap', 'mdph', 'aah', 'ehpad', 'dependance', 'apa ',
                              'infirmier', 'kinesitherapeute', 'sage-femme', 'don du sang',
                              'vaccination', 'psychiatrique', 'cure thermale'],
        'association'     => ['association', 'loi 1901', 'buvette', 'siren asso', 'siret asso',
                              'agrement asso', 'subvention asso',
                              'benevole', 'fondation', 'reconnaissance d\'utilite publique',
                              'loterie', 'tombola', 'manifestation sportive'],
        'travail'         => ['employeur', 'employe', 'salarie', 'salaire', 'demission', 'licencie',
                              'rupture conventionnelle', 'stage', 'apprenti', 'alternance',
                              'conge parental', 'conge paye', 'conge maternite', 'conge paternite',
                              'fonction publique', 'fonctionnaire', 'titularisation', 'corps ', 'cadre d\'emploi',
                              'syndical', 'prud\'homme', 'retraite', 'chomage', 'pole emploi',
                              'heures supplementaires', 'smic', 'licenciement', 'mise a pied',
                              'travail', 'embauche', 'cdd', 'cdi', 'contrat de travail',
                              'agent public', 'militaire', 'gendarme', 'reserviste',
                              'concours', 'mutation', 'detachement', 'disponibilite',
                              'medaille d\'honneur', 'anciennete', 'temps partiel',
                              'travailleur independant', 'auto-entrepreneur', 'micro-entreprise'],
        'transports'      => ['vol aerien', 'avion', 'aerien', 'compagnie aerienne', 'sncf', 'ratp',
                              'bagage', 'retard de vol', 'refus d\'embarquement', 'annulation de vol',
                              'indemnisation voyage', 'billet de train', 'voyage',
                              'transport en commun', 'taxi', 'vtc', 'navigation', 'bateau',
                              'maritime', 'fluvial', 'ferroviaire',
                              'train', 'gare ', 'peage', 'autoroute'],
        'auto'            => ['garagiste', 'voiture', 'vehicule', 'automobile',
                              'garage', 'mecanique', 'moto', 'scooter', 'carrosserie',
                              'conducteur', 'circulation', 'stationnement', 'amende',
                              'radar', 'exces de vitesse', 'alcoolemie', 'remorque'],
        'logement'        => ['bail', 'locataire', 'proprietaire', 'loyer', 'caution locative',
                              'copropri', 'syndic', 'logement', 'habitation', 'immobili',
                              'residence', 'appartement', 'maison', 'voisin', 'nuisance',
                              'debroussaill', 'urbanisme', 'construction', 'travaux maison',
                              'demenagement', 'depot de garantie', 'etat des lieux',
                              'hlm', 'logement social', 'expulsion', 'insalubr', 'peril',
                              'assainissement', 'raccordement', 'cadastre', 'servitude',
                              'bornage', 'mitoyennete', 'terrain', 'parcelle', 'lotissement'],
        'consommation'    => ['retractation', 'consommateur', 'garantie', 'vice cache',
                              'vente a distance', 'achat a distance', 'demarchage', 'fournisseur',
                              'operateur', 'telecom', 'internet', 'telephonie', 'abonnement',
                              'dgccrf', 'repression des fraudes', 'teinturier', 'pressing',
                              'depannage', 'devis', 'artisan', 'commercant', 'remboursement',
                              'facture', 'livraison', 'produit non conforme', 'service mal execute',
                              'facture eau', 'fuite d\'eau', 'agence immobiliere honoraires',
                              'facture detaillee', 'honoraires', 'charte', 'renovation',
                              'vente', 'deballage',
                              'cesu', 'cheque-vacances', 'energie', 'electricite', 'gaz ',
                              'demarchage telephonique', 'litige commercial'],
        'finances'        => ['banque', 'compte bancaire', 'cheque', 'virement', 'carte bancaire',
                              'credit', 'pret', 'prelevement', 'decouvert', 'surendettement',
                              'interdit bancaire', 'opposition', 'mediateur banque', 'bct',
                              'bureau central de tarification', 'fichier fcc', 'fichier fnci',
                              'non-paiement', 'certificat de non-paiement', 'saisir paye',
                              'saisie', 'don manuel', 'reconnaissance de dette', 'pret entre particuliers',
                              'taux',
                              'epargne', 'livret a', 'pea ', 'succession bancaire',
                              'fonds de garantie', 'indemnisation financiere', 'bourse'],
        'assurances'      => ['assurance', 'assureur', 'sinistre', 'mediateur en assurance',
                              'habitation assurance', 'contrat assurance', 'assurance-vie',
                              'catastrophe naturelle', 'degat des eaux', 'assurance-vie',
                              'indemnisation du sinistre', 'expertise amiable'],
        'justice'         => ['plainte', 'procureur', 'tribunal', 'juge', 'avocat', 'magistrat',
                              'saisine', 'partie civile', 'huissier', 'commissaire de justice',
                              'conciliateur', 'mediateur de ', 'greffe', 'audience', 'citation',
                              'assignation', 'requete', 'appel ', 'pourvoi', 'cassation',
                              'aide juridictionnelle', 'casier judiciaire', 'detention',
                              'condamnation', 'infraction', 'victime', 'prejudice',
                              'recours gracieux', 'recours contentieux', 'recours hierarchique',
                              'contestation', 'contester', 'litige'],
        'citoyennete'     => ['passeport', 'attestation sur l\'honneur',
                              'nationalite', 'election', 'vote', 'recensement', 'changement de nom',
                              'changement de prenom', 'acte de naissance', 'acte de mariage',
                              'fiche de police', 'certificat de resident', 'covoiturage',
                              'bordereau des pieces',
                              'etat civil', 'legalisation de signature', 'apostille',
                              'service national', 'journee defense', 'jury d\'assises',
                              'anciens combattants', 'evade', 'deporte', 'resistance'],
        'etranger'        => ['visa', 'titre de sejour', 'naturalisation', 'etranger', 'schengen',
                              'asile', 'ofpra', 'reconduite',
                              'ressortissant', 'regroupement familial', 'attestation d\'accueil',
                              'expatri', 'consulat', 'francais de l\'etranger'],
        'securite'        => ['interdit de jeux', 'jeux d\'argent', 'force de l\'ordre',
                              'deontologie de la securite', 'fiche individuelle de police',
                              'arme', 'explosif', 'video-protection', 'videosurveillance',
                              'agent de securite', 'garde particulier'],
        // En dernier : la catégorie la plus large, pour ne pas capturer ce qui
        // relève d'un domaine précis.
        'administration'  => ['prefecture', 'mairie', 'impot', 'fiscal', 'tresor public',
                              'decision administrative',
                              'administration', 'defenseur des droits', 'crpa', 'urssaf', 'caf',
                              'caisse nationale', 'allocation', 'rsa', 'prime',
                              'autorisation', 'agrement', 'declaration prealable', 'habilitation',
                              'attestation', 'certificat', 'inscription', 'immatriculation',
                              'subvention', 'aide financiere', 'demande d\'aide', 'dossier de demande',
                              'registre', 'licence', 'derogation', 'dispense', 'exoneration',
                              'candidature', 'renouvellement', 'duplicata', 'convention'],
    ];

    foreach ($rules as $cat => $kws) {
        foreach ($kws as $kw) {
            if (str_contains($s, $kw)) {
                return $cat;
            }
        }
    }

    return 'divers';
}
