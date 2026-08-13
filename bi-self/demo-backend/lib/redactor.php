<?php
/**
 * Bi-Self Demo — Redactor.
 *
 * Supprime les secrets du serveur avant d'envoyer un log au frontend.
 * Utilisé par Logger (logs visibles) et par l'endpoint /code (source PHP visible).
 */

declare(strict_types=1);

final class Redactor {
    /**
     * Paths absolus à masquer (remplacés par {xxx}).
     */
    private const PATH_MAP = [
        '#/var/lib/selfjustice/demo-sessions/[a-f0-9-]+/#i' => '{session_dir}/',
        '#/var/lib/selfjustice/admin/#i'                     => '{admin_dir}/',
        '#/var/lib/selfjustice/#i'                           => '{state_dir}/',
        '#/var/www/bi-self/api/demo/#i'                      => '{demo_api}/',
        '#/var/www/bi-self/#i'                               => '{document_root}/',
    ];

    /**
     * Patterns de secrets inline à censurer dans le code ou les logs.
     */
    private const SECRET_PATTERNS = [
        '#\$site_salt\s*=\s*[^;]+;#'                 => '$site_salt = [REDACTED — set at install];',
        '#\$bypass_token\s*=\s*[^;]+;#'              => '$bypass_token = [REDACTED];',
        '#/var/lib/selfjustice/admin/token\.txt#'    => '{admin_dir}/token.txt',
        '#/var/lib/selfjustice/admin/bypass_token#'  => '{admin_dir}/bypass_token',
    ];

    public static function redactLog(string $msg): string {
        foreach (self::PATH_MAP as $re => $repl) {
            $msg = preg_replace($re, $repl, $msg) ?? $msg;
        }
        foreach (self::SECRET_PATTERNS as $re => $repl) {
            $msg = preg_replace($re, $repl, $msg) ?? $msg;
        }
        return $msg;
    }

    public static function redactSource(string $code): string {
        foreach (self::SECRET_PATTERNS as $re => $repl) {
            $code = preg_replace($re, $repl, $code) ?? $code;
        }
        foreach (self::PATH_MAP as $re => $repl) {
            $code = preg_replace($re, $repl, $code) ?? $code;
        }
        return $code;
    }

    /**
     * Pour les valeurs de contexte JSON : tronque les strings longues,
     * masque les potentiels tokens.
     */
    public static function redactCtx(array $ctx): array {
        $out = [];
        foreach ($ctx as $k => $v) {
            if (is_string($v)) {
                // Tronque les chaînes hex longues (bcrypt, HMAC) après 16 chars
                if (preg_match('/^[a-f0-9]{32,}$/i', $v)) {
                    $out[$k] = substr($v, 0, 16) . '…truncated';
                } elseif (strlen($v) > 200) {
                    $out[$k] = substr($v, 0, 200) . '…';
                } else {
                    $out[$k] = self::redactLog($v);
                }
            } elseif (is_array($v)) {
                $out[$k] = self::redactCtx($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
