<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Recovery;

/**
 * Un dossier de niveau 3, tel que le stockage le rend.
 *
 * Objet de valeur plutôt que tableau parce qu'il TRAVERSE : le même dossier est
 * lu par `soumettre()`, relu par `fil()`, retrouvé par `trancher()`, puis par
 * `reEnroler()`. C'est le critère qui distingue déjà `Appareil` d'un tableau
 * shapé dans ce module — traverser une frontière de méthode, ou être lu et
 * consommé sur place.
 *
 * ⚠️ Il ne porte JAMAIS le sésame, seulement son empreinte. Le sésame vit chez
 * le demandeur et nulle part ailleurs ; s'il entrait ici, il entrerait dans les
 * journaux, les traces d'exception et les vidages de variables.
 */
final class Litige
{
    /** Les états qu'un dossier peut prendre, dans l'ordre où on les rencontre. */
    public const OUVERT    = 'open';
    public const A_LIRE    = 'awaiting_admin';
    public const ACCEPTE   = 'accepted';
    public const REFUSE    = 'refused';
    public const CLOS      = 'closed';

    public function __construct(
        public readonly int $id,
        public readonly string $numero,
        public readonly int $compteId,
        public readonly string $nomCompte,
        public readonly string $statut,
        /** SHA-256 du sésame, en hexadécimal minuscule. Jamais le sésame. */
        public readonly string $empreinteSesame,
        public readonly int $creeLe,
        public readonly int $expireLe,
        /** Horodatage du dernier dépôt de réponses, 0 s'il n'y en a pas eu. */
        public readonly int $deposeLe = 0,
        public readonly ?int $trancheLe = null,
        public readonly ?string $tranchePar = null,
        /**
         * Combien de fois quelqu'un a tenté d'ouvrir un dossier alors que
         * celui-ci courait déjà.
         *
         * 🔑 C'est un fait que l'arbitre doit voir, pas un compteur technique :
         * il ne distingue pas l'insistance du titulaire de la présence d'un
         * tiers, et c'est précisément ce doute qu'il doit lever en parlant.
         */
        public readonly int $demandeursConcurrents = 0,
    ) {
    }

    public function expire(int $maintenant): bool
    {
        return $this->expireLe <= $maintenant;
    }

    /** Un dossier encore recevable : ouvert ou en attente de lecture. */
    public function enCours(): bool
    {
        return $this->statut === self::OUVERT || $this->statut === self::A_LIRE;
    }
}
