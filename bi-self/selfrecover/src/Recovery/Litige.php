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

    /**
     * Sous cette valeur, un instant n'en est pas un.
     *
     * 🔑 **Le défaut que ce plancher ferme, et pourquoi le typage ne suffit pas.**
     * Les champs ci-dessous sont typés `int`, et un adaptateur les remplit depuis
     * sa base. Si la colonne est restée en `TEXT` — ce qui arrive, SQLite ne s'y
     * oppose pas — le cast rend le MILLÉSIME :
     *
     *     (int) '2026-07-12 08:00:00'  =  2026        → un instant de janvier 1970
     *     (int) '1752000000'           =  1752000000  → correct
     *
     * Les deux satisfont `int`. Aucune ligne n'échoue, rien ne lève, et l'arbitre
     * reçoit un dossier daté de 1970 — donc expiré, donc invisible. Le contrôle de
     * FORME reste vert pendant que la valeur est perdue : `2026` est un entier
     * parfaitement valide.
     *
     * 1 000 000 000 est le 9 septembre 2001. Tout instant réel du protocole est
     * au-dessus ; un millésime et un zéro sont en dessous. Le plancher est donc
     * large et ne se discute pas — il ne sépare pas les dates plausibles des
     * autres, il sépare les instants des nombres qui n'en sont pas.
     */
    public const PLANCHER_EPOQUE = 1_000_000_000;

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
        // ⚠️ Le refus est ICI, à la construction, et pas dans une méthode qu'il
        // faudrait penser à appeler : une discipline n'est pas un garde-fou tant
        // qu'une sonde ne rougit pas quand on la rompt. Tous les adaptateurs
        // passent par ce constructeur, y compris ceux qui n'existent pas encore.
        //
        // Refuser plutôt que juger un dossier sur une date fausse : un litige de
        // niveau 3 décide de l'accès à un compte, et une erreur y coûte plus cher
        // qu'une exception au chargement.
        foreach (['creeLe' => $creeLe, 'expireLe' => $expireLe] as $champ => $valeur) {
            if ($valeur < self::PLANCHER_EPOQUE) {
                throw new \InvalidArgumentException(self::plainte($champ, $valeur));
            }
        }

        // `deposeLe` vaut 0 quand rien n'a été déposé — c'est documenté et
        // légitime. Ce qui ne l'est pas, c'est une valeur entre 0 et le plancher :
        // elle prétend porter un dépôt et n'en porte pas.
        if ($deposeLe !== 0 && $deposeLe < self::PLANCHER_EPOQUE) {
            throw new \InvalidArgumentException(self::plainte('deposeLe', $deposeLe));
        }

        // `trancheLe` est nul tant que personne n'a tranché.
        if ($trancheLe !== null && $trancheLe < self::PLANCHER_EPOQUE) {
            throw new \InvalidArgumentException(self::plainte('trancheLe', $trancheLe));
        }
    }

    /** Une plainte qui dit où chercher, plutôt que « valeur invalide ». */
    private static function plainte(string $champ, int $valeur): string
    {
        return sprintf(
            'Litige::%s = %d — ce n\'est pas un instant UNIX (plancher %d, soit 2001). '
            . 'Cause la plus fréquente : la colonne de votre base est en TEXT, et le '
            . 'cast en rend le millésime — (int) "2026-07-12 08:00:00" vaut 2026. '
            . 'Le contrat attend des SECONDES depuis le 1er janvier 1970 UTC.',
            $champ,
            $valeur,
            self::PLANCHER_EPOQUE,
        );
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
