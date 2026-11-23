<?php

declare(strict_types=1);

/**
 * escrow-ceremony — CLI de récupération escrow (couche déploiement de l'application appelante).
 *
 * Lit les champs escrow d'un compte EN CAS DE RÉCUP, ou les compare à une valeur
 * déclarée sans les afficher, par EscrowCeremony, la seule voie de la
 * bibliothèque : l'arbitre dit si le dossier autorise la lecture des champs nommés,
 * l'intention part au journal d'audit chaîné et signé puis chez le témoin de
 * tête, et la passphrase admin ne descelle la clé privée de récupération
 * qu'ensuite. Chaque acte — accordé, refusé ou échoué — est au journal. L'admin
 * obtient les champs escrow, jamais le master_key.
 *
 * L'arbitre et le témoin sont à l'exploitant : SelfDataGuard n'en livre aucun
 * qui réponde « oui » par défaut. Chacun vit dans un fichier PHP qui charge son
 * autoloader et RENVOIE l'objet (un EscrowArbiter, un HeadWitness ; sans témoin,
 * `return new NoHeadWitness();`, choisi par son nom). Le témoin rend aussi la
 * dernière tête qu'il a prise : un journal coupé ou réécrit depuis arrête la
 * cérémonie avant son intention. Sa réserve vit hors de cette machine, en ajout
 * seul, et ne se restaure jamais d'une sauvegarde. Un témoin neuf, ou qui a
 * perdu sa réserve, s'amorce par `seed-witness`, sur la tête notée à la
 * dernière vérification : le journal doit encore la porter. Avant de servir,
 * le témoin passe `bin/witness-conformance.php` : une réserve qui prend une
 * tête de quiconque peut y écrire laisse une seule tête forgée fermer toutes
 * les cérémonies. Si un témoin rend une tête que le journal ne porte pas, rien
 * ici ne distingue une tête forgée d'un journal coupé : vérifier le journal
 * contre l'ancre notée hors de la machine (`verify-log --ancre`), donner à la
 * cérémonie un témoin sur une réserve neuve, puis l'amorcer sur cette ancre.
 *
 * Usage :
 *   php bin/escrow-ceremony.php unlock <user> <dossier> <champ> [champ...]
 *   php bin/escrow-ceremony.php unlock-archive <user> <archive> <dossier> <champ> [champ...]
 *   php bin/escrow-ceremony.php compare <user> <dossier> <champ> [champ...] [--normalisation <n>]
 *   php bin/escrow-ceremony.php compare-archive <user> <archive> <dossier> <champ> [champ...] [--normalisation <n>]
 *   php bin/escrow-ceremony.php archives <user> <dossier>
 *   php bin/escrow-ceremony.php verify-log [--ancre <seq:hmac>]
 *   php bin/escrow-ceremony.php seed-witness --ancre <seq:hmac>
 *   php bin/escrow-ceremony.php seal-to-keeper [user...]
 *   php bin/escrow-ceremony.php adopt-admin
 *   php bin/escrow-ceremony.php change-admin
 *   php bin/escrow-ceremony.php reseal-admin [user...]
 *
 * `adopt-admin` inscrit la clé d'administration dans la base : c'est l'acte de
 * l'exploitant, et le seul qui l'inscrit — aucun dépôt ne le fait, et un dépôt sur une
 * base sans clé inscrite est refusé. Il prouve qu'il tient la clé : la passphrase
 * descelle DATAGUARD_ADMIN_SEALED_FILE, et la clé secrète qu'il porte est la moitié
 * privée de DATAGUARD_ADMIN_PUBKEY_FILE. Une base qui a inscrit une autre clé refuse.
 * Chaque séquestre porte l'empreinte de la clé qui le scelle : la cérémonie la compare à
 * celle qu'on lui donne avant tout gardien, si bien qu'une mauvaise clé ne coûte aucune
 * remise.
 *
 * `change-admin` remplace la clé inscrite (DATAGUARD_OLD_ADMIN_PUBKEY_FILE) par celle des
 * fichiers DATAGUARD_ADMIN_*, prouvée par sa passphrase. `reseal-admin` est la passe qui
 * suit : chaque séquestre que l'ancienne clé ouvre seule (DATAGUARD_OLD_ADMIN_SEALED_FILE et
 * sa passphrase) est re-scellé à la nouvelle ; sans compte nommé, tous ceux de la base. Elle
 * rend ce qu'elle laisse et pourquoi — un séquestre scellé à un gardien ne s'ouvre que par une
 * remise, et se re-scelle au prochain dépôt de son titulaire — et sort en 5 s'il reste
 * quelque chose : garder l'ancienne clé jusque-là. Changer de clé sert un changement de
 * détenteur ; cela ne répare aucune fuite, une copie de la base d'avant s'ouvrant toujours
 * avec l'ancienne.
 *
 * Sous un gardien, une lecture peut être annoncée plutôt que faite : le gardien
 * l'inscrit et la tient jusqu'à une échéance, que le titulaire voit et à laquelle il
 * peut s'opposer. La commande sort alors en 4 ; la même commande, relancée après
 * l'échéance, lit. `seal-to-keeper` est la passe de l'exploitant : chaque séquestre
 * — vivant, dormant, archivé — que l'administrateur seul ouvre encore est scellé au
 * gardien, sans aucun secret en main ; sans compte nommé, tous ceux de la base. La passe
 * scelle au gardien que la base a inscrit, et l'inscrit quand elle n'en a aucun ; donnée
 * un autre, elle ne scelle rien. Un compte en échec est nommé et journalisé, et la passe
 * continue : elle sort en 2 à la fin, avec la liste des comptes à relancer.
 *
 * Une lecture nomme ses champs : aucune ne les prend tous par omission. `compare`
 * demande, pour chaque champ nommé, la valeur que le dossier déclare (saisie masquée,
 * ou une ligne par champ sur STDIN après la passphrase), et rend par champ
 * « concorde », « ne concorde pas sous la normalisation <n> » ou « indisponible »,
 * sans afficher le séquestre. Normalisations : `exact` (par défaut, octet pour octet)
 * et `case-and-spaces` (espaces aux bords retirés, suites d'espaces réduites à une,
 * minuscules) — accents, tirets et formats de date restent tels quels, si bien qu'un
 * « ne concorde pas » ne dit jamais qui a déclaré la valeur. Une comparaison est une
 * lecture : même arbitre, même journal, même gardien, même délai, même objection.
 *
 * Sorties : 0 fait (pour `compare`, chaque champ concorde) · 1 journal rompu · 2 refusé
 * · 3 configuration · 4 lecture annoncée, à relancer après l'échéance · 5 comparaison
 * faite, au moins un champ ne concorde pas ou est indisponible ; pour `reseal-admin`,
 * passe faite, au moins un séquestre laissé sous l'ancienne clé.
 *
 * Config (env) :
 *   DATAGUARD_DB                base sqlite (coffres + escrow)
 *   DATAGUARD_ADMIN_PUBKEY_FILE fichier clé publique admin (base64)
 *   DATAGUARD_ADMIN_SEALED_FILE fichier clé privée SCELLÉE (v2:ops:mem:salt:blob ; salt:blob avant 0.6.0)
 *   DATAGUARD_OLD_ADMIN_PUBKEY_FILE, DATAGUARD_OLD_ADMIN_SEALED_FILE
 *                               (change-admin, reseal-admin) les fichiers de la clé remplacée
 *   DATAGUARD_AUDIT_LOG         chemin du journal d'audit
 *   DATAGUARD_AUDIT_SECRET      secret HMAC de signature du journal ; un secret neuf ouvre un journal
 *                               neuf : sous lui, l'ancien se rompt à sa première ligne. L'ancien se
 *                               vérifie une dernière fois avec son ancre et se garde ; le neuf part
 *                               d'un fichier neuf, la réserve du témoin vidée
 *   DATAGUARD_ARBITER_FILE      fichier PHP qui renvoie l'EscrowArbiter
 *   DATAGUARD_WITNESS_FILE      fichier PHP qui renvoie le HeadWitness
 *   DATAGUARD_KEEPER_FILE       fichier PHP qui renvoie l'EscrowKeeper (`return new NoKeeper();` sans gardien, choisi
 *                               par son nom) : un séquestre scellé à un gardien ne s'ouvre que par lui, et
 *                               seulement par celui que la base a inscrit
 *   DATAGUARD_FORMER_KEEPER_FILES (optionnel) les gardiens que le déploiement a remplacés, séparés par « : » :
 *                               ce qui leur reste scellé — les archives, un séquestre pas encore re-scellé —
 *                               se lit par eux ; chacun doit figurer parmi les anciens que la base a inscrits
 *   DATAGUARD_OPERATOR          (optionnel) identité opérateur pour le forensic
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Crypto\LegacyCipherUnavailableException;
use Pierroons\SelfDataGuard\Escrow\AdminKey;
use Pierroons\SelfDataGuard\Escrow\EscrowAdminException;
use Pierroons\SelfDataGuard\Escrow\AuditLog;
use Pierroons\SelfDataGuard\Escrow\Comparison;
use Pierroons\SelfDataGuard\Escrow\EscrowArbiter;
use Pierroons\SelfDataGuard\Escrow\EscrowAnnouncedException;
use Pierroons\SelfDataGuard\Escrow\EscrowCeremony;
use Pierroons\SelfDataGuard\Escrow\EscrowKeeper;
use Pierroons\SelfDataGuard\Escrow\EscrowKeeperException;
use Pierroons\SelfDataGuard\Escrow\EscrowRefusedException;
use Pierroons\SelfDataGuard\Escrow\HeadWitness;
use Pierroons\SelfDataGuard\Escrow\Normalization;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Storage\UnreadableEscrowException;
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

/**
 * Les anciens gardiens que nomme DATAGUARD_FORMER_KEEPER_FILES — des chemins séparés
 * par « : », chacun un fichier qui renvoie un EscrowKeeper ; aucun sans la variable.
 *
 * @return list<EscrowKeeper>
 */
function anciensGardiens(): array
{
    $gardiens = [];
    foreach (array_filter(explode(':', (string) getenv('DATAGUARD_FORMER_KEEPER_FILES'))) as $path) {
        $gardien = is_file($path) ? require $path : null;
        if (!$gardien instanceof EscrowKeeper) {
            fwrite(STDERR, "DATAGUARD_FORMER_KEEPER_FILES doit nommer des fichiers PHP qui renvoient chacun un EscrowKeeper : {$path}\n");
            exit(3);
        }
        $gardiens[] = $gardien;
    }
    return $gardiens;
}

/**
 * L'ancre que portent les options, ou null sans option. Une option mal écrite
 * sort en 2 : elle ne doit pas rendre « intègre » sans ancre.
 */
function ancreDes(array $options, string $usage): ?string
{
    if ($options === []) {
        return null;
    }
    if ($options[0] === '--ancre' && count($options) === 2) {
        return $options[1];
    }
    if (str_starts_with($options[0], '--ancre=') && count($options) === 1) {
        return substr($options[0], strlen('--ancre='));
    }
    fwrite(STDERR, "❌ option inconnue : " . implode(' ', $options) . " — usage : {$usage}\n");
    exit(2);
}

/** La cérémonie sur la base, l'arbitre et le témoin que nomme la config. */
function ceremonie(AuditLog $auditLog, array $ctx): EscrowCeremony
{
    $pdo = new PDO('sqlite:' . envOrDie('DATAGUARD_DB'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return new EscrowCeremony(
        new SqliteAdapter($pdo),
        $auditLog,
        objectFrom('DATAGUARD_ARBITER_FILE', EscrowArbiter::class),
        objectFrom('DATAGUARD_WITNESS_FILE', HeadWitness::class),
        objectFrom('DATAGUARD_KEEPER_FILE', EscrowKeeper::class),
        $ctx['operator'] . ($ctx['ip'] !== null ? " ({$ctx['ip']})" : ''),
        anciensGardiens()
    );
}

// -----------------------------------------------------------------------------

$cmd = $argv[1] ?? '';

$auditLog = new AuditLog(envOrDie('DATAGUARD_AUDIT_LOG'), envOrDie('DATAGUARD_AUDIT_SECRET'));

if ($cmd === 'seed-witness') {
    $ancre = ancreDes(array_slice($argv, 2), 'seed-witness --ancre seq:hmac');
    if ($ancre === null) {
        fwrite(STDERR, "❌ seed-witness demande --ancre : la tête notée hors de cette machine à la dernière vérification\n");
        exit(2);
    }
    try {
        ceremonie($auditLog, operatorContext())->seedWitness($ancre);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, "❌ ancre illisible : {$e->getMessage()}\n");
        exit(2);
    } catch (\Throwable $e) {
        fwrite(STDERR, "⛔ Témoin non amorcé — {$e->getMessage()}\n");
        exit(2);
    }
    echo "✅ témoin amorcé : le journal porte l'ancre {$ancre}, et le témoin tient sa tête\n";
    exit(0);
}

if ($cmd === 'verify-log') {
    // L'ancre est la tête notée lors d'une vérification précédente, hors de cette
    // machine : sans elle, un journal tronqué ou supprimé reste une chaîne valide.
    $ancre = ancreDes(array_slice($argv, 2), 'verify-log [--ancre seq:hmac]');
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

/**
 * Les comptes nommés, ou, sans nom, ceux de la base qui portent un séquestre —
 * lisible ou non — ou une archive : une passe n'a rien à faire chez les autres.
 *
 * @param list<string> $noms
 * @return list<string>
 */
function comptesDeLaPasse(array $noms): array
{
    if ($noms !== []) {
        return $noms;
    }
    $pdo = new PDO('sqlite:' . envOrDie('DATAGUARD_DB'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stockage = new SqliteAdapter($pdo);
    $comptes  = [];
    for ($apres = ''; ($page = $stockage->accountIds($apres)) !== []; $apres = (string) end($page)) {
        foreach ($page as $compte) {
            try {
                $aSceller = $stockage->loadEscrow($compte) !== null || $stockage->listArchives($compte) !== [];
            } catch (UnreadableEscrowException) {
                $aSceller = true;
            }
            if ($aSceller) {
                $comptes[] = $compte;
            }
        }
    }
    return $comptes;
}

if ($cmd === 'seal-to-keeper') {
    $comptes  = comptesDeLaPasse(array_slice($argv, 2));
    $ceremony = ceremonie($auditLog, operatorContext());
    $gele = 0;
    $echecs = [];
    foreach ($comptes as $compte) {
        try {
            $fait = $ceremony->sealToKeeper((string) $compte);
        } catch (\Throwable $e) {
            fwrite(STDERR, "⛔ {$compte} — {$e->getMessage()}\n");
            $echecs[] = (string) $compte;
            continue;
        }
        $gele += $fait['frozen'] ? 1 : 0;
        echo "🔐 {$compte} : " . ($fait['live'] ? 'coffre vivant scellé au gardien' : 'coffre vivant sans changement')
            . ($fait['archives'] === [] ? '' : ', ' . count($fait['archives']) . ' archive(s) scellée(s)')
            . ($fait['frozen'] ? ' — ⚠️ coffre gelé, non scellé : à reprendre après la clôture' : '') . "\n";
    }
    if ($echecs !== []) {
        fwrite(STDERR, '⛔ ' . count($echecs) . ' compte(s) non scellé(s), à relancer : ' . implode(', ', $echecs) . "\n");
    }
    exit($gele === 0 && $echecs === [] ? 0 : 2);
}

if ($cmd === 'adopt-admin') {
    $ctx  = operatorContext();
    $qui  = $ctx['operator'] . ($ctx['ip'] !== null ? " ({$ctx['ip']})" : '');
    $pub  = fileEnv('DATAGUARD_ADMIN_PUBKEY_FILE');
    $pdo  = new PDO('sqlite:' . envOrDie('DATAGUARD_DB'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $passphrase = readPassphrase("Passphrase admin de récupération : ");
    try {
        SelfDataGuard::adoptEscrowAdmin(new SqliteAdapter($pdo), fileEnv('DATAGUARD_ADMIN_SEALED_FILE'), $passphrase, $pub);
        $empreinte = AdminKey::fingerprint($pub);
    } catch (\Throwable $e) {
        $raison = match (true) {
            $e instanceof EscrowAdminException  => $e->reason,
            $e instanceof WrongSecretException  => 'bad-passphrase',
            default                             => 'sealed-key-unreadable',
        };
        $auditLog->append(['action' => 'adopt-admin-denied', 'reason' => $raison, 'operator' => $qui]);
        fwrite(STDERR, "⛔ Clé d'administration non inscrite ({$raison}) — {$e->getMessage()}\n");
        exit(2);
    } finally {
        sodium_memzero($passphrase);
    }
    $auditLog->append(['action' => 'adopt-admin', 'admin' => $empreinte, 'operator' => $qui]);
    echo "✅ clé d'administration {$empreinte} inscrite : chaque dépôt la scelle désormais, et la cérémonie compare chaque séquestre à elle\n";
    exit(0);
}

if ($cmd === 'change-admin') {
    $ctx  = operatorContext();
    $qui  = $ctx['operator'] . ($ctx['ip'] !== null ? " ({$ctx['ip']})" : '');
    $ancienne = fileEnv('DATAGUARD_OLD_ADMIN_PUBKEY_FILE');
    $nouvelle = fileEnv('DATAGUARD_ADMIN_PUBKEY_FILE');
    $pdo  = new PDO('sqlite:' . envOrDie('DATAGUARD_DB'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $passphrase = readPassphrase("Passphrase de la NOUVELLE clé d'administration : ");
    try {
        SelfDataGuard::changeEscrowAdmin(new SqliteAdapter($pdo), $ancienne, fileEnv('DATAGUARD_ADMIN_SEALED_FILE'), $passphrase, $nouvelle);
    } catch (\Throwable $e) {
        $raison = match (true) {
            $e instanceof EscrowAdminException  => $e->reason,
            $e instanceof WrongSecretException  => 'bad-passphrase',
            default                             => 'sealed-key-unreadable',
        };
        $auditLog->append(['action' => 'change-admin-denied', 'reason' => $raison, 'operator' => $qui]);
        fwrite(STDERR, "⛔ Clé d'administration non changée ({$raison}) — {$e->getMessage()}\n");
        exit(2);
    } finally {
        sodium_memzero($passphrase);
    }
    $auditLog->append(['action' => 'change-admin', 'admin' => AdminKey::fingerprint($nouvelle), 'former' => AdminKey::fingerprint($ancienne), 'operator' => $qui]);
    echo "✅ clé d'administration " . AdminKey::fingerprint($nouvelle) . " inscrite à la place de " . AdminKey::fingerprint($ancienne)
        . " : chaque dépôt scelle à la nouvelle. Ce qui reste scellé à l'ancienne se re-scelle par reseal-admin — garde l'ancienne clé jusque-là.\n";
    exit(0);
}

if ($cmd === 'reseal-admin') {
    $comptes  = comptesDeLaPasse(array_slice($argv, 2));
    $ceremony = ceremonie($auditLog, operatorContext());
    $passphrase = readPassphrase("Passphrase de l'ANCIENNE clé d'administration : ");
    try {
        $faits = $ceremony->resealToAdmin($comptes, fileEnv('DATAGUARD_OLD_ADMIN_SEALED_FILE'), $passphrase,
            fileEnv('DATAGUARD_OLD_ADMIN_PUBKEY_FILE'), fileEnv('DATAGUARD_ADMIN_PUBKEY_FILE'));
    } catch (EscrowAdminException $e) {
        fwrite(STDERR, "⛔ Passe refusée — clé d'administration ({$e->reason}) : {$e->getMessage()}\n");
        exit(2);
    } catch (WrongSecretException) {
        fwrite(STDERR, "⛔ Passe refusée — passphrase de l'ancienne clé invalide.\n");
        exit(2);
    } catch (\Throwable $e) {
        fwrite(STDERR, "⛔ Passe refusée — {$e->getMessage()}\n");
        exit(2);
    } finally {
        sodium_memzero($passphrase);
    }
    $laisses = 0;
    foreach ($faits as $compte => $fait) {
        echo "🔐 {$compte} : " . ($fait['live'] ? 'coffre vivant re-scellé à la nouvelle clé' : 'coffre vivant sans changement')
            . ($fait['archives'] === [] ? '' : ', ' . count($fait['archives']) . ' archive(s) re-scellée(s)') . "\n";
        foreach ($fait['left'] as $ou => $raison) {
            $laisses++;
            echo "   ⚠️ laissé sous l'ancienne clé — {$ou} : {$raison}\n";
        }
    }
    if ($laisses > 0) {
        fwrite(STDERR, "⚠️ {$laisses} séquestre(s) laissé(s) sous l'ancienne clé : garde-la tant qu'il en reste.\n");
    }
    exit($laisses === 0 ? 0 : 5);
}

$arites = ['unlock' => 3, 'unlock-archive' => 4, 'archives' => 2, 'compare' => 3, 'compare-archive' => 4];
$args   = array_slice($argv, 2);
$normalisation = Normalization::Exact;
foreach ($args as $i => $arg) {
    if ($arg === '--normalisation' || str_starts_with($arg, '--normalisation=')) {
        $nom = $arg === '--normalisation' ? ($args[$i + 1] ?? '') : substr($arg, strlen('--normalisation='));
        $normalisation = Normalization::tryFrom($nom) ?? (function () use ($nom): never {
            fwrite(STDERR, "❌ normalisation inconnue « {$nom} » : exact ou case-and-spaces\n");
            exit(3);
        })();
        array_splice($args, $i, $arg === '--normalisation' ? 2 : 1);
        break;
    }
}
if (!isset($arites[$cmd]) || count($args) < $arites[$cmd]) {
    fwrite(STDERR, "Usage:\n"
        . "  php bin/escrow-ceremony.php unlock <user> <dossier> <champ> [champ...]\n"
        . "  php bin/escrow-ceremony.php unlock-archive <user> <archive> <dossier> <champ> [champ...]\n"
        . "  php bin/escrow-ceremony.php compare <user> <dossier> <champ> [champ...] [--normalisation exact|case-and-spaces]\n"
        . "  php bin/escrow-ceremony.php compare-archive <user> <archive> <dossier> <champ> [champ...] [--normalisation exact|case-and-spaces]\n"
        . "  php bin/escrow-ceremony.php archives <user> <dossier>\n"
        . "  php bin/escrow-ceremony.php verify-log [--ancre <seq:hmac>]\n"
        . "  php bin/escrow-ceremony.php seed-witness --ancre <seq:hmac>\n"
        . "  php bin/escrow-ceremony.php seal-to-keeper [user...]\n"
        . "  php bin/escrow-ceremony.php adopt-admin\n"
        . "  php bin/escrow-ceremony.php change-admin\n"
        . "  php bin/escrow-ceremony.php reseal-admin [user...]\n");
    exit(3);
}

$surArchive = $cmd === 'unlock-archive' || $cmd === 'compare-archive';
$compare    = $cmd === 'compare' || $cmd === 'compare-archive';
$user       = $args[0];
$archive    = $surArchive ? $args[1] : null;
$dossier    = $args[$surArchive ? 2 : 1];
$fields     = array_slice($args, $surArchive ? 3 : 2);
if (count(array_unique($fields)) !== count($fields)) {
    fwrite(STDERR, "❌ un champ est nommé deux fois : " . implode(' ', $fields) . "\n");
    exit(3);
}
$ctx      = operatorContext();
$ceremony = ceremonie($auditLog, $ctx);

$passphrase = '';
$declare    = [];
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
    if ($compare) {
        foreach ($fields as $champ) {
            $declare[$champ] = readPassphrase("Valeur déclarée pour « {$champ} » : ");
        }
        $resultat = $archive === null
            ? $ceremony->compareEscrow($user, $dossier, $sealed, $passphrase, $adminPub, $declare, $normalisation)
            : $ceremony->compareArchiveEscrow($user, $archive, $dossier, $sealed, $passphrase, $adminPub, $declare, $normalisation);
    } else {
        $plain = $archive === null
            ? $ceremony->readEscrow($user, $dossier, $sealed, $passphrase, $adminPub, $fields)
            : $ceremony->readArchiveEscrow($user, $archive, $dossier, $sealed, $passphrase, $adminPub, $fields);
    }
} catch (EscrowAnnouncedException $e) {
    fwrite(STDERR, "⏳ Lecture annoncée chez le gardien : rien n'est descellé avant " . gmdate('Y-m-d H:i', $e->due)
        . " UTC. Le titulaire la voit et peut s'y opposer d'ici là ; relance la même commande ensuite.\n");
    exit(4);
} catch (EscrowKeeperException $e) {
    fwrite(STDERR, "⛔ Refusé — gardien ({$e->reason}) : {$e->getMessage()}\n");
    exit(2);
} catch (EscrowAdminException $e) {
    fwrite(STDERR, "⛔ Refusé — clé d'administration ({$e->reason}) : {$e->getMessage()}\n");
    exit(2);
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
    foreach (array_keys($declare) as $champ) {
        sodium_memzero($declare[$champ]);
    }
}

$cible = "« {$user} »" . ($archive !== null ? " (archive {$archive})" : '') . " — dossier {$dossier}";
$qui   = "   opérateur : {$ctx['operator']}" . ($ctx['ip'] ? " · IP {$ctx['ip']}" : '') . "\n";
if ($compare) {
    echo "🔎 Comparaison pour {$cible} — normalisation « {$normalisation->value} »\n" . $qui;
    foreach ($resultat as $name => $issue) {
        echo "   {$name} : " . match ($issue) {
            Comparison::Matches     => 'concorde',
            Comparison::Differs     => "ne concorde pas sous la normalisation « {$normalisation->value} »",
            Comparison::Unavailable => "indisponible — le séquestre ne porte pas ce champ, ou il ne s'ouvre pas",
        } . "\n";
    }
    echo "   Aucune valeur du séquestre n'est affichée. La comparaison est au journal ; sous un gardien, le titulaire la voit comme une remise.\n";
    exit(array_filter($resultat, static fn (Comparison $c): bool => $c !== Comparison::Matches) === [] ? 0 : 5);
}
echo "🔓 Escrow déverrouillé pour {$cible}\n" . $qui;
echo "   ─────────────────────────────────────────────\n";
foreach ($plain as $name => $value) {
    echo "   {$name} = {$value}\n";
}
echo "   ─────────────────────────────────────────────\n";
echo "   Cette lecture est au journal ; sous un gardien, le titulaire la voit comme une remise. Pour confronter une\n"
    . "   déclaration sans afficher le séquestre : la commande compare.\n";
exit(0);
