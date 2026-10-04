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
            //
            // 🔑 Aucune directive n'a été ouverte pour la KDF. Une implémentation
            // en WebAssembly aurait exigé `wasm-unsafe-eval` ; `argon2id.js` est
            // du JavaScript ordinaire, servi comme le reste.
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
    /** Longueur du jeton CSRF et de son masque, en octets (SHA-256 brut). */
    private const CSRF_OCTETS = 32;

    private static function csrfSecret(): string
    {
        return 'csrf|' . SecretInstance::lire('.serversecret', 48, SecretInstance::PLANCHER);
    }

    /** Token CSRF déterministe lié au token de session (pas de stockage requis). */
    /**
     * Jeton CSRF, MASQUE a chaque rendu.
     *
     * 🔑 Le jeton sous-jacent reste deterministe — derive de la session, donc
     * rien a stocker. Mais il sort masque par un alea different a chaque appel :
     * deux rendus de la meme page ne portent jamais les memes octets.
     *
     * Pourquoi : la compression HTTP est active, et une page qui porte un secret
     * ET reflete une entree choisie par un tiers laisse fuir ce secret par la
     * TAILLE des reponses compressees (BREACH, CVE-2013-3587). L'attaque a besoin
     * que le secret soit identique d'une requete a l'autre pour que les tailles
     * se correlent. Un masque par rendu supprime cette condition — sans toucher a
     * gzip, sans allonger les pages, et sans que le navigateur ait rien a faire.
     *
     * Le masque voyage avec le jeton : c'est sa raison d'etre, il n'est pas
     * secret. Ce qu'il protege, c'est la CORRELATION entre deux rendus.
     */
    public static function csrfToken(string $sessionToken): string
    {
        $jeton  = hash_hmac('sha256', $sessionToken, self::csrfSecret(), true);
        $masque = random_bytes(self::CSRF_OCTETS);

        return rtrim(strtr(base64_encode($masque . ($masque ^ $jeton)), '+/', '-_'), '=');
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
        // Le jeton arrive masque : <masque><jeton xor masque>, en base64url.
        // Un format inattendu se refuse sans rien dire de plus — un message
        // distinct par cause renseignerait qui tatonne.
        $brut = base64_decode(strtr($provided, '-_', '+/'), true);
        if ($brut === false || strlen($brut) !== 2 * self::CSRF_OCTETS) {
            return false;
        }
        $masque   = substr($brut, 0, self::CSRF_OCTETS);
        $demasque = $masque ^ substr($brut, self::CSRF_OCTETS);

        return hash_equals(hash_hmac('sha256', $sessionToken, self::csrfSecret(), true), $demasque);
    }
}
