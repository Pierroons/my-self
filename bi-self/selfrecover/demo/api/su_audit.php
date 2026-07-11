<?php
/**
 * SelfRecover — Log SU « insupprimable » (audit forensique création/révocation admin).
 *
 * 4 couches : (1) append-only (chattr +a en prod) · (2) hash-chain · (3) HMAC ·
 * (4) externalisation ntfy. Source de vérité = fichier JSON-lines HORS DB/webroot ;
 * la DB n'en est jamais la source. Un is_admin=1 sans entrée ici = admin fantôme.
 */

function su_audit_log_path(): string {
    $env = getenv('SELFRECOVER_SU_AUDIT_LOG');
    if ($env) return $env;
    $dir = getenv('SELFRECOVER_STATE_DIR');
    if ($dir) return rtrim($dir, '/') . '/su-audit.log';
    return __DIR__ . '/../su-audit.log'; // fallback dev
}

function su_audit_secret(): string {
    // Prod : dérivée de la passphrase SU (label « su-audit », HKDF distinct de l'auth SU).
    // Dev : secret dédié en env.
    return getenv('SELFRECOVER_SU_AUDIT_SECRET') ?: 'dev-su-audit-secret-CHANGE-IN-PROD';
}

/** Contexte forensique max : « qui est derrière la co SU ». */
function su_forensic_context(): array {
    $ssh = getenv('SSH_CONNECTION') ?: '';          // "client_ip client_port server_ip server_port"
    $parts = $ssh !== '' ? explode(' ', $ssh) : [];
    $argv = $GLOBALS['argv'] ?? ($_SERVER['argv'] ?? []);
    return [
        'operator'      => getenv('SU_OPERATOR') ?: null,   // « qui » (propagé par le wrapper hôte : user@host + IP SSH)
        'unix_user'     => getenv('USER') ?: (getenv('LOGNAME') ?: '?'),
        'sudo_user'     => getenv('SUDO_USER') ?: null,
        'uid'           => function_exists('posix_getuid') ? posix_getuid() : (int)@getmyuid(),
        'gid'           => function_exists('posix_getgid') ? posix_getgid() : (int)@getmygid(),
        'ssh_client_ip' => $parts[0] ?? null,
        'ssh_raw'       => $ssh ?: null,
        'tty'           => (function_exists('posix_ttyname') && defined('STDIN')) ? (@posix_ttyname(STDIN) ?: null) : (getenv('SSH_TTY') ?: null),
        'pid'           => getmypid(),
        'ppid'          => function_exists('posix_getppid') ? posix_getppid() : null,
        'hostname'      => gethostname() ?: '?',
        'cmd'           => $argv ? implode(' ', $argv) : '?',
    ];
}

/** Lit toutes les entrées du log (array de décodés, dans l'ordre). */
function su_audit_read(): array {
    $path = su_audit_log_path();
    if (!file_exists($path)) return [];
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $d = json_decode($line, true);
        if ($d) $out[] = $d;
    }
    return $out;
}

/** Hash du coeur d'une entrée (hors entry_hash/hmac), chaîné au prev_hash. */
function su_audit_entry_hash(array $core, string $prevHash): string {
    unset($core['entry_hash'], $core['hmac']);
    return hash('sha256', json_encode($core, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . $prevHash);
}

/**
 * Ajoute une entrée au log (append-only + chaîne + HMAC + ntfy).
 * @param string $action  add-admin | revoke-admin | approve-request | reject-request | reset-shell | …
 * @param string $target  username concerné
 * @param array  $extra   ex: ['requester'=>…, 'request_id'=>…, 'su_note'=>…]
 * @return array l'entrée écrite.
 */
function su_audit_append(string $action, string $target, array $extra = []): array {
    $path = su_audit_log_path();
    $entries = su_audit_read();
    $prevHash = $entries ? ($entries[count($entries) - 1]['entry_hash'] ?? str_repeat('0', 64)) : str_repeat('0', 64);

    $now = new DateTime('now', new DateTimeZone('UTC'));
    $paris = (clone $now)->setTimezone(new DateTimeZone('Europe/Paris'));
    $core = [
        'seq'       => count($entries) + 1,
        'ts_utc'    => $now->format('Y-m-d\TH:i:s\Z'),
        'ts_paris'  => $paris->format('Y-m-d H:i:s'),
        'action'    => $action,
        'target'    => $target,
        'extra'     => $extra,
        'forensic'  => su_forensic_context(),
        'prev_hash' => $prevHash,
    ];
    $core['entry_hash'] = su_audit_entry_hash($core, $prevHash);
    $core['hmac']       = hash_hmac('sha256', $core['entry_hash'], su_audit_secret());

    $line = json_encode($core, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
        fwrite(STDERR, "⚠️  su-audit : échec écriture $path\n");
    }
    su_audit_ntfy($core);
    return $core;
}

/** Externalisation ntfy — MINIMALE (jamais le forensic en clair : action + cible + heure). */
function su_audit_ntfy(array $entry): void {
    $url = getenv('SELFRECOVER_NTFY_URL'); // ex https://alerts.my-self.fr/secu
    if (!$url) return;
    $msg = sprintf('[SU-AUDIT] %s : %s (seq %d · %s)', $entry['action'], $entry['target'], $entry['seq'], $entry['ts_paris']);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: text/plain\r\nTitle: SelfRecover SU\r\nPriority: high\r\nTags: warning,key",
        'content' => $msg,
        'timeout' => 4,
    ]]);
    @file_get_contents($url, false, $ctx);
}

/** Vérifie l'intégrité de la chaîne (prev_hash + entry_hash + HMAC de chaque entrée). */
function su_audit_verify(): array {
    $entries = su_audit_read();
    $prev = str_repeat('0', 64);
    foreach ($entries as $i => $e) {
        if (($e['prev_hash'] ?? null) !== $prev)
            return ['ok' => false, 'break_at' => $i + 1, 'reason' => 'chaîne rompue (prev_hash)'];
        $h = su_audit_entry_hash($e, $e['prev_hash']);
        if ($h !== ($e['entry_hash'] ?? ''))
            return ['ok' => false, 'break_at' => $i + 1, 'reason' => 'entrée altérée (entry_hash)'];
        if (!hash_equals(hash_hmac('sha256', $h, su_audit_secret()), (string)($e['hmac'] ?? '')))
            return ['ok' => false, 'break_at' => $i + 1, 'reason' => 'signature invalide (HMAC)'];
        $prev = $e['entry_hash'];
    }
    return ['ok' => true, 'count' => count($entries)];
}
