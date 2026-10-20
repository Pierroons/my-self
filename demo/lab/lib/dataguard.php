<?php
/**
 * MySelf-Lab — wrapper SelfDataGuard pour chiffrement at-rest des DM.
 *
 * Démontre la résistance à l'exfiltration de la base : le contenu des messages
 * privés est chiffré XChaCha20-Poly1305 avec une clé dérivée d'un secret serveur
 * (blind key) stocké HORS de la base et hors du webroot. Un dump SQL de la
 * table `dm` ne révèle que des blobs base64 illisibles.
 *
 * NB (V1) : chiffrement at-rest serveur, pas E2E inter-utilisateurs. Le E2E
 * (clés asymétriques par membre) est prévu en V2.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

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
        // 🔑 **La garde vit ICI, et elle porte sur « y a-t-il quelque chose à perdre ».**
        // Tout chemin qui chiffre, déchiffre ou calcule une empreinte passe par ce
        // point — y compris `hmac()`, appelé par trois routes anonymes (dépôt et suivi
        // des rapports, soumission de drapeau). `SecretInstance::lire()` POSE un secret
        // absent : c'est juste sur une instance neuve, et destructeur sur une base qui
        // porte déjà des chiffrés — la clé neuve les rendrait tous illisibles, et la
        // case « repartir d'un profil neuf » deviendrait l'outil de leur destruction.
        //
        // Posée sur les chemins de lecture seulement, la garde était désarmée par un
        // POST anonyme sur le chemin de REFUS d'une de ces routes. Posée ici sans
        // condition, elle empêchait une instance neuve de s'installer. La condition
        // juste se mesure sans la clé : un secret absent n'est une panne que si des
        // données chiffrées l'attendent.
        self::garderSecret();

        return SecretInstance::lire('.blindkey', 48, SecretInstance::PLANCHER, 'LAB_BLINDKEY_PATH');
    }

    /**
     * Exige que la clé soit disponible, et laisse remonter si elle ne l'est pas.
     *
     * 🔑 **À appeler par tout chemin qui déchiffre, dès qu'il a un blob en main.**
     * La garde elle-même vit dans `blindKey()`, et tous les chemins la traversent ;
     * cet appel sert à la faire tomber AVANT la boucle, hors du `catch` de chaque
     * ligne, qui sinon l'avalerait et l'afficherait comme un blob déplacé.
     * Un `catch` par ligne ne peut
     * pas distinguer « ce blob n'est pas à sa place » d'« il n'y a plus de clé » :
     * les deux sortent en `RuntimeException`. Une panne de secret habillée en blob
     * déplacé enverrait chercher un attaquant là où le serveur a perdu sa clé. La
     * dérivation étant mémoïsée, l'appel ne coûte qu'au premier.
     *
     * ⚠️ **Deux pannes sur trois.** Le secret absent et le secret trop court sont
     * refusés par `blindKey()`, donc sur tous les chemins et pas seulement ici. Un
     * secret **remplacé par un autre valide** n'est pas détectable : rien dans le
     * fichier ne dit qu'il n'est pas celui qui a chiffré, et le constater
     * demanderait un témoin que cette classe ne tient pas. Ce cas se reconnaît à ce
     * qu'il produit — toutes les lignes illisibles à la fois —, et `Dm::inbox()` le
     * journalise.
     */
    public static function exigerCle(): void
    {
        self::garderSecret();
        self::dmKey();
    }

    /**
     * Refuse un secret absent quand des données chiffrées l'attendent.
     *
     * Une seule condition, appelée de deux endroits : `blindKey()`, que tout chemin
     * traverse, et `exigerCle()`, qui doit la faire tomber même quand la clé dérivée
     * est déjà en mémoire — le cache de `cle()` ne rappelle pas `blindKey()`.
     */
    private static function garderSecret(): void
    {
        if (!SecretInstance::existe('.blindkey', 'LAB_BLINDKEY_PATH') && self::chiffresEnBase()) {
            throw new \RuntimeException(
                "Secret d'instance .blindkey absent alors que la base porte des données chiffrées : "
                . 'une clé neuve les rendrait toutes illisibles. Refus de servir — restaurer le secret.'
            );
        }
    }

    /** La base porte-t-elle au moins un blob ? Mesurable sans la clé, et c'est le but. */
    private static function chiffresEnBase(): bool
    {
        return (bool) Db::pdo()->query(
            'SELECT EXISTS (SELECT 1 FROM dm) OR EXISTS (SELECT 1 FROM profiles)'
        )->fetchColumn();
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
     * Le marqueur des blobs liés à un contexte.
     *
     * Il est en clair et ne dit rien du contenu : il dit sous quelle règle le blob
     * a été scellé. Le sort des blobs qui ne le portent pas se lit sur `decrypt()`,
     * qui en décide.
     */
    public const MARQUEUR_CONTEXTE = 'L2.';

    /**
     * Chiffre un texte en le liant au contexte qui a le droit de le relire.
     *
     * Le contexte devient la donnée associée du chiffré : la clé seule ne suffit
     * plus à le déchiffrer, il faut aussi présenter le même contexte. Un blob
     * déplacé — d'une conversation à une autre, d'un profil à un autre, d'une
     * table à une autre — présente alors un contexte différent du sien et le
     * déchiffrement échoue.
     *
     * 🔑 **Ce n'est pas la confidentialité de la base que cela protège** : une
     * exfiltration ne rendait déjà que des blobs, la clé vivant hors base. C'est
     * ce qui doit tenir quand l'ÉCRITURE a cédé. Sans contexte, une injection SQL
     * rend le clair des messages privés : il suffit de recopier un blob dans une
     * ligne qu'on a le droit de lire. Avec, elle ne rend que l'écriture.
     *
     * Le contexte se construit par `contexteDm()` ou `contexteProfil()`, jamais
     * à la main : deux appelants qui l'écriraient différemment produiraient des
     * blobs que l'autre ne sait pas relire.
     */
    public static function encrypt(string $plaintext, string $contexte): string
    {
        if ($contexte === '') {
            throw new \InvalidArgumentException('Un chiffré du lab se lie à un contexte : il ne peut pas être vide.');
        }

        return self::MARQUEUR_CONTEXTE . Primitives::encrypt($plaintext, self::dmKey(), $contexte)->toBase64();
    }

    /**
     * Déchiffre un blob, en exigeant le contexte sous lequel il a été scellé.
     *
     * Un blob sans `MARQUEUR_CONTEXTE` précède la liaison au contexte : il est
     * déchiffré sans donnée associée, et reste donc déplaçable jusqu'à ce que
     * `tools/migrer-aad.php` le réécrive.
     */
    public static function decrypt(string $b64, string $contexte): string
    {
        if (!str_starts_with($b64, self::MARQUEUR_CONTEXTE)) {
            return Primitives::decrypt(EncryptedBlob::fromBase64($b64), self::dmKey());
        }

        $nu = substr($b64, strlen(self::MARQUEUR_CONTEXTE));

        return Primitives::decrypt(EncryptedBlob::fromBase64($nu), self::dmKey(), $contexte);
    }

    /**
     * Le contexte d'un message privé : la paire qui a le droit de le lire.
     *
     * Les deux identifiants sont ordonnés par l'expéditeur puis le destinataire,
     * et non triés : un message d'Alice à Bob n'est pas celui de Bob à Alice, et
     * les deux ne doivent pas partager un contexte.
     */
    public static function contexteDm(int $expediteur, int $destinataire): string
    {
        return 'dm:' . $expediteur . ':' . $destinataire;
    }

    /** Le contexte d'un profil : le compte dont il décrit le titulaire. */
    public static function contexteProfil(int $compte): string
    {
        return 'profil:' . $compte;
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
