<?php
/**
 * SelfAct API — /act/api/deadline
 *
 * Calcule une échéance procédurale selon les articles 640 à 643 du code de
 * procédure civile, et rend le raisonnement suivi.
 *
 * GET /act/api/deadline?start=2026-04-17&days=15
 * GET /act/api/deadline?start=2026-04-17&months=2
 * GET /act/api/deadline?start=2026-04-17&months=1&days=15
 *   → JSON : échéance, règles appliquées, prorogation éventuelle
 *
 * &distance=outremer|etranger
 *   → applique l'augmentation de l'article 643 (recours seulement, voir plus bas)
 *
 * &format=ics
 *   → rend un fichier .ics importable dans un agenda
 *
 * ## Pourquoi ce calcul mérite du code plutôt qu'une réponse d'IA
 *
 * 🔑 **Un délai procédural est déterministe, et une IA le calcule mal.** Le jour
 * de départ ne compte pas, les mois se comptent par quantième et non en jours,
 * et une échéance qui tombe un samedi glisse au lundi. Trois règles simples que
 * l'arithmétique mentale — humaine ou statistique — rate régulièrement, alors
 * que l'erreur est irrattrapable : un recours déposé un jour trop tard est
 * irrecevable, quel que soit le fond du dossier.
 *
 * Les articles 640 à 643 du CPC ont été relus dans la base LEGI plutôt que
 * cités de mémoire — c'est la règle même du module. La version retenue est
 * datée par `CPC_VERSION_LEGI` ci-dessous, et c'est la seule fois qu'elle est
 * écrite : le fondement rendu à l'appelant la reprend de là.
 *
 * ⚠️ **Ce que ce calcul ne fait pas.** Il applique une durée à une date. Il ne
 * dit pas quelle durée s'applique à votre cas, ni à partir de quel événement
 * elle court — ce sont précisément les deux questions qui font perdre les
 * dossiers, et elles relèvent de la qualification juridique. L'article 643 n'est
 * appliqué que si vous le demandez, parce qu'il ne vise que certains recours
 * limitativement énumérés.
 */

declare(strict_types=1);

// Version LEGI des articles 640 à 643 d'après laquelle les règles ci-dessous
// ont été codées. Elle est servie à l'appelant : une règle du CPC qui changerait
// rendrait ce calcul faux en silence, et cette date est ce qui permet de le
// voir. À rapprocher de la cadence de vérification législative du module.
const CPC_VERSION_LEGI = '2026-08-02';

/**
 * Dimanche de Pâques (calendrier grégorien), algorithme de Meeus/Jones/Butcher.
 * Sert de pivot à trois des onze jours fériés français.
 */
function selfact_paques(int $annee): DateTimeImmutable {
    $a = $annee % 19;
    $b = intdiv($annee, 100);
    $c = $annee % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mois = intdiv($h + $l - 7 * $m + 114, 31);
    $jour = (($h + $l - 7 * $m + 114) % 31) + 1;

    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $annee, $mois, $jour));
}

/**
 * Jours fériés légaux en France métropolitaine (art. L3133-1 du code du travail).
 *
 * ⚠️ Alsace-Moselle (Vendredi saint, 26 décembre) et l'outre-mer ajoutent des
 * jours qui ne figurent pas ici : une échéance calculée pour ces territoires
 * peut donc être annoncée un jour trop tôt.
 *
 * @return array<string, string> date ISO => libellé
 */
function selfact_jours_feries(int $annee): array {
    $paques = selfact_paques($annee);
    $feries = [
        sprintf('%04d-01-01', $annee) => 'Jour de l\'an',
        sprintf('%04d-05-01', $annee) => 'Fête du Travail',
        sprintf('%04d-05-08', $annee) => 'Victoire 1945',
        sprintf('%04d-07-14', $annee) => 'Fête nationale',
        sprintf('%04d-08-15', $annee) => 'Assomption',
        sprintf('%04d-11-01', $annee) => 'Toussaint',
        sprintf('%04d-11-11', $annee) => 'Armistice 1918',
        sprintf('%04d-12-25', $annee) => 'Noël',
        $paques->modify('+1 day')->format('Y-m-d')   => 'Lundi de Pâques',
        $paques->modify('+39 days')->format('Y-m-d') => 'Ascension',
        $paques->modify('+50 days')->format('Y-m-d') => 'Lundi de Pentecôte',
    ];
    ksort($feries);
    return $feries;
}

/**
 * Ajoute des mois en respectant l'article 641 : l'échéance porte le même
 * quantième ; à défaut de quantième identique, elle tombe le dernier jour du
 * mois.
 *
 * 🔑 C'est le piège classique du calcul « en jours ». Deux mois après le
 * 31 décembre, ce n'est pas le 2 mars : c'est le 28 (ou le 29) février, parce
 * que le 31 février n'existe pas. `+2 months` en PHP répond le 2 ou 3 mars —
 * d'où ce passage par le dernier jour du mois.
 */
function selfact_ajouter_mois(DateTimeImmutable $d, int $mois): DateTimeImmutable {
    $quantieme = (int) $d->format('j');
    $cible = $d->modify('first day of this month')->modify("+$mois months");
    $dernierJour = (int) $cible->format('t');
    return $cible->setDate(
        (int) $cible->format('Y'),
        (int) $cible->format('n'),
        min($quantieme, $dernierJour)
    );
}

/**
 * Calcule une échéance et documente chaque règle appliquée.
 *
 * @return array{echeance:string, jour:string, etapes:list<array<string,string>>}
 */
function selfact_calcul_delai(
    DateTimeImmutable $depart,
    int $jours = 0,
    int $mois = 0,
    int $annees = 0,
    string $distance = 'metropole'
): array {
    $etapes = [];
    $d = $depart;

    // Art. 643 : l'augmentation de distance s'ajoute au délai lui-même.
    $moisDistance = ['metropole' => 0, 'outremer' => 1, 'etranger' => 2][$distance] ?? 0;
    if ($moisDistance > 0) {
        $mois += $moisDistance;
        $etapes[] = [
            'regle'  => 'art. 643 CPC',
            'detail' => sprintf(
                'Délai de distance : +%d mois (%s). Ne s\'applique qu\'aux délais de '
                . 'comparution, d\'appel, d\'opposition, de tierce opposition, de recours '
                . 'en révision et de pourvoi en cassation.',
                $moisDistance,
                $distance === 'outremer' ? 'outre-mer' : 'étranger'
            ),
        ];
    }

    // Art. 641 al. 3 : les mois se décomptent d'abord, puis les jours.
    if ($annees > 0) {
        $d = selfact_ajouter_mois($d, $annees * 12);
        $etapes[] = ['regle' => 'art. 641 al. 2 CPC',
                     'detail' => "+$annees année(s) au même quantième → " . $d->format('Y-m-d')];
    }
    if ($mois > 0) {
        $avant = $d;
        $d = selfact_ajouter_mois($d, $mois);
        $detail = "+$mois mois au même quantième → " . $d->format('Y-m-d');
        if ((int) $avant->format('j') !== (int) $d->format('j')) {
            $detail .= ' (quantième inexistant dans le mois d\'arrivée : reporté au dernier jour)';
        }
        $etapes[] = ['regle' => 'art. 641 al. 2 CPC', 'detail' => $detail];
    }
    if ($jours > 0) {
        $d = $d->modify("+$jours days");
        $etapes[] = ['regle' => 'art. 641 al. 1 CPC',
                     'detail' => "+$jours jour(s), le jour de départ ne comptant pas → "
                                 . $d->format('Y-m-d')];
    }

    // Art. 642 : prorogation si l'échéance tombe un samedi, un dimanche ou un férié.
    $feries = selfact_jours_feries((int) $d->format('Y'))
            + selfact_jours_feries((int) $d->format('Y') + 1);
    $reporte = false;
    $motifs = [];
    while (true) {
        $jourSemaine = (int) $d->format('N');   // 6 = samedi, 7 = dimanche
        $iso = $d->format('Y-m-d');
        if ($jourSemaine === 6)                  { $motifs[] = "$iso : samedi"; }
        elseif ($jourSemaine === 7)              { $motifs[] = "$iso : dimanche"; }
        elseif (isset($feries[$iso]))            { $motifs[] = "$iso : {$feries[$iso]}"; }
        else                                     { break; }
        $d = $d->modify('+1 day');
        $reporte = true;
    }
    if ($reporte) {
        $etapes[] = ['regle' => 'art. 642 al. 2 CPC',
                     'detail' => 'Échéance prorogée au premier jour ouvrable suivant ('
                                 . implode(' ; ', $motifs) . ') → ' . $d->format('Y-m-d')];
    } else {
        $etapes[] = ['regle' => 'art. 642 al. 1 CPC',
                     'detail' => 'Échéance un jour ouvrable : expire le ' . $d->format('Y-m-d')
                                 . ' à 24 h 00.'];
    }

    $joursFr = ['Monday'=>'lundi','Tuesday'=>'mardi','Wednesday'=>'mercredi',
                'Thursday'=>'jeudi','Friday'=>'vendredi','Saturday'=>'samedi','Sunday'=>'dimanche'];

    return [
        'echeance' => $d->format('Y-m-d'),
        'jour'     => $joursFr[$d->format('l')] ?? $d->format('l'),
        'reporte'  => $reporte,
        'etapes'   => $etapes,
    ];
}

/**
 * Produit un événement iCalendar pour l'échéance.
 *
 * L'événement est posé sur la journée entière du dernier jour utile : un délai
 * expire à 24 h, pas à une heure de rendez-vous.
 */
function selfact_ics(string $echeance, string $resume, string $description): string {
    $dt = new DateTimeImmutable($echeance);
    $uid = 'selfact-' . substr(hash('sha256', $echeance . $resume), 0, 16) . '@my-self.fr';
    $echap = static fn(string $s): string =>
        str_replace(["\\", "\n", ',', ';'], ["\\\\", '\n', '\,', '\;'], $s);

    $lignes = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//SelfAct//Calendrier procedural//FR',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:' . $uid,
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART;VALUE=DATE:' . $dt->format('Ymd'),
        'DTEND;VALUE=DATE:' . $dt->modify('+1 day')->format('Ymd'),
        'SUMMARY:' . $echap($resume),
        'DESCRIPTION:' . $echap($description),
        'BEGIN:VALARM',
        'TRIGGER:-P7D',
        'ACTION:DISPLAY',
        'DESCRIPTION:' . $echap('Échéance dans 7 jours : ' . $resume),
        'END:VALARM',
        'END:VEVENT',
        'END:VCALENDAR',
    ];
    // RFC 5545 : séparateur CRLF.
    return implode("\r\n", $lignes) . "\r\n";
}

// ---------------------------------------------------------------- endpoint

// Ce fichier sert aussi de bibliothèque à draft.php et aux tests : ne rien
// émettre s'il est inclus plutôt qu'appelé directement.
if (PHP_SAPI === 'cli' || !isset($_SERVER['REQUEST_METHOD'])) { return; }

function selfact_repondre(int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$start = trim((string) ($_GET['start'] ?? ''));
if ($start === '') {
    selfact_repondre(400, [
        'ok'    => false,
        'error' => 'missing_start',
        'hint'  => 'Indiquez la date de départ : ?start=AAAA-MM-JJ&days=15',
    ]);
}
$d = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
$erreurs = DateTimeImmutable::getLastErrors();
if ($d === false || ($erreurs && ($erreurs['warning_count'] || $erreurs['error_count']))) {
    selfact_repondre(400, [
        'ok'    => false,
        'error' => 'invalid_start',
        'hint'  => 'Format attendu : AAAA-MM-JJ (ex. 2026-04-17).',
    ]);
}

$jours  = max(0, (int) ($_GET['days']   ?? 0));
$mois   = max(0, (int) ($_GET['months'] ?? 0));
$annees = max(0, (int) ($_GET['years']  ?? 0));

if ($jours + $mois + $annees === 0) {
    selfact_repondre(400, [
        'ok'    => false,
        'error' => 'missing_duration',
        'hint'  => 'Indiquez au moins une durée : &days=15, &months=2 ou &years=1.',
    ]);
}
// Bornes de bon sens : au-delà, c'est une faute de frappe, pas un délai.
if ($jours > 3650 || $mois > 120 || $annees > 10) {
    selfact_repondre(400, [
        'ok'    => false,
        'error' => 'duration_out_of_range',
        'hint'  => 'Durées acceptées : jusqu\'à 3650 jours, 120 mois ou 10 ans.',
    ]);
}

// 🔑 Un paramètre présent et vide vaut absent : c'est ce que produit un
// formulaire dont le champ n'a pas été rempli. `?? 'metropole'` ne le voyait
// pas — seul `distance` complètement omis retombait sur le défaut, et
// `?distance=` recevait un refus pour n'avoir rien choisi.
$distance = strtolower(trim((string) ($_GET['distance'] ?? '')));
if ($distance === '') { $distance = 'metropole'; }
if (!in_array($distance, ['metropole', 'outremer', 'etranger'], true)) {
    selfact_repondre(400, [
        'ok'    => false,
        'error' => 'invalid_distance',
        'hint'  => 'Valeurs acceptées : metropole, outremer, etranger.',
    ]);
}

$res = selfact_calcul_delai($d, $jours, $mois, $annees, $distance);

$duree = [];
if ($annees) { $duree[] = "$annees an(s)"; }
if ($mois)   { $duree[] = "$mois mois"; }
if ($jours)  { $duree[] = "$jours jour(s)"; }
$dureeTxt = implode(' et ', $duree);

// 🔑 Les paramètres du lien se dérivent de ceux qui ont servi au calcul, et non
// d'une liste réécrite à la main. `distance` y manquait : l'agenda recevait une
// échéance antérieure de un ou deux mois à celle affichée dans la même réponse,
// et son résumé annonçait une durée qui n'était pas celle demandée. Un
// paramètre ajouté demain retomberait dans le même trou.
$parametres = ['start' => $d->format('Y-m-d')];
if ($jours)  { $parametres['days'] = $jours; }
if ($mois)   { $parametres['months'] = $mois; }
if ($annees) { $parametres['years'] = $annees; }
if ($distance !== 'metropole') { $parametres['distance'] = $distance; }

$dureeDistance = ['outremer' => ' + 1 mois de distance (art. 643)',
                  'etranger' => ' + 2 mois de distance (art. 643)'][$distance] ?? '';
$resume = 'Échéance SelfAct : ' . $dureeTxt . $dureeDistance . ' à compter du ' . $d->format('d/m/Y');

if (strtolower((string) ($_GET['format'] ?? '')) === 'ics') {
    $description = "Delai de $dureeTxt" . strtr($dureeDistance, ['é' => 'e', 'à' => 'a'])
                 . " courant a compter du " . $d->format('d/m/Y') . ".\n"
                 . "Calcul selon les articles 640 a 642 du code de procedure civile.\n"
                 . "Le delai expire le " . $res['echeance'] . " a 24h00.\n"
                 . "Verifiez que ce delai est bien celui qui s'applique a votre situation.";
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="echeance-' . $res['echeance'] . '.ics"');
    header('X-Content-Type-Options: nosniff');
    echo selfact_ics($res['echeance'], $resume, $description);
    exit;
}

selfact_repondre(200, [
    'ok'       => true,
    'depart'   => $d->format('Y-m-d'),
    'duree'    => ['jours' => $jours, 'mois' => $mois, 'annees' => $annees,
                   'distance' => $distance],
    'echeance' => $res['echeance'],
    'jour'     => $res['jour'],
    'reporte'  => $res['reporte'],
    'expire'   => 'le ' . $res['echeance'] . ' à 24 h 00',
    'raisonnement' => $res['etapes'],
    'fondement'    => [
        'art. 640 CPC' => 'Point de départ : la date de l\'acte, de l\'événement, de la décision ou de la notification.',
        'art. 641 CPC' => 'Délai en jours : le jour de départ ne compte pas. Délai en mois ou années : même quantième, à défaut le dernier jour du mois.',
        'art. 642 CPC' => 'Le délai expire le dernier jour à 24 h. S\'il tombe un samedi, un dimanche ou un jour férié, il est prorogé au premier jour ouvrable suivant.',
        // 🔑 Cette ligne disait « Textes relus dans la base LEGI (dump officiel
        // DILA), et non cités de mémoire ». Au passé composé et sans sujet, dans
        // le résultat d'un appel, elle se lisait comme la provenance de CETTE
        // réponse — un contrôle extérieur l'a recopiée le 21/08/2026 comme une
        // déclaration sur son propre travail. Or ce fichier n'ouvre aucune base :
        // il applique des règles écrites à la main d'après une version datée.
        // Décrire l'écriture, pas l'exécution.
        'source'       => 'Règles codées à la main d\'après les articles 640 à 643 du '
                        . 'CPC, version LEGI du ' . CPC_VERSION_LEGI . '. Ce calcul est '
                        . 'arithmétique : il ne consulte aucune base pendant son '
                        . 'exécution, et ne suivra donc pas tout seul une réforme de '
                        . 'ces articles.',
        'mention' => "SelfAct est indépendant et n'est affilié à aucun organisme public. "
                   . "Les formulaires et démarches officiels sont disponibles gratuitement "
                   . "sur service-public.gouv.fr : cet outil n'est jamais nécessaire pour y accéder.",
    ],
    'avertissement' => 'Ce calcul applique une durée à une date. Il ne dit ni quelle '
                     . 'durée s\'applique à votre situation, ni à partir de quel '
                     . 'événement elle court — ces deux questions relèvent de la '
                     . 'qualification juridique. Vérifiez-les avant de vous fier à '
                     . 'cette échéance.',
    'ics' => '?' . http_build_query($parametres + ['format' => 'ics']),
]);
