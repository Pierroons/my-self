<?php

/**
 * Les seuils du moteur, en un objet plutôt qu'en constantes de classe.
 *
 * Quatre d'entre eux portaient déjà la marque `⏱️ VALEUR DÉMO` et leur valeur de
 * production en commentaire : les changer demandait d'éditer le fichier du
 * module, donc de faire diverger le code d'un déploiement de celui du dépôt.
 * Ils vivent ici, et les deux jeux connus se nomment — `demo()` et `prod()`.
 *
 * Les constantes de `Moderate` restent en place et gardent les valeurs de démo :
 * elles sont lues par les vues du lab, et un moteur qui change ses seuils ne
 * doit pas casser l'affichage de qui les cite.
 */

declare(strict_types=1);

namespace Pierroons\SelfModerate;

use InvalidArgumentException;

final class Config
{
    /**
     * @param int[] $dureesBan Paliers de bannissement automatique, du premier
     *                         épisode au dernier. Le dernier palier se répète :
     *                         un quatrième épisode ne rend pas la peine infinie,
     *                         il la reconduit, et l'arbitre décide au-delà.
     */
    public function __construct(
        public readonly int $reputationInitiale = 20,
        public readonly int $reputationMax = 30,
        public readonly int $perteDroitDeVoteSous = 5,
        public readonly int $banA = 0,
        public readonly int $fenetreSalveSecondes = 60,
        public readonly int $salveVotantsMin = 3,
        public readonly int $fenetreMeuteJours = 30,
        public readonly int $meuteLiensMin = 2,
        public readonly int $meuteVotantsMax = 30,
        public readonly int $meuteEpisodeCooldown = 86400,
        public readonly int $meuteMute2 = 604800,
        public readonly int $meuteMute3 = 2592000,
        public readonly int $meutePenalite3 = 5,
        public readonly int $fenetreFarmingJours = 60,
        public readonly int $farmingUpvotesMax = 3,
        public readonly int $farmingDownvotesMax = 3,
        public readonly int $ageMinPourVoterSecondes = 120,
        public readonly array $dureesBan = [120, 600, 1800],
        public readonly ?int $dureeBanAdminSecondes = null,
        public readonly int $intervalleConvalescenceSecondes = 86400,
    ) {
        if ($this->dureesBan === []) {
            throw new InvalidArgumentException('dureesBan ne peut pas être vide : sans palier, aucune peine n\'a de fin.');
        }
        foreach ($this->dureesBan as $duree) {
            if (!is_int($duree) || $duree <= 0) {
                throw new InvalidArgumentException('dureesBan n\'accepte que des durées entières et strictement positives.');
            }
        }
    }

    /** Les valeurs raccourcies, pour qu'une démonstration tienne dans une séance. */
    public static function demo(): self
    {
        return new self();
    }

    /**
     * Les valeurs de service. Elles étaient déjà écrites — en commentaire, à
     * côté de chaque `⏱️ VALEUR DÉMO`. Les sortir du commentaire est tout ce que
     * cette méthode fait.
     */
    public static function prod(): self
    {
        return new self(
            fenetreSalveSecondes: 300,
            ageMinPourVoterSecondes: 86400,
            dureesBan: [86400, 604800, 2592000],
            intervalleConvalescenceSecondes: 604800,
        );
    }

    /**
     * La peine que vaut un épisode, le premier portant le numéro 1.
     *
     * Au-delà du dernier palier, c'est lui qui se reconduit. Faire croître la
     * peine indéfiniment reviendrait à une exclusion définitive prononcée par
     * une machine, ce que ce module refuse : passé le dernier palier, ce qui
     * change est que l'arbitre est appelé, pas que la peine s'allonge.
     */
    public function dureeBanPourEpisode(int $episode): int
    {
        $rang = max(1, $episode);
        $index = min($rang, count($this->dureesBan)) - 1;

        return $this->dureesBan[$index];
    }

    /**
     * Durée d'un bannissement prononcé à la main. Sans valeur explicite, c'est
     * le premier palier automatique : un geste d'arbitre n'a pas de raison
     * d'être plus lourd que la première sanction de la machine, et laisser
     * cette durée se régler seule évite un second nombre à tenir d'accord.
     */
    public function dureeBanAdmin(): int
    {
        return $this->dureeBanAdminSecondes ?? $this->dureesBan[0];
    }
}
