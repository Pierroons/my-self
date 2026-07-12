<?php

declare(strict_types=1);

/**
 * escrow-ceremony — CLI de récupération escrow (couche déploiement le service hote).
 *
 * Déverrouille les champs escrow d'un user EN CAS DE RÉCUP, sous conditions
 * cumulées, façon `su-cli` de SelfRecover :
 *   1. un LITIGE OUVERT existe pour ce user (garde anti-curieux) ;
 *   2. la PASSPHRASE ADMIN déscelle la clé privée de récupération.
 * Chaque acte (succès OU refus) est écrit dans un LOG D'AUDIT chaîné + signé
 * (inaltérable). L'admin obtient escrow_key — jamais le master_key.
 *
 * La lib DataGuard ignore litiges et audit : c'est CETTE couche qui les pose.
 *
 * Usage :
 *   php bin/escrow-ceremony.php unlock <user> <litige_id> [champ...]
 *   php bin/escrow-ceremony.php verify-log
 *
 * Config (env) :
 *   DATAGUARD_DB                base sqlite (vaults + escrow + litiges)
 *   DATAGUARD_ADMIN_PUBKEY_FILE fichier clé publique admin (base64)
 *   DATAGUARD_ADMIN_SEALED_FILE fichier clé privée SCELLÉE (salt:blob)
 *   DATAGUARD_AUDIT_LOG         chemin du journal d'audit
 *   DATAGUARD_AUDIT_SECRET      secret HMAC de signature du journal
 *   DATAGUARD_BLINDKEY_FILE     (optionnel) blindKey — non utilisé côté escrow
 *   DATAGUARD_OPERATOR          (optionnel) identité opérateur pour le forensic
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Escrow\AuditLog;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;

function envOrDie(string $key): string
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        fwrite(STDERR, "Config manquante : {$key}\n");
        exit(3);
    }
    return $v;
}

function fileEnv(string $key): string
{
    $path = envOrDie($key);
    if (!is_file($path)) {
        fwrite(STDERR, "Fichier introuvable ({$key}) : {$path}\n");
        exit(3);
    }
    return trim((string) file_get_contents($path));
}

function operatorContext(): array
{
    $op = getenv('DATAGUARD_OPERATOR') ?: (get_current_user() . '@' . php_uname('n'));
    $ip = null;
    $ssh = getenv('SSH_CONNECTION');
    if (is_string($ssh) && $ssh !== '') {
        $ip = explode(' ', $ssh)[0] ?: null;
    }
    return ['operator' => $op, 'ip' => $ip];
}

function readPassphrase(string $prompt): string
{
    if (function_exists('stream_isatty') && @stream_isatty(STDIN)) {
        fwrite(STDERR, $prompt);
        $tty = @fopen('/dev/tty', 'r') ?: STDIN;
        shell_exec('stty -echo 2>/dev/null');
        $line = fgets($tty);
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDERR, "\n");
    } else {
        // Non-interactif (pipe/test) : une ligne sur STDIN.
        $line = fgets(STDIN);
    }
    return rtrim((string) $line, "\r\n");
}

function litigeIsOpen(PDO $pdo, string $litigeId, string $userId): bool
{
    try {
        $st = $pdo->prepare('SELECT status, user_id FROM litiges WHERE id = ?');
        $st->execute([$litigeId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException) {
        return false; // table absente = aucun litige
    }
    return $row && $row['status'] === 'open' && $row['user_id'] === $userId;
}

// -----------------------------------------------------------------------------

$cmd = $argv[1] ?? '';

$auditLog = new AuditLog(envOrDie('DATAGUARD_AUDIT_LOG'), envOrDie('DATAGUARD_AUDIT_SECRET'));

if ($cmd === 'verify-log') {
    $r = $auditLog->verify();
    if ($r['ok']) {
        echo "✅ journal d'audit intègre — {$r['count']} entrée(s), chaîne + signatures valides\n";
        exit(0);
    }
    fwrite(STDERR, "❌ journal d'audit ROMPU à l'entrée #{$r['brokenAt']} (sur {$r['count']})\n");
    exit(1);
}

if ($cmd !== 'unlock' || !isset($argv[2], $argv[3])) {
    fwrite(STDERR, "Usage:\n  php bin/escrow-ceremony.php unlock <user> <litige_id> [champ...]\n  php bin/escrow-ceremony.php verify-log\n");
    exit(3);
}

$user      = $argv[2];
$litigeId  = $argv[3];
$fields    = array_slice($argv, 4);           // vide = tous les champs escrow
$ctx       = operatorContext() + ['target' => $user, 'litige' => $litigeId];

$dbPath = envOrDie('DATAGUARD_DB');
$pdo    = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function denyAndExit(AuditLog $log, array $ctx, string $reason, string $human): never
{
    $log->append(['action' => 'escrow-unlock-denied', 'reason' => $reason] + $ctx);
    fwrite(STDERR, "⛔ Refusé — {$human}\n");
    exit(2);
}

// (1) Garde anti-curieux : litige ouvert ?
if (!litigeIsOpen($pdo, $litigeId, $user)) {
    denyAndExit($auditLog, $ctx, 'no-open-litige', "aucun litige ouvert « {$litigeId} » pour « {$user} ».");
}

// (2) Cérémonie : passphrase → clé privée en RAM.
$passphrase = readPassphrase("Passphrase admin de récupération : ");
try {
    $sk = SelfDataGuard::unsealAdminRecoveryKey(fileEnv('DATAGUARD_ADMIN_SEALED_FILE'), $passphrase);
} catch (\Throwable) {
    sodium_memzero($passphrase);
    denyAndExit($auditLog, $ctx, 'bad-passphrase', 'passphrase admin invalide.');
}
sodium_memzero($passphrase);

$adminPub = fileEnv('DATAGUARD_ADMIN_PUBKEY_FILE');
$blindKey = getenv('DATAGUARD_BLINDKEY_FILE') ? fileEnv('DATAGUARD_BLINDKEY_FILE') : str_repeat("\0", 32);
$dg = new SelfDataGuard(new SqliteAdapter($pdo), $blindKey);

try {
    $plain = $dg->getEscrowFieldsAsAdmin($user, $sk, $adminPub, $fields);
} catch (\Throwable $e) {
    sodium_memzero($sk);
    denyAndExit($auditLog, $ctx, 'no-escrow-or-open-failed', $e->getMessage());
}
sodium_memzero($sk);

// Trace de l'acte (succès), signée + chaînée.
$auditLog->append(['action' => 'escrow-unlock', 'fields' => array_keys($plain)] + $ctx);

echo "🔓 Escrow déverrouillé pour « {$user} » (litige {$litigeId})\n";
echo "   opérateur : {$ctx['operator']}" . ($ctx['ip'] ? " · IP {$ctx['ip']}" : '') . "\n";
echo "   ─────────────────────────────────────────────\n";
foreach ($plain as $name => $value) {
    echo "   {$name} = {$value}\n";
}
echo "   ─────────────────────────────────────────────\n";
echo "   ⚠️  Vérifie hors-bande. En cas de mismatch : imposteur → laisse traîner ou clôture.\n";
exit(0);
