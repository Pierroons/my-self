<?php
/**
 * MySelf-Lab — couche sécurité applicative.
 *
 * - Headers HTTP de sécurité (CSP, anti-clickjacking, anti-sniffing)
 * - Protection CSRF (token lié à la session, sans stockage : HMAC du token de session)
 * - Helpers de validation
 *
 * Le secret CSRF est un secret d'instance à lui seul (`.serversecret`, hors webroot).
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

require_once __DIR__ . '/secret_instance.php';

final class Security
{
    private static ?string $nonce = null;

    /** Nonce CSP unique par requête : autorise nos <script> légitimes sans 'unsafe-inline'. */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

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
        // Pages authentifiées : jamais de cache. Sans cela le navigateur peut
        // resservir depuis son historique une page rendue pour une session
        // ouverte — profil, messages, ou un mémo affiché déchiffré. Rien n'est
        // stocké par l'application ; c'est le navigateur qui conserve.
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        if ($htmlPage) {
            // CSP stricte : JS uniquement depuis nos <script nonce>. Pas d'inline non signé,
            // pas de handlers onclick (tous externalisés en addEventListener). LAB-06.
            header(
                "Content-Security-Policy: default-src 'self'; "
                . "script-src 'self' 'nonce-" . self::nonce() . "'; "
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

    /**
     * Secret serveur pour signer les tokens CSRF.
     *
     * ⚠️ **Ce secret était le seul du lab dont l'absence ne cassait rien**, et c'est
     * ce qui le rendait dangereux : un sel de site vide fait échouer la recherche des
     * codes, une clé de coffre vide fait lever la dérivation, mais `hash_hmac` accepte
     * n'importe quelle clé — y compris la chaîne vide. L'application continuait donc
     * de servir des jetons que quiconque lit le dépôt pouvait recalculer. Aucun garde
     * ne pouvait vivre en aval : il est dans `SecretInstance`, qui lève plutôt que de
     * rendre un secret court.
     *
     * Il ne partage plus `.blindkey` avec le chiffrement des coffres : un même
     * secret pour signer et pour chiffrer mélange deux contextes, et rien
     * n'obligeait à le faire.
     */
    private static function csrfSecret(): string
    {
        return 'csrf|' . SecretInstance::lire('.serversecret', 48, 32);
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
