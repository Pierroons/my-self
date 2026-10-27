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
 * Sans journal branché, `Moderate` ne bannit pas tout seul — il signale — et
 * aucun geste d'arbitre ne passe. Une sanction qui ne laisse pas de trace n'est
 * pas une sanction allégée, c'est une sanction dont personne ne répond.
 *
 * ⚠️ **Le moteur écrit au journal sous le verrou d'écriture de sa base.** Une
 * implémentation qui écrirait dans cette même base par une autre connexion
 * attendrait un verrou que le moteur tient ; une implémentation lente retarde
 * d'autant les écritures concurrentes. Et le journal ne se défait pas avec la
 * base : aucun geste d'arbitre ne se fait donc dans la transaction d'un hôte,
 * et un vote n'y arme pas le ban automatique. Une clôture de peine ou une levée
 * pour meute faites là, puis défaites avec elle, se refont au passage suivant,
 * et s'inscrivent alors deux fois.
 */

declare(strict_types=1);

namespace Pierroons\SelfModerate;

interface Journal
{
    /**
     * Inscrit un acte. Le moteur passe un tableau dont les clés sont stables :
     *
     *   acte       `ban_debut` | `ban_fin` | `ban_leve` | `ban_maintenu` | `grace` | `revue_classee`
     *   compte     identifiant numérique du membre visé
     *   origine    `auto` (réputation à zéro) | `admin` (arbitre) ; null pour un ban
     *              posé avant que l'origine ne soit enregistrée
     *   arbitre    nom de l'admin, ou null quand c'est la machine
     *   motif      `reputation_zero` ou `meute_detectee` pour la machine, texte validé
     *              par `validateReason()` pour un arbitre ; le même au début et à la
     *              fin d'un ban
     *   episode    numéro d'épisode (1 = premier)              ban_debut, ban_fin
     *   dont_arbitre  combien de ces épisodes sont des bans     ban_debut
     *              d'arbitre : le palier d'un ban automatique en dépend aussi
     *   duree      durée de la peine en secondes               ban_debut
     *   debut      horodatage du début de la peine             ban_debut, ban_fin
     *   jusqu_a    horodatage de fin prévue                    ban_debut, ban_fin, ban_maintenu
     *   observe_a  instant où la fin s'est inscrite : la       ban_fin
     *              première écriture qui a touché le compte, ou le balayage
     *   cause      `grace` | `meute_detectee`                  ban_leve
     *   anticipee  levée avant le plancher de la peine         ban_leve
     *   reste      secondes de peine qui restaient             ban_leve
     *   plancher   horodatage du plancher, ou null             ban_leve
     *   reputation réputation avant la grâce                   grace
     *   rang_efface       rang de meute effacé, 0 s'il n'y en avait pas    ban_leve (grâce), grace
     *   suspension_levee  une suspension de vote courait encore           ban_leve (grâce), grace
     *   raison     cause du signal fermé ; pour un ban         revue_classee, ban_maintenu,
     *              d'arbitre, la cause ouverte que la case     ban_debut
     *              unique aurait portée, ou null
     *   ouverts    les causes ouvertes sur le compte quand     tous
     *              l'acte se décide, sous le verrou
     *   ferme      les signaux que l'acte ferme, ou retire de  tous ; `[]` pour un ban
     *              la file sans les fermer (un état vu reste   d'arbitre
     *              en base jusqu'à ce qu'il cesse), chacun
     *              `{signal, cause, ouvert_le, occurrences, detections}`
     *
     * `ouverts` et `ferme` sont des listes, passées en texte JSON : toute valeur
     * d'un acte reste scalaire, et une implémentation qui range une colonne par
     * clé n'a rien à sérialiser.
     *
     * Une implémentation peut en ajouter (horodatage, chaînage, signature) ;
     * elle ne doit pas en retirer.
     *
     * Une exception levée ici remonte à l'appelant : une écriture de journal
     * qui échoue en silence vaut l'absence de journal. Une seule exception à la
     * règle, et elle n'est pas muette : quand un vote ou un geste constate une
     * peine échue, le refus est consigné et signalé (`journal_en_panne`), et la
     * peine reste à refermer à l'écriture suivante. Une lecture ne constate
     * rien. Le planificateur (`balayerBansEchus()`) et le début d'un ban font
     * remonter le refus.
     */
    public function inscrire(array $acte): void;
}
