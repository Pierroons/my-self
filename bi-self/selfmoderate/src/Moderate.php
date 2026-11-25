<?php
/**
 * SelfModerate — réputation distribuée et anti-manipulation.
 *
 * Vote sur un contenu ou sur un membre (±1) : la réputation affectée est
 * toujours celle d'une personne, le contenu ne fait que dire d'où vient le vote.
 * Défenses : anti-Sybil, upvote-farming, downvote-farming, quorum de votants
 * sans lien aux seuils, sanctions graduées — automatiques en démonstration,
 * signalées à un arbitre en service.
 *
 * Le moteur ne connaît ni la langue ni l'interface de son hôte : il rend ses
 * messages à travers `setTranslator()`, et laisse l'appelant fournir la
 * connexion. Les noms de tables restent ceux du schéma du lab tant que le
 * déménagement se fait à comportement constant ; les paramétrer viendra avec
 * le deuxième hôte, pas avant — une abstraction écrite pour un seul appelant
 * se trompe de découpe une fois sur deux.
 */

declare(strict_types=1);

namespace Pierroons\SelfModerate;

use PDO;
use PDOException;

class Moderate
{
    /**
     * L'échéance d'un ban d'arbitre sans fin : le 31/12/9999 à 23:59:59 UTC. Une
     * date et non un état à part : les comparaisons du moteur et des hôtes
     * (`> 0`, `> time()`) tiennent, et elle n'échoit jamais.
     */
    public const SANS_FIN = 253402300799;

    /** Ce ban est-il sans fin ? À l'usage des hôtes qui affichent une échéance. */
    public static function estSansFin(int $jusqua): bool
    {
        return $jusqua >= self::SANS_FIN;
    }

    /**
     * La fin d'un ban telle qu'une page la montre au compte banni. Un ban
     * automatique commence à l'instant du vote décisif : sa fin s'arrondit dès
     * la pose (`arrondirFin()`), et la page écrit « au plus tard ». Celle d'un
     * ban posé avant cette règle s'arrondit ici de même. Un ban d'arbitre est
     * signé : il reste à la seconde. Un ban sans fin, ou aucun ban, passe tel
     * quel.
     */
    public static function finAffichee(int $bannedUntil, ?string $origine): int
    {
        return $origine === 'auto' ? self::arrondirFin($bannedUntil) : $bannedUntil;
    }

    /**
     * La fin d'un ban automatique, portée au multiple suivant de
     * `arrondiFinBanSecondes` (UTC) : sa fin moins son palier redonnerait la
     * seconde du vote décisif, par la page comme par l'instant où le compte
     * revote. Aucun ban, ou un ban sans fin, passe tel quel.
     */
    private static function arrondirFin(int $fin): int
    {
        $pas = self::config()->arrondiFinBanSecondes;
        if ($fin <= 0 || self::estSansFin($fin)) {
            return $fin;
        }

        return intdiv($fin + $pas - 1, $pas) * $pas;
    }

    /**
     * Traduction des messages rendus à l'appelant. Identité par défaut : un
     * moteur qui impose sa langue n'est pas réutilisable, et un moteur qui
     * exige un traducteur pour démarrer ne l'est pas non plus.
     */
    private static $translator = null;

    public static function setTranslator(?callable $fn): void
    {
        self::$translator = $fn;
    }

    protected static function t(string $texte): string
    {
        return self::$translator ? (string) (self::$translator)($texte) : $texte;
    }

    /**
     * L'heure que lit le moteur, en secondes Unix. `time()` par défaut ; un hôte
     * qui simule le temps (une démonstration, un banc) branche la sienne, et
     * lit la même par `maintenant()` pour afficher ce que le moteur décide.
     * Le journal date ses entrées à sa manière : ce branchement ne le touche pas.
     * Une horloge qui rend autre chose qu'un instant entier, positif et avant
     * `SANS_FIN` lève ; une horloge qui recule fait reculer le moteur avec elle.
     */
    private static $horloge = null;

    public static function setHorloge(?callable $fn): void
    {
        self::$horloge = $fn;
    }

    public static function maintenant(): int
    {
        if (self::$horloge === null) {
            return time();
        }
        $t = (self::$horloge)();
        if (!is_int($t) || $t <= 0 || $t >= self::SANS_FIN) {
            throw new \LogicException(sprintf(
                'SelfModerate : l\'horloge branchée par setHorloge() doit rendre un instant Unix entier, positif et avant Moderate::SANS_FIN ; elle a rendu %s.',
                is_int($t) ? (string) $t : get_debug_type($t)
            ));
        }

        return $t;
    }

    /**
     * La plus longue peine qui ait une échéance : un siècle de 365,25 jours.
     * Au-delà, un ban se pose sans fin — sinon il s'afficherait comme un ban
     * sans fin, sans en porter le signal ni la règle du motif à la levée.
     */
    public const DUREE_MAX = 3_155_760_000;

    /**
     * Un vote au plafond de son auteur, et dans la salve, en filtre SQL sur
     * `mod_votes` : un vote qui porte, ou qu'un croisement, la riposte qui le fait
     * ou sa trêve ont fait tomber — sans quoi un membre qui riposte contre chacun
     * de ses critiques n'aurait aucune borne. Un vote que la part des comptes
     * récents a neutralisé n'y est pas : il n'a rien déplacé ; une cible qui
     * remplit la part par ses propres comptes, le temps de les croiser, ne fait pas
     * taire les comptes récents qui votent entre-temps pour toute la fenêtre des
     * plafonds (`fenetreFarmingJours`) ; et, sans plafond, il relancerait la salve
     * à chaque message de sa cible.
     */
    private const VOTE_AU_PLAFOND = "(blocked = 0 OR blocked_reason IN ('vote_croise', 'riposte', 'treve'))";

    /**
     * Les raisons d'un vote tombé par croisement : le vote de face, la riposte,
     * la trêve. Une page publique les tait — la ligne dirait à chacun des deux que
     * l'autre a voté contre lui — et les écarte dans la requête
     * (`blockedVotes(…, saufRaisons: self::RAISONS_DU_CROISEMENT)`).
     */
    public const RAISONS_DU_CROISEMENT = ['vote_croise', 'riposte', 'treve'];

    /**
     * Un vote qui a porté : compté, ou croisé après avoir compté. C'est ce que sa
     * cible en voit — son motif, et sa place parmi les votes reçus. Une riposte
     * ou un vote de trêve n'ont jamais porté : montrés, un motif apparu sans
     * point perdu désignerait leur auteur. Au croisement, aucune page ne change.
     */
    private const VOTE_RECU = "(blocked = 0 OR blocked_reason = 'vote_croise')";

    /**
     * Une durée en clair : l'unité suit l'ordre de grandeur, et la durée n'est
     * jamais dite plus courte qu'elle n'est — le texte annonce souvent une
     * attente. Minutes arrondies au-dessus, puis jours et heures, ou heures et
     * minutes : 86 399 s se lit « 1 jour », 90 000 s « 1 jour 1 heure ». Les
     * unités passent par le traducteur, pour que l'hôte affiche les seuils de
     * la config dans sa langue.
     */
    public static function dureeEnClair(int $secondes): string
    {
        $minutes = max(1, intdiv($secondes + 59, 60));
        if ($minutes >= 1440) {
            $jours  = intdiv($minutes, 1440);
            $heures = intdiv($minutes - $jours * 1440 + 59, 60);
            if ($heures === 24) {
                [$jours, $heures] = [$jours + 1, 0];
            }
            $parties = [[$jours, 'jour', 'jours'], [$heures, 'heure', 'heures']];
        } elseif ($minutes >= 60) {
            $parties = [[intdiv($minutes, 60), 'heure', 'heures'], [$minutes % 60, 'minute', 'minutes']];
        } else {
            $parties = [[$minutes, 'minute', 'minutes']];
        }
        $mots = [];
        foreach ($parties as [$n, $un, $plusieurs]) {
            if ($n > 0) {
                $mots[] = $n . ' ' . static::t($n > 1 ? $plusieurs : $un);
            }
        }

        return implode(' ', $mots);
    }

    // Motif de vote — un downvote coûte une phrase. Les bornes visent le
    // remplissage : « lol » est trop court, « aaaaaa… » et « bon bon bon » sont
    // assez longs mais ne disent rien, et on les atteint en bloquant une touche.
    public const REASON_MIN_CHARS          = 40;
    // Une borne haute aussi : un motif s'affiche sur le profil de la personne
    // visée, et un texte sans fin rendrait la page illisible.
    public const REASON_MAX_CHARS          = 1000;
    public const REASON_MIN_WORDS          = 3;
    // Répétitions : un plancher toujours permis, puis une part du texte, plus
    // large pour un mot court (« de », « les », « mon ») que pour un mot
    // porteur, dont la répétition signe le bourrage. Une part et non un plafond
    // fixe, pour la même raison que le compte des caractères ci-dessous, qui
    // joue ici dans l'autre sens : une phrase détaillée répète ses mots-outils.
    // La mesure suit le mot le plus fréquent, pas la rotation — des mots qui se
    // relaient, chacun sous sa part, passent.
    public const REASON_MAX_WORD_REPEATS   = 2;
    public const REASON_CARRIER_MIN_LEN    = 4;
    public const REASON_SHORT_ONE_IN       = 3;
    public const REASON_CARRIER_ONE_IN     = 5;
    // Un COMPTE, pas un ratio : l'alphabet est fini, donc plus un texte est long
    // plus son ratio de caractères distincts baisse — une mesure relative punit
    // le motif détaillé et laisse passer le bourrage court. Une phrase qui dit
    // quelque chose emploie une quinzaine de lettres ; « azertyazerty… » en
    // emploie six, quelle que soit sa longueur.
    public const REASON_MIN_DISTINCT_CHARS = 12;
    public const REASON_MAX_RUN            = 3;

    /** Motifs proposés par l'hôte. Le protocole les veut configurables par plateforme. */
    private static array $reasonCodes = [
        'hors_sujet', 'agressif', 'desinformation',
        'entraide', 'contribution_utile', 'autre',
    ];

    public static function setReasonCodes(array $codes): void
    {
        self::$reasonCodes = array_values(array_unique(array_map('strval', $codes)));
    }

    public static function reasonCodes(): array
    {
        return self::$reasonCodes;
    }

    /**
     * Les seuils et la déclaration de l'hôte — leur seule source, pour le moteur
     * comme pour les pages de l'hôte. Aucun par défaut : `config()` lève tant que
     * l'hôte ne l'a pas posée.
     */
    private static ?Config $config = null;

    /** Le journal des sanctions. Sans lui, le moteur ne bannit pas seul. */
    private static ?Journal $journal = null;

    /** Les sanctions que la machine signale dues : un ban d'arbitre y répond. */
    private const CAUSES_DUES = ['reputation_zero', 'ban_en_attente', 'perte_du_vote_due'];

    public static function setConfig(?Config $config): void
    {
        self::$config = $config;
    }

    /**
     * Sans `setConfig()`, le moteur refuse au lieu de prendre une démonstration :
     * un hôte qui oublie l'appel servirait des durées de quelques minutes et des
     * bans automatiques sans le savoir.
     */
    public static function config(): Config
    {
        return self::$config ?? throw new \LogicException(static::t(
            'SelfModerate attend sa configuration : l\'hôte appelle Moderate::setConfig() avec Config::prod(Messagerie::DM ou Messagerie::HORS_MOTEUR), ou Config::demo() pour une démonstration, avant tout appel au moteur.'
        ));
    }

    /**
     * Branche le journal, et avec lui le bannissement automatique et les gestes
     * d'arbitre.
     *
     * 🔑 **C'est ce branchement qui arme la sanction**, et rien d'autre. Un
     * déploiement qui ne journalise pas signale la réputation à zéro, et aucun
     * geste d'arbitre n'y passe (`refusSansJournal()`). Le choix n'est pas une
     * précaution de style — une peine prononcée ou levée sans trace ne laisse
     * personne pour en répondre, ni rien pour la contester.
     */
    public static function setJournal(?Journal $journal): void
    {
        self::$journal = $journal;
    }

    /** Le journal branché, pour qui doit le suspendre le temps d'une simulation. */
    public static function journal(): ?Journal
    {
        return self::$journal;
    }

    /**
     * Une peine échue dont le journal a refusé la fin attend sa clôture
     * (`journal_en_panne`) : la base la garde, alors que la lecture la dit
     * finie. Ni un ban, ni une grâce, ni un classement ne passe par-dessus — le
     * ban la recouvrirait, la grâce la refermerait sans que sa fin soit écrite
     * nulle part, le classement effacerait le seul signal qui en reste. Le ban
     * automatique tient la même règle.
     *
     * @param array{fin_en_attente: bool} $etat
     * @return array{ok: false, message: string}|null
     */
    private static function refusFinEnAttente(array $etat): ?array
    {
        return $etat['fin_en_attente']
            ? ['ok' => false, 'message' => static::t('La peine précédente attend que le journal inscrive sa fin : aucun geste ne passe avant.')]
            : null;
    }

    /** Le bannissement automatique est-il armé sur ce déploiement ? Seulement en démonstration. */
    public static function banAutomatiqueArme(): bool
    {
        return self::$journal !== null && self::config()->usage === Usage::DEMONSTRATION;
    }

    /**
     * Le refus commun aux gestes d'arbitre quand aucun journal n'est
     * branché. Un geste d'arbitre porte un nom et s'écrit au journal, comme le
     * ban que la machine prononce seule ; celui qui passerait sans trace est
     * celui qui efface — une grâce remet la réputation au départ et les strikes
     * à zéro.
     *
     * @return array{ok: false, message: string}|null
     */
    private static function refusSansJournal(): ?array
    {
        return self::$journal === null
            ? ['ok' => false, 'message' => static::t('Aucun journal de modération n\'est branché : un geste d\'arbitre ne se fait pas sans trace.')]
            : null;
    }

    /**
     * Un geste d'arbitre ne se fait pas dans la transaction d'un hôte : il
     * rendrait `ok`, l'hôte pourrait encore annuler, et le journal dirait un
     * ban ou une grâce que la base n'a jamais vu.
     *
     * @return array{ok: false, message: string}|null
     */
    private static function refusTransactionDeLHote(PDO $pdo): ?array
    {
        return self::dansLaTransactionDeLHote($pdo)
            ? ['ok' => false, 'message' => static::t('Un geste d\'arbitre ne se fait pas dans la transaction de l\'hôte : le journal ne se défait pas avec elle.')]
            : null;
    }

    /** Crée la ligne de modération si absente. */
    public static function ensureRow(PDO $pdo, int $accountId): void
    {
        $pdo->prepare(
            'INSERT OR IGNORE INTO member_moderation (account_id, reputation, updated_at) VALUES (?, ?, ?)'
        )->execute([$accountId, self::config()->reputationInitiale, self::maintenant()]);
    }

    /**
     * L'état d'un compte, tel que l'heure l'établit. Une lecture n'écrit rien,
     * ni en base ni au journal, et ne prend aucun verrou : une ligne absente se
     * lit comme les valeurs de départ, une convalescence avec les points que le
     * temps lui a rendus, une peine échue comme finie — que la base le porte
     * déjà ou pas encore. Ce que le temps a fait s'inscrit à la première
     * écriture qui touche le compte (`rattraper()`), ou au balayage ; tant que
     * ce n'est pas fait, `fin_en_attente` le dit. Un écrivain du moteur lit par
     * `etat()`, après avoir rattrapé : décider sur ce que la base ne porte pas
     * encore perdrait en silence ce qu'elle seule aurait inscrit.
     */
    public static function getReputation(PDO $pdo, int $accountId): array
    {
        if (self::$ecritures > 0) {
            throw new LectureDansUneEcriture('SelfModerate : un écrivain du moteur lit l\'état par etat(), sous son verrou — getReputation() n\'inscrit rien.');
        }
        $t       = self::maintenant();
        $row     = self::ligne($pdo, $accountId);
        $avance  = self::avancer($row, $t);
        $signaux = $row !== null && (int) $row['signale'] ? self::signauxDe($pdo, $accountId) : [];
        // Les états que le temps a fait cesser tombent de la lecture ; la panne du
        // journal, que seule une inscription réussie lève, reste.
        $tombent = array_diff($avance['tombent'], ['journal_en_panne']);
        $signaux = array_values(array_filter($signaux, static fn (array $s): bool => !in_array($s['cause'], $tombent, true)));

        return self::presenter($avance['ligne'], $signaux, $avance['fin'] !== null, $t);
    }

    /** Le nombre d'écritures du moteur en cours : `transaction()` le tient. */
    private static int $ecritures = 0;

    /**
     * L'état d'un compte pour qui va écrire : ce que le temps a fait, inscrit sous
     * le verrou, puis la ligne telle que la base la porte. `fin_en_attente` y dit
     * qu'une fin de peine n'a pas pu s'inscrire — le journal l'a refusée.
     */
    private static function etat(PDO $pdo, int $accountId): array
    {
        if (self::$ecritures === 0) {
            throw new \LogicException('SelfModerate : etat() se lit sous le verrou d\'écriture, après rattrapage.');
        }
        // Une seule heure pour tout l'état : lue deux fois, une échéance tombée entre
        // les deux lectures se dirait ni en cours ni en attente de sa fin.
        $t = self::maintenant();
        self::rattraper($pdo, $accountId, contenir: true, t: $t);
        $row     = self::ligne($pdo, $accountId);
        $signaux = (int) $row['signale'] ? self::signauxDe($pdo, $accountId) : [];
        $jusqua  = (int) $row['banned_until'];

        return self::presenter($row, $signaux, $jusqua > 0 && $jusqua <= $t, $t);
    }

    private static function presenter(array $row, array $signaux, bool $finEnAttente, int $t): array
    {
        return [
            'reputation'    => (int) $row['reputation'],
            'strikes'       => (int) $row['strikes'],
            'strikes_admin' => (int) $row['strikes_admin'],
            'voting_rights' => (bool) $row['voting_rights'],
            'banned'        => ((int) $row['banned_until']) > $t,
            'banned_until'  => (int) $row['banned_until'],
            'ban_debut'     => (int) $row['ban_debut'],
            'ban_origine'   => $row['ban_origine'] !== null ? (string) $row['ban_origine'] : null,
            'ban_motif'     => $row['ban_motif'] !== null ? (string) $row['ban_motif'] : null,
            'needs_review'  => self::projection($signaux) !== null,
            'review_reason' => self::projection($signaux),
            'signaux'       => $signaux,
            'convalescent'  => (bool) $row['convalescent'],
            'vote_muted'       => ((int) $row['vote_muted_until']) > $t,
            'vote_muted_until' => (int) $row['vote_muted_until'],
            'fin_en_attente'   => $finEnAttente,
        ];
    }

    /**
     * La ligne de modération d'un compte, ou null s'il n'en a pas encore. Elle
     * dit si un signal est ouvert sur lui, sans le lire : un compte calme se lit
     * en une requête, et la lecture ne se fie pas au résumé de la case. Elle le
     * confronte seulement aux tables : une case ouverte sans aucun signal, un
     * ban en cours sans sa ligne dans `mod_peines`, ou dont la ligne ouverte
     * porte un autre début ou une autre échéance, dit qu'une reprise n'a pas été
     * faite, et la lecture refuse.
     */
    private static function ligne(PDO $pdo, int $accountId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT reputation, strikes, strikes_admin, voting_rights, banned_until, ban_debut, ban_origine, ban_motif,
                    convalescent, last_regen_at, vote_muted_until, needs_review,
                    EXISTS (SELECT 1 FROM mod_signaux s WHERE s.account_id = m.account_id) AS signale,
                    EXISTS (SELECT 1 FROM mod_peines p WHERE p.account_id = m.account_id AND p.fin IS NULL) AS peine_ouverte,
                    EXISTS (SELECT 1 FROM mod_peines p WHERE p.account_id = m.account_id AND p.fin IS NULL
                               AND p.debut = m.ban_debut AND p.echeance = m.banned_until) AS peine_accordee
               FROM member_moderation m WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        if ($row !== false && (int) $row['needs_review'] && !(int) $row['signale']) {
            throw self::repriseManquante($accountId);
        }
        if ($row !== false && (int) $row['banned_until'] > 0 && !(int) $row['peine_accordee']) {
            if ((int) $row['peine_ouverte']) {
                throw self::repriseManquante($accountId, 'divergente');
            }
            $fermee = $pdo->prepare('SELECT 1 FROM mod_peines WHERE account_id = ? AND debut = ? AND debut > 0');
            $fermee->execute([$accountId, (int) $row['ban_debut']]);
            $reprise = $fermee->fetchColumn() !== false ? 'fermee' : 'peines';
            $fermee->closeCursor();
            throw self::repriseManquante($accountId, $reprise);
        }

        return $row === false ? null : $row;
    }

    /**
     * Ce que le temps a fait à une ligne, à l'instant `$t`, sans rien écrire :
     * la remontée de convalescence, puis la fin d'une peine échue. La lecture
     * le rend, `rattraper()` l'inscrit : une seule source pour les deux, et ce
     * qu'une page montre est ce que la première écriture inscrira.
     *
     * 🔑 **Personne ne s'exécute à l'instant où une peine expire.** La fin d'un
     * ban automatique remet la réputation au point de départ et garde les
     * strikes : le temps de la peine vaut remise à flot, et le ban suivant sera
     * plus long. Un ban d'arbitre n'est pas venu de la réputation, et n'y touche
     * pas en sortant. La convalescence rend un point par intervalle écoulé et
     * borne sa propre progression, pas le score ; revenue au point de départ,
     * elle se lève, et avec elle le signalement de la chute — lui seul : un
     * comportement ne se rachète pas en attendant.
     *
     * Rend aussi ce que l'inscription aura à faire : la fin à journaliser, les
     * signaux d'état qui tombent, les effets de vote à effacer, une horloge de
     * convalescence à poser.
     *
     * @return array{ligne: array, fin: ?array, tombent: list<string>, tombent_regen: list<string>,
     *               tombent_fin: list<string>, effacer: bool, horloge: bool}
     */
    private static function avancer(?array $row, int $t, bool $clore = true): array
    {
        $l = $row ?? [
            'reputation' => self::config()->reputationInitiale, 'strikes' => 0, 'strikes_admin' => 0, 'voting_rights' => 1,
            'banned_until' => 0, 'ban_debut' => 0, 'ban_origine' => null, 'ban_motif' => null,
            'convalescent' => 0, 'last_regen_at' => 0, 'vote_muted_until' => 0,
        ];
        $tombentRegen = [];
        $tombentFin   = [];
        $effacer      = false;
        $solder       = false;
        $horloge      = false;
        $fin          = null;

        if ((int) $l['convalescent']) {
            $last   = (int) $l['last_regen_at'];
            $gagnes = 0;
            if ($last <= 0) {
                // Base migrée : l'état existe sans son horloge. La première écriture la
                // pose, et le compte part de là.
                $horloge = true;
            } else {
                $gagnes = intdiv($t - $last, self::config()->intervalleConvalescenceSecondes);
            }
            $depart = (int) $l['reputation'];
            // Les seuils se lisent sur le score, quel que soit le chemin qui l'y a porté :
            // des votes positifs rendent le droit de vote et lèvent l'état sans attendre
            // le point suivant.
            if ($gagnes >= 1 || $depart >= self::config()->perteDroitDeVoteSous) {
                // La remontée borne sa propre progression, pas le score : un point gagné
                // par un vote positif pendant la convalescence reste acquis.
                $rep = max($depart, min(self::config()->sortieConvalescence(), $depart + $gagnes));
                $l['reputation'] = $rep;
                // L'horloge avance des intervalles consommés, pas jusqu'à maintenant : le
                // reste de temps est acquis et compte pour le point suivant.
                $l['last_regen_at'] = $last + $gagnes * self::config()->intervalleConvalescenceSecondes;
                if ($rep > self::config()->banA) {
                    $tombentRegen[] = 'ban_en_attente';
                }
                if ($rep >= self::config()->perteDroitDeVoteSous) {
                    $l['voting_rights'] = 1;
                    array_push($tombentRegen, 'droit_de_vote_perdu', 'perte_du_vote_due', 'chute_sans_quorum');
                }
                if ($rep >= self::config()->sortieConvalescence()) {
                    $l['convalescent'] = 0;
                    array_push($tombentRegen, 'reputation_zero', 'ban_en_attente');
                    $effacer           = true;
                }
            }
        }

        $jusqua = (int) $l['banned_until'];
        if ($clore && $jusqua > 0 && $jusqua <= $t) {
            $fin = [
                'origine' => $l['ban_origine'] !== null ? (string) $l['ban_origine'] : null,
                'episode' => (int) $l['strikes'],
                'debut'   => (int) $l['ban_debut'],
                'jusqu_a' => $jusqua,
                'motif'   => $l['ban_motif'] !== null ? (string) $l['ban_motif'] : null,
            ];
            if ($fin['origine'] === 'auto') {
                // La chute et le ban qu'elle a causé cessent ensemble.
                $l['reputation']    = self::config()->reputationInitiale;
                $l['voting_rights'] = 1;
                $l['convalescent']  = 0;
                $l['last_regen_at'] = 0;
                $effacer            = true;
                $solder             = true;
                array_push($tombentFin, 'ban_auto', 'reputation_zero');
            } elseif ((int) $l['reputation'] >= self::config()->perteDroitDeVoteSous) {
                $l['voting_rights'] = 1;
            }
            if ((int) $l['voting_rights'] === 1) {
                $tombentFin[] = 'droit_de_vote_perdu';
            }
            if ((int) $l['reputation'] >= self::config()->perteDroitDeVoteSous) {
                array_push($tombentFin, 'chute_sans_quorum', 'perte_du_vote_due');
            }
            $tombentFin[]      = 'journal_en_panne';
            $l['banned_until'] = 0;
            $l['ban_debut']    = 0;
            $l['ban_origine']  = null;
            $l['ban_motif']    = null;
        }

        return [
            'ligne'         => $l,
            'fin'           => $fin,
            'tombent'       => array_values(array_unique([...$tombentRegen, ...$tombentFin])),
            'tombent_regen' => $tombentRegen,
            'tombent_fin'   => $tombentFin,
            'effacer'       => $effacer,
            'solder'        => $solder,
            'horloge'       => $horloge,
        ];
    }

    /**
     * Inscrit ce que le temps a fait à un compte, sous le verrou d'écriture : ce
     * qu'`avancer()` rend, et rien d'autre. Une ligne absente se crée. Rend vrai
     * s'il a refermé une peine, faux s'il n'y en avait aucune à refermer, et
     * `null` si le journal en a refusé la fin et que le refus est contenu.
     *
     * La fin d'une peine va au journal AVANT la base, avec l'échéance et l'instant
     * où elle s'inscrit : l'écriture qui la constate, jamais une page.
     * `banned_until = 0` est l'état « aucune peine à clore », décidé sous le
     * verrou : un seul passage la voit à faire. Défaite avec la transaction qui
     * la porte, elle se refait au passage suivant, et le journal porte alors deux
     * fins pour une peine, que `debut` et `jusqu_a` identifient : `applyVote()`
     * rattrape donc le votant et la cible avant d'ouvrir sa transaction.
     *
     * ⚠️ **Un journal qui refuse d'écrire laisse la peine à refermer.** Avec
     * `$contenir`, le refus est consigné et signalé (`journal_en_panne`), seule la
     * remontée de convalescence s'inscrit, et l'appel continue ; la fin attend le
     * retour du journal, et tant qu'elle attend, aucune écriture ne décide sur
     * elle. Sans `$contenir`, le refus remonte. Une `LectureDansUneEcriture`
     * remonte toujours : c'est une erreur d'intégration — un journal qui
     * rappelle le moteur —, pas une panne du journal.
     */
    private static function rattraper(PDO $pdo, int $accountId, bool $contenir, bool $clore = true, bool $creer = true, ?int $t = null): ?bool
    {
        $row = self::ligne($pdo, $accountId);
        if ($row === null) {
            if ($creer) {
                self::ensureRow($pdo, $accountId);
            }

            return false;
        }
        $t     ??= self::maintenant();
        $avance  = self::avancer($row, $t, $clore);
        $refusee = false;
        if ($avance['fin'] !== null) {
            $signaux = self::signauxDe($pdo, $accountId);
            try {
                self::inscrireAuJournal([
                    'acte'      => 'ban_fin',
                    'compte'    => $accountId,
                    'origine'   => $avance['fin']['origine'],
                    'episode'   => $avance['fin']['episode'],
                    'debut'     => $avance['fin']['debut'],
                    'jusqu_a'   => $avance['fin']['jusqu_a'],
                    'observe_a' => $t,
                    'motif'     => $avance['fin']['motif'] ?? self::projection($signaux) ?? 'inconnu',
                    'arbitre'   => null,
                    'arbitre_id' => null,
                    'ouverts'   => self::causesEnTexte($signaux),
                    'ferme'     => self::fermesEnTexte(self::deCause($signaux, ...$avance['tombent_fin'])),
                ]);
            } catch (\Throwable $e) {
                if (!$contenir || $e instanceof LectureDansUneEcriture) {
                    throw $e;
                }
                error_log(sprintf('SelfModerate : fin de peine du compte %d non inscrite au journal — %s', $accountId, $e->getMessage()));
                self::signaler($pdo, $accountId, 'journal_en_panne');
                // Un signal d'état ne bouge plus une fois posé (`poserSignal()`) : ici,
                // sa date dit le dernier refus, et le balayage passe d'abord le compte
                // refusé depuis le plus longtemps.
                $pdo->prepare("UPDATE mod_signaux SET derniere_le = ? WHERE account_id = ? AND cause = 'journal_en_panne'")
                    ->execute([$t, $accountId]);
                $avance = self::avancer($row, $t, clore: false);
                $refusee = true;
            }
        }
        $l = $avance['ligne'];
        if ($avance['horloge']) {
            $l['last_regen_at'] = $t;
        }
        $colonnes = self::colonnesChangees($l, $row);
        if ($colonnes !== []) {
            $poses = implode(', ', array_map(static fn (string $col): string => "$col = ?", $colonnes));
            $pdo->prepare("UPDATE member_moderation SET $poses, updated_at = ? WHERE account_id = ?")
                ->execute([...array_map(static fn (string $col): mixed => $l[$col], $colonnes), $t, $accountId]);
        }
        if ($avance['fin'] !== null) {
            self::fermerPeine($pdo, $accountId, $avance['fin']['jusqu_a'], 'echeance', null);
        }
        if ($avance['effacer']) {
            self::effacerEffets($pdo, $accountId, $avance['solder']);
        }
        self::designaler($pdo, $accountId, ...$avance['tombent']);

        return $refusee ? null : $avance['fin'] !== null;
    }

    /** Les colonnes de la ligne lue que l'état avancé change. */
    private static function colonnesChangees(array $l, array $row): array
    {
        return array_values(array_filter(
            ['reputation', 'voting_rights', 'convalescent', 'last_regen_at', 'banned_until', 'ban_debut', 'ban_origine', 'ban_motif'],
            static fn (string $col): bool => (string) $l[$col] !== (string) $row[$col]
        ));
    }

    /**
     * Rattrape un compte dans sa propre transaction, avant que l'écriture qui le
     * touche ouvre la sienne — et seulement s'il y a quelque chose à inscrire. Un
     * refus que la lecture décide (droit de vote, motif) ne prend pas le verrou
     * d'écriture : sous un autre écrivain, il rendrait une exception au lieu du
     * refus. Ce qui est dû se relit sous le verrou avant de s'inscrire. Une ligne
     * absente n'a rien à inscrire : l'écriture qui en a besoin la crée sous son
     * verrou (`etat()`).
     *
     * Dans la transaction d'un hôte, le verrou se prend avant toute lecture : une
     * transaction différée qui a déjà lu ne peut plus attendre le verrou, et
     * SQLite la refuse aussitôt quand un autre écrit.
     */
    private static function rattraperSeul(PDO $pdo, int $accountId): void
    {
        if (!self::dansLaTransactionDeLHote($pdo)) {
            $row = self::ligne($pdo, $accountId);
            if ($row === null) {
                return;
            }
            $avance = self::avancer($row, self::maintenant());
            if ($avance['fin'] === null && !$avance['horloge'] && $avance['tombent'] === []
                && self::colonnesChangees($avance['ligne'], $row) === []) {
                return;
            }
        }
        self::transaction($pdo, static fn (): ?bool => self::rattraper($pdo, $accountId, contenir: true, creer: false));
    }

    /**
     * Inscrit en une passe ce que le temps a fait : les peines échues, au journal
     * d'abord, et les convalescences dues. Pour un planificateur, la console d'un
     * arbitre — qui lit la base et l'appelle avant de la lire — et le filet du
     * vote (`applyVote()`). Sans eux, une fin s'inscrit à la première écriture
     * qui touche le compte ; la lecture la montre dès l'échéance.
     *
     * Rend le nombre de peines refermées. Sans journal, elles se referment
     * quand même, sans rien inscrire. Un journal qui refuse la fin d'un compte
     * n'arrête pas la passe : le compte est signalé (`journal_en_panne`), et le
     * refus remonte à la fin, pour le planificateur et la console. Après ce
     * premier refus, la passe n'appelle plus le journal — une panne se paie une
     * fois par passe, pas une fois par compte : les convalescences s'inscrivent
     * encore, les fins attendent la passe suivante, où les comptes déjà signalés
     * passent en dernier, le plus anciennement refusé d'abord : un compte que le
     * journal refuse à chaque passe ne retient pas ceux qui le suivent.
     *
     * Un compte dont la reprise d'avant la 0.7.0 manque (`RepriseManquante`)
     * n'arrête pas la passe non plus : il est sauté, et levé à la fin, à la place
     * d'un refus du journal, qui reste signalé sur son compte. Hors de la transaction d'un hôte seulement : une fin y
     * serait inscrite au journal et survivrait à l'annulation que l'hôte décide.
     */
    public static function balayerBansEchus(PDO $pdo, int $limite = 500): int
    {
        if (self::dansLaTransactionDeLHote($pdo)) {
            throw new \LogicException('SelfModerate : balayerBansEchus() s\'appelle hors de la transaction de l\'hôte — une fin inscrite au journal y survivrait à son annulation.');
        }
        $stmt = $pdo->prepare(
            "SELECT account_id FROM member_moderation m
              WHERE (banned_until > 0 AND banned_until <= :t)
                 OR (convalescent = 1 AND (last_regen_at <= 0 OR last_regen_at <= :t - :i OR reputation >= :sortie
                                           OR (reputation >= :vote AND (voting_rights = 0 OR EXISTS (
                                                   SELECT 1 FROM mod_signaux s WHERE s.account_id = m.account_id AND s.cause IN ('chute_sans_quorum', 'perte_du_vote_due'))))))
           ORDER BY EXISTS (SELECT 1 FROM mod_signaux s WHERE s.account_id = m.account_id AND s.cause = 'journal_en_panne'),
                    (SELECT s.derniere_le FROM mod_signaux s WHERE s.account_id = m.account_id AND s.cause = 'journal_en_panne'),
                    banned_until = 0, banned_until
              LIMIT :n"
        );
        $stmt->bindValue('t', self::maintenant(), PDO::PARAM_INT);
        $stmt->bindValue('i', self::config()->intervalleConvalescenceSecondes, PDO::PARAM_INT);
        $stmt->bindValue('sortie', self::config()->sortieConvalescence(), PDO::PARAM_INT);
        $stmt->bindValue('vote', self::config()->perteDroitDeVoteSous, PDO::PARAM_INT);
        $stmt->bindValue('n', $limite, PDO::PARAM_INT);
        $stmt->execute();

        $clos        = 0;
        $refuse      = null;
        $sansReprise = null;
        foreach ($stmt->fetchAll() as $row) {
            try {
                $close = self::transaction($pdo, static fn (): ?bool => self::rattraper($pdo, (int) $row['account_id'], contenir: true, clore: $refuse === null));
            } catch (RepriseManquante $e) {
                $sansReprise ??= $e;
                continue;
            }
            if ($close === null) {
                $refuse = (int) $row['account_id'];
            } elseif ($close) {
                $clos++;
            }
        }
        if ($sansReprise !== null) {
            throw $sansReprise;
        }
        if ($refuse !== null) {
            throw new \RuntimeException(sprintf(
                'SelfModerate : le journal a refusé la fin de peine du compte %d, après %d inscrite(s) ; les fins suivantes attendent la passe suivante, la cause est au journal d\'erreurs.',
                $refuse,
                $clos
            ));
        }

        return $clos;
    }

    /**
     * Convalescence — inscrit la remontée que le temps écoulé donne au compte,
     * sans refermer de peine. La lecture la montre sans l'inscrire.
     */
    public static function regenerate(PDO $pdo, int $accountId): void
    {
        self::transaction($pdo, static fn (): bool =>
            self::ligne($pdo, $accountId) !== null && self::rattraper($pdo, $accountId, contenir: true, clore: false));
    }

    /**
     * La réputation est revenue au départ : ce que les votes déjà reçus avaient
     * retiré est rendu. Leur effet passe à zéro, pour qu'une annulation ultérieure
     * ne le rende pas une seconde fois.
     *
     * `$solder` : une remise — la fin d'un ban automatique, échu ou levé, une grâce — a rendu
     * d'un coup le départ, et la chute est soldée : ses votants ne sont plus des
     * voix au quorum de la suivante (`chute_soldee_le`, lu par `fenetreDesVoix()`).
     * La sortie de convalescence ne solde rien : une remontée que des soutiens
     * peuvent hâter n'efface pas les voix des critiques.
     */
    private static function effacerEffets(PDO $pdo, int $accountId, bool $solder): void
    {
        $pdo->prepare('UPDATE mod_votes SET effet = 0 WHERE target_author = ? AND (effet IS NULL OR effet <> 0)')
            ->execute([$accountId]);
        if ($solder) {
            $pdo->prepare('UPDATE member_moderation SET chute_soldee_le = ? WHERE account_id = ?')
                ->execute([self::maintenant(), $accountId]);
        }
    }

    /** Anti-Sybil + sanctions : ce membre peut-il voter ? Retourne [bool, raison]. */
    public static function canVote(PDO $pdo, int $accountId): array
    {
        $rep = self::getReputation($pdo, $accountId);
        if ($rep['banned']) {
            return [false, self::estSansFin($rep['banned_until'])
                ? static::t('Compte banni sans fin.')
                : sprintf(
                    $rep['ban_origine'] === 'auto' ? static::t('Compte banni jusqu\'au %s au plus tard.') : static::t('Compte banni jusqu\'au %s.'),
                    date('d/m/Y H:i', self::finAffichee($rep['banned_until'], $rep['ban_origine']))
                )];
        }
        // Avant le seuil de réputation, et non après : les deux refusent le
        // vote, mais l'un s'efface en attendant et l'autre à une date. Rendre
        // « réputation trop basse » à quelqu'un qui purge une suspension
        // l'enverrait guetter une remontée qui ne lui rendra rien.
        if ($rep['vote_muted']) {
            return [false, sprintf(
                static::t('Droit de vote suspendu par un arbitre jusqu\'au %s.'),
                date('d/m/Y H:i', $rep['vote_muted_until'])
            )];
        }
        if (!$rep['voting_rights']) {
            return [false, static::t('Droit de vote retiré (réputation trop basse).')];
        }
        // Anti-Sybil : un compte récent ne vote que s'il a assez publié
        // (`messagesPourVoterAvantAge`). Un compte
        // absent ou une date qui n'est pas un horodatage ne se convertissent pas :
        // `(int)` en ferait 0 — un compte né en 1970 — ou l'année d'une date ISO,
        // et l'un comme l'autre passerait pour très ancien.
        $stmt = $pdo->prepare('SELECT created_at FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $cree = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($cree === false) {
            return [false, static::t('Compte introuvable.')];
        }
        if (!is_int($cree) && !(is_string($cree) && ctype_digit($cree))) {
            return [false, static::t('Date de création du compte illisible : le vote attend un horodatage en secondes.')];
        }
        $createdAt = (int) $cree;
        $age = self::maintenant() - $createdAt;
        $ageMin = self::config()->ageMinPourVoterSecondes;
        if ($age < $ageMin) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE account_id = ?');
            $stmt->execute([$accountId]);
            $nbPosts = (int) $stmt->fetchColumn();
            $dispense = self::config()->messagesPourVoterAvantAge;
            if ($dispense === 0 || $nbPosts < $dispense) {
                $attente = static::dureeEnClair($ageMin - $age);

                return [false, match (true) {
                    $dispense === 0 => sprintf(static::t('Compte trop récent : attends encore %s pour pouvoir voter (anti-Sybil).'), $attente),
                    $dispense === 1 => sprintf(static::t('Compte trop récent : publie au moins un message ou attends encore %s pour pouvoir voter (anti-Sybil).'), $attente),
                    default         => sprintf(static::t('Compte trop récent : publie au moins %1$d messages ou attends encore %2$s pour pouvoir voter (anti-Sybil).'), $dispense, $attente),
                }];
            }
        }
        return [true, ''];
    }

    /**
     * Le motif dit-il quelque chose ? Cinq mesures, parce qu'une seule se
     * contourne : en bloquant une touche on atteint n'importe quelle longueur.
     * Le message rendu nomme ce qu'il faut corriger — un refus opaque pousse au
     * remplissage plutôt qu'à l'écriture.
     *
     * @return array{0: bool, 1: string}
     */
    public static function validateReason(?string $reason): array
    {
        $texte = trim((string) $reason);
        // Un motif est lu par l'arbitre et inscrit au journal : des octets qui ne
        // forment pas de l'UTF-8 se refusent ici, avant que la base les garde.
        if (preg_match('//u', $texte) !== 1) {
            return [false, static::t('Ce motif n\'est pas du texte lisible : réécris-le.')];
        }
        $n = mb_strlen($texte, 'UTF-8');
        if ($n < self::REASON_MIN_CHARS) {
            return [false, sprintf(
                static::t('Explique en %d caractères au moins : il en manque %d.'),
                self::REASON_MIN_CHARS,
                self::REASON_MIN_CHARS - $n
            )];
        }
        if ($n > self::REASON_MAX_CHARS) {
            return [false, sprintf(
                static::t('Motif trop long : %d caractères au plus, il y en a %d.'),
                self::REASON_MAX_CHARS,
                $n
            )];
        }

        $plat = strtr(mb_strtolower($texte, 'UTF-8'), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae',
        ]);

        if (preg_match('/(.)\1{' . self::REASON_MAX_RUN . ',}/u', $plat)) {
            return [false, static::t('Un caractère est répété en rafale : écris une phrase.')];
        }

        $lettres = preg_replace('/\s+/u', '', $plat) ?? '';
        $distincts = count(array_unique(preg_split('//u', $lettres, -1, PREG_SPLIT_NO_EMPTY) ?: []));
        if ($distincts < self::REASON_MIN_DISTINCT_CHARS) {
            return [false, static::t('Ce motif tourne sur trop peu de caractères différents.')];
        }

        $mots = preg_split('/[^\p{L}\p{N}]+/u', $plat, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $comptes = array_count_values($mots);
        if (count($comptes) < self::REASON_MIN_WORDS) {
            return [false, sprintf(
                static::t('Il faut au moins %d mots différents.'),
                self::REASON_MIN_WORDS
            )];
        }
        $n = count($mots);
        foreach ($comptes as $mot => $fois) {
            // (string) : array_count_values() rend une clé entière pour « 3 ».
            $unSur = mb_strlen((string) $mot, 'UTF-8') >= self::REASON_CARRIER_MIN_LEN
                ? self::REASON_CARRIER_ONE_IN
                : self::REASON_SHORT_ONE_IN;
            if ($fois > max(self::REASON_MAX_WORD_REPEATS, intdiv($n, $unSur))) {
                return [false, static::t('Un mot revient trop souvent : dis ce que tu reproches.')];
            }
        }

        return [true, ''];
    }

    /** Résout l'auteur dont la réputation est affectée par un vote. */
    private static function resolveAuthor(PDO $pdo, string $targetType, int $targetId): ?int
    {
        if ($targetType === 'member') {
            $stmt = $pdo->prepare('SELECT id FROM accounts WHERE id = ?');
            $stmt->execute([$targetId]);
            $id = $stmt->fetchColumn();
            return $id === false ? null : (int) $id;
        }
        // post → auteur du post
        $stmt = $pdo->prepare('SELECT account_id FROM posts WHERE id = ?');
        $stmt->execute([$targetId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Inscrit un vote que le moteur neutralise : il reste visible de l'arbitre
     * et occupe sa place dans l'index unique, mais n'a aucun effet sur la
     * réputation.
     */
    private static function inscrireVoteNeutralise(
        PDO $pdo,
        int $voterId,
        string $targetType,
        int $targetId,
        int $author,
        int $value,
        ?string $reason,
        ?string $reasonCode,
        string $raison
    ): void {
        $pdo->prepare(
            'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, reason_code, blocked, blocked_reason, effet, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, 0, ?)'
        )->execute([$voterId, $targetType, $targetId, $author, $value, $reason, $reasonCode, $raison, self::maintenant()]);
    }

    /**
     * Applique un vote. Retourne ['ok'=>..., 'blocked'=>..., 'message'=>...] — jamais la
     * réputation de la cible : la page de profil tait ce chiffre sous le seuil de
     * sanction, et la réponse d'un vote le rendrait à quiconque vote.
     *
     * Le motif est exigé au downvote et facultatif à l'upvote : il existe pour
     * que la personne sanctionnée sache ce qu'on lui reproche, et un pouce en
     * l'air ne sanctionne personne. L'upvote reste tenu par son plafond de
     * `farmingUpvotesMax` sur la fenêtre.
     *
     * Tout s'inscrit ou rien : le vote, son effet sur la réputation et ce que
     * la détection en tire tiennent dans une transaction — celle du moteur,
     * ouverte s'il n'y en a pas, ou un point de reprise dans celle de l'hôte,
     * qui reste maître de la sienne. Ce qui refuse sans rien écrire se décide
     * avant : paramètres, droit de vote, motif, cible — sauf dans la transaction
     * d'un hôte, où le rattrapage du votant prend d'abord le verrou d'écriture,
     * refus compris — sauf celui de paramètres invalides —, et l'hôte le garde
     * jusqu'à sa fin (`rattraperSeul()`).
     *
     * Le journal s'écrit hors de la base, avant elle : une validation qui
     * échoue après lui laisse une entrée sans effet, jamais une peine sans
     * trace. Sous la transaction du vote ne s'écrit que l'entrée que ce vote
     * cause — le début d'un ban —, et une entrée défaite avec lui se lit comme
     * une entrée sans effet : l'acte se réinscrit au passage suivant. Une peine échue du votant ou de la cible
     * se referme AVANT que la transaction s'ouvre : défaite avec elle, sa
     * clôture se referait, et le journal porterait deux fins pour une peine.
     */
    public static function applyVote(
        PDO $pdo,
        int $voterId,
        string $targetType,
        int $targetId,
        int $value,
        ?string $reason = null,
        ?string $reasonCode = null
    ): array {
        $reason = $reason !== null ? trim($reason) : null;
        $refus  = null;
        $author = self::cibleDuVote($pdo, $voterId, $targetType, $targetId, $value, $reason, $reasonCode, $refus);
        if ($author === null) {
            return $refus;
        }

        self::$trancheBalayee = false;
        $resultat = self::transaction($pdo, static fn (): array =>
            self::inscrireVote($pdo, $voterId, $targetType, $targetId, $author, $value, $reason, $reasonCode));
        // Le filet sans planificateur : le vote qui a pris la tranche de balayage
        // inscrit ensuite, hors de sa transaction et jamais dans celle d'un hôte,
        // ce que le temps a fait aux comptes que personne ne touche. Le vote est
        // déjà validé : un refus du journal ne le défait pas, il se dit.
        if (self::$trancheBalayee && !self::enTransaction($pdo)) {
            try {
                self::balayerBansEchus($pdo, self::config()->balayageFiletMax);
            } catch (\Throwable $e) {
                error_log('SelfModerate : le balayage du filet laisse des fins à inscrire — ' . $e->getMessage());
            }
        }

        return $resultat;
    }

    /** Ce vote a-t-il pris la tranche de balayage ? `balayageDu()` le pose. */
    private static bool $trancheBalayee = false;

    /** Un nom par point de reprise posé dans la transaction d'un hôte. */
    private static int $pointsDeReprise = 0;

    /** La connexion sur laquelle ce moteur tient une transaction qu'il a ouverte lui-même. */
    private static ?PDO $transactionOuverteSur = null;

    /** Les connexions dont le schéma est vérifié (`exigerSchema()`). */
    private static ?\WeakMap $schemaVerifie = null;

    /** Les connexions dont l'index de la paire est vérifié (`exigerIndexPaire()`). */
    private static ?\WeakMap $indexVerifie = null;

    /**
     * L'exception après laquelle la base a défait une transaction du moteur, ou
     * celle qui contenait son point de reprise : ce n'est pas un refus, et rien
     * ne s'écrit plus sous elle (`surAutrui()`).
     */
    private static ?\Throwable $transactionDefaitePar = null;

    /** Une transaction court-elle sur cette connexion, la nôtre ou celle de l'hôte ? */
    private static function enTransaction(PDO $pdo): bool
    {
        return self::$transactionOuverteSur === $pdo || $pdo->inTransaction();
    }

    /**
     * La transaction ouverte sur cette connexion est-elle celle de l'hôte ? Le
     * journal ne se défait pas avec elle : un acte que le moteur y inscrirait
     * survivrait à une annulation que l'hôte décide après coup.
     */
    private static function dansLaTransactionDeLHote(PDO $pdo): bool
    {
        return $pdo->inTransaction() && self::$transactionOuverteSur !== $pdo;
    }

    /**
     * Une écriture tenue d'un bloc, sous le verrou d'écriture : la seule forme
     * de transaction du moteur.
     *
     * `BEGIN IMMEDIATE`, et non `BEGIN` : on lit avant d'écrire, et SQLite
     * refuse sans attendre à une transaction en lecture de passer en écriture
     * pendant qu'une autre écrit — deux écritures simultanées rendraient
     * « database is locked ». Le verrou pris d'emblée fait attendre, dans le
     * délai que PDO accorde, et l'état se relit sous lui avant toute décision
     * et toute écriture au journal. Les ordres passent en SQL, jamais par
     * `commit()` : toutes les versions de PDO ne voient pas une transaction
     * ouverte en SQL, et l'on ne valide que ce qu'on a ouvert soi-même — d'où
     * le marqueur `$transactionOuverteSur`. Dans une transaction déjà ouverte,
     * celle de l'hôte ou la nôtre, un point de reprise : l'écriture s'y défait
     * seule, sans décider pour la transaction qui la contient. Un point de
     * reprise ne prend aucun verrou, et la transaction d'un hôte est souvent
     * différée : une écriture qui ne touche rien le prend, avant toute lecture
     * qui décide et tout acte au journal. Si une autre connexion le tient, elle
     * l'attend dans le délai que PDO accorde ; si l'hôte a déjà lu dans sa
     * transaction, SQLite refuse aussitôt, et rien n'est inscrit.
     *
     * @template T
     * @param callable(): T $ecriture
     * @return T
     */
    private static function transaction(PDO $pdo, callable $ecriture): mixed
    {
        $propre = !self::enTransaction($pdo);
        $point  = $propre ? null : 'selfmoderate_' . ++self::$pointsDeReprise;
        self::ordonner($pdo, $propre ? 'BEGIN IMMEDIATE' : 'SAVEPOINT ' . $point);
        if ($propre) {
            self::$transactionOuverteSur = $pdo;
        }
        self::$ecritures++;
        try {
            if (!$propre) {
                self::ordonner($pdo, 'UPDATE member_moderation SET account_id = account_id WHERE 0');
            }
            self::exigerSchema($pdo);
            if (self::config()->quorumAuxSeuils && self::config()->messagerie === Messagerie::DM) {
                self::exigerIndexPaire($pdo);
            }
            $resultat = $ecriture();
            self::ordonner($pdo, $propre ? 'COMMIT' : 'RELEASE SAVEPOINT ' . $point);

            return $resultat;
        } catch (\Throwable $e) {
            foreach ($propre ? ['ROLLBACK'] : ['ROLLBACK TO SAVEPOINT ' . $point, 'RELEASE SAVEPOINT ' . $point] as $ordre) {
                try {
                    self::ordonner($pdo, $ordre);
                } catch (\Throwable) {
                    // La base a déjà défait la transaction : l'exception d'origine est celle qui compte.
                    // Un point de reprise disparu dit que la transaction qui le contenait l'est aussi.
                    self::$transactionDefaitePar = $e;
                }
            }
            throw $e;
        } finally {
            self::$ecritures--;
            if ($propre) {
                self::$transactionOuverteSur = null;
            }
        }
    }

    /**
     * La colonne de la 0.9.0 que le moteur écrit au solde d'une chute : absente,
     * l'écriture qui la touche échoue après l'acte qu'elle a déjà inscrit au
     * journal, et chaque essai en ajoute un. Vérifiée une fois par connexion, sous
     * le verrou de la première écriture — une lecture avant lui ferait refuser
     * aussitôt la transaction différée d'un hôte quand un autre écrit — et à la
     * première lecture des voix d'une chute.
     */
    private static function exigerSchema(PDO $pdo): void
    {
        self::$schemaVerifie ??= new \WeakMap();
        if (isset(self::$schemaVerifie[$pdo])) {
            return;
        }
        try {
            $lu = $pdo->query('SELECT chute_soldee_le FROM member_moderation LIMIT 0');
        } catch (\PDOException) {
            $lu = false;
        }
        if ($lu === false) {
            throw new \LogicException('SelfModerate : la base n\'a pas la colonne « member_moderation.chute_soldee_le » — la note de montée vers la 0.9.0 (README) l\'ajoute : ALTER TABLE member_moderation ADD COLUMN chute_soldee_le INTEGER NOT NULL DEFAULT 0.');
        }
        $lu->closeCursor();
        self::$schemaVerifie[$pdo] = true;
    }

    /** Un ordre de transaction, dont le refus ne passe jamais inaperçu, même sous un PDO silencieux. */
    private static function ordonner(PDO $pdo, string $ordre): void
    {
        if ($pdo->exec($ordre) === false) {
            throw new \RuntimeException('SelfModerate : l\'ordre « ' . $ordre . ' » a été refusé par la base.');
        }
    }

    /**
     * Ce qui refuse un vote sans rien écrire, et la cible qu'il vise. Rend
     * l'auteur dont la réputation est en jeu, ou null avec le refus dans
     * `$refus`. Les peines échues du votant et de la cible se referment ici,
     * hors de la transaction du vote (voir `applyVote()`).
     */
    private static function cibleDuVote(
        PDO $pdo,
        int $voterId,
        string $targetType,
        int $targetId,
        int $value,
        ?string $reason,
        ?string $reasonCode,
        ?array &$refus
    ): ?int {
        if (!in_array($targetType, ['post', 'member'], true) || !in_array($value, [-1, 1], true)) {
            $refus = ['ok' => false, 'message' => static::t('Paramètres de vote invalides.')];

            return null;
        }

        self::rattraperSeul($pdo, $voterId);
        [$can, $why] = self::canVote($pdo, $voterId);
        if (!$can) {
            $refus = ['ok' => false, 'message' => $why];

            return null;
        }
        // Seul l'état inscrit autorise une écriture : une page montre la peine
        // finie à son échéance, mais tant que le journal refuse d'en inscrire la
        // fin, le compte ne vote pas — et il sait pourquoi. La page ne le dit pas
        // d'avance : seul un essai sait si le journal est revenu.
        if (self::getReputation($pdo, $voterId)['fin_en_attente']) {
            $refus = ['ok' => false, 'message' => static::t('La fin de ta peine attend que le journal l\'inscrive : ton vote reprendra dès qu\'elle le sera.')];

            return null;
        }

        // 🔑 Le MOTIF se valide AVANT que la cible soit résolue, et c'est une
        // mesure de sûreté, pas de style.
        //
        // Dans l'ordre inverse — celui d'avant —, un `target_id` nu suffisait à
        // distinguer l'existant de l'inexistant : la cible introuvable rendait
        // « Cible introuvable », la cible existante rendait « il manque 40
        // caractères ». Deux réponses, aucun vote inscrit, aucun compteur touché,
        // aucune zone de lissage sur cette route : **l'espace des identifiants
        // internes se balayait en boucle, gratuitement**, y compris les comptes
        // qui n'ont jamais publié et qu'aucune autre route ne montre.
        //
        // Les trois autres refus ci-dessous ne divulguent rien sur un tiers — son
        // propre identifiant, et un vote qu'on sait avoir déposé —, donc ils
        // gardent leur message : un refus indistinct aurait coûté de
        // l'intelligibilité sans fermer quoi que ce soit de plus.
        //
        // ⚠️ Ce que cet ordre ferme exactement : sonder par un vote NÉGATIF exige un
        // motif VALIDE, et un motif valide sur une cible qui existe **inscrit le vote**.
        // La sonde devient donc tracée dans `mod_votes`, visible de l'arbitre, et
        // non répétable — l'index unique `(voter_id, target_type, target_id)` l'y
        // oblige. L'énumération n'est pas rendue impossible, elle est rendue
        // VISIBLE et coûteuse, ce qui vaut mieux sur un terrain où scanner est le
        // jeu : un balayage muet n'apprend rien à personne d'autre qu'à son auteur.
        // Le vote positif, dont le motif est facultatif, reste une sonde : il passe
        // sur un identifiant qui existe et refuse sur un autre, et donne un point à
        // la cible. Seul le plafond par paire le borne — limite déclarée au README.
        if ($value === -1 || ($reason !== null && $reason !== '')) {
            [$motifOk, $pourquoi] = self::validateReason($reason);
            if (!$motifOk) {
                $refus = ['ok' => false, 'message' => $pourquoi];

                return null;
            }
        }
        if ($reasonCode !== null && $reasonCode !== '' && !in_array($reasonCode, self::$reasonCodes, true)) {
            $refus = ['ok' => false, 'message' => static::t('Motif inconnu de cette plateforme.')];

            return null;
        }

        $author = self::resolveAuthor($pdo, $targetType, $targetId);
        if ($author === null) {
            $refus = ['ok' => false, 'message' => static::t('Cible introuvable.')];

            return null;
        }
        if ($author === $voterId) {
            $refus = ['ok' => false, 'message' => static::t('Tu ne peux pas voter pour toi-même.')];

            return null;
        }
        self::rattraperSeul($pdo, $author);

        return $author;
    }

    /**
     * Le votant est-il récent, et la part des comptes récents sur cet auteur
     * est-elle déjà pleine dans le sens de son vote ?
     *
     * Un compte est récent au moment de SON vote (`ageEtabliSecondes`) : un vote
     * reste dans la part quand son auteur vieillit, jusqu'à sortir de la fenêtre.
     * La part se lit en solde net de ce que les votes qui portent ont déplacé
     * (`effet`) : un vote puis son contraire, ou un vote que la cible fait tomber en
     * le croisant, la laissent libre — la cible ne la remplit pas à vide avec ses
     * propres comptes. Un vote qui porte sans rien déplacer (`effet` à zéro : une
     * borne de la réputation, ou une remise passée) compte un dans son sens tant que
     * la cible est à la borne de ce sens — au plafond pour un vote positif, au
     * plancher pour un vote négatif — et qu'il suit la dernière chute soldée
     * (`chute_soldee_le`), et rien sinon : à la borne, la part borne encore les
     * scores des messages et les motifs ; des votes émis pendant un ban, ou que la
     * remise a effacés, ne l'occupent plus, même quand la cible revient au
     * plancher. Lue sous le verrou du vote, sans la dispense par message de
     * `canVote()`.
     */
    private static function partDesRecentsPleine(PDO $pdo, int $voterId, int $author, int $value): bool
    {
        $ageEtabli = self::config()->ageEtabliSecondes;
        if ($ageEtabli === 0) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT created_at FROM accounts WHERE id = ?');
        $stmt->execute([$voterId]);
        $cree = (int) $stmt->fetchColumn();
        $stmt->closeCursor();
        if (self::maintenant() - $cree >= $ageEtabli) {
            return false;
        }
        // Le seuil est interpolé, pas lié : comparée à un paramètre arrivé en TEXT,
        // une expression sans affinité serait toujours plus petite sous SQLite, et
        // tout vote passerait pour récent. Le sens et la borne, des entiers calculés
        // ici, le sont avec lui, comme la chute soldée, entier que rend
        // `fenetreDesVoix()`.
        $sens = $value === -1 ? -1 : 1;
        $reputation = self::etat($pdo, $author)['reputation'];
        $aLaBorne = (int) ($sens === 1 ? $reputation >= self::config()->reputationMax : $reputation <= self::config()->banA);
        [, , $soldee] = self::fenetreDesVoix($pdo, $author, self::maintenant());
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(CASE WHEN v.effet = 0 THEN (CASE WHEN ' . $aLaBorne . ' AND v.value = ' . $sens . ' AND v.created_at > ' . $soldee . ' THEN v.value ELSE 0 END)
                                      ELSE COALESCE(v.effet, v.value) END), 0)
               FROM mod_votes v JOIN accounts a ON a.id = v.voter_id
              WHERE v.target_author = ? AND v.blocked = 0 AND v.created_at >= ?
                AND v.created_at - a.created_at < ' . $ageEtabli
        );
        $stmt->execute([$author, self::maintenant() - self::config()->fenetreFarmingJours * 86400]);
        $solde = (int) $stmt->fetchColumn();
        $stmt->closeCursor();
        $plafond = $value === -1 ? self::config()->farmingDownvotesMax : self::config()->farmingUpvotesMax;

        return $solde * $value >= $plafond;
    }

    /** Le vote lui-même, sous la transaction qu'`applyVote()` tient. */
    private static function inscrireVote(
        PDO $pdo,
        int $voterId,
        string $targetType,
        int $targetId,
        int $author,
        int $value,
        ?string $reason,
        ?string $reasonCode
    ): array {
        // Double vote ?
        $stmt = $pdo->prepare('SELECT id FROM mod_votes WHERE voter_id = ? AND target_type = ? AND target_id = ?');
        $stmt->execute([$voterId, $targetType, $targetId]);
        if ($stmt->fetchColumn()) {
            return ['ok' => false, 'message' => static::t('Tu as déjà voté ici.')];
        }

        // Anti upvote-farming : >3 upvotes voter→author sur 60j
        if ($value === 1) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM mod_votes WHERE voter_id = ? AND target_author = ? AND value = 1 AND ' . self::VOTE_AU_PLAFOND . ' AND created_at >= ?'
            );
            $stmt->execute([$voterId, $author, self::maintenant() - self::config()->fenetreFarmingJours * 86400]);
            if ((int) $stmt->fetchColumn() >= self::config()->farmingUpvotesMax) {
                self::inscrireVoteNeutralise($pdo, $voterId, $targetType, $targetId, $author, $value, $reason, $reasonCode, 'upvote_farming');
                return ['ok' => true, 'blocked' => true, 'blocked_reason' => 'upvote_farming',
                        'message' => static::t('Vote enregistré mais neutralisé : trop d\'upvotes répétés vers ce membre (anti-farming).')];
            }
        }

        // Anti downvote-farming : plafonne les downvotes d'un même votant vers le
        // même auteur (membre et messages) sur la fenêtre longue. Sans lui, un
        // votant patient érode une réputation message après message.
        if ($value === -1) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM mod_votes WHERE voter_id = ? AND target_author = ? AND value = -1 AND ' . self::VOTE_AU_PLAFOND . ' AND created_at >= ?'
            );
            $stmt->execute([$voterId, $author, self::maintenant() - self::config()->fenetreFarmingJours * 86400]);
            if ((int) $stmt->fetchColumn() >= self::config()->farmingDownvotesMax) {
                self::inscrireVoteNeutralise($pdo, $voterId, $targetType, $targetId, $author, $value, $reason, $reasonCode, 'downvote_farming');
                return ['ok' => true, 'blocked' => true, 'blocked_reason' => 'downvote_farming',
                        'message' => static::t('Vote enregistré mais neutralisé : trop de downvotes répétés vers ce membre (anti-farming).')];
            }
        }

        // Un votant ne vise pas tout le forum : le plafond anti-farming le borne
        // par cible, celui-ci par nombre de cibles. Au-delà, un downvote vers un
        // membre de plus est neutralisé, et le votant est signalé à l'arbitre.
        if ($value === -1) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT target_author) AS cibles, MAX(target_author = ?) AS deja
                   FROM mod_votes WHERE voter_id = ? AND value = -1 AND ' . self::VOTE_AU_PLAFOND . ' AND created_at >= ?'
            );
            $stmt->execute([$author, $voterId, self::maintenant() - self::config()->fenetreFarmingJours * 86400]);
            $portee = $stmt->fetch();
            if (!(int) $portee['deja'] && (int) $portee['cibles'] >= self::config()->ciblesParVotantMax) {
                self::inscrireVoteNeutralise($pdo, $voterId, $targetType, $targetId, $author, $value, $reason, $reasonCode, 'plafond_votant');
                self::signaler($pdo, $voterId, 'votant_etendu');
                return ['ok' => true, 'blocked' => true, 'blocked_reason' => 'plafond_votant',
                        'message' => sprintf(
                            static::t('Vote enregistré mais neutralisé : tu as déjà voté contre %d membres différents ces %d derniers jours.'),
                            self::config()->ciblesParVotantMax,
                            self::config()->fenetreFarmingJours
                        )];
            }
        }

        // Les comptes récents, ensemble, ne pèsent pas plus qu'un membre seul sur un
        // même auteur (`partDesRecentsPleine()`). Avant le croisement : un vote qui
        // ne pèse pas ne fait tomber personne et n'ouvre aucune trêve.
        if (self::partDesRecentsPleine($pdo, $voterId, $author, $value)) {
            self::inscrireVoteNeutralise($pdo, $voterId, $targetType, $targetId, $author, $value, $reason, $reasonCode, 'comptes_recents');
            self::signaler($pdo, $author, 'comptes_recents');
            // Le message est celui d'un vote compté : la part se remplit des votes
            // d'autres comptes, et la dire pleine leur apprendrait ces votes.
            return ['ok' => true, 'blocked' => true, 'blocked_reason' => 'comptes_recents', 'message' => static::t('Vote enregistré.')];
        }

        // Deux membres qui votent l'un contre l'autre se croisent : les deux votes
        // tombent, dans cette transaction (`croiser()`). Le message est celui d'un
        // vote compté ; `blocked` et sa raison sont pour l'hôte, dont la route ne
        // les rend pas au votant. Sous le seuil du vote, plus de croisement : une
        // cible que le quorum laisse voter effacerait sinon chacun de ses critiques
        // avant qu'il fasse une voix.
        $seuil = self::config()->perteDroitDeVoteSous;
        if ($value === -1 && self::etat($pdo, $voterId)['reputation'] >= $seuil && self::etat($pdo, $author)['reputation'] >= $seuil) {
            $croise = self::croiser($pdo, $voterId, $author);
            if ($croise !== null) {
                self::inscrireVoteNeutralise($pdo, $voterId, $targetType, $targetId, $author, $value, $reason, $reasonCode, $croise);
                // Tombé, le vote a été émis : il peut compléter une salve, et la
                // détection passe comme au vote compté.
                self::detecter($pdo, self::balayageDu($pdo) ? null : $author, $author);
                return ['ok' => true, 'blocked' => true, 'blocked_reason' => $croise, 'message' => static::t('Vote enregistré.')];
            }
        }

        // La réputation se lit AVANT l'insertion : le vote garde ce qu'il a
        // réellement changé. Un vote qui bute sur une borne n'a rien changé, et
        // l'annuler plus tard ne doit rien rendre.
        $rep = self::etat($pdo, $author);
        $newRep = max(self::config()->banA, min(self::config()->reputationMax, $rep['reputation'] + $value));
        $pdo->prepare(
            'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, reason_code, effet, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$voterId, $targetType, $targetId, $author, $value, $reason, $reasonCode, $newRep - $rep['reputation'], self::maintenant()]);
        $pdo->prepare('UPDATE member_moderation SET reputation = ?, updated_at = ? WHERE account_id = ?')
            ->execute([$newRep, self::maintenant(), $author]);
        // Le ban dû qui attend le journal vit avec la chute au plancher : un vote de
        // soutien qui l'en sort le fait tomber, sans attendre la convalescence.
        if ($value === 1 && $newRep > self::config()->banA) {
            self::designaler($pdo, $author, 'ban_en_attente');
        }

        // Le vote ne regarde que sa cible, sauf le premier de sa tranche, qui
        // balaie le site (`balayageDu()`).
        // Seul un vote négatif décide d'une sanction : un vote de soutien qui laisse
        // le compte sous le seuil ne le fait pas sanctionner.
        if ($value === -1) {
            self::detecter($pdo, self::balayageDu($pdo) ? null : $author, $author);
            self::enforceThresholds($pdo, $author, $newRep, $rep['reputation']);
        }

        return ['ok' => true, 'blocked' => false, 'message' => static::t('Vote enregistré.')];
    }

    /**
     * Le vote croisé, sous le verrou du vote de `$votant` contre `$cible`.
     *
     * - **Trêve** : un croisement a eu lieu entre eux, dans un sens ou dans
     *   l'autre, il y a moins de `fenetreCroiseeSecondes` ; le vote tombe
     *   (`treve`). La trêve court depuis le croisement, pas depuis la dernière
     *   tentative : un membre qui revoterait sans cesse ne s'abriterait pas des
     *   votes de l'autre.
     * - **Croisement** : `$cible` a contre `$votant` un vote négatif qui porte
     *   encore, posé dans la même fenêtre ; le plus récent tombe (`vote_croise`),
     *   et le vote de `$votant` avec lui (`riposte`) — un vote contre un vote,
     *   quel que soit le message visé. Le point qu'il avait retiré revient à
     *   `$votant`. La riposte est posée à l'instant du croisement : la trêve
     *   part d'elle.
     *
     * Aucune peine n'est levée : un croisement n'est pas une injustice réparée,
     * et une peine causée entre-temps reste à l'arbitre. Les deux votes restent
     * comptés dans les plafonds de leur auteur (`VOTE_AU_PLAFOND`).
     *
     * @return 'treve'|'riposte'|null la raison qui fait tomber le vote, ou null s'il porte
     */
    private static function croiser(PDO $pdo, int $votant, int $cible): ?string
    {
        $depuis = self::maintenant() - self::config()->fenetreCroiseeSecondes;
        $treve = $pdo->prepare(
            "SELECT 1 FROM mod_votes
              WHERE blocked_reason = 'riposte' AND created_at >= ?
                AND ((voter_id = ? AND target_author = ?) OR (voter_id = ? AND target_author = ?))
              LIMIT 1"
        );
        $treve->execute([$depuis, $votant, $cible, $cible, $votant]);
        $enTreve = $treve->fetchColumn() !== false;
        $treve->closeCursor();
        if ($enTreve) {
            return 'treve';
        }
        $face = $pdo->prepare(
            'SELECT id FROM mod_votes
              WHERE voter_id = ? AND target_author = ? AND value = -1 AND blocked = 0 AND created_at >= ?
              ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $face->execute([$cible, $votant, $depuis]);
        $vote = $face->fetchColumn();
        $face->closeCursor();
        if ($vote === false) {
            return null;
        }
        $annule = $pdo->prepare("UPDATE mod_votes SET blocked = 1, blocked_reason = 'vote_croise' WHERE id = ? AND blocked = 0");
        $annule->execute([(int) $vote]);
        if ($annule->rowCount() !== 1) {
            return null;
        }
        self::rendreLePoint($pdo, $votant, (int) $vote);

        return 'riposte';
    }

    /**
     * Deux comptes sont-ils liés ? Un message privé dans CHAQUE sens sur la
     * fenêtre — un échange consenti des deux côtés, l'équivalent le plus proche
     * de l'invitation acceptée que décrit le protocole. Un message resté sans
     * réponse ne dit aucune entente : écrire à des votants ne les lie à personne.
     *
     * Le contenu n'est jamais lu : seuls l'expéditeur, le destinataire et la
     * date sont interrogés. Le chiffré reste fermé. Sur un hôte dont la
     * messagerie passe hors du moteur, rien ne se lit : personne n'est lié.
     */
    public static function areLinked(PDO $pdo, int $a, int $b): bool
    {
        if (self::config()->messagerie === Messagerie::HORS_MOTEUR) {
            return false;
        }

        return $a !== $b && self::mutualPairs($pdo, [$a, $b]) !== [];
    }

    /**
     * Les paires de `$voters` liées au sens d'`areLinked()` : un message dans
     * chaque sens entre le début de la paire et `$t` (maintenant par défaut). Le
     * début d'une paire est `fenetreMeuteJours` avant le plus ancien des premiers
     * votes de ses deux membres (`$premiers`, votant → instant ; `$t` pour chacun
     * par défaut) : seuls les deux membres d'une paire règlent sa profondeur, et une
     * clique qui cesse de s'écrire reste une clique tant que ses votes comptent.
     * `areLinked()` passe par ici : la définition du lien n'existe qu'une fois.
     *
     * Les sens se lisent dans l'index de la paire (`recusDe()`), au plus `$max`
     * recherches : au-delà, la fonction rend null. Le coût suit les votants et
     * leurs expéditeurs distincts, jamais le volume des messages.
     *
     * @param int[] $voters
     * @param array<int, int>|null $premiers
     * @return list<array{int, int}>|null paires [a, b] avec a < b, ou null au-delà de `$max`
     */
    private static function mutualPairs(PDO $pdo, array $voters, ?int $t = null, ?array $premiers = null, ?int $max = null): ?array
    {
        if (count(array_unique($voters)) < 2) {
            return [];
        }
        $t ??= self::maintenant();
        $premiers ??= array_fill_keys($voters, $t);
        $l = self::lecteurDeSens($pdo, $voters, $t, $max ?? PHP_INT_MAX);
        $dates = [];
        foreach ($l['votants'] as $r) {
            $recus = self::recusDe($l, $r);
            if ($recus === null || $l['budget'] < 0) {
                self::conclureLecture($l);

                return null;
            }
            foreach ($recus as $s => $quand) {
                $dates[$s][$r] = $quand;
            }
        }
        self::conclureLecture($l);
        $profondeur = self::config()->fenetreMeuteJours * 86400;
        $paires = [];
        foreach ($dates as $de => $versQui) {
            foreach ($versQui as $vers => $quand) {
                if ($de < $vers && isset($dates[$vers][$de])
                    && min($quand, $dates[$vers][$de]) >= min($premiers[$de], $premiers[$vers]) - $profondeur) {
                    $paires[] = [$de, $vers];
                }
            }
        }

        return $paires;
    }

    /**
     * Les votants de `$voters` liés à personne parmi eux, cherchés compte par
     * compte quand la lecture de `mutualPairs()` dépasse sa borne : pour chacun,
     * ses expéditeurs votants dans l'index de la paire, puis le sens inverse sur
     * leur paire, qui peut n'avoir aucun message — la lecture d'un compte s'arrête
     * à son premier lien. Ils sont des voix les uns pour les autres, quel que soit le
     * reste du graphe. La recherche s'arrête à `$assez`, ou au bout de `$max`
     * recherches : un votant qu'elle n'a pas fini de lire ne compte pas, et le
     * minimum reste exact.
     *
     * @param int[] $voters
     * @param array<int, int> $premiers
     * @return list<int>
     */
    private static function liesAPersonne(PDO $pdo, array $voters, int $t, array $premiers, int $assez, int $max): array
    {
        $seuls = [];
        if ($assez <= 0 || $voters === []) {
            return $seuls;
        }
        $profondeur = self::config()->fenetreMeuteJours * 86400;
        $l = self::lecteurDeSens($pdo, $voters, $t, $max);
        foreach ($l['votants'] as $v) {
            $lie = false;
            $recus = self::recusDe($l, $v, static function (int $x, int $quand) use (&$l, &$lie, $v, $premiers, $profondeur): bool {
                $depuis = min($premiers[$v], $premiers[$x]) - $profondeur;
                if ($quand < $depuis) {
                    return false;
                }
                $retour = self::dateDuSens($l, $x, $v, connu: false);
                $lie = is_int($retour) && $retour >= $depuis;

                return $lie;
            });
            if ($recus === null || $l['budget'] < 0) {
                break;
            }
            if (!$lie) {
                $seuls[] = $v;
                if (count($seuls) >= $assez) {
                    break;
                }
            }
        }
        self::conclureLecture($l);

        return $seuls;
    }

    /**
     * Ce qu'une lecture des sens tient : les votants triés, les requêtes préparées
     * sur l'index de la paire (`exigerIndexPaire()`), le budget de recherches, et
     * le compte des sens lisibles et illisibles (`conclureLecture()`).
     *
     * @param int[] $voters
     * @return array{pdo: PDO, votants: list<int>, estVotant: array<int, true>, t: int, budget: int,
     *               suivant: \PDOStatement, date: \PDOStatement, dernier: \PDOStatement, existe: \PDOStatement,
     *               lisibles: int, illisibles: array<int, array<int, true>>}
     */
    private static function lecteurDeSens(PDO $pdo, array $voters, int $t, int $budget): array
    {
        self::exigerIndexPaire($pdo);
        $votants = array_values(array_unique(array_map('intval', $voters)));
        sort($votants);
        try {
            return [
                'pdo'        => $pdo,
                'votants'    => $votants,
                'estVotant'  => array_fill_keys($votants, true),
                't'          => $t,
                'budget'     => $budget,
                'suivant'    => $pdo->prepare('SELECT MIN(sender_id) FROM dm WHERE recipient_id = ? AND sender_id >= ?'),
                'date'       => $pdo->prepare('SELECT MAX(created_at) FROM dm WHERE recipient_id = ? AND sender_id = ? AND created_at <= ?'),
                'dernier'    => $pdo->prepare('SELECT MAX(created_at) FROM dm WHERE recipient_id = ? AND sender_id = ?'),
                'existe'     => $pdo->prepare('SELECT 1 FROM dm WHERE recipient_id = ? AND sender_id = ? LIMIT 1'),
                'lisibles'   => 0,
                'illisibles' => [],
            ];
        } catch (\PDOException $e) {
            throw self::dmIntrouvable($pdo, $e);
        }
    }

    /**
     * Les expéditeurs votants de `$r`, avec la date de leur sens vers lui : le plus
     * petit expéditeur à partir de chaque identifiant de votant, par sauts dans
     * l'index — un expéditeur qui ne vote pas fait sauter au votant suivant. Un
     * compte coûte un saut par votant ou par expéditeur distinct, le plus petit des
     * deux, plus le dernier saut, et de une à trois recherches par sens
     * (`dateDuSens()`). Un sens illisible n'y est pas. Un expéditeur qui n'est
     * pas un nombre se range, dans une colonne numérique (`exigerIndexPaire()`),
     * après tous les nombres : il clôt la boîte, aucun votant n'est derrière lui.
     * `$assez`, s'il est donné, est appelé à chaque sens lisible : vrai, la
     * lecture du compte s'arrête là.
     *
     * @param callable(int, int): bool|null $assez
     * @return array<int, int>|null expéditeur → date ; null quand le budget s'épuise à
     *         un saut ou à une date. Il peut aussi s'épuiser dans `$assez`, au dernier
     *         saut : la lecture rend alors ce qu'elle a lu, et l'appelant lit le budget.
     */
    private static function recusDe(array &$l, int $r, ?callable $assez = null): ?array
    {
        $recus = [];
        $n = count($l['votants']);
        $i = 0;
        while ($i < $n) {
            if (--$l['budget'] < 0) {
                return null;
            }
            $l['suivant']->bindValue(1, $r, PDO::PARAM_INT);
            $l['suivant']->bindValue(2, $l['votants'][$i], PDO::PARAM_INT);
            $l['suivant']->execute();
            $s = $l['suivant']->fetchColumn();
            $l['suivant']->closeCursor();
            if (!is_int($s) && !is_float($s) && !(is_string($s) && is_numeric($s))) {
                break;
            }
            $s = (int) $s;
            if ($s !== $r && isset($l['estVotant'][$s])) {
                $quand = self::dateDuSens($l, $r, $s);
                if ($quand === false) {
                    return null;
                }
                if ($quand !== null) {
                    $recus[$s] = $quand;
                    if ($assez !== null && $assez($s, $quand)) {
                        return $recus;
                    }
                }
            }
            $i = self::votantApres($l['votants'], $s);
        }

        return $recus;
    }

    /** La place, dans les votants triés, du premier identifiant au-delà de `$s`. */
    private static function votantApres(array $votants, int $s): int
    {
        $bas = 0;
        $haut = count($votants);
        while ($bas < $haut) {
            $milieu = intdiv($bas + $haut, 2);
            if ($votants[$milieu] <= $s) {
                $bas = $milieu + 1;
            } else {
                $haut = $milieu;
            }
        }

        return $bas;
    }

    /**
     * La date du sens `$s` → `$r` : la plus récente jusqu'à `t`, lisible si elle tombe
     * dans `[t / 2, t]` — un instant en secondes est du même ordre que maintenant,
     * et des millisecondes, des dixièmes de seconde, un jour julien n'y tombent pas.
     * Sinon, la plus récente tout court dit le reste : un sens dont le dernier
     * message tient dans `]t, 2 t]` est lisible, et hors de la fenêtre (0) ; un
     * instant écrit en texte (une vue en `strftime('%s', …)`) se relit avec `t` en
     * texte ; tout autre sens — des dates NULL, en clair, hors d'échelle — est
     * illisible, retenu à part (`conclureLecture()`), et ne lie personne. Un sens
     * que l'index n'a pas trouvé (`$connu` faux) peut n'avoir aucun message : il ne
     * lie personne, sans être illisible. Une à trois recherches, quatre pour un
     * sens inconnu.
     *
     * @return int|null|false la date, null si le sens est illisible ou vide, false quand le budget est épuisé
     */
    private static function dateDuSens(array &$l, int $r, int $s, bool $connu = true): int|null|false
    {
        $t = $l['t'];
        $lisible = static fn (?int $quand): bool => $quand !== null && $quand >= intdiv($t, 2) && $quand <= $t;
        $quand = self::lireDate($l, $l['date'], [$r, $s, $t]);
        if ($quand === false) {
            return false;
        }
        if (!$lisible($quand)) {
            $dernier = self::lireDate($l, $l['dernier'], [$r, $s], brut: $brut);
            if ($dernier === false) {
                return false;
            }
            if ($dernier !== null && $dernier > $t && $dernier <= 2 * $t) {
                $l['lisibles']++;

                return 0;
            }
            $quand = is_string($brut) ? self::lireDate($l, $l['date'], [$r, $s, (string) $t]) : null;
            if ($quand === false) {
                return false;
            }
            if (!$lisible($quand)) {
                if ($dernier === null && $brut === null && !$connu) {
                    if (--$l['budget'] < 0) {
                        return false;
                    }
                    $l['existe']->bindValue(1, $r, PDO::PARAM_INT);
                    $l['existe']->bindValue(2, $s, PDO::PARAM_INT);
                    $l['existe']->execute();
                    $existe = $l['existe']->fetchColumn() !== false;
                    $l['existe']->closeCursor();
                    if (!$existe) {
                        return null;
                    }
                }
                $l['illisibles'][$r][$s] = true;

                return null;
            }
        }
        $l['lisibles']++;

        return $quand;
    }

    /**
     * Une recherche de date, comptée sur le budget : l'instant rendu, null s'il n'y
     * en a pas ou s'il n'est pas un nombre, false quand le budget est épuisé.
     * `$brut` reçoit la valeur telle que la base l'a rendue.
     *
     * @param list<int|string> $valeurs
     */
    private static function lireDate(array &$l, \PDOStatement $q, array $valeurs, mixed &$brut = null): int|null|false
    {
        if (--$l['budget'] < 0) {
            return false;
        }
        foreach ($valeurs as $i => $valeur) {
            $q->bindValue($i + 1, $valeur, is_int($valeur) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $q->execute();
        $brut = $q->fetchColumn();
        $q->closeCursor();

        return match (true) {
            is_int($brut)                          => $brut,
            is_float($brut)                        => (int) $brut,
            is_string($brut) && is_numeric($brut)  => (int) (float) $brut,
            default                                => null,
        };
    }

    /**
     * Ce qu'une lecture dit des dates de `dm` quand elle a rencontré des sens
     * illisibles. Si aucun sens n'était lisible et qu'une paire est illisible dans
     * ses deux sens, c'est la convention de la colonne qui est fausse : la lecture lève, en
     * nommant une date — sinon le quorum ne lirait aucun lien, et chaque votant
     * ferait une voix. Sinon, les sens illisibles ne lient personne, et le journal
     * d'erreurs le dit : un vieux message mal daté ne gèle aucune chute.
     */
    private static function conclureLecture(array $l): void
    {
        if ($l['illisibles'] === []) {
            return;
        }
        $illisibles = array_sum(array_map('count', $l['illisibles']));
        foreach ($l['illisibles'] as $r => $expediteurs) {
            foreach (array_keys($expediteurs) as $s) {
                if ($l['lisibles'] === 0 && isset($l['illisibles'][$s][$r])) {
                    $exemple = $l['pdo']->prepare('SELECT created_at, typeof(created_at) FROM dm WHERE recipient_id = ? AND sender_id = ? LIMIT 1');
                    $exemple->execute([$r, $s]);
                    $ligne = $exemple->fetch(PDO::FETCH_NUM) ?: ['?', '?'];
                    $exemple->closeCursor();
                    throw new \LogicException(sprintf(
                        'SelfModerate : « dm.created_at » de l\'hôte porte « %s » (%s), qui n\'est pas un instant Unix en secondes, du même ordre que maintenant (%d) — aucun message entre les votants n\'a de date lisible, et le quorum des seuils compare des instants.',
                        mb_substr((string) $ligne[0], 0, 40),
                        $ligne[1],
                        $l['t']
                    ));
                }
            }
        }
        error_log(sprintf('SelfModerate : %d sens de messages privés entre votants ont une date illisible (ni un instant en secondes, ni du même ordre que maintenant) ; ils ne lient personne.', $illisibles));
    }

    /**
     * L'index de la paire, `dm(recipient_id, sender_id, created_at)` : sans lui,
     * chaque recherche de la lecture des liens relirait une boîte entière, sous le
     * verrou d'écriture. Vérifié une fois par connexion — à la première écriture
     * du moteur (un vote, un signalement, le balayage, un geste d'arbitre) quand le
     * quorum aux seuils est allumé —, sur les plans que SQLite donne aux deux
     * recherches : l'expéditeur suivant (l'égalité sur le destinataire, la plage
     * sur l'expéditeur) et la date d'un sens (l'égalité sur les deux, la plage sur
     * la date) tiennent chacun en une seule recherche, dans un index qui suffit
     * seul — couvrant, ou la clé primaire d'une table `WITHOUT ROWID`. Une vue qui
     * calcule l'une de ces colonnes, une vue filtrée sans index partiel du même
     * filtre ou une vue `UNION ALL` relisent les messages, et lèvent.
     *
     * Les deux identifiants ont une affinité numérique : la marche par sauts
     * (`recusDe()`) suit l'ordre des nombres, qu'une colonne de texte ou sans type
     * ne donne pas.
     */
    private static function exigerIndexPaire(PDO $pdo): void
    {
        self::$indexVerifie ??= new \WeakMap();
        if (isset(self::$indexVerifie[$pdo])) {
            return;
        }
        try {
            $plan = $pdo->query('EXPLAIN QUERY PLAN SELECT MIN(sender_id) FROM dm WHERE recipient_id = 1 AND sender_id >= 1')
                ->fetchAll(PDO::FETCH_COLUMN, 3);
            $planDate = $pdo->query('EXPLAIN QUERY PLAN SELECT MAX(created_at) FROM dm WHERE recipient_id = 1 AND sender_id = 1 AND created_at <= 1')
                ->fetchAll(PDO::FETCH_COLUMN, 3);
            $types = array_column($pdo->query('PRAGMA table_info(dm)')->fetchAll(PDO::FETCH_ASSOC), 'type', 'name');
        } catch (\PDOException $e) {
            throw self::dmIntrouvable($pdo, $e);
        }
        $seul = static fn (array $lignes, string $contraintes): bool => count($lignes) === 1
            && preg_match('/^SEARCH (?:TABLE )?\S+ USING (?:COVERING INDEX \S+|PRIMARY KEY) \(' . $contraintes . '\)$/', (string) $lignes[0]) === 1;
        if (!$seul($plan, '\S+=\? AND \S+>\?') || !$seul($planDate, '\S+=\? AND \S+=\? AND \S+<\?')) {
            throw new \LogicException(
                'SelfModerate : la table « dm » de l\'hôte n\'a pas d\'index où la lecture des liens du quorum tienne en une recherche ('
                . implode(' | ', [...$plan, ...$planDate]) . ') — un index couvrant ou la clé primaire ; une vue filtrée demande un index partiel '
                . 'du même filtre, une vue UNION ALL n\'est pas prise. La note de montée vers la 0.9.0 (README) crée l\'index : '
                . 'CREATE INDEX IF NOT EXISTS idx_dm_paire ON dm(recipient_id, sender_id, created_at).'
            );
        }
        foreach (['sender_id', 'recipient_id'] as $colonne) {
            $type = strtoupper((string) ($types[$colonne] ?? ''));
            if (!str_contains($type, 'INT') && ($type === '' || preg_match('/CHAR|CLOB|TEXT|BLOB/', $type) === 1)) {
                throw new \LogicException(sprintf(
                    'SelfModerate : « dm.%s » de l\'hôte est déclarée %s : la lecture des liens du quorum marche dans l\'index dans l\'ordre des nombres, '
                    . 'qu\'une colonne de texte ou sans type ne suit pas — ses identifiants doivent être des entiers (INTEGER).',
                    $colonne,
                    $type === '' ? 'sans type' : '« ' . $type . ' »'
                ));
            }
        }
        self::$indexVerifie[$pdo] = true;
    }

    /** L'erreur d'une lecture de `dm` : la table nommée si elle manque, l'erreur telle quelle sinon. */
    private static function dmIntrouvable(PDO $pdo, \PDOException $e): \Throwable
    {
        if (!self::tableDeLHote($pdo, 'dm')) {
            return new \LogicException(
                'SelfModerate : la table « dm » de l\'hôte est introuvable, ni table ni vue — le quorum des seuils la lit.',
                0,
                $e
            );
        }

        return $e;
    }

    /**
     * La base porte-t-elle cette table de l'hôte, ou une vue de ce nom, y compris
     * une vue `TEMP` ? Lu seulement après un échec : le chemin normal n'en paie rien.
     */
    private static function tableDeLHote(PDO $pdo, string $nom): bool
    {
        $existe = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE name = ? AND type IN ('table', 'view')
             UNION ALL
             SELECT 1 FROM sqlite_temp_master WHERE name = ? AND type IN ('table', 'view')"
        );
        $existe->execute([$nom, $nom]);
        $trouvee = $existe->fetchColumn() !== false;
        $existe->closeCursor();

        return $trouvee;
    }

    /**
     * Ce downvote doit-il balayer tout le site ? Oui pour le premier de sa
     * tranche de `balayagePeriodeSecondes`, non pour les autres.
     *
     * Un vote ne regarde que sa cible ; le balayage relit le site — un compte
     * qu'un refus a fait sauter au passage d'avant (`comptes_sautes`), les fins
     * de peine que personne n'a inscrites (`balayageFiletMax`). Fait à chaque
     * vote, il rendrait le coût d'un vote proportionnel à l'activité du site. Une
     * fois par tranche, ce coût est borné, et aucun hôte n'a de minuteur à poser
     * pour que le filet existe.
     *
     * 🔑 La clé primaire de `mod_sweeps` arbitre : deux premiers votes
     * simultanés insèrent la même tranche, la base en refuse un, un seul
     * balaie. Seul ce refus-là est rattrapé — une table absente doit faire du
     * bruit, pas éteindre le filet.
     */
    private static function balayageDu(PDO $pdo): bool
    {
        $tranche = intdiv(self::maintenant(), self::config()->balayagePeriodeSecondes);
        $vue = $pdo->prepare('SELECT 1 FROM mod_sweeps WHERE slot = ?');
        $vue->execute([$tranche]);
        if ($vue->fetchColumn() !== false) {
            return false;
        }
        try {
            $pdo->prepare('INSERT INTO mod_sweeps (slot) VALUES (?)')->execute([$tranche]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
        $pdo->prepare('DELETE FROM mod_sweeps WHERE slot < ?')->execute([$tranche]);
        self::$trancheBalayee = true;
        return true;
    }

    /**
     * La salve rapide : plusieurs votants qui votent contre la même cible dans la
     * même minute. Le plus souvent la même réaction au même message : rien n'est
     * annulé, la cible est signalée à un admin. Annuler ici protégerait un
     * message d'autant mieux qu'il choque plus de monde à la fois — la détection
     * travaillerait alors pour l'abuseur.
     *
     * Aucun lien n'annule de vote : des votants liés entre eux ne pèsent
     * ensemble qu'une voix au quorum des seuils (`enforceThresholds()`), et leurs
     * votes restent. `pack_detected` est donc toujours faux, `cancelled_votes`
     * nul, `packs` et `voters_truncated` vides ; les clés restent pour les hôtes
     * qui les lisent.
     *
     * `$seulement` borne le passage à une cible : un vote ne peut faire naître
     * une salve que sur la sienne. Sans lui, tout le site.
     *
     * `comptes_sautes` nomme les comptes qu'un refus a fait sauter, avec sa
     * raison : `reprise_manquante` ou `refus`. Le passage suivant retrouve un
     * compte balayé.
     */
    public static function detectPackVoting(PDO $pdo, ?int $seulement = null): array
    {
        return self::detecter($pdo, $seulement, $seulement);
    }

    /**
     * Le passage lui-même. `$voteSur` est la cible du vote qui le lance : seule
     * elle peut avoir reçu un vote qu'aucune détection n'a encore vu. Un balayage
     * relit sur les autres comptes des votes déjà détectés, et leurs signaux
     * redétectés ne font pas refuser l'affichage de l'arbitre.
     */
    private static function detecter(PDO $pdo, ?int $seulement, ?int $voteSur): array
    {
        $surCible = $seulement === null ? '' : ' AND target_author = ?';
        $deLaCible = $seulement === null ? [] : [$seulement];
        $salves = [];
        $sautes = [];

        $sinceSalve = self::maintenant() - self::config()->fenetreSalveSecondes * 2;
        // Le seuil est interpolé, pas lié : un paramètre PDO arrive en TEXT, et
        // SQLite range tout INTEGER avant tout TEXT — `COUNT(*) >= '2'` est donc
        // toujours faux. Les colonnes INTEGER convertissent leur paramètre par
        // affinité ; une expression comme COUNT(*) n'a aucune affinité.
        $minVotants = (int) self::config()->salveVotantsMin;
        $stmt = $pdo->prepare("
            SELECT target_author FROM mod_votes
             WHERE value = -1 AND " . self::VOTE_AU_PLAFOND . " AND created_at >= ?$surCible
          GROUP BY target_author HAVING COUNT(DISTINCT voter_id) >= $minVotants
        ");
        $stmt->execute(array_merge([$sinceSalve], $deLaCible));

        foreach ($stmt->fetchAll() as $row) {
            $author = (int) $row['target_author'];
            // Un vote croisé, une riposte ou un vote de trêve restent dans la
            // salve : tombés, ils ont été émis.
            $vs = $pdo->prepare('SELECT voter_id, created_at FROM mod_votes
                                  WHERE target_author = ? AND value = -1 AND ' . self::VOTE_AU_PLAFOND . ' AND created_at >= ?
                                  ORDER BY created_at ASC');
            $vs->execute([$author, $sinceSalve]);
            $votes = $vs->fetchAll();
            $n = count($votes);

            // Plus gros groupe de votants distincts tenant dans une fenêtre de
            // `fenetreSalveSecondes`. Raisonner par fenêtre glissante plutôt que sur
            // l'étalement global empêche un vote espacé de masquer le groupe.
            $best = [];
            for ($i = 0; $i < $n; $i++) {
                $cluster = [];
                for ($k = $i; $k < $n; $k++) {
                    if ((int) $votes[$k]['created_at'] - (int) $votes[$i]['created_at'] > self::config()->fenetreSalveSecondes) {
                        break;
                    }
                    $cluster[(int) $votes[$k]['voter_id']] = true;
                }
                if (count($cluster) > count($best)) {
                    $best = $cluster;
                }
            }
            if (count($best) < self::config()->salveVotantsMin) {
                continue;
            }
            // 🔑 Quelques comptes ordinaires suffisent à déclencher cette branche
            // (`salveVotantsMin`), et elle tourne à chaque vote négatif : écrire
            // le signal sans regarder celui en place laisserait un tiers remplacer
            // un motif d'arbitrage par le plus bénin de la liste, et le rendre
            // indélébile. `signaler()` porte la règle pour tous les poseurs.
            $signale = self::surAutrui($author, $voteSur, static function () use ($pdo, $author, $voteSur): bool {
                self::signaler($pdo, $author, 'salve_rapide', $author === $voteSur);

                return true;
            }, $sautes);
            if ($signale === null) {
                continue;
            }
            $salves[] = ['target_author' => $author, 'voters' => array_keys($best)];
        }

        return [
            'pack_detected'    => false,
            'cancelled_votes'  => 0,
            'packs'            => [],
            'salves'           => $salves,
            'voters_truncated' => [],
            'comptes_sautes'   => $sautes,
        ];
    }

    /**
     * Ce qu'un balayage fait sur un compte que le vote qui le lance ne vise pas.
     * Un refus — une reprise oubliée, toute exception — ne défait pas le vote
     * d'un tiers : le compte est sauté, son point de reprise défait, le saut
     * rendu dans `$sautes` — sous le compte de la reprise oubliée quand c'en est
     * une — et écrit au journal d'erreurs ; la détection le retrouve au balayage
     * suivant. La cible du vote n'y passe pas : ce que le vote cause s'inscrit,
     * ou le vote tombe.
     *
     * Remontent toujours : une `LectureDansUneEcriture`, une `\Error` — un défaut
     * de code n'est pas un refus —, et l'erreur après laquelle la base a défait une
     * transaction du moteur, celle du vote ou celle du planificateur : une panne,
     * pas un refus. Un compte n'est nommé qu'une fois par raison.
     *
     * @template T
     * @param callable(): T            $geste
     * @param list<array{compte: int, raison: string}> $sautes
     * @return T|null null quand le compte est sauté
     */
    private static function surAutrui(int $compte, ?int $voteSur, callable $geste, array &$sautes): mixed
    {
        if ($compte === $voteSur) {
            return $geste();
        }
        try {
            return $geste();
        } catch (LectureDansUneEcriture $e) {
            throw $e;
        } catch (\Exception $e) {
            if ($e === self::$transactionDefaitePar) {
                throw $e;
            }
            error_log(sprintf('SelfModerate : le balayage saute le compte %d — %s', $compte, $e->getMessage()));
            $saut = [
                'compte' => $e instanceof RepriseManquante ? $e->compte : $compte,
                'raison' => $e instanceof RepriseManquante ? 'reprise_manquante' : 'refus',
            ];
            if (!in_array($saut, $sautes, true)) {
                $sautes[] = $saut;
            }

            return null;
        }
    }

    /**
     * Rend à `$author` ce que le vote croisé lui avait réellement retiré, et rien
     * de plus. Un vote posé au plancher n'a rien retiré, un vote dont l'effet a
     * été effacé par une remise au départ non plus : le rendre ferait des points
     * gratuits. Un vote d'avant la colonne `effet` n'a pas d'effet connu : il ne
     * rend rien.
     *
     * Aucune peine n'est levée et la convalescence suit son cours : un croisement
     * n'annule aucune faute. Le droit de vote revient avec le seuil.
     */
    private static function rendreLePoint(PDO $pdo, int $author, int $voteId): void
    {
        // Ce que le temps a fait au compte s'inscrit d'abord : une sortie de
        // convalescence efface les effets des votes reçus, et une restitution
        // comptée avant elle rendrait ce que la remise au départ a déjà rendu.
        self::rattraper($pdo, $author, contenir: true);
        $stmt = $pdo->prepare('SELECT COALESCE(effet, 0) FROM mod_votes WHERE id = ?');
        $stmt->execute([$voteId]);
        $rendu = -min(0, (int) $stmt->fetchColumn());
        $stmt->closeCursor();
        if ($rendu > 0) {
            $pdo->prepare('UPDATE member_moderation SET reputation = MIN(reputation + ?, ?), updated_at = ? WHERE account_id = ?')
                ->execute([$rendu, self::config()->reputationMax, self::maintenant(), $author]);
        }
        $apres = self::etat($pdo, $author)['reputation'];
        if ($apres > self::config()->banA) {
            self::designaler($pdo, $author, 'ban_en_attente');
        }
        if ($apres >= self::config()->perteDroitDeVoteSous) {
            $pdo->prepare('UPDATE member_moderation SET voting_rights = 1 WHERE account_id = ?')->execute([$author]);
            self::designaler($pdo, $author, 'droit_de_vote_perdu', 'perte_du_vote_due', 'chute_sans_quorum');
        }
    }

    /**
     * Rang de récidive d'un votant : le nombre d'épisodes de meute qu'une base
     * d'avant la 0.9.0 a consignés à son compte ; aucun ne s'y ajoute plus. Lu comme un MAX et non comme un COUNT — un épisode laisse une
     * ligne par cible, et trois victimes le même soir restent un épisode. Une
     * grâce d'arbitre l'efface : seules les lignes qui la suivent comptent, et
     * les précédentes restent lisibles.
     */
    public static function rangDe(PDO $pdo, int $voterId): int
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(rang), 0) FROM mod_pack_flags
              WHERE voter_id = ? AND id > (SELECT COALESCE(MAX(id), 0) FROM mod_pack_flags
                                            WHERE voter_id = ? AND action = 'grace')"
        );
        $stmt->execute([$voterId, $voterId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Deux familles de signaux. Un signal d'ÉTAT dit un état du compte, et se lève
     * seul quand l'état cesse : `reputation_zero`, `ban_auto`, `journal_en_panne`
     * se déduisent de la base ; `chute_sans_quorum`, une chute sous un seuil que
     * le quorum n'a pas sanctionnée, cesse quand la réputation repasse
     * `perteDroitDeVoteSous` ou quand la sanction tombe ; `levee_en_attente`, que
     * seule une base d'avant la 0.9.0 porte, cesse par une grâce ou quand un
     * arbitre la classe. Un signal de COMPORTEMENT (une salve, un votant étendu…)
     * dit ce qui s'est passé, et seul un arbitre le ferme. Une cause que le moteur
     * ne connaît pas — reprise d'une base ancienne — est un comportement : ce
     * qu'il ne sait pas lever, un humain le ferme.
     */
    private static function famille(string $cause): string
    {
        return in_array($cause, self::causesEtat(), true) ? 'etat' : 'comportement';
    }

    /** Les causes d'état, que lisent `famille()` et la reprise (`migrerSignaux()`). */
    private static function causesEtat(): array
    {
        return ['reputation_zero', 'ban_auto', 'journal_en_panne', 'levee_en_attente', 'ban_en_attente', 'droit_de_vote_perdu', 'perte_du_vote_due', 'ban_sans_fin', 'chute_sans_quorum'];
    }

    /**
     * Le délai depuis la dernière occurrence comptée au-delà duquel une
     * détection est un fait neuf. Une salve se redétecte à chaque downvote de sa
     * fenêtre, un votant étendu à chacun de ses votes : sans ce délai, le compte dirait
     * combien de fois on a regardé, pas combien de fois c'est arrivé. Compté
     * depuis la dernière détection, un filet continu resterait à un pour toujours.
     */
    private static function regroupement(string $cause): int
    {
        return match ($cause) {
            'salve_rapide'     => 2 * self::config()->fenetreSalveSecondes,
            'votant_etendu', 'comptes_recents' => self::config()->balayagePeriodeSecondes,
            default            => 0,
        };
    }

    /**
     * Signale un compte à l'arbitre : une ligne par cause, et aucune cause n'en
     * remplace une autre. Un comportement déjà ouvert compte une occurrence de
     * plus, ce qui fait refuser le geste d'un arbitre qui ne l'a pas vue ; un
     * état déjà ouvert reste tel quel, vu ou non. `$neuf` est faux quand la
     * détection ne relit que des votes qu'une détection a déjà vus.
     */
    private static function signaler(PDO $pdo, int $accountId, string $cause, bool $neuf = true): void
    {
        self::transaction($pdo, static fn () => self::poserSignal($pdo, $accountId, $cause, $neuf));
    }

    private static function poserSignal(PDO $pdo, int $accountId, string $cause, bool $neuf): void
    {
        self::exigerReprise($pdo, $accountId);
        $maintenant = self::maintenant();
        $pose = $pdo->prepare('INSERT OR IGNORE INTO mod_signaux (account_id, cause, ouvert_le, derniere_le) VALUES (?, ?, ?, ?)');
        $pose->execute([$accountId, $cause, $maintenant, $maintenant]);
        if ($pose->rowCount() === 1) {
            self::resumer($pdo, $accountId);

            return;
        }
        if (self::famille($cause) === 'etat') {
            return;
        }
        $suite = $pdo->prepare(
            'UPDATE mod_signaux SET occurrences = occurrences + 1, derniere_le = ?
              WHERE account_id = ? AND cause = ? AND derniere_le <= ?'
        );
        $suite->bindValue(1, $maintenant, PDO::PARAM_INT);
        $suite->bindValue(2, $accountId, PDO::PARAM_INT);
        $suite->bindValue(3, $cause);
        $suite->bindValue(4, $maintenant - self::regroupement($cause), PDO::PARAM_INT);
        $suite->execute();
        // Une détection qui peut porter un vote neuf fait refuser un geste fondé sur
        // l'affichage d'avant : une salve d'autres votants, repliée dans le compte
        // faute d'être un fait neuf, n'en est pas moins arrivée après que l'arbitre a
        // regardé. Un votant étendu ne porte que lui-même, et la part des comptes
        // récents ne change rien à la réputation : chez eux, seule une occurrence comptée
        // fait refuser — sinon chaque vote priverait l'arbitre du classement aussi
        // longtemps qu'il dure, et un essaim de comptes neufs le tiendrait à l'écart.
        if ($suite->rowCount() === 1 || ($neuf && !in_array($cause, ['votant_etendu', 'comptes_recents'], true))) {
            $pdo->prepare('UPDATE mod_signaux SET detections = detections + 1 WHERE account_id = ? AND cause = ?')
                ->execute([$accountId, $cause]);
        }
    }

    /**
     * Lève des signaux d'ÉTAT : l'état a cessé. Appelée dans la transaction qui
     * le clôt. Un comportement passé ici est une faute de code, pas une donnée :
     * la machine ne ferme jamais ce qu'un arbitre doit voir.
     */
    private static function designaler(PDO $pdo, int $accountId, string ...$causes): void
    {
        if ($causes === []) {
            return;
        }
        foreach ($causes as $cause) {
            if (self::famille($cause) !== 'etat') {
                throw new \LogicException('SelfModerate : seul un arbitre ferme un signal de comportement (' . $cause . ').');
            }
        }
        $ph = implode(',', array_fill(0, count($causes), '?'));
        $leve = $pdo->prepare("DELETE FROM mod_signaux WHERE account_id = ? AND cause IN ($ph)");
        $leve->execute([$accountId, ...$causes]);
        if ($leve->rowCount() > 0) {
            self::resumer($pdo, $accountId);
        }
    }

    /**
     * Remet dans la file un état que l'arbitre a vu et qui s'aggrave : il a jugé
     * ce qu'il voyait, pas la suite. L'occurrence et la détection comptées font
     * refuser un geste fondé sur l'affichage d'avant.
     */
    private static function rouvrirEtat(PDO $pdo, int $accountId, string $cause): void
    {
        $rouvre = $pdo->prepare(
            'UPDATE mod_signaux SET vu_le = NULL, derniere_le = ?, occurrences = occurrences + 1, detections = detections + 1
              WHERE account_id = ? AND cause = ? AND vu_le IS NOT NULL'
        );
        $rouvre->execute([self::maintenant(), $accountId, $cause]);
        if ($rouvre->rowCount() > 0) {
            self::resumer($pdo, $accountId);
        }
    }

    /**
     * Pose un état qu'une réponse déjà donnée couvre : en base, hors de la file,
     * comme s'il avait été vu. Il tombe comme les autres, quand l'état cesse.
     */
    private static function poserHorsDeLaFile(PDO $pdo, int $accountId, string $cause): void
    {
        self::transaction($pdo, static function () use ($pdo, $accountId, $cause): void {
            self::poserSignal($pdo, $accountId, $cause, false);
            $vu = $pdo->prepare('UPDATE mod_signaux SET vu_le = ? WHERE account_id = ? AND cause = ? AND vu_le IS NULL');
            $vu->execute([self::maintenant(), $accountId, $cause]);
            if ($vu->rowCount() > 0) {
                self::resumer($pdo, $accountId);
            }
        });
    }

    /**
     * Ce qu'un geste d'arbitre retire de la file : un comportement se ferme, un
     * état vu reste en base jusqu'à ce qu'il cesse, hors de la file.
     *
     * @param list<array{signal: int, famille: string}> $signaux
     */
    private static function retirerDeLaFile(PDO $pdo, int $accountId, array $signaux): void
    {
        if ($signaux === []) {
            return;
        }
        foreach ($signaux as $s) {
            if ($s['famille'] === 'comportement') {
                $pdo->prepare('DELETE FROM mod_signaux WHERE id = ?')->execute([$s['signal']]);
            } else {
                $pdo->prepare('UPDATE mod_signaux SET vu_le = ? WHERE id = ?')->execute([self::maintenant(), $s['signal']]);
            }
        }
        self::resumer($pdo, $accountId);
    }

    /**
     * Le signal qu'un geste d'arbitre ferme, ou la raison de son refus. Avec
     * `$vu`, celui que la console a affiché, à condition qu'il soit encore
     * ouvert, à voir, du ressort de ce geste, et pas redétecté depuis ;
     * sans lui, le seul signal du ressort de ce geste. Un geste ne ferme jamais
     * une cause que personne n'a désignée, ni un fait arrivé après l'affichage.
     *
     * @param list<array{signal: int, cause: string, detections: int, vu_le: ?int}> $signaux
     * @param array{signal?: int, detections?: int}|null $vu
     * @param callable(array): bool $duGeste
     * @return array{signal: int, cause: string, famille: string, ouvert_le: int, occurrences: int, vu_le: ?int}|string
     */
    private static function signalVise(array $signaux, ?array $vu, callable $duGeste, string $aucun, string $horsGeste): array|string
    {
        $aVoir = array_values(array_filter($signaux, static fn (array $s): bool => $s['vu_le'] === null));
        if ($vu !== null) {
            foreach ($aVoir as $s) {
                if ($s['signal'] !== (int) ($vu['signal'] ?? 0)) {
                    continue;
                }
                if (!$duGeste($s)) {
                    return $horsGeste;
                }

                return $s['detections'] === (int) ($vu['detections'] ?? 0)
                    ? $s
                    : static::t('Ce signalement a changé depuis ton affichage : recharge la console.');
            }

            return static::t('Ce signalement n\'est plus ouvert : recharge la console.');
        }
        $possibles = array_values(array_filter($aVoir, $duGeste));
        if ($possibles === []) {
            return $aVoir === [] ? $aucun : $horsGeste;
        }
        if (count($possibles) > 1) {
            return sprintf(
                static::t('Plusieurs signalements sont ouverts sur ce compte (%s) : nomme celui que tu fermes.'),
                implode(', ', array_column($possibles, 'cause'))
            );
        }

        return $possibles[0];
    }

    /**
     * @param list<array{famille: string}> $signaux
     * @return list<array{famille: string}>
     */
    private static function deFamille(array $signaux, string $famille): array
    {
        return array_values(array_filter($signaux, static fn (array $s): bool => $s['famille'] === $famille));
    }

    /**
     * Recale la case `needs_review` / `review_reason` sur la table, pour qui lit
     * la base sans passer par le moteur. Le moteur ne la relit que pour refuser
     * une case ouverte sans signal (`exigerReprise()`).
     */
    private static function resumer(PDO $pdo, int $accountId): void
    {
        $cause = self::projection(self::signauxDe($pdo, $accountId));
        self::ensureRow($pdo, $accountId);
        $pdo->prepare('UPDATE member_moderation SET needs_review = ?, review_reason = ?, updated_at = ? WHERE account_id = ?')
            ->execute([$cause === null ? 0 : 1, $cause, self::maintenant(), $accountId]);
    }

    /**
     * Refuse d'écrire un signal sur un compte dont la case est ouverte sans aucun
     * signal en table : la reprise n'a pas été faite, et recaler la case effacerait
     * le seul endroit où ce signalement existe encore. Sur une base reprise, la
     * case suit la table à chaque écriture, et ce cas n'arrive pas.
     */
    private static function exigerReprise(PDO $pdo, int $accountId): void
    {
        $case = $pdo->prepare(
            'SELECT 1 FROM member_moderation m
              WHERE account_id = ? AND needs_review = 1
                AND NOT EXISTS (SELECT 1 FROM mod_signaux s WHERE s.account_id = m.account_id)'
        );
        $case->execute([$accountId]);
        if ($case->fetchColumn() !== false) {
            throw self::repriseManquante($accountId);
        }
    }

    /**
     * La même garde pour les listes de l'arbitre, qui lisent les tables sans
     * passer par la ligne d'un compte : sans elle, une reprise oubliée s'y lirait
     * « rien à revoir ».
     */
    private static function exigerRepriseFaite(PDO $pdo): void
    {
        $case = $pdo->prepare(
            'SELECT m.account_id FROM member_moderation m
              WHERE m.needs_review = 1
                AND NOT EXISTS (SELECT 1 FROM mod_signaux s WHERE s.account_id = m.account_id)
              LIMIT 1'
        );
        $case->execute();
        $compte = $case->fetchColumn();
        if ($compte !== false) {
            throw self::repriseManquante((int) $compte);
        }
        $ban = $pdo->prepare(
            'SELECT m.account_id FROM member_moderation m
              WHERE m.banned_until > 0
                AND NOT EXISTS (SELECT 1 FROM mod_peines p WHERE p.account_id = m.account_id AND p.fin IS NULL)
              LIMIT 1'
        );
        $ban->execute();
        $compte = $ban->fetchColumn();
        if ($compte !== false) {
            throw self::repriseManquante((int) $compte, 'peines');
        }
        $orpheline = $pdo->prepare(
            'SELECT p.account_id FROM mod_peines p
              WHERE p.fin IS NULL
                AND NOT EXISTS (SELECT 1 FROM member_moderation m WHERE m.account_id = p.account_id AND m.banned_until > 0)
              LIMIT 1'
        );
        $orpheline->execute();
        $compte = $orpheline->fetchColumn();
        if ($compte !== false) {
            throw self::repriseManquante((int) $compte, 'orpheline');
        }
        $divergente = $pdo->prepare(
            'SELECT p.account_id FROM mod_peines p JOIN member_moderation m ON m.account_id = p.account_id
              WHERE p.fin IS NULL AND m.banned_until > 0
                AND (p.debut <> m.ban_debut OR p.echeance <> m.banned_until)
              LIMIT 1'
        );
        $divergente->execute();
        $compte = $divergente->fetchColumn();
        if ($compte !== false) {
            throw self::repriseManquante((int) $compte, 'divergente');
        }
    }

    /**
     * Ce qui manque, dit à qui peut le réparer. `peines` : un ban d'avant la 0.9.0 ;
     * `fermee` : un ban dont la ligne a été fermée hors du moteur ; `orpheline` : une
     * ligne ouverte sans ban, laissée par un écrivain hors du moteur ; `divergente` :
     * une ligne ouverte qui ne dit plus le ban en cours, levé puis reposé ou
     * prolongé hors du moteur. `migrerBans()` répare les quatre.
     *
     * @param 'signaux'|'peines'|'fermee'|'orpheline'|'divergente' $reprise
     */
    private static function repriseManquante(int $accountId, string $reprise = 'signaux'): RepriseManquante
    {
        return new RepriseManquante(sprintf(match ($reprise) {
            'peines'    => 'SelfModerate : le compte %d a un ban en cours et aucune peine ouverte dans mod_peines — '
                . 'la reprise n\'a pas été faite : appelle Moderate::migrerBans(), juste après avoir créé la table.',
            'fermee'    => 'SelfModerate : le compte %d a un ban en cours dont la ligne dans mod_peines a été fermée hors du moteur — '
                . 'appelle Moderate::migrerBans(), qui la rouvre.',
            'orpheline' => 'SelfModerate : le compte %d porte une peine ouverte dans mod_peines et aucun ban en cours — '
                . 'une peine ne s\'ouvre pas sur une autre ; elle a été fermée hors du moteur (une montée faite service en marche, '
                . 'une écriture SQL) : appelle Moderate::migrerBans(), qui la ferme.',
            'divergente' => 'SelfModerate : le compte %d a un ban en cours que sa ligne ouverte dans mod_peines ne dit plus — '
                . 'un autre début ou une autre échéance, posés hors du moteur (une montée faite service en marche, une écriture SQL) : '
                . 'appelle Moderate::migrerBans(), qui la met d\'accord.',
            default     => 'SelfModerate : le compte %d a une case de signalement ouverte et aucun signal dans mod_signaux — '
                . 'la reprise n\'a pas été faite : appelle Moderate::migrerSignaux() une fois, juste après avoir créé la table.',
        }, $accountId), $accountId);
    }

    /**
     * Reprend dans `mod_signaux` la case unique d'avant la 0.7.0. À appeler UNE
     * fois, juste après avoir créé la table. Tant qu'elle n'a pas tourné, le
     * moteur refuse de lire ou de signaler un compte dont la case est ouverte, et
     * nomme cette fonction : il ne lit plus la case, et l'écraserait. La marque
     * qui empêche de la rejouer est à l'hôte — rejouée, la copie relirait le
     * résumé que le moteur tient dans la case, et un écrivain resté à l'ancienne
     * forme y deviendrait un signal sans un mot. Rend le nombre de signaux repris.
     *
     * Une case ouverte devient une ligne ; sans cause, `signal_anterieur`, que seul
     * un arbitre ferme ; sa date est la dernière écriture de la ligne. Un état ne
     * passe que si son état tient : la chute reste ouverte sous un ban d'arbitre,
     * que rien ne lève avec elle. Un ban automatique en cours reçoit son signal
     * même quand la case portait autre chose — elle laissait un comportement, ou
     * la chute, recouvrir le ban —, vu si la case était close : il a été maintenu.
     * La case se recale ensuite sur la table.
     */
    public static function migrerSignaux(PDO $pdo): int
    {
        $avant = (int) $pdo->query('SELECT COUNT(*) FROM mod_signaux')->fetchColumn();
        $pdo->exec(
            "INSERT OR IGNORE INTO mod_signaux (account_id, cause, ouvert_le, derniere_le)
             SELECT account_id, COALESCE(review_reason, 'signal_anterieur'), updated_at, updated_at
               FROM member_moderation
              WHERE needs_review = 1
                AND CASE review_reason
                      WHEN 'ban_auto'         THEN ban_origine = 'auto' AND banned_until > 0
                      WHEN 'journal_en_panne' THEN banned_until > 0
                      WHEN 'reputation_zero'  THEN convalescent = 1 AND (banned_until = 0 OR ban_origine IS NOT 'auto')
                      ELSE 1
                    END"
        );
        $pdo->exec(
            "INSERT OR IGNORE INTO mod_signaux (account_id, cause, ouvert_le, derniere_le, vu_le)
             SELECT account_id, 'ban_auto', COALESCE(NULLIF(ban_debut, 0), updated_at), COALESCE(NULLIF(ban_debut, 0), updated_at),
                    CASE WHEN needs_review = 0 THEN updated_at END
               FROM member_moderation
              WHERE ban_origine = 'auto' AND banned_until > 0"
        );
        $etats = self::causesEtat();
        $pdo->prepare(
            "UPDATE member_moderation
                SET review_reason = COALESCE(
                        (SELECT cause FROM mod_signaux s WHERE s.account_id = member_moderation.account_id AND s.vu_le IS NULL
                            AND s.cause NOT IN (" . implode(', ', array_fill(0, count($etats), '?')) . ") ORDER BY s.id LIMIT 1),
                        (SELECT cause FROM mod_signaux s WHERE s.account_id = member_moderation.account_id AND s.vu_le IS NULL
                          ORDER BY s.id DESC LIMIT 1)),
                    needs_review = EXISTS (SELECT 1 FROM mod_signaux s WHERE s.account_id = member_moderation.account_id AND s.vu_le IS NULL)"
        )->execute($etats);

        return (int) $pdo->query('SELECT COUNT(*) FROM mod_signaux')->fetchColumn() - $avant;
    }

    /**
     * Met `mod_peines` d'accord avec les bans de `member_moderation`. À appeler
     * juste après avoir créé la table, et de nouveau si une montée s'est faite
     * service en marche : tant qu'ils divergent, le moteur refuse le compte et
     * nomme cette fonction. Rejouée sur une base d'accord, elle ne fait rien.
     * Rend le nombre de lignes ouvertes, rouvertes, fermées ou recalées.
     *
     * - Un ban sans ligne ouverte : la ligne fermée qui porte son début est
     *   rouverte ; sans elle, une ligne s'ouvre, et ce que la base ne savait pas
     *   reste inconnu (`reprise = 1`) : l'arbitre, l'échéance d'avant une
     *   prolongation, un début non enregistré (`debut = 0`). Ses causes sont les
     *   votes dont l'effet est encore négatif à la reprise.
     * - Une ligne ouverte qui ne dit plus le ban en cours — aucun ban, ou un ban
     *   d'un autre début (levé puis reposé hors du moteur) : elle se ferme à
     *   l'instant de la reprise, cause `hors_moteur` ; l'instant réel de sa fin
     *   n'est pas connu.
     * - Une ligne ouverte dont l'échéance n'est plus celle du ban (prolongé hors du
     *   moteur) reçoit l'échéance de l'état, et `reprise = 1` : la prolongation
     *   n'est pas connue.
     */
    public static function migrerBans(PDO $pdo): int
    {
        return self::transaction($pdo, static function () use ($pdo): int {
            $t = self::maintenant();
            $fermees = $pdo->prepare(
                "UPDATE mod_peines SET fin = ?, fin_cause = 'hors_moteur'
                  WHERE fin IS NULL
                    AND NOT EXISTS (SELECT 1 FROM member_moderation m WHERE m.account_id = mod_peines.account_id
                                       AND m.banned_until > 0 AND m.ban_debut = mod_peines.debut)"
            );
            $fermees->execute([$t]);
            $faites = $fermees->rowCount();
            $bans = $pdo->query(
                'SELECT account_id, ban_origine, strikes, ban_motif, ban_debut, banned_until
                   FROM member_moderation m
                  WHERE banned_until > 0
                    AND NOT EXISTS (SELECT 1 FROM mod_peines p WHERE p.account_id = m.account_id AND p.fin IS NULL)'
            )->fetchAll(PDO::FETCH_ASSOC);
            $rouvrir = $pdo->prepare(
                'UPDATE mod_peines SET fin = NULL, fin_cause = NULL, fin_arbitre_id = NULL, fin_arbitre_nom = NULL, echeance = ?
                  WHERE id = (SELECT id FROM mod_peines WHERE account_id = ? AND debut = ? AND debut > 0 ORDER BY id DESC LIMIT 1)'
            );
            foreach ($bans as $b) {
                $rouvrir->execute([(int) $b['banned_until'], (int) $b['account_id'], (int) $b['ban_debut']]);
                if ($rouvrir->rowCount() === 0) {
                    self::ouvrirPeine(
                        $pdo,
                        (int) $b['account_id'],
                        $b['ban_origine'] !== null ? (string) $b['ban_origine'] : null,
                        (int) $b['strikes'],
                        $b['ban_motif'] !== null ? (string) $b['ban_motif'] : null,
                        null,
                        (int) $b['ban_debut'],
                        (int) $b['banned_until'],
                        reprise: true
                    );
                }
                $faites++;
            }
            $recalees = $pdo->prepare(
                'UPDATE mod_peines
                    SET echeance = (SELECT m.banned_until FROM member_moderation m WHERE m.account_id = mod_peines.account_id),
                        reprise = 1
                  WHERE fin IS NULL
                    AND echeance <> (SELECT m.banned_until FROM member_moderation m WHERE m.account_id = mod_peines.account_id)'
            );
            $recalees->execute();

            return $faites + $recalees->rowCount();
        });
    }

    /**
     * Les signaux ouverts sur un compte, dans l'ordre où ils ont été posés.
     *
     * @return list<array{signal: int, cause: string, famille: string, ouvert_le: int,
     *                    derniere_le: int, occurrences: int, detections: int, vu_le: ?int}>
     */
    private static function signauxDe(PDO $pdo, int $accountId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, cause, ouvert_le, derniere_le, occurrences, detections, vu_le FROM mod_signaux WHERE account_id = ? ORDER BY id'
        );
        $stmt->execute([$accountId]);

        return array_map(static fn (array $s): array => [
            'signal'      => (int) $s['id'],
            'cause'       => (string) $s['cause'],
            'famille'     => self::famille((string) $s['cause']),
            'ouvert_le'   => (int) $s['ouvert_le'],
            'derniere_le' => (int) $s['derniere_le'],
            'occurrences' => (int) $s['occurrences'],
            'detections'  => (int) $s['detections'],
            'vu_le'       => $s['vu_le'] !== null ? (int) $s['vu_le'] : null,
        ], $stmt->fetchAll());
    }

    /**
     * La cause que la case unique d'avant aurait portée, parmi les signaux
     * qu'un arbitre n'a pas encore vus : le premier comportement, sinon le
     * dernier état. C'est ce que rendent `review_reason` et la case.
     *
     * @param list<array{cause: string, famille: string, vu_le: ?int}> $signaux
     */
    private static function projection(array $signaux): ?string
    {
        $aVoir = array_values(array_filter($signaux, static fn (array $s): bool => $s['vu_le'] === null));
        foreach ($aVoir as $s) {
            if ($s['famille'] === 'comportement') {
                return $s['cause'];
            }
        }

        return $aVoir === [] ? null : $aVoir[count($aVoir) - 1]['cause'];
    }

    /**
     * @param list<array{cause: string}> $signaux
     * @return list<array{cause: string}>
     */
    private static function deCause(array $signaux, string ...$causes): array
    {
        return array_values(array_filter($signaux, static fn (array $s): bool => in_array($s['cause'], $causes, true)));
    }

    /**
     * Le seul passage vers le journal. Chaque chaîne de l'acte y arrive en UTF-8
     * valide : une cause ou un motif lus en base peuvent porter des octets qu'un
     * journal qui encode strictement refuse, et ce refus fermerait au compte les
     * gestes de l'arbitre. Les octets invalides prennent le caractère de
     * remplacement, comme dans `causesEnTexte()` ; la base garde les siens.
     */
    private static function inscrireAuJournal(array $acte): void
    {
        array_walk_recursive($acte, static function (mixed &$valeur): void {
            if (is_string($valeur) && preg_match('//u', $valeur) !== 1) {
                $valeur = json_decode(json_encode($valeur, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
            }
        });
        self::$journal?->inscrire($acte);
    }

    /**
     * Les causes ouvertes, telles que le journal les reçoit : du texte JSON. Une
     * valeur d'acte reste scalaire, et un journal qui range une colonne par clé
     * n'a rien à sérialiser — avec une liste, il rangeait `Array` sans un mot.
     * Une cause vient de la base, que l'hôte écrit aussi : ce qui n'y est pas de
     * l'UTF-8 est remplacé dans l'acte, sans quoi aucun acte du compte ne
     * s'encoderait plus, et sa peine resterait ouverte au nom d'un journal en panne.
     *
     * @param list<array{cause: string}> $signaux
     */
    private static function causesEnTexte(array $signaux): string
    {
        return json_encode(array_column($signaux, 'cause'), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * Les signaux qu'un acte ferme, en texte JSON : l'instance, sa cause, depuis
     * quand, combien de faits et de détections l'arbitre avait sous les yeux.
     *
     * @param list<array{signal: int, cause: string, ouvert_le: int, occurrences: int, detections: int}> $signaux
     */
    private static function fermesEnTexte(array $signaux): string
    {
        return json_encode(array_map(static fn (array $s): array => [
            'signal'      => $s['signal'],
            'cause'       => $s['cause'],
            'ouvert_le'   => $s['ouvert_le'],
            'occurrences' => $s['occurrences'],
            'detections'  => $s['detections'],
        ], $signaux), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * Les sanctions que la réputation appelle : sous `perteDroitDeVoteSous`, la
     * perte du vote ; au plancher (`banA`), le ban automatique
     * (`sanctionnerReputationZero()`). En service, ni l'une ni l'autre ne tombe :
     * la machine signale (`perte_du_vote_due`, `reputation_zero`), un arbitre
     * décide ; en démonstration, les deux s'inscrivent au journal. Avec `quorumAuxSeuils`, chacune attend que
     * la chute soit portée par assez de voix sans lien entre elles
     * (`voixPourSanction()`, `voixDeLaChute()`) ; sinon rien n'est sanctionné, et
     * la chute est signalée à l'arbitre (`chute_sans_quorum`). Une sanction tombée
     * ne se défait pas ici : un lien formé après elle n'y change rien.
     *
     * Un ban prononcé sans humain est une arme d'escalade à la portée de qui n'a
     * aucun droit d'admin ; un signalement seul ne protège personne tant que
     * l'arbitre n'est pas devant. D'où un ban tenu par trois bornes : la peine est
     * graduée et FINIE, visible dès qu'elle tombe (le signal `ban_auto` attend
     * l'arbitre), et ses deux bouts sont inscrits au journal.
     *
     * Une chute que l'arbitre a classée lui revient quand elle s'aggrave : quand
     * elle atteint le plancher (`$avant`, la réputation d'avant le vote, au-dessus),
     * ou quand, au plancher, elle retire le droit de vote qu'elle laissait.
     */
    private static function enforceThresholds(PDO $pdo, int $accountId, int $reputation, int $avant): void
    {
        if ($reputation >= self::config()->perteDroitDeVoteSous) {
            return;
        }
        $service = self::config()->usage === Usage::SERVICE;
        $auPlancher = $reputation <= self::config()->banA;
        $enPeine = self::enPeineAutomatique($pdo, $accountId);
        $voix = null;
        $vote = $pdo->prepare('SELECT voting_rights, vote_muted_until FROM member_moderation WHERE account_id = ?');
        $vote->execute([$accountId]);
        $droit = $vote->fetch();
        $vote->closeCursor();
        $votait = $droit !== false && (int) $droit['voting_rights'] === 1;
        $suspendu = $droit !== false && (int) $droit['vote_muted_until'] > self::maintenant();
        // Une perte due posée est une décision rendue, qu'elle attende l'arbitre ou
        // qu'il y ait répondu (elle reste alors en base, hors de la file) : le quorum
        // du vote ne se recherche pas à chaque vote, et elle ne revient qu'après une
        // remontée au seuil puis une nouvelle chute. En démonstration, le retrait se
        // retente.
        $voteDu = $votait && self::etatOuvert($pdo, $accountId, 'perte_du_vote_due');
        $voteEnJeu = $votait && !$voteDu;
        if (self::config()->quorumAuxSeuils) {
            // Le quorum ne se cherche que s'il reste une sanction à décider : une
            // peine automatique EST la sanction du plancher, et un vote déjà retiré
            // celle du seuil du vote.
            if ($voteEnJeu || ($auPlancher && !$enPeine)) {
                $voix = self::voixDeLaChute($pdo, $accountId, self::maintenant(), max(
                    self::config()->voixPourSanction(self::config()->perteDroitDeVoteSous - 1),
                    $auPlancher ? self::config()->voixPourSanction(self::config()->banA) : 0
                ));
                if ($voix['indecis']) {
                    error_log(sprintf('SelfModerate : le quorum du compte %d n\'a pas pu décider (travail ou recherches dans l\'index au-delà de leur borne) ; seules ses %d voix trouvées comptent.', $accountId, $voix['voix']));
                }
            }
        }
        $quorum = static fn (int $seuil): bool => $voix === null || $voix['voix'] >= self::config()->voixPourSanction($seuil);
        $perdLeVote = $voteEnJeu && $quorum(self::config()->perteDroitDeVoteSous - 1);

        $retire = false;
        if ($quorum(self::config()->perteDroitDeVoteSous - 1)) {
            // Une suspension en cours répond déjà au seuil du vote : la machine ne
            // retire rien par-dessus.
            $retire = $votait && !$service && !$suspendu && self::retirerLeVote($pdo, $accountId, $reputation);
            // Au plancher, la chute prend sa place, et un ban automatique porte déjà
            // son signal : la perte du vote n'en ajoute pas.
            if (!$auPlancher) {
                self::designaler($pdo, $accountId, 'chute_sans_quorum');
                if ($votait && !$retire) {
                    if ($suspendu) {
                        self::poserHorsDeLaFile($pdo, $accountId, 'perte_du_vote_due');
                    } else {
                        self::signaler($pdo, $accountId, 'perte_du_vote_due');
                    }
                }
                if (($retire || (!$votait && !$service)) && !$enPeine) {
                    self::signaler($pdo, $accountId, 'droit_de_vote_perdu');
                }
            }
        } elseif (!$auPlancher) {
            self::signaler($pdo, $accountId, 'chute_sans_quorum');
        }
        if ($auPlancher) {
            if ($quorum(self::config()->banA)) {
                self::sanctionnerReputationZero($pdo, $accountId);
                self::designaler($pdo, $accountId, 'chute_sans_quorum');
                // Un ban dû que l'arbitre a classé lui revient quand le compte
                // retombe au plancher : il a jugé une chute, pas la suivante.
                if ($service && $avant > self::config()->banA) {
                    self::rouvrirEtat($pdo, $accountId, 'reputation_zero');
                }
            } else {
                self::signaler($pdo, $accountId, 'chute_sans_quorum');
                if ($avant > self::config()->banA || $perdLeVote) {
                    self::rouvrirEtat($pdo, $accountId, 'chute_sans_quorum');
                }
            }
            // La chute prend la place de la perte du vote dans la file : un fait, un
            // signal. En service, la perte que le quorum du vote rend due reste en
            // base, hors de la file : elle est rendue, et la remontée seule la fait
            // tomber — sans elle, chaque vote au plancher la rechercherait et la
            // rendrait à la file après le plancher.
            self::designaler($pdo, $accountId, 'droit_de_vote_perdu');
            if ($service && $votait && $quorum(self::config()->perteDroitDeVoteSous - 1)) {
                self::poserHorsDeLaFile($pdo, $accountId, 'perte_du_vote_due');
            } else {
                self::designaler($pdo, $accountId, 'perte_du_vote_due');
            }
        }

        // Sous le seuil de vote, la convalescence s'ouvre, sanction ou non.
        // `AND convalescent = 0` n'est pas une précaution d'idempotence : sans
        // lui, chaque nouveau downvote remettrait l'horloge à zéro, et un votant
        // patient suffirait à repousser la remontée indéfiniment.
        $pdo->prepare(
            'UPDATE member_moderation SET convalescent = 1, last_regen_at = ? WHERE account_id = ? AND convalescent = 0'
        )->execute([self::maintenant(), $accountId]);
    }

    /**
     * Ce qui borne les voix d'une chute à l'instant `$t` : la fenêtre des plafonds,
     * et la dernière chute soldée (`effacerEffets()`) — les votants d'une chute que
     * la fin d'un ban automatique, échu ou levé, ou une grâce ont soldée ne portent pas la
     * suivante.
     * Une remise tombe souvent à la seconde du vote qui l'inscrit : à cette seconde,
     * un vote d'avant elle a perdu son effet, et un vote d'après en a un, la
     * réputation repartant du départ. Une chute soldée après `$t` ne borne rien, et
     * seule la dernière est gardée : relue après la fin d'un ban qui suivait une
     * autre chute soldée, la composition de ce ban compte aussi les votants de
     * celle-ci.
     *
     * @return array{0: int, 1: int, 2: int} [début, fin, soldée] : un vote compte s'il
     *         tombe entre début et fin, après soldée, ou à sa seconde avec un effet
     */
    private static function fenetreDesVoix(PDO $pdo, int $cible, int $t): array
    {
        self::exigerSchema($pdo);
        $lire = $pdo->prepare('SELECT chute_soldee_le FROM member_moderation WHERE account_id = ?');
        $lire->execute([$cible]);
        $soldee = (int) $lire->fetchColumn();
        $lire->closeCursor();
        if ($soldee > $t) {
            $soldee = 0;
        }

        return [max($t - self::config()->fenetreFarmingJours * 86400, $soldee), $t, $soldee];
    }

    /**
     * Les votants d'une chute à l'instant `$t`, la seule source du quorum et de la
     * composition qu'en voit l'arbitre : ceux dont le solde des votes qui portent
     * contre `$cible` est négatif sur `fenetreDesVoix()`, tous — une borne remplie
     * dans un ordre d'arrivée se remplirait des comptes de la cible. Un vote suivi
     * de son contraire ne fait pas de votant, et un vote d'avant la colonne
     * `effet` (NULL) n'en fait pas non plus. `recent` dit qu'un votant n'a émis que
     * des votes négatifs de compte récent (`ageEtabliSecondes`) ; `premier` et
     * `dernier` sont ses votes négatifs extrêmes.
     *
     * @return list<array{voter_id: int|string, cree_le: int|string, recent: int|string, premier: int|string, dernier: int|string}>
     */
    private static function votantsDeLaChute(PDO $pdo, int $cible, int $t): array
    {
        [$depuis, $jusqua, $soldee] = self::fenetreDesVoix($pdo, $cible, $t);
        // Le seuil est interpolé, pas lié : comparée à un paramètre PDO (TEXT),
        // une expression sans affinité est toujours la plus petite sous SQLite.
        $stmt = $pdo->prepare(
            'SELECT v.voter_id, a.created_at AS cree_le,
                    MIN(CASE WHEN v.value = -1 THEN v.created_at - a.created_at < ' . self::config()->ageEtabliSecondes . ' END) AS recent,
                    MIN(CASE WHEN v.value = -1 THEN v.created_at END) AS premier,
                    MAX(CASE WHEN v.value = -1 THEN v.created_at END) AS dernier
               FROM mod_votes v JOIN accounts a ON a.id = v.voter_id
              WHERE v.target_author = ? AND v.blocked = 0 AND v.effet IS NOT NULL AND v.created_at BETWEEN ? AND ?
                AND (v.created_at > ? OR v.effet <> 0)
           GROUP BY v.voter_id
             HAVING SUM(v.value) < 0'
        );
        $stmt->execute([$cible, $depuis, $jusqua, $soldee]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Les voix d'une chute à l'instant `$t` : le plus grand nombre de ses votants
     * (`votantsDeLaChute()`) sans lien deux à deux (`mutualPairs()`), cherché
     * jusqu'à `$assez`. Les comptes récents font une voix à eux tous, comme ils
     * font une part : un votant récent sort du graphe, et sa classe y ajoute une
     * voix.
     *
     * Les liens d'une paire se lisent depuis `fenetreMeuteJours` avant le plus
     * ancien vote compté de ses deux membres (`mutualPairs()`).
     *
     * `voix` est exact sous `$assez`, et vaut `$assez` dès qu'il est atteint : la
     * recherche s'arrête au quorum. `indecis` dit qu'elle n'a pas pu décider — son
     * travail épuisé (`quorumTravailMax`), ou une lecture des liens au-delà de
     * `quorumRecherchesMax` recherches dans l'index, où seuls les votants liés à
     * personne se comptent (`liesAPersonne()`) : `voix` n'est alors qu'un minimum,
     * et la machine ne sanctionne que ce qu'il porte. `paires_liees` est null quand
     * les liens n'ont pas été lus en entier.
     *
     * @return array{voix: int, votants: int, recents: int, paires_liees: int|null, indecis: bool}
     */
    private static function voixDeLaChute(PDO $pdo, int $cible, int $t, int $assez): array
    {
        $etablis = [];
        $premiers = [];
        $recents = 0;
        foreach (self::votantsDeLaChute($pdo, $cible, $t) as $r) {
            if ((int) $r['recent'] === 1) {
                $recents++;
            } else {
                $etablis[] = (int) $r['voter_id'];
                $premiers[(int) $r['voter_id']] = (int) $r['premier'];
            }
        }
        $classe = min(1, $recents);
        if (self::config()->messagerie === Messagerie::HORS_MOTEUR) {
            // Les liens ne se lisent pas sur cet hôte : chaque votant établi fait
            // sa voix, comme un votant lié à personne, sans aucune recherche.
            return [
                'voix'         => min(count($etablis), max(0, $assez - $classe)) + $classe,
                'votants'      => count($etablis) + $recents,
                'recents'      => $recents,
                'paires_liees' => null,
                'indecis'      => false,
            ];
        }
        $paires = self::mutualPairs($pdo, $etablis, $t, $premiers, self::config()->quorumRecherchesMax);
        if ($paires === null) {
            $sansLien = count(self::liesAPersonne($pdo, $etablis, $t, $premiers, $assez - $classe, self::config()->quorumRecherchesMax));
            $indecis = $sansLien + $classe < $assez;
            $pairesLiees = null;
        } else {
            [$sansLien, $indecis] = self::voixSansLien($etablis, $paires, $assez - $classe);
            $pairesLiees = count($paires);
        }

        return [
            'voix'         => $sansLien + $classe,
            'votants'      => count($etablis) + $recents,
            'recents'      => $recents,
            'paires_liees' => $pairesLiees,
            'indecis'      => $indecis,
        ];
    }

    /**
     * Le plus grand nombre de `$sommets` sans lien deux à deux, cherché jusqu'à
     * `$assez` : exact en dessous, au moins `$assez` sinon. Une séparation : le
     * sommet le moins lié d'abord, pris sans branche quand ses voisins sont liés
     * entre eux (un plus grand ensemble le contient alors) ; une branche s'arrête
     * quand ce qu'elle a pris, plus le nombre de cliques d'une couverture gloutonne
     * du reste, ne dépasse pas le meilleur trouvé. Son travail se compte en
     * sommets lus, chaque lecture du reste en coûtant la taille, et s'arrête à
     * `quorumTravailMax` : d'un graphe trop lourd à lire, la cible ne gagne que
     * les sanctions que les voix déjà trouvées ne portent pas, et sa chute est
     * signalée. Prendre un sommet sans branche coûte aussi : un graphe de votants
     * sans lien s'arrête au quorum, pas à son dernier sommet.
     *
     * @param int[] $sommets
     * @param list<array{int, int}> $paires
     * @return array{0: int, 1: bool} les voix trouvées, et si le budget s'est épuisé avant de décider
     */
    private static function voixSansLien(array $sommets, array $paires, int $assez): array
    {
        if ($assez <= 0) {
            return [0, false];
        }
        $voisins = array_fill_keys($sommets, []);
        foreach ($paires as [$a, $b]) {
            $voisins[$a][$b] = true;
            $voisins[$b][$a] = true;
        }
        $meilleur = 0;
        $travail = 0;
        $epuise = false;
        $cliques = static function (array $reste) use ($voisins, &$travail): int {
            $travail += count($reste);
            $couverture = [];
            foreach (array_keys($reste) as $v) {
                foreach ($couverture as $i => $clique) {
                    foreach ($clique as $u) {
                        if (!isset($voisins[$v][$u])) {
                            continue 2;
                        }
                    }
                    $couverture[$i][] = $v;
                    continue 2;
                }
                $couverture[] = [$v];
            }

            return count($couverture);
        };
        $explorer = static function (array $reste, int $pris) use (&$explorer, &$meilleur, &$travail, &$epuise, $voisins, $assez, $cliques): void {
            while (true) {
                if ($reste === [] || $pris >= $assez) {
                    $meilleur = max($meilleur, $pris);

                    return;
                }
                if (($travail += count($reste)) > self::config()->quorumTravailMax) {
                    $meilleur = max($meilleur, $pris);
                    $epuise = true;

                    return;
                }
                $v = null;
                $autour = [];
                foreach (array_keys($reste) as $u) {
                    $lies = array_intersect_key($voisins[$u], $reste);
                    if ($v === null || count($lies) < count($autour)) {
                        $v = $u;
                        $autour = $lies;
                        if ($lies === []) {
                            break;
                        }
                    }
                }
                foreach (array_keys($autour) as $a) {
                    foreach (array_keys($autour) as $b) {
                        if ($a < $b && !isset($voisins[$a][$b])) {
                            break 3;
                        }
                    }
                }
                $pris++;
                $reste = array_diff_key($reste, $autour, [$v => true]);
            }
            $meilleur = max($meilleur, $pris);
            if ($meilleur >= $assez) {
                return;
            }
            if ($pris + $cliques($reste) <= $meilleur) {
                return;
            }
            $explorer(array_diff_key($reste, $autour, [$v => true]), $pris + 1);
            if ($meilleur >= $assez || $epuise) {
                return;
            }
            $explorer(array_diff_key($reste, [$v => true]), $pris);
        };
        $explorer(array_fill_keys($sommets, true), 0);
        // La fermeture se tient elle-même (`use (&$explorer)`) : sans ce retrait, chaque
        // recherche resterait en mémoire jusqu'au passage du ramasse-cycles.
        $explorer = null;

        return [$meilleur, $epuise && $meilleur < $assez];
    }

    /**
     * En démonstration, le vote se retire au journal d'abord, comme le ban
     * automatique : sans journal, dans la transaction de l'hôte ou sur un refus
     * du journal, il reste, et l'appelant signale la perte due.
     */
    private static function retirerLeVote(PDO $pdo, int $accountId, int $reputation): bool
    {
        if (self::$journal === null || self::dansLaTransactionDeLHote($pdo)) {
            return false;
        }
        // Au plancher, le ban qui suit exige qu'aucune peine ne soit ouverte, et
        // défait tout le vote sinon : l'acte écrit avant lui survivrait à la base.
        $ban = $pdo->prepare('SELECT banned_until FROM member_moderation WHERE account_id = ?');
        $ban->execute([$accountId]);
        $libre = (int) $ban->fetchColumn() === 0;
        $ban->closeCursor();
        if ($reputation <= self::config()->banA && $libre) {
            self::exigerAucunePeineOuverte($pdo, $accountId);
        }
        $signaux = self::signauxDe($pdo, $accountId);
        try {
            self::inscrireAuJournal([
                'acte'       => 'vote_retire',
                'compte'     => $accountId,
                'origine'    => 'auto',
                'debut'      => self::maintenant(),
                'jusqu_a'    => null,
                'reputation' => $reputation,
                'motif'      => 'perte_droit_de_vote',
                'arbitre'    => null,
                'arbitre_id' => null,
                'ouverts'    => self::causesEnTexte($signaux),
                'ferme'      => self::fermesEnTexte(self::deCause($signaux, 'perte_du_vote_due')),
            ]);
        } catch (\Throwable $e) {
            if ($e instanceof LectureDansUneEcriture) {
                throw $e;
            }
            error_log(sprintf('SelfModerate : retrait du vote du compte %d non inscrit au journal — %s', $accountId, $e->getMessage()));

            return false;
        }
        $pdo->prepare('UPDATE member_moderation SET voting_rights = 0 WHERE account_id = ?')->execute([$accountId]);
        self::designaler($pdo, $accountId, 'perte_du_vote_due');

        return true;
    }

    /** Un état est-il posé sur ce compte, vu ou non ? */
    private static function etatOuvert(PDO $pdo, int $accountId, string $cause): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM mod_signaux WHERE account_id = ? AND cause = ?');
        $stmt->execute([$accountId, $cause]);
        $ouvert = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();

        return $ouvert;
    }

    /**
     * Réputation au plancher : en démonstration, bannir quand le journal le
     * permet ; en service, et sinon, signaler.
     * Dans la transaction d'un hôte, le journal ne le permet pas : l'hôte peut
     * encore annuler, et l'acte survivrait à la base. La réputation à zéro y est
     * signalée, comme sans journal ; le ban tombe au premier vote hors d'elle.
     *
     * ⚠️ **Le journal s'écrit AVANT la base**, et l'ordre n'est pas indifférent.
     * Les deux écritures ne peuvent pas tenir dans une même transaction : l'une
     * va dans la base, l'autre là où le déploiement range son journal. Dans cet
     * ordre, une panne laisse une entrée de journal sans effet — elle se lit et
     * se rattrape. Dans l'autre, elle laisserait un compte banni dont plus rien
     * ne dit ni pourquoi ni jusqu'à quand.
     */
    private static function sanctionnerReputationZero(PDO $pdo, int $accountId): void
    {
        $maintenant = self::maintenant();

        if (self::config()->usage === Usage::SERVICE) {
            if (!self::enPeineAutomatique($pdo, $accountId)) {
                self::signaler($pdo, $accountId, 'reputation_zero');
            }

            return;
        }
        if (self::$journal === null || self::dansLaTransactionDeLHote($pdo)) {
            if (!self::enPeineAutomatique($pdo, $accountId)) {
                self::signaler($pdo, $accountId, 'reputation_zero');
            }

            return;
        }

        self::rattraper($pdo, $accountId, contenir: true);
        $stmt = $pdo->prepare('SELECT reputation, banned_until, strikes, strikes_admin FROM member_moderation WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $etat = $stmt->fetch();
        if (!$etat || (int) $etat['reputation'] > self::config()->banA) {
            return;
        }
        // Une peine en cours ne se rallonge pas au downvote suivant. Sans cette
        // garde, sa fin reculerait aussi longtemps qu'on vote contre le compte :
        // la peine graduée existe précisément pour que l'escalade ait un terme.
        // Une peine échue que le journal n'a pas encore refermée tient la même
        // place : aucun ban ne commence avant que la fin du précédent soit écrite.
        if ((int) $etat['banned_until'] > 0) {
            if (!self::enPeineAutomatique($pdo, $accountId)) {
                self::signaler($pdo, $accountId, 'reputation_zero');
            }

            return;
        }

        $episode = (int) $etat['strikes'] + 1;
        $duree   = self::config()->dureeBanPourEpisode($episode);
        $jusqua  = self::arrondirFin($maintenant + $duree);
        $signaux = self::signauxDe($pdo, $accountId);
        self::exigerAucunePeineOuverte($pdo, $accountId);

        // Un refus du journal ne défait pas le vote qui a fait tomber le compte au
        // plancher : la chute est signalée, le ban attend que le journal l'accepte
        // (le vote négatif suivant le retente), et l'arbitre sait pourquoi.
        try {
            self::inscrireAuJournal([
                'acte'    => 'ban_debut',
                'compte'  => $accountId,
                'origine' => 'auto',
                'episode' => $episode,
                'dont_arbitre' => (int) $etat['strikes_admin'],
                'duree'   => $jusqua - $maintenant,
                'debut'   => $maintenant,
                'jusqu_a' => $jusqua,
                'sans_fin' => self::estSansFin($jusqua),
                'motif'   => 'reputation_zero',
                'arbitre' => null,
                'arbitre_id' => null,
                'ouverts' => self::causesEnTexte($signaux),
                'ferme'   => self::fermesEnTexte(self::deCause($signaux, 'reputation_zero', 'ban_en_attente', 'droit_de_vote_perdu', 'perte_du_vote_due', 'chute_sans_quorum')),
            ]);
        } catch (\Throwable $e) {
            if ($e instanceof LectureDansUneEcriture) {
                throw $e;
            }
            error_log(sprintf('SelfModerate : ban automatique du compte %d non inscrit au journal — %s', $accountId, $e->getMessage()));
            // `ban_en_attente` vit et tombe avec la chute ; posé avant elle, il
            // laisse au résumé du compte (`review_reason`) la chute elle-même.
            self::signaler($pdo, $accountId, 'ban_en_attente');
            self::signaler($pdo, $accountId, 'reputation_zero');

            return;
        }

        $pdo->prepare(
            "UPDATE member_moderation
                SET banned_until = ?, ban_debut = ?, ban_origine = 'auto', ban_motif = 'reputation_zero',
                    voting_rights = 0, strikes = ?, updated_at = ?
              WHERE account_id = ?"
        )->execute([$jusqua, $maintenant, $episode, $maintenant, $accountId]);
        self::ouvrirPeine($pdo, $accountId, 'auto', $episode, 'reputation_zero', null, $maintenant, $jusqua);
        // Le ban prend la place de la chute qui l'a causé : un fait, un signal. Un
        // refus précédent de ce ban se ferme avec lui.
        self::designaler($pdo, $accountId, 'reputation_zero', 'ban_en_attente', 'droit_de_vote_perdu', 'chute_sans_quorum');
        self::signaler($pdo, $accountId, 'ban_auto');
    }

    /**
     * Un geste sur un compte que l'hôte ne connaît pas est refusé, au lieu de
     * buter sur la clé étrangère de la ligne de modération, que la route rendait
     * en erreur 500.
     */
    private static function refusCompteInconnu(PDO $pdo, int $accountId): ?array
    {
        $compte = $pdo->prepare('SELECT 1 FROM accounts WHERE id = ?');
        $compte->execute([$accountId]);
        $connu = $compte->fetchColumn() !== false;
        $compte->closeCursor();

        return $connu ? null : ['ok' => false, 'message' => static::t('Compte introuvable.')];
    }

    /**
     * Une peine automatique EST l'état d'une réputation à zéro, et porte son
     * signal (`ban_auto`) : un second pour le même fait survivrait à la peine, sans
     * rien pour le lever. Un ban d'arbitre n'en porte aucun : la chute qui tombe
     * pendant lui se signale, et reste ouverte à sa fin, que rien ne lève avec elle.
     */
    private static function enPeineAutomatique(PDO $pdo, int $accountId): bool
    {
        $peine = $pdo->prepare("SELECT 1 FROM member_moderation WHERE account_id = ? AND banned_until > 0 AND ban_origine = 'auto'");
        $peine->execute([$accountId]);
        $auto = $peine->fetchColumn() !== false;
        $peine->closeCursor();

        return $auto;
    }

    /**
     * L'identifiant de l'arbitre est un entier, quel que soit le mode de l'appelant :
     * hors `strict_types`, PHP convertirait `"1"`, `1.5` ou `true` en identifiant, et
     * un nom fait de chiffres signerait au nom d'un autre compte.
     */
    private static function identifiantArbitre(mixed $arbitre): int
    {
        if (!is_int($arbitre)) {
            throw new \TypeError(sprintf(
                'SelfModerate : l\'arbitre d\'un geste se désigne par l\'identifiant entier de son compte (accounts.id), pas par %s.',
                get_debug_type($arbitre)
            ));
        }

        return $arbitre;
    }

    /**
     * L'arbitre d'un geste est un compte de l'hôte, désigné par son identifiant
     * (`accounts.id`) : un nom se prend, un identifiant non. Il ne fait aucun geste
     * sur son propre compte, ni aucun tant qu'un **autre arbitre** l'a banni : un
     * pair coupe la modération, et c'est signé. Un ban d'origine inconnue peut en
     * être un, et compte comme tel. Banni par la machine, il garde ses gestes :
     * des votants ne coupent pas la modération ; deux arbitres bannis par la
     * machine peuvent donc se lever l'un l'autre, par deux gestes signés. Lu sous
     * le verrou du geste : un ban posé sur l'arbitre pendant ce geste est vu.
     *
     * @return array{id: int, nom: string}|string le compte, ou le refus
     */
    private static function arbitre(PDO $pdo, int $arbitre, int $accountId): array|string
    {
        $stmt = $pdo->prepare(
            'SELECT a.id, a.username, COALESCE(m.banned_until, 0) AS banned_until, m.ban_origine
               FROM accounts a LEFT JOIN member_moderation m ON m.account_id = a.id
              WHERE a.id = ?'
        );
        $stmt->execute([$arbitre]);
        $compte = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($compte === false) {
            return static::t('L\'arbitre doit être un compte de l\'instance : cet identifiant n\'en désigne aucun.');
        }
        if ((int) $compte['id'] === $accountId) {
            return static::t('Un arbitre ne fait aucun geste sur son propre compte.');
        }
        if ((int) $compte['banned_until'] > self::maintenant() && $compte['ban_origine'] !== 'auto') {
            return static::t('Un arbitre sous peine ne fait aucun geste d\'arbitre.');
        }

        return ['id' => (int) $compte['id'], 'nom' => (string) $compte['username']];
    }

    /**
     * Ouvre la ligne d'une peine qui commence, et fige ses causes : les votes
     * reçus dont l'effet est encore négatif. La remise au départ met cet effet à
     * zéro (`effacerEffets()`) ; la copie reste, et dit après l'échéance qui a
     * fait tomber le compte. Sous le verrou, après l'acte de journal, comme
     * l'état qu'elle accompagne.
     *
     * @param array{id: int, nom: string}|null $arbitre null quand c'est la machine
     */
    private static function ouvrirPeine(
        PDO $pdo,
        int $accountId,
        ?string $origine,
        int $episode,
        ?string $motif,
        ?array $arbitre,
        int $debut,
        int $echeance,
        bool $reprise = false
    ): int {
        $pdo->prepare(
            'INSERT INTO mod_peines (account_id, origine, episode, motif, arbitre_id, arbitre_nom,
                                     debut, echeance_initiale, echeance, decide_le, decide_par_id, decide_par_nom, reprise)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $accountId, $origine, $episode, $motif, $arbitre['id'] ?? null, $arbitre['nom'] ?? null,
            $debut, $echeance, $echeance, $debut, $arbitre['id'] ?? null, $arbitre['nom'] ?? null, (int) $reprise,
        ]);
        $peine = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO mod_peine_causes (peine_id, vote_id, voter_id, effet, vote_le)
             SELECT ?, id, voter_id, effet, created_at FROM mod_votes
              WHERE target_author = ? AND blocked = 0 AND effet < 0'
        )->execute([$peine, $accountId]);

        return $peine;
    }

    /**
     * Une peine ne s'ouvre pas sur une autre : une ligne ouverte sans ban en
     * cours a été laissée par un écrivain hors du moteur (une montée faite
     * service en marche, une écriture SQL), et `migrerBans()` la ferme. Vérifié
     * avant l'acte de journal, qui sinon dirait un ban que la base refuserait.
     */
    private static function exigerAucunePeineOuverte(PDO $pdo, int $accountId): void
    {
        $stmt = $pdo->prepare('SELECT id FROM mod_peines WHERE account_id = ? AND fin IS NULL');
        $stmt->execute([$accountId]);
        $ouverte = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($ouverte !== false) {
            throw self::repriseManquante($accountId, 'orpheline');
        }
    }

    /**
     * La peine ouverte reçoit sa nouvelle échéance : c'est la dernière décision
     * qui la porte, signée de l'arbitre qui rallonge.
     *
     * @param array{id: int, nom: string} $par
     */
    private static function prolongerPeine(PDO $pdo, int $accountId, int $echeance, int $decideLe, array $par): void
    {
        $pdo->prepare(
            'UPDATE mod_peines SET echeance = ?, decide_le = ?, decide_par_id = ?, decide_par_nom = ?,
                                   prolongations = prolongations + 1
              WHERE account_id = ? AND fin IS NULL'
        )->execute([$echeance, $decideLe, $par['id'], $par['nom'], $accountId]);
    }

    /**
     * La peine ouverte se ferme : à son échéance, par une levée ou une grâce.
     * `$fin` est l'instant où elle a cessé — l'échéance elle-même quand le temps
     * l'a close, même inscrite plus tard.
     *
     * @param 'echeance'|'levee'|'grace' $cause
     * @param array{id: int, nom: string}|null $arbitre
     */
    private static function fermerPeine(PDO $pdo, int $accountId, int $fin, string $cause, ?array $arbitre): void
    {
        $pdo->prepare(
            'UPDATE mod_peines SET fin = ?, fin_cause = ?, fin_arbitre_id = ?, fin_arbitre_nom = ?
              WHERE account_id = ? AND fin IS NULL'
        )->execute([$fin, $cause, $arbitre['id'] ?? null, $arbitre['nom'] ?? null, $accountId]);
    }

    /** Score (somme votes non bloqués) d'un post ou d'un membre. */
    public static function score(PDO $pdo, string $targetType, int $targetId): int
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(value),0) FROM mod_votes WHERE target_type = ? AND target_id = ? AND blocked = 0');
        $stmt->execute([$targetType, $targetId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Le vote du membre sur cette cible, pour sa page : compté, neutralisé ou
     * tombé, il le voit le même. Une page qui le tairait sur un vote tombé
     * dirait au votant ce qu'en a fait le moteur — un croisement, la part
     * des comptes récents — et rouvrirait des boutons que « déjà voté » refuse.
     */
    public static function userVote(PDO $pdo, int $voterId, string $targetType, int $targetId): ?int
    {
        $stmt = $pdo->prepare('SELECT value FROM mod_votes WHERE voter_id = ? AND target_type = ? AND target_id = ?');
        $stmt->execute([$voterId, $targetType, $targetId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /**
     * Liste des votes bloqués. Rend le votant ET sa cible : vue d'arbitrage, et
     * le contraire de ce que reasonsFor() protège juste en dessous. Un appelant
     * qui la sert sans contrôle d'accès publie qui a voté contre qui.
     *
     * `$saufRaisons` écarte ces raisons dans la requête, avant la limite : une
     * page publique qui les retirerait après verrait son compte baisser à chaque
     * vote écarté, et le daterait. Une page publique passe
     * `RAISONS_DU_CROISEMENT`, et retire les noms.
     *
     * @param string[] $saufRaisons
     */
    public static function blockedVotes(PDO $pdo, int $limit = 30, array $saufRaisons = []): array
    {
        $sauf = $saufRaisons === [] ? '' : ' AND v.blocked_reason NOT IN (' . implode(',', array_fill(0, count($saufRaisons), '?')) . ')';
        $stmt = $pdo->prepare("
            SELECT v.id, v.target_type, v.target_id, v.value, v.blocked_reason, v.created_at,
                   voter.username AS voter, auth.username AS cible
              FROM mod_votes v
              JOIN accounts voter ON voter.id = v.voter_id
              JOIN accounts auth ON auth.id = v.target_author
             WHERE v.blocked = 1$sauf
             ORDER BY v.created_at DESC LIMIT ?
        ");
        $stmt->execute([...array_map('strval', array_values($saufRaisons)), $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Le nombre de votes qu'un membre a reçus et qui ont porté — compté, ou
     * croisé après avoir compté : ceux dont `reasonsFor()` rend le motif. Une
     * page de l'hôte qui affiche des votes reçus compte ceux-ci : une riposte ou
     * un vote de trêve, comptés, désigneraient leur auteur.
     */
    public static function votesRecus(PDO $pdo, int $accountId): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM mod_votes WHERE target_author = ? AND ' . self::VOTE_RECU);
        $stmt->execute([$accountId]);
        $n = (int) $stmt->fetchColumn();
        $stmt->closeCursor();

        return $n;
    }

    /**
     * Les motifs qu'un membre a reçus. Le protocole veut qu'il voie les raisons
     * sans voir qui a voté : aucune colonne de votant ne sort d'ici.
     *
     * La date est ramenée au jour, et l'ordre à l'intérieur d'un jour est
     * alphabétique et non chronologique — à la seconde près, recoupée avec les
     * présences, elle désignerait son auteur. Limite qu'aucun tri ne lève : sur
     * un unique downvote reçu, la personne devine souvent qui l'a émis. Et
     * l'arrondi ne protège pas d'un chiffre public : un score ou une réputation
     * que l'hôte affiche sans session date chaque vote à qui les relève.
     *
     * Le jour est celui du fuseau de l'hôte, compté en PHP : SQLite ne connaît
     * que le fuseau de son processus. Les jours rendus sont ceux des `$limit`
     * motifs les plus récents, lus en entier pour que le dernier garde son ordre
     * alphabétique.
     */
    public static function reasonsFor(PDO $pdo, int $accountId, int $limit = 50): array
    {
        // Un vote qui a porté garde son motif, même croisé ensuite : le retirer
        // dirait à sa cible lequel venait de l'autre. Une riposte ou un vote de
        // trêve n'ont jamais porté, et ne se montrent pas (`VOTE_RECU`).
        $recus = "FROM mod_votes WHERE target_author = ? AND " . self::VOTE_RECU . " AND reason IS NOT NULL AND reason <> ''";
        // Sous ce nombre de votants, un motif peut désigner son auteur : rien n'est rendu.
        if (self::config()->motifsVotantsMin > 1) {
            $votants = $pdo->prepare("SELECT COUNT(DISTINCT voter_id) $recus");
            $votants->execute([$accountId]);
            $assez = (int) $votants->fetchColumn() >= self::config()->motifsVotantsMin;
            $votants->closeCursor();
            if (!$assez) {
                return [];
            }
        }
        $borne = $pdo->prepare("SELECT created_at $recus ORDER BY created_at DESC LIMIT 1 OFFSET ?");
        $borne->bindValue(1, $accountId, PDO::PARAM_INT);
        $borne->bindValue(2, max(0, $limit - 1), PDO::PARAM_INT);
        $borne->execute();
        $dernierLu = $borne->fetchColumn();
        $borne->closeCursor();
        $stmt = $pdo->prepare("SELECT value, reason, reason_code, created_at $recus AND created_at >= ?");
        $stmt->bindValue(1, $accountId, PDO::PARAM_INT);
        $stmt->bindValue(2, $dernierLu === false ? 0 : (int) strtotime(date('Y-m-d', (int) $dernierLu)), PDO::PARAM_INT);
        $stmt->execute();
        $motifs = array_map(static fn (array $v): array => [
            'value'       => $v['value'],
            'reason'      => $v['reason'],
            'reason_code' => $v['reason_code'],
            'jour'        => date('Y-m-d', (int) $v['created_at']),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        usort($motifs, static fn (array $a, array $b): int => [$b['jour'], $a['reason']] <=> [$a['jour'], $b['reason']]);

        return array_slice($motifs, 0, $limit);
    }

    /**
     * Ban prononcé par un arbitre. Le motif est exigé, et contrôlé comme celui
     * d'un downvote : la personne bannie doit pouvoir lire ce qu'on lui
     * reproche. Un ban en cours n'est pas remplacé — l'arbitre le lève d'abord,
     * et les deux gestes restent au journal.
     *
     * Le ban répond aux sanctions que la machine signalait dues : il ferme le ban
     * en attente, et sort de la file la chute au plancher et la perte du vote due,
     * qui restent en base jusqu'à la remontée. Le reste de la revue ne se ferme
     * pas avec lui : un signal de comportement attend qu'un arbitre le classe
     * (`adminClasser()`), un autre signal d'état se lève quand l'état cesse.
     * L'acte dit ce qui était ouvert et ce qu'il ferme.
     *
     * `$arbitre` est le compte qui signe le geste, dans les huit gestes d'arbitre :
     * son identifiant entier (`accounts.id`), vérifié à l'exécution — une chaîne
     * lève une `TypeError`, même hors `strict_types` (`identifiantArbitre()`). Une
     * durée de plus d'un siècle (`DUREE_MAX`) est refusée : ce ban-là se pose sans fin.
     *
     * @param int $arbitre
     *
     * @return array{ok: bool, message: string}
     */
    public static function adminBan(
        PDO $pdo,
        int $accountId,
        mixed $arbitre,
        string $motif,
        ?int $seconds = null,
        bool $sansFin = false
    ): array {
        $sansTrace = self::refusSansJournal();
        if ($sansTrace !== null) {
            return $sansTrace;
        }
        $dansLHote = self::refusTransactionDeLHote($pdo);
        if ($dansLHote !== null) {
            return $dansLHote;
        }
        $inconnu = self::refusCompteInconnu($pdo, $accountId);
        if ($inconnu !== null) {
            return $inconnu;
        }
        $arbitreId = self::identifiantArbitre($arbitre);
        [$motifOk, $pourquoi] = self::validateReason($motif);
        if (!$motifOk) {
            return ['ok' => false, 'message' => $pourquoi];
        }
        $seconds ??= self::config()->dureeBanAdmin();
        if (!$sansFin && $seconds <= 0) {
            return ['ok' => false, 'message' => static::t('Durée de ban invalide.')];
        }
        // Une peine échue se referme avant le verrou, comme au vote. Sous lui,
        // l'état se relit : la décision se prend sur ce qu'il est, pas sur ce
        // qu'il était avant qu'un autre geste passe.
        self::rattraperSeul($pdo, $accountId);

        return self::transaction($pdo, static function () use ($pdo, $accountId, $arbitreId, $motif, $seconds, $sansFin): array {
            $etat = self::etat($pdo, $accountId);
            $qui = self::arbitre($pdo, $arbitreId, $accountId);
            if (is_string($qui)) {
                return ['ok' => false, 'message' => $qui];
            }
            if ($etat['banned']) {
                return ['ok' => false, 'message' => self::estSansFin($etat['banned_until'])
                    ? static::t('Déjà banni sans fin : lève ce ban avant d\'en poser un autre.')
                    : sprintf(
                        static::t('Déjà banni jusqu\'au %s : lève ce ban avant d\'en poser un autre.'),
                        date('d/m/Y H:i', $etat['banned_until'])
                    )];
            }
            $enAttente = self::refusFinEnAttente($etat);
            if ($enAttente !== null) {
                return $enAttente;
            }

            $maintenant = self::maintenant();
            if (!$sansFin && $seconds > self::DUREE_MAX) {
                return ['ok' => false, 'message' => static::t('Durée trop longue : au-delà d\'un siècle, un ban se pose sans fin.')];
            }
            self::exigerAucunePeineOuverte($pdo, $accountId);
            $jusqua     = $sansFin ? self::SANS_FIN : $maintenant + $seconds;
            $episode    = $etat['strikes'] + 1;
            $parArbitre = $etat['strikes_admin'] + 1;
            self::inscrireAuJournal([
                'acte'    => 'ban_debut',
                'compte'  => $accountId,
                'origine' => 'admin',
                'episode' => $episode,
                'dont_arbitre' => $parArbitre,
                'duree'   => $sansFin ? null : $seconds,
                'debut'   => $maintenant,
                'jusqu_a' => $jusqua,
                'sans_fin' => $sansFin,
                'motif'   => trim($motif),
                'arbitre' => $qui['nom'],
                'arbitre_id' => $qui['id'],
                'raison'  => $etat['review_reason'],
                'ouverts' => self::causesEnTexte($etat['signaux']),
                'ferme'   => self::fermesEnTexte(self::deCause($etat['signaux'], ...self::CAUSES_DUES)),
            ]);
            // Le droit de vote n'est pas touché : un banni ne vote pas (`canVote()`),
            // et la perte du vote reste à la réputation et à son quorum — un ban
            // d'arbitre qui la poserait la ferait tomber sans voix.
            $pdo->prepare(
                "UPDATE member_moderation
                    SET banned_until = ?, ban_debut = ?, ban_origine = 'admin', ban_motif = ?,
                        strikes = ?, updated_at = ?
                  WHERE account_id = ?"
            )->execute([$jusqua, $maintenant, trim($motif), $episode, $maintenant, $accountId]);
            self::ouvrirPeine($pdo, $accountId, 'admin', $episode, trim($motif), $qui, $maintenant, $jusqua);
            // Le ban de l'arbitre répond à la sanction que la machine lui signalait due :
            // le ban automatique refusé se ferme ; la chute et la perte du vote, qui
            // durent tant que la réputation ne remonte pas, quittent la file et restent
            // en base, pour revenir à l'arbitre si elles s'aggravent.
            self::designaler($pdo, $accountId, 'ban_en_attente');
            self::retirerDeLaFile($pdo, $accountId, self::deCause($etat['signaux'], 'reputation_zero', 'perte_du_vote_due'));
            // Un ban d'arbitre compte comme un épisode, et alourdit le ban automatique
            // suivant : la récidive se paie, quelle que soit la main qui l'a constatée.
            // Leur enchaînement sur un même compte passe sous un autre regard.
            $pdo->prepare('UPDATE member_moderation SET strikes_admin = strikes_admin + 1 WHERE account_id = ?')
                ->execute([$accountId]);
            if ($parArbitre >= self::config()->bansArbitreSignal) {
                self::signaler($pdo, $accountId, 'bans_arbitre');
            }
            if ($sansFin) {
                self::poserSansFinVu($pdo, $accountId);
            }

            return ['ok' => true, 'message' => $sansFin
                ? static::t('Compte banni sans fin.')
                : sprintf(static::t('Compte banni jusqu\'au %s.'), date('d/m/Y H:i', $jusqua))];
        });
    }

    /**
     * Suspension du vote par un arbitre : le geste plus léger que le ban, qui
     * répond en service à `perte_du_vote_due` et la sort de la file. Le motif est
     * exigé et contrôlé comme au ban ; la durée est celle d'un ban d'arbitre par
     * défaut, un siècle au plus (`DUREE_MAX`). Elle s'inscrit au journal
     * (`vote_retire`, origine `admin`), et se lève par `adminRendreVote()` ou par
     * la grâce.
     *
     * @param int $arbitre
     *
     * @return array{ok: bool, message: string}
     */
    public static function adminSuspendreVote(PDO $pdo, int $accountId, mixed $arbitre, string $motif, ?int $seconds = null): array
    {
        $sansTrace = self::refusSansJournal();
        if ($sansTrace !== null) {
            return $sansTrace;
        }
        $dansLHote = self::refusTransactionDeLHote($pdo);
        if ($dansLHote !== null) {
            return $dansLHote;
        }
        $inconnu = self::refusCompteInconnu($pdo, $accountId);
        if ($inconnu !== null) {
            return $inconnu;
        }
        $arbitreId = self::identifiantArbitre($arbitre);
        [$motifOk, $pourquoi] = self::validateReason($motif);
        if (!$motifOk) {
            return ['ok' => false, 'message' => $pourquoi];
        }
        $seconds ??= self::config()->dureeBanAdmin();
        if ($seconds <= 0 || $seconds > self::DUREE_MAX) {
            return ['ok' => false, 'message' => static::t('Durée de suspension invalide : strictement positive, et d\'au plus un siècle.')];
        }
        self::rattraperSeul($pdo, $accountId);

        return self::transaction($pdo, static function () use ($pdo, $accountId, $arbitreId, $motif, $seconds): array {
            $etat = self::etat($pdo, $accountId);
            $qui = self::arbitre($pdo, $arbitreId, $accountId);
            if (is_string($qui)) {
                return ['ok' => false, 'message' => $qui];
            }
            $enAttente = self::refusFinEnAttente($etat);
            if ($enAttente !== null) {
                return $enAttente;
            }
            if ($etat['vote_muted']) {
                return ['ok' => false, 'message' => sprintf(
                    static::t('Vote déjà suspendu jusqu\'au %s : rends-le avant d\'en poser une autre suspension.'),
                    date('d/m/Y H:i', $etat['vote_muted_until'])
                )];
            }
            $maintenant = self::maintenant();
            $jusqua = $maintenant + $seconds;
            self::inscrireAuJournal([
                'acte'       => 'vote_retire',
                'compte'     => $accountId,
                'origine'    => 'admin',
                'debut'      => $maintenant,
                'jusqu_a'    => $jusqua,
                'reputation' => $etat['reputation'],
                'motif'      => trim($motif),
                'arbitre'    => $qui['nom'],
                'arbitre_id' => $qui['id'],
                'ouverts'    => self::causesEnTexte($etat['signaux']),
                'ferme'      => self::fermesEnTexte(self::deCause($etat['signaux'], 'perte_du_vote_due')),
            ]);
            $pdo->prepare('UPDATE member_moderation SET vote_muted_until = ?, updated_at = ? WHERE account_id = ?')
                ->execute([$jusqua, $maintenant, $accountId]);
            // Comme au ban : la perte due quitte la file et reste en base, réponse
            // rendue, jusqu'à ce que la réputation repasse le seuil du vote.
            self::retirerDeLaFile($pdo, $accountId, self::deCause($etat['signaux'], 'perte_du_vote_due'));

            return ['ok' => true, 'message' => sprintf(static::t('Vote suspendu jusqu\'au %s.'), date('d/m/Y H:i', $jusqua))];
        });
    }

    /**
     * Fin d'une suspension du vote avant son échéance, par un arbitre, inscrite au
     * journal (`vote_rendu`, origine `admin`). Le motif est facultatif ; donné, il
     * se contrôle comme celui d'une levée.
     *
     * @param int $arbitre
     *
     * @return array{ok: bool, message: string}
     */
    public static function adminRendreVote(PDO $pdo, int $accountId, mixed $arbitre, ?string $motif = null): array
    {
        $sansTrace = self::refusSansJournal();
        if ($sansTrace !== null) {
            return $sansTrace;
        }
        $dansLHote = self::refusTransactionDeLHote($pdo);
        if ($dansLHote !== null) {
            return $dansLHote;
        }
        $inconnu = self::refusCompteInconnu($pdo, $accountId);
        if ($inconnu !== null) {
            return $inconnu;
        }
        $arbitreId = self::identifiantArbitre($arbitre);
        $motif = $motif !== null ? trim($motif) : '';
        if ($motif !== '') {
            [$motifOk, $pourquoi] = self::validateReason($motif);
            if (!$motifOk) {
                return ['ok' => false, 'message' => $pourquoi];
            }
        }
        self::rattraperSeul($pdo, $accountId);

        return self::transaction($pdo, static function () use ($pdo, $accountId, $arbitreId, $motif): array {
            $etat = self::etat($pdo, $accountId);
            $qui = self::arbitre($pdo, $arbitreId, $accountId);
            if (is_string($qui)) {
                return ['ok' => false, 'message' => $qui];
            }
            $enAttente = self::refusFinEnAttente($etat);
            if ($enAttente !== null) {
                return $enAttente;
            }
            if (!$etat['vote_muted']) {
                return ['ok' => false, 'message' => static::t('Aucune suspension du vote en cours sur ce compte.')];
            }
            $maintenant = self::maintenant();
            self::inscrireAuJournal([
                'acte'       => 'vote_rendu',
                'compte'     => $accountId,
                'origine'    => 'admin',
                'debut'      => $maintenant,
                'jusqu_a'    => $etat['vote_muted_until'],
                'motif'      => $motif !== '' ? $motif : null,
                'arbitre'    => $qui['nom'],
                'arbitre_id' => $qui['id'],
                'ouverts'    => self::causesEnTexte($etat['signaux']),
                'ferme'      => self::fermesEnTexte([]),
            ]);
            $pdo->prepare('UPDATE member_moderation SET vote_muted_until = 0, updated_at = ? WHERE account_id = ?')
                ->execute([$maintenant, $accountId]);

            return ['ok' => true, 'message' => static::t('Vote rendu.')];
        });
    }

    /**
     * Grâce d'un arbitre : lève le ban en cours, rend le vote, remet la
     * réputation au départ et les strikes à zéro, lève une suspension de vote
     * et efface le rang de meute. Sans ban en cours, elle fait tout cela sauf
     * lever un ban.
     *
     * Elle ferme ce qu'elle défait : les signaux d'état, dont l'état est remis
     * au départ, et la récidive de meute quand elle en efface le rang. Une
     * salve, un votant étendu restent à classer : une grâce
     * passe sans motif une fois le plancher franchi — jamais sur un ban sans
     * fin —, et un comportement ne se ferme pas sans motif écrit. L'acte dit ce qui était ouvert et ce qu'elle
     * a fermé.
     *
     * 🔑 **Le plancher.** Avant `plancherFraction` de la peine en cours, la grâce
     * exige un motif, et s'inscrit comme levée anticipée avec le temps qui
     * restait. Le plancher ne ferme pas le favoritisme : il le rend coûteux,
     * parce qu'il laisse une trace signée d'un nom.
     *
     * @return array{ok: bool, message: string, anticipee?: bool}
     */
    public static function adminPardon(PDO $pdo, int $accountId, mixed $arbitre, ?string $motif = null): array
    {
        $sansTrace = self::refusSansJournal();
        if ($sansTrace !== null) {
            return $sansTrace;
        }
        $dansLHote = self::refusTransactionDeLHote($pdo);
        if ($dansLHote !== null) {
            return $dansLHote;
        }
        $inconnu = self::refusCompteInconnu($pdo, $accountId);
        if ($inconnu !== null) {
            return $inconnu;
        }
        $arbitreId = self::identifiantArbitre($arbitre);
        $motif = $motif !== null ? trim($motif) : '';
        if ($motif !== '') {
            [$motifOk, $pourquoi] = self::validateReason($motif);
            if (!$motifOk) {
                return ['ok' => false, 'message' => $pourquoi];
            }
        }
        self::rattraperSeul($pdo, $accountId);

        return self::transaction($pdo, static function () use ($pdo, $accountId, $arbitreId, $motif): array {
            $etat       = self::etat($pdo, $accountId);
            $qui = self::arbitre($pdo, $arbitreId, $accountId);
            if (is_string($qui)) {
                return ['ok' => false, 'message' => $qui];
            }
            $enAttente  = self::refusFinEnAttente($etat);
            if ($enAttente !== null) {
                return $enAttente;
            }
            $maintenant = self::maintenant();
            $anticipee  = false;
            $rangEfface = self::rangDe($pdo, $accountId);
            $fermes     = array_values(array_filter($etat['signaux'], static fn (array $s): bool =>
                $s['famille'] === 'etat' || ($s['cause'] === 'meute_recidive' && $rangEfface > 0) || $s['cause'] === 'bans_arbitre'));
            $ouverts    = self::causesEnTexte($etat['signaux']);
            $ferme      = self::fermesEnTexte($fermes);

            if ($etat['banned']) {
                $plancher  = $etat['ban_debut'] > 0
                    ? self::config()->plancherDe($etat['ban_debut'], $etat['banned_until'])
                    : null;
                $anticipee = $plancher !== null && $maintenant < $plancher;
                if (($anticipee || self::estSansFin($etat['banned_until'])) && $motif === '') {
                    return ['ok' => false, 'message' => self::refusLeveeSansMotif(self::estSansFin($etat['banned_until']), $plancher)];
                }
                self::inscrireAuJournal([
                    'acte'      => 'ban_leve',
                    'compte'    => $accountId,
                    'origine'   => $etat['ban_origine'],
                    'arbitre'   => $qui['nom'],
                    'arbitre_id' => $qui['id'],
                    'motif'     => $motif !== '' ? $motif : null,
                    'cause'     => 'grace',
                    'anticipee' => $anticipee,
                    'reste'     => self::estSansFin($etat['banned_until']) ? null : $etat['banned_until'] - $maintenant,
                    'plancher'  => $plancher,
                    'sans_fin'  => self::estSansFin($etat['banned_until']),
                    'rang_efface'      => $rangEfface,
                    'suspension_levee' => $etat['vote_muted'],
                    'ouverts'   => $ouverts,
                    'ferme'     => $ferme,
                ]);
            } else {
                self::inscrireAuJournal([
                    'acte'       => 'grace',
                    'compte'     => $accountId,
                    'arbitre'    => $qui['nom'],
                    'arbitre_id' => $qui['id'],
                    'motif'      => $motif !== '' ? $motif : null,
                    'reputation' => $etat['reputation'],
                    'rang_efface'      => $rangEfface,
                    'suspension_levee' => $etat['vote_muted'],
                    'ouverts'    => $ouverts,
                    'ferme'      => $ferme,
                ]);
            }

            $pdo->prepare(
                'UPDATE member_moderation
                    SET banned_until = 0, ban_debut = 0, ban_origine = NULL, ban_motif = NULL,
                        voting_rights = 1, reputation = ?, strikes = 0, strikes_admin = 0,
                        convalescent = 0, vote_muted_until = 0, updated_at = ?
                  WHERE account_id = ?'
            )->execute([self::config()->reputationInitiale, $maintenant, $accountId]);
            if ($etat['banned_until'] > 0) {
                self::fermerPeine($pdo, $accountId, $maintenant, 'grace', $qui);
            }
            self::designaler($pdo, $accountId, ...array_column(self::deFamille($fermes, 'etat'), 'cause'));
            self::retirerDeLaFile($pdo, $accountId, self::deFamille($fermes, 'comportement'));
            self::effacerEffets($pdo, $accountId, solder: true);
            if ($rangEfface > 0) {
                $pdo->prepare(
                    "INSERT INTO mod_pack_flags (voter_id, target_author, rang, action, vote_ids, detected_at)
                     VALUES (?, ?, 0, 'grace', NULL, ?)"
                )->execute([$accountId, $accountId, $maintenant]);
            }

            return ['ok' => true, 'anticipee' => $anticipee, 'message' => match (true) {
                $anticipee      => static::t('Ban levé avant son plancher : la levée anticipée et son motif sont au journal.'),
                $etat['banned'] => static::t('Ban levé.'),
                default         => static::t('Réputation remise au point de départ.'),
            }];
        });
    }

    /**
     * Lève le ban en cours sans gracier : la peine s'arrête maintenant, dans
     * l'état que son échéance aurait laissé — la réputation qu'un ban automatique
     * rend au départ, le vote que la réputation permet —, et la récidive reste
     * comptée (`strikes`, `strikes_admin`). La grâce, elle, remet tout au départ.
     *
     * Avant le plancher de la peine, le motif est exigé, comme pour la grâce ; un
     * ban sans fin ne se lève qu'avec un motif écrit, quel que soit son plancher.
     *
     * @return array{ok: bool, message: string, anticipee?: bool}
     */
    public static function adminLever(PDO $pdo, int $accountId, mixed $arbitre, ?string $motif = null): array
    {
        $sansTrace = self::refusSansJournal();
        if ($sansTrace !== null) {
            return $sansTrace;
        }
        $dansLHote = self::refusTransactionDeLHote($pdo);
        if ($dansLHote !== null) {
            return $dansLHote;
        }
        $inconnu = self::refusCompteInconnu($pdo, $accountId);
        if ($inconnu !== null) {
            return $inconnu;
        }
        $arbitreId = self::identifiantArbitre($arbitre);
        $motif = $motif !== null ? trim($motif) : '';
        if ($motif !== '') {
            [$motifOk, $pourquoi] = self::validateReason($motif);
            if (!$motifOk) {
                return ['ok' => false, 'message' => $pourquoi];
            }
        }
        self::rattraperSeul($pdo, $accountId);

        return self::transaction($pdo, static function () use ($pdo, $accountId, $arbitreId, $motif): array {
            $etat = self::etat($pdo, $accountId);
            $qui = self::arbitre($pdo, $arbitreId, $accountId);
            if (is_string($qui)) {
                return ['ok' => false, 'message' => $qui];
            }
            $enAttente = self::refusFinEnAttente($etat);
            if ($enAttente !== null) {
                return $enAttente;
            }
            if (!$etat['banned']) {
                return ['ok' => false, 'message' => static::t('Aucun ban en cours à lever.')];
            }
            $maintenant = self::maintenant();
            $sansFin    = self::estSansFin($etat['banned_until']);
            $plancher   = $etat['ban_debut'] > 0 ? self::config()->plancherDe($etat['ban_debut'], $etat['banned_until']) : null;
            $anticipee  = $plancher !== null && $maintenant < $plancher;
            if (($anticipee || $sansFin) && $motif === '') {
                return ['ok' => false, 'message' => self::refusLeveeSansMotif($sansFin, $plancher)];
            }

            // L'échéance avancée à maintenant : le calcul est celui de la fin d'une
            // peine, sans le `ban_fin`, que la levée remplace au journal.
            $row = self::ligne($pdo, $accountId);
            $row['banned_until'] = $maintenant;
            $avance = self::avancer($row, $maintenant);
            $tombent = [...$avance['tombent'], 'ban_sans_fin'];
            self::inscrireAuJournal([
                'acte'      => 'ban_leve',
                'compte'    => $accountId,
                'origine'   => $etat['ban_origine'],
                'arbitre'   => $qui['nom'],
                'arbitre_id' => $qui['id'],
                'motif'     => $motif !== '' ? $motif : null,
                'cause'     => 'levee',
                'anticipee' => $anticipee,
                'reste'     => $sansFin ? null : $etat['banned_until'] - $maintenant,
                'plancher'  => $plancher,
                'sans_fin'  => $sansFin,
                'ouverts'   => self::causesEnTexte($etat['signaux']),
                'ferme'     => self::fermesEnTexte(self::deCause($etat['signaux'], ...$tombent)),
            ]);
            $l = $avance['ligne'];
            if ($avance['horloge']) {
                $l['last_regen_at'] = $maintenant;
            }
            $colonnes = self::colonnesChangees($l, $row);
            $poses = implode(', ', array_map(static fn (string $col): string => "$col = ?", $colonnes));
            $pdo->prepare("UPDATE member_moderation SET $poses, updated_at = ? WHERE account_id = ?")
                ->execute([...array_map(static fn (string $col): mixed => $l[$col], $colonnes), $maintenant, $accountId]);
            self::fermerPeine($pdo, $accountId, $maintenant, 'levee', $qui);
            if ($avance['effacer']) {
                self::effacerEffets($pdo, $accountId, $avance['solder']);
            }
            self::designaler($pdo, $accountId, ...$tombent);

            return ['ok' => true, 'anticipee' => $anticipee, 'message' => $anticipee
                ? static::t('Ban levé avant son plancher : la levée anticipée et son motif sont au journal.')
                : static::t('Ban levé : la peine s\'arrête ici, la récidive reste comptée.')];
        });
    }

    /**
     * Rallonge le ban d'arbitre en cours : une échéance plus lointaine de
     * `$secondes`, ou sans fin. Même épisode, aucun strike de plus : c'est la même
     * peine, plus longue. Un ban automatique ne se rallonge pas : l'arbitre qui
     * veut une autre peine le lève, la récidive restant comptée, et pose la sienne.
     * Une échéance à plus d'un siècle (`DUREE_MAX`) est refusée : ce ban-là se
     * pose sans fin.
     *
     * @return array{ok: bool, message: string}
     */
    public static function adminProlonger(
        PDO $pdo,
        int $accountId,
        mixed $arbitre,
        string $motif,
        int $secondes = 0,
        bool $sansFin = false
    ): array {
        $sansTrace = self::refusSansJournal();
        if ($sansTrace !== null) {
            return $sansTrace;
        }
        $dansLHote = self::refusTransactionDeLHote($pdo);
        if ($dansLHote !== null) {
            return $dansLHote;
        }
        $inconnu = self::refusCompteInconnu($pdo, $accountId);
        if ($inconnu !== null) {
            return $inconnu;
        }
        $arbitreId = self::identifiantArbitre($arbitre);
        [$motifOk, $pourquoi] = self::validateReason($motif);
        if (!$motifOk) {
            return ['ok' => false, 'message' => $pourquoi];
        }
        if (!$sansFin && $secondes <= 0) {
            return ['ok' => false, 'message' => static::t('Durée de prolongation invalide.')];
        }
        self::rattraperSeul($pdo, $accountId);

        return self::transaction($pdo, static function () use ($pdo, $accountId, $arbitreId, $motif, $secondes, $sansFin): array {
            $etat = self::etat($pdo, $accountId);
            $qui = self::arbitre($pdo, $arbitreId, $accountId);
            if (is_string($qui)) {
                return ['ok' => false, 'message' => $qui];
            }
            $enAttente = self::refusFinEnAttente($etat);
            if ($enAttente !== null) {
                return $enAttente;
            }
            if (!$etat['banned']) {
                return ['ok' => false, 'message' => static::t('Aucun ban en cours à rallonger.')];
            }
            if (self::estSansFin($etat['banned_until'])) {
                return ['ok' => false, 'message' => static::t('Ce ban est déjà sans fin.')];
            }
            // Seule une peine d'arbitre se rallonge : celle de la machine dure le palier
            // de son épisode. Un ban d'origine inconnue, posé avant qu'elle ne
            // s'enregistre, peut être automatique.
            if ($etat['ban_origine'] !== 'admin') {
                return ['ok' => false, 'message' => static::t('Seul un ban d\'arbitre se rallonge : lève celui-ci — la récidive reste comptée —, puis pose le tien.')];
            }
            $maintenant = self::maintenant();
            if (!$sansFin && ($secondes > self::DUREE_MAX || $etat['banned_until'] + $secondes - $maintenant > self::DUREE_MAX)) {
                return ['ok' => false, 'message' => static::t('Durée trop longue : au-delà d\'un siècle, un ban se pose sans fin.')];
            }
            $jusqua = $sansFin ? self::SANS_FIN : $etat['banned_until'] + $secondes;
            self::inscrireAuJournal([
                'acte'     => 'ban_prolonge',
                'compte'   => $accountId,
                'origine'  => $etat['ban_origine'],
                'arbitre'  => $qui['nom'],
                'arbitre_id' => $qui['id'],
                'motif'    => trim($motif),
                'de'       => $etat['banned_until'],
                'jusqu_a'  => $jusqua,
                'sans_fin' => $sansFin,
                'ouverts'  => self::causesEnTexte($etat['signaux']),
                'ferme'    => self::fermesEnTexte([]),
            ]);
            $pdo->prepare('UPDATE member_moderation SET banned_until = ?, updated_at = ? WHERE account_id = ?')
                ->execute([$jusqua, $maintenant, $accountId]);
            self::prolongerPeine($pdo, $accountId, $jusqua, $maintenant, $qui);
            if ($sansFin) {
                self::poserSansFinVu($pdo, $accountId);
            }

            return ['ok' => true, 'message' => $sansFin
                ? static::t('Ban prolongé sans fin.')
                : sprintf(static::t('Ban prolongé jusqu\'au %s.'), date('d/m/Y H:i', $jusqua))];
        });
    }

    /**
     * Un ban sans fin se signale, déjà vu par l'arbitre qui l'a posé : il reste
     * parmi les signaux ouverts du compte sans attendre de revue.
     */
    private static function poserSansFinVu(PDO $pdo, int $accountId): void
    {
        self::signaler($pdo, $accountId, 'ban_sans_fin');
        self::retirerDeLaFile($pdo, $accountId, self::deCause(self::signauxDe($pdo, $accountId), 'ban_sans_fin'));
    }

    /** Le refus d'une levée sans motif : avant le plancher, ou à tout moment pour un ban sans fin. */
    private static function refusLeveeSansMotif(bool $sansFin, ?int $plancher): string
    {
        return $sansFin
            ? static::t('Un ban sans fin ne se lève qu\'avec un motif écrit.')
            : sprintf(static::t('Levée anticipée : sans motif écrit, ce ban ne se lève qu\'à partir du %s.'), date('d/m/Y H:i', (int) $plancher));
    }

    /**
     * Prend acte d'un ban automatique sans le lever : l'arbitre a regardé, la
     * peine court. Seul un ban automatique attend cette revue — un ban
     * d'arbitre a déjà eu son regard humain, et un comportement signalé sur un
     * compte banni se classe. Le signal du ban reste en base, vu, jusqu'à la
     * fin de la peine. Le motif est exigé — un maintien sans raison ne se
     * distingue pas d'un oubli.
     *
     * `$vu` est ce que la console a affiché, `['signal' => id, 'detections' => n]` :
     * si le signal a été redétecté depuis, le geste refuse. Sans lui, le geste
     * vise le signal tel qu'il est au moment du clic.
     *
     * @param array{signal: int, detections: int}|null $vu
     * @return array{ok: bool, message: string}
     */
    public static function adminMaintenir(PDO $pdo, int $accountId, mixed $arbitre, string $motif, ?array $vu = null): array
    {
        $sansTrace = self::refusSansJournal();
        if ($sansTrace !== null) {
            return $sansTrace;
        }
        $dansLHote = self::refusTransactionDeLHote($pdo);
        if ($dansLHote !== null) {
            return $dansLHote;
        }
        $inconnu = self::refusCompteInconnu($pdo, $accountId);
        if ($inconnu !== null) {
            return $inconnu;
        }
        $arbitreId = self::identifiantArbitre($arbitre);
        [$motifOk, $pourquoi] = self::validateReason($motif);
        if (!$motifOk) {
            return ['ok' => false, 'message' => $pourquoi];
        }
        self::rattraperSeul($pdo, $accountId);

        return self::transaction($pdo, static function () use ($pdo, $accountId, $arbitreId, $motif, $vu): array {
            $etat = self::etat($pdo, $accountId);
            $qui = self::arbitre($pdo, $arbitreId, $accountId);
            if (is_string($qui)) {
                return ['ok' => false, 'message' => $qui];
            }
            if (!$etat['banned']) {
                return ['ok' => false, 'message' => static::t('Aucun ban en cours sur ce compte.')];
            }
            $aucun = static::t('Ce ban n\'attend aucune revue : un signalement se classe.');
            $vise  = self::signalVise($etat['signaux'], $vu, static fn (array $s): bool => $s['cause'] === 'ban_auto', $aucun, $aucun);
            if (is_string($vise)) {
                return ['ok' => false, 'message' => $vise];
            }
            self::inscrireAuJournal([
                'acte'    => 'ban_maintenu',
                'compte'  => $accountId,
                'origine' => $etat['ban_origine'],
                'arbitre' => $qui['nom'],
                'arbitre_id' => $qui['id'],
                'motif'   => trim($motif),
                'jusqu_a' => $etat['banned_until'],
                'raison'  => $vise['cause'],
                'ouverts' => self::causesEnTexte($etat['signaux']),
                'ferme'   => self::fermesEnTexte([$vise]),
            ]);
            self::retirerDeLaFile($pdo, $accountId, [$vise]);

            return ['ok' => true, 'message' => static::t('Ban maintenu ; la revue est close.')];
        });
    }

    /**
     * Classe UN signalement sans rien toucher d'autre : l'arbitre a regardé, et
     * le compte n'appelle aucun geste. La grâce remettrait la réputation au
     * départ, ce qu'un signalement classé ne justifie pas. Le motif est exigé,
     * comme pour un maintien.
     *
     * Un comportement classé se ferme. Une réputation à zéro classée reste en
     * base, vue, tant que la réputation n'est pas remontée. Une levée en attente
     * classée se ferme : l'arbitre a jugé qu'il n'y a rien à rendre, et vue, elle
     * ferait taire le refus suivant. Un ban automatique se maintient, il ne se
     * classe pas, et une fin de peine qui attend le journal n'est pas au compte
     * d'en répondre.
     *
     * `$vu` est ce que la console a affiché, `['signal' => id, 'detections' => n]` :
     * si le signal a été fermé ou redétecté depuis, le geste refuse — même
     * quand la détection n'a pas compté de fait neuf, une salve d'autres votants
     * peut s'y être repliée : l'arbitre ne ferme pas ce qu'il n'a pas vu. Sans
     * lui, le geste vise le seul signal classable, et refuse s'il y en a plusieurs.
     *
     * @param array{signal: int, detections: int}|null $vu
     * @return array{ok: bool, message: string}
     */
    public static function adminClasser(PDO $pdo, int $accountId, mixed $arbitre, string $motif, ?array $vu = null): array
    {
        $sansTrace = self::refusSansJournal();
        if ($sansTrace !== null) {
            return $sansTrace;
        }
        $dansLHote = self::refusTransactionDeLHote($pdo);
        if ($dansLHote !== null) {
            return $dansLHote;
        }
        $inconnu = self::refusCompteInconnu($pdo, $accountId);
        if ($inconnu !== null) {
            return $inconnu;
        }
        $arbitreId = self::identifiantArbitre($arbitre);
        [$motifOk, $pourquoi] = self::validateReason($motif);
        if (!$motifOk) {
            return ['ok' => false, 'message' => $pourquoi];
        }
        self::rattraperSeul($pdo, $accountId);

        return self::transaction($pdo, static function () use ($pdo, $accountId, $arbitreId, $motif, $vu): array {
            $etat = self::etat($pdo, $accountId);
            $qui = self::arbitre($pdo, $arbitreId, $accountId);
            if (is_string($qui)) {
                return ['ok' => false, 'message' => $qui];
            }
            $enAttente = self::refusFinEnAttente($etat);
            if ($enAttente !== null) {
                return $enAttente;
            }
            $vise = self::signalVise(
                $etat['signaux'],
                $vu,
                // En démonstration, la perte du vote due attend le journal comme le ban
                // dû : classée, le vote suivant retenterait quand même le retrait.
                static fn (array $s): bool => !in_array($s['cause'], ['ban_auto', 'journal_en_panne', 'ban_en_attente'], true)
                    && !($s['cause'] === 'perte_du_vote_due' && self::config()->usage === Usage::DEMONSTRATION),
                static::t('Aucun signalement en cours sur ce compte.'),
                static::t('Ce signal ne se classe pas : un ban automatique se maintient ; une fin de peine, un ban dû ou, en démonstration, une perte du vote due attendent le journal.')
            );
            if (is_string($vise)) {
                return ['ok' => false, 'message' => $vise];
            }
            self::inscrireAuJournal([
                'acte'    => 'revue_classee',
                'compte'  => $accountId,
                'arbitre' => $qui['nom'],
                'arbitre_id' => $qui['id'],
                'motif'   => trim($motif),
                'raison'  => $vise['cause'],
                'ouverts' => self::causesEnTexte($etat['signaux']),
                'ferme'   => self::fermesEnTexte([$vise]),
            ]);
            if ($vise['cause'] === 'levee_en_attente') {
                self::designaler($pdo, $accountId, 'levee_en_attente');
            } else {
                self::retirerDeLaFile($pdo, $accountId, [$vise]);
            }

            return ['ok' => true, 'message' => static::t('Signalement classé.')];
        });
    }

    /** Le compte purge-t-il un ban ? Pour l'hôte qui décide ce qu'un ban bloque au-delà du vote. */
    public static function estBanni(PDO $pdo, int $accountId): bool
    {
        return self::getReputation($pdo, $accountId)['banned'];
    }

    /**
     * Les bans en cours, avec ce qu'un arbitre doit voir avant de toucher à
     * l'un d'eux : l'origine, le motif, le temps restant, la date du plancher,
     * et pour un ban automatique la composition de sa chute.
     *
     * `a_revoir` : un ban automatique que personne n'a encore maintenu ; `revue`
     * est alors ce que `adminMaintenir()` attend en `$vu`.
     *
     * `arbitre` est le compte qui a posé le ban, `decide_par` celui de la
     * dernière décision (le ban, ou sa dernière prolongation) ; null pour la
     * machine ou une peine reprise d'avant la 0.9.0 (`migrerBans()`). `peine` est
     * sa ligne dans l'historique (`peinesDe()`).
     *
     * @return list<array{account_id: int, username: string, origine: ?string, motif: ?string,
     *                    debut: int, jusqu_a: int, reste: int, sans_fin: bool, plancher: ?int, episode: int,
     *                    a_revoir: bool, revue: ?array{signal: int, detections: int},
     *                    chute: ?array{votants: int, ages_jours: list<int>, etalement: int, voix_sans_lien: int,
     *                                  recents: int, paires_liees: int|null, indecis: bool},
     *                    peine: int, arbitre: ?array{id: int, nom: string}, echeance_initiale: int,
     *                    prolongations: int, decide_par: ?array{id: int, nom: string}}>
     */
    public static function bansEnCours(PDO $pdo, int $limite = 100): array
    {
        self::exigerRepriseFaite($pdo);
        $maintenant = self::maintenant();
        $stmt = $pdo->prepare(
            "SELECT m.account_id, a.username, m.ban_origine, m.ban_motif, m.ban_debut, m.banned_until,
                    m.strikes, s.id AS revue, s.detections,
                    p.id AS peine, p.arbitre_id, p.arbitre_nom, p.echeance_initiale, p.prolongations,
                    p.decide_par_id, p.decide_par_nom
               FROM member_moderation m JOIN accounts a ON a.id = m.account_id
          LEFT JOIN mod_signaux s ON s.account_id = m.account_id AND s.cause = 'ban_auto' AND s.vu_le IS NULL
          LEFT JOIN mod_peines p ON p.account_id = m.account_id AND p.fin IS NULL
              WHERE m.banned_until > 0 AND m.banned_until > ? ORDER BY m.banned_until ASC LIMIT ?"
        );
        $stmt->bindValue(1, $maintenant, PDO::PARAM_INT);
        $stmt->bindValue(2, $limite, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static function (array $r) use ($pdo, $maintenant): array {
            $debut = (int) $r['ban_debut'];
            $fin   = (int) $r['banned_until'];
            $auto  = $r['ban_origine'] === 'auto';
            return [
                'account_id' => (int) $r['account_id'],
                'username'   => (string) $r['username'],
                'origine'    => $r['ban_origine'] !== null ? (string) $r['ban_origine'] : null,
                'motif'      => $r['ban_motif'] !== null ? (string) $r['ban_motif'] : null,
                'debut'      => $debut,
                'jusqu_a'    => $fin,
                'reste'      => $fin - $maintenant,
                'sans_fin'   => self::estSansFin($fin),
                'plancher'   => $debut > 0 ? self::config()->plancherDe($debut, $fin) : null,
                'episode'    => (int) $r['strikes'],
                'a_revoir'   => $r['revue'] !== null,
                'revue'      => $r['revue'] !== null ? ['signal' => (int) $r['revue'], 'detections' => (int) $r['detections']] : null,
                'chute'      => $auto ? self::compositionChute($pdo, (int) $r['account_id'], $debut) : null,
                'peine'      => (int) $r['peine'],
                'arbitre'    => self::compteSigne($r['arbitre_id'], $r['arbitre_nom']),
                'echeance_initiale' => (int) $r['echeance_initiale'],
                'prolongations'     => (int) $r['prolongations'],
                'decide_par'        => self::compteSigne($r['decide_par_id'], $r['decide_par_nom']),
            ];
        }, $stmt->fetchAll());
    }

    /** Un arbitre lu en base : `{id, nom}`, ou null quand c'est la machine ou une reprise. */
    private static function compteSigne(mixed $id, mixed $nom): ?array
    {
        return $id !== null ? ['id' => (int) $id, 'nom' => (string) $nom] : null;
    }

    /**
     * L'historique des peines d'un compte, de la plus récente à la plus
     * ancienne : qui l'a prononcée, sur quel motif, jusqu'à quand, comment elle
     * a fini, et combien de votes et de votants l'avaient causée quand elle a
     * commencé. Des nombres, pas des noms ; et des **jours**, pas des secondes : le
     * début d'une peine automatique est l'instant du vote qui l'a causée, et à la
     * seconde, recoupé avec les présences, il désignerait son auteur. Les dates
     * sont ramenées à minuit, au fuseau de l'hôte, comme les motifs de
     * `reasonsFor()` ; `$precis` les rend à la seconde, pour la console d'un
     * arbitre. `fin` reste null tant que la peine n'est pas close en base — une
     * peine échue que le moteur n'a pas encore inscrite l'est déjà pour
     * `getReputation()`. Les causes sont les votes à effet négatif en cours au
     * début de la peine : pour un ban automatique, ceux qui l'ont causée.
     *
     * @return list<array{peine: int, origine: ?string, episode: int, motif: ?string,
     *                    arbitre: ?array{id: int, nom: string}, debut: int, echeance_initiale: int,
     *                    echeance: int, sans_fin: bool, decide_le: int, decide_par: ?array{id: int, nom: string},
     *                    prolongations: int,
     *                    fin: ?int, fin_cause: ?string, fin_arbitre: ?array{id: int, nom: string},
     *                    reprise: bool, causes: array{votes: int, votants: int}}>
     */
    public static function peinesDe(PDO $pdo, int $accountId, int $limite = 50, bool $precis = false): array
    {
        // Un ban en cours sans sa ligne manquerait à la liste sans un mot : la
        // lecture du compte refuse à sa place.
        self::ligne($pdo, $accountId);
        $stmt = $pdo->prepare(
            'SELECT p.*, (SELECT COUNT(*) FROM mod_peine_causes c WHERE c.peine_id = p.id) AS votes,
                    (SELECT COUNT(DISTINCT c.voter_id) FROM mod_peine_causes c WHERE c.peine_id = p.id) AS votants
               FROM mod_peines p WHERE p.account_id = ? ORDER BY p.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $accountId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limite, PDO::PARAM_INT);
        $stmt->execute();

        // Minuit du jour, sauf pour l'inconnu (0) et le sans-fin, qui restent des sentinelles.
        $jour = static fn (?int $t): ?int => $t === null || $precis || $t <= 0 || self::estSansFin($t)
            ? $t
            : (int) strtotime(date('Y-m-d', $t));

        return array_map(static fn (array $p): array => [
            'peine'             => (int) $p['id'],
            'origine'           => $p['origine'] !== null ? (string) $p['origine'] : null,
            'episode'           => (int) $p['episode'],
            'motif'             => $p['motif'] !== null ? (string) $p['motif'] : null,
            'arbitre'           => self::compteSigne($p['arbitre_id'], $p['arbitre_nom']),
            'debut'             => $jour((int) $p['debut']),
            'echeance_initiale' => $jour((int) $p['echeance_initiale']),
            'echeance'          => $jour((int) $p['echeance']),
            'sans_fin'          => self::estSansFin((int) $p['echeance']),
            'decide_le'         => $jour((int) $p['decide_le']),
            'decide_par'        => self::compteSigne($p['decide_par_id'], $p['decide_par_nom']),
            'prolongations'     => (int) $p['prolongations'],
            'fin'               => $jour($p['fin'] !== null ? (int) $p['fin'] : null),
            'fin_cause'         => $p['fin_cause'] !== null ? (string) $p['fin_cause'] : null,
            'fin_arbitre'       => self::compteSigne($p['fin_arbitre_id'], $p['fin_arbitre_nom']),
            'reprise'           => (bool) $p['reprise'],
            'causes'            => ['votes' => (int) $p['votes'], 'votants' => (int) $p['votants']],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Qui a fait tomber un compte jusqu'à `$debut` : les votants que le quorum lit
     * (`votantsDeLaChute()`), l'âge de leurs comptes à cet
     * instant, et le temps entre leur premier et leur dernier vote négatif. Des
     * faits bruts, sans seuil ni score. Une coordination espacée entre comptes qui
     * ne se sont jamais écrit n'est reconnue par aucune détection : c'est ici
     * qu'un arbitre peut la voir.
     *
     * S'y ajoute ce que le quorum en tire, relu sur la base du moment :
     * `voix_sans_lien`, exact sous le quorum du ban et égal à ce quorum sinon —
     * un minimum quand `indecis` ;
     * `recents`, les votants que leur âge range dans une seule voix ;
     * `paires_liees`, les liens entre les autres, null quand la lecture des liens
     * dépasse sa borne (`quorumRecherchesMax`) ; `indecis`, une
     * recherche qui n'a pas pu décider ; `liens`, ce que valent ces liens :
     * `lus`, `non_lus` sur un hôte dont la messagerie passe hors du moteur, `muets`
     * quand l'hôte déclare `dm` et que la table n'a aucune ligne — chaque votant
     * y fait alors sa voix (`etatDuSite()`).
     *
     * @return array{votants: int, ages_jours: list<int>, etalement: int, voix_sans_lien: int,
     *               recents: int, paires_liees: int|null, indecis: bool, liens: string}
     */
    public static function compositionChute(PDO $pdo, int $accountId, int $debut): array
    {
        $votants = self::votantsDeLaChute($pdo, $accountId, $debut);
        $ages = array_map(static fn (array $v): int => intdiv(max(0, $debut - (int) $v['cree_le']), 86400), $votants);
        sort($ages);
        $quorum = self::voixDeLaChute($pdo, $accountId, $debut, self::config()->voixPourSanction(self::config()->banA));

        return [
            'votants'        => count($votants),
            'ages_jours'     => $ages,
            'etalement'      => $votants === []
                ? 0
                : max(array_map('intval', array_column($votants, 'dernier'))) - min(array_map('intval', array_column($votants, 'premier'))),
            'voix_sans_lien' => $quorum['voix'],
            'recents'        => $quorum['recents'],
            'paires_liees'   => $quorum['paires_liees'],
            'indecis'        => $quorum['indecis'],
            'liens'          => self::config()->messagerie === Messagerie::HORS_MOTEUR
                ? 'non_lus'
                : (self::dmSansLigne($pdo) ? 'muets' : 'lus'),
        ];
    }

    /**
     * L'état du site, que l'arbitre lit à côté de ses signaux : ce qui contredit
     * la déclaration de l'hôte, calculé à la lecture, sans rien écrire — un état
     * de site n'a pas de compte, et `mod_signaux` en exige un.
     *
     * `liens_muets` : l'hôte déclare `dm`, la table n'a aucune ligne, et le
     * premier vote a plus de `fenetreMeuteJours` jours : depuis une fenêtre
     * entière, le quorum compte chaque votant pour une voix sans que personne
     * l'ait déclaré. Une `dm` vide est l'état d'un site neuf : l'alarme attend.
     *
     * @return list<array{cause: string, depuis: int}>
     */
    public static function etatDuSite(PDO $pdo): array
    {
        $etats = [];
        if (self::config()->messagerie === Messagerie::DM && self::dmSansLigne($pdo)) {
            $premier = $pdo->query('SELECT MIN(created_at) FROM mod_votes')->fetchColumn();
            if ($premier !== null && $premier !== false
                && self::maintenant() - (int) $premier > self::config()->fenetreMeuteJours * 86400) {
                $etats[] = ['cause' => 'liens_muets', 'depuis' => (int) $premier];
            }
        }

        return $etats;
    }

    /** La table `dm` est-elle sans aucune ligne ? Lue sur un hôte qui déclare `dm`. */
    private static function dmSansLigne(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query('SELECT 1 FROM dm LIMIT 1');
        } catch (\PDOException $e) {
            throw self::dmIntrouvable($pdo, $e);
        }
        $vide = $stmt->fetchColumn() === false;
        $stmt->closeCursor();

        return $vide;
    }

    /**
     * Membres à arbitrer, une ligne par compte, celui qui attend depuis le plus
     * longtemps en tête : un flot de signaux neufs ne pousse pas les anciens
     * hors de la page. `review_reason` rend une cause, celle que la case unique
     * aurait portée ; `signaux` les rend toutes. Les valeurs sont listées dans le
     * schéma de référence, leurs deux familles dans `famille()`. Un drapeau sans
     * sa cause laisserait l'admin appliquer le même geste à des situations
     * opposées.
     */
    public static function flaggedForReview(PDO $pdo, int $limit = 50): array
    {
        self::exigerRepriseFaite($pdo);
        $stmt = $pdo->prepare(
            'SELECT m.account_id, a.username, m.reputation, m.strikes, m.convalescent
               FROM mod_signaux s
               JOIN member_moderation m ON m.account_id = s.account_id
               JOIN accounts a ON a.id = s.account_id
              WHERE s.vu_le IS NULL
           GROUP BY s.account_id ORDER BY MIN(s.ouvert_le), s.account_id LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static function (array $r) use ($pdo): array {
            $signaux = self::signauxDe($pdo, (int) $r['account_id']);

            return [...$r, 'review_reason' => self::projection($signaux), 'signaux' => $signaux];
        }, $stmt->fetchAll());
    }

    /**
     * Les signaux à voir, une ligne par signal, en pages par cause : la plus
     * ancienne en tête de chaque page, au plus `$parCause` lignes par cause, et
     * `total_cause` pour dire ce que la page tait. Un tiers qui provoque cent
     * salves remplit la page des salves, et aucune autre cause ne sort de la vue.
     * `signal` et `detections` sont ce qu'un geste rend en `$vu`.
     *
     * @return list<array{signal: int, account_id: int, username: string, cause: string, famille: string,
     *                    ouvert_le: int, derniere_le: int, occurrences: int, detections: int, reputation: ?int, total_cause: int}>
     */
    public static function signauxOuverts(PDO $pdo, int $parCause = 50, ?string $cause = null): array
    {
        self::exigerRepriseFaite($pdo);
        $causes = $pdo->prepare(
            'SELECT cause, COUNT(*) AS total FROM mod_signaux WHERE vu_le IS NULL' . ($cause === null ? '' : ' AND cause = ?')
            . ' GROUP BY cause ORDER BY MIN(ouvert_le), cause'
        );
        $causes->execute($cause === null ? [] : [$cause]);
        $page = $pdo->prepare(
            'SELECT s.id, s.account_id, a.username, s.cause, s.ouvert_le, s.derniere_le, s.occurrences, s.detections, m.reputation
               FROM mod_signaux s
               JOIN accounts a ON a.id = s.account_id
          LEFT JOIN member_moderation m ON m.account_id = s.account_id
              WHERE s.cause = ? AND s.vu_le IS NULL
           ORDER BY s.ouvert_le, s.id LIMIT ?'
        );

        $lignes = [];
        foreach ($causes->fetchAll() as $c) {
            $page->bindValue(1, (string) $c['cause']);
            $page->bindValue(2, $parCause, PDO::PARAM_INT);
            $page->execute();
            foreach ($page->fetchAll() as $s) {
                $lignes[] = [
                    'signal'      => (int) $s['id'],
                    'account_id'  => (int) $s['account_id'],
                    'username'    => (string) $s['username'],
                    'cause'       => (string) $s['cause'],
                    'famille'     => self::famille((string) $s['cause']),
                    'ouvert_le'   => (int) $s['ouvert_le'],
                    'derniere_le' => (int) $s['derniere_le'],
                    'occurrences' => (int) $s['occurrences'],
                    'detections'  => (int) $s['detections'],
                    'reputation'  => $s['reputation'] !== null ? (int) $s['reputation'] : null,
                    'total_cause' => (int) $c['total'],
                ];
            }
        }

        return $lignes;
    }
}
