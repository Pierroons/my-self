<?php

declare(strict_types=1);

/**
 * escrow-ceremony — CLI de récupération escrow (couche déploiement de l'application appelante).
 *
 * Lit les champs escrow d'un compte EN CAS DE RÉCUP, par EscrowCeremony, la seule
 * voie de la bibliothèque : l'arbitre dit si le dossier autorise la lecture,
 * l'intention part au journal d'audit chaîné et signé puis chez le témoin de
 * tête, et la passphrase admin ne descelle la clé privée de récupération
 * qu'ensuite. Chaque acte — accordé, refusé ou échoué — est au journal. L'admin
 * obtient les champs escrow, jamais le master_key.
 *
 * L'arbitre et le témoin sont à l'exploitant : SelfDataGuard n'en livre aucun
 * qui réponde « oui » par défaut. Chacun vit dans un fichier PHP qui charge son
 * autoloader et RENVOIE l'objet (un EscrowArbiter, un HeadWitness ; sans témoin,
 * `return new NoHeadWitness();`, choisi par son nom).
 *
 * Usage :
 *   php bin/escrow-ceremony.php unlock <user> <dossier> [champ...]
 *   php bin/escrow-ceremony.php unlock-archive <user> <archive> <dossier> [champ...]
 *   php bin/escrow-ceremony.php archives <user> <dossier>
 *   php bin/escrow-ceremony.php verify-log [--ancre <seq:hmac>]
 *
 * Config (env) :
 *   DATAGUARD_DB                base sqlite (coffres + escrow)
 *   DATAGUARD_ADMIN_PUBKEY_FILE fichier clé publique admin (base64)
 *   DATAGUARD_ADMIN_SEALED_FILE fichier clé privée SCELLÉE (v2:ops:mem:salt:blob ; salt:blob avant 0.6.0)
 *   DATAGUARD_AUDIT_LOG         chemin du journal d'audit
 *   DATAGUARD_AUDIT_SECRET      secret HMAC de signature du journal
 *   DATAGUARD_ARBITER_FILE      fichier PHP qui renvoie l'EscrowArbiter
 *   DATAGUARD_WITNESS_FILE      fichier PHP qui renvoie le HeadWitness
 *   DATAGUARD_OPERATOR          (optionnel) identité opérateur pour le forensic
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Crypto\LegacyCipherUnavailableException;
use Pierroons\SelfDataGuard\Escrow\AuditLog;
use Pierroons\SelfDataGuard\Escrow\EscrowArbiter;
use Pierroons\SelfDataGuard\Escrow\EscrowCeremony;
use Pierroons\SelfDataGuard\Escrow\EscrowRefusedException;
use Pierroons\SelfDataGuard\Escrow\HeadWitness;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Vault\WrongSecretException;

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

/** L'objet que renvoie le fichier nommé par $key, qui doit être un $interface. */
function objectFrom(string $key, string $interface): object
{
    $path   = envOrDie($key);
    $object = is_file($path) ? require $path : null;
    if (!$object instanceof $interface) {
        fwrite(STDERR, "{$key} doit nommer un fichier PHP qui renvoie un {$interface} : {$path}\n");
        exit(3);
    }
    return $object;
}

// -----------------------------------------------------------------------------

$cmd = $argv[1] ?? '';

$auditLog = new AuditLog(envOrDie('DATAGUARD_AUDIT_LOG'), envOrDie('DATAGUARD_AUDIT_SECRET'));

if ($cmd === 'verify-log') {
    // L'ancre est la tête notée lors d'une vérification précédente, hors de cette
    // machine : sans elle, un journal tronqué ou supprimé reste une chaîne valide.
    $ancre = null;
    $options = array_slice($argv, 2);
    if ($options !== []) {
        if ($options[0] === '--ancre' && count($options) === 2) {
            $ancre = $options[1];
        } elseif (str_starts_with($options[0], '--ancre=') && count($options) === 1) {
            $ancre = substr($options[0], strlen('--ancre='));
        } else {
            // Une option mal écrite ne doit pas rendre « intègre » sans ancre.
            fwrite(STDERR, "❌ option inconnue : " . implode(' ', $options) . " — usage : verify-log [--ancre seq:hmac]\n");
            exit(2);
        }
    }
    try {
        $r = $ancre === null ? $auditLog->verifyUnanchored() : $auditLog->verify($ancre);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, "❌ ancre illisible : {$e->getMessage()}\n");
        exit(2);
    }
    if ($r['ok']) {
        echo "✅ journal d'audit intègre — {$r['count']} entrée(s), chaîne + signatures valides"
            . ($ancre !== null ? ", ancre retrouvée" : '') . "\n";
        if ($r['head'] !== null) {
            echo "   tête : {$r['head']['seq']}:{$r['head']['hmac']}\n"
                . "   Note-la hors de cette machine, et passe-la en --ancre à la prochaine vérification.\n";
        }
        if ($ancre === null) {
            echo "   ⚠️ sans --ancre, une troncature de la fin du journal ne se voit pas.\n";
        }
        if ($r['quarantined'] > 0) {
            echo "   ⚠️ {$r['quarantined']} ligne(s) non signée(s) mise(s) en quarantaine par le journal : un incident d'écriture à examiner.\n";
        }
        exit(0);
    }
    $raison = match ($r['reason']) {
        'truncated' => "TRONQUÉ : l'entrée #{$r['brokenAt']} de l'ancre a disparu",
        'rewritten' => "RÉÉCRIT : l'entrée #{$r['brokenAt']} n'a plus le hmac de l'ancre",
        'unsigned-tail' => "QUEUE NON SIGNÉE à partir de la ligne #{$r['brokenAt']} : le prochain acte la mettra en quarantaine",
        default     => "ROMPU à l'entrée #{$r['brokenAt']}",
    };
    fwrite(STDERR, "❌ journal d'audit {$raison} (sur {$r['count']})\n");
    exit(1);
}

$arites = ['unlock' => 2, 'unlock-archive' => 3, 'archives' => 2];
if (!isset($arites[$cmd]) || count($argv) < 2 + $arites[$cmd]) {
    fwrite(STDERR, "Usage:\n"
        . "  php bin/escrow-ceremony.php unlock <user> <dossier> [champ...]\n"
        . "  php bin/escrow-ceremony.php unlock-archive <user> <archive> <dossier> [champ...]\n"
        . "  php bin/escrow-ceremony.php archives <user> <dossier>\n"
        . "  php bin/escrow-ceremony.php verify-log [--ancre <seq:hmac>]\n");
    exit(3);
}

$user    = $argv[2];
$archive = $cmd === 'unlock-archive' ? $argv[3] : null;
$dossier = $argv[$cmd === 'unlock-archive' ? 4 : 3];
$fields  = array_slice($argv, $cmd === 'unlock-archive' ? 5 : 4);   // vide = tous les champs escrow
$ctx     = operatorContext();
$pdo     = new PDO('sqlite:' . envOrDie('DATAGUARD_DB'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$ceremony = new EscrowCeremony(
    new SqliteAdapter($pdo),
    $auditLog,
    objectFrom('DATAGUARD_ARBITER_FILE', EscrowArbiter::class),
    objectFrom('DATAGUARD_WITNESS_FILE', HeadWitness::class),
    $ctx['operator'] . ($ctx['ip'] !== null ? " ({$ctx['ip']})" : '')
);

$passphrase = '';
try {
    if ($cmd === 'archives') {
        echo "📦 Archives de « {$user} » (dossier {$dossier})\n";
        foreach ($ceremony->listArchives($user, $dossier) as $a) {
            echo "   {$a['id']}  {$a['archivedAt']->format('c')}  serrures : "
                . implode(', ', array_map(static fn ($l) => $l->value, $a['locks'])) . "\n";
        }
        exit(0);
    }
    $passphrase = readPassphrase("Passphrase admin de récupération : ");
    $sealed     = fileEnv('DATAGUARD_ADMIN_SEALED_FILE');
    $adminPub   = fileEnv('DATAGUARD_ADMIN_PUBKEY_FILE');
    $plain = $archive === null
        ? $ceremony->readEscrow($user, $dossier, $sealed, $passphrase, $adminPub, $fields)
        : $ceremony->readArchiveEscrow($user, $archive, $dossier, $sealed, $passphrase, $adminPub, $fields);
} catch (EscrowRefusedException $e) {
    fwrite(STDERR, "⛔ Refusé — le dossier « {$dossier} » n'autorise pas la lecture pour « {$user} » ({$e->verdict->value}).\n");
    exit(2);
} catch (WrongSecretException) {
    fwrite(STDERR, "⛔ Refusé — passphrase admin invalide.\n");
    exit(2);
} catch (LegacyCipherUnavailableException $e) {
    fwrite(STDERR, "⛔ Refusé — {$e->getMessage()}\n");
    exit(2);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, "⛔ Refusé — fichier de clé scellée illisible : {$e->getMessage()}\n");
    exit(2);
} catch (\Throwable $e) {
    fwrite(STDERR, "⛔ Refusé — {$e->getMessage()}\n");
    exit(2);
} finally {
    sodium_memzero($passphrase);
}

echo "🔓 Escrow déverrouillé pour « {$user} »" . ($archive !== null ? " (archive {$archive})" : '') . " — dossier {$dossier}\n";
echo "   opérateur : {$ctx['operator']}" . ($ctx['ip'] ? " · IP {$ctx['ip']}" : '') . "\n";
echo "   ─────────────────────────────────────────────\n";
foreach ($plain as $name => $value) {
    echo "   {$name} = {$value}\n";
}
echo "   ─────────────────────────────────────────────\n";
echo "   ⚠️  Vérifie hors-bande. En cas de mismatch : imposteur → laisse traîner ou clôture.\n";
exit(0);
