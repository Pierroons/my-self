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
 * et un vote n'y arme ni le ban ni le retrait du vote automatiques. Une clôture
 * de peine faite là, puis défaite avec elle, se refait au passage suivant, et
 * s'inscrit alors deux fois.
 *
 * Le retour du vote que la machine a retiré, en démonstration, ne s'inscrit
 * pas : il revient avec la remontée, que rien ne date au journal.
 */

declare(strict_types=1);

namespace Pierroons\SelfModerate;

interface Journal
{
    /**
     * Inscrit un acte. Le moteur passe un tableau dont les clés sont stables :
     *
     *   acte       `ban_debut` | `ban_fin` | `ban_leve` | `ban_prolonge` | `ban_maintenu` | `grace` |
     *              `revue_classee` | `vote_retire` | `vote_rendu`
     *   compte     identifiant numérique du membre visé
     *   origine    `auto` (la machine, en démonstration) | `admin` (arbitre) ; null pour
     *              un ban posé avant que l'origine ne soit enregistrée. Absente de
     *              `grace` et de `revue_classee`, toujours d'un arbitre
     *   arbitre    nom du compte de l'arbitre au moment du geste, ou null quand
     *              c'est la machine
     *   arbitre_id identifiant de ce compte (`accounts.id`), celui que l'hôte a
     *              passé au geste ; null quand c'est la machine. Le nom peut
     *              changer, l'identifiant reste
     *   motif      `reputation_zero` pour un ban de la machine, `perte_droit_de_vote`
     *              pour son retrait du vote (`meute_detectee` dans les actes
     *              d'avant la 0.9.0), texte validé
     *              par `validateReason()` pour un arbitre ; le même au début et à la
     *              fin d'un ban
     *   episode    numéro d'épisode (1 = premier)              ban_debut, ban_fin
     *   dont_arbitre  combien de ces épisodes sont des bans     ban_debut
     *              d'arbitre : le palier d'un ban automatique en dépend aussi.
     *              Compté depuis la 0.7.0 : une base migrée part de zéro
     *   duree      durée de la peine en secondes ; null pour   ban_debut
     *              un ban sans fin
     *   debut      horodatage du début de la peine, ou du      ban_debut, ban_fin, vote_retire,
     *              geste                                       vote_rendu
     *   jusqu_a    horodatage de fin prévue ; `Moderate::SANS_FIN`  ban_debut, ban_fin, ban_maintenu,
     *              pour un ban sans fin ; null pour un vote    ban_prolonge, vote_retire,
     *              que la machine retire jusqu'à la remontée ;  vote_rendu
     *              l'échéance qu'un `vote_rendu` devance
     *   sans_fin   le ban n'a pas d'échéance                   ban_debut, ban_leve, ban_prolonge
     *   de         l'échéance que la prolongation repousse     ban_prolonge
     *   observe_a  instant où la fin s'est inscrite : la       ban_fin
     *              première écriture qui a touché le compte, ou le balayage
     *   cause      `grace` | `levee`                         ban_leve ; `levee` arrête la peine sans
     *              effacer la récidive, `grace` remet tout au départ ;
     *              `meute_detectee` dans les actes d'avant la 0.9.0
     *   anticipee  levée avant le plancher de la peine         ban_leve
     *   reste      secondes de peine qui restaient ; null      ban_leve
     *              pour un ban sans fin
     *   plancher   horodatage du plancher, ou null             ban_leve
     *   reputation réputation avant la grâce, ou au retrait    grace, vote_retire
     *              du vote
     *   rang_efface       rang de meute effacé, 0 s'il n'y en avait pas    ban_leve (grâce), grace
     *   suspension_levee  une suspension de vote courait encore           ban_leve (grâce), grace
     *   raison     cause du signal fermé ; pour un ban         revue_classee, ban_maintenu,
     *              d'arbitre, la cause ouverte que la case     ban_debut
     *              unique aurait portée, ou null
     *   ouverts    les causes ouvertes sur le compte quand     tous
     *              l'acte se décide, sous le verrou
     *   ferme      les signaux que l'acte ferme, ou retire de  tous ; un ban d'arbitre
     *              la file sans les fermer (un état vu reste   ferme les sanctions dues
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
     * qui échoue en silence vaut l'absence de journal. Des exceptions à la
     * règle, aucune muette. Quand un vote, un geste ou le balayage
     * constate une peine échue, le refus est consigné et signalé
     * (`journal_en_panne`), et la peine reste à refermer à l'écriture suivante.
     * Une lecture ne constate rien. Le balayage n'appelle plus le journal après
     * un premier refus, et le fait remonter en fin de passe ; le début d'un ban
     * d'arbitre le fait remonter aussitôt. Au début d'un ban automatique, le
     * refus ne défait pas le vote qui l'a causé : la chute est signalée avec le
     * ban dû (`ban_en_attente`), et le vote négatif suivant le retente. Un refus
     * se lève par n'importe quelle exception, une `\Error` comprise : là où le
     * refus est contenu — une fin de peine, le début d'un ban automatique, le
     * retrait du vote, le balayage —, elle se consigne et se signale comme lui ;
     * d'un geste d'arbitre, elle remonte. Un début de ban inscrit ici, puis défait
     * avec la base, reste sans effet : le suivant porte ses propres dates, et le
     * dernier est celui de la peine.
     *
     * Une implémentation ne rappelle pas le moteur : elle est appelée au milieu
     * d'une de ses écritures, où la lecture d'un compte par le moteur
     * (`getReputation()`, `canVote()`, `estBanni()`) est refusée par une
     * `LectureDansUneEcriture`, qui remonte telle quelle — une erreur
     * d'intégration ne se déguise pas en panne du journal —, sauf au filet du
     * vote : le vote est déjà validé, et elle part au journal d'erreurs. Les
     * listes de l'arbitre n'y sont pas refusées, et y lisent l'état que
     * l'écriture en cours n'a pas fini d'inscrire.
     */
    public function inscrire(array $acte): void;
}
