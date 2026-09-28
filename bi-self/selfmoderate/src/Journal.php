<?php

/**
 * Le journal où s'écrivent les actes de sanction.
 *
 * Le module n'en fournit pas d'implémentation et n'impose aucun format : il
 * existe déjà deux journaux chaînés et signés dans cet écosystème — celui du
 * super-utilisateur et celui du séquestre SelfDataGuard —, et un module de
 * modération n'a pas à en inventer un troisième ni à choisir lequel.
 *
 * 🔑 **Ce que l'implémentation doit garantir**, parce que le moteur s'y fie
 * pour armer le bannissement automatique : l'écriture est durable et ne peut
 * pas être réécrite après coup. Un journal qui perd une entrée rend muette la
 * seule trace d'une sanction prononcée sans humain.
 *
 * Sans journal branché, `Moderate` ne bannit pas tout seul : il signale, et
 * l'arbitre tranche. Une sanction automatique qui ne laisse pas de trace n'est
 * pas une sanction allégée, c'est une sanction dont personne ne répond.
 */

declare(strict_types=1);

namespace Pierroons\SelfModerate;

interface Journal
{
    /**
     * Inscrit un acte. Le moteur passe un tableau dont les clés sont stables :
     *
     *   acte      `ban_auto_debut` | `ban_auto_fin` | `ban_leve` | `ban_maintenu`
     *   compte    identifiant du membre sanctionné
     *   episode   numéro d'épisode au moment de l'acte (1 = premier)
     *   duree     durée de la peine en secondes, pour les actes qui en posent une
     *   jusqu_a   horodatage de fin prévue
     *   motif     `reputation_zero` pour l'automatique, libre pour un acte d'arbitre
     *   arbitre   identifiant de l'admin, ou null quand c'est la machine
     *   observe_a horodatage de la constatation, présent quand il diffère de l'échéance
     *
     * Une implémentation peut en ajouter (horodatage, chaînage, signature) ;
     * elle ne doit pas en retirer.
     *
     * Toute exception levée ici remonte à l'appelant sans être avalée : une
     * écriture de journal qui échoue en silence vaut l'absence de journal.
     */
    public function inscrire(array $acte): void;
}
