<?php
/**
 * MySelf-Lab — façade vers le moteur SelfModerate.
 *
 * Le moteur vit dans `bi-self/selfmoderate/`, comme SelfRecover et
 * SelfDataGuard vivent dans les leurs. Ce fichier n'existe que pour deux
 * raisons : brancher la traduction du lab sur un moteur qui n'en connaît
 * aucune, et garder le nom `Pierroons\MySelfLab\Moderate` que sept fichiers
 * appellent déjà.
 *
 * Aucune règle de modération ici. Si une décision se prend dans ce fichier,
 * c'est qu'elle est au mauvais endroit.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use Pierroons\SelfModerate\Moderate as Engine;

require_once __DIR__ . '/i18n.php';

// `tc()` est dans le namespace global : i18n.php n'en déclare aucun.
// Posé au chargement de la classe, donc avant tout appel — l'autoload PSR-4
// charge ce fichier dès que `Moderate::` est référencé quelque part.
Engine::setTranslator('tc');

final class Moderate extends Engine
{
    /**
     * Ce que les pages annoncent des règles, lu dans la config en service :
     * les textes ne portent aucun seuil à eux.
     *
     * @return array{initiale: int, max: int, sybil: string, farmingMax: int, farmingJours: int, perteVote: int, paliers: string, balayage: string, convalescence: string, sortie: int, quorumVote: int, quorumBan: int}
     */
    public static function regles(): array
    {
        $c = self::config();
        return [
            'initiale'     => $c->reputationInitiale,
            'max'          => $c->reputationMax,
            'sybil'        => self::dureeEnClair($c->ageMinPourVoterSecondes),
            'recents'      => self::dureeEnClair($c->ageEtabliSecondes),
            'farmingMax'   => $c->farmingUpvotesMax,
            'farmingJours' => $c->fenetreFarmingJours,
            'perteVote'    => $c->perteDroitDeVoteSous,
            'paliers'      => implode(' → ', array_map([self::class, 'dureeEnClair'], $c->dureesBan)),
            'balayage'     => self::dureeEnClair($c->balayagePeriodeSecondes),
            'convalescence' => self::dureeEnClair($c->intervalleConvalescenceSecondes),
            'sortie'       => $c->sortieConvalescence(),
            'quorumVote'   => $c->voixPourSanction($c->perteDroitDeVoteSous - 1),
            'quorumBan'    => $c->voixPourSanction($c->banA),
        ];
    }

    /**
     * La fin d'un ban telle que la voit le compte banni. Pour un ban automatique, le
     * moteur l'arrondit au pas de la config, dès la pose (`finAffichee()`) — sans quoi
     * elle redonne l'instant du vote décisif —, et la page dit « au plus tard ».
     *
     * @param array{banned_until: int, ban_origine: ?string} $etat ce que rend `getReputation()`
     */
    public static function finDeBan(array $etat): string
    {
        $fin = date('d/m/Y H:i', self::finAffichee((int) $etat['banned_until'], $etat['ban_origine'] ?? null));

        return ($etat['ban_origine'] ?? null) === 'auto' ? sprintf(tc('%s au plus tard'), $fin) : $fin;
    }
}
