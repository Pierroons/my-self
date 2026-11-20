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
     * @param int      $banA                  Plancher de la réputation ET seuil du ban automatique :
     *                                        aucun vote ne la descend plus bas, et l'atteindre
     *                                        bannit (voir `banAutomatique`).
     * @param int      $fenetreSalveSecondes  Salve rapide : plusieurs downvotes groupés
     *                                        dans ce délai, que leurs votants soient liés ou non ;
     *                                        un vote que la part des comptes récents a neutralisé
     *                                        n'y entre pas. Le plus souvent une réaction spontanée
     *                                        au même message : elle signale, elle n'annule rien.
     * @param int      $fenetreMeuteJours     Deux comptes sont liés quand chacun a écrit à l'autre
     *                                        en privé sur cette fenêtre — au quorum, celle qui
     *                                        précède le plus ancien vote compté de la paire, et
     *                                        jusqu'à maintenant. Au quorum, des votants liés entre
     *                                        eux ne comptent que pour une voix.
     * @param int      $balayagePeriodeSecondes Un balayage de tout le site par tranche de
     *                                        cette durée, payé par le premier downvote qui
     *                                        y tombe ; les autres ne regardent que leur cible.
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
     *                                        il la reconduit, et l'arbitre décide au-delà. Un
     *                                        palier de plus d'un siècle (`Moderate::DUREE_MAX`)
     *                                        est refusé.
     * @param int      $intervalleConvalescenceSecondes Convalescence : +1 point par
     *                                        intervalle. La réputation remonte avec le
     *                                        temps, pas avec le mérite.
     * @param float    $plancherFraction      Part de la peine EN COURS avant laquelle un
     *                                        arbitre ne lève un ban qu'avec un motif écrit.
     * @param int      $balayageFiletMax      Sans planificateur, le premier vote négatif d'une
     *                                        tranche de balayage inscrit ensuite au plus ce
     *                                        nombre de fins de peine et de convalescences dues ;
     *                                        0 éteint le filet.
     * @param int      $bansArbitreSignal     Bans d'arbitre sur un même compte à partir
     *                                        desquels l'enchaînement est signalé à
     *                                        l'arbitre : chacun alourdit le ban
     *                                        automatique suivant.
     * @param bool     $banAutomatique        La réputation au plancher bannit, journal branché et
     *                                        hors de la transaction de l'hôte. Faux : elle est
     *                                        signalée à l'arbitre, sans ban — la machine signale,
     *                                        l'arbitre décide.
     * @param bool     $signalPerteDuVote     Signale à l'arbitre le compte qui perd son droit de
     *                                        vote sous `perteDroitDeVoteSous` ; le signal tombe
     *                                        quand le droit revient.
     * @param int      $messagesPourVoterAvantAge Messages publiés qui dispensent de
     *                                        `ageMinPourVoterSecondes` ; 0 n'en dispense personne.
     * @param int      $motifsVotantsMin      `reasonsFor()` ne rend les motifs reçus qu'à partir
     *                                        de ce nombre de votants distincts : sous lui, un motif
     *                                        peut désigner son auteur. 1 les rend toujours.
     * @param int      $fenetreCroiseeSecondes Deux membres qui votent l'un contre l'autre dans
     *                                        cette fenêtre se croisent : les deux votes sont
     *                                        annulés. La même durée fait ensuite la trêve, où
     *                                        aucun des deux ne vote plus contre l'autre.
     * @param int      $arrondiFinBanSecondes La fin d'un ban automatique se pose au multiple
     *                                        suivant de cette durée (UTC) : sa fin moins son
     *                                        palier redonnerait la seconde du vote décisif.
     *                                        1 la laisse exacte ; au plus un jour, et au plus le
     *                                        plus court palier de `dureesBan`.
     * @param int      $ageEtabliSecondes     En deçà de cette ancienneté au moment de son vote, un
     *                                        compte est récent : les votes qui portent des comptes
     *                                        récents sur un même auteur partagent, en solde net sur
     *                                        `fenetreFarmingJours`, le plafond qu'un votant seul a
     *                                        dans chaque sens (`farmingDownvotesMax`,
     *                                        `farmingUpvotesMax`) ; un vote qui n'a rien déplacé
     *                                        compte un dans son sens. 0 l'éteint.
     * @param bool     $quorumAuxSeuils       La perte du vote et le ban automatique attendent un
     *                                        quorum de votants sans lien entre eux
     *                                        (`voixPourSanction()`) ; sans lui, la chute est
     *                                        signalée à l'arbitre et rien n'est sanctionné. Faux :
     *                                        la réputation seule décide.
     * @param int      $quorumTravailMax      Le travail de la recherche du quorum, en sommets lus.
     *                                        Épuisée avant de décider, elle ne sanctionne que ce
     *                                        que portent les voix déjà trouvées ; la chute est
     *                                        signalée, et `compositionChute()` la dit indécise.
     * @param int      $quorumRecherchesMax   Recherches dans l'index de la paire de `dm` que la
     *                                        lecture des liens du quorum fait au plus. Au-delà,
     *                                        seuls les votants liés à personne font des voix,
     *                                        cherchés compte par compte sous la même borne ; s'ils
     *                                        n'atteignent pas le quorum, la chute se dit indécise.
     *                                        Le coût suit les votants et leurs expéditeurs
     *                                        distincts, jamais le volume des messages.
     */
    public function __construct(
        public readonly int $reputationInitiale = 20,
        public readonly int $reputationMax = 30,
        public readonly int $perteDroitDeVoteSous = 5,
        public readonly int $banA = 0,
        public readonly int $fenetreSalveSecondes = 60,
        public readonly int $salveVotantsMin = 3,
        public readonly int $fenetreMeuteJours = 30,
        public readonly int $balayagePeriodeSecondes = 3600,
        public readonly int $fenetreFarmingJours = 60,
        public readonly int $farmingUpvotesMax = 3,
        public readonly int $farmingDownvotesMax = 3,
        public readonly int $ciblesParVotantMax = 10,
        public readonly int $ageMinPourVoterSecondes = 120,
        public readonly array $dureesBan = [120, 600, 1800],
        public readonly ?int $dureeBanAdminSecondes = null,
        public readonly int $intervalleConvalescenceSecondes = 86400,
        public readonly float $plancherFraction = 1 / 3,
        public readonly int $bansArbitreSignal = 2,
        public readonly int $balayageFiletMax = 50,
        public readonly bool $banAutomatique = true,
        public readonly bool $signalPerteDuVote = true,
        public readonly int $messagesPourVoterAvantAge = 1,
        public readonly int $motifsVotantsMin = 1,
        public readonly int $fenetreCroiseeSecondes = 600,
        public readonly int $arrondiFinBanSecondes = 60,
        public readonly int $ageEtabliSecondes = 3600,
        public readonly bool $quorumAuxSeuils = true,
        public readonly int $quorumTravailMax = 200000,
        public readonly int $quorumRecherchesMax = 200000,
    ) {
        if ($this->dureesBan === []) {
            throw new InvalidArgumentException('dureesBan ne peut pas être vide : sans palier, aucune peine n\'a de fin.');
        }
        // `PHP_INT_MAX` dit « infini » à qui ne lit pas le moteur : l'échéance
        // déborderait en flottant, et le banni ne serait plus lu comme banni.
        foreach ($this->dureesBan as $duree) {
            if (!is_int($duree) || $duree <= 0) {
                throw new InvalidArgumentException('dureesBan n\'accepte que des durées entières et strictement positives.');
            }
            if ($duree > Moderate::DUREE_MAX) {
                throw new InvalidArgumentException('dureesBan : un palier de plus d\'un siècle (Moderate::DUREE_MAX) se lirait comme un ban sans fin, que la machine ne prononce pas.');
            }
        }
        if ($this->dureeBanAdminSecondes !== null && ($this->dureeBanAdminSecondes <= 0 || $this->dureeBanAdminSecondes > Moderate::DUREE_MAX)) {
            throw new InvalidArgumentException('dureeBanAdminSecondes est une durée finie : strictement positive, et d\'au plus un siècle (Moderate::DUREE_MAX).');
        }
        if ($this->plancherFraction < 0 || $this->plancherFraction > 1) {
            throw new InvalidArgumentException('plancherFraction est une part de la peine : entre 0 et 1.');
        }
        if ($this->bansArbitreSignal < 1) {
            throw new InvalidArgumentException('bansArbitreSignal compte des bans d\'arbitre : 1 ou plus.');
        }
        if ($this->balayageFiletMax < 0) {
            throw new InvalidArgumentException('balayageFiletMax compte des comptes : 0 ou plus, et 0 éteint le filet.');
        }
        if ($this->messagesPourVoterAvantAge < 0) {
            throw new InvalidArgumentException('messagesPourVoterAvantAge compte des messages : 0 ou plus, et 0 ne dispense personne.');
        }
        if ($this->motifsVotantsMin < 1) {
            throw new InvalidArgumentException('motifsVotantsMin compte des votants : 1 ou plus.');
        }
        if ($this->fenetreCroiseeSecondes < 1 || $this->fenetreCroiseeSecondes > Moderate::DUREE_MAX) {
            throw new InvalidArgumentException('fenetreCroiseeSecondes est une durée : strictement positive, et d\'au plus un siècle (Moderate::DUREE_MAX).');
        }
        if ($this->arrondiFinBanSecondes < 1 || $this->arrondiFinBanSecondes > 86400) {
            throw new InvalidArgumentException('arrondiFinBanSecondes est un pas d\'arrondi : 1 seconde au moins, un jour au plus.');
        }
        if ($this->ageEtabliSecondes < 0 || $this->ageEtabliSecondes > Moderate::DUREE_MAX) {
            throw new InvalidArgumentException('ageEtabliSecondes est une durée : 0 l\'éteint, et elle tient en un siècle au plus (Moderate::DUREE_MAX).');
        }
        if ($this->quorumTravailMax < 1) {
            throw new InvalidArgumentException('quorumTravailMax compte des sommets lus : 1 ou plus.');
        }
        if ($this->quorumRecherchesMax < 1) {
            throw new InvalidArgumentException('quorumRecherchesMax compte des recherches dans un index : 1 ou plus.');
        }
        if ($this->arrondiFinBanSecondes > min($this->dureesBan)) {
            throw new InvalidArgumentException(sprintf(
                'arrondiFinBanSecondes (%d s, 60 par défaut) dépasse le plus court palier de dureesBan (%d s) : l\'arrondi allongerait la peine de plus qu\'elle ne dure.',
                $this->arrondiFinBanSecondes,
                min($this->dureesBan)
            ));
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
            fenetreCroiseeSecondes: 259200,
            arrondiFinBanSecondes: 3600,
            ageEtabliSecondes: 2592000,
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

    /**
     * Les voix sans lien qu'une sanction exige au seuil `$seuil` : autant que de
     * votants au plafond (`farmingDownvotesMax`) pour descendre du point de départ
     * jusqu'à lui : le ban se lit à `banA`, la perte du vote à
     * `perteDroitDeVoteSous - 1`.
     */
    public function voixPourSanction(int $seuil): int
    {
        return (int) ceil(max(0, $this->reputationInitiale - $seuil) / max(1, $this->farmingDownvotesMax));
    }

    /** La remontée s'arrête au point de départ, jamais au-delà. */
    public function sortieConvalescence(): int
    {
        return $this->reputationInitiale;
    }
}
