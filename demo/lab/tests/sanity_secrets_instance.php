#!/usr/bin/env php
<?php
/**
 * MySelf-Lab — un secret qui ne peut pas s'écrire doit refuser, jamais servir.
 *
 * Usage : php demo/lab/tests/sanity_secrets_instance.php
 *
 * Ce que ce banc éprouve, et pourquoi il existe : le 09/09/2026, trois fonctions
 * du lab généraient chacune leur secret sans lire le retour de `file_put_contents`.
 * Sur un répertoire non inscriptible — l'état exact de la production ce jour-là —
 * `Security::csrfSecret()` rendait la constante `csrf|`, et rien ne le disait :
 * `hash_hmac` accepte n'importe quelle clé, l'application continuait de servir des
 * jetons que quiconque lit le dépôt pouvait recalculer.
 *
 * 🔑 **Huit défauts replantés, huit rougissements.** Le plus instructif est le sixième :
 * remplacer l'`exit(6)` de `verify-log` par un `break` — c'est ce que le premier jet du
 * correctif portait, et le banc ne le voyait pas tant que sa section 6 n'existait pas.
 * Les autres : écriture non contrôlée · `strlen()` retiré de `SecretInstance` · prise
 * d'environnement vide ignorée · `csrfSecret()` ramené à son ancien corps · sceau vide
 * au lieu de `null` · empreinte redevenue un condensat nu · `chmod` de la base neuve
 * retiré de `Db::pdo()`.
 *
 * ⚠️ Un de ces sept passait pour une mauvaise raison : sans la garde, une prise vide part
 * comme CHEMIN et l'échec survient plus loin, au renommage. Le banc voyait une exception
 * et concluait que la propriété était tenue. Il lit maintenant le message, pas la levée.
 *
 * Conformément à `AGENTS.md`, le jeu de défauts plantés ne vit pas dans le dépôt.
 *
 * ⚠️ **Ce que ce banc ne garde pas.**
 * - Le scan anti-régression (§3) ne reconnaît que `random_bytes`/`randomBytes` comme
 *   source d'aléa. Un secret produit par un autre appel — `generateAdminRecoveryKey`,
 *   par exemple — lui échappe.
 * - Il n'éprouve pas le cas où `chmod` échoue faute d'être propriétaire du fichier.
 */

declare(strict_types=1);

// La base et les quatre secrets de ce banc, à lui seul — jamais ceux de `data/`.
require __DIR__ . '/instance_isolee.inc';

// Le profil Argon2id vient de la bibliothèque, jamais d'une valeur recopiée ici :
// `check-profil-unique.sh` refuse un second porteur, et il a raison. L'autoload sans
// Composer suffit, comme pour la console SU.
foreach ([
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../bi-self/selfrecover/src/autoload.php',
] as $chemin) {
    if (is_file($chemin)) {
        require_once $chemin;
        break;
    }
}

require_once __DIR__ . '/../lib/secret_instance.php';
require_once __DIR__ . '/../lib/su_audit.php';

// Le sel de l'instance ne doit pas bouger : un remplacement rendrait introuvables
// tous les codes déjà émis. La prise existe pour cela.
$bacSel = chemin_isole('.sitesalt-banc');
putenv('LAB_SITESALT_PATH=' . $bacSel);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/dataguard.php';
require_once __DIR__ . '/../lib/journal_moderation.php';

use Pierroons\MySelfLab\Auth;
use Pierroons\MySelfLab\DataGuard;
use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\JournalModeration;
use Pierroons\MySelfLab\SecretInstance;
use Pierroons\MySelfLab\Security;
use Pierroons\MySelfLab\SuAudit;

// La base que consulte un secret absent avant de se poser. Sans cette prise, le
// banc la demanderait à la base de l'instance locale, qui n'est pas la sienne.
putenv('LAB_DB_PATH=' . sys_get_temp_dir() . '/lab-secrets-sans-base-' . getmypid() . '.db');

/** Ce que déclare un secret de banc : rien ne dépend de lui. */
$rien = static fn (): ?string => null;

$passes = 0;
$echecs = 0;
$sautes = 0;

function verifier(string $intitule, bool $condition, string $detail = ''): void
{
    global $passes, $echecs;
    $condition ? $passes++ : $echecs++;
    echo ($condition ? "  \u{2705} " : "  \u{274C} ") . $intitule . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function section(string $titre): void
{
    echo "\n→ {$titre}\n";
}

/** Le corps d'une méthode, tel qu'il est écrit dans le fichier. */
function corpsDe(string $classe, string $methode): string
{
    $r      = new ReflectionMethod($classe, $methode);
    $lignes = file((string) $r->getFileName(), FILE_IGNORE_NEW_LINES);

    return implode("\n", array_slice(
        (array) $lignes,
        $r->getStartLine() - 1,
        $r->getEndLine() - $r->getStartLine() + 1
    ));
}

/**
 * Les écritures d'aléa dont le retour n'est pas lu, **appel par appel**.
 *
 * Interroger le fichier entier déclarerait sain tout fichier qui contient une
 * écriture contrôlée à côté d'une qui ne l'est pas — c'est-à-dire le premier
 * fichier de `lib/` qui gagnera un second secret.
 */
function ecrituresNonControlees(string $source): array
{
    // 🔑 Les commentaires sont retirés par l'analyseur de PHP lui-même, pas par une
    // regex. Un docblock qui NOMME `file_put_contents` — ce fichier-là en a — était
    // pris pour un appel ; l'inverse est plus grave, une regex qui contourne les
    // commentaires à la main finit par manquer du code. Les sauts de ligne sont
    // conservés pour que les numéros rendus restent ceux du fichier.
    $src = '';
    foreach (token_get_all($source) as $t) {
        if (is_array($t)) {
            $src .= in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)
                ? str_repeat("\n", substr_count($t[1], "\n"))
                : $t[1];
        } else {
            $src .= $t;
        }
    }

    $trouves = [];
    $offset  = 0;
    while (($pos = strpos($src, 'file_put_contents', $offset)) !== false) {
        $fin    = strpos($src, ';', $pos);
        $phrase = substr($src, $pos, ($fin === false ? strlen($src) : $fin) - $pos);
        if (preg_match('/random_bytes|randomBytes/', $phrase) === 1
            && preg_match('/(===|!==)\s*false/', $phrase) !== 1) {
            $trouves[] = substr_count(substr($src, 0, $pos), "\n") + 1;
        }
        $offset = $pos + 1;
    }

    return $trouves;
}

/** Tous les `.php` sous un répertoire, sous-dossiers compris. */
function phpSous(string $racine): array
{
    if (!is_dir($racine)) {
        return [];
    }
    $out = [];
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $out[] = $f->getPathname();
        }
    }
    sort($out);

    return $out;
}

/** Lance la console SU dans un environnement isolé, et rend son code et sa sortie. */
function console(string $args, array $env): array
{
    $prefixe = '';
    foreach ($env as $k => $v) {
        $prefixe .= $k . '=' . escapeshellarg((string) $v) . ' ';
    }
    $sortie = [];
    $code   = 0;
    exec(
        $prefixe . 'php ' . escapeshellarg(__DIR__ . '/../selfrecover-su') . ' ' . $args . ' 2>&1',
        $sortie,
        $code
    );

    return ['code' => $code, 'texte' => implode("\n", $sortie)];
}

// Dans le dossier de l'instance isolée, qui s'efface à la sortie, fichiers à point
// compris : rangé à part dans `/tmp`, ce bac y laissait à chaque passage un dossier de
// soixante-neuf fichiers, dont des secrets de banc (mesuré par la huitième passe d'audit).
$bac = chemin_isole('bac');
mkdir($bac, 0700, true);

echo "\n" . str_repeat('=', 63) . "\n";
echo "  Secrets d'instance du lab — refuser plutôt que servir\n";
echo str_repeat('=', 63) . "\n";

// ─────────────────────────────────────────────────────────────────────────────
section('1. Un répertoire fermé fait lever, il ne fait pas rendre une chaîne vide');

$ferme = $bac . '/ferme';
mkdir($ferme, 0500, true);
chmod($ferme, 0500);

$racine = function_exists('posix_geteuid') && posix_geteuid() === 0;
if ($racine) {
    // Un contrôle qui ne peut pas s'exécuter ne rend pas le même verdict qu'un
    // contrôle réussi : root écrit dans un répertoire en 0500, la propriété n'est
    // pas éprouvable ici et le banc le dit plutôt que de compter un vert.
    $sautes++;
    echo "  ⏭️  section non éprouvable sous root (0500 ne ferme rien) — signalé, pas compté\n";
} else {
    putenv('BANC_SECRET_PATH=' . $ferme . '/.secret-x');
    $leve  = false;
    $rendu = null;
    try {
        $rendu = SecretInstance::lire('.secret-x', 48, 32, 'BANC_SECRET_PATH', $rien);
    } catch (RuntimeException $e) {
        $leve = true;
    }
    verifier(
        'écriture impossible → RuntimeException',
        $leve,
        $leve ? '' : 'a rendu ' . var_export($rendu, true)
    );
    verifier('aucune valeur de repli n\'est rendue', $rendu === null);

    // Le porteur historique, exercé par le même chemin.
    putenv('LAB_SITESALT_PATH=' . $ferme . '/.sitesalt');
    $leveSel = false;
    try {
        Auth::siteSalt();
    } catch (RuntimeException $e) {
        $leveSel = true;
    }
    verifier('Auth::siteSalt() lève aussi, par le même porteur', $leveSel);
    putenv('LAB_SITESALT_PATH=' . $bacSel);
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. C\'est la longueur qui qualifie le secret, pas sa présence');

foreach (['.tronque' => 'court', '.vide' => ''] as $nom => $contenu) {
    file_put_contents($bac . '/' . $nom, $contenu);
    putenv('BANC_SECRET_PATH=' . $bac . '/' . $nom);
    $leve = false;
    try {
        SecretInstance::lire($nom, 48, 32, 'BANC_SECRET_PATH', $rien);
    } catch (RuntimeException $e) {
        $leve = true;
    }
    verifier("un fichier « {$nom} » est refusé", $leve);
}

putenv('BANC_SECRET_PATH=' . $bac . '/.neuf');
$neuf = SecretInstance::lire('.neuf', 48, 32, 'BANC_SECRET_PATH', $rien);
verifier('un secret neuf est créé et dépasse la longueur exigée', strlen($neuf) >= 32, strlen($neuf) . ' caractères');
verifier('le même appel rend la même valeur', SecretInstance::lire('.neuf', 48, 32, 'BANC_SECRET_PATH', $rien) === $neuf);
verifier('le fichier n\'est lisible que par son propriétaire', (fileperms($bac . '/.neuf') & 0o777) === 0o600);
verifier('aucun fichier temporaire ne subsiste', glob($bac . '/.neuf.*.tmp') === []);

// 🔑 Une variable POSÉE MAIS VIDE n'est pas une variable absente. Retomber en
// silence sur le chemin par défaut ferait tirer un sel neuf dans `data/`, et tous
// les codes de récupération déjà émis deviendraient introuvables.
putenv('BANC_SECRET_PATH=');
$causeVide = '';
try {
    SecretInstance::lire('.neuf', 48, 32, 'BANC_SECRET_PATH', $rien);
} catch (RuntimeException $e) {
    $causeVide = $e->getMessage();
}
// 🔑 Le message, pas seulement la levée. Sans la garde, la chaîne vide part comme
// chemin et l'échec survient plus loin, au renommage : le banc voyait une exception
// et concluait que la propriété était tenue. Mesuré au canari le 09/09/2026 — le
// contrôle répondait, sur une grandeur voisine.
verifier(
    'une prise posée mais vide est refusée, pas ignorée',
    str_contains($causeVide, 'posée mais vide'),
    $causeVide === '' ? 'aucune levée' : $causeVide
);
putenv('BANC_SECRET_PATH');

// ─────────────────────────────────────────────────────────────────────────────
section('3. Les quatre secrets passent par le porteur unique, et ne se mélangent plus');

$corpsServeur = corpsDe('Pierroons\\MySelfLab\\Security', 'secretServeur');
$corpsCsrf    = corpsDe('Pierroons\\MySelfLab\\Security', 'csrfSecret');
$corpsBlind   = corpsDe('Pierroons\\MySelfLab\\DataGuard', 'blindKey');
$corpsSel     = corpsDe('Pierroons\\MySelfLab\\Auth', 'siteSalt');
$corpsJournal = corpsDe('Pierroons\\MySelfLab\\JournalModeration', 'log');
$delegues     = 0;

foreach ([
    'Security::secretServeur' => [$corpsServeur, '.serversecret'],
    'DataGuard::blindKey'     => [$corpsBlind, '.blindkey'],
    'Auth::siteSalt'          => [$corpsSel, '.sitesalt'],
    'JournalModeration::log'  => [$corpsJournal, '.modaudit'],
] as $nom => [$corps, $fichier]) {
    $passe = str_contains($corps, 'SecretInstance::lire')
        && str_contains($corps, $fichier)
        && !str_contains($corps, 'file_put_contents');
    verifier("{$nom} délègue à SecretInstance ({$fichier})", $passe);
    $passe && $delegues++;
}

verifier(
    'le secret CSRF ne partage plus son fichier avec le chiffrement des coffres',
    str_contains($corpsCsrf, 'self::secretServeur()') && !str_contains($corpsServeur, '.blindkey')
);

// 🔑 Un secret ne déclare qu'une fois ce qui dépend de lui, donc il n'est lu qu'à un
// endroit. Lu de deux, le premier appel venu le posait : le jeton CSRF d'une page
// anonyme créait le secret serveur sans demander si des drapeaux attendaient l'ancien.
// ⚠️ Lu par JETONS et sur le fichier entier : une recherche ligne à ligne laissait
// passer un second lecteur écrit sur deux lignes (mesuré par la sixième passe
// d'audit). Et sur tout le lab hors bancs, scripts de la racine compris.
$sources = array_merge(
    phpSous(__DIR__ . '/../lib'),
    phpSous(__DIR__ . '/../public'),
    phpSous(__DIR__ . '/../tools'),
    glob(__DIR__ . '/../*.php') ?: [],
    [__DIR__ . '/../selfrecover-su']
);
$lectures = [];
foreach ($sources as $fichier) {
    $jetons = array_values(array_filter(
        token_get_all((string) file_get_contents($fichier)),
        static fn ($j): bool => !is_array($j) || !in_array($j[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
    ));
    // Les noms sous lesquels la classe se lit dans CE fichier : le sien, et tout alias
    // qu'un `use … as` lui donne — sans quoi `SI::lire()` passait inaperçu (mesuré par la
    // septième passe d'audit). Un appel par un nom que ce contrôle ne reconnaît pas
    // (`use` groupé avec alias, `$c::lire()`, `call_user_func`) est attrapé par le
    // suivant, s'il est écrit dans un autre fichier que celui du lecteur.
    $noms = ['SecretInstance', 'Pierroons\\MySelfLab\\SecretInstance'];
    foreach ($jetons as $k => $j) {
        if (is_array($j) && $j[0] === T_USE && is_array($jetons[$k + 1] ?? null)
            && str_ends_with($jetons[$k + 1][1], 'SecretInstance')
            && is_array($jetons[$k + 2] ?? null) && $jetons[$k + 2][0] === T_AS && is_array($jetons[$k + 3] ?? null)) {
            $noms[] = $jetons[$k + 3][1];
        }
    }
    foreach ($jetons as $k => $j) {
        $classe = preg_replace('/^namespace\\\\/i', '', ltrim(is_array($j) ? $j[1] : '', '\\'));
        if (!in_array($classe, $noms, true)
            || !is_array($jetons[$k + 2] ?? null) || strtolower($jetons[$k + 2][1]) !== 'lire') {
            continue;
        }
        $premier = $jetons[$k + 4] ?? null;
        $nomSecret = is_array($premier) && $premier[0] === T_CONSTANT_ENCAPSED_STRING
            ? trim($premier[1], "'\"")
            : '(nom non littéral)';
        $lectures[$nomSecret][] = basename($fichier) . ':' . $j[2];
    }
}
verifier(
    '⭐ chaque secret n\'est lu qu\'à un seul endroit, qui déclare ce qui en dépend',
    count($lectures) === 4 && array_filter($lectures, static fn (array $l): bool => count($l) > 1) === [],
    json_encode($lectures, JSON_UNESCAPED_SLASHES)
);

// Le même contrôle par l'autre bout : le NOM du secret, en chaîne, ne figure que dans
// le fichier de son lecteur. Il ne dépend pas de la forme de l'appel — un second
// lecteur écrit `namespace\SecretInstance::lire()` ou par `call_user_func` passait le
// précédent (mesuré par la huitième passe d'audit). ⚠️ Reste invisible : un nom
// composé à l'exécution, ou un second appel dynamique dans le fichier même du lecteur.
$nomsVus = [];
foreach ($sources as $fichier) {
    foreach (token_get_all((string) file_get_contents($fichier)) as $j) {
        if (is_array($j) && $j[0] === T_CONSTANT_ENCAPSED_STRING && isset($lectures[trim($j[1], "'\"")])) {
            $nomsVus[trim($j[1], "'\"")][basename($fichier)] = true;
        }
    }
}
verifier(
    '⭐ le nom de chaque secret n\'apparaît que dans le fichier de son lecteur',
    count($nomsVus) === 4 && array_filter($nomsVus, static fn (array $f): bool => count($f) > 1) === [],
    json_encode(array_map('array_keys', $nomsVus), JSON_UNESCAPED_SLASHES)
);

// Le contrôle qui attrape le jumeau suivant, appel par appel plutôt que fichier
// par fichier, et sur tout le module plutôt que sur le seul `lib/`.
$fautifs = [];
foreach (array_merge(phpSous(__DIR__ . '/../lib'), phpSous(__DIR__ . '/../public')) as $fichier) {
    foreach (ecrituresNonControlees((string) file_get_contents($fichier)) as $ligne) {
        $fautifs[] = basename($fichier) . ':' . $ligne;
    }
}
verifier(
    'aucune écriture de secret sans lecture du retour, dans lib/ ni public/',
    $fautifs === [],
    $fautifs === [] ? '' : implode(', ', $fautifs)
);

// ─────────────────────────────────────────────────────────────────────────────
section('4. Une base inaccessible nomme sa cause et sa prise');

if ($racine) {
    $sautes++;
    echo "  ⏭️  section non éprouvable sous root — signalé, pas compté\n";
} else {
    putenv('LAB_DB_PATH=' . $ferme . '/lab.db');
    $message = '';
    try {
        Db::pdo();
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }
    verifier('l\'échec porte le nom de la prise', str_contains($message, 'LAB_DB_PATH'), $message);
    verifier('l\'échec nomme l\'utilisateur à qui l\'accès manque', str_contains($message, '«'));
    verifier(
        'ce n\'est plus une PDOException nue',
        $message !== '' && !str_contains($message, 'unable to open database file')
    );
    putenv('LAB_DB_PATH=' . instance_isolee()['LAB_DB_PATH']);   // rendue à l'isolement, jamais à `data/`
}

// ─────────────────────────────────────────────────────────────────────────────
section('5. Le journal sait dire que le secret a changé — et qu\'il ne sait pas');

$etat = $bac . '/etat';
mkdir($etat, 0700, true);
putenv('SELFRECOVER_STATE_DIR=' . $etat);
putenv('SELFRECOVER_SU_AUDIT_SECRET=banc-secrets-instance');
putenv('SU_FORENSIC_MINIMAL=1');
putenv('SELFRECOVER_NTFY_URL');

SuAudit::append(SuAudit::ACTION_ADD_ADMIN, 'quelqu-un', ['note' => 'entrée sans empreinte']);
verifier(
    'un journal sans empreinte ne rend pas un sceau — il rend null',
    SuAudit::dernierSceau() === null
);

// Une valeur quelconque suffit : c'est l'empreinte qu'on éprouve, pas le secret.
// Écrire ici un vrai hash Argon2id ferait de ce banc un second porteur du profil,
// ce que `check-profil-unique.sh` refuse à juste titre.
$secretFictif = 'secret-fictif-du-banc-des-secrets-d-instance';
$empreinte    = SuAudit::empreinteDe($secretFictif);
verifier(
    'l\'empreinte est un HMAC, pas un condensat nu du secret',
    $empreinte !== hash('sha256', $secretFictif)
);

SuAudit::append(SuAudit::ACTION_CHANGE_PASS, 'SU', ['store' => 'su-secret', 'empreinte' => $empreinte]);
$sceau = SuAudit::dernierSceau();
verifier('l\'empreinte posée est relue', is_array($sceau) && $sceau['empreinte'] === $empreinte);
verifier('le sceau porte la date à laquelle il a été vu', is_array($sceau) && ($sceau['ts_paris'] ?? '') !== '');
verifier(
    'une empreinte différente est vue comme différente',
    is_array($sceau) && !hash_equals($sceau['empreinte'], SuAudit::empreinteDe('un-autre-hash'))
);
verifier('et le journal reste intègre après ces écritures', (SuAudit::verify()['ok'] ?? false) === true);

// ─────────────────────────────────────────────────────────────────────────────
section('6. La console distingue trois issues par son CODE DE SORTIE, pas par du texte');

// 🔑 C'est la section qui manquait au premier jet du correctif : « je ne peux pas
// trancher » y sortait à 0, comme « tout concorde ». Une tâche planifiée qui alerte
// sur un code non nul n'aurait rien vu, et le faux vert que ce mécanisme ferme se
// serait logé dans le mécanisme.
$su   = $bac . '/su';
mkdir($su, 0700, true);
$pass = 'correct cheval batterie agrafe';
// Le profil vient de la bibliothèque : deux valeurs pour une même règle, c'est la
// divergence que le secret SU a mis trois semaines à révéler.
file_put_contents($su . '/su-secret', password_hash($pass, PASSWORD_ARGON2ID, Auth::argon2Options()));
$envSu = [
    'SELFRECOVER_STATE_DIR'       => $su,
    'SELFRECOVER_SU_AUDIT_SECRET' => 'banc-console',
    'SELFRECOVER_SU_SECRET_INPUT' => $pass,
    'SU_FORENSIC_MINIMAL'         => '1',
];

$r = console('verify-log', $envSu);
verifier('sans sceau, verify-log ne sort PAS à 0', $r['code'] !== 0, 'code ' . $r['code']);
verifier('et il nomme ce qui reste à établir', str_contains($r['texte'], 'SCEAU NON VÉRIFIÉ'));

$r = console('record-seal', $envSu);
verifier('record-seal réussit', $r['code'] === 0, $r['texte']);

$r = console('verify-log', $envSu);
verifier('après le sceau, verify-log sort à 0', $r['code'] === 0, 'code ' . $r['code']);
verifier('et il dit à quel sceau le secret est conforme', str_contains($r['texte'], 'conforme au sceau'));

$codeAvant = $r['code'];
file_put_contents(
    $su . '/su-secret',
    password_hash('un-tout-autre-secret-du-banc', PASSWORD_ARGON2ID, Auth::argon2Options())
);
$envAutre = ['SELFRECOVER_SU_SECRET_INPUT' => 'un-tout-autre-secret-du-banc'] + $envSu;
$r = console('verify-log', $envAutre);
verifier('un secret remplacé hors journal fait échouer verify-log', $r['code'] === 5, 'code ' . $r['code']);
verifier(
    'et le message n\'affirme pas une cause unique',
    str_contains($r['texte'], 'Trois causes')
);
verifier(
    'les trois issues portent trois codes distincts',
    count(array_unique([$codeAvant, 5, 6])) === 3
);

$r = console('record-seal', $envAutre);
verifier('record-seal acquitte le désaccord et le DIT', $r['code'] === 0 && str_contains($r['texte'], 'REMPLACÉ'), $r['texte']);
verifier('et verify-log repasse au vert', console('verify-log', $envAutre)['code'] === 0);

// Une installation qui pose le secret par l'environnement n'a pas de fichier :
// le sceau doit y fonctionner aussi, sinon l'alarme ne s'éteint jamais.
foreach (glob($su . '/*') ?: [] as $f) {
    @unlink($f);
}
$envEnv = [
    'SELFRECOVER_STATE_DIR'       => $su,
    'SELFRECOVER_SU_AUDIT_SECRET' => 'banc-console-env',
    'SELFRECOVER_SU_SECRET'       => 'secret-pose-par-l-environnement-du-banc',
    'SELFRECOVER_SU_SECRET_INPUT' => 'secret-pose-par-l-environnement-du-banc',
    'SU_FORENSIC_MINIMAL'         => '1',
];
$r = console('record-seal', $envEnv);
verifier('un secret posé par l\'environnement est scellable', $r['code'] === 0, $r['texte']);
verifier('et verify-log le reconnaît', console('verify-log', $envEnv)['code'] === 0);

// ─────────────────────────────────────────────────────────────────────────────
section('7. Une base neuve naît partageable : 0660, fermée aux autres comptes');

// Le site et la console écrivent la même base. Celle que recrée un `reset-db`
// doit rester inscriptible par le groupe commun, quel que soit le masque de qui
// la crée ; une base existante garde le mode que l'exploitation lui a donné.
$base = $bac . '/base/lab.db';
mkdir(dirname($base), 0700, true);
$masque = umask(0022);
putenv('LAB_DB_PATH=' . $base);
Db::fermer();
Db::pdo();
Db::fermer();
clearstatcache();
$mode = fileperms($base) & 0777;
verifier('une base créée sous le masque 022 naît en 0660', $mode === 0660, sprintf('%o', $mode));

chmod($base, 0640);
Db::pdo();
Db::fermer();
clearstatcache();
$mode = fileperms($base) & 0777;
verifier('une base existante garde son mode', $mode === 0640, sprintf('%o', $mode));
umask($masque);
putenv('LAB_DB_PATH=' . instance_isolee()['LAB_DB_PATH']);   // rendue à l'isolement, jamais à `data/`

// ─────────────────────────────────────────────────────────────────────────────
section('8. Un secret absent ne se pose que si rien n\'en dépend');

// 🔑 Le poser est juste sur une instance neuve, et destructeur sur une instance qui a
// déjà chiffré, signé ou indexé sous lui : rien ne dirait que tout est devenu
// illisible ou introuvable. Trouvé secret par secret par la cinquième passe d'audit
// du lot AAD — le sel de site se recréait en silence, et tous les codes émis avec.
putenv('BANC_SECRET_PATH=' . $bac . '/.attendu');
$questions = 0;
$cause = '';
try {
    SecretInstance::lire('.attendu', 48, 32, 'BANC_SECRET_PATH', static function () use (&$questions): ?string {
        $questions++;

        return '3 choses chiffrées';
    });
} catch (RuntimeException $e) {
    $cause = $e->getMessage();
}
verifier(
    '⭐ absent alors que quelque chose en dépend : refus qui le nomme, et rien de posé',
    str_contains($cause, '3 choses chiffrées') && !is_file($bac . '/.attendu'),
    $cause === '' ? 'aucune levée' : $cause
);
verifier('absent, et rien qui en dépende : il se pose', strlen(SecretInstance::lire('.attendu', 48, 32, 'BANC_SECRET_PATH', $rien)) >= 32);
$questions = 0;
SecretInstance::lire('.attendu', 48, 32, 'BANC_SECRET_PATH', static function () use (&$questions): ?string {
    $questions++;

    return 'x';
});
verifier('présent, la question n\'est pas posée', $questions === 0);
putenv('BANC_SECRET_PATH');

// Chacun des quatre par son vrai porteur, sur une base qui ne porte QUE ce qui
// dépend de lui : une base commune aux quatre laissait permuter leurs déclarations
// sans un rouge (mesuré par la sixième passe d'audit). Les tables suffisent nues :
// la question compte des lignes.
$baseQuiPorte = static function (string $table) use ($bac): string {
    $f = $bac . '/regle-' . $table . '.db';
    $p = new PDO('sqlite:' . $f);
    foreach (['recovery_codes', 'flag_digests', 'redteam_reports', 'dm', 'profiles'] as $t) {
        $p->exec("CREATE TABLE {$t} (x)");
    }
    if ($table !== '') {
        $p->exec("INSERT INTO {$table} VALUES (1)");
    }

    return $f;
};
$journalPlein = $bac . '/moderation.log';
file_put_contents($journalPlein, "{}\n");
foreach ([
    '.sitesalt'     => ['LAB_SITESALT_PATH', 'recovery_codes', static fn () => Auth::siteSalt(), 'code(s) de récupération'],
    '.serversecret' => ['LAB_SERVERSECRET_PATH', 'flag_digests', static fn () => Security::secretServeur(), 'empreinte(s) de drapeau'],
    '.blindkey'     => ['LAB_BLINDKEY_PATH', 'redteam_reports', static fn () => DataGuard::hmac('x', 'banc'), 'rapport(s) red team'],
    '.modaudit'     => ['LAB_MODAUDIT_PATH', '', static fn () => (new JournalModeration($journalPlein))->verifier(), 'journal de modération'],
] as $nom => [$prise, $table, $lire, $attendu]) {
    putenv('LAB_DB_PATH=' . $baseQuiPorte($table));
    $chemin = $bac . '/absent' . $nom;
    putenv($prise . '=' . $chemin);
    $cause = '';
    try {
        $lire();
    } catch (RuntimeException $e) {
        $cause = $e->getMessage();
    }
    verifier(
        "⭐ {$nom} absent, ce qui en dépend présent : refus nommé, rien de posé",
        str_contains($cause, $attendu) && !is_file($chemin),
        $cause === '' ? 'aucune levée' : $cause
    );
    putenv($prise);
}
putenv('LAB_SITESALT_PATH=' . $bacSel);

// La course à la création : seize premières requêtes au même instant. Tester
// l'existence puis renommer laissait la fenêtre ouverte, et `rename()` écrase —
// mesuré par l'audit : jusqu'à six secrets différents servis sur un même essai. Une
// requête qui refuse à tort compte aussi : elle ne rend pas le secret des autres.
$enfant = $bac . '/course.php';
file_put_contents($enfant, '<?php
require ' . var_export(__DIR__ . '/../lib/secret_instance.php', true) . ';
while (microtime(true) < (float) $argv[1]) {
}
echo Pierroons\MySelfLab\SecretInstance::lire(".course", 48, 32, "BANC_SECRET_PATH", static fn (): ?string => null);
');
$divergents = 0;
for ($essai = 0; $essai < 30; $essai++) {
    $chemin = $bac . '/.course-' . $essai;
    $depart = sprintf('%.6f', microtime(true) + 0.3);
    $enCours = [];
    for ($k = 0; $k < 16; $k++) {
        $proc = proc_open([PHP_BINARY, $enfant, $depart], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes, null, ['BANC_SECRET_PATH' => $chemin]);
        $enCours[] = [$proc, $tubes];
    }
    $rendus = [];
    foreach ($enCours as [$proc, $tubes]) {
        $rendus[] = trim((string) stream_get_contents($tubes[1]));
        proc_close($proc);
    }
    $final = trim((string) @file_get_contents($chemin));
    if (count(array_unique($rendus)) !== 1 || $rendus[0] !== $final) {
        $divergents++;
    }
}
// ⚠️ Ce cas attrape la publication par renommage à chaque fois (mesuré). La course plus
// étroite — le témoin d'existence lu sans relire le secret — rougit selon la machine :
// une fois sur trois ici, cinq fois sur cinq chez l'audit (mesurés). Ce vert-là est
// une présomption, pas une preuve.
verifier('⭐ seize premières requêtes simultanées reçoivent toutes le même secret, trente fois sur trente', $divergents === 0, "{$divergents} essai(s) sur 30 où les seize n'ont pas reçu le même secret, ou l'une a refusé");
putenv('LAB_DB_PATH=' . instance_isolee()['LAB_DB_PATH']);   // rendue à l'isolement, jamais à `data/`

// ─────────────────────────────────────────────────────────────────────────────
section('9. Un secret disparu se voit sans aucune base, et sa publication ne laisse rien derrière');

// La règle du §8 interroge la base du lab : un processus qui déroute la base sans
// dérouter le secret en interroge une autre. Le témoin posé à côté du secret ne
// dépend d'aucune base (mesuré par la sixième passe d'audit : un banc recréait le sel
// de l'instance en lisant sa propre base, vide).
putenv('LAB_DB_PATH=' . $bac . '/aucune-base.db');
putenv('BANC_SECRET_PATH=' . $bac . '/.disparu');
SecretInstance::lire('.disparu', 48, 32, 'BANC_SECRET_PATH', $rien);
unlink($bac . '/.disparu');
$cause = '';
try {
    SecretInstance::lire('.disparu', 48, 32, 'BANC_SECRET_PATH', $rien);
} catch (RuntimeException $e) {
    $cause = $e->getMessage();
}
verifier(
    '⭐ un secret disparu, rien en base : son témoin suffit à refuser',
    str_contains($cause, 'a existé ici') && !is_file($bac . '/.disparu'),
    $cause === '' ? 'aucune levée' : $cause
);
unlink($bac . '/.disparu.existe');
verifier('le témoin retiré — la décision humaine —, il se pose de nouveau', strlen(SecretInstance::lire('.disparu', 48, 32, 'BANC_SECRET_PATH', $rien)) >= 32);
unlink($bac . '/.disparu.existe');
SecretInstance::lire('.disparu', 48, 32, 'BANC_SECRET_PATH', $rien);
verifier('un secret d\'avant le témoin le gagne à sa première lecture', is_file($bac . '/.disparu.existe'));

// Un témoin que l'identité qui sert ne peut pas poser : le secret se sert, et le
// manque se dit au journal — c'est la seule trace qu'un exploitant en aura.
if ($racine) {
    $sautes++;
    echo "  ⏭️  témoin non posable : non éprouvable sous root — signalé, pas compté\n";
} else {
    $clos = $bac . '/clos';
    mkdir($clos, 0700);
    file_put_contents($clos . '/.servi', bin2hex(random_bytes(48)));
    chmod($clos, 0500);
    $journalErreurs = $bac . '/erreurs.log';
    $avantLog = ini_set('error_log', $journalErreurs);
    putenv('BANC_SECRET_PATH=' . $clos . '/.servi');
    $servi = SecretInstance::lire('.servi', 48, 32, 'BANC_SECRET_PATH', $rien);
    ini_set('error_log', (string) $avantLog);
    chmod($clos, 0700);
    verifier(
        '⭐ un témoin non posable : le secret est servi, et le manque est au journal',
        strlen($servi) >= 32 && !is_file($clos . '/.servi.existe')
            && str_contains((string) @file_get_contents($journalErreurs), 'témoin d\'existence non posable'),
        (string) @file_get_contents($journalErreurs)
    );
}
putenv('BANC_SECRET_PATH');

// `link()` désactivée (ce que conseille un durcissement de PHP) : refus net, et
// aucun candidat laissé sur le disque — il y en avait un à chaque tentative.
$sansLien = $bac . '/sans-lien.php';
file_put_contents($sansLien, '<?php
require ' . var_export(__DIR__ . '/../lib/secret_instance.php', true) . ';
try { Pierroons\MySelfLab\SecretInstance::lire(".lie", 48, 32, "BANC_SECRET_PATH", static fn (): ?string => null); echo "POSE"; }
catch (\RuntimeException $e) { echo "LEVE:" . $e->getMessage(); }
');
$rendu = (string) shell_exec(sprintf(
    'BANC_SECRET_PATH=%s %s -d disable_functions=link %s 2>&1',
    escapeshellarg($bac . '/.lie'),
    escapeshellcmd(PHP_BINARY),
    escapeshellarg($sansLien)
));
verifier(
    '⭐ sans link(), le secret n\'est pas publié, et aucun candidat ne reste',
    str_starts_with($rendu, 'LEVE:') && str_contains($rendu, 'link()') && !is_file($bac . '/.lie')
        && glob($bac . '/.lie.*.tmp') === [],
    $rendu
);

// Une prise de base posée mais vide ne retombe plus sur la base par défaut.
putenv('LAB_DB_PATH=');
$cause = '';
try {
    Db::path();
} catch (RuntimeException $e) {
    $cause = $e->getMessage();
}
verifier('⭐ LAB_DB_PATH posée mais vide est refusée, pas ignorée', str_contains($cause, 'posée mais vide'), $cause === '' ? 'aucune levée' : $cause);
// La même règle chez chacun de ses lecteurs : écrite prise par prise, elle manquait
// toujours à la suivante.
foreach ([
    'LAB_MODERATION_LOG'       => static fn () => (new JournalModeration())->chemin(),
    'SELFRECOVER_SU_AUDIT_LOG' => static fn () => SuAudit::logPath(),
    'SELFRECOVER_STATE_DIR'    => static fn () => SuAudit::logPath(),
] as $prise => $lire) {
    $avant = getenv($prise);
    if ($prise === 'SELFRECOVER_STATE_DIR') {
        putenv('SELFRECOVER_SU_AUDIT_LOG');
    }
    putenv($prise . '=');
    $cause = '';
    try {
        $lire();
    } catch (RuntimeException $e) {
        $cause = $e->getMessage();
    }
    verifier("{$prise} posée mais vide est refusée", str_contains($cause, 'posée mais vide'), $cause === '' ? 'aucune levée' : $cause);
    putenv($avant === false ? $prise : $prise . '=' . $avant);
}

// ─────────────────────────────────────────────────────────────────────────────
section('10. Une session ne se range que sous l\'empreinte de son jeton');

// Rangé en clair, un jeton faisait d'une base volée la clé de toutes les sessions en
// cours. Une base d'avant : une session au jeton en clair, convertie à l'ouverture.
$baseSessions = $bac . '/sessions/lab.db';
mkdir(dirname($baseSessions), 0700, true);
putenv('LAB_DB_PATH=' . $baseSessions);
Db::fermer();
$p = Db::pdo();
$p->exec("INSERT INTO accounts (id, username, pw_hash, pass_hash, recovery_hash, created_at) VALUES (1, 'alice', 'h', 'h', 'h', " . time() . ')');
$jeton = Auth::generateSessionToken();
$cookie = (new ReflectionClassConstant(Auth::class, 'COOKIE'))->getValue();
$p->prepare('INSERT INTO app_sessions (account_id, token, created_at) VALUES (1, ?, ?)')->execute([$jeton, time()]);
// Une base d'avant ne porte pas la trace de la conversion : on la lui retire.
$p->exec("DELETE FROM lab_migrations WHERE nom = 'sessions-sous-empreinte'");
Db::fermer();
$p = Db::pdo();
$range = (string) $p->query('SELECT token FROM app_sessions')->fetchColumn();
verifier(
    '⭐ une session d\'avant est convertie : la base ne porte plus le jeton',
    $range === Db::empreinteJeton($jeton) && !str_contains($range, $jeton)
);
$_COOKIE[$cookie] = $jeton;
verifier('et elle s\'ouvre toujours : personne n\'est déconnecté', (Auth::currentAccount($p)['username'] ?? null) === 'alice');
$_COOKIE[$cookie] = $range;
verifier('l\'empreinte volée dans la base n\'ouvre rien', Auth::currentAccount($p) === null);
unset($_COOKIE[$cookie]);
Db::fermer();
putenv('LAB_DB_PATH=' . instance_isolee()['LAB_DB_PATH']);   // rendue à l'isolement, jamais à `data/`

// ─────────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 63) . "\n";
printf("  ▸ délégations contrôlées sur le source : %d\n", $delegues);
printf("  ▸ sections sautées : %d\n", $sautes);
printf("  Secrets d'instance — %d passés, %d échoués\n", $passes, $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
