<?php

/**
 * Les seuils du moteur, en un objet plutôt qu'en constantes de classe.
 *
 * C'est leur seule source : le moteur les lit ici, et un hôte qui les affiche
 * les lit ici aussi, par `Moderate::config()`. Changer de jeu (`demo()`,
 * `prod()`, ou le sien) change donc à la fois ce que le moteur applique et ce
 * que les pages annoncent.
 */

declare(strict_types=1);

namespace Pierroons\SelfModerate;

use InvalidArgumentException;

final class Config
{
    /**
     * @param int      $fenetreSalveSecondes  Salve rapide : plusieurs downvotes groupés
     *                                        dans ce délai, SANS lien entre les votants.
     *                                        Le plus souvent une réaction spontanée au
     *                                        même message : elle signale, elle n'annule rien.
     * @param int      $fenetreMeuteJours     Meute : des votants LIÉS ENTRE EUX qui frappent
     *                                        la même cible. Le lien seul déclenche
     *                                        l'annulation, sur une fenêtre longue — une
     *                                        meute prend son temps.
     * @param int      $meuteVotantsMax       Borne du graphe des votants d'une cible : au-delà,
     *                                        les plus récents sont gardés et la cible est
     *                                        signalée à l'arbitre.
     * @param int      $balayagePeriodeSecondes Un balayage de tout le site par tranche de
     *                                        cette durée, payé par le premier downvote qui
     *                                        y tombe ; les autres ne regardent que leur cible.
     * @param int      $meuteEpisodeCooldown  Un épisode de meute par votant sur ce délai,
     *                                        compté depuis le début de l'épisode : trois
     *                                        victimes le même soir restent un épisode.
     * @param int      $farmingDownvotesMax   Plafond de downvotes d'un votant vers un même
     *                                        auteur sur la fenêtre : casse l'érosion lente
     *                                        d'un votant patient qui vise chaque message.
     * @param int      $ciblesParVotantMax    Membres distincts qu'un votant peut viser sur la
     *                                        fenêtre de farming : au-delà, ses downvotes vers un
     *                                        membre de plus sont neutralisés et il est signalé.
     * @param int      $ageMinPourVoterSecondes Anti-Sybil : ancienneté exigée pour voter,
     *                                        sauf si le compte a déjà publié.
     * @param int[]    $dureesBan             Paliers de bannissement automatique, du premier
     *                                        épisode au dernier. Le dernier palier se répète :
     *                                        un quatrième épisode ne rend pas la peine infinie,
     *                                        il la reconduit, et l'arbitre décide au-delà.
     * @param int      $intervalleConvalescenceSecondes Convalescence : +1 point par
     *                                        intervalle. La réputation remonte avec le
     *                                        temps, pas avec le mérite.
     * @param float    $plancherFraction      Part de la peine EN COURS avant laquelle un
     *                                        arbitre ne lève un ban qu'avec un motif écrit.
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
        public readonly int $meuteVotantsMax = 200,
        public readonly int $balayagePeriodeSecondes = 3600,
        public readonly int $meuteEpisodeCooldown = 86400,
        public readonly int $fenetreFarmingJours = 60,
        public readonly int $farmingUpvotesMax = 3,
        public readonly int $farmingDownvotesMax = 3,
        public readonly int $ciblesParVotantMax = 10,
        public readonly int $ageMinPourVoterSecondes = 120,
        public readonly array $dureesBan = [120, 600, 1800],
        public readonly ?int $dureeBanAdminSecondes = null,
        public readonly int $intervalleConvalescenceSecondes = 86400,
        public readonly float $plancherFraction = 1 / 3,
    ) {
        if ($this->dureesBan === []) {
            throw new InvalidArgumentException('dureesBan ne peut pas être vide : sans palier, aucune peine n\'a de fin.');
        }
        foreach ($this->dureesBan as $duree) {
            if (!is_int($duree) || $duree <= 0) {
                throw new InvalidArgumentException('dureesBan n\'accepte que des durées entières et strictement positives.');
            }
        }
        if ($this->plancherFraction < 0 || $this->plancherFraction > 1) {
            throw new InvalidArgumentException('plancherFraction est une part de la peine : entre 0 et 1.');
        }
    }

    /** Les valeurs raccourcies, pour qu'une démonstration tienne dans une séance. */
    public static function demo(): self
    {
        return new self();
    }

    /** Les valeurs de service. */
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

    /**
     * La convalescence s'ouvre sous ce seuil et se lève à `sortieConvalescence()`.
     *
     * Un ÉTAT plutôt qu'un seuil : conditionner la remontée à « score < seuil
     * du vote » l'arrêterait pile au seuil qui rend le droit de vote, et
     * laisserait le compte à vie sur le fil du rasoir.
     */
    public function entreeConvalescenceSous(): int
    {
        return $this->perteDroitDeVoteSous;
    }

    /**
     * L'instant avant lequel lever ce ban demande un motif écrit : une fraction
     * de la peine en cours, jamais du palier maximal — un tiers de trente jours
     * appliqué à un premier ban d'un jour ferait un plancher dix fois plus long
     * que la peine.
     */
    public function plancherDe(int $debut, int $fin): int
    {
        return $debut + (int) ceil($this->plancherFraction * max(0, $fin - $debut));
    }

    /** La remontée s'arrête au point de départ, jamais au-delà. */
    public function sortieConvalescence(): int
    {
        return $this->reputationInitiale;
    }
}
