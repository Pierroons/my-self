<?php
/**
 * MySelf-Lab — couche sécurité applicative.
 *
 * - Headers HTTP de sécurité (CSP, anti-clickjacking, anti-sniffing)
 * - Protection CSRF (token lié à la session, sans stockage : HMAC du token de session)
 * - Helpers de validation
 *
 * Le secret CSRF est dérivé de la blind key serveur (déjà hors webroot).
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

final class Security
{
    /** Envoie les headers de sécurité. À appeler en tête de chaque page/endpoint. */
    public static function sendHeaders(bool $htmlPage = true): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        // LAB-08 : cloisonnement cross-origin (défense en profondeur ; tout est same-origin ici).
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        if ($htmlPage) {
            // CSP stricte : pas de JS externe, pas d'inline sauf nos handlers.
            // 'unsafe-inline' toléré pour les onclick existants (MVP) — à durcir en nonce V2.
            header(
                "Content-Security-Policy: default-src 'self'; "
                . "script-src 'self' 'unsafe-inline'; "
                . "style-src 'self' 'unsafe-inline'; "
                . "img-src 'self' data:; "
                . "connect-src 'self'; "
                . "frame-ancestors 'none'; "
                . "base-uri 'self'; form-action 'self'"
            );
        }
        if (!empty($_SERVER['HTTPS'])) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /** Secret serveur pour signer les tokens CSRF (réutilise la blind key). */
    private static function csrfSecret(): string
    {
        $f = __DIR__ . '/../data/.blindkey';
        if (!file_exists($f)) {
            file_put_contents($f, bin2hex(random_bytes(48)));
            @chmod($f, 0600);
        }
        return 'csrf|' . trim((string) file_get_contents($f));
    }

    /** Token CSRF déterministe lié au token de session (pas de stockage requis). */
    public static function csrfToken(string $sessionToken): string
    {
        return hash_hmac('sha256', $sessionToken, self::csrfSecret());
    }

    /**
     * Vérifie le token CSRF. Source : header X-CSRF-Token (API JSON) ou champ
     * POST classique. On NE lit PAS php://input ici pour ne pas le consommer
     * avant l'endpoint (qui le relit via json_in()).
     */
    public static function verifyCsrf(?string $sessionToken): bool
    {
        if ($sessionToken === null || $sessionToken === '') {
            return false; // pas de session = pas d'action sensible
        }
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
        if (!is_string($provided) || $provided === '') {
            return false;
        }
        $expected = self::csrfToken($sessionToken);
        return hash_equals($expected, $provided);
    }
}
