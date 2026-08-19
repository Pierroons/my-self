<?php

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;
use Pierroons\SelfRecover\Device\Device as Protocole;

/**
 * Facteur de possession « cet appareil » — façade du lab sur la bibliothèque.
 *
 * 🔑 **Le protocole ne vit plus ici.** Enrôlement, défi et vérification de
 * signature sont dans `Pierroons\SelfRecover\Device\Device`, partagée avec les
 * autres consommateurs. Ce fichier ne garde que ce qui est propre au lab : la
 * forme des réponses attendue par `public/js/sr-device.js` et `recover.php`,
 * et les seuils de ce déploiement.
 *
 * Ce que ce déplacement ferme : le 02/08/2026, un correctif de prise de compte
 * a été posé ici et n'a atteint la démo publique que le 13/08. Onze jours,
 * parce que le même mécanisme vivait en deux exemplaires. Il n'en reste qu'un.
 */
final class Device
{
    /** Fenêtre de comptage des échecs par IP (15 min). */
    private const ENROLL_WINDOW = 900;
    /** Aligné sur Auth::LOGIN_MAX_FAILS_PER_IP — un foyer NAT partage son IP. */
    private const ENROLL_MAX_FAILS_PER_IP = 12;

    private static function protocole(PDO $pdo): Protocole
    {
        return new Protocole(
            new StockageSelfRecover($pdo),
            fenetreEchecs: self::ENROLL_WINDOW,
            maxEchecsIp: self::ENROLL_MAX_FAILS_PER_IP,
        );
    }

    /**
     * Enrôle un appareil pour un compte, contre preuve du mot mémorisé.
     *
     * ⚠️ Enrôler n'est pas récupérer. On enrôle une machine quand on est déjà
     * connecté ; qui a perdu son accès passe par L1, L2 ou L3.
     */
    public static function enroll(
        PDO $pdo,
        string $username,
        string $credentialId,
        string $publicKeyB64url,
        string $recoveryDerivedKey = '',
        ?string $ip = null
    ): array {
        return self::protocole($pdo)->enroler($username, $credentialId, $publicKeyB64url, $recoveryDerivedKey, $ip);
    }

    /** Émet un challenge de 32 octets pour la récupération « depuis cet appareil ». */
    public static function authBegin(PDO $pdo, string $credentialId): array
    {
        return self::protocole($pdo)->ouvrirDefi($credentialId);
    }

    /**
     * Vérifie la signature du challenge et rend un mot de passe neuf.
     *
     * La forme du retour est celle qu'attend `recover.php` : `credentials`
     * plutôt que `mot_de_passe`. La traduction reste ici, avec l'interface qui
     * la lit.
     */
    public static function authFinish(PDO $pdo, string $credentialId, string $challenge, string $signatureB64url): array
    {
        $r = self::protocole($pdo)->cloreDefi($credentialId, $challenge, $signatureB64url);

        if (!$r['ok']) {
            return ['ok' => false, 'message' => $r['message']];
        }

        return [
            'ok'          => true,
            'credentials' => ['password' => $r['mot_de_passe']],
            'note'        => 'Mot de passe réinitialisé (cet appareil + mot mémorisé). Copie-le maintenant.',
        ];
    }

    /** @deprecated Utiliser Pierroons\SelfRecover\Device\Device::estCleDerivee(). */
    public static function isDerivedKey(string $value): bool
    {
        return Protocole::estCleDerivee($value);
    }
}
