<?php
/**
 * SelfModerate — réputation distribuée et anti-manipulation.
 *
 * Vote sur un contenu ou sur un membre (±1) : la réputation affectée est
 * toujours celle d'une personne, le contenu ne fait que dire d'où vient le vote.
 * Défenses : anti-Sybil, upvote-farming, downvote-farming, pack-voting,
 * sanctions graduées.
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
     * Une durée en clair : la plus grande unité qui la divise exactement, sinon
     * des minutes arrondies au-dessus. Les unités passent par le traducteur, pour
     * que l'hôte affiche les seuils de la config dans sa langue.
     */
    public static function dureeEnClair(int $secondes): string
    {
        foreach ([[86400, 'jour', 'jours'], [3600, 'heure', 'heures'], [60, 'minute', 'minutes']] as [$unite, $un, $plusieurs]) {
            if ($secondes >= $unite && $secondes % $unite === 0) {
                $n = intdiv($secondes, $unite);
                return $n . ' ' . static::t($n > 1 ? $plusieurs : $un);
            }
        }
        $n = max(1, intdiv($secondes + 59, 60));
        return $n . ' ' . static::t($n > 1 ? 'minutes' : 'minute');
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
     * Les seuils en service — leur seule source, pour le moteur comme pour les
     * pages de l'hôte. Par défaut ceux de la démo.
     */
    private static ?Config $config = null;

    /** Le journal des sanctions. Sans lui, le moteur ne bannit pas seul. */
    private static ?Journal $journal = null;

    public static function setConfig(?Config $config): void
    {
        self::$config = $config;
    }

    public static function config(): Config
    {
        return self::$config ??= Config::demo();
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
     * (`journal_en_panne`) : `banned_until` la garde, alors que `banned` la dit
     * finie. Ni un ban, ni une grâce, ni un classement ne passe par-dessus — le
     * ban la recouvrirait, la grâce la refermerait sans que sa fin soit écrite
     * nulle part, le classement effacerait le seul signal qui en reste. Le ban
     * automatique tient la même règle.
     *
     * @param array{banned: bool, banned_until: int} $etat
     * @return array{ok: false, message: string}|null
     */
    private static function refusFinEnAttente(array $etat): ?array
    {
        return $etat['banned_until'] > 0 && !$etat['banned']
            ? ['ok' => false, 'message' => static::t('La peine précédente attend que le journal inscrive sa fin : aucun geste ne passe avant.')]
            : null;
    }

    /** Le bannissement automatique est-il armé sur ce déploiement ? */
    public static function banAutomatiqueArme(): bool
    {
        return self::$journal !== null;
    }

    /**
     * Le refus commun aux quatre gestes d'arbitre quand aucun journal n'est
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
        )->execute([$accountId, self::config()->reputationInitiale, time()]);
    }

    public static function getReputation(PDO $pdo, int $accountId): array
    {
        // Lire d'abord, écrire seulement si l'état le demande : la ligne
        // absente, la convalescence, la clôture d'une peine. Dans le cas
        // courant, lire une réputation est un `SELECT` et rien d'autre — une
        // insertion « si absent », même ignorée, prendrait un verrou d'écriture.
        // Quand il y a à écrire, c'est sous le verrou d'écriture, et l'état se
        // relit sous lui : sans cela, N lecteurs d'une peine échue l'inscrivent
        // N fois au journal, et N − 1 d'entre eux échouent en voulant promouvoir
        // leur verrou de lecture — une lecture ne doit jamais échouer pour un
        // autre lecteur.
        $row    = self::ligne($pdo, $accountId);
        $jusqua = $row === null ? 0 : (int) $row['banned_until'];
        if ($row === null || (int) $row['convalescent'] || ($jusqua > 0 && $jusqua <= time())) {
            self::transaction($pdo, static function () use ($pdo, $accountId): void {
                $row = self::ligne($pdo, $accountId);
                if ($row === null) {
                    self::ensureRow($pdo, $accountId);

                    return;
                }
                if ((int) $row['convalescent']) {
                    self::regenererSousVerrou($pdo, $accountId);
                }
                $jusqua = (int) $row['banned_until'];
                if ($jusqua > 0 && $jusqua <= time()) {
                    self::cloreBanExpire($pdo, $accountId, contenir: true);
                }
            });
            $row = self::ligne($pdo, $accountId);
        }
        $signaux = (int) $row['signale'] ? self::signauxDe($pdo, $accountId) : [];
        return [
            'reputation'    => (int) $row['reputation'],
            'strikes'       => (int) $row['strikes'],
            'voting_rights' => (bool) $row['voting_rights'],
            'banned'        => ((int) $row['banned_until']) > time(),
            'banned_until'  => (int) $row['banned_until'],
            'ban_debut'     => (int) $row['ban_debut'],
            'ban_origine'   => $row['ban_origine'] !== null ? (string) $row['ban_origine'] : null,
            'ban_motif'     => $row['ban_motif'] !== null ? (string) $row['ban_motif'] : null,
            'needs_review'  => self::projection($signaux) !== null,
            'review_reason' => self::projection($signaux),
            'signaux'       => $signaux,
            'convalescent'  => (bool) $row['convalescent'],
            'vote_muted'       => ((int) $row['vote_muted_until']) > time(),
            'vote_muted_until' => (int) $row['vote_muted_until'],
        ];
    }

    /**
     * La ligne de modération d'un compte, ou null s'il n'en a pas encore. Elle
     * dit si un signal est ouvert sur lui, sans le lire : un compte calme se lit
     * en une requête, et la lecture ne se fie pas au résumé de la case. Elle le
     * confronte seulement à la table : une case ouverte sans aucun signal dit
     * que la reprise n'a pas été faite, et la lecture refuse.
     */
    private static function ligne(PDO $pdo, int $accountId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT reputation, strikes, voting_rights, banned_until, ban_debut, ban_origine, ban_motif,
                    convalescent, vote_muted_until, needs_review,
                    EXISTS (SELECT 1 FROM mod_signaux s WHERE s.account_id = m.account_id) AS signale
               FROM member_moderation m WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        if ($row !== false && (int) $row['needs_review'] && !(int) $row['signale']) {
            throw self::repriseManquante($accountId);
        }

        return $row === false ? null : $row;
    }

    /**
     * Referme une peine échue, et l'inscrit au journal quand il y en a un.
     *
     * 🔑 **Personne ne s'exécute à l'instant où une peine expire.** La fin se
     * constate donc quand on regarde l'état — ce que `getReputation()` fait à
     * chaque lecture —, et l'entrée porte les deux dates : l'échéance prévue et
     * l'instant où on l'a vue. Les confondre daterait la levée du moment où un
     * curieux a ouvert la fiche. Un déploiement qui veut des entrées à l'heure
     * appelle `balayerBansEchus()` depuis son planificateur ; il n'y est pas
     * tenu, et rien n'est perdu s'il ne le fait pas.
     *
     * La fin d'un ban automatique remet la réputation au point de départ et
     * garde les strikes : le temps de la peine vaut remise à flot, et le ban
     * suivant sera plus long. Sans cette remise, le compte ressortait à zéro et
     * le premier vote contraire le renvoyait au palier supérieur. Un ban
     * d'arbitre n'est pas venu de la réputation, et n'y touche pas en sortant.
     *
     * `banned_until = 0` est l'état « aucune peine à clore » : c'est lui qui
     * rend l'inscription unique, parce que la clôture se décide sous le verrou
     * d'écriture (`transaction()`), à la lecture comme au balayage — un seul
     * passage la voit à faire. Rend vrai s'il a refermé la peine. Défaite
     * avec la transaction qui la porte, elle se refait au passage suivant, et
     * le journal porte alors deux fins pour une peine, que `debut` et `jusqu_a`
     * identifient : `applyVote()` referme donc les peines échues du votant et
     * de la cible avant d'ouvrir la sienne.
     *
     * ⚠️ **Un journal qui refuse d'écrire laisse la peine à refermer.** Avec
     * `$contenir`, le refus est consigné et signalé (`journal_en_panne`), et
     * l'appel continue : `banned` se calcule sur l'heure, donc la personne n'est
     * plus bannie ; le droit de vote et la réputation remise au départ, eux,
     * attendent la clôture. Faire tomber toute page qui lit le compte
     * n'ajouterait aucune trace. La clôture se fait au passage suivant, le
     * journal revenu. Le planificateur, lui, fait remonter le refus.
     */
    private static function cloreBanExpire(PDO $pdo, int $accountId, bool $contenir = false): bool
    {
        $maintenant = time();
        $stmt = $pdo->prepare(
            'SELECT banned_until, ban_debut, ban_origine, ban_motif, strikes FROM member_moderation
              WHERE account_id = ? AND banned_until > 0 AND banned_until <= ?'
        );
        $stmt->execute([$accountId, $maintenant]);
        $row = $stmt->fetch();
        $stmt->closeCursor();
        if (!$row) {
            return false;
        }
        $origine = $row['ban_origine'] !== null ? (string) $row['ban_origine'] : null;
        // La fin d'un ban automatique remet la réputation au départ : la chute et le
        // ban qu'elle a causé cessent ensemble.
        $signaux = self::signauxDe($pdo, $accountId);
        $tombent = array_values(array_filter($signaux, static fn (array $s): bool =>
            $s['cause'] === 'journal_en_panne'
            || ($origine === 'auto' && in_array($s['cause'], ['ban_auto', 'reputation_zero'], true))));

        try {
            self::$journal?->inscrire([
                'acte'      => 'ban_fin',
                'compte'    => $accountId,
                'origine'   => $origine,
                'episode'   => (int) $row['strikes'],
                'debut'     => (int) $row['ban_debut'],
                'jusqu_a'   => (int) $row['banned_until'],
                'observe_a' => $maintenant,
                'motif'     => $row['ban_motif'] ?? self::projection($signaux) ?? 'inconnu',
                'arbitre'   => null,
                'ouverts'   => self::causesEnTexte($signaux),
                'ferme'     => self::fermesEnTexte($tombent),
            ]);
        } catch (\Throwable $e) {
            if (!$contenir) {
                throw $e;
            }
            error_log(sprintf('SelfModerate : fin de peine du compte %d non inscrite au journal — %s', $accountId, $e->getMessage()));
            self::signaler($pdo, $accountId, 'journal_en_panne');
            return false;
        }

        if ($origine === 'auto') {
            $pdo->prepare(
                'UPDATE member_moderation
                    SET reputation = ?, voting_rights = 1, convalescent = 0, last_regen_at = 0
                  WHERE account_id = ?'
            )->execute([self::config()->reputationInitiale, $accountId]);
            self::effacerEffets($pdo, $accountId);
        } else {
            $pdo->prepare('UPDATE member_moderation SET voting_rights = 1 WHERE account_id = ? AND reputation >= ?')
                ->execute([$accountId, self::config()->perteDroitDeVoteSous]);
        }
        $pdo->prepare(
            'UPDATE member_moderation
                SET banned_until = 0, ban_debut = 0, ban_origine = NULL, ban_motif = NULL, updated_at = ?
              WHERE account_id = ?'
        )->execute([$maintenant, $accountId]);
        self::designaler($pdo, $accountId, ...array_column($tombent, 'cause'));

        return true;
    }

    /**
     * Clôt toutes les peines échues en une passe, pour un planificateur.
     *
     * Rend le nombre de peines refermées. Sans journal, elles se referment
     * quand même, sans rien inscrire.
     */
    public static function balayerBansEchus(PDO $pdo, int $limite = 500): int
    {
        $stmt = $pdo->prepare(
            'SELECT account_id FROM member_moderation
              WHERE banned_until > 0 AND banned_until <= ? ORDER BY banned_until LIMIT ?'
        );
        $stmt->bindValue(1, time(), PDO::PARAM_INT);
        $stmt->bindValue(2, $limite, PDO::PARAM_INT);
        $stmt->execute();

        $clos = 0;
        foreach ($stmt->fetchAll() as $row) {
            if (self::transaction($pdo, static fn (): bool => self::cloreBanExpire($pdo, (int) $row['account_id']))) {
                $clos++;
            }
        }

        return $clos;
    }

    /**
     * Convalescence — porte la réputation au niveau que le temps écoulé lui donne.
     *
     * Appelée en tête de getReputation() plutôt qu'à la connexion : un score qui
     * ne se met à jour que pour qui se connecte est faux pour tous les autres,
     * et c'est justement quand on regarde un profil qu'on a besoin du bon
     * chiffre. Ne jamais appeler getReputation() ici : la récursion est immédiate.
     * Elle lit puis écrit, et lève un signal : sous le verrou d'écriture.
     */
    public static function regenerate(PDO $pdo, int $accountId): void
    {
        self::transaction($pdo, static fn () => self::regenererSousVerrou($pdo, $accountId));
    }

    private static function regenererSousVerrou(PDO $pdo, int $accountId): void
    {
        $stmt = $pdo->prepare(
            'SELECT reputation, convalescent, last_regen_at FROM member_moderation WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        if (!$row || !(int) $row['convalescent']) {
            return;
        }
        $now  = time();
        $last = (int) $row['last_regen_at'];
        if ($last <= 0) {
            // Base migrée : l'état existe sans son horloge. Le compte part d'ici.
            $pdo->prepare('UPDATE member_moderation SET last_regen_at = ? WHERE account_id = ?')
                ->execute([$now, $accountId]);
            return;
        }
        $gagnes = intdiv($now - $last, self::config()->intervalleConvalescenceSecondes);
        $depart = (int) $row['reputation'];
        if ($gagnes < 1 && $depart < self::config()->sortieConvalescence()) {
            return;
        }
        // La remontée borne sa propre progression, pas le score : un point gagné
        // par un vote positif pendant la convalescence reste acquis.
        $rep = max($depart, min(self::config()->sortieConvalescence(), $depart + $gagnes));
        // L'horloge avance des intervalles consommés, pas jusqu'à maintenant : le
        // reste de temps est acquis et compte pour le point suivant.
        $pdo->prepare('UPDATE member_moderation SET reputation = ?, last_regen_at = ?, updated_at = ? WHERE account_id = ?')
            ->execute([$rep, $last + $gagnes * self::config()->intervalleConvalescenceSecondes, $now, $accountId]);

        if ($rep >= self::config()->perteDroitDeVoteSous) {
            $pdo->prepare('UPDATE member_moderation SET voting_rights = 1 WHERE account_id = ?')->execute([$accountId]);
        }
        if ($rep >= self::config()->sortieConvalescence()) {
            // Revenu à son point de départ : l'état se lève, et le signalement
            // qui accompagnait la chute n'a plus d'objet. Uniquement celui-là :
            // une récidive de meute ne se rachète pas en attendant que la
            // réputation remonte, et l'admin doit encore la trouver.
            $pdo->prepare('UPDATE member_moderation SET convalescent = 0 WHERE account_id = ?')
                ->execute([$accountId]);
            self::designaler($pdo, $accountId, 'reputation_zero');
            self::effacerEffets($pdo, $accountId);
        }
    }

    /**
     * La réputation est revenue au départ : la remise a rendu d'un coup ce que
     * les votes déjà reçus avaient retiré. Leur effet passe à zéro, pour qu'une
     * annulation ultérieure ne le rende pas une seconde fois.
     */
    private static function effacerEffets(PDO $pdo, int $accountId): void
    {
        $pdo->prepare('UPDATE mod_votes SET effet = 0 WHERE target_author = ? AND (effet IS NULL OR effet <> 0)')
            ->execute([$accountId]);
    }

    /** Anti-Sybil + sanctions : ce membre peut-il voter ? Retourne [bool, raison]. */
    public static function canVote(PDO $pdo, int $accountId): array
    {
        $rep = self::getReputation($pdo, $accountId);
        if ($rep['banned']) {
            return [false, sprintf(static::t('Compte banni jusqu\'au %s.'), date('d/m/Y H:i', $rep['banned_until']))];
        }
        // Avant le seuil de réputation, et non après : les deux refusent le
        // vote, mais l'un s'efface en attendant et l'autre à une date. Rendre
        // « réputation trop basse » à quelqu'un qui purge une suspension
        // l'enverrait guetter une remontée qui ne lui rendra rien.
        if ($rep['vote_muted']) {
            return [false, sprintf(
                static::t('Droit de vote suspendu jusqu\'au %s (participation à une meute).'),
                date('d/m/Y', $rep['vote_muted_until'])
            )];
        }
        if (!$rep['voting_rights']) {
            return [false, static::t('Droit de vote retiré (réputation trop basse).')];
        }
        // Anti-Sybil : compte récent sans activité ne peut pas voter
        $stmt = $pdo->prepare('SELECT created_at FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $createdAt = (int) $stmt->fetchColumn();
        $age = time() - $createdAt;
        $ageMin = self::config()->ageMinPourVoterSecondes;
        if ($age < $ageMin) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE account_id = ?');
            $stmt->execute([$accountId]);
            $nbPosts = (int) $stmt->fetchColumn();
            if ($nbPosts < 1) {
                return [false, sprintf(
                    static::t('Compte trop récent : publie au moins un message ou attends encore %s pour pouvoir voter (anti-Sybil).'),
                    static::dureeEnClair($ageMin - $age)
                )];
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
        )->execute([$voterId, $targetType, $targetId, $author, $value, $reason, $reasonCode, $raison, time()]);
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
     * avant : paramètres, droit de vote, motif, cible.
     *
     * Le journal s'écrit hors de la base, avant elle : une validation qui
     * échoue après lui laisse une entrée sans effet, jamais une peine sans
     * trace. Sous la transaction du vote ne s'écrivent que les entrées que ce
     * vote cause — le début d'un ban, sa levée par une meute reconnue — et une
     * entrée défaite avec lui se lit comme une entrée sans effet : l'acte se
     * réinscrit au passage suivant. Une peine échue du votant ou de la cible
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

        return self::transaction($pdo, static fn (): array =>
            self::inscrireVote($pdo, $voterId, $targetType, $targetId, $author, $value, $reason, $reasonCode));
    }

    /** Un nom par point de reprise posé dans la transaction d'un hôte. */
    private static int $pointsDeReprise = 0;

    /** La connexion sur laquelle ce moteur tient une transaction qu'il a ouverte lui-même. */
    private static ?PDO $transactionOuverteSur = null;

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
     * qui décide et tout acte au journal. Si une autre connexion le tient,
     * SQLite refuse aussitôt, et rien n'est inscrit.
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
        try {
            if (!$propre) {
                self::ordonner($pdo, 'UPDATE member_moderation SET account_id = account_id WHERE 0');
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
                }
            }
            throw $e;
        } finally {
            if ($propre) {
                self::$transactionOuverteSur = null;
            }
        }
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

        [$can, $why] = self::canVote($pdo, $voterId);
        if (!$can) {
            $refus = ['ok' => false, 'message' => $why];

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
        // ⚠️ Ce que cet ordre ferme exactement : sonder exige désormais un motif
        // VALIDE, et un motif valide sur une cible qui existe **inscrit le vote**.
        // La sonde devient donc tracée dans `mod_votes`, visible de l'arbitre, et
        // non répétable — l'index unique `(voter_id, target_type, target_id)` l'y
        // oblige. L'énumération n'est pas rendue impossible, elle est rendue
        // VISIBLE et coûteuse, ce qui vaut mieux sur un terrain où scanner est le
        // jeu : un balayage muet n'apprend rien à personne d'autre qu'à son auteur.
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
        self::getReputation($pdo, $author);

        return $author;
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
                'SELECT COUNT(*) FROM mod_votes WHERE voter_id = ? AND target_author = ? AND value = 1 AND blocked = 0 AND created_at >= ?'
            );
            $stmt->execute([$voterId, $author, time() - self::config()->fenetreFarmingJours * 86400]);
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
                'SELECT COUNT(*) FROM mod_votes WHERE voter_id = ? AND target_author = ? AND value = -1 AND blocked = 0 AND created_at >= ?'
            );
            $stmt->execute([$voterId, $author, time() - self::config()->fenetreFarmingJours * 86400]);
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
                   FROM mod_votes WHERE voter_id = ? AND value = -1 AND blocked = 0 AND created_at >= ?'
            );
            $stmt->execute([$author, $voterId, time() - self::config()->fenetreFarmingJours * 86400]);
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

        // La réputation se lit AVANT l'insertion : le vote garde ce qu'il a
        // réellement changé. Un vote qui bute sur une borne n'a rien changé, et
        // l'annuler plus tard ne doit rien rendre.
        $rep = self::getReputation($pdo, $author);
        $newRep = max(self::config()->banA, min(self::config()->reputationMax, $rep['reputation'] + $value));
        $pdo->prepare(
            'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, reason_code, effet, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$voterId, $targetType, $targetId, $author, $value, $reason, $reasonCode, $newRep - $rep['reputation'], time()]);
        $pdo->prepare('UPDATE member_moderation SET reputation = ?, updated_at = ? WHERE account_id = ?')
            ->execute([$newRep, time(), $author]);

        // La meute se détecte AVANT les seuils : si ce downvote la complète, la
        // réputation est restituée d'abord, et `enforceThresholds()` ne pose ni
        // ban ni strike injuste. Le vote ne regarde que sa cible, sauf le premier
        // de sa tranche, qui balaie le site (`balayageDu()`) et lève ban et strikes a posteriori.
        if ($value === -1) {
            self::detecter($pdo, self::balayageDu($pdo) ? null : $author, $author);
            $newRep = self::getReputation($pdo, $author)['reputation']; // après éventuelle restauration
        }
        self::enforceThresholds($pdo, $author, $newRep);

        return ['ok' => true, 'blocked' => false, 'message' => static::t('Vote pris en compte.')];
    }

    /**
     * Deux comptes sont-ils liés ? Un message privé dans CHAQUE sens sur la
     * fenêtre — un échange consenti des deux côtés, l'équivalent le plus proche
     * de l'invitation acceptée que décrit le protocole. Exiger la réciprocité
     * empêche un spammeur de se rendre invulnérable en écrivant à tout le monde.
     *
     * Le contenu n'est jamais lu : seuls l'expéditeur, le destinataire et la
     * date sont interrogés. Le chiffré reste fermé.
     */
    public static function areLinked(PDO $pdo, int $a, int $b): bool
    {
        return $a !== $b && self::mutualPairs($pdo, [$a, $b]) !== [];
    }

    /**
     * Les paires de `$voters` liées au sens d'`areLinked()`, lues en UNE requête.
     * `areLinked()` passe par ici : la définition du lien n'existe qu'une fois.
     *
     * Une requête par paire ferait payer le carré des votants à chaque vote :
     * les échanges entre eux se lisent d'un coup, seule la réciprocité se
     * vérifie en mémoire.
     *
     * @param int[] $voters
     * @return array<int, array{int, int}> paires [a, b] avec a < b
     */
    private static function mutualPairs(PDO $pdo, array $voters): array
    {
        if (count($voters) < 2) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($voters), '?'));
        $stmt = $pdo->prepare("SELECT DISTINCT sender_id, recipient_id FROM dm
                                WHERE created_at >= ? AND sender_id IN ($ph) AND recipient_id IN ($ph)");
        $stmt->execute(array_merge([time() - self::config()->fenetreMeuteJours * 86400], $voters, $voters));
        $aEcrit = [];
        foreach ($stmt->fetchAll() as $r) {
            $aEcrit[(int) $r['sender_id']][(int) $r['recipient_id']] = true;
        }
        $paires = [];
        foreach ($aEcrit as $a => $destinataires) {
            foreach (array_keys($destinataires) as $b) {
                if ($a < $b && isset($aEcrit[$b][$a])) {
                    $paires[] = [$a, $b];
                }
            }
        }
        return $paires;
    }

    /**
     * Regroupe des votants en composantes de connaissances mutuelles.
     * A–B liés et B–C liés donnent {A,B,C} même si A et C ne se connaissent pas :
     * une meute a un meneur, et exiger que tous se connaissent deux à deux la
     * laisserait passer.
     *
     * @param int[] $voters
     * @return int[][] composantes de taille >= 2, la plus grande d'abord
     */
    private static function linkedGroups(PDO $pdo, array $voters): array
    {
        $parent = [];
        foreach ($voters as $v) {
            $parent[$v] = $v;
        }
        $find = static function (int $x) use (&$parent): int {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }
            return $x;
        };
        foreach (self::mutualPairs($pdo, $voters) as [$a, $b]) {
            $parent[$find($a)] = $find($b);
        }
        $groupes = [];
        foreach ($voters as $v) {
            $groupes[$find($v)][] = $v;
        }
        $groupes = array_values(array_filter($groupes, static fn (array $g): bool => count($g) >= 2));
        usort($groupes, static fn (array $x, array $y): int => count($y) <=> count($x));
        return $groupes;
    }

    /**
     * Ce downvote doit-il balayer tout le site ? Oui pour le premier de sa
     * tranche de `balayagePeriodeSecondes`, non pour les autres.
     *
     * Un vote ne voit que sa cible, et ce qu'il ne voit pas est un lien formé
     * APRÈS les votes : deux votants qui s'écrivent une fois la cible frappée,
     * sur laquelle personne ne revote. Seul un balayage le rattrape ; fait à
     * chaque vote, il rendrait le coût d'un vote proportionnel à l'activité du
     * site. Une fois par tranche, ce coût est borné, et aucun hôte n'a de
     * minuteur à poser pour que le filet existe.
     *
     * 🔑 La clé primaire de `mod_sweeps` arbitre : deux premiers votes
     * simultanés insèrent la même tranche, la base en refuse un, un seul
     * balaie. Seul ce refus-là est rattrapé — une table absente doit faire du
     * bruit, pas éteindre le filet.
     */
    private static function balayageDu(PDO $pdo): bool
    {
        $tranche = intdiv(time(), self::config()->balayagePeriodeSecondes);
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
        return true;
    }

    /**
     * Deux détections, deux conséquences.
     *
     * MEUTE — des votants liés entre eux ont frappé la même cible sur la fenêtre
     * longue. Le groupe ne pèse pas plus qu'un membre seul : le premier votant
     * garde ses votes, ceux des autres sont annulés, la réputation restituée de
     * ce qu'ils avaient pris. Personne n'est puni : le lien ne distingue ni deux
     * amis de bonne foi, ni un lien fabriqué par la cible, d'une coordination.
     *
     * SALVE RAPIDE — plusieurs votants sans aucun lien votent dans la même
     * minute. Ce n'est pas une meute, c'est le plus souvent la même réaction au
     * même message : rien n'est annulé, la cible est signalée à un admin.
     * Annuler ici protégerait un message d'autant mieux qu'il choque plus de
     * monde à la fois — la détection travaillerait alors pour l'abuseur.
     *
     * Ce que ni l'une ni l'autre ne voit : une coordination hors plateforme
     * entre comptes qui ne se sont jamais écrit ici. Elle tombe en salve rapide,
     * donc signalée, jamais annulée.
     *
     * `$seulement` borne le passage à une cible : un vote ne peut faire naître
     * une meute ou une salve que sur la sienne. Sans lui, tout le site.
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
        $maintenant = time();
        $packs = [];
        $salves = [];
        $cancelled = 0;
        $tronques = [];

        // ── Meute : fenêtre longue, critère relationnel ──────────────────────
        $sinceMeute = $maintenant - self::config()->fenetreMeuteJours * 86400;
        // Le seuil est interpolé, pas lié : un paramètre PDO arrive en TEXT, et
        // SQLite range tout INTEGER avant tout TEXT — `COUNT(*) >= '2'` est donc
        // toujours faux. Les colonnes INTEGER convertissent leur paramètre par
        // affinité ; une expression comme COUNT(*) n'a aucune affinité.
        $minLies = (int) self::config()->meuteLiensMin;
        // Un vote déjà annulé pour meute reste dans le graphe : son auteur relie
        // encore ceux qui ne se connaissent qu'à travers lui. Sans lui, une
        // chaîne A–B–C dont B a été annulé laisserait C garder son vote.
        $enJeu = "value = -1 AND (blocked = 0 OR blocked_reason = 'pack_voting')";
        $stmt = $pdo->prepare("
            SELECT target_author FROM mod_votes
             WHERE $enJeu AND created_at >= ?$surCible
          GROUP BY target_author HAVING COUNT(DISTINCT voter_id) >= $minLies AND SUM(blocked = 0) > 0
        ");
        $stmt->execute(array_merge([$sinceMeute], $deLaCible));
        $auteurs = array_map('intval', array_column($stmt->fetchAll(), 'target_author'));

        foreach ($auteurs as $author) {
            $vs = $pdo->prepare("SELECT voter_id FROM mod_votes
                                  WHERE target_author = ? AND $enJeu AND created_at >= ?
                               GROUP BY voter_id ORDER BY MAX(created_at) DESC, voter_id DESC");
            $vs->execute([$author, $sinceMeute]);
            $voters = array_map('intval', array_column($vs->fetchAll(), 'voter_id'));

            // On borne, en gardant les votants les plus récents : une meute de
            // comptes neufs vote en dernier, et c'est elle qu'une coupe par
            // ancienneté écarterait. Une troncature muette se lirait comme une
            // absence de meute : la cible est signalée à l'arbitre.
            if (count($voters) > self::config()->meuteVotantsMax) {
                $tronques[] = ['target_author' => $author, 'voters' => count($voters), 'scanned' => self::config()->meuteVotantsMax];
                $voters = array_slice($voters, 0, self::config()->meuteVotantsMax);
                self::signaler($pdo, $author, 'votants_tronques');
            }

            foreach (self::linkedGroups($pdo, $voters) as $groupe) {
                if (count($groupe) < self::config()->meuteLiensMin) {
                    continue;
                }
                $ph = implode(',', array_fill(0, count($groupe), '?'));
                $ids = $pdo->prepare("SELECT id, voter_id, blocked FROM mod_votes
                                       WHERE target_author = ? AND $enJeu
                                         AND created_at >= ? AND voter_id IN ($ph)
                                       ORDER BY created_at ASC, id ASC");
                $ids->execute(array_merge([$author, $sinceMeute], $groupe));
                $votes = $ids->fetchAll();
                if (!$votes) {
                    continue;
                }
                // Le premier votant garde ses votes : c'est ce qu'un membre seul
                // aurait obtenu, et le groupe n'obtient rien de plus.
                $premier = (int) $votes[0]['voter_id'];
                $aAnnuler = array_map('intval', array_column(
                    array_filter($votes, static fn (array $v): bool =>
                        (int) $v['voter_id'] !== $premier && !(int) $v['blocked']),
                    'id'
                ));
                // L'annulation, les rangs qu'elle consigne et ce qu'elle rend
                // tiennent ensemble : faits à moitié, aucun passage ne les
                // rattraperait — les votes annulés ne s'annulent plus. Les rangs
                // passent avant la restitution, qui peut écrire au journal : un
                // échec sur eux défait tout avant que rien ne soit inscrit.
                $episode = self::transaction($pdo, static function () use ($pdo, $author, $groupe, $aAnnuler): ?array {
                    $voteIds = self::annulerVotes($pdo, $aAnnuler);
                    if (!$voteIds) {
                        return null;
                    }
                    $rangs = self::consignerMeute($pdo, $groupe, $author, $voteIds);
                    self::restoreAfterCancel($pdo, $author, $voteIds);

                    return ['votes' => $voteIds, 'rangs' => $rangs];
                });
                if ($episode === null) {
                    continue;
                }
                $cancelled += count($episode['votes']);
                $packs[] = [
                    'target_author' => $author,
                    'voters'        => $groupe,
                    'premier'       => $premier,
                    'cancelled'     => count($episode['votes']),
                    'rangs'         => $episode['rangs'],
                ];
            }
        }

        // ── Salve rapide : fenêtre courte, aucun lien, aucune annulation ─────
        $sinceSalve = $maintenant - self::config()->fenetreSalveSecondes * 2;
        $minVotants = (int) self::config()->salveVotantsMin;
        $stmt = $pdo->prepare("
            SELECT target_author FROM mod_votes
             WHERE value = -1 AND blocked = 0 AND created_at >= ?$surCible
          GROUP BY target_author HAVING COUNT(DISTINCT voter_id) >= $minVotants
        ");
        $stmt->execute(array_merge([$sinceSalve], $deLaCible));

        foreach ($stmt->fetchAll() as $row) {
            $author = (int) $row['target_author'];
            $vs = $pdo->prepare('SELECT voter_id, created_at FROM mod_votes
                                  WHERE target_author = ? AND value = -1 AND blocked = 0 AND created_at >= ?
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
            self::signaler($pdo, $author, 'salve_rapide', $author === $voteSur);
            $salves[] = ['target_author' => $author, 'voters' => array_keys($best)];
        }

        return [
            'pack_detected'    => count($packs) > 0,
            'cancelled_votes'  => $cancelled,
            'packs'            => $packs,
            'salves'           => $salves,
            'voters_truncated' => $tronques,
        ];
    }

    /**
     * Annule des votes pour meute, et rend ceux que CE passage a annulés. Deux
     * balayages concurrents peuvent lire les mêmes votes : la condition sur
     * l'état est la garde de la course, et seul le passage qui annule rend
     * l'effet et inscrit l'épisode.
     *
     * @param  int[] $ids
     * @return int[]
     */
    private static function annulerVotes(PDO $pdo, array $ids): array
    {
        $miens = [];
        $annuler = $pdo->prepare("UPDATE mod_votes SET blocked = 1, blocked_reason = 'pack_voting' WHERE id = ? AND blocked = 0");
        foreach ($ids as $id) {
            $annuler->execute([$id]);
            if ($annuler->rowCount() === 1) {
                $miens[] = $id;
            }
        }
        return $miens;
    }

    /**
     * Après annulation d'une meute : rend ce que les votes annulés avaient
     * réellement retiré, et rien de plus. Un vote posé au plancher n'a rien
     * retiré, un vote dont l'effet a été effacé par une remise au départ non
     * plus : les rendre ferait des points gratuits. Un vote d'avant la colonne
     * `effet` n'a pas d'effet connu : il ne rend rien et ne cause aucun ban.
     *
     * Un ban automatique ne se lève que si la meute l'a CAUSÉ : des votes
     * annulés, posés avant son début, avaient un effet — sans eux, la
     * réputation ne serait pas tombée à zéro. La levée retire le strike de ce
     * ban, et lui seul.
     *
     * @param int[] $voteIds
     */
    private static function restoreAfterCancel(PDO $pdo, int $author, array $voteIds): void
    {
        $ph = implode(',', array_fill(0, count($voteIds), '?'));
        $stmt = $pdo->prepare("SELECT COALESCE(effet, 0) AS effet, created_at FROM mod_votes WHERE id IN ($ph)");
        $stmt->execute($voteIds);
        $annules = $stmt->fetchAll();
        $rendu = -array_sum(array_map(static fn (array $v): int => min(0, (int) $v['effet']), $annules));
        if ($rendu > 0) {
            $pdo->prepare('UPDATE member_moderation SET reputation = MIN(reputation + ?, ?), updated_at = ? WHERE account_id = ?')
                ->execute([$rendu, self::config()->reputationMax, time(), $author]);
        }

        $rep = self::getReputation($pdo, $author);
        if ($rep['reputation'] >= self::config()->perteDroitDeVoteSous) {
            $pdo->prepare('UPDATE member_moderation SET voting_rights = 1 WHERE account_id = ?')->execute([$author]);
        }
        // Seul un ban automatique se lève ici : il est venu des votes. Un ban
        // d'arbitre n'en dépend pas. Pas de plancher non plus : cette levée
        // répare une injustice, elle n'en accorde aucune.
        $causes = array_filter($annules, static fn (array $v): bool =>
            (int) $v['effet'] < 0 && (int) $v['created_at'] <= $rep['ban_debut']);
        if ($rep['banned'] && $rep['ban_origine'] === 'auto' && $causes !== []
            && $rep['reputation'] > self::config()->banA) {
            self::$journal?->inscrire([
                'acte'      => 'ban_leve',
                'compte'    => $author,
                'origine'   => 'auto',
                'arbitre'   => null,
                'motif'     => 'meute_detectee',
                'cause'     => 'meute_detectee',
                'anticipee' => false,
                'reste'     => $rep['banned_until'] - time(),
                'ouverts'   => self::causesEnTexte($rep['signaux']),
                'ferme'     => self::fermesEnTexte(self::deCause($rep['signaux'], 'ban_auto')),
            ]);
            $pdo->prepare(
                'UPDATE member_moderation
                    SET banned_until = 0, ban_debut = 0, ban_origine = NULL, ban_motif = NULL,
                        strikes = MAX(0, strikes - 1),
                        updated_at = ?
                  WHERE account_id = ?'
            )->execute([time(), $author]);
            self::designaler($pdo, $author, 'ban_auto');
        }
        // La convalescence avait été ouverte par une chute qui n'aurait pas dû
        // avoir lieu : on la referme, plutôt que d'imposer une guérison au temps
        // pour une faute annulée.
        if ($rep['reputation'] >= self::config()->entreeConvalescenceSous()) {
            $pdo->prepare('UPDATE member_moderation SET convalescent = 0 WHERE account_id = ?')
                ->execute([$author]);
            // Seul le signalement que la chute a provoqué s'en va avec elle. Une
            // salve à arbitrer et une récidive de meute portent chacune leur
            // propre motif d'exister : la restauration d'un score n'y répond pas.
            self::designaler($pdo, $author, 'reputation_zero');
        }
    }

    /**
     * Rang de récidive d'un votant : le nombre d'épisodes de meute qu'il a à son
     * compte. Lu comme un MAX et non comme un COUNT — un épisode laisse une
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
     * Inscrit l'épisode de meute de chaque votant, sans peine. La neutralisation
     * des votes a déjà eu lieu ; le rang sert à l'arbitre, qui voit une récidive
     * et juge sur le fond. Une peine bâtie sur le lien frapperait des honnêtes
     * au choix d'un tiers : un troll qui poste chaque jour ferait récidiver ses
     * critiques, un compte jetable choisirait à qui il se lie.
     *
     * Un votant ne monte d'un rang qu'une fois par `meuteEpisodeCooldown`,
     * compté depuis le DÉBUT de l'épisode. Compté depuis la dernière détection,
     * une meute qui frappe un peu plus souvent que ce délai resterait au premier
     * rang indéfiniment. Les cibles supplémentaires d'un même épisode sont
     * inscrites quand même : l'arbitre voit l'ampleur.
     *
     * Le rang appartient au VOTANT. Compté sur la cible, un groupe qui change de
     * proie resterait au premier rang indéfiniment.
     *
     * @param  int[] $voters
     * @param  int[] $voteIds
     * @return array<int, array{voter: int, rang: int, action: string}>
     */
    private static function consignerMeute(PDO $pdo, array $voters, int $author, array $voteIds): array
    {
        $maintenant = time();
        $rangs = [];

        foreach ($voters as $voter) {
            $voter = (int) $voter;
            self::ensureRow($pdo, $voter);

            $stmt = $pdo->prepare(
                "SELECT rang, MIN(detected_at) AS debut FROM mod_pack_flags
                  WHERE voter_id = ? AND id > (SELECT COALESCE(MAX(id), 0) FROM mod_pack_flags
                                                WHERE voter_id = ? AND action = 'grace')
               GROUP BY rang ORDER BY rang DESC LIMIT 1"
            );
            $stmt->execute([$voter, $voter]);
            $courant = $stmt->fetch();

            // Il n'entre dans l'épisode que s'il a voté contre cette cible depuis
            // son dernier épisode sur elle : sans cette garde, un tiers lié qui
            // revote ferait monter le rang de qui n'a rien refait.
            $neuf = $pdo->prepare(
                'SELECT 1 FROM mod_votes WHERE voter_id = ? AND target_author = ? AND value = -1
                    AND created_at > COALESCE((SELECT MAX(detected_at) FROM mod_pack_flags
                                                WHERE voter_id = ? AND target_author = ?), -1)
                  LIMIT 1'
            );
            $neuf->execute([$voter, $author, $voter, $author]);
            if ($neuf->fetchColumn() === false) {
                continue;
            }

            $memeEpisode = $courant
                && ($maintenant - (int) $courant['debut']) < self::config()->meuteEpisodeCooldown;
            $rang = $memeEpisode ? (int) $courant['rang'] : ($courant ? (int) $courant['rang'] : 0) + 1;
            $action = $rang <= 1 ? 'avertissement' : 'revue_admin';

            $pdo->prepare(
                'INSERT INTO mod_pack_flags (voter_id, target_author, rang, action, vote_ids, detected_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$voter, $author, $rang, $action, json_encode(array_values($voteIds)), $maintenant]);

            if (!$memeEpisode && $rang >= 2) {
                self::signaler($pdo, $voter, 'meute_recidive');
            }
            $rangs[] = ['voter' => $voter, 'rang' => $rang, 'action' => $action];
        }

        return $rangs;
    }

    /**
     * Deux familles de signaux. Un signal d'ÉTAT (`reputation_zero`, `ban_auto`,
     * `journal_en_panne`) se déduit de la base et se lève seul quand l'état
     * cesse. Un signal de COMPORTEMENT (une salve, une récidive de meute…) dit
     * ce qui s'est passé, et seul un arbitre le ferme. Une cause que le moteur
     * ne connaît pas — reprise d'une base ancienne — est un comportement : ce
     * qu'il ne sait pas lever, un humain le ferme.
     */
    private static function famille(string $cause): string
    {
        return in_array($cause, ['reputation_zero', 'ban_auto', 'journal_en_panne'], true) ? 'etat' : 'comportement';
    }

    /**
     * Le délai depuis la dernière occurrence comptée au-delà duquel une
     * détection est un fait neuf. Une salve se redétecte à chaque downvote de sa
     * fenêtre, une troncature à chaque balayage : sans ce délai, le compte dirait
     * combien de fois on a regardé, pas combien de fois c'est arrivé. Compté
     * depuis la dernière détection, un filet continu resterait à un pour toujours.
     */
    private static function regroupement(string $cause): int
    {
        return match ($cause) {
            'salve_rapide'     => 2 * self::config()->fenetreSalveSecondes,
            'votants_tronques', 'votant_etendu' => self::config()->balayagePeriodeSecondes,
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
        $maintenant = time();
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
        // regardé. Un votant étendu ne porte que lui-même, une troncature dit la
        // taille du graphe et non qui le compose : chez eux, seule une occurrence
        // comptée fait refuser — sinon chaque vote priverait l'arbitre du classement
        // aussi longtemps qu'il dure.
        if ($suite->rowCount() === 1 || ($neuf && !in_array($cause, ['votant_etendu', 'votants_tronques'], true))) {
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
                $pdo->prepare('UPDATE mod_signaux SET vu_le = ? WHERE id = ?')->execute([time(), $s['signal']]);
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
     * la base sans passer par le moteur. Le moteur ne la relit jamais.
     */
    private static function resumer(PDO $pdo, int $accountId): void
    {
        $cause = self::projection(self::signauxDe($pdo, $accountId));
        self::ensureRow($pdo, $accountId);
        $pdo->prepare('UPDATE member_moderation SET needs_review = ?, review_reason = ?, updated_at = ? WHERE account_id = ?')
            ->execute([$cause === null ? 0 : 1, $cause, time(), $accountId]);
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

    private static function repriseManquante(int $accountId): \LogicException
    {
        return new \LogicException(sprintf(
            'SelfModerate : le compte %d a une case de signalement ouverte et aucun signal dans mod_signaux — '
            . 'la reprise n\'a pas été faite : appelle Moderate::migrerSignaux() une fois, juste après avoir créé la table.',
            $accountId
        ));
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
        $pdo->exec(
            "UPDATE member_moderation
                SET review_reason = COALESCE(
                        (SELECT cause FROM mod_signaux s WHERE s.account_id = member_moderation.account_id AND s.vu_le IS NULL
                            AND s.cause NOT IN ('reputation_zero', 'ban_auto', 'journal_en_panne') ORDER BY s.id LIMIT 1),
                        (SELECT cause FROM mod_signaux s WHERE s.account_id = member_moderation.account_id AND s.vu_le IS NULL
                          ORDER BY s.id DESC LIMIT 1)),
                    needs_review = EXISTS (SELECT 1 FROM mod_signaux s WHERE s.account_id = member_moderation.account_id AND s.vu_le IS NULL)"
        );

        return (int) $pdo->query('SELECT COUNT(*) FROM mod_signaux')->fetchColumn() - $avant;
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

    private static function enforceThresholds(PDO $pdo, int $accountId, int $reputation): void
    {
        if ($reputation < self::config()->perteDroitDeVoteSous) {
            $pdo->prepare('UPDATE member_moderation SET voting_rights = 0 WHERE account_id = ?')->execute([$accountId]);
        }
        // Une réputation au plancher qui arrive ici vient d'une érosion étalée :
        // toute meute a déjà été neutralisée et la réputation restituée par
        // `detectPackVoting()`. Un ban prononcé sans humain est une arme
        // d'escalade à la portée de qui n'a aucun droit d'admin ; un signalement
        // seul ne protège personne tant que l'arbitre n'est pas devant. D'où un
        // ban tenu par trois bornes : la peine est graduée et FINIE, visible dès
        // qu'elle tombe (le signal `ban_auto` attend l'arbitre), et ses deux bouts sont
        // inscrits au journal.
        if ($reputation <= self::config()->banA) {
            self::sanctionnerReputationZero($pdo, $accountId);
        }

        // Sous le seuil de vote, la convalescence s'ouvre. `AND convalescent = 0`
        // n'est pas une précaution d'idempotence : sans lui, chaque nouveau
        // downvote remettrait l'horloge à zéro, et un votant patient suffirait à
        // repousser la remontée indéfiniment.
        if ($reputation < self::config()->perteDroitDeVoteSous) {
            $pdo->prepare(
                'UPDATE member_moderation SET convalescent = 1, last_regen_at = ? WHERE account_id = ? AND convalescent = 0'
            )->execute([time(), $accountId]);
        }
    }

    /**
     * Réputation au plancher : bannir quand le journal le permet, signaler sinon.
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
        $maintenant = time();

        if (self::$journal === null || self::dansLaTransactionDeLHote($pdo)) {
            if (!self::enPeineAutomatique($pdo, $accountId)) {
                self::signaler($pdo, $accountId, 'reputation_zero');
            }

            return;
        }

        self::cloreBanExpire($pdo, $accountId, contenir: true);
        $stmt = $pdo->prepare('SELECT reputation, banned_until, strikes FROM member_moderation WHERE account_id = ?');
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
        $jusqua  = $maintenant + $duree;
        $signaux = self::signauxDe($pdo, $accountId);

        self::$journal->inscrire([
            'acte'    => 'ban_debut',
            'compte'  => $accountId,
            'origine' => 'auto',
            'episode' => $episode,
            'duree'   => $duree,
            'debut'   => $maintenant,
            'jusqu_a' => $jusqua,
            'motif'   => 'reputation_zero',
            'arbitre' => null,
            'ouverts' => self::causesEnTexte($signaux),
            'ferme'   => self::fermesEnTexte(self::deCause($signaux, 'reputation_zero')),
        ]);

        $pdo->prepare(
            "UPDATE member_moderation
                SET banned_until = ?, ban_debut = ?, ban_origine = 'auto', ban_motif = 'reputation_zero',
                    voting_rights = 0, strikes = ?, updated_at = ?
              WHERE account_id = ?"
        )->execute([$jusqua, $maintenant, $episode, $maintenant, $accountId]);
        // Le ban prend la place de la chute qui l'a causé : un fait, un signal.
        self::designaler($pdo, $accountId, 'reputation_zero');
        self::signaler($pdo, $accountId, 'ban_auto');
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

    /** Score (somme votes non bloqués) d'un post ou d'un membre. */
    public static function score(PDO $pdo, string $targetType, int $targetId): int
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(value),0) FROM mod_votes WHERE target_type = ? AND target_id = ? AND blocked = 0');
        $stmt->execute([$targetType, $targetId]);
        return (int) $stmt->fetchColumn();
    }

    /** Le membre a-t-il déjà voté sur cette cible ? (pour UI) */
    public static function userVote(PDO $pdo, int $voterId, string $targetType, int $targetId): ?int
    {
        $stmt = $pdo->prepare('SELECT value FROM mod_votes WHERE voter_id = ? AND target_type = ? AND target_id = ? AND blocked = 0');
        $stmt->execute([$voterId, $targetType, $targetId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /**
     * Liste des votes bloqués. Rend le votant ET sa cible : vue d'arbitrage, et
     * le contraire de ce que reasonsFor() protège juste en dessous. Un appelant
     * qui la sert sans contrôle d'accès publie qui a voté contre qui.
     */
    public static function blockedVotes(PDO $pdo, int $limit = 30): array
    {
        $stmt = $pdo->prepare('
            SELECT v.id, v.target_type, v.target_id, v.value, v.blocked_reason, v.created_at,
                   voter.username AS voter, auth.username AS cible
              FROM mod_votes v
              JOIN accounts voter ON voter.id = v.voter_id
              JOIN accounts auth ON auth.id = v.target_author
             WHERE v.blocked = 1
             ORDER BY v.created_at DESC LIMIT ?
        ');
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Les motifs qu'un membre a reçus. Le protocole veut qu'il voie les raisons
     * sans voir qui a voté : aucune colonne de votant ne sort d'ici.
     *
     * La date est ramenée au jour, et l'ordre à l'intérieur d'un jour est
     * alphabétique et non chronologique — à la seconde près, recoupée avec les
     * présences, elle désignerait son auteur. Limite qu'aucun tri ne lève : sur
     * un unique downvote reçu, la personne devine souvent qui l'a émis.
     */
    public static function reasonsFor(PDO $pdo, int $accountId, int $limit = 50): array
    {
        $stmt = $pdo->prepare("
            SELECT value, reason, reason_code,
                   strftime('%Y-%m-%d', created_at, 'unixepoch') AS jour
              FROM mod_votes
             WHERE target_author = ? AND blocked = 0 AND reason IS NOT NULL AND reason <> ''
          ORDER BY jour DESC, reason ASC
             LIMIT ?
        ");
        $stmt->bindValue(1, $accountId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Ban prononcé par un arbitre. Le motif est exigé, et contrôlé comme celui
     * d'un downvote : la personne bannie doit pouvoir lire ce qu'on lui
     * reproche. Un ban en cours n'est pas remplacé — l'arbitre le lève d'abord,
     * et les deux gestes restent au journal.
     *
     * La revue ouverte ne se ferme pas avec le ban : un signal de comportement
     * attend qu'un arbitre le classe (`adminClasser()`), un signal d'état se
     * lève quand l'état cesse. L'acte dit laquelle était ouverte.
     *
     * @return array{ok: bool, message: string}
     */
    public static function adminBan(PDO $pdo, int $accountId, string $arbitre, string $motif, ?int $seconds = null): array
    {
        $sansTrace = self::refusSansJournal();
        if ($sansTrace !== null) {
            return $sansTrace;
        }
        $dansLHote = self::refusTransactionDeLHote($pdo);
        if ($dansLHote !== null) {
            return $dansLHote;
        }
        [$motifOk, $pourquoi] = self::validateReason($motif);
        if (!$motifOk) {
            return ['ok' => false, 'message' => $pourquoi];
        }
        $seconds ??= self::config()->dureeBanAdmin();
        if ($seconds <= 0) {
            return ['ok' => false, 'message' => static::t('Durée de ban invalide.')];
        }
        // Une peine échue se referme avant le verrou, comme au vote. Sous lui,
        // l'état se relit : la décision se prend sur ce qu'il est, pas sur ce
        // qu'il était avant qu'un autre geste passe.
        self::getReputation($pdo, $accountId);

        return self::transaction($pdo, static function () use ($pdo, $accountId, $arbitre, $motif, $seconds): array {
            $etat = self::getReputation($pdo, $accountId);
            if ($etat['banned']) {
                return ['ok' => false, 'message' => sprintf(
                    static::t('Déjà banni jusqu\'au %s : lève ce ban avant d\'en poser un autre.'),
                    date('d/m/Y H:i', $etat['banned_until'])
                )];
            }
            $enAttente = self::refusFinEnAttente($etat);
            if ($enAttente !== null) {
                return $enAttente;
            }

            $maintenant = time();
            $jusqua     = $maintenant + $seconds;
            $episode    = $etat['strikes'] + 1;
            self::$journal->inscrire([
                'acte'    => 'ban_debut',
                'compte'  => $accountId,
                'origine' => 'admin',
                'episode' => $episode,
                'duree'   => $seconds,
                'debut'   => $maintenant,
                'jusqu_a' => $jusqua,
                'motif'   => trim($motif),
                'arbitre' => $arbitre,
                'raison'  => $etat['review_reason'],
                'ouverts' => self::causesEnTexte($etat['signaux']),
                'ferme'   => self::fermesEnTexte([]),
            ]);
            $pdo->prepare(
                "UPDATE member_moderation
                    SET banned_until = ?, ban_debut = ?, ban_origine = 'admin', ban_motif = ?,
                        voting_rights = 0, strikes = ?, updated_at = ?
                  WHERE account_id = ?"
            )->execute([$jusqua, $maintenant, trim($motif), $episode, $maintenant, $accountId]);

            return ['ok' => true, 'message' => sprintf(static::t('Compte banni jusqu\'au %s.'), date('d/m/Y H:i', $jusqua))];
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
     * salve, une troncature, un votant étendu restent à classer : une grâce
     * passe sans motif une fois le plancher franchi, et un comportement ne se
     * ferme pas sans motif écrit. L'acte dit ce qui était ouvert et ce qu'elle
     * a fermé.
     *
     * 🔑 **Le plancher.** Avant `plancherFraction` de la peine en cours, la grâce
     * exige un motif, et s'inscrit comme levée anticipée avec le temps qui
     * restait. Le plancher ne ferme pas le favoritisme : il le rend coûteux,
     * parce qu'il laisse une trace signée d'un nom. Il ne s'applique pas à
     * l'annulation pour meute détectée (`restoreAfterCancel`), qui répare une
     * injustice au lieu d'en accorder une.
     *
     * @return array{ok: bool, message: string, anticipee?: bool}
     */
    public static function adminPardon(PDO $pdo, int $accountId, string $arbitre, ?string $motif = null): array
    {
        $sansTrace = self::refusSansJournal();
        if ($sansTrace !== null) {
            return $sansTrace;
        }
        $dansLHote = self::refusTransactionDeLHote($pdo);
        if ($dansLHote !== null) {
            return $dansLHote;
        }
        $motif = $motif !== null ? trim($motif) : '';
        if ($motif !== '') {
            [$motifOk, $pourquoi] = self::validateReason($motif);
            if (!$motifOk) {
                return ['ok' => false, 'message' => $pourquoi];
            }
        }
        self::getReputation($pdo, $accountId);

        return self::transaction($pdo, static function () use ($pdo, $accountId, $arbitre, $motif): array {
            $etat       = self::getReputation($pdo, $accountId);
            $enAttente  = self::refusFinEnAttente($etat);
            if ($enAttente !== null) {
                return $enAttente;
            }
            $maintenant = time();
            $anticipee  = false;
            $rangEfface = self::rangDe($pdo, $accountId);
            $fermes     = array_values(array_filter($etat['signaux'], static fn (array $s): bool =>
                $s['famille'] === 'etat' || ($s['cause'] === 'meute_recidive' && $rangEfface > 0)));
            $ouverts    = self::causesEnTexte($etat['signaux']);
            $ferme      = self::fermesEnTexte($fermes);

            if ($etat['banned']) {
                $plancher  = $etat['ban_debut'] > 0
                    ? self::config()->plancherDe($etat['ban_debut'], $etat['banned_until'])
                    : null;
                $anticipee = $plancher !== null && $maintenant < $plancher;
                if ($anticipee && $motif === '') {
                    return ['ok' => false, 'message' => sprintf(
                        static::t('Levée anticipée : sans motif écrit, ce ban ne se lève qu\'à partir du %s.'),
                        date('d/m/Y H:i', $plancher)
                    )];
                }
                self::$journal->inscrire([
                    'acte'      => 'ban_leve',
                    'compte'    => $accountId,
                    'origine'   => $etat['ban_origine'],
                    'arbitre'   => $arbitre,
                    'motif'     => $motif !== '' ? $motif : null,
                    'cause'     => 'grace',
                    'anticipee' => $anticipee,
                    'reste'     => $etat['banned_until'] - $maintenant,
                    'plancher'  => $plancher,
                    'rang_efface'      => $rangEfface,
                    'suspension_levee' => $etat['vote_muted'],
                    'ouverts'   => $ouverts,
                    'ferme'     => $ferme,
                ]);
            } else {
                self::$journal->inscrire([
                    'acte'       => 'grace',
                    'compte'     => $accountId,
                    'arbitre'    => $arbitre,
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
                        voting_rights = 1, reputation = ?, strikes = 0,
                        convalescent = 0, vote_muted_until = 0, updated_at = ?
                  WHERE account_id = ?'
            )->execute([self::config()->reputationInitiale, $maintenant, $accountId]);
            self::designaler($pdo, $accountId, ...array_column(self::deFamille($fermes, 'etat'), 'cause'));
            self::retirerDeLaFile($pdo, $accountId, self::deFamille($fermes, 'comportement'));
            self::effacerEffets($pdo, $accountId);
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
    public static function adminMaintenir(PDO $pdo, int $accountId, string $arbitre, string $motif, ?array $vu = null): array
    {
        $sansTrace = self::refusSansJournal();
        if ($sansTrace !== null) {
            return $sansTrace;
        }
        $dansLHote = self::refusTransactionDeLHote($pdo);
        if ($dansLHote !== null) {
            return $dansLHote;
        }
        [$motifOk, $pourquoi] = self::validateReason($motif);
        if (!$motifOk) {
            return ['ok' => false, 'message' => $pourquoi];
        }
        self::getReputation($pdo, $accountId);

        return self::transaction($pdo, static function () use ($pdo, $accountId, $arbitre, $motif, $vu): array {
            $etat = self::getReputation($pdo, $accountId);
            if (!$etat['banned']) {
                return ['ok' => false, 'message' => static::t('Aucun ban en cours sur ce compte.')];
            }
            $aucun = static::t('Ce ban n\'attend aucune revue : un signalement se classe.');
            $vise  = self::signalVise($etat['signaux'], $vu, static fn (array $s): bool => $s['cause'] === 'ban_auto', $aucun, $aucun);
            if (is_string($vise)) {
                return ['ok' => false, 'message' => $vise];
            }
            self::$journal->inscrire([
                'acte'    => 'ban_maintenu',
                'compte'  => $accountId,
                'origine' => $etat['ban_origine'],
                'arbitre' => $arbitre,
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
     * base, vue, tant que la réputation n'est pas remontée. Un ban automatique
     * se maintient, il ne se classe pas, et une fin de peine qui attend le
     * journal n'est pas au compte d'en répondre.
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
    public static function adminClasser(PDO $pdo, int $accountId, string $arbitre, string $motif, ?array $vu = null): array
    {
        $sansTrace = self::refusSansJournal();
        if ($sansTrace !== null) {
            return $sansTrace;
        }
        $dansLHote = self::refusTransactionDeLHote($pdo);
        if ($dansLHote !== null) {
            return $dansLHote;
        }
        [$motifOk, $pourquoi] = self::validateReason($motif);
        if (!$motifOk) {
            return ['ok' => false, 'message' => $pourquoi];
        }
        self::getReputation($pdo, $accountId);

        return self::transaction($pdo, static function () use ($pdo, $accountId, $arbitre, $motif, $vu): array {
            $etat = self::getReputation($pdo, $accountId);
            $enAttente = self::refusFinEnAttente($etat);
            if ($enAttente !== null) {
                return $enAttente;
            }
            $vise = self::signalVise(
                $etat['signaux'],
                $vu,
                static fn (array $s): bool => !in_array($s['cause'], ['ban_auto', 'journal_en_panne'], true),
                static::t('Aucun signalement en cours sur ce compte.'),
                static::t('Ce signal ne se classe pas : un ban automatique se maintient, une fin de peine attend le journal.')
            );
            if (is_string($vise)) {
                return ['ok' => false, 'message' => $vise];
            }
            self::$journal->inscrire([
                'acte'    => 'revue_classee',
                'compte'  => $accountId,
                'arbitre' => $arbitre,
                'motif'   => trim($motif),
                'raison'  => $vise['cause'],
                'ouverts' => self::causesEnTexte($etat['signaux']),
                'ferme'   => self::fermesEnTexte([$vise]),
            ]);
            self::retirerDeLaFile($pdo, $accountId, [$vise]);

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
     * @return list<array{account_id: int, username: string, origine: ?string, motif: ?string,
     *                    debut: int, jusqu_a: int, reste: int, plancher: ?int, episode: int, a_revoir: bool,
     *                    revue: ?array{signal: int, detections: int},
     *                    chute: ?array{votants: int, ages_jours: list<int>, etalement: int}}>
     */
    public static function bansEnCours(PDO $pdo, int $limite = 100): array
    {
        $maintenant = time();
        $stmt = $pdo->prepare(
            "SELECT m.account_id, a.username, m.ban_origine, m.ban_motif, m.ban_debut, m.banned_until,
                    m.strikes, s.id AS revue, s.detections
               FROM member_moderation m JOIN accounts a ON a.id = m.account_id
          LEFT JOIN mod_signaux s ON s.account_id = m.account_id AND s.cause = 'ban_auto' AND s.vu_le IS NULL
              WHERE m.banned_until > ? ORDER BY m.banned_until ASC LIMIT ?"
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
                'plancher'   => $debut > 0 ? self::config()->plancherDe($debut, $fin) : null,
                'episode'    => (int) $r['strikes'],
                'a_revoir'   => $r['revue'] !== null,
                'revue'      => $r['revue'] !== null ? ['signal' => (int) $r['revue'], 'detections' => (int) $r['detections']] : null,
                'chute'      => $auto ? self::compositionChute($pdo, (int) $r['account_id'], $debut) : null,
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Qui a fait tomber un compte jusqu'au ban : les votants dont le vote a
     * réellement retiré un point sur la fenêtre de meute qui précède le ban,
     * l'âge de leurs comptes à son début, et le temps entre le premier et le
     * dernier vote. Des faits
     * bruts, sans seuil ni score. Une coordination espacée entre comptes qui ne
     * se sont jamais écrit n'est reconnue par aucune détection : c'est ici
     * qu'un arbitre peut la voir.
     *
     * @return array{votants: int, ages_jours: list<int>, etalement: int}
     */
    public static function compositionChute(PDO $pdo, int $accountId, int $debut): array
    {
        $stmt = $pdo->prepare(
            'SELECT MIN(v.created_at) AS premier, MAX(v.created_at) AS dernier, a.created_at AS cree_le
               FROM mod_votes v JOIN accounts a ON a.id = v.voter_id
              WHERE v.target_author = ? AND v.blocked = 0 AND COALESCE(v.effet, 0) < 0
                AND v.created_at BETWEEN ? AND ?
           GROUP BY v.voter_id'
        );
        $stmt->execute([$accountId, $debut - self::config()->fenetreMeuteJours * 86400, $debut]);
        $votants = $stmt->fetchAll();
        $ages = array_map(static fn (array $v): int => intdiv(max(0, $debut - (int) $v['cree_le']), 86400), $votants);
        sort($ages);

        return [
            'votants'    => count($votants),
            'ages_jours' => $ages,
            'etalement'  => $votants === []
                ? 0
                : max(array_map('intval', array_column($votants, 'dernier'))) - min(array_map('intval', array_column($votants, 'premier'))),
        ];
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
