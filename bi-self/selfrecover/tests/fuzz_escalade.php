<?php

declare(strict_types=1);

/**
 * Fuzzer à propriétés du niveau 3.
 *
 * 🔑 **Il ne cherche pas des plantages, il cherche des mensonges.** Un fuzzer
 * classique tire des octets et guette un segfault ; PHP n'en a pas, et une
 * exception non rattrapée serait déjà visible. Ce qu'on vise ici est plus
 * pernicieux : une séquence d'appels ordinaire, chacun rendant un refus poli, au
 * terme de laquelle une propriété que le protocole PROMET a cessé d'être vraie.
 *
 * `sanity_escalade.php` éprouve les cas que j'ai su imaginer. Ce fichier éprouve
 * ceux que je n'ai pas imaginés : il tire des suites d'opérations au hasard et
 * vérifie les invariants APRÈS CHAQUE APPEL, quel que soit le chemin parcouru.
 *
 * ⚠️ **Reproductible, sinon inutile.** La graine est affichée à chaque essai et
 * se rejoue : `php tests/fuzz_escalade.php --graine=123456`. Un fuzzer dont on
 * ne peut pas rejouer l'échec ne fait que produire de l'anxiété.
 *
 * ⚠️ Ce qu'il NE garde PAS : il tourne sur `StockageMemoire`, donc il ne dit
 * rien d'un adaptateur SQL — ni de l'échappement, ni des transactions réelles.
 * Il ne garde pas non plus le temps constant : les durées mesurées sous un
 * fuzzer sont du bruit.
 *
 * Usage : php tests/fuzz_escalade.php [--tours=500] [--graine=N]
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/StockageMemoire.php';

use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Recovery\Escalade;
use Pierroons\SelfRecover\Recovery\Recovery;
use Pierroons\SelfRecover\Tests\StockageMemoire;

// ── Réglages ────────────────────────────────────────────────────────────────

$opts   = getopt('', ['tours::', 'graine::']);
$tours  = (int) ($opts['tours'] ?? 500);
$graine = isset($opts['graine']) ? (int) $opts['graine'] : random_int(1, PHP_INT_MAX);
mt_srand($graine);

echo "\n";
echo "  Fuzzer du niveau 3 — $tours tours, graine $graine\n";
echo "  Rejouer cet essai : php tests/fuzz_escalade.php --tours=$tours --graine=$graine\n\n";

/** @var list<array{tour: int, propriete: string, detail: string, journal: list<string>}> */
$violations = [];
$profonds   = 0;      // ré-enrôlements réussis : la mesure de ce que le fuzzer a atteint

// ── Le vivier d'entrées ─────────────────────────────────────────────────────
//
// 🔑 Les valeurs « presque bonnes » trouvent plus que les valeurs absurdes. Une
// empreinte de 63 caractères, un numéro à la bonne forme mais inexistant, un
// sésame qui ne diffère que par la casse : c'est là que vivent les gardes
// écrites avec un `==` de trop ou un `strtolower` oublié.

/** Chaînes tordues, dont plusieurs sont des pièges connus de PHP. */
function vivier(): array
{
    static $v = null;
    if ($v !== null) {
        return $v;
    }

    return $v = [
        '',
        ' ',
        '0',                                   // vaut false en contexte booléen
        'null',
        str_repeat('a', 4096),
        "\x00",                                // octet nul : coupe une chaîne C
        "octet\x00nul",
        "\xC3\x28",                            // UTF-8 INVALIDE — json_encode rend false
        "\xF0\x9F\x94\x91",                    // 🔑, hors du plan multilingue de base
        "é",
        "É",
        "  espaces autour  ",
        "saut\nde\nligne",
        'LIT-',
        'LIT-0000000000000000',                // bonne forme, inexistant
        'lit-0000000000000000',                // même chose en minuscules
        str_repeat('a1', 32),                  // 64 hex : une clé dérivée valide
        str_repeat('a1', 31),                  // 62 hex : presque
        str_repeat('a1', 32) . 'ff',           // 68 hex : trop
        str_repeat('b2', 16),                  // 32 hex : un sel valide
        strtoupper(str_repeat('a1', 32)),      // hex en MAJUSCULES
        '../../etc/passwd',
        "' OR 1=1 --",
        '<script>alert(1)</script>',
        '{"json":"dans une chaîne"}',
        '-1',
        (string) PHP_INT_MAX,
    ];
}

function tordu(): string
{
    $v = vivier();

    return $v[mt_rand(0, count($v) - 1)];
}

/** Tantôt la vraie valeur, tantôt une valeur tordue : sinon on ne parcourt rien. */
function parfois(string $vrai): string
{
    return mt_rand(0, 2) === 0 ? tordu() : $vrai;
}

// ── L'état observé ──────────────────────────────────────────────────────────

/**
 * Photographie de ce qui NE DOIT PAS bouger.
 *
 * 🔑 C'est l'invariant central du niveau 3, et il tient en une phrase : rien de
 * ce qui appartient au compte ne change tant qu'un `reEnroler` n'a pas rendu
 * `ok`. Ni un refus, ni un gel, ni un message, ni une décision d'arbitre.
 */
function photo(StockageMemoire $st): array
{
    return [
        'empreinte_mot'  => $st->comptes['alice']['empreinte_mot'] ?? null,
        'empreinte_pass' => $st->passphrases['alice']['empreinte_passphrase'] ?? null,
        'empreinte_mdp'  => $st->empreintes[1] ?? null,
        'sel'            => $st->sels[1] ?? null,
        'sessions'       => $st->sessionsRevoquees,
        'codes'          => count($st->codes),
    ];
}

// ── La boucle ───────────────────────────────────────────────────────────────

$MOT = str_repeat('a1', 32);
$SEL = str_repeat('b2', 16);

for ($tour = 1; $tour <= $tours; $tour++) {
    $st = new StockageMemoire();
    $st->comptes['alice']     = ['id' => 1, 'empreinte_mot' => Hashing::hash('mot initial')];
    $st->passphrases['alice'] = ['id' => 1, 'empreinte_passphrase' => Hashing::hash('phrase initiale')];
    $st->faits[1] = [
        'cree_le'            => 1_600_000_000,
        // Tantôt tracé, tantôt pas : le troisième état du faisceau ne se
        // parcourt que sur un compte dont le serveur ne sait rien.
        'derniere_connexion' => mt_rand(0, 1) ? 1_690_000_000 : null,
        'nombre_connexions'  => mt_rand(0, 1) ? mt_rand(0, 200) : null,
    ];

    // ⚠️ Le compte est POURVU : mot de passe de connexion posé, lot de codes
    // émis. Sans ça, `photo()` compare du vide à du vide et l'invariant central
    // ne peut pas être violé — mesuré, un refus qui purgeait les codes passait.
    $st->empreintes[1] = Hashing::hash('mot de passe de connexion');
    // Les codes sont posés à la main, pas par `emettreCodes()` : dix Argon2id à
    // 64 Mio par tour rendraient le fuzzer trop lent pour tourner souvent, et
    // ce qu'on mesure ici est qu'ils SURVIVENT, pas comment ils sont fabriqués.
    for ($c = 1; $c <= 10; $c++) {
        $st->codes[] = ['id' => $c, 'compte_id' => 1, 'index' => "idx$c",
                        'empreinte' => "emp$c", 'utilise' => false];
    }
    $recovery = new Recovery($st, 'sel-du-fuzzer', delaiRefusUs: 0);
    $esc      = new Escalade($st, $recovery, delaiRefusUs: 0);

    $avant     = photo($st);
    // ⚠️ Ce drapeau vaut pour UN pas, pas pour la suite. Écrit une fois pour
    // toutes, il désarmait l'invariant central dès le premier ré-enrôlement
    // réussi : tout ce qui bougeait ensuite dans le compte passait sans contrôle,
    // et c'est précisément après un ré-enrôlement que l'état est le plus riche.
    $reussiCePas = false;        // un reEnroler a-t-il rendu ok à CE pas ?
    $maintenant = 1_700_000_000;
    $numeros   = [];             // les numéros vus, pour pouvoir les rejouer
    $sesames   = [];
    $journal   = [];
    $motDePasseChoisi = '';
    $motDePasseSoumis = '';
    $sesamesConsommes = [];      // ceux qui ont servi : ils ne doivent plus rien ouvrir

    $signaler = static function (string $propriete, string $detail) use (&$violations, $tour, &$journal): void {
        $violations[] = ['tour' => $tour, 'propriete' => $propriete, 'detail' => $detail,
                         'journal' => $journal];
    };

    // ⚠️ La longueur se tire UNE fois. Écrite `$pas < mt_rand(6, 20)`, elle se
    // retirait à chaque itération : la suite s'arrête dès que le tirage tombe
    // sous le compteur, ce qui donne 9,6 pas de moyenne au lieu de 13 et ne
    // laisse jamais atteindre 20. Les états les plus profonds vivent dans les
    // suites longues, et ce défaut les rendait rares sans rien signaler.
    $longueur = mt_rand(6, 20);
    for ($pas = 0; $pas < $longueur; $pas++) {
        $maintenant += mt_rand(0, 200000);

        // 🔑 **Marche GUIDÉE, pas purement aléatoire.** Une suite tirée
        // uniformément n'atteint presque jamais un ré-enrôlement réussi : il y
        // faut une ouverture, une acceptation, puis les bons numéro, sésame,
        // mot dérivé, sel et longueur de mot de passe — une conjonction que le
        // hasard produit une fois sur des milliers. Or c'est précisément l'état
        // profond où vivent les propriétés qui comptent. Mesuré : sans ce
        // guidage, deux défauts plantés exprès restaient verts sur 60 tours.
        //
        // Une fois sur trois on tire quand même au hasard : le guidage rejoue
        // les chemins nominaux, le hasard visite ceux qu'aucune spécification
        // ne décrit.
        $toutes = ['ouvrir', 'soumettre', 'etat', 'fil', 'repondre', 'trancher', 'degeler', 'reEnroler', 'purger'];
        $guide  = false;
        if (mt_rand(0, 2) === 0 || $numeros === []) {
            $op = $toutes[mt_rand(0, count($toutes) - 1)];
        } else {
            $guide = true;
            $vise   = $esc->etat(end($numeros), end($sesames), $maintenant);
            $statut = $vise['statut'] ?? null;
            $op = match ($statut) {
                'open'           => 'soumettre',
                'awaiting_admin' => 'trancher',
                'accepted'       => 'reEnroler',
                default          => $toutes[mt_rand(0, count($toutes) - 1)],
            };
        }

        // Le dernier dossier ouvert est le plus souvent visé, pour que la suite
        // progresse au lieu de disperser ses appels sur des dossiers morts.
        //
        // ⚠️ Les deux régimes ne partagent PAS leurs arguments. Le régime guidé
        // emploie les vraies valeurs — sinon il vise la bonne opération et la
        // rate sur ses paramètres, et l'état profond reste hors d'atteinte
        // (mesuré : 4 ré-enrôlements réussis sur 200 tours avant cette
        // séparation). Le régime aléatoire, lui, tord tout ce qu'il touche.
        if ($guide) {
            $numero = end($numeros);
            $sesame = end($sesames);
        } else {
            $recent = $numeros !== [] && mt_rand(0, 3) > 0;
            $numero = $numeros === [] ? tordu() : parfois($recent ? end($numeros) : $numeros[array_rand($numeros)]);
            $sesame = $sesames === [] ? tordu() : parfois($recent ? end($sesames) : $sesames[array_rand($sesames)]);
        }

        try {
            switch ($op) {
                case 'ouvrir':
                    $s = bin2hex(random_bytes(16));
                    $r = $esc->ouvrir(parfois('alice'), parfois(hash('sha256', $s)), maintenant: $maintenant);
                    if (($r['ok'] ?? false) === true) {
                        $numeros[] = $r['numero'];
                        $sesames[] = $s;
                    }
                    break;
                case 'soumettre':
                    $r = $esc->soumettre($numero, $sesame, [
                        'annee_creation' => tordu(),
                        'mois_connexion' => tordu(),
                        'frequence'      => tordu(),
                        tordu()          => tordu(),
                    ], $maintenant);
                    break;
                case 'etat':
                    $r = $esc->etat($numero, $sesame, $maintenant);
                    break;
                case 'fil':
                    $r = $esc->fil($numero, $sesame, mt_rand(0, 1) ? tordu() : null, $maintenant);
                    break;
                case 'repondre':
                    $r = $esc->repondre($numero, tordu(), $maintenant);
                    break;
                case 'trancher':
                    // « accepte » deux fois sur quatre : c'est la seule porte
                    // vers le ré-enrôlement, et donc vers les propriétés du
                    // sésame consommé.
                    $verdict = $guide ? 'accepte' : ['accepte', 'refuse', tordu()][mt_rand(0, 2)];
                    $r = $esc->trancher($numero, $verdict, tordu(), $maintenant);
                    break;
                case 'degeler':
                    $r = $esc->degeler(parfois('alice'), tordu(), $maintenant);
                    break;
                case 'reEnroler':
                    // Un mot de passe reconnaissable et assez long pour franchir
                    // le plancher : sinon on ne teste que le refus de forme.
                    $motDePasseChoisi = 'MotDePasseTemoin-' . bin2hex(random_bytes(4));
                    $motDePasseSoumis = ($guide || mt_rand(0, 4)) ? $motDePasseChoisi : tordu();
                    $r = $esc->reEnroler(
                        $numero,
                        $sesame,
                        $motDePasseSoumis,
                        ($guide || mt_rand(0, 4)) ? $MOT : tordu(),
                        ($guide || mt_rand(0, 4)) ? $SEL : tordu(),
                        $maintenant,
                    );
                    if (($r['ok'] ?? false) === true) {
                        $reussiCePas = true;
                        $profonds++;
                        $sesamesConsommes[] = $sesame;

                        // ⚠️ Éprouvé ICI, pas au pas suivant : un ré-enrôlement
                        // qui tombe en dernier pas d'une séquence ne serait
                        // jamais suivi de rien, et la propriété ne serait pas
                        // vérifiée du tout. Mesuré — le défaut « clore laisse le
                        // sésame valable » restait vert pour cette seule raison.
                        foreach ($numeros as $n) {
                            foreach (['etat', 'fil'] as $porte) {
                                $rr = $porte === 'etat'
                                    ? $esc->etat($n, $sesame, $maintenant)
                                    : $esc->fil($n, $sesame, null, $maintenant);
                                if (($rr['ok'] ?? false) === true) {
                                    $signaler('⭐ un sésame consommé ne rouvre plus aucune porte',
                                        $porte . '() a rouvert ' . $n . ' avec un sésame déjà utilisé');
                                    break 2;
                                }
                            }
                        }

                        // ⭐ Le plancher de longueur ne se contourne pas : un
                        // ré-enrôlement réussi implique un mot de passe recevable.
                        if (mb_strlen($motDePasseSoumis) < Escalade::MOT_DE_PASSE_MINIMUM) {
                            $signaler('⭐ un mot de passe sous le plancher n\'est jamais accepté',
                                'accepté avec ' . mb_strlen($motDePasseSoumis) . ' caractères');
                        }
                    }
                    break;
                default:
                    $r = ['ok' => true, 'efface' => $esc->purger($maintenant)];
            }
        } catch (\Throwable $e) {
            // ⚠️ La bibliothèque promet de ne JAMAIS lever pour un refus. Une
            // exception ici est donc toujours un défaut, jamais un cas limite.
            $signaler(
                'aucune exception pour un refus',
                $op . ' a levé ' . $e::class . ' : ' . $e->getMessage()
                . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
            );
            break;
        }

        $journal[] = $op . ' → ' . (($r['ok'] ?? false) ? 'ok' : ('refus:' . ($r['error'] ?? '?')));

        // ── Les propriétés, vérifiées après CHAQUE appel ────────────────────

        if (!array_key_exists('ok', $r) || !is_bool($r['ok'])) {
            $signaler('toute réponse porte un `ok` booléen', $op . ' a rendu ' . json_encode(array_keys($r)));
        }
        if (($r['ok'] ?? true) === false && !is_string($r['message'] ?? null)) {
            $signaler('tout refus porte un message lisible', $op . ' a refusé sans message');
        }
        // 🔑 On cherche la VALEUR, pas la clé. Une première version énumérait
        // les clés interdites (`mot_de_passe`, `passphrase`…) : il suffisait de
        // renvoyer le mot de passe sous la clé `password` pour passer au vert.
        // Un secret qui fuit ne demande la permission d'aucun nom de champ.
        $plat = json_encode($r, JSON_UNESCAPED_UNICODE) ?: '';
        if ($motDePasseSoumis !== '' && strlen($motDePasseSoumis) > 8 && str_contains($plat, $motDePasseSoumis)) {
            $signaler('⭐ le niveau 3 ne renvoie JAMAIS le mot de passe soumis',
                $op . ' l\'a fait ressortir : ' . substr($plat, 0, 120));
        }

        $apres = photo($st);
        if (!$reussiCePas && $apres !== $avant) {
            // ⚠️ `json_encode` rend `false` sur ce qu'il ne sait pas encoder, et la
            // fermeture promettait `string` sous `strict_types` : la sonde mourait
            // d'une TypeError au lieu de rapporter la violation qu'elle venait de
            // détecter. Le seul chemin où elle sait quelque chose est celui où elle
            // se taisait.
            $plat = static fn ($v): string => var_export($v, true);
            $diff = array_keys(array_diff_assoc(
                array_map($plat, $apres),
                array_map($plat, $avant),
            ));
            $signaler(
                '⭐ rien du compte ne bouge sans un reEnroler réussi',
                $op . ' a modifié : ' . implode(', ', $diff)
            );
            break;
        }
        $avant       = $apres;
        $reussiCePas = false;

        // Le faisceau part en base par `json_encode` : s'il rend `false` sur de
        // l'UTF-8 invalide, un `(string)` le range en chaîne VIDE, sans erreur.
        foreach ($st->litiges as $l) {
            if ($l['faisceau'] !== null && ($l['faisceau'] === '' || json_decode($l['faisceau'], true) === null)) {
                $signaler(
                    '⭐ le faisceau rangé est toujours du JSON relisible',
                    'faisceau illisible sur ' . $l['numero'] . ' (' . strlen((string) $l['faisceau']) . ' octets)'
                );
                break 2;
            }
        }

        // ⭐ Un sésame qui a servi ne rouvre plus rien. Le canari a montré que la
        // sonde d'équivalence ne touchait jamais `cloreLitige` : un `claim_hash`
        // laissé en place rendait le sésame réutilisable, et c'était vert.
        // Ici on le rejoue sur les TROIS portes, pas seulement celle du
        // ré-enrôlement — `fil()` et `etat()` ne regardent pas le statut.
        foreach ($sesamesConsommes as $consomme) {
            foreach ($numeros as $n) {
                foreach (['etat', 'fil'] as $porte) {
                    $rr = $porte === 'etat'
                        ? $esc->etat($n, $consomme, $maintenant)
                        : $esc->fil($n, $consomme, null, $maintenant);
                    if (($rr['ok'] ?? false) === true) {
                        $signaler('⭐ un sésame consommé ne rouvre plus aucune porte',
                            $porte . '() a rouvert ' . $n . ' avec un sésame déjà utilisé');
                        break 3;
                    }
                }
            }
        }

        // ⭐ Un dossier ne porte JAMAIS le sésame, dans aucun de ses champs.
        //
        // ⚠️ Ce contrôle ne regardait que `empreinte_sesame`, c'est-à-dire la
        // seule colonne dont le nom promet qu'elle ne le contient pas. Une fuite
        // par le faisceau, par un message recopié ou par un champ ajouté plus
        // tard passait donc inaperçue. On sérialise le dossier entier et on
        // cherche dedans : c'est la question qu'on voulait poser.
        foreach ($st->litiges as $l) {
            $serialise = var_export($l, true);
            foreach ($sesames as $ses) {
                if ($ses !== '' && str_contains($serialise, $ses)) {
                    $signaler('⭐ un dossier ne stocke jamais le sésame en clair',
                        'sésame trouvé dans ' . $l['numero'] . ' — ' . substr($serialise, 0, 160));
                    break 3;
                }
            }
        }
    }
}

// ── Verdict ─────────────────────────────────────────────────────────────────

echo str_repeat('=', 72) . "\n";
// ⚠️ Un fuzzer qui n'atteint jamais l'état profond rend le même vert qu'un
// fuzzer qui n'y trouve rien. Le compte de ré-enrôlements réussis est donc
// affiché : à zéro, le vert ne vaut rien et il faut le dire.
printf("  États profonds atteints : %d ré-enrôlement(s) réussi(s) sur %d tours.\n", $profonds, $tours);
if ($profonds === 0) {
    fwrite(STDERR, "  ⚠️  AUCUN ré-enrôlement réussi : ce vert ne mesure pas les propriétés du sésame consommé.\n");
}
if ($violations === []) {
    printf("  Aucune violation sur %d tours (graine %d).\n", $tours, $graine);
    echo str_repeat('=', 72) . "\n\n";
    exit(0);
}

// Une violation par propriété suffit à la corriger ; les autres sont du bruit.
$vues = [];
foreach ($violations as $v) {
    if (isset($vues[$v['propriete']])) {
        continue;
    }
    $vues[$v['propriete']] = true;
    printf("  ❌ %s\n     tour %d — %s\n", $v['propriete'], $v['tour'], $v['detail']);
    if ($v['journal'] !== []) {
        echo "     séquence : " . implode(' · ', $v['journal']) . "\n";
    }
    echo "\n";
}
printf("  %d violation(s) sur %d tours, %d propriété(s) distincte(s). Graine %d.\n",
    count($violations), $tours, count($vues), $graine);
echo str_repeat('=', 72) . "\n\n";

exit(1);
