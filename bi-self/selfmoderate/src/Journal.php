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
     *   acte       `ban_debut` | `ban_fin` | `ban_leve` | `ban_maintenu` | `grace`
     *   compte     identifiant numérique du membre visé
     *   origine    `auto` (réputation à zéro) | `admin` (arbitre) ; null pour un ban
     *              posé avant que l'origine ne soit enregistrée
     *   arbitre    nom de l'admin, ou null quand c'est la machine
     *   motif      `reputation_zero` ou `meute_detectee` pour la machine, texte validé
     *              par `validateReason()` pour un arbitre ; le même au début et à la
     *              fin d'un ban
     *   episode    numéro d'épisode (1 = premier)              ban_debut, ban_fin
     *   duree      durée de la peine en secondes               ban_debut
     *   debut      horodatage du début de la peine             ban_debut, ban_fin
     *   jusqu_a    horodatage de fin prévue                    ban_debut, ban_fin, ban_maintenu
     *   observe_a  instant où la fin a été constatée           ban_fin
     *   cause      `grace` | `meute_detectee`                  ban_leve
     *   anticipee  levée avant le plancher de la peine         ban_leve
     *   reste      secondes de peine qui restaient             ban_leve
     *   plancher   horodatage du plancher, ou null             ban_leve
     *   reputation réputation avant la grâce                   grace
     *
     * Une implémentation peut en ajouter (horodatage, chaînage, signature) ;
     * elle ne doit pas en retirer.
     *
     * Toute exception levée ici remonte à l'appelant sans être avalée : une
     * écriture de journal qui échoue en silence vaut l'absence de journal.
     */
    public function inscrire(array $acte): void;
}
