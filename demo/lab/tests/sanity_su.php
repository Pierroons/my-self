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

$echecs = 0;
$total  = 7;
function ok(string $m): void  { echo "  ✓ $m\n"; }
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
    SuAudit::ACTION_ADD_ADMIN, SuAudit::ACTION_REVOKE_ADMIN,
    SuAudit::ACTION_APPROVE_REQUEST, SuAudit::ACTION_REJECT_REQUEST,
    SuAudit::ACTION_QUARANTINE, SuAudit::ACTION_RESET_SHELL,
];
$rejouees = array_merge(SuAudit::GRANTING, SuAudit::REVOKING);
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

echo "\n";
if ($echecs === 0) {
    echo "OK — $total/$total contrôles conformes.\n";
    exit(0);
}
fwrite(STDERR, "ÉCHEC — $echecs contrôle(s) sur $total.\n");
exit(1);
