<?php

declare(strict_types=1);

/**
 * Banc — la démo publique donne à chaque visiteur sa propre base.
 *
 * Lancé contre `php -S` : deux visiteurs, deux cookies, deux bases. Le second ne
 * voit pas le coffre du premier (inspect_db), ne le retrouve pas par son index
 * aveugle (find_user), ne s'y connecte pas. Une base vieille de plus de 30 minutes
 * disparaît ; au plafond, un nouveau visiteur reçoit 503 ; un cookie mal formé est
 * remplacé sans rien créer hors du répertoire des bases.
 *
 * Run:  php demo/selfdataguard/tests/sanity_sessions.php
 * Exit: 0 si tout passe.
 */

$passes = 0;
$echecs = 0;
function ok(string $l): void { global $passes; $passes++; echo "  ✅ {$l}\n"; }
function ko(string $l, string $d = ''): void { global $echecs; $echecs++; echo "  ❌ {$l}" . ($d !== '' ? " — {$d}" : '') . "\n"; }

$demo  = dirname(__DIR__);
$etat  = sys_get_temp_dir() . '/sanity-sdg-sessions-' . getmypid();
@mkdir($etat, 0700, true);
$bases = $etat . '/sessions';

$port    = 9000 + (getmypid() % 1000);
$serveur = proc_open(
    sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($demo)),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $tuyaux,
    null,
    ['DATAGUARD_DB_PATH' => $etat . '/demo.sqlite', 'DATAGUARD_BLINDKEY_PATH' => $etat . '/blindkey.bin',
     'PATH' => getenv('PATH') ?: '/usr/bin:/bin']
);
$debout = false;
for ($i = 0; $i < 50 && !$debout; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($c) { fclose($c); $debout = true; } else { usleep(100000); }
}
if (!$debout) {
    ko('le serveur de la démo n\'a pas démarré — ce banc n\'a rien établi');
    exit(1);
}

/** @return array{code: int, json: array<string, mixed>, cookie: ?string} */
function appel(string $route, ?array $corps, ?string $cookie): array
{
    global $port;
    $entetes = ['Content-Type: application/json'];
    if ($cookie !== null) {
        $entetes[] = 'Cookie: sdg_demo=' . $cookie;
    }
    $contexte = stream_context_create(['http' => [
        'method'        => $corps === null ? 'GET' : 'POST',
        'header'        => implode("\r\n", $entetes),
        'content'       => $corps === null ? '' : json_encode($corps),
        'ignore_errors' => true,
        'timeout'       => 30,
    ]]);
    $brut = (string) @file_get_contents("http://127.0.0.1:{$port}/api/{$route}", false, $contexte);
    $code = 0;
    $rendu = null;
    foreach ($http_response_header ?? [] as $ligne) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $ligne, $m)) { $code = (int) $m[1]; }
        if (preg_match('/^Set-Cookie: sdg_demo=([^;]*)/i', $ligne, $m)) { $rendu = $m[1]; }
    }
    return ['code' => $code, 'json' => json_decode($brut, true) ?? [], 'cookie' => $rendu];
}

echo "\n→ Deux visiteurs, deux bases\n";

$a = appel('register.php', ['userId' => 'alice', 'password' => 'mot-de-passe-alice', 'memorized' => 'sentier-alice',
                            'fields' => ['email' => 'alice@example.org'], 'indexed' => ['email']], null);
$cookieA = $a['cookie'];
$a['code'] === 200 && $cookieA !== null && preg_match('/^[a-f0-9]{32}\z/', $cookieA) === 1
    ? ok('le premier visiteur s\'inscrit et reçoit son cookie')
    : ko('inscription du premier visiteur', $a['code'] . ' ' . json_encode($a['json']));

$b = appel('inspect_db.php', null, null);
$cookieB = $b['cookie'];
$cookieB !== null && $cookieB !== $cookieA
    ? ok('le second visiteur reçoit un autre cookie')
    : ko('le second visiteur partage le cookie du premier');
$nomsB = array_column($b['json']['vaults'] ?? [], 'user_id');
$b['code'] === 200 && !in_array('alice', $nomsB, true)
    ? ok('inspect_db du second visiteur ne montre pas le coffre du premier')
    : ko('le second visiteur voit le coffre du premier dans inspect_db', json_encode($nomsB));

$f = appel('find_user.php', ['fieldName' => 'email', 'value' => 'alice@example.org'], $cookieB);
$f['code'] === 200 && array_key_exists('userId', $f['json']) && $f['json']['userId'] === null
    ? ok('find_user du second visiteur ne retrouve pas le premier par son index aveugle')
    : ko('le second visiteur retrouve le premier par find_user', json_encode($f['json']));

$l = appel('login.php', ['userId' => 'alice', 'secret' => 'mot-de-passe-alice', 'mode' => 'password'], $cookieB);
$l['code'] !== 200
    ? ok('le second visiteur ne se connecte pas au coffre du premier, même avec son mot de passe')
    : ko('le second visiteur a ouvert le coffre du premier');

$retour = appel('inspect_db.php', null, $cookieA);
in_array('alice', array_column($retour['json']['vaults'] ?? [], 'user_id'), true)
    ? ok('le premier visiteur retrouve son coffre avec son cookie')
    : ko('le premier visiteur a perdu son coffre', json_encode($retour['json']));

echo "\n→ Cookie mal formé\n";

$m = appel('inspect_db.php', null, '..%2F..%2Fdemo');
$m['cookie'] !== null && preg_match('/^[a-f0-9]{32}\z/', $m['cookie']) === 1
    ? ok('un cookie mal formé est remplacé par un identifiant neuf')
    : ko('un cookie mal formé a été gardé', (string) $m['cookie']);
$horsBases = array_filter(glob($etat . '/*') ?: [], static fn ($p) => !in_array(basename($p), ['sessions', 'blindkey.bin', 'admin-recovery.pub', 'admin-recovery.sealed'], true));
$horsBases === []
    ? ok('rien n\'est créé hors du répertoire des bases')
    : ko('des fichiers sont apparus hors du répertoire des bases', implode(', ', array_map('basename', $horsBases)));

echo "\n→ Expiration et plafond\n";

$vieille = $bases . '/' . str_repeat('0', 32) . '.sqlite';
touch($vieille, time() - 31 * 60);
appel('inspect_db.php', null, $cookieA);
!is_file($vieille)
    ? ok('une base sans action depuis 31 minutes est effacée à la requête suivante')
    : ko('une base périmée a survécu');

$factices = [];
$vivantes = count(glob($bases . '/*.sqlite') ?: []);
for ($i = $vivantes; $i < 200; $i++) {
    $factice = sprintf('%s/%032x.sqlite', $bases, 0xabc000 + $i);
    touch($factice);
    $factices[] = $factice;
}
$plein = appel('inspect_db.php', null, null);
$plein['code'] === 503
    ? ok('au plafond de 200 bases, un nouveau visiteur reçoit 503')
    : ko('le plafond n\'arrête pas un nouveau visiteur', (string) $plein['code']);
appel('inspect_db.php', null, $cookieA)['code'] === 200
    ? ok('au plafond, un visiteur déjà là continue')
    : ko('le plafond a coupé un visiteur déjà là');
foreach ($factices as $factice) { @unlink($factice); }

proc_terminate($serveur);
proc_close($serveur);
foreach (array_merge(glob($bases . '/*') ?: [], glob($etat . '/*') ?: []) as $p) { is_file($p) && @unlink($p); }
@rmdir($bases);
@rmdir($etat);

echo "\n  Sessions de la démo SelfDataGuard — {$passes} passés, {$echecs} échoués\n\n";
exit($echecs === 0 ? 0 : 1);
