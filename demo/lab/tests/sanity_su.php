#!/usr/bin/env php
<?php
/**
 * Contrôles du journal SU — les garanties que la console annonce.
 *
 * Chacun a été vu rougir avant d'être écrit : le défaut correspondant a été
 * planté, mesuré, puis corrigé. Un contrôle qu'on n'a jamais fait échouer ne se
 * distingue pas d'un contrôle qui ne mesure rien.
 *
 * 🔑 Le contrôle n° 4 est celui qui compte le plus, et c'est le moins intuitif :
 * il constate que la chaîne NE DÉTECTE PAS sa propre troncature. Ce n'est pas un
 * défaut à corriger — le préfixe d'une chaîne valide est une chaîne valide, et
 * aucune vérification interne n'y changera rien. C'est la raison d'être de
 * l'ancre externe, et le jour où quelqu'un croira réparer ce test, il retirera
 * la seule justification écrite de l'externalisation.
 *
 * Usage : php tests/sanity_su.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/su_audit.php';

use Pierroons\MySelfLab\SuAudit;

// 🔑 Le total se compte, il ne s'écrit pas — même raison que dans
// `sanity_moderate.php` et `deploy/my-self/tests/test_deploy.sh`.
$echecs = 0; $reussites = 0;
function ok(string $m): void  { global $reussites; echo "  ✓ $m\n"; $reussites++; }
function nok(string $m): void { global $echecs; fwrite(STDERR, "  ✗ $m\n"); $echecs++; }

// Bac à sable : le journal réel n'est jamais touché.
$dir = sys_get_temp_dir() . '/sanity_su_' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
putenv("SELFRECOVER_STATE_DIR=$dir");
putenv('SELFRECOVER_SU_AUDIT_SECRET=sanity-secret-fixe');
putenv('SU_FORENSIC_MINIMAL=1');
putenv('SELFRECOVER_NTFY_URL');            // aucune sortie réseau depuis un test
$log = SuAudit::logPath();

register_shutdown_function(static function () use ($dir): void {
    foreach (glob("$dir/*") ?: [] as $f) {
        unlink($f);
    }
    @rmdir($dir);
});

// ── 1. La chaîne se construit et se vérifie ─────────────────────────────────
foreach (['alice', 'bob', 'carol'] as $u) {
    SuAudit::append(SuAudit::ACTION_ADD_ADMIN, $u);
}
$v = SuAudit::verify();
$v['ok'] && $v['count'] === 3
    ? ok('chaîne intègre sur 3 entrées')
    : nok('chaîne non vérifiée : ' . json_encode($v));

$intact = (string) file_get_contents($log);

// ── 2. Une entrée modifiée rompt la chaîne ──────────────────────────────────
file_put_contents($log, str_replace('"target":"bob"', '"target":"eve"', $intact));
$v = SuAudit::verify();
!$v['ok'] && ($v['break_at'] ?? 0) === 2
    ? ok("entrée altérée détectée à la bonne position — {$v['reason']}")
    : nok('altération non détectée, ou position fausse : ' . json_encode($v));
file_put_contents($log, $intact);

// ── 3. Les seq et le chaînage tiennent sous écritures concurrentes ──────────
// La tête de chaîne se lit dans le verrou qui protège l'écriture. Lue en
// dehors, deux appends partent du même prev_hash : mesuré, 5 seq distincts sur
// 20 et chaîne rompue dès la deuxième entrée.
$dirC = sys_get_temp_dir() . '/sanity_su_c_' . bin2hex(random_bytes(6));
mkdir($dirC, 0700, true);
$procs = [];
for ($i = 0; $i < 12; $i++) {
    $cmd = sprintf(
        'SELFRECOVER_STATE_DIR=%s SELFRECOVER_SU_AUDIT_SECRET=sanity-secret-fixe SU_FORENSIC_MINIMAL=1 %s -r %s',
        escapeshellarg($dirC),
        escapeshellarg(PHP_BINARY),
        escapeshellarg(sprintf(
            'require %s; Pierroons\MySelfLab\SuAudit::append("add-admin", "u" . getmypid());',
            var_export(__DIR__ . '/../lib/su_audit.php', true)
        ))
    );
    // Sans `&` : proc_open lance déjà sans attendre, et c'est proc_close qui
    // attend la fin. Détacher au shell rendrait la main sur un fils orphelin,
    // et le journal serait lu avant la première écriture.
    $procs[] = proc_open($cmd, [], $pipes);
}
foreach ($procs as $p) {
    if (is_resource($p)) {
        proc_close($p);
    }
}
$lignes = file("$dirC/su-audit.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$seqs   = array_map(static fn ($l) => json_decode($l, true)['seq'] ?? null, $lignes);
if (count($lignes) !== 12) {
    nok(sprintf('%d entrées écrites sur 12 — appends perdus', count($lignes)));
} elseif ($seqs !== range(1, 12)) {
    nok('numéros de séquence dupliqués ou discontinus : ' . implode(',', $seqs));
} else {
    ok('12 appends concurrents — séquence continue, aucune collision');
}
foreach (glob("$dirC/*") ?: [] as $f) {
    unlink($f);
}
@rmdir($dirC);

// ── 4. La troncature reste invisible de l'intérieur ─────────────────────────
// Constat, pas défaut. Voir l'avertissement en tête de fichier.
file_put_contents($log, implode("\n", array_slice(explode("\n", trim($intact)), 0, 2)) . "\n");
$v = SuAudit::verify();
$v['ok'] && $v['count'] === 2
    ? ok("troncature invisible de l'intérieur — l'ancre externe est la seule parade")
    : nok('comportement inattendu sur log tronqué : ' . json_encode($v));
file_put_contents($log, $intact);

// ── 5. Une queue illisible arrête l'écriture ────────────────────────────────
// Reprendre une chaîne dont on ignore l'état reviendrait à en démarrer une
// neuve en silence — ce que le journal existe pour rendre impossible.
file_put_contents($log, $intact . '{"seq":4,"tronqu');
try {
    SuAudit::append(SuAudit::ACTION_ADD_ADMIN, 'dave');
    nok('une chaîne à la queue illisible a quand même été prolongée');
} catch (RuntimeException) {
    ok('queue illisible : écriture refusée');
}
file_put_contents($log, $intact);

// ── 6. Les listes de rejeu dérivent des constantes ──────────────────────────
// Un nom écrit d'un côté et relu de l'autre avait produit une branche morte
// dans la logique qui décide d'une révocation.
$connues = [
    SuAudit::ACTION_FIRST_ADMIN, SuAudit::ACTION_ADD_ADMIN, SuAudit::ACTION_REVOKE_ADMIN,
    SuAudit::ACTION_APPROVE_REQUEST, SuAudit::ACTION_REJECT_REQUEST,
    SuAudit::ACTION_QUARANTINE, SuAudit::ACTION_RESET_SHELL, SuAudit::ACTION_RESET_DB,
];
$rejouees = array_merge(SuAudit::GRANTING, SuAudit::REVOKING, SuAudit::RESETS);
$inconnues = array_diff($rejouees, $connues);
$inconnues === []
    ? ok('les ' . count($rejouees) . ' actions rejouées par `audit` sont toutes des constantes déclarées')
    : nok('action rejouée sans constante correspondante : ' . implode(', ', $inconnues));

// ── 7. Un administrateur ne dépose pas sa propre promotion ──────────────────
// ⚠️ Ce contrôle vérifie le **code d'erreur**, pas seulement le refus, et c'est
// délibéré : le dépôt exige `require_admin`, donc un demandeur est déjà
// administrateur et `already_admin` refuserait de toute façon. Retirer la garde
// `self_promotion` laisserait donc le refus en place, avec le mauvais motif —
// jusqu'au jour où le dépôt s'ouvrirait aux comptes ordinaires, où plus rien
// n'empêcherait quelqu'un de déposer sa propre promotion.
//
// Mesuré : garde retirée, le contrôle rougit sur `already_admin`.
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/admin.php';

$dbTest = sys_get_temp_dir() . '/sanity_su_db_' . bin2hex(random_bytes(6)) . '.sqlite';
putenv("LAB_DB_PATH=$dbTest");
register_shutdown_function(static fn () => @unlink($dbTest));

$pdo = \Pierroons\MySelfLab\Db::pdo();
$pdo->prepare('INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, is_admin, created_at)
               VALUES (?, ?, ?, ?, 1, ?)')->execute(['alice', 'x', 'x', 'x', time()]);
$pdo->prepare('INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, is_admin, created_at)
               VALUES (?, ?, ?, ?, 0, ?)')->execute(['bob', 'x', 'x', 'x', time()]);

$soi   = \Pierroons\MySelfLab\Admin::requestPromotion($pdo, 'alice', 'alice', 'motif suffisamment long pour passer');
$autre = \Pierroons\MySelfLab\Admin::requestPromotion($pdo, 'alice', 'bob', 'motif suffisamment long pour passer');

if (!empty($soi['ok'])) {
    nok('un administrateur a pu déposer sa propre promotion');
} elseif (($soi['error'] ?? '') !== 'self_promotion') {
    nok("l'auto-promotion est refusée, mais pour une autre raison : " . ($soi['error'] ?? '?'));
} elseif (empty($autre['ok'])) {
    nok('une demande légitime est refusée : ' . ($autre['message'] ?? '?'));
} else {
    ok('auto-promotion refusée, demande vers un tiers acceptée');
}

// ── 8. Le secret SU est posé au profil du dépôt, pas à celui de PHP ─────────
// 🔑 Le contrôle passe par la CONSOLE, pas par `Hashing::ARGON2` : le défaut
// n'était pas dans le profil, il était dans un appel qui ne le lisait pas.
// `password_hash($p, PASSWORD_ARGON2ID)` sans options prend ceux de PHP, et
// aujourd'hui ils ne diffèrent que d'un fil — assez peu pour ne rien casser,
// assez pour que le secret le plus privilégié du modèle reste en arrière le
// jour où le profil monte. Un contrôle sur la constante seule serait resté vert
// pendant tout ce temps.
//
// Mesuré : options retirées de l'appel, le contrôle rougit sur `p=1`.
require_once __DIR__ . '/../../../bi-self/selfrecover/src/autoload.php';

$dirP = sys_get_temp_dir() . '/sanity_su_prof_' . bin2hex(random_bytes(6));
mkdir($dirP, 0700, true);
register_shutdown_function(static function () use ($dirP): void {
    foreach (glob("$dirP/*") ?: [] as $f) {
        unlink($f);
    }
    @rmdir($dirP);
});

$console = __DIR__ . '/../selfrecover-su';
$secret  = 'banc-de-mesure-passphrase-longue-sans-espace';
$env     = [
    'PATH'                          => getenv('PATH') ?: '/usr/bin:/bin',
    'SELFRECOVER_STATE_DIR'         => $dirP,
    'SELFRECOVER_SU_AUDIT_SECRET'   => 'sanity-secret-fixe',
    'SU_FORENSIC_MINIMAL'           => '1',
    'SELFRECOVER_SU_DEV'            => '1',
    'SELFRECOVER_NTFY_URL'          => '',
    'SELFRECOVER_SU_SECRET'         => $secret,
    'SELFRECOVER_SU_SECRET_INPUT'   => $secret,
    'SELFRECOVER_SU_NEW_INPUT'      => 'une-autre-passphrase-longue-et-sans-espace',
];
$proc = proc_open(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($console) . ' change-passphrase',
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    null,
    $env
);
if (is_resource($proc)) {
    proc_close($proc);
}

$pose = @file_get_contents("$dirP/su-secret");
if ($pose === false || $pose === '') {
    nok('la console n\'a posé aucun secret — le contrôle ne mesure rien');
} else {
    $attendu = Pierroons\SelfRecover\Crypto\Hashing::ARGON2;
    $motif   = sprintf(
        '/^\$argon2id\$v=19\$m=%d,t=%d,p=%d\$/',
        $attendu['memory_cost'],
        $attendu['time_cost'],
        $attendu['threads']
    );
    preg_match($motif, trim($pose)) === 1
        ? ok('le secret SU porte le profil du dépôt (m=' . $attendu['memory_cost']
            . ', t=' . $attendu['time_cost'] . ', p=' . $attendu['threads'] . ')')
        : nok('le secret SU ne porte pas le profil du dépôt : ' . substr(trim($pose), 0, 32));
}

// ── 9. Un secret posé avant le correctif s'authentifie toujours ─────────────
// `password_verify` lit les paramètres DANS l'empreinte : changer le profil de
// hachage ne condamne aucun secret déjà posé. Sans ce contrôle, quelqu'un
// pourrait croire qu'un durcissement du profil oblige à refaire le tour des
// déploiements.
$ancien = password_hash($secret, PASSWORD_ARGON2ID);   // profil de PHP, comme avant le correctif
password_verify($secret, $ancien)
    ? ok('un secret posé sous l\'ancien profil se vérifie encore')
    : nok('un secret posé sous l\'ancien profil ne se vérifie plus — migration forcée');

// ─── Le refus de la valeur de démonstration vit DANS la fonction ─────────────
// 🔑 L'en-tête de SuAudit promet que les valeurs de démonstration sont refusées en
// régime strict. Le refus existait — mais chez UN appelant, la console. Tout autre
// consommateur recevait `DEMO_SECRET` sans un mot et aurait signé le journal
// d'audit avec une constante publiée dans le dépôt. Une garde chez l'appelant
// n'est pas une garde, c'est une convention.
$secretDeCeBanc = getenv('SELFRECOVER_SU_AUDIT_SECRET');

putenv('SELFRECOVER_SU_AUDIT_SECRET');     // rien de posé
putenv('SELFRECOVER_SU_DEV');              // régime strict
try {
    $rendu = SuAudit::secret();
    nok('régime strict, rien de posé : secret() a rendu ' . strlen($rendu) . ' caractères au lieu de refuser');
} catch (RuntimeException $e) {
    str_contains($e->getMessage(), 'SELFRECOVER_SU_AUDIT_SECRET')
        ? ok('régime strict, rien de posé : secret() refuse et nomme la variable')
        : nok('secret() refuse, mais sans nommer la variable à poser');
}
SuAudit::secretPose() === false
    ? ok('secretPose() rend faux sans lever — la console garde son propre message')
    : nok('secretPose() devrait rendre faux quand rien n\'est posé');

putenv('SELFRECOVER_SU_AUDIT_SECRET=' . SuAudit::DEMO_SECRET);
try {
    SuAudit::secret();
    nok('la valeur de démonstration posée explicitement passe en régime strict');
} catch (RuntimeException) {
    ok('la valeur de démonstration posée explicitement est refusée aussi');
}

putenv('SELFRECOVER_SU_DEV=1');
SuAudit::secret() === SuAudit::DEMO_SECRET
    ? ok('le mode dev reste permissif — les bancs en dépendent')
    : nok('le mode dev devrait rendre la valeur de démonstration');

putenv('SELFRECOVER_SU_DEV');
putenv('SELFRECOVER_SU_AUDIT_SECRET=' . $secretDeCeBanc);   // le banc reprend son décor

// ─── Le témoin distant : son silence doit se voir HORS du journal ────────────
// 🔑 La chaîne de hachage ne détecte pas sa propre troncature — le préfixe d'une
// chaîne valide est une chaîne valide. Le témoin distant est le seul angle qui la
// rende visible, et jusqu'ici son silence ne se voyait nulle part : `ntfy_delivered`
// était posé APRÈS l'écriture de la ligne, donc il n'atteignait jamais le fichier,
// et aucun appelant ne lisait le retour d'`append()`.
// La marque vit hors du journal, donc ces cinq états se fabriquent sans serveur ntfy.
$marque = SuAudit::marqueTemoinPath();
$tete   = (int) (SuAudit::read()[count(SuAudit::read()) - 1]['seq'] ?? 0);
$poser  = static function (int $seq) use ($marque): void {
    file_put_contents($marque, json_encode(['seq' => $seq, 'entry_hash' => str_repeat('a', 64), 'confirme_a' => gmdate('c')]));
};

putenv('SELFRECOVER_NTFY_URL');                       // aucune externalisation
@unlink($marque);
SuAudit::ecartTemoin()['etat'] === 'non_configure'
    ? ok('sans externalisation configurée : l\'état le dit, il ne se tait pas')
    : nok('une externalisation absente devrait être annoncée');

putenv('SELFRECOVER_NTFY_URL=https://exemple.invalid/su');   // jamais contactée
SuAudit::ecartTemoin()['etat'] === 'jamais'
    ? ok('externalisation configurée, aucune confirmation : état « jamais »')
    : nok('aucun envoi confirmé devrait rendre « jamais »');

$poser($tete);
SuAudit::ecartTemoin()['etat'] === 'a_jour'
    ? ok('marque à la tête du journal : état « à jour »')
    : nok('une marque à la tête devrait rendre « a_jour »');

$poser($tete - 2);
$e = SuAudit::ecartTemoin();
$e['etat'] === 'muet' && str_contains($e['detail'], '2 entrée')
    ? ok('témoin en retard de 2 entrées : « muet », et il compte combien')
    : nok('un témoin en retard devrait rendre « muet » et nommer l\'écart', $e['detail'] ?? '');

// LE CAS QUI COMPTE : le journal est plus court que ce que le témoin a confirmé.
// C'est une PREUVE de troncature, et elle tient parce que la marque est extérieure.
$poser($tete + 3);
$e = SuAudit::ecartTemoin();
$e['etat'] === 'tronque' && $e['marque'] === $tete + 3 && $e['tete'] === $tete
    ? ok('journal plus court que la marque : « tronqué » — la preuve vient du dehors')
    : nok('un journal raccourci sous la marque devrait rendre « tronque »', $e['detail'] ?? '');

@unlink($marque);
putenv('SELFRECOVER_NTFY_URL');

// ─── Le témoin qui RÉPOND et REFUSE — le cas que les cinq états ne voient pas ──
// 🔑 Les états ci-dessus se fabriquent sans serveur, et c'est le bon choix pour
// eux. Mais aucun n'exerce la lecture du code HTTP, et un témoin injoignable
// échoue AVANT d'avoir un code : le contrôle n'était donc jamais éprouvé. Un ntfy
// qui rend 401 faute de jeton répond parfaitement — `curl_exec` rend le corps,
// `curl_errno` rend 0 — et `notify()` concluait « remis ». Il faut un vrai
// serveur, et il faut les DEUX réponses : sans le contre-témoin du 200, une
// fonction qui refuserait tout passerait aussi.
$racine = sys_get_temp_dir() . '/sanity-su-ntfy-' . getmypid();
@mkdir($racine, 0700, true);
file_put_contents($racine . '/index.php', <<<'SRV'
<?php
$h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($h !== 'Bearer le-bon-jeton') { http_response_code(401); echo 'unauthorized'; exit; }
http_response_code(200); echo 'ok';
SRV);

$port    = 8000 + (getmypid() % 1000);
$serveur = proc_open(
    sprintf('exec php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg($racine), escapeshellarg($racine . '/index.php')),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $tuyaux
);

$debout = false;
for ($i = 0; $i < 50 && !$debout; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($c) { fclose($c); $debout = true; } else { usleep(100000); }
}

if (!$debout) {
    nok('le serveur témoin du banc n\'a pas démarré — ce contrôle n\'a rien établi');
} else {
    putenv('SELFRECOVER_NTFY_URL=http://127.0.0.1:' . $port . '/');
    putenv('SELFRECOVER_NTFY_SOCKS=none');

    // LE CAS QUI COMPTE : le témoin répond, et il refuse.
    putenv('SELFRECOVER_NTFY_TOKEN');
    @unlink($marque);
    $e = SuAudit::append('banc-temoin', 'refus-401', ['note' => 'le témoin répond 401']);
    $e['ntfy_delivered'] === false
        ? ok('témoin qui répond 401 : « non remis » — le code HTTP est lu, pas seulement le transport')
        : nok('un témoin qui REFUSE ne doit pas compter comme remis', var_export($e['ntfy_delivered'], true));
    !file_exists($marque)
        ? ok('témoin qui refuse : aucune marque posée — l\'écart restera visible')
        : nok('une marque posée sur un refus rend « à jour » un témoin qui n\'a rien reçu');

    // LE CONTRE-TÉMOIN : sans lui, une fonction qui refuserait tout passerait.
    putenv('SELFRECOVER_NTFY_TOKEN=le-bon-jeton');
    @unlink($marque);
    $e = SuAudit::append('banc-temoin', 'accepte-200', ['note' => 'le témoin répond 200']);
    $e['ntfy_delivered'] === true
        ? ok('témoin qui répond 200 : « remis » — la sonde ne refuse pas tout')
        : nok('un témoin qui ACCEPTE doit compter comme remis', var_export($e['ntfy_delivered'], true));
    file_exists($marque)
        ? ok('témoin qui accepte : marque posée')
        : nok('une acceptation doit poser la marque');

    if (is_resource($serveur)) { proc_terminate($serveur); proc_close($serveur); }
}

// Le témoin injoignable — l'ancien seul cas, gardé : il échoue AVANT tout code HTTP.
putenv('SELFRECOVER_NTFY_URL=http://127.0.0.1:' . ($port + 1) . '/');
@unlink($marque);
$e = SuAudit::append('banc-temoin', 'injoignable', []);
$e['ntfy_delivered'] === false
    ? ok('témoin injoignable : « non remis »')
    : nok('un témoin injoignable ne peut pas être remis');

@unlink($racine . '/index.php');
@rmdir($racine);
@unlink($marque);
putenv('SELFRECOVER_NTFY_URL');
putenv('SELFRECOVER_NTFY_TOKEN');
putenv('SELFRECOVER_NTFY_SOCKS');

echo "\n";
$total = $reussites + $echecs;
if ($echecs === 0) {
    echo "OK — $total/$total contrôles conformes.\n";
    exit(0);
}
fwrite(STDERR, "ÉCHEC — $echecs contrôle(s) sur $total.\n");
exit(1);
