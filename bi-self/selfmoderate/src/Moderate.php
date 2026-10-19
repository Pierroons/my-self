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
     * Branche le journal, et avec lui le bannissement automatique.
     *
     * 🔑 **C'est ce branchement qui arme la sanction automatique**, et rien
     * d'autre. Un déploiement qui ne journalise pas garde le comportement
     * d'avant : la réputation à zéro lève un signalement, un arbitre tranche.
     * Le choix n'est pas une précaution de style — une peine prononcée sans
     * humain et sans trace ne laisse personne pour en répondre, ni rien pour
     * la contester.
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

    /** Le bannissement automatique est-il armé sur ce déploiement ? */
    public static function banAutomatiqueArme(): bool
    {
        return self::$journal !== null;
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
        // Lire d'abord, écrire seulement si l'état le demande : la convalescence
        // et la clôture d'une peine ne se tentent que si la ligne les appelle,
        // et `ensureRow()` n'est appelé que sur une ligne absente — une
        // insertion, même ignorée, prend un verrou d'écriture. Dans le cas
        // courant, lire une réputation est un `SELECT` et rien d'autre.
        $row = self::ligne($pdo, $accountId);
        if ($row === null) {
            self::ensureRow($pdo, $accountId);
            $row = self::ligne($pdo, $accountId);
        }
        $relire = false;
        if ((int) $row['convalescent']) {
            self::regenerate($pdo, $accountId);
            $relire = true;
        }
        $jusqua = (int) $row['banned_until'];
        if ($jusqua > 0 && $jusqua <= time()) {
            self::cloreBanExpire($pdo, $accountId, contenir: true);
            $relire = true;
        }
        if ($relire) {
            $row = self::ligne($pdo, $accountId);
        }
        return [
            'reputation'    => (int) $row['reputation'],
            'strikes'       => (int) $row['strikes'],
            'voting_rights' => (bool) $row['voting_rights'],
            'banned'        => ((int) $row['banned_until']) > time(),
            'banned_until'  => (int) $row['banned_until'],
            'ban_debut'     => (int) $row['ban_debut'],
            'ban_origine'   => $row['ban_origine'] !== null ? (string) $row['ban_origine'] : null,
            'ban_motif'     => $row['ban_motif'] !== null ? (string) $row['ban_motif'] : null,
            'needs_review'  => (bool) $row['needs_review'],
            'review_reason' => $row['review_reason'] !== null ? (string) $row['review_reason'] : null,
            'convalescent'  => (bool) $row['convalescent'],
            'vote_muted'       => ((int) $row['vote_muted_until']) > time(),
            'vote_muted_until' => (int) $row['vote_muted_until'],
        ];
    }

    /** La ligne de modération d'un compte, ou null s'il n'en a pas encore. */
    private static function ligne(PDO $pdo, int $accountId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT reputation, strikes, voting_rights, banned_until, ban_debut, ban_origine, ban_motif,
                    needs_review, review_reason, convalescent, vote_muted_until
               FROM member_moderation WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();

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
     * rend l'inscription unique.
     *
     * ⚠️ **Un journal qui refuse d'écrire laisse la peine à refermer.** Avec
     * `$contenir`, le refus est consigné et signalé (`journal_en_panne`), et
     * l'appel continue : `banned` se calcule sur l'heure, donc la personne n'est
     * plus bannie ; le droit de vote et la réputation remise au départ, eux,
     * attendent la clôture. Faire tomber toute page qui lit le compte
     * n'ajouterait aucune trace. La clôture se fait au passage suivant, le
     * journal revenu. Le planificateur, lui, fait remonter le refus.
     */
    private static function cloreBanExpire(PDO $pdo, int $accountId, bool $contenir = false): void
    {
        $maintenant = time();
        $stmt = $pdo->prepare(
            'SELECT banned_until, ban_debut, ban_origine, ban_motif, strikes, review_reason FROM member_moderation
              WHERE account_id = ? AND banned_until > 0 AND banned_until <= ?'
        );
        $stmt->execute([$accountId, $maintenant]);
        $row = $stmt->fetch();
        if (!$row) {
            return;
        }
        $origine = $row['ban_origine'] !== null ? (string) $row['ban_origine'] : null;

        try {
            self::$journal?->inscrire([
                'acte'      => 'ban_fin',
                'compte'    => $accountId,
                'origine'   => $origine,
                'episode'   => (int) $row['strikes'],
                'debut'     => (int) $row['ban_debut'],
                'jusqu_a'   => (int) $row['banned_until'],
                'observe_a' => $maintenant,
                'motif'     => $row['ban_motif'] ?? $row['review_reason'] ?? 'inconnu',
                'arbitre'   => null,
            ]);
        } catch (\Throwable $e) {
            if (!$contenir) {
                throw $e;
            }
            error_log(sprintf('SelfModerate : fin de peine du compte %d non inscrite au journal — %s', $accountId, $e->getMessage()));
            self::signaler($pdo, $accountId, 'journal_en_panne');
            return;
        }

        if ($origine === 'auto') {
            $pdo->prepare(
                'UPDATE member_moderation
                    SET reputation = ?, voting_rights = 1, convalescent = 0, last_regen_at = 0
                  WHERE account_id = ?'
            )->execute([self::config()->reputationInitiale, $accountId]);
            self::effacerEffets($pdo, $accountId);
            self::designaler($pdo, $accountId, 'ban_auto');
        } else {
            $pdo->prepare('UPDATE member_moderation SET voting_rights = 1 WHERE account_id = ? AND reputation >= ?')
                ->execute([$accountId, self::config()->perteDroitDeVoteSous]);
        }
        $pdo->prepare(
            'UPDATE member_moderation
                SET banned_until = 0, ban_debut = 0, ban_origine = NULL, ban_motif = NULL, updated_at = ?
              WHERE account_id = ?'
        )->execute([$maintenant, $accountId]);
        self::designaler($pdo, $accountId, 'journal_en_panne');
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
            self::cloreBanExpire($pdo, (int) $row['account_id']);
            $clos++;
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
     */
    public static function regenerate(PDO $pdo, int $accountId): void
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
        if ($gagnes < 1) {
            return;
        }
        $rep = min(self::config()->sortieConvalescence(), (int) $row['reputation'] + $gagnes);
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
     * la détection en tire tiennent dans une transaction, que le moteur ouvre
     * si l'hôte n'en a pas, et dont l'hôte reste maître sinon. Le journal
     * s'écrit hors de la base, avant elle : une validation qui échoue après lui
     * laisse une entrée sans effet, jamais une peine sans trace.
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
        // `BEGIN IMMEDIATE`, et non `BEGIN` : un vote lit longtemps avant
        // d'écrire, et SQLite refuse sans attendre à une transaction en lecture
        // de passer en écriture pendant qu'une autre écrit — deux votes
        // simultanés rendraient « database is locked ». Le verrou pris d'emblée
        // fait attendre, dans le délai que PDO accorde. Les trois ordres passent
        // en SQL, jamais par `commit()` : toutes les versions de PDO ne voient
        // pas une transaction ouverte en SQL, et l'on ne valide que ce qu'on a
        // ouvert soi-même.
        $propre = false;
        if (!$pdo->inTransaction()) {
            $pdo->exec('BEGIN IMMEDIATE');
            $propre = true;
        }
        try {
            $resultat = self::appliquerVote($pdo, $voterId, $targetType, $targetId, $value, $reason, $reasonCode);
            if ($propre) {
                $pdo->exec('COMMIT');
            }

            return $resultat;
        } catch (\Throwable $e) {
            if ($propre) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (\Throwable) {
                    // La base a déjà défait la transaction : l'exception d'origine est celle qui compte.
                }
            }
            throw $e;
        }
    }

    /** Le vote lui-même, sous la transaction qu'`applyVote()` tient. */
    private static function appliquerVote(
        PDO $pdo,
        int $voterId,
        string $targetType,
        int $targetId,
        int $value,
        ?string $reason,
        ?string $reasonCode
    ): array {
        if (!in_array($targetType, ['post', 'member'], true) || !in_array($value, [-1, 1], true)) {
            return ['ok' => false, 'message' => static::t('Paramètres de vote invalides.')];
        }

        [$can, $why] = self::canVote($pdo, $voterId);
        if (!$can) {
            return ['ok' => false, 'message' => $why];
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
        $reason = $reason !== null ? trim($reason) : null;
        if ($value === -1 || ($reason !== null && $reason !== '')) {
            [$motifOk, $pourquoi] = self::validateReason($reason);
            if (!$motifOk) {
                return ['ok' => false, 'message' => $pourquoi];
            }
        }
        if ($reasonCode !== null && $reasonCode !== '' && !in_array($reasonCode, self::$reasonCodes, true)) {
            return ['ok' => false, 'message' => static::t('Motif inconnu de cette plateforme.')];
        }

        $author = self::resolveAuthor($pdo, $targetType, $targetId);
        if ($author === null) {
            return ['ok' => false, 'message' => static::t('Cible introuvable.')];
        }
        if ($author === $voterId) {
            return ['ok' => false, 'message' => static::t('Tu ne peux pas voter pour toi-même.')];
        }
        self::ensureRow($pdo, $author);

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
            self::detectPackVoting($pdo, self::balayageDu($pdo) ? null : $author);
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
                $voteIds = self::annulerVotes($pdo, array_map('intval', array_column(
                    array_filter($votes, static fn (array $v): bool =>
                        (int) $v['voter_id'] !== $premier && !(int) $v['blocked']),
                    'id'
                )));
                if (!$voteIds) {
                    continue;
                }
                self::restoreAfterCancel($pdo, $author, $voteIds);
                $cancelled += count($voteIds);
                $packs[] = [
                    'target_author' => $author,
                    'voters'        => $groupe,
                    'premier'       => $premier,
                    'cancelled'     => count($voteIds),
                    'rangs'         => self::consignerMeute($pdo, $groupe, $author, $voteIds),
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
            self::signaler($pdo, $author, 'salve_rapide');
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
     * Signale un compte à l'arbitre, sans écraser un signal plus précis.
     *
     * Deux familles. Un signal d'ÉTAT (`reputation_zero`, `ban_auto`,
     * `journal_en_panne`) se déduit de la base et se lève seul quand l'état
     * cesse. Un signal de COMPORTEMENT
     * (une salve, une récidive de meute…) dit ce qui s'est passé, et seul un
     * arbitre le lève. Un comportement remplace un état ; un état ne remplace
     * jamais un comportement, et un comportement n'en remplace pas un autre.
     */
    private static function signaler(PDO $pdo, int $accountId, string $raison): void
    {
        $etats = ['reputation_zero', 'ban_auto', 'journal_en_panne'];
        $remplacables = in_array($raison, $etats, true) ? $etats : [...$etats, $raison];
        $ph = implode(',', array_fill(0, count($remplacables), '?'));
        $pdo->prepare(
            "UPDATE member_moderation SET needs_review = 1, review_reason = ?, updated_at = ?
              WHERE account_id = ? AND (review_reason IS NULL OR review_reason IN ($ph))"
        )->execute([$raison, time(), $accountId, ...$remplacables]);
    }

    /**
     * Lève un signal d'ÉTAT, et lui seul : l'état a cessé. Un signal de
     * comportement posé entre-temps reste en place, l'arbitre doit encore le
     * voir. Le pendant de `signaler()`.
     */
    private static function designaler(PDO $pdo, int $accountId, string $raison): void
    {
        $pdo->prepare(
            'UPDATE member_moderation SET needs_review = 0, review_reason = NULL
              WHERE account_id = ? AND review_reason = ?'
        )->execute([$accountId, $raison]);
    }

    private static function enforceThresholds(PDO $pdo, int $accountId, int $reputation): void
    {
        if ($reputation < self::config()->perteDroitDeVoteSous) {
            $pdo->prepare('UPDATE member_moderation SET voting_rights = 0 WHERE account_id = ?')->execute([$accountId]);
        }
        // Une réputation au plancher qui arrive ici vient d'une érosion étalée :
        // une meute a déjà été neutralisée et la réputation restituée par
        // `detectPackVoting()`. Un ban prononcé sans humain est une arme
        // d'escalade à la portée de qui n'a aucun droit d'admin ; un signalement
        // seul ne protège personne tant que l'arbitre n'est pas devant. D'où un
        // ban tenu par trois bornes : la peine est graduée et FINIE, visible dès
        // qu'elle tombe (`needs_review` reste levé), et ses deux bouts sont
        // inscrits au journal. L'escalade reste possible ; elle est datée,
        // nominative et réversible d'un geste.
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

        if (self::$journal === null) {
            self::signaler($pdo, $accountId, 'reputation_zero');

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
            return;
        }

        $episode = (int) $etat['strikes'] + 1;
        $duree   = self::config()->dureeBanPourEpisode($episode);
        $jusqua  = $maintenant + $duree;

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
        ]);

        $pdo->prepare(
            "UPDATE member_moderation
                SET banned_until = ?, ban_debut = ?, ban_origine = 'auto', ban_motif = 'reputation_zero',
                    voting_rights = 0, strikes = ?, updated_at = ?
              WHERE account_id = ?"
        )->execute([$jusqua, $maintenant, $episode, $maintenant, $accountId]);
        self::signaler($pdo, $accountId, 'ban_auto');
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
     * @return array{ok: bool, message: string}
     */
    public static function adminBan(PDO $pdo, int $accountId, string $arbitre, string $motif, ?int $seconds = null): array
    {
        [$motifOk, $pourquoi] = self::validateReason($motif);
        if (!$motifOk) {
            return ['ok' => false, 'message' => $pourquoi];
        }
        $seconds ??= self::config()->dureeBanAdmin();
        if ($seconds <= 0) {
            return ['ok' => false, 'message' => static::t('Durée de ban invalide.')];
        }
        $etat = self::getReputation($pdo, $accountId);
        if ($etat['banned']) {
            return ['ok' => false, 'message' => sprintf(
                static::t('Déjà banni jusqu\'au %s : lève ce ban avant d\'en poser un autre.'),
                date('d/m/Y H:i', $etat['banned_until'])
            )];
        }

        $maintenant = time();
        $jusqua     = $maintenant + $seconds;
        $episode    = $etat['strikes'] + 1;
        self::$journal?->inscrire([
            'acte'    => 'ban_debut',
            'compte'  => $accountId,
            'origine' => 'admin',
            'episode' => $episode,
            'duree'   => $seconds,
            'debut'   => $maintenant,
            'jusqu_a' => $jusqua,
            'motif'   => trim($motif),
            'arbitre' => $arbitre,
        ]);
        $pdo->prepare(
            "UPDATE member_moderation
                SET banned_until = ?, ban_debut = ?, ban_origine = 'admin', ban_motif = ?,
                    voting_rights = 0, strikes = ?, needs_review = 0, review_reason = NULL, updated_at = ?
              WHERE account_id = ?"
        )->execute([$jusqua, $maintenant, trim($motif), $episode, $maintenant, $accountId]);

        return ['ok' => true, 'message' => sprintf(static::t('Compte banni jusqu\'au %s.'), date('d/m/Y H:i', $jusqua))];
    }

    /**
     * Grâce d'un arbitre : lève le ban en cours, rend le vote, remet la
     * réputation au départ et les strikes à zéro, lève une suspension de vote
     * et efface le rang de meute. Sans ban en cours, elle fait tout cela sauf
     * lever un ban.
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
        $motif = $motif !== null ? trim($motif) : '';
        if ($motif !== '') {
            [$motifOk, $pourquoi] = self::validateReason($motif);
            if (!$motifOk) {
                return ['ok' => false, 'message' => $pourquoi];
            }
        }
        $etat       = self::getReputation($pdo, $accountId);
        $maintenant = time();
        $anticipee  = false;
        $rangEfface = self::rangDe($pdo, $accountId);

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
            self::$journal?->inscrire([
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
            ]);
        } else {
            self::$journal?->inscrire([
                'acte'       => 'grace',
                'compte'     => $accountId,
                'arbitre'    => $arbitre,
                'motif'      => $motif !== '' ? $motif : null,
                'reputation' => $etat['reputation'],
                'rang_efface'      => $rangEfface,
                'suspension_levee' => $etat['vote_muted'],
            ]);
        }

        $pdo->prepare(
            'UPDATE member_moderation
                SET banned_until = 0, ban_debut = 0, ban_origine = NULL, ban_motif = NULL,
                    voting_rights = 1, reputation = ?, strikes = 0, needs_review = 0, review_reason = NULL,
                    convalescent = 0, vote_muted_until = 0, updated_at = ?
              WHERE account_id = ?'
        )->execute([self::config()->reputationInitiale, $maintenant, $accountId]);
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
    }

    /**
     * Clôt la revue d'un ban sans le lever : l'arbitre a regardé, la peine
     * court. La revue close est celle du compte, quel qu'en soit le motif. Le
     * motif est exigé — un maintien sans raison ne se distingue pas d'un oubli.
     *
     * @return array{ok: bool, message: string}
     */
    public static function adminMaintenir(PDO $pdo, int $accountId, string $arbitre, string $motif): array
    {
        [$motifOk, $pourquoi] = self::validateReason($motif);
        if (!$motifOk) {
            return ['ok' => false, 'message' => $pourquoi];
        }
        $etat = self::getReputation($pdo, $accountId);
        if (!$etat['banned']) {
            return ['ok' => false, 'message' => static::t('Aucun ban en cours sur ce compte.')];
        }
        self::$journal?->inscrire([
            'acte'    => 'ban_maintenu',
            'compte'  => $accountId,
            'origine' => $etat['ban_origine'],
            'arbitre' => $arbitre,
            'motif'   => trim($motif),
            'jusqu_a' => $etat['banned_until'],
        ]);
        $pdo->prepare('UPDATE member_moderation SET needs_review = 0, review_reason = NULL, updated_at = ? WHERE account_id = ?')
            ->execute([time(), $accountId]);

        return ['ok' => true, 'message' => static::t('Ban maintenu ; la revue est close.')];
    }

    /**
     * Classe un signalement sans rien toucher d'autre : l'arbitre a regardé, et
     * le compte n'appelle aucun geste. La grâce remettrait la réputation au
     * départ, ce qu'un signalement classé ne justifie pas. Le motif est exigé,
     * comme pour un maintien.
     *
     * @return array{ok: bool, message: string}
     */
    public static function adminClasser(PDO $pdo, int $accountId, string $arbitre, string $motif): array
    {
        [$motifOk, $pourquoi] = self::validateReason($motif);
        if (!$motifOk) {
            return ['ok' => false, 'message' => $pourquoi];
        }
        $etat = self::getReputation($pdo, $accountId);
        if (!$etat['needs_review']) {
            return ['ok' => false, 'message' => static::t('Aucun signalement en cours sur ce compte.')];
        }
        self::$journal?->inscrire([
            'acte'    => 'revue_classee',
            'compte'  => $accountId,
            'arbitre' => $arbitre,
            'motif'   => trim($motif),
            'raison'  => $etat['review_reason'],
        ]);
        $pdo->prepare('UPDATE member_moderation SET needs_review = 0, review_reason = NULL, updated_at = ? WHERE account_id = ?')
            ->execute([time(), $accountId]);

        return ['ok' => true, 'message' => static::t('Signalement classé.')];
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
     * @return list<array{account_id: int, username: string, origine: ?string, motif: ?string,
     *                    debut: int, jusqu_a: int, reste: int, plancher: ?int, episode: int, a_revoir: bool,
     *                    chute: ?array{votants: int, ages_jours: list<int>, etalement: int}}>
     */
    public static function bansEnCours(PDO $pdo, int $limite = 100): array
    {
        $maintenant = time();
        $stmt = $pdo->prepare(
            'SELECT m.account_id, a.username, m.ban_origine, m.ban_motif, m.ban_debut, m.banned_until,
                    m.strikes, m.needs_review
               FROM member_moderation m JOIN accounts a ON a.id = m.account_id
              WHERE m.banned_until > ? ORDER BY m.banned_until ASC LIMIT ?'
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
                'a_revoir'   => (bool) $r['needs_review'],
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
     * Membres à arbitrer par un admin. `review_reason` dit la cause ; les valeurs
     * sont listées dans le schéma de référence, leurs deux familles dans
     * `signaler()`. Un drapeau sans sa cause laisserait l'admin appliquer le même
     * geste à des situations opposées.
     */
    public static function flaggedForReview(PDO $pdo, int $limit = 50): array
    {
        $stmt = $pdo->prepare(
            'SELECT m.account_id, a.username, m.reputation, m.strikes, m.review_reason, m.convalescent
               FROM member_moderation m JOIN accounts a ON a.id = m.account_id
              WHERE m.needs_review = 1 ORDER BY m.updated_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
