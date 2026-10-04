<?php

declare(strict_types=1);

/**
 * Banc — la démo publique donne à chaque visiteur sa propre base.
 *
 * Joué contre `php -S`, d'abord comme en production (cookie `__Host-`, Secure),
 * puis avec DATAGUARD_DEMO_HTTP=1 comme `run.sh`. Deux visiteurs, deux bases : le
 * second ne voit, ne retrouve, n'ouvre ni ne supprime le coffre du premier. Seule
 * une inscription valide crée une base ; une base périmée disparaît, par la requête
 * suivante comme par la commande du minuteur ; plafond global et quota par adresse
 * n'arrêtent que les inscriptions.
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
$bases = $etat . '/sessions';
@mkdir($etat, 0700, true);

/** Lance la démo sur un port libre ; rend [processus, port]. */
function demarrer(array $env): array
{
    global $demo, $etat;
    $port = 0;
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    $proc = proc_open(
        sprintf('exec php -d display_errors=1 -S 127.0.0.1:%d -t %s', $port, escapeshellarg($demo)),
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $tuyaux,
        null,
        $env + ['DATAGUARD_DB_PATH' => $etat . '/demo.sqlite', 'DATAGUARD_BLINDKEY_PATH' => $etat . '/blindkey.bin',
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin']
    );
    for ($i = 0; $i < 50; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if ($c) { fclose($c); return [$proc, $port]; }
        usleep(100000);
    }
    ko('le serveur de la démo n\'a pas démarré — ce banc n\'a rien établi');
    exit(1);
}

/** @return array{code: int, json: array<string, mixed>, valeur: ?string, ligne: ?string} */
function appel(int $port, string $nom, string $route, ?array $corps, ?string $cookie): array
{
    $entetes = ['Content-Type: application/json'];
    if ($cookie !== null) {
        $entetes[] = 'Cookie: ' . $nom . '=' . $cookie;
    }
    $contexte = stream_context_create(['http' => [
        'method' => $corps === null ? 'GET' : 'POST', 'header' => implode("\r\n", $entetes),
        'content' => $corps === null ? '' : json_encode($corps), 'ignore_errors' => true, 'timeout' => 30,
    ]]);
    $brut = (string) @file_get_contents("http://127.0.0.1:{$port}/api/{$route}", false, $contexte);
    $code = 0; $valeur = null; $ligne = null;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) { $code = (int) $m[1]; }
        if (stripos($h, 'Set-Cookie: ' . $nom . '=') === 0) {
            $ligne = $h;
            $valeur = explode(';', substr($h, strlen('Set-Cookie: ' . $nom . '=')))[0];
        }
    }
    return ['code' => $code, 'json' => json_decode($brut, true) ?? ['brut' => $brut], 'valeur' => $valeur, 'ligne' => $ligne];
}

function nbBases(): int { global $bases; return count(glob($bases . '/*.sqlite') ?: []); }
function oublierQuotas(): void { global $bases; foreach (glob($bases . '/adresse-*') ?: [] as $q) { @unlink($q); } }

$INSCRIPTION = ['userId' => 'alice', 'password' => 'mot-de-passe-alice', 'memorized' => 'sentier-alice',
                'fields' => ['email' => 'alice@example.org'], 'indexed' => ['email']];

// ── Comme en production ─────────────────────────────────────────────────────
[$serveur, $port] = demarrer([]);
$NOM = '__Host-sdg_demo';

echo "\n→ Le cookie\n";
$a = appel($port, $NOM, 'inspect_db.php', null, null);
$cookieA = $a['valeur'];
$a['code'] === 200 && $cookieA !== null && preg_match('/^[a-f0-9]{32}\z/', $cookieA) === 1
    ? ok('une première visite reçoit un cookie __Host- de 128 bits')
    : ko('première visite', $a['code'] . ' ' . (string) $a['ligne']);
$attributs = strtolower((string) $a['ligne']);
str_contains($attributs, '; secure') && str_contains($attributs, '; httponly')
    && str_contains($attributs, '; samesite=strict') && str_contains($attributs, '; path=/')
    && !str_contains($attributs, 'domain=')
    ? ok('il porte Secure, HttpOnly, SameSite=Strict et Path=/, sans Domain')
    : ko('attributs du cookie', (string) $a['ligne']);
nbBases() === 0
    ? ok('une lecture ne crée aucune base')
    : ko('une lecture a créé une base', (string) nbBases());

echo "\n→ Une inscription refusée ne crée rien\n";
$get = appel($port, $NOM, 'register.php', null, $cookieA);
$court = appel($port, $NOM, 'register.php', ['userId' => 'alice', 'password' => 'court'], $cookieA);
$get['code'] === 405 && $court['code'] === 400 && nbBases() === 0
    ? ok('un GET et un mot de passe trop court sur register.php ne créent aucune base')
    : ko('une inscription refusée a créé une base', "{$get['code']} {$court['code']} bases=" . nbBases());

echo "\n→ Deux visiteurs, deux bases\n";
$r = appel($port, $NOM, 'register.php', $INSCRIPTION, $cookieA);
$r['code'] === 200 && nbBases() === 1
    ? ok('l\'inscription crée la base du premier visiteur')
    : ko('inscription du premier visiteur', $r['code'] . ' ' . json_encode($r['json']));

$b = appel($port, $NOM, 'inspect_db.php', null, null);
$cookieB = $b['valeur'];
$cookieB !== null && $cookieB !== $cookieA
    ? ok('le second visiteur reçoit un autre cookie')
    : ko('le second visiteur partage le cookie du premier');
!in_array('alice', array_column($b['json']['vaults'] ?? [], 'user_id'), true) && $b['code'] === 200
    ? ok('inspect_db du second visiteur ne montre pas le coffre du premier')
    : ko('le second visiteur voit le coffre du premier dans inspect_db', json_encode($b['json']));
$f = appel($port, $NOM, 'find_user.php', ['fieldName' => 'email', 'value' => 'alice@example.org'], $cookieB);
$f['code'] === 200 && array_key_exists('userId', $f['json']) && $f['json']['userId'] === null
    ? ok('find_user du second visiteur ne retrouve pas le premier')
    : ko('le second visiteur retrouve le premier par find_user', json_encode($f['json']));
$essais = [
    ['login.php', ['userId' => 'alice', 'secret' => 'mot-de-passe-alice', 'mode' => 'password'], 'ne se connecte pas au'],
    ['coffre_open.php', ['userId' => 'alice', 'secret' => 'mot-de-passe-alice', 'mode' => 'password'], 'n\'ouvre pas le'],
    ['delete.php', ['userId' => 'alice', 'password' => 'mot-de-passe-alice'], 'ne supprime pas le'],
];
$sansBase = appel($port, $NOM, 'login.php', $essais[0][1], $cookieB);
$sansBase['code'] === 404 && str_contains((string) ($sansBase['json']['error'] ?? ''), 'register first')
    ? ok('sans base, une connexion répond 404 « inscris-toi », pas « mauvais mot de passe »')
    : ko('connexion sans base', $sansBase['code'] . ' ' . json_encode($sansBase['json']));
$bob = appel($port, $NOM, 'register.php', ['userId' => 'bob', 'password' => 'mot-de-passe-bob-1'], $cookieB);
$bob['code'] === 200 ? ok('le second visiteur s\'inscrit dans sa propre base') : ko('inscription du second visiteur', (string) $bob['code']);
foreach ($essais as [$route, $corps, $verbe]) {
    $x = appel($port, $NOM, $route, $corps, $cookieB);
    $x['code'] === 401
        ? ok("le second visiteur {$verbe} coffre du premier, même avec son mot de passe (401)")
        : ko("{$route} du second visiteur sur le coffre du premier", $x['code'] . ' ' . json_encode($x['json']));
}
$retour = appel($port, $NOM, 'inspect_db.php', null, $cookieA);
in_array('alice', array_column($retour['json']['vaults'] ?? [], 'user_id'), true)
    ? ok('le premier visiteur retrouve son coffre, intact')
    : ko('le premier visiteur a perdu son coffre', json_encode($retour['json']));
nbBases() === 2
    ? ok('deux inscriptions, deux bases : les essais n\'en ont créé aucune de plus')
    : ko('des bases sont nées sans inscription', (string) nbBases());
$doublon = appel($port, $NOM, 'register.php', $INSCRIPTION, $cookieA);
$doublon['code'] === 409 && in_array('alice', array_column(appel($port, $NOM, 'inspect_db.php', null, $cookieA)['json']['vaults'] ?? [], 'user_id'), true)
    ? ok('une inscription refusée dans une base existante ne l\'efface pas')
    : ko('une inscription en doublon a touché la base existante', (string) $doublon['code']);

echo "\n→ Cookies inattendus\n";
$inconnu = appel($port, $NOM, 'inspect_db.php', null, str_repeat('ab', 16));
$inconnu['code'] === 200 && ($inconnu['json']['vaults'] ?? null) === [] && nbBases() === 2
    ? ok('un identifiant bien formé mais inconnu lit une base vide, sans en créer')
    : ko('identifiant inconnu', $inconnu['code'] . ' ' . json_encode($inconnu['json']));
$m = appel($port, $NOM, 'inspect_db.php', null, '..%2F..%2Fdemo');
$m['valeur'] !== null && preg_match('/^[a-f0-9]{32}\z/', $m['valeur']) === 1
    ? ok('un cookie mal formé est remplacé par un identifiant neuf')
    : ko('un cookie mal formé a été gardé', (string) $m['valeur']);
!file_exists($bases . '/../../demo.sqlite') && !file_exists($bases . '/../../demo')
    ? ok('rien n\'est créé là où le cookie mal formé pointait')
    : ko('le cookie mal formé a créé un fichier hors du répertoire des bases');
$tab = appel($port, $NOM . '[x]', 'inspect_db.php', null, '1');
$tab['code'] === 200 && !str_contains(json_encode($tab['json']), 'Warning') && !str_contains(json_encode($tab['json']), 'Notice')
    ? ok('un cookie en tableau est ignoré, sans avertissement')
    : ko('cookie en tableau', $tab['code'] . ' ' . json_encode($tab['json']));

echo "\n→ Expiration\n";
$vieille = $bases . '/' . str_repeat('0', 32) . '.sqlite';
touch($vieille, time() - 31 * 60);
touch($vieille . '-journal', time() - 31 * 60);
touch($bases . '/adresse-vieille', time() - 31 * 60);
appel($port, $NOM, 'inspect_db.php', null, $cookieA);
!is_file($vieille) && !is_file($vieille . '-journal') && !is_file($bases . '/adresse-vieille')
    ? ok('une base sans action depuis 31 minutes est effacée par la requête suivante, journal et quota compris')
    : ko('un fichier périmé a survécu à une requête');

// La commande du minuteur, lue dans l'unité et jouée sur le répertoire du banc.
$unite = (string) file_get_contents(dirname(__DIR__, 3) . '/deploy/selfdataguard/demo-sessions-purge.service');
preg_match('/^ExecStart=(.+)$/m', $unite, $em);
$commande = str_replace('/var/lib/selfdataguard/sessions', escapeshellarg($bases), $em[1] ?? '', $remplaces);
if ($remplaces !== 1) {
    ko('la commande du minuteur ne vise plus /var/lib/selfdataguard/sessions — elle n\'est pas jouée ici', $em[1] ?? '');
    $commande = 'false';
}
preg_match('/-mmin \+(\d+)/', $em[1] ?? '', $mm);
preg_match('/DEMO_TTL_SECONDES\s*=\s*(\d+)\s*\*\s*(\d+)/', (string) file_get_contents($demo . '/api/_bootstrap.php'), $ttl);
isset($mm[1], $ttl[1]) && (int) $mm[1] * 60 === (int) $ttl[1] * (int) $ttl[2]
    ? ok('le minuteur et le code ont la même durée de vie')
    : ko('le minuteur et le code ne disent pas la même durée', ($mm[1] ?? '?') . ' min contre ' . ($ttl[1] ?? '?') . '×' . ($ttl[2] ?? '?') . ' s');
$perimee = $bases . '/' . str_repeat('1', 32) . '.sqlite';
touch($perimee, time() - 31 * 60);
$fraiche = $bases . '/' . str_repeat('2', 32) . '.sqlite';
touch($fraiche);
exec($commande, $sortie, $codeMinuteur);
$codeMinuteur === 0 && !is_file($perimee) && is_file($fraiche)
    ? ok('la commande du minuteur efface une base périmée et garde une base fraîche, sans visiteur')
    : ko('commande du minuteur', "code {$codeMinuteur} : {$commande}");
@unlink($fraiche);

echo "\n→ Plafond\n";
oublierQuotas();
$factices = [];
for ($i = nbBases(); $i < 1000; $i++) {
    $factice = sprintf('%s/%032x.sqlite', $bases, 0xabc000 + $i);
    touch($factice);
    $factices[] = $factice;
}
$plein = appel($port, $NOM, 'register.php', $INSCRIPTION, null);
$plein['code'] === 503
    ? ok('au plafond de 1 000 bases, une nouvelle inscription reçoit 503')
    : ko('le plafond n\'arrête pas une inscription', (string) $plein['code']);
appel($port, $NOM, 'inspect_db.php', null, null)['code'] === 200
    ? ok('au plafond, une simple visite passe encore')
    : ko('le plafond refuse une simple visite');
appel($port, $NOM, 'inspect_db.php', null, $cookieA)['code'] === 200
    ? ok('au plafond, un visiteur déjà là continue')
    : ko('le plafond a coupé un visiteur déjà là');
foreach ($factices as $factice) { @unlink($factice); }

echo "\n→ Quota par adresse\n";
oublierQuotas();
$avant = nbBases();
$codes = [];
for ($i = 0; $i < 6; $i++) {
    $codes[] = appel($port, $NOM, 'register.php', ['userId' => "v{$i}", 'password' => 'mot-de-passe-quota'], null)['code'];
}
array_slice($codes, 0, 5) === [200, 200, 200, 200, 200] && $codes[5] === 429 && nbBases() === $avant + 5
    ? ok('une adresse crée 5 bases par demi-heure, la sixième inscription reçoit 429')
    : ko('quota par adresse', implode(',', $codes) . ' ; bases +' . (nbBases() - $avant));

proc_terminate($serveur);
proc_close($serveur);

// ── Comme avec run.sh, en clair ─────────────────────────────────────────────
echo "\n→ Essais locaux en clair (DATAGUARD_DEMO_HTTP=1)\n";
[$serveur, $port] = demarrer(['DATAGUARD_DEMO_HTTP' => '1']);
$c = appel($port, 'sdg_demo', 'inspect_db.php', null, null);
$c['valeur'] !== null && !str_contains(strtolower((string) $c['ligne']), '; secure')
    ? ok('le cookie s\'appelle sdg_demo et perd Secure')
    : ko('cookie en clair', (string) $c['ligne']);
proc_terminate($serveur);
proc_close($serveur);

foreach (array_merge(glob($bases . '/*') ?: [], glob($etat . '/*') ?: []) as $p) { is_file($p) && @unlink($p); }
@rmdir($bases);
@rmdir($etat);

echo "\n  Sessions de la démo SelfDataGuard — {$passes} passés, {$echecs} échoués\n\n";
exit($echecs === 0 ? 0 : 1);
