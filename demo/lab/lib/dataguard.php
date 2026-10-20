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
        // Tout chemin qui chiffre, déchiffre ou calcule une empreinte passe par ce point,
        // y compris `hmac()`, qu'appellent trois routes anonymes. Ce qui dépend du secret
        // se déclare donc ici, une fois pour tous : les messages et profils chiffrés sous
        // lui, et les rapports red team, dont le jeton de suivi en dérive
        // (`Redteam::jetonSuivi()`) — une clé neuve les rendrait tous « invalides » sans
        // dire que c'est la clé qui a changé. Les empreintes d'adresse n'y figurent pas :
        // elles ne servent qu'au frein, et s'effacent au bout d'une heure.
        return SecretInstance::lire('.blindkey', 48, SecretInstance::PLANCHER, 'LAB_BLINDKEY_PATH', static fn (): ?string => Db::ceQuiDepend([
            'dm'              => 'message(s) privé(s) chiffré(s)',
            'profiles'        => 'profil(s) chiffré(s)',
            'redteam_reports' => 'rapport(s) red team, dont le jeton de suivi dérive de ce secret',
        ]));
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
     * Un blob sans `MARQUEUR_CONTEXTE` précède la liaison au contexte : il se lit
     * sans donnée associée **tant que la passe de migration n'a pas fini**, et
     * reste déplaçable jusque-là. Après, il est refusé : voir `temoinScelle()`.
     */
    public static function decrypt(string $b64, string $contexte): string
    {
        if (!str_starts_with($b64, self::MARQUEUR_CONTEXTE)) {
            if (self::lectureSansContexteFermee()) {
                throw new \RuntimeException(
                    'Blob sans contexte refusé : la passe de migration a scellé cette base.'
                );
            }

            return self::decryptSansContexte($b64);
        }

        $nu = substr($b64, strlen(self::MARQUEUR_CONTEXTE));

        return Primitives::decrypt(EncryptedBlob::fromBase64($nu), self::dmKey(), $contexte);
    }

    /**
     * Déchiffre un blob d'avant la liaison au contexte, sans rien exiger.
     *
     * Réservé à `tools/migrer-aad.php`, qui doit relire les blobs nus pour les
     * sceller — y compris ceux d'une sauvegarde restaurée après sa première passe,
     * quand `decrypt()` les refuse déjà.
     */
    public static function decryptSansContexte(string $b64): string
    {
        return Primitives::decrypt(EncryptedBlob::fromBase64($b64), self::dmKey());
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
        return SecretInstance::chemin('.blindkey', 'LAB_BLINDKEY_PATH') . '.scelle';
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
