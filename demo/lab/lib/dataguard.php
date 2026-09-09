<?php
/**
 * MySelf-Lab — wrapper SelfDataGuard pour chiffrement at-rest des DM.
 *
 * Démontre la résistance à l'exfiltration de la base : le contenu des messages
 * privés est chiffré AES-256-GCM avec une clé dérivée d'un secret serveur
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
        return SecretInstance::lire('.blindkey', 48, 32);
    }

    /** Clé 32 bytes dérivée du blind key, contexte isolé "DM". */
    private static function dmKey(): string
    {
        return Primitives::deriveFromMemorized(self::blindKey(), '/my-self-lab/dm');
    }

    public static function encrypt(string $plaintext): string
    {
        return Primitives::aesGcmEncrypt($plaintext, self::dmKey())->toBase64();
    }

    public static function decrypt(string $b64): string
    {
        return Primitives::aesGcmDecrypt(EncryptedBlob::fromBase64($b64), self::dmKey());
    }

    /**
     * HMAC-SHA256 dérivé du blind key, contexte isolé. Sert à hasher des
     * identifiants (ex. IP pour rate-limit) sans jamais les stocker en clair.
     */
    public static function hmac(string $data, string $ctx): string
    {
        $key = Primitives::deriveFromMemorized(self::blindKey(), '/my-self-lab/hmac/' . $ctx);
        return hash_hmac('sha256', $data, $key);
    }
}
