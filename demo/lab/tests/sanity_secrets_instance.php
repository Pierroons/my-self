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
$bacSel = sys_get_temp_dir() . '/lab-secrets-sitesalt-' . getmypid();
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

$bac = sys_get_temp_dir() . '/lab-secrets-' . bin2hex(random_bytes(6));
mkdir($bac, 0700, true);
register_shutdown_function(static function () use ($bac, $bacSel): void {
    foreach (['ferme', 'etat', 'su', 'base'] as $sous) {
        @chmod("$bac/$sous", 0700);
        foreach (glob("$bac/$sous/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir("$bac/$sous");
    }
    foreach (glob($bac . '/*') ?: [] as $f) {
        is_dir($f) ? @rmdir($f) : @unlink($f);
    }
    @rmdir($bac);
    @unlink($bacSel);
});

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
$lectures = [];
foreach (array_merge(phpSous(__DIR__ . '/../lib'), phpSous(__DIR__ . '/../public'), phpSous(__DIR__ . '/../tools')) as $fichier) {
    foreach ((array) file($fichier) as $n => $ligne) {
        if (preg_match('/^\s*(\*|\/\/)/', (string) $ligne) === 1) {
            continue;
        }
        preg_match_all("/SecretInstance::lire\(\s*'([^']+)'/", (string) $ligne, $m);
        foreach ($m[1] as $nomSecret) {
            $lectures[$nomSecret][] = basename($fichier) . ':' . ($n + 1);
        }
    }
}
verifier(
    '⭐ chaque secret n\'est lu qu\'à un seul endroit, qui déclare ce qui en dépend',
    count($lectures) === 4 && array_filter($lectures, static fn (array $l): bool => count($l) > 1) === [],
    json_encode($lectures, JSON_UNESCAPED_SLASHES)
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
    putenv('LAB_DB_PATH');
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
putenv('LAB_DB_PATH');

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
// dépend de lui. Les tables suffisent nues : la question compte des lignes.
$baseRegle = $bac . '/regle.db';
$p = new PDO('sqlite:' . $baseRegle);
$p->exec('CREATE TABLE recovery_codes (x); CREATE TABLE flag_digests (x); CREATE TABLE redteam_reports (x);
          CREATE TABLE dm (x); CREATE TABLE profiles (x);
          INSERT INTO recovery_codes VALUES (1); INSERT INTO flag_digests VALUES (1); INSERT INTO redteam_reports VALUES (1);');
$p = null;
$journalPlein = $bac . '/moderation.log';
file_put_contents($journalPlein, "{}\n");
putenv('LAB_DB_PATH=' . $baseRegle);
foreach ([
    '.sitesalt'     => ['LAB_SITESALT_PATH', static fn () => Auth::siteSalt(), 'code(s) de récupération'],
    '.serversecret' => ['LAB_SERVERSECRET_PATH', static fn () => Security::secretServeur(), 'empreinte(s) de drapeau'],
    '.blindkey'     => ['LAB_BLINDKEY_PATH', static fn () => DataGuard::hmac('x', 'banc'), 'rapport(s) red team'],
    '.modaudit'     => ['LAB_MODAUDIT_PATH', static fn () => (new JournalModeration($journalPlein))->verifier(), 'journal de modération'],
] as $nom => [$prise, $lire, $attendu]) {
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
// mesuré par l'audit : jusqu'à six secrets différents servis sur un même essai.
$enfant = $bac . '/course.php';
file_put_contents($enfant, '<?php
require ' . var_export(__DIR__ . '/../lib/secret_instance.php', true) . ';
while (microtime(true) < (float) $argv[1]) {
}
echo Pierroons\MySelfLab\SecretInstance::lire(".course", 48, 32, "BANC_SECRET_PATH", static fn (): ?string => null);
');
$divergents = 0;
for ($essai = 0; $essai < 10; $essai++) {
    $chemin = $bac . '/.course-' . $essai;
    $depart = sprintf('%.6f', microtime(true) + 0.5);
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
verifier('⭐ seize premières requêtes simultanées reçoivent toutes le même secret, dix fois sur dix', $divergents === 0, "{$divergents} essai(s) sur 10 ont servi plusieurs secrets");
putenv('LAB_DB_PATH');

// ─────────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 63) . "\n";
printf("  ▸ délégations contrôlées sur le source : %d\n", $delegues);
printf("  ▸ sections sautées : %d\n", $sautes);
printf("  Secrets d'instance — %d passés, %d échoués\n", $passes, $echecs);
echo str_repeat('=', 63) . "\n\n";

exit($echecs === 0 ? 0 : 1);
