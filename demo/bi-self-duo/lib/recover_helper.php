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
// L'adaptateur du duo, dont `selDeDerivation` a besoin pour monter le protocole.
// Il vit hors autoload : la bibliothèque ne connaît que ses propres classes.
require_once __DIR__ . '/StockageSelfRecover.php';

use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Device\Device as Protocole;
use Pierroons\SelfRecover\Recovery\Recovery;

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

    /** Le mot mémorisé doit arriver dérivé — cf. la bibliothèque. */
    public static function isDerivedKey(string $value): bool {
        return Protocole::estCleDerivee($value);
    }

    /**
     * L'index de recherche d'un code de secours.
     *
     * 🔑 Délégué à la bibliothèque, qui normalise avant de hacher. Cette démo
     * calculait le même HMAC à la main, sans la normalisation : les deux
     * concordaient parce que les codes sont engendrés en minuscules, donc par
     * accident. Le jour où un code arrive autrement, l'index posé et l'index
     * cherché cessent de se répondre, et la récupération échoue sans qu'aucune
     * erreur ne le dise.
     */
    public static function indexCode(DemoSession $session, string $code): string {
        return self::protocole($session)->indexRecherche($code);
    }

    /**
     * Le sel de dérivation d'un compte, trouvé par l'un de ses codes de secours.
     *
     * 🔑 **NE DOIT PAS DEVENIR UN ORACLE.** Répondre pour un code valide et
     * refuser pour un code inconnu permettrait d'énumérer les codes sans payer
     * un seul Argon2id — donc de contourner le coût sur lequel repose tout le
     * niveau 2. On rend **TOUJOURS** un sel : pour un code inconnu, un sel
     * fabriqué, déterministe, de même longueur, et stable si le code est
     * retenté.
     *
     * Deux raffinements repris des deux implémentations qui ont écrit ce patron
     * en premier : la normalisation est celle de la vérification — sinon un code
     * en majuscules obtiendrait un faux sel ici et serait accepté là-bas — et
     * le `WHERE` ne filtre pas les codes consommés, sans quoi la route dirait
     * « ce compte vient d'être récupéré ».
     *
     * ⚠️ **Dans CETTE démo, la garde est démonstrative, pas effective**, et il
     * faut le dire à qui copierait ce code. Le faux sel repose sur
     * `siteSalt()`, dont l'open book publie la formule
     * (`hash('sha256', 'demo-salt|' . $session->id)`) et dont l'ingrédient —
     * l'identifiant de session — est dans le cookie du visiteur. Il peut donc
     * recalculer ses faux sels et les distinguer des vrais.
     *
     * Ce qui rend la chose sans conséquence n'est pas la garde : c'est que
     * chaque visiteur est seul dans sa base SQLite, pour trente minutes. Il
     * n'énumérerait que ses propres codes. En déploiement réel, le sel de
     * déploiement est un secret serveur, et alors seulement la garde mord.
     *
     * ⚠️ **Et la porte d'à côté reste ouverte** : `api/recover/register.php`
     * répond `409 username_taken` sur un identifiant pris. La question que
     * cette route refuse de trancher, l'inscription y répond gratuitement. Ici
     * c'est assumé — la démo veut montrer un conflit d'identifiant — mais qui
     * copie cette garde doit se demander ce que ses AUTRES routes disent du
     * même compte. Un garde-fou branché ne vaut que par ce qui l'entoure.
     */
    public static function selDeDerivation(DemoSession $session, string $code, string $username = ''): string {
        $code     = strtolower(trim($code));
        $username = strtolower(trim($username));

        // Par identifiant : l'étape qui démontre la dérivation en désigne un, et
        // n'a pas de code sous la main. Même garde — un identifiant inconnu rend
        // un sel fabriqué, jamais une erreur, sinon cette route dirait qui existe.
        if ($code === '' && preg_match('/^[a-z0-9]{3,20}$/', $username)) {
            $stmt = $session->db()->prepare('SELECT recovery_salt FROM accounts WHERE username = :u');
            $stmt->bindValue(':u', $username);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if (is_array($row) && ($row['recovery_salt'] ?? '') !== '') {
                return (string) $row['recovery_salt'];
            }
        }

        if (preg_match('/^[a-f0-9]{5}-[a-f0-9]{5}$/', $code)) {
            $stmt = $session->db()->prepare(
                'SELECT a.recovery_salt FROM recovery_codes c
                   JOIN accounts a ON a.id = c.account_id
                  WHERE c.code_lookup = :l'
            );
            $stmt->bindValue(':l', self::indexCode($session, $code));
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if (is_array($row) && ($row['recovery_salt'] ?? '') !== '') {
                return (string) $row['recovery_salt'];
            }
        }

        return substr(hash_hmac('sha256', 'sel-absent:' . $code . '|' . $username, self::siteSalt($session)), 0, 32);
    }

    /** Le protocole monté sur la base de cette session de démo. */
    private static function protocole(DemoSession $session): Recovery {
        return new Recovery(new StockageSelfRecover($session->db()), self::siteSalt($session));
    }

    /**
     * Le sel de déploiement de cette session de démo.
     *
     * Il ne sert plus à la dérivation du mot mémorisé — c'est le rôle du sel
     * par compte, engendré par le navigateur. Il lui reste deux usages, tous
     * deux internes au serveur : indexer les codes de secours
     * (`Recovery::indexRecherche`) et fabriquer le faux sel rendu aux codes
     * inconnus (`selDeDerivation`). À ce titre il ne sort plus, alors que
     * cette démo l'exposait par une route dédiée.
     *
     * Dérivé de l'UUID de session : stable pendant les trente minutes qu'elle
     * vit, et différent d'un visiteur à l'autre — ce qui montre au passage que
     * chaque déploiement aurait le sien.
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
