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
    /**
     * Constat, pas mutation : l'empreinte du secret en place est portée au
     * journal sans que le secret change. Sans cette action, un secret posé hors
     * de `change-passphrase` — à la main, par restauration — laisse `verify-log`
     * dans un désaccord permanent, et une alarme qui démarre rouge s'ignore.
     */
    public const ACTION_RECORD_SEAL     = 'record-seal';
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

    /**
     * Le secret est-il réellement posé pour ce régime ?
     *
     * Rendu à part pour qu'un appelant puisse refuser avec SON message — la console
     * sait dire quoi faire, une exception générique non. Ce prédicat ne lève pas :
     * c'est `secret()` qui tranche.
     */
    public static function secretPose(): bool
    {
        if (self::devMode()) {
            return true;
        }
        $pose = getenv('SELFRECOVER_SU_AUDIT_SECRET') ?: '';

        return $pose !== '' && $pose !== self::DEMO_SECRET;
    }

    /**
     * 🔑 **Le refus appartient à la fonction, pas à ses appelants.**
     *
     * L'en-tête de cette classe promet que « les valeurs de démonstration sont
     * refusées au démarrage » en régime strict. Le refus existait — mais dans
     * `selfrecover-su`, c'est-à-dire chez UN appelant. Cette méthode, elle, rendait
     * `DEMO_SECRET` sans un mot à qui la demandait autrement : un second outil, une
     * page, un script d'exploitation auraient signé le journal d'audit avec une
     * constante publiée dans le dépôt, et rien n'aurait rougi.
     *
     * Une garde placée chez l'appelant n'est pas une garde, c'est une convention —
     * et une convention se contourne par le prochain appelant. Le constat vient de
     * deux endroits le même jour : une route de promotion dont le contrôle vivait
     * chez son appelant, et `SecretInstance::lire()`, corrigé dans le même lot.
     *
     * La console garde son propre refus : il arrive plus tôt et il dit quoi faire.
     * Celui-ci est le filet, pour tous les autres.
     *
     * @throws RuntimeException en régime strict, quand rien n'est posé ou que la
     *                          valeur de démonstration a été laissée en place
     */
    public static function secret(): string
    {
        if (self::devMode()) {
            return getenv('SELFRECOVER_SU_AUDIT_SECRET') ?: self::DEMO_SECRET;
        }
        $pose = getenv('SELFRECOVER_SU_AUDIT_SECRET') ?: '';
        if ($pose === '' || $pose === self::DEMO_SECRET) {
            throw new RuntimeException(
                'SELFRECOVER_SU_AUDIT_SECRET absent ou laissé à sa valeur de démonstration. '
                . 'Le journal SU ne sera pas signé avec une constante publiée dans le dépôt. '
                . 'Pose la variable à l\'installation, ou SELFRECOVER_SU_DEV=1 pour un banc.'
            );
        }

        return $pose;
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

    /**
     * Toutes les entrées, dans l'ordre. Lecture intégrale : réservée à l'affichage et à la vérification.
     *
     * 🔑 **« Illisible » ne se rend jamais comme « aucun événement ».** Un journal qu'on
     * ne peut pas ouvrir — répertoire non traversable, droits perdus, disque monté en
     * lecture seule — rendait un tableau vide, indiscernable d'un journal sans entrée.
     * Sur un journal d'audit, c'est le pire des faux verts : l'absence de preuve prend
     * l'apparence de la preuve d'absence.
     *
     * Le cas du répertoire compte autant que celui du fichier : quand le dossier n'est
     * pas traversable, `file_exists()` répond non pour un fichier qui est bien là.
     * Rencontré le 15/09/2026 sur un déploiement intégrateur, un conteneur en uid 1000
     * devant un répertoire `700 root` — le service annonçait « certificat absent ».
     *
     * @throws \RuntimeException quand le journal est illisible, ou que son absence ne
     *                           peut pas être établie
     */
    public static function read(): array
    {
        $path = self::logPath();
        if (!file_exists($path)) {
            $dossier = dirname($path);
            if (!is_dir($dossier) || !is_readable($dossier) || !is_executable($dossier)) {
                throw new \RuntimeException(
                    "Journal SU : impossible d'établir si {$path} existe — {$dossier} n'est pas "
                    . 'traversable. Refus de répondre « aucune entrée » : illisible n\'est pas vide.'
                );
            }

            return [];
        }
        $lignes = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lignes === false) {
            throw new \RuntimeException(
                "Journal SU : {$path} est présent mais illisible. "
                . 'Refus de répondre « aucune entrée » : illisible n\'est pas vide.'
            );
        }
        $out = [];
        foreach ($lignes as $line) {
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
        if ($core['ntfy_delivered'] === true) {
            self::poserMarqueTemoin($core);
        }

        return $core;
    }

    /**
     * Fichier de la marque du témoin — la trace du dernier envoi CONFIRMÉ.
     *
     * 🔑 **Il vit à côté du journal, pas dedans.** C'est tout l'objet de ce
     * mécanisme : la trace « le témoin n'a pas répondu » ne peut pas vivre dans le
     * fichier que le témoin existe pour protéger. Qui tronque le journal effacerait
     * aussi la preuve que l'externalisation échouait.
     */
    public static function marqueTemoinPath(): string
    {
        return dirname(self::logPath()) . '/su-audit-temoin.json';
    }

    /**
     * Enregistre le plus haut point confirmé par le témoin distant : `seq` et
     * `entry_hash` de la dernière entrée dont l'envoi a réussi.
     *
     * Écriture par fichier temporaire puis renommage : une marque tronquée dirait
     * un point de confirmation faux, et une marque fausse est pire qu'absente —
     * c'est le motif du sel de déploiement, dans `install.sh`.
     */
    private static function poserMarqueTemoin(array $entry): void
    {
        $f   = self::marqueTemoinPath();
        $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $doc = json_encode([
            'seq'        => $entry['seq'],
            'entry_hash' => $entry['entry_hash'],
            'confirme_a' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($tmp, $doc . "\n") === false) {
            return;   // ne jamais faire échouer une action d'administration pour ça
        }
        @chmod($tmp, 0600);
        @rename($tmp, $f);
    }

    /**
     * Où en est le témoin distant, **sans lire le journal pour le savoir** ?
     *
     * 🔑 **Trois états, et le troisième est celui qui vaut.** La chaîne de hachage
     * se vérifie de l'intérieur : elle ne détecte pas sa propre troncature, car le
     * préfixe d'une chaîne valide est une chaîne valide. Le témoin distant est le
     * seul angle qui la rende visible — et jusqu'ici, son silence ne se voyait
     * nulle part. Rien, ni dans l'entrée, ni ailleurs, ne disait si la notification
     * était partie : `ntfy_delivered` était posé APRÈS l'écriture de la ligne,
     * donc il n'atteignait jamais le fichier, et aucun appelant ne lisait le retour.
     *
     * - `jamais` : une externalisation est configurée, aucun envoi n'a été confirmé.
     * - `muet` : le témoin n'a rien confirmé depuis N entrées — il échoue en silence.
     * - `tronque` : **le journal est plus court que ce que le témoin a confirmé.**
     *   La marque vit hors du journal : une troncature en dessous d'elle se voit
     *   donc sans que le journal ait à le dire.
     *
     * ⚠️ Ce que ça NE fait PAS : résister à qui a root et efface les deux fichiers.
     * La marque rend visible une défaillance silencieuse et une troncature partielle ;
     * contre un effacement complet, c'est le témoin DISTANT qui reste la seule preuve.
     *
     * @return array{etat: string, marque: ?int, tete: ?int, detail: string}
     */
    public static function ecartTemoin(): array
    {
        $rien = static fn(string $e, string $d): array
            => ['etat' => $e, 'marque' => null, 'tete' => null, 'detail' => $d];

        if (!getenv('SELFRECOVER_NTFY_URL')) {
            return $rien('non_configure', 'aucune externalisation configurée — la troncature du journal ne serait visible de nulle part');
        }

        $f = self::marqueTemoinPath();
        $m = is_readable($f) ? json_decode((string) @file_get_contents($f), true) : null;

        $entrees = self::read();
        $tete    = $entrees ? (int) ($entrees[count($entrees) - 1]['seq'] ?? 0) : 0;

        if (!is_array($m) || !isset($m['seq'])) {
            return ['etat' => 'jamais', 'marque' => null, 'tete' => $tete,
                    'detail' => "aucun envoi confirmé — le témoin distant n'a jamais répondu"];
        }
        $marque = (int) $m['seq'];

        if ($marque > $tete) {
            return ['etat' => 'tronque', 'marque' => $marque, 'tete' => $tete,
                    'detail' => "le témoin a confirmé l'entrée $marque, le journal s'arrête à $tete — il a été RACCOURCI"];
        }
        if ($marque < $tete) {
            return ['etat' => 'muet', 'marque' => $marque, 'tete' => $tete,
                    'detail' => 'le témoin n\'a rien confirmé depuis ' . ($tete - $marque) . " entrée(s) — dernière confirmation : $marque"];
        }

        return ['etat' => 'a_jour', 'marque' => $marque, 'tete' => $tete,
                'detail' => "le témoin a confirmé jusqu'à l'entrée $marque, qui est la dernière"];
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

    /**
     * L'empreinte que le journal retient d'un secret SU.
     *
     * ⚠️ **HMAC et non SHA-256 nu.** Le secret attendu n'est pas toujours un hash
     * Argon2id : `su_expected_secret()` accepte aussi une valeur en clair, et un
     * condensat non salé d'un secret mémorisé se casse hors ligne à coût nul par
     * essai. La clé du journal fait ici office de poivre — elle ne quitte pas la
     * machine, là où le journal, lui, part en sauvegarde hors site.
     */
    public static function empreinteDe(string $secret): string
    {
        return hash_hmac('sha256', $secret, self::secret());
    }

    /**
     * La dernière empreinte de secret SU que le journal ait vue poser, ou `null`
     * si aucune entrée n'en porte.
     *
     * 🔑 `null` ne veut pas dire « conforme ». Les entrées écrites avant que ce
     * champ existe n'en portent aucune, et un secret posé à la main n'a jamais
     * traversé le journal : dans les deux cas le journal ne peut rien affirmer,
     * et le dire est le seul verdict honnête. `verify()` contrôle la chaîne, pas
     * ce que la chaîne raconte — ce sont deux questions distinctes.
     *
     * @return array{empreinte: string, ts_paris: string, action: string}|null
     */
    public static function dernierSceau(): ?array
    {
        $vu = null;
        foreach (self::read() as $e) {
            $action = (string) ($e['action'] ?? '');
            if ($action !== self::ACTION_CHANGE_PASS && $action !== self::ACTION_RECORD_SEAL) {
                continue;
            }
            $h = $e['extra']['empreinte'] ?? null;
            if (is_string($h) && $h !== '') {
                $vu = [
                    'empreinte' => $h,
                    'ts_paris' => (string) ($e['ts_paris'] ?? '?'),
                    'action'   => $action,
                ];
            }
        }

        return $vu;
    }
}
