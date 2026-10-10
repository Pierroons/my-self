<?php

declare(strict_types=1);

/**
 * `tools/purger.php` dit-il la vérité, et détruit-il quelque chose quand il échoue ?
 *
 * 🔑 **Ce banc lance l'OUTIL, pas le stockage.** La section « atomicité » de
 * `banc_stockage_pdo.php` éprouve `commencerTransaction()`, `annuler` et
 * `valider` ; elle ne dit rien de l'outil qui les appelle. Des primitives justes
 * ne prouvent pas que leur appelant s'en sert.
 *
 * Ce qu'il établit, et rien de plus :
 *   — ⭐ **le compte à blanc annonce exactement ce que la purge efface**, sur la
 *     même base. C'est le cas qui tient le design : les compteurs du contrat
 *     PARTAGENT la clause de leur purge, et sans cette comparaison rien ne le
 *     dirait avant qu'un filtre ajouté d'un seul côté les sépare ;
 *   — le compte à blanc n'écrit RIEN : il lit, donc il ne peut pas détruire ;
 *   — une purge qui lève à mi-chemin ne laisse rien de détruit ;
 *   — l'outil sort en 2 avec une ligne, **sur la sortie d'erreur**, jamais une
 *     trace de pile — c'est le journal d'un planificateur qui la reçoit, et une
 *     trace y porte le chemin du module ;
 *   — aucun compte n'est annoncé tant que rien n'est validé.
 *
 * Ce qu'il n'établit PAS : rien sur la justesse des clauses de purge — c'est le
 * banc du stockage qui les mesure —, rien sur un moteur autre que SQLite.
 *
 * 🔑 **Le préalable se vérifie avant toute mesure.** Sans compte NI dossier
 * périmé en base, « rien n'a été détruit » est vrai sur une table vide : le banc
 * mesurerait le vide et le présenterait comme un résultat. Il SORT en 9 si son
 * décor n'est pas en place.
 *
 * Usage : php bi-self/selfrecover/tests/sanity_purger.php
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfRecover\Etiquette;

const OUTIL  = __DIR__ . '/../tools/purger.php';
const SCHEMA = __DIR__ . '/../schema.sql';

$passes = 0;
$echecs = 0;

function verifier(string $quoi, bool $vrai, string $detail = ''): void
{
    global $passes, $echecs;
    echo ($vrai ? "  \u{2705} " : "  \u{274C} ") . $quoi
        . ($detail !== '' ? " — {$detail}" : '') . "\n";
    $vrai ? $passes++ : $echecs++;
}

function abandonner(string $pourquoi): never
{
    fwrite(STDERR, "\u{26A0}\u{FE0F}  PRÉALABLE ABSENT — {$pourquoi}\n"
        . "Le banc sort sans mesurer : sur un décor incomplet, « rien n'a été détruit » est vrai pour la mauvaise raison.\n");
    exit(9);
}

/** Les colonnes qu'une table exige, lues DANS la base et non supposées. */
function obligatoires(PDO $pdo, string $table): array
{
    $cols = [];
    foreach ($pdo->query("PRAGMA table_info({$table})") as $c) {
        if ((int) $c['notnull'] === 1 && $c['dflt_value'] === null && $c['name'] !== 'id') {
            $cols[] = $c['name'];
        }
    }
    if ($cols === []) {
        abandonner("PRAGMA table_info({$table}) ne rend aucune colonne obligatoire");
    }

    return $cols;
}

/**
 * Une base neuve au schéma du dépôt : un compte, un dossier périmé, et des
 * lignes d'échec anciennes sous étiquette.
 *
 * ⚠️ Les deux insertions lisent leurs colonnes au `PRAGMA`. Nommées en dur, une
 * colonne obligatoire ajoutée au schéma ferait lever PDO : le banc sortirait en
 * 255 au lieu du 9 qu'il promet, et le garde de CI enverrait lire l'outil au
 * lieu du décor.
 */
function decor(bool $casser): string
{
    $fichier = tempnam(sys_get_temp_dir(), 'banc-purge-');
    if ($fichier === false) {
        abandonner('impossible de créer une base temporaire');
    }
    register_shutdown_function(static function () use ($fichier): void {
        foreach ([$fichier, $fichier . '-wal', $fichier . '-shm'] as $compagnon) {
            if (is_file($compagnon)) {
                unlink($compagnon);
            }
        }
    });

    $pdo = new PDO('sqlite:' . $fichier, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec((string) file_get_contents(SCHEMA));

    $cols = obligatoires($pdo, 'accounts');
    $pdo->prepare(
        'INSERT INTO accounts (' . implode(', ', $cols) . ') VALUES ('
        . implode(', ', array_fill(0, count($cols), '?')) . ')',
    )->execute(array_map(static fn (string $c): string => $c === 'username' ? 'titulaire' : 'x', $cols));
    $idCompte = (int) $pdo->lastInsertId();

    $jadis   = time() - 10 * 86400;
    $colsLit = obligatoires($pdo, 'disputes');
    // `expires_at` est NULLABLE, donc absent des obligatoires : c'est lui qui
    // rend le dossier périmé, il s'écrit explicitement.
    // ⚠️ `status` et `expires_at` s'écrivent EXPLICITEMENT : le premier porte un
    // défaut au schéma (`'open'`), le second est nullable — aucun des deux n'est
    // dans les colonnes obligatoires. Laissés au `match`, les trois dossiers
    // naissaient ouverts, et le préalable l'a dit au lieu de mesurer.
    $colonnes = array_values(array_diff($colsLit, ['status']));
    $poser = $pdo->prepare(
        'INSERT INTO disputes (' . implode(', ', [...$colonnes, 'status', 'expires_at']) . ') VALUES ('
        . implode(', ', array_fill(0, count($colonnes) + 2, '?')) . ')',
    );
    // 🔑 **TROIS dossiers périmés, dont deux que la purge doit épargner.** Avec
    // le seul dossier ouvert, un compteur qui recopie la clause et oublie
    // l'exclusion des refusés et des acceptés annonce le même chiffre que la
    // purge : la divergence existe et aucun cas ne la voit. Mesuré — le canari
    // restait vert. Les deux états épargnés sont une propriété du contrat, pas un
    // décor de confort.
    foreach ([['D-PERIME-1', 'open'], ['D-REFUSE-1', 'refused'], ['D-ACCEPTE-1', 'accepted']] as [$numero, $statut]) {
        $poser->execute([...array_map(static fn (string $c): int|string => match ($c) {
            'dispute_number' => $numero,
            'account_id'     => $idCompte,
            default          => $jadis,
        }, $colonnes), $statut, $jadis]);
    }

    // Trois échecs sous étiquette, AU-DELÀ de la rétention par défaut de l'outil.
    //
    // ⚠️ Posés à dix jours, ils tombaient en deçà des trente que `purger.php`
    // garde : la purge n'en effaçait aucun, le compte à blanc annonçait zéro, et
    // les deux « coïncidaient » — sur rien. Un décor doit poser de la matière que
    // l'outil mord, sinon l'accord des deux voies ne vaut rien.
    $vieux = time() - 60 * 86400;
    $ins = $pdo->prepare('INSERT INTO login_attempts (username, success, ip, attempted_at) VALUES (?, 0, ?, ?)');
    foreach ([Etiquette::PREFIXE_L2, Etiquette::PREFIXE_L2_INCONNU, Etiquette::PREFIXE_L1] as $i => $prefixe) {
        $ins->execute([$prefixe . 'ancien' . $i, '192.0.2.' . (10 + $i), $vieux]);
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn() !== 1) {
        abandonner('le compte n\'est pas en base');
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM disputes WHERE expires_at < ' . time())->fetchColumn() !== 3) {
        abandonner('les trois dossiers périmés ne sont pas en base');
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM disputes WHERE status IN ('refused', 'accepted')")->fetchColumn() !== 2) {
        abandonner('les deux dossiers que la purge doit épargner ne sont pas en base');
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn() !== 3) {
        abandonner('les lignes d\'échec ne sont pas en base');
    }
    // 🔑 Présentes ne suffit pas : elles doivent être purgeables par l'outil tel
    // qu'il est lancé. Le seuil est son défaut — trente jours.
    $seuil = time() - 30 * 86400;
    if ((int) $pdo->query('SELECT COUNT(*) FROM login_attempts WHERE attempted_at <= ' . $seuil)->fetchColumn() !== 3) {
        abandonner('les lignes d\'échec sont en base mais plus récentes que la rétention : la purge ne les mordrait pas');
    }

    if ($casser) {
        // La seconde purge doit lever : on retire la table qu'elle lit.
        $pdo->exec('DROP TABLE login_attempts');
        if ((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'login_attempts'")->fetchColumn() !== 0) {
            abandonner('`login_attempts` est toujours là, la seconde purge ne lèverait pas');
        }
    }

    return $fichier;
}

/**
 * Lance l'outil sur une copie du décor et relit la base.
 *
 * ⚠️ Les deux flux sont séparés : fusionnés par `2>&1`, le banc ne peut pas voir
 * lequel a porté la ligne, et « sur la sortie d'erreur » n'est alors pas mesuré.
 * Le partage compte ici — le message d'erreur peut porter le DSN, donc un chemin
 * de disque, et un opérateur redirige souvent la sortie normale vers un relevé.
 */
function lancer(string $decor, string $mode): array
{
    $copie = tempnam(sys_get_temp_dir(), 'banc-purge-c-');
    if ($copie === false || !copy($decor, $copie)) {
        abandonner('impossible de copier la base du décor');
    }
    $fOut = $copie . '.out';
    $fErr = $copie . '.err';
    register_shutdown_function(static function () use ($copie, $fOut, $fErr): void {
        foreach ([$copie, $fOut, $fErr] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    });

    $commande = sprintf(
        'SELFRECOVER_DSN=%s %s -d memory_limit=256M %s %s > %s 2> %s',
        escapeshellarg('sqlite:' . $copie),
        escapeshellarg(PHP_BINARY),
        escapeshellarg(OUTIL),
        $mode,
        escapeshellarg($fOut),
        escapeshellarg($fErr),
    );
    $ignore = [];
    $code   = 0;
    exec($commande, $ignore, $code);

    $lignes = static fn (string $f): array => array_values(array_filter(
        explode("\n", (string) file_get_contents($f)),
        static fn (string $l): bool => trim($l) !== '',
    ));

    $pdo = new PDO('sqlite:' . $copie, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $table = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();

    return [
        'code'     => $code,
        'out'      => $lignes($fOut),
        'err'      => $lignes($fErr),
        'dossiers' => $table("SELECT COUNT(*) FROM disputes WHERE dispute_number = 'D-PERIME-1'"),
        'epargnes' => $table("SELECT COUNT(*) FROM disputes WHERE status IN ('refused', 'accepted')"),
        'echecs'   => $table("SELECT COUNT(*) FROM sqlite_master WHERE name = 'login_attempts'") === 0
            ? -1
            : $table('SELECT COUNT(*) FROM login_attempts'),
    ];
}

/** Le premier entier d'une ligne qui parle de dossiers, ou -1. */
function annonce(array $lignes, string $quoi): int
{
    foreach ($lignes as $l) {
        if (str_contains($l, $quoi) && preg_match('/^(\d+)/', $l, $m) === 1) {
            return (int) $m[1];
        }
    }

    return -1;
}

// ── 1. Sur une base SAINE : le compte à blanc dit-il ce que la purge fait ? ──
$sain = decor(false);
echo "décor sain : 1 compte, 3 dossiers périmés (1 à purger, 2 épargnés), 3 lignes d'échec anciennes\n\n";

echo "le compte à blanc, sur une base saine\n";
$blanc = lancer($sain, '--a-blanc');
verifier('il sort en 0 et n\'écrit rien sur la sortie d\'erreur',
    $blanc['code'] === 0 && $blanc['err'] === [], implode(' | ', $blanc['err']));
verifier('le dossier périmé est toujours là — ce mode LIT', $blanc['dossiers'] === 1);
verifier('les trois lignes d\'échec aussi', $blanc['echecs'] === 3, (string) $blanc['echecs']);

echo "\nla purge réelle, sur la même base\n";
$reel = lancer($sain, '');
verifier('elle sort en 0', $reel['code'] === 0, implode(' | ', $reel['err']));
verifier('le dossier ouvert a disparu', $reel['dossiers'] === 0);
verifier('⭐ mais les dossiers refusé et accepté ont survécu — le contrat les épargne',
    $reel['epargnes'] === 2, (string) $reel['epargnes']);
verifier('les lignes d\'échec anciennes aussi', $reel['echecs'] === 0, (string) $reel['echecs']);

$dBlanc = annonce($blanc['out'], 'dossier');
$lBlanc = annonce($blanc['out'], 'ligne');
$dReel  = annonce($reel['out'], 'dossier');
$lReel  = annonce($reel['out'], 'ligne');
verifier('⭐ le compte à blanc annonçait EXACTEMENT les dossiers effacés',
    $dBlanc === $dReel && $dBlanc === 1, "à blanc {$dBlanc}, réel {$dReel}");
verifier('⭐ et exactement les lignes d\'échec effacées',
    $lBlanc === $lReel && $lBlanc === 3, "à blanc {$lBlanc}, réel {$lReel}");

// ── 2. Sur une base CASSÉE : que laisse l'échec derrière lui ? ───────────────
$casse = decor(true);
echo "\nla purge réelle, quand la seconde moitié lève\n";
$ko = lancer($casse, '');
verifier('⭐ le dossier périmé est toujours là — rien n\'est détruit à moitié', $ko['dossiers'] === 1);
verifier('elle sort en 2, jamais 255', $ko['code'] === 2);
verifier('une seule ligne, et sur la sortie d\'ERREUR',
    count($ko['err']) === 1 && $ko['out'] === [],
    'err ' . count($ko['err']) . ', out ' . count($ko['out']));
verifier('aucune trace de pile, donc aucun chemin de module au journal',
    preg_grep('/Stack trace|^#\d+ /', $ko['err']) === []);
verifier('aucun compte effacé n\'est annoncé alors que rien n\'est validé',
    preg_grep('/dossier\(s\) effacé/', [...$ko['out'], ...$ko['err']]) === []);

echo "\nle compte à blanc, devant la même panne\n";
$koBlanc = lancer($casse, '--a-blanc');
verifier('il ne détruit rien — il n\'écrit jamais', $koBlanc['dossiers'] === 1);
verifier('il sort comme la purge réelle : 2, une ligne sur stderr',
    $koBlanc['code'] === 2 && count($koBlanc['err']) === 1 && $koBlanc['out'] === [],
    'rc ' . $koBlanc['code'] . ', err ' . count($koBlanc['err']) . ', out ' . count($koBlanc['out']));
verifier('aucune trace de pile non plus — le mode prudent n\'est pas le plus bruyant',
    preg_grep('/Stack trace|^#\d+ /', $koBlanc['err']) === []);

$total = $passes + $echecs;
echo "\n" . ($echecs === 0 ? 'OK' : 'ÉCHEC') . " — {$passes}/{$total}\n";
exit($echecs === 0 ? 0 : 1);
