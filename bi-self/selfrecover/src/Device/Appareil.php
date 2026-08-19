<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Device;

/**
 * Un appareil de confiance enrôlé sur un compte.
 *
 * Le serveur ne détient que la clé publique. La privée reste dans le navigateur,
 * chiffrée au repos par une clé dérivée du mot mémorisé — d'où la 2FA : rendre
 * une signature valide suppose l'appareil (le blob) et le mot (pour l'ouvrir).
 */
final class Appareil
{
    public function __construct(
        public readonly string $credentialId,
        /** Clé publique ECDSA P-256, SPKI DER encodé en base64url. */
        public readonly string $clePubliqueB64url,
        public readonly int $compteId,
        public readonly string $nomCompte,
    ) {
    }
}
