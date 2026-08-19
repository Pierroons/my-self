<?php
/**
 * SelfRecover demo — helpers (session app, password gen, etc.).
 */

declare(strict_types=1);

require_once __DIR__ . '/session_manager.php';
// La bibliothèque n'est pas installée par Composer ici : son autoload suffit,
// et il évite d'imposer une étape d'installation à une démo qu'on déploie par
// simple copie.
require_once __DIR__ . '/../../../bi-self/selfrecover/src/autoload.php';
require_once __DIR__ . '/diceware/wordlist.php';

use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Device\Device as Protocole;

final class RecoverHelper {
    /**
     * Profil Argon2id du projet.
     *
     * 🔑 Sa définition vit dans `Pierroons\SelfRecover\Crypto\Hashing`, une
     * seule fois pour tous les consommateurs. Le recopier ici le ferait diverger
     * au premier ajustement sans qu'aucune erreur ne survienne : c'est ce qui a
     * laissé cette démo soixante-dix jours derrière le lab, du 08/06 au 17/08/2026.
     */
    public const ARGON2 = Hashing::ARGON2;

    /**
     * Hache un secret destiné à être stocké.
     *
     * ⚠️ Le coût mémoire est le paramètre qui compte : 64 Mo à mobiliser par
     * hachage, ce qui rend l'attaque par GPU coûteuse à paralléliser.
     */
    public static function hash(string $secret): string {
        return Hashing::hash($secret);
    }

    /**
     * Hash Argon2id factice, à vérifier quand le compte ou le code n'existe pas.
     *
     * 🔑 Sans lui, le chemin « identifiant inconnu » ne calcule rien et répond
     * plus vite — ou, quand un délai fixe le compense, plus lentement mais de
     * façon parfaitement régulière. Dans les deux cas le temps de réponse
     * distingue ce que le message refuse de dire, et trier des comptes existants
     * ne demande qu'une requête et un chronomètre.
     *
     * ⚠️ Il DOIT porter les mêmes paramètres que les vrais hash : c'est leur coût
     * qu'on imite, pas leur existence. Un profil plus faible se verrait au temps.
     * Aucun secret réel ne correspond à cette empreinte, et aucune vérification
     * contre elle ne doit réussir — elle n'est là que pour brûler le même temps.
     */

    public static function dummyHash(): string {
        return Hashing::dummyHash();
    }

    /**
     * Génère un password random (16 chars alphanum + quelques symboles mémorisables).
     */
    /**
     * Mot de passe temporaire rendu à l'utilisateur après une récupération.
     *
     * 🔑 Délégué à la bibliothèque, dont l'alphabet exclut `l 1 I O 0`. Ce mot
     * est fait pour être recopié depuis un écran : une ambiguïté de glyphe y
     * coûte plus cher que les 3,8 bits qu'elle fait gagner — 93 bits restent
     * hors de portée, un `1` pris pour un `l` bloque l'utilisateur tout de suite.
     */
    public static function generatePassword(int $length = 16): string {
        return Protocole::engendrerMotDePasse($length);
    }

    /**
     * Calcule derived_key = HMAC-SHA256(recovery_word, domain || site_salt).
     *
     * Dans le vrai protocole SelfRecover le HMAC est calculé côté client (JS).
     * Pour la démo on le fait aussi côté serveur pour la simulation du flux
     * inscription (où le serveur génère tout pour pédagogie). Pour la récupération
     * L2 (session 4), le frontend calculera le HMAC lui-même et n'enverra que le
     * derived_key au serveur, comme dans le vrai protocole.
     */
    public static function deriveKey(string $recoveryWord, string $domain, string $siteSalt): string {
        // Protocole SelfRecover : key = recovery_word, message = domain || site_salt
        // PHP hash_hmac signature: hash_hmac($algo, $data, $key)
        // Donc $data = message = domain||salt ; $key = recovery_word
        return hash_hmac('sha256', $domain . $siteSalt, $recoveryWord);
    }

    /**
     * Site salt de la session démo. Pour la prod, 32 bytes random persistants.
     * Ici, dérivé de l'UUID de session pour qu'il soit stable dans la durée
     * de la session et différent entre sessions (montre que chaque déploiement
     * aurait son propre salt).
     */
    public static function siteSalt(DemoSession $session): string {
        return hash('sha256', 'demo-salt|' . $session->id);
    }

    public static function generateSessionToken(): string {
        return bin2hex(random_bytes(24));
    }

    public static function getLoggedAccount(DemoSession $session): ?array {
        $token = $_COOKIE['sr_app_session'] ?? '';
        if ($token === '' || !preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $db = $session->db();
        $stmt = $db->prepare('
            SELECT a.id, a.username
              FROM accounts a
              JOIN app_sessions s ON s.account_id = a.id
             WHERE s.token = :token
        ');
        $stmt->bindValue(':token', $token);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function setAppSessionCookie(string $token, int $lifetimeSeconds = 1800): void {
        setcookie('sr_app_session', $token, [
            'expires'  => time() + $lifetimeSeconds,
            'path'     => '/',
            'domain'   => 'bi-self.my-self.fr',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function clearAppSessionCookie(): void {
        setcookie('sr_app_session', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => 'bi-self.my-self.fr',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
