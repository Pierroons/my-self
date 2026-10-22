<?php
/**
 * MySelf-Lab — wrapper SelfDataGuard pour chiffrement at-rest des DM et des profils.
 *
 * Démontre la résistance à l'exfiltration de la base : le contenu des messages
 * privés et des profils est chiffré XChaCha20-Poly1305 avec une clé dérivée d'un
 * secret serveur (blind key) stocké HORS de la base et hors du webroot. Un dump SQL
 * ne rend pas ce contenu ; il rend en clair ce qui l'entoure — qui écrit à qui,
 * quand, si c'est lu — et la longueur de chaque message, que rien ne bourre.
 *
 * NB (V1) : chiffrement at-rest serveur, pas E2E inter-utilisateurs. Le E2E
 * (clés asymétriques par membre) est prévu en V2.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;
use Pierroons\SelfDataGuard\Crypto\Aad;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;

require_once __DIR__ . '/secret_instance.php';
require_once __DIR__ . '/db.php';

final class DataGuard
{
    /**
     * Secret serveur ≥32 bytes, généré une fois, hors DB + hors webroot.
     *
     * La longueur annoncée est maintenant exigée : `SecretInstance` refuse de
     * rendre un secret plus court. Avant, l'échec d'écriture rendait la chaîne
     * vide, et c'est `Primitives::deriveFromMemorized` qui levait — la garantie
     * tenait par la bibliothèque, pas par cette ligne.
     */
    private static function blindKey(): string
    {
        // Tout chemin qui chiffre, déchiffre ou calcule une empreinte passe par ce point,
        // y compris `hmac()`, qu'appellent trois routes anonymes. Ce qui dépend du secret
        // se déclare donc ici, une fois pour tous : les messages et profils chiffrés sous
        // lui, et les rapports red team, dont le jeton de suivi en dérive
        // (`Redteam::jetonSuivi()`) — une clé neuve les rendrait tous « invalides » sans
        // dire que c'est la clé qui a changé. Les empreintes d'adresse n'y figurent pas :
        // elles ne servent qu'au frein d'une heure, et s'effacent à la soumission suivante.
        $nee = !is_file(self::fichiersDeLaCle()[0]);
        $cle = SecretInstance::lire('.blindkey', 48, SecretInstance::PLANCHER, 'LAB_BLINDKEY_PATH', static fn (): ?string => self::ceQuiDependDeLaCle());
        if ($nee && !self::lectureSansContexteFermee()) {
            // Une clé qui naît ici n'a jamais chiffré sans contexte : la bibliothèque n'écrit
            // plus que des blobs liés. Sans ce geste, une instance née sous la 0.7.0 gardait la
            // lecture sans contexte ouverte pour toujours — aucune passe n'ayant de blob nu à
            // relier, aucune ne posait le témoin (mesuré par la neuvième passe d'audit).
            try {
                self::fermerLectureSansContexte();
            } catch (\RuntimeException $e) {
                error_log($e->getMessage());
            }
        }

        return $cle;
    }

    /** Ce que le déploiement a déjà bâti sur la clé, en une phrase, ou `null`. */
    private static function ceQuiDependDeLaCle(): ?string
    {
        return Db::ceQuiDepend([
            'dm'              => 'message(s) privé(s) chiffré(s)',
            'profiles'        => 'profil(s) chiffré(s)',
            'redteam_reports' => 'rapport(s) red team, dont le jeton de suivi dérive de ce secret',
        ]);
    }

    /**
     * La clé que voit ce processus est-elle celle qui a scellé la base ? `null` si oui, ou si
     * rien n'en dépend ; sinon la raison, en une phrase.
     *
     * 🔑 `reset-db` fige la clé qu'il voit. Une console qui ne portait pas la prise du site
     * la cherchait ailleurs, ne la trouvait pas, et répondait « rien n'avait été chiffré »
     * sur une base qui portait des messages : la clé du site restait, et ce qu'elle avait
     * scellé se rouvrait dans la base suivante (mesuré par la neuvième passe d'audit). La
     * question se pose donc à la base — ce qui dépend de la clé, et un chiffré qui s'ouvre
     * sous elle —, pas au chemin. Une base qui ne porte que des rapports n'a aucun chiffré
     * à ouvrir : la clé y est seulement exigée présente.
     */
    public static function cleDeCetteBase(PDO $pdo): ?string
    {
        $enDepend = self::ceQuiDependDeLaCle();
        if ($enDepend === null) {
            return null;
        }
        $cle = self::fichiersDeLaCle()[0];
        if (!is_file($cle)) {
            return "la base porte {$enDepend}, et aucune clé n'est à {$cle} (LAB_BLINDKEY_PATH)";
        }
        // Une clé illisible ou trop courte se dit telle : avalée par l'essai des chiffrés,
        // elle passait pour une clé étrangère.
        try {
            self::exigerCle();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        $essais = $pdo->query(
            'SELECT * FROM (SELECT ciphertext, sender_id, recipient_id, NULL AS account_id FROM dm LIMIT 20)
             UNION ALL
             SELECT * FROM (SELECT ciphertext, NULL, NULL, account_id FROM profiles LIMIT 20)'
        )->fetchAll(PDO::FETCH_ASSOC);
        // Sous une lecture fermée, un blob nu ne s'ouvre sous aucune clé : il ne dit rien d'elle.
        $fermee = self::lectureSansContexteFermee();
        $concluants = 0;
        foreach ($essais as $ligne) {
            $blob = (string) $ligne['ciphertext'];
            if ($fermee && !self::estLie($blob)) {
                continue;
            }
            $concluants++;
            try {
                if (!self::estLie($blob)) {
                    self::decryptSansContexte($blob);
                } elseif ($ligne['account_id'] !== null) {
                    self::decrypt($blob, self::contexteProfil((int) $ligne['account_id']));
                } else {
                    self::decrypt($blob, self::contexteDm((int) $ligne['sender_id'], (int) $ligne['recipient_id']));
                }

                return null;
            } catch (\Throwable) {
                continue;
            }
        }

        if ($essais === []) {
            return null;
        }

        return $concluants === 0
            ? sprintf(
                'les %d chiffrés essayés datent d\'avant la passe de migration, et la lecture sans contexte est fermée '
                . '(%s) : aucun ne peut confirmer la clé de %s',
                count($essais),
                self::temoinScelle(),
                $cle
            )
            : sprintf(
                'aucun des %d chiffrés essayés de la base ne s\'ouvre sous la clé de %s : ce n\'est pas celle qui l\'a '
                . 'scellée, le numéro de la base a changé, ou ces chiffrés sont abîmés',
                $concluants,
                $cle
            );
    }

    /**
     * Exige que la clé soit disponible, et laisse remonter si elle ne l'est pas.
     *
     * 🔑 **À appeler par tout chemin qui déchiffre, dès qu'il a un blob en main.**
     * La garde elle-même vit dans `SecretInstance::lire()`, que tous les chemins
     * traversent ; cet appel sert à la faire tomber AVANT la boucle, hors du `catch` de chaque
     * ligne, qui sinon l'avalerait et l'afficherait comme un blob déplacé.
     * Un `catch` par ligne ne peut
     * pas distinguer « ce blob n'est pas à sa place » d'« il n'y a plus de clé » :
     * les deux sortent en `RuntimeException`. Une panne de secret habillée en blob
     * déplacé enverrait chercher un attaquant là où le serveur a perdu sa clé. La
     * dérivation étant mémoïsée, l'appel ne coûte qu'au premier.
     *
     * ⚠️ **Deux pannes sur trois.** Le secret absent et le secret trop court sont
     * refusés par `SecretInstance::lire()`, donc sur tous les chemins et pas seulement ici. Un
     * secret **remplacé par un autre valide** n'est pas détectable : rien dans le
     * fichier ne dit qu'il n'est pas celui qui a chiffré, et le constater
     * demanderait un témoin que cette classe ne tient pas. Ce cas se reconnaît à ce
     * qu'il produit — toutes les lignes illisibles à la fois —, et `Dm::inbox()` le
     * journalise.
     */
    public static function exigerCle(): void
    {
        // `blindKey()` d'abord, et non le seul `dmKey()` : la clé dérivée est mémoïsée,
        // et un secret retiré pendant la vie du processus ne se verrait plus.
        self::blindKey();
        self::dmKey();
    }

    /** @var array<string, string> clés dérivées, une par contexte, le temps de la requête */
    private static array $cles = [];

    /**
     * Clé 32 bytes dérivée du blind key pour un contexte. `deriveFromMemorized`
     * coûte un Argon2id de 64 Mio : sans ce mémo, lister N messages en payait N.
     */
    private static function cle(string $contexte): string
    {
        return self::$cles[$contexte] ??= Primitives::deriveFromMemorized(self::blindKey(), $contexte);
    }

    /** Clé du contexte isolé "DM". */
    private static function dmKey(): string
    {
        return self::cle('/my-self-lab/dm');
    }

    /**
     * Chiffre un texte en le liant au contexte qui a le droit de le relire.
     *
     * Le contexte devient la donnée associée du chiffré : la clé seule ne suffit
     * plus à le déchiffrer, il faut aussi présenter le même contexte. Un blob
     * déplacé — d'une conversation à une autre, d'un profil à un autre, d'une
     * table à une autre — présente alors un contexte différent du sien et le
     * déchiffrement échoue. Le format v3 de SelfDataGuard porte la liaison : c'est
     * la bibliothèque, et non ce wrapper, qui dit d'un blob qu'il est lié
     * (`EncryptedBlob::bindsItsContext()`).
     *
     * 🔑 **Ce que cela protège, et ce que cela ne protège pas.** Une exfiltration ne
     * rendait déjà que des blobs, la clé vivant hors base. Ce qui change, c'est une
     * écriture qui porte sur les contenus : recopier un blob dans une ligne qu'on a
     * le droit de lire ne l'ouvre plus. ⚠️ Une écriture SQL générale reste une prise
     * de compte — elle fabrique une session ou un hachage de mot de passe, et lit
     * alors comme le destinataire, sans rien déplacer. L'AAD ferme le déplacement,
     * pas l'usurpation.
     *
     * Le contexte se construit par `contexteDm()` ou `contexteProfil()`, jamais
     * à la main : deux appelants qui l'écriraient différemment produiraient des
     * blobs que l'autre ne sait pas relire.
     */
    public static function encrypt(string $plaintext, Aad $contexte): string
    {
        if (!$contexte->isBound()) {
            throw new \InvalidArgumentException('Un chiffré du lab se lie à un contexte : Aad::none() est refusé ici.');
        }

        return Primitives::encrypt($plaintext, self::dmKey(), $contexte)->toBase64();
    }

    /**
     * Déchiffre un blob, en exigeant le contexte sous lequel il a été scellé.
     *
     * Un blob qui ne porte pas sa liaison (formats v1 et v2, d'avant la 0.7.0 de
     * SelfDataGuard) se lit sans contexte **tant que la passe de migration n'a pas
     * fini**, et reste déplaçable jusque-là. Après, il est refusé : voir `temoinScelle()`.
     */
    public static function decrypt(string $b64, Aad $contexte): string
    {
        $blob = EncryptedBlob::fromBase64($b64);
        if ($blob->bindsItsContext()) {
            return Primitives::decrypt($blob, self::dmKey(), $contexte);
        }
        if (self::lectureSansContexteFermee()) {
            throw new \RuntimeException(
                'Blob sans contexte refusé : la passe de migration a scellé cette base.'
            );
        }

        return Primitives::decrypt($blob, self::dmKey(), Aad::legacy(''));
    }

    /**
     * Ce blob porte-t-il sa liaison ? Un blob mal formé ne la porte pas.
     *
     * La seule question que la passe de migration pose pour savoir quoi relier :
     * la réponse vient du format, donc de la bibliothèque.
     */
    public static function estLie(string $b64): bool
    {
        try {
            return EncryptedBlob::fromBase64($b64)->bindsItsContext();
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Déchiffre un blob d'avant la liaison au contexte, sans rien exiger.
     *
     * Réservé à `tools/migrer-aad.php`, qui doit relire les blobs nus pour les
     * sceller — y compris ceux d'une sauvegarde restaurée après sa première passe,
     * quand `decrypt()` les refuse déjà. Le lab les écrivait sans donnée associée :
     * `Aad::legacy('')` reproduit exactement ces octets.
     */
    public static function decryptSansContexte(string $b64): string
    {
        return Primitives::decrypt(EncryptedBlob::fromBase64($b64), self::dmKey(), Aad::legacy(''));
    }

    /**
     * Le témoin que la passe de migration a scellé tous les blobs nus.
     *
     * 🔑 **Tant qu'il manque, un blob nu se lit sans contexte** — c'est ce qui laisse
     * une base antérieure servir jusqu'à sa passe. Une fois posé, plus aucun : sans
     * lui, un blob nu gardé d'avant la passe (un dump, une archive de sauvegarde) se
     * replanterait dans n'importe quelle ligne et rendrait son clair, et la passe
     * suivante le scellerait là comme s'il y était né.
     *
     * Il vit à côté du secret, et non dans la base, pour la raison même du secret :
     * l'adversaire que l'AAD vise écrit dans la base, et y effacer le témoin
     * rouvrirait la lecture. Une base restaurée d'avant la passe se relit en
     * relançant la passe, qui scelle ce qu'elle trouve.
     */
    private static function temoinScelle(): string
    {
        return self::fichiersDeLaCle()[2];
    }

    /**
     * Les fichiers qui font la clé de cette base : le secret et ses deux témoins.
     *
     * `reset-db` les fige avec la base : une clé qui survivait à sa base laissait qui
     * détient un dump de l'ancienne, et une écriture dans la nouvelle, y recopier le
     * numéro de l'ancienne avec ses blobs — et les rouvrir (mesuré par la huitième
     * passe d'audit). Les témoins partent avec elle : ils disent un fait sur cette
     * clé-là, et `.existe` laissé en place ferait refuser la clé neuve que la base
     * neuve doit recevoir.
     *
     * @return list<string>
     */
    public static function fichiersDeLaCle(): array
    {
        $cle = SecretInstance::chemin('.blindkey', 'LAB_BLINDKEY_PATH');

        return [$cle, $cle . SecretInstance::A_EXISTE, $cle . '.scelle'];
    }

    public static function lectureSansContexteFermee(): bool
    {
        return is_file(self::temoinScelle());
    }

    /** Pose le témoin. Appelé par la passe de migration quand il ne reste aucun blob nu. */
    public static function fermerLectureSansContexte(): void
    {
        $f = self::temoinScelle();
        if (is_file($f)) {
            return;
        }
        if (@file_put_contents($f, gmdate('c') . "\n") === false || !@chmod($f, 0600)) {
            throw new \RuntimeException(
                "Témoin de scellement non posable ({$f}) : la lecture sans contexte reste ouverte."
            );
        }
    }

    /**
     * Le contexte d'un message privé : la paire qui a le droit de le lire.
     *
     * Les deux identifiants sont ordonnés par l'expéditeur puis le destinataire,
     * et non triés : un message d'Alice à Bob n'est pas celui de Bob à Alice, et
     * les deux ne doivent pas partager un contexte. Le numéro de la base les précède :
     * un identifiant de compte n'est unique que dans une base (`Db::generation()`).
     */
    public static function contexteDm(int $expediteur, int $destinataire): Aad
    {
        return Aad::of('dm', Db::generation(), (string) $expediteur, (string) $destinataire);
    }

    /** Le contexte d'un profil : le compte dont il décrit le titulaire, dans cette base. */
    public static function contexteProfil(int $compte): Aad
    {
        return Aad::of('profil', Db::generation(), (string) $compte);
    }

    /**
     * HMAC-SHA256 dérivé du blind key, contexte isolé. Sert à hasher des
     * identifiants (ex. IP pour rate-limit) sans jamais les stocker en clair.
     */
    public static function hmac(string $data, string $ctx): string
    {
        return hash_hmac('sha256', $data, self::cle('/my-self-lab/hmac/' . $ctx));
    }
}
