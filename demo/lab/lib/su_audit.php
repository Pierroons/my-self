<?php
/**
 * MySelf-Lab — journal SU (gouvernance des administrateurs).
 *
 * Quatre couches : append-only (`chattr +a` en production), chaîne de hachage,
 * HMAC par entrée, et externalisation vers ntfy. Le fichier JSON-lines est la
 * source de vérité ; la base n'en est qu'un cache. Un `is_admin = 1` sans entrée
 * de création correspondante est un admin fantôme, que `selfrecover-su audit`
 * révoque.
 *
 * La chaîne se vérifie de l'intérieur, donc elle ne détecte pas sa propre
 * troncature : le préfixe d'une chaîne valide est une chaîne valide. C'est le
 * rôle de l'externalisation, qui emporte `entry_hash` et `seq` hors de la
 * machine — un témoin distant rend la troncature visible, y compris contre
 * quelqu'un qui détient le secret HMAC local.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use RuntimeException;

final class SuAudit
{
    public const ACTION_ADD_ADMIN       = 'add-admin';
    public const ACTION_REVOKE_ADMIN    = 'revoke-admin';
    public const ACTION_APPROVE_REQUEST = 'approve-request';
    public const ACTION_REJECT_REQUEST  = 'reject-request';
    public const ACTION_QUARANTINE      = 'quarantine-ghost';
    public const ACTION_RESET_SHELL     = 'reset-shell';
    public const ACTION_CHANGE_PASS     = 'change-passphrase';
    public const ACTION_BACKUP_LOG      = 'backup-log';

    /**
     * Les deux listes que `audit` rejoue pour reconstituer qui est légitimement
     * admin. Elles dérivent des constantes : une action renommée d'un côté sans
     * l'autre produisait une branche morte silencieuse dans la logique qui
     * décide d'une révocation.
     */
    public const GRANTING = [self::ACTION_ADD_ADMIN, self::ACTION_APPROVE_REQUEST];
    public const REVOKING = [self::ACTION_REVOKE_ADMIN, self::ACTION_RESET_SHELL, self::ACTION_QUARANTINE];

    public const DEMO_SECRET = 'dev-su-audit-secret-CHANGE-IN-PROD';

    /**
     * Le mode permissif se demande, il ne s'hérite pas. Un déploiement qui ne
     * pose rien obtient le régime strict : les valeurs de démonstration y sont
     * refusées au démarrage.
     */
    public static function devMode(): bool
    {
        return getenv('SELFRECOVER_SU_DEV') === '1';
    }

    public static function logPath(): string
    {
        $env = getenv('SELFRECOVER_SU_AUDIT_LOG');
        if ($env) {
            return $env;
        }
        $dir = getenv('SELFRECOVER_STATE_DIR');

        return $dir ? rtrim($dir, '/') . '/su-audit.log' : self::stateFallback() . '/su-audit.log';
    }

    /**
     * Repli quand aucun `SELFRECOVER_STATE_DIR` n'est posé. Il sort de
     * l'arborescence du module : le journal et le secret SU vivaient sinon à
     * côté des pages servies, où une racine web mal placée les rend joignables
     * par une simple requête. Le `.gitignore` protège le dépôt, pas le serveur.
     */
    public static function stateFallback(): string
    {
        return dirname(__DIR__, 3) . '/.su-state';
    }

    public static function secret(): string
    {
        return getenv('SELFRECOVER_SU_AUDIT_SECRET') ?: self::DEMO_SECRET;
    }

    /**
     * Contexte forensique : qui est derrière la connexion. Il prouve qui a agi
     * sur le serveur, ce qui est le but pour un opérateur assumé — et l'inverse
     * de ce que cherche un opérateur anonyme. `SU_FORENSIC_MINIMAL=1` réduit
     * l'entrée à ce que la chaîne exige.
     */
    public static function forensicContext(): array
    {
        if (getenv('SU_FORENSIC_MINIMAL') === '1') {
            return ['mode' => 'minimal'];
        }

        $ssh   = getenv('SSH_CONNECTION') ?: '';
        $parts = $ssh !== '' ? explode(' ', $ssh) : [];
        $argv  = $GLOBALS['argv'] ?? ($_SERVER['argv'] ?? []);

        return [
            'operator'      => getenv('SU_OPERATOR') ?: null,
            'unix_user'     => getenv('USER') ?: (getenv('LOGNAME') ?: '?'),
            'sudo_user'     => getenv('SUDO_USER') ?: null,
            'uid'           => function_exists('posix_getuid') ? posix_getuid() : (int) @getmyuid(),
            'gid'           => function_exists('posix_getgid') ? posix_getgid() : (int) @getmygid(),
            'ssh_client_ip' => $parts[0] ?? null,
            'ssh_raw'       => $ssh ?: null,
            'tty'           => (function_exists('posix_ttyname') && defined('STDIN'))
                ? (@posix_ttyname(STDIN) ?: null)
                : (getenv('SSH_TTY') ?: null),
            'pid'           => getmypid(),
            'ppid'          => function_exists('posix_getppid') ? posix_getppid() : null,
            'hostname'      => gethostname() ?: '?',
            'cmd'           => $argv ? implode(' ', $argv) : '?',
        ];
    }

    /** Toutes les entrées, dans l'ordre. Lecture intégrale : réservée à l'affichage et à la vérification. */
    public static function read(): array
    {
        $path = self::logPath();
        if (!file_exists($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $d = json_decode($line, true);
            if ($d) {
                $out[] = $d;
            }
        }

        return $out;
    }

    public static function entryHash(array $core, string $prevHash): string
    {
        unset($core['entry_hash'], $core['hmac']);

        return hash('sha256', json_encode($core, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . $prevHash);
    }

    /**
     * Ajoute une entrée : tête de chaîne lue et ligne écrite dans la même
     * section critique. Séparées, deux appends concurrents partent du même
     * `prev_hash` et rompent la chaîne — improbable sous une CLI conduite par un
     * humain, certain dès qu'un service journalise.
     *
     * @param string $action Une des constantes ACTION_*.
     */
    public static function append(string $action, string $target, array $extra = []): array
    {
        $path = self::logPath();
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("su-audit : impossible de créer $dir");
        }

        // 'a+' et non 'c+' : `chattr +a` n'autorise l'ouverture en écriture
        // qu'avec O_APPEND. Un mode qui permettrait de réécrire sur place ferait
        // échouer l'ouverture sur toute machine où la mesure est appliquée.
        $fh = @fopen($path, 'a+');
        if ($fh === false) {
            throw new RuntimeException("su-audit : ouverture impossible de $path");
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            throw new RuntimeException("su-audit : verrou refusé sur $path");
        }

        try {
            $last     = self::tailEntry($fh);
            $prevHash = $last['entry_hash'] ?? str_repeat('0', 64);
            $seq      = ($last['seq'] ?? 0) + 1;

            $now   = new \DateTime('now', new \DateTimeZone('UTC'));
            $paris = (clone $now)->setTimezone(new \DateTimeZone('Europe/Paris'));

            $core = [
                'seq'       => $seq,
                'ts_utc'    => $now->format('Y-m-d\TH:i:s\Z'),
                'ts_paris'  => $paris->format('Y-m-d H:i:s'),
                'action'    => $action,
                'target'    => $target,
                'extra'     => $extra,
                'forensic'  => self::forensicContext(),
                'prev_hash' => $prevHash,
            ];
            $core['entry_hash'] = self::entryHash($core, $prevHash);
            $core['hmac']       = hash_hmac('sha256', $core['entry_hash'], self::secret());

            $line = json_encode($core, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            if (fwrite($fh, $line) === false) {
                throw new RuntimeException("su-audit : écriture impossible dans $path");
            }
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        @chmod($path, 0600);
        $core['ntfy_delivered'] = self::notify($core);

        return $core;
    }

    /**
     * Dernière entrée du journal, lue en remontant par blocs : la tête de chaîne
     * ne coûte pas la relecture du fichier entier.
     *
     * Une queue illisible lève : reprendre une chaîne dont on ne sait pas où
     * elle en est reviendrait à en démarrer une neuve en silence, ce qui est
     * exactement ce que le journal existe pour rendre impossible.
     */
    private static function tailEntry($fh): ?array
    {
        $stat = fstat($fh);
        $size = $stat['size'] ?? 0;
        if ($size === 0) {
            return null;
        }

        $buf = '';
        $pos = $size;
        while ($pos > 0) {
            $read = (int) min(4096, $pos);
            $pos -= $read;
            fseek($fh, $pos, SEEK_SET);
            $buf     = (string) fread($fh, $read) . $buf;
            $trimmed = rtrim($buf, "\n");
            $nl      = strrpos($trimmed, "\n");
            if ($nl !== false) {
                $buf = substr($trimmed, $nl + 1);
                break;
            }
            if ($pos === 0) {
                $buf = $trimmed;
            }
        }

        if (trim($buf) === '') {
            return null;
        }
        $decoded = json_decode($buf, true);
        if (!is_array($decoded) || !isset($decoded['entry_hash'], $decoded['seq'])) {
            throw new RuntimeException(
                'su-audit : dernière entrée illisible — chaîne non reprise. Vérifie ' . self::logPath()
            );
        }

        return $decoded;
    }

    /**
     * Externalisation. Le message porte `entry_hash` et `seq` : sans eux, le
     * témoin distant sait qu'une action a eu lieu mais ne peut pas dire si le
     * journal local les a toutes gardées.
     *
     * Le transport passe par cURL et non par le wrapper `http://` : celui-ci
     * n'accepte que des proxys HTTP, et une option `proxy => socks5://…` posée
     * sur un contexte de flux est ignorée sans erreur — la requête partirait en
     * clair en croyant passer par Tor. `SOCKS5_HOSTNAME` fait en outre résoudre
     * le nom par le proxy : résolu localement, il fuirait en DNS ce que le
     * circuit protège.
     *
     * @return bool|null null si aucune externalisation n'est configurée.
     */
    private static function notify(array $entry): ?bool
    {
        $url = getenv('SELFRECOVER_NTFY_URL');
        if (!$url) {
            return null;
        }

        $msg = sprintf(
            '[SU-AUDIT] %s : %s (seq %d · %s) hash %s',
            $entry['action'],
            $entry['target'],
            $entry['seq'],
            $entry['ts_paris'],
            substr($entry['entry_hash'], 0, 16)
        );

        $headers = ['Content-Type: text/plain', 'Title: SelfRecover SU', 'Priority: high', 'Tags: warning,key'];
        $token   = getenv('SELFRECOVER_NTFY_TOKEN') ?: '';
        if ($token !== '') {
            $headers[] = "Authorization: Bearer $token";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $msg,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
        ]);

        $socks = getenv('SELFRECOVER_NTFY_SOCKS');
        if ($socks === false || $socks === '') {
            $socks = self::onionMode() ? 'socks5h://127.0.0.1:9050' : '';
        }
        if ($socks !== '' && $socks !== 'none') {
            curl_setopt($ch, CURLOPT_PROXY, $socks);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        }

        $ok = curl_exec($ch) !== false && curl_errno($ch) === 0;
        curl_close($ch);

        return $ok;
    }

    /** Le service est-il déclaré comme servi derrière un service caché. */
    public static function onionMode(): bool
    {
        return getenv('SELFRECOVER_ONION_MODE') === '1';
    }

    /** Intégrité de la chaîne : prev_hash, entry_hash et HMAC de chaque entrée. */
    public static function verify(): array
    {
        $entries = self::read();
        $prev    = str_repeat('0', 64);
        foreach ($entries as $i => $e) {
            if (($e['prev_hash'] ?? null) !== $prev) {
                return ['ok' => false, 'break_at' => $i + 1, 'reason' => 'chaîne rompue (prev_hash)'];
            }
            $h = self::entryHash($e, $e['prev_hash']);
            if ($h !== ($e['entry_hash'] ?? '')) {
                return ['ok' => false, 'break_at' => $i + 1, 'reason' => 'entrée altérée (entry_hash)'];
            }
            if (!hash_equals(hash_hmac('sha256', $h, self::secret()), (string) ($e['hmac'] ?? ''))) {
                return ['ok' => false, 'break_at' => $i + 1, 'reason' => 'signature invalide (HMAC)'];
            }
            $prev = $e['entry_hash'];
        }

        return ['ok' => true, 'count' => count($entries)];
    }
}
