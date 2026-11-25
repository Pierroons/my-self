<?php
declare(strict_types=1);

/**
 * L'état de la démo suit-il SELFRECOVER_SESSIONS_DIR, partout ?
 *
 * Le frein, le masquage des journaux et la purge écrivaient le chemin de
 * l'installation d'origine en dur. Sous une autre identité PHP, la variable seule
 * déplaçait les sessions, pas le reste : les écritures préfixées de `@` du frein
 * échouaient sans bruit, et le plafond de sessions comptait un dossier vide. Ce
 * banc pose les quatre variables sur un dossier temporaire et vérifie que chaque
 * écriture y arrive, que la purge y travaille et refuse un dossier mal nommé, et
 * qu'aucune bibliothèque ne réécrit le chemin en dur.
 *
 * Usage : php tests/sanity_etat.php
 */

$passes = 0;
$echecs = 0;

function verifier(string $intitule, bool $condition, string $detail = ''): void
{
    global $passes, $echecs;
    $condition ? $passes++ : $echecs++;
    echo ($condition ? "  \u{2705} " : "  \u{274C} ") . $intitule . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function effacer(string $chemin): void
{
    if (is_dir($chemin) && !is_link($chemin)) {
        foreach (scandir($chemin) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                effacer($chemin . '/' . $e);
            }
        }
        rmdir($chemin);
    } elseif (file_exists($chemin) || is_link($chemin)) {
        unlink($chemin);
    }
}

function session(string $base, string $id, int $expiresAt): void
{
    mkdir($base . '/' . $id, 0700, true);
    file_put_contents($base . '/' . $id . '/meta.json', json_encode(['expires_at' => $expiresAt]));
}

$racine   = sys_get_temp_dir() . '/duo-etat-' . getmypid();
$base     = $racine . '/demo-sessions';
$jeton    = $racine . '/bypass_token.txt';
$abus     = $racine . '/abuse.log';
$purgeLog = $racine . '/cleanup.log';
mkdir($base, 0700, true);
putenv('SELFRECOVER_SESSIONS_DIR=' . $base);
putenv('SELFRECOVER_BYPASS_TOKEN=' . $jeton);
putenv('SELFRECOVER_ABUSE_LOG=' . $abus);

require_once __DIR__ . '/../lib/session_manager.php';

// Ce qu'un frein resté sur le chemin d'origine écrirait : il ne doit pas apparaître.
$ip            = '203.0.113.7';
$compteurDefaut = DemoSession::BASE_DIR_DEFAUT . '/.counters/ip-' . substr(hash('sha256', 'biself-demo|' . $ip), 0, 16) . '.log';
$avantDefaut   = file_exists($compteurDefaut) ? (string) filemtime($compteurDefaut) : 'absent';

try {
    echo "▸ le dossier des sessions\n";
    verifier('baseDir() rend la variable', DemoSession::baseDir() === $base, DemoSession::baseDir());

    echo "▸ le frein écrit sous ce dossier\n";
    $_SERVER['REMOTE_ADDR'] = $ip;
    $premier = RateLimit::canCreateSession();
    verifier('une première session est acceptée', ($premier['ok'] ?? false) === true, json_encode($premier));
    for ($i = 0; $i < 6; $i++) {
        RateLimit::recordSessionStart($ip);
    }
    verifier('les compteurs vont sous le dossier des sessions', RateLimit::countSessionsForIpLastHour($ip) === 6
        && count(glob($base . '/.counters/ip-*.log') ?: []) === 1);
    $septieme = RateLimit::canCreateSession();
    verifier('la septième demande de l\'heure bannit', ($septieme['reason'] ?? '') === 'abuse_banned', json_encode($septieme));
    verifier('la liste des bannis est sous le dossier des sessions', str_contains((string) @file_get_contents($base . '/.banned_ips'), $ip . ' '));
    verifier('le bannissement se relit', RateLimit::isBanned($ip));
    verifier('le journal d\'abus va à SELFRECOVER_ABUSE_LOG', str_contains((string) @file_get_contents($abus), "ip={$ip} action=banned"));
    $apresDefaut = file_exists($compteurDefaut) ? (string) filemtime($compteurDefaut) : 'absent';
    verifier('rien n\'est écrit au chemin d\'origine', $apresDefaut === $avantDefaut, "{$avantDefaut} → {$apresDefaut}");

    echo "▸ le plafond compte les sessions de ce dossier\n";
    session($base, 'aaaaaaaa-0000-0000-0000-000000000001', time() + 600);
    session($base, 'aaaaaaaa-0000-0000-0000-000000000002', time() + 600);
    session($base, 'aaaaaaaa-0000-0000-0000-000000000003', time() - 60);
    verifier('deux sessions vivantes, une échue : 2', RateLimit::countActiveSessions() === 2, (string) RateLimit::countActiveSessions());
    for ($i = 4; $i <= 11; $i++) {
        session($base, sprintf('aaaaaaaa-0000-0000-0000-%012d', $i), time() + 600);
    }
    $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
    $plein = RateLimit::canCreateSession();
    verifier('dix sessions vivantes : une onzième est refusée', ($plein['reason'] ?? '') === 'capacity_full', json_encode($plein));

    echo "▸ le jeton de contournement se lit à SELFRECOVER_BYPASS_TOKEN\n";
    $secret = bin2hex(random_bytes(16));
    file_put_contents($jeton, $secret . "\n");
    $_COOKIE['sj_bypass'] = $secret;
    verifier('le bon jeton contourne', RateLimit::hasBypassCookie());
    $_COOKIE['sj_bypass'] = str_repeat('0', 32);
    verifier('un autre jeton ne contourne pas', !RateLimit::hasBypassCookie());
    unset($_COOKIE['sj_bypass']);

    echo "▸ le masquage voit le dossier là où il est\n";
    $chemin = $base . '/aaaaaaaa-0000-0000-0000-000000000001/log.jsonl';
    verifier('un chemin de session devient {session_dir}', Redactor::redactLog("ouvert {$chemin}") === 'ouvert {session_dir}/log.jsonl',
        Redactor::redactLog("ouvert {$chemin}"));
    verifier('le reste du dossier devient {state_dir}', !str_contains(Redactor::redactLog($base . '/.counters/x'), $racine));
    // Le chemin en tête : coupée avant d'être masquée, la valeur le garderait en clair.
    $long = Redactor::redactCtx(['m' => $chemin . ' ' . str_repeat('y', 250)]);
    verifier('une valeur longue est masquée avant d\'être coupée', !str_contains($long['m'], $racine), substr($long['m'], 0, 60));

    echo "▸ la purge travaille sur ce dossier, et sur aucun autre\n";
    $purge = __DIR__ . '/../tools/cleanup_demo_sessions.sh';
    $env   = 'SELFRECOVER_CLEANUP_LOG=' . escapeshellarg($purgeLog) . ' ';
    exec($env . 'SELFRECOVER_SESSIONS_DIR=' . escapeshellarg($base) . ' bash ' . escapeshellarg($purge) . ' 2>&1', $sortie, $rc);
    verifier('la purge sort en 0', $rc === 0, implode(' | ', $sortie));
    verifier('la session échue est purgée', !is_dir($base . '/aaaaaaaa-0000-0000-0000-000000000003'));
    verifier('les sessions vivantes restent', is_dir($base . '/aaaaaaaa-0000-0000-0000-000000000001')
        && is_dir($base . '/aaaaaaaa-0000-0000-0000-000000000011'));
    verifier('la liste des bannis reste', is_file($base . '/.banned_ips'));
    mkdir($racine . '/autre/sans-meta', 0700, true);
    foreach (['/' => '/', 'un chemin relatif' => 'demo-sessions', 'un autre dossier' => $racine . '/autre'] as $cas => $mauvais) {
        $sortie = [];
        exec($env . 'SELFRECOVER_SESSIONS_DIR=' . escapeshellarg($mauvais) . ' bash ' . escapeshellarg($purge) . ' 2>&1', $sortie, $rc);
        verifier("la purge refuse {$cas}, en 2", $rc === 2, "code {$rc}");
    }
    verifier('rien n\'est effacé dans le dossier refusé', is_dir($racine . '/autre/sans-meta'));

    echo "▸ une seule source du chemin\n";
    preg_match('/^BASE="\$\{SELFRECOVER_SESSIONS_DIR:-([^}]+)\}"/m', (string) file_get_contents($purge), $m);
    verifier('la purge et la démo ont le même défaut', ($m[1] ?? '') === DemoSession::BASE_DIR_DEFAUT, $m[1] ?? 'motif absent');
    $enDur = [];
    foreach (glob(__DIR__ . '/../lib/*.php') ?: [] as $f) {
        foreach (file($f) ?: [] as $n => $ligne) {
            if (str_contains($ligne, 'demo-sessions') && !str_contains($ligne, 'BASE_DIR_DEFAUT =')
                && !preg_match('~^\s*(\*|//)~', $ligne) && !str_contains($ligne, "'#/var/lib/selfjustice/demo-sessions/")) {
                $enDur[] = basename($f) . ':' . ($n + 1);
            }
        }
    }
    verifier('aucune bibliothèque de la démo n\'écrit le chemin en dur', $enDur === [], implode(', ', $enDur));
} finally {
    putenv('SELFRECOVER_SESSIONS_DIR');
    putenv('SELFRECOVER_BYPASS_TOKEN');
    putenv('SELFRECOVER_ABUSE_LOG');
    effacer($racine);
}

$total = $passes + $echecs;
if ($echecs === 0) {
    echo "OK — {$passes}/{$total} : l'état de la démo suit SELFRECOVER_SESSIONS_DIR.\n";
    exit(0);
}
fwrite(STDERR, "ÉCHEC — {$echecs} contrôle(s) sur {$total}.\n");
exit(1);
