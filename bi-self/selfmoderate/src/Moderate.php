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
    public const REASON_MIN_WORDS          = 3;
    public const REASON_MAX_WORD_REPEATS   = 2;
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
        self::ensureRow($pdo, $accountId);
        self::regenerate($pdo, $accountId);
        self::cloreBanExpire($pdo, $accountId);
        $stmt = $pdo->prepare(
            'SELECT reputation, strikes, voting_rights, banned_until, ban_debut, ban_origine, ban_motif,
                    needs_review, review_reason, convalescent, vote_muted_until
               FROM member_moderation WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
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
     */
    private static function cloreBanExpire(PDO $pdo, int $accountId): void
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

        if ($origine === 'auto') {
            $pdo->prepare(
                "UPDATE member_moderation
                    SET reputation = ?, voting_rights = 1, convalescent = 0, last_regen_at = 0,
                        needs_review  = CASE WHEN review_reason = 'ban_auto' THEN 0 ELSE needs_review END,
                        review_reason = CASE WHEN review_reason = 'ban_auto' THEN NULL ELSE review_reason END
                  WHERE account_id = ?"
            )->execute([self::config()->reputationInitiale, $accountId]);
        } else {
            $pdo->prepare('UPDATE member_moderation SET voting_rights = 1 WHERE account_id = ? AND reputation >= ?')
                ->execute([$accountId, self::config()->perteDroitDeVoteSous]);
        }
        $pdo->prepare(
            'UPDATE member_moderation
                SET banned_until = 0, ban_debut = 0, ban_origine = NULL, ban_motif = NULL, updated_at = ?
              WHERE account_id = ?'
        )->execute([$maintenant, $accountId]);
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
            $pdo->prepare(
                "UPDATE member_moderation SET needs_review = 0, review_reason = NULL
                  WHERE account_id = ? AND review_reason = 'reputation_zero'"
            )->execute([$accountId]);
        }
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
        if (max($comptes) > self::REASON_MAX_WORD_REPEATS) {
            return [false, static::t('Un mot revient trop souvent : dis ce que tu reproches.')];
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
     * Applique un vote. Retourne ['ok'=>..., 'blocked'=>..., 'new_reputation'=>..., 'message'=>...].
     *
     * Le motif est exigé au downvote et facultatif à l'upvote : il existe pour
     * que la personne sanctionnée sache ce qu'on lui reproche, et un pouce en
     * l'air ne sanctionne personne. L'upvote reste tenu par son plafond de
     * `farmingUpvotesMax` sur la fenêtre.
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
            // ⚠️ Seule chaîne de ce fichier qui ne passait pas par `static::t()`,
            // et c'est la seule que le balayage lisait.
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
                $pdo->prepare(
                    'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, reason_code, blocked, blocked_reason, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
                )->execute([$voterId, $targetType, $targetId, $author, $value, $reason, $reasonCode, 'upvote_farming', time()]);
                return ['ok' => true, 'blocked' => true, 'blocked_reason' => 'upvote_farming',
                        'message' => static::t('Vote enregistré mais neutralisé : trop d\'upvotes répétés vers ce membre (anti-farming).')];
            }
        }

        // R10-LAB-01 — Anti downvote-farming : limite les downvotes répétés d'un même votant vers le
        // même auteur (membre + ses posts) sur la fenêtre longue. Casse le « slow-drip » d'un votant
        // patient qui downvote le membre puis chacun de ses posts pour éroder sa réputation en douce.
        if ($value === -1) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM mod_votes WHERE voter_id = ? AND target_author = ? AND value = -1 AND blocked = 0 AND created_at >= ?'
            );
            $stmt->execute([$voterId, $author, time() - self::config()->fenetreFarmingJours * 86400]);
            if ((int) $stmt->fetchColumn() >= self::config()->farmingDownvotesMax) {
                $pdo->prepare(
                    'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, reason_code, blocked, blocked_reason, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
                )->execute([$voterId, $targetType, $targetId, $author, $value, $reason, $reasonCode, 'downvote_farming', time()]);
                return ['ok' => true, 'blocked' => true, 'blocked_reason' => 'downvote_farming',
                        'message' => static::t('Vote enregistré mais neutralisé : trop de downvotes répétés vers ce membre (anti-farming).')];
            }
        }

        // Insert + maj réputation
        $pdo->prepare(
            'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, reason, reason_code, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$voterId, $targetType, $targetId, $author, $value, $reason, $reasonCode, time()]);

        $rep = self::getReputation($pdo, $author);
        $newRep = max(self::config()->banA, min(self::config()->reputationMax, $rep['reputation'] + $value));
        $pdo->prepare('UPDATE member_moderation SET reputation = ?, updated_at = ? WHERE account_id = ?')
            ->execute([$newRep, time(), $author]);

        // V8-LAB-02 : détecter un pack AVANT d'appliquer les sanctions de seuil. Si le downvote
        // courant complète un cluster coordonné, la réputation est restaurée d'abord → enforceThresholds
        // ne pose alors NI ban NI strike injuste. detectPackVoting reste aussi appelé au sweep
        // (/api/detect_abuse.php) comme filet, où il lève le ban/strikes a posteriori.
        if ($value === -1) {
            self::detectPackVoting($pdo);
            $newRep = self::getReputation($pdo, $author)['reputation']; // après éventuelle restauration
        }
        self::enforceThresholds($pdo, $author, $newRep);

        return ['ok' => true, 'blocked' => false, 'new_reputation' => $newRep,
                'message' => 'Vote pris en compte.'];
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
        if ($a === $b) {
            return false;
        }
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT sender_id) FROM dm
              WHERE created_at >= ?
                AND ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))'
        );
        $stmt->execute([time() - self::config()->fenetreMeuteJours * 86400, $a, $b, $b, $a]);
        // Deux expéditeurs distincts sur les messages échangés entre eux : chacun
        // a écrit à l'autre.
        return (int) $stmt->fetchColumn() === 2;
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
        $n = count($voters);
        for ($i = 0; $i < $n; $i++) {
            for ($k = $i + 1; $k < $n; $k++) {
                if ($find($voters[$i]) === $find($voters[$k])) {
                    continue;
                }
                if (self::areLinked($pdo, $voters[$i], $voters[$k])) {
                    $parent[$find($voters[$i])] = $find($voters[$k]);
                }
            }
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
     * Deux détections, deux conséquences.
     *
     * MEUTE — des votants liés entre eux ont frappé la même cible sur la fenêtre
     * longue. Le lien est le signal : leurs votes sont annulés, la réputation
     * restituée, et les sanctions posées pendant la chute sont levées.
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
     */
    public static function detectPackVoting(PDO $pdo): array
    {
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
        $stmt = $pdo->prepare("
            SELECT target_author FROM mod_votes
             WHERE value = -1 AND blocked = 0 AND created_at >= ?
          GROUP BY target_author HAVING COUNT(DISTINCT voter_id) >= $minLies
        ");
        $stmt->execute([$sinceMeute]);
        $auteurs = array_map('intval', array_column($stmt->fetchAll(), 'target_author'));

        foreach ($auteurs as $author) {
            $vs = $pdo->prepare('SELECT DISTINCT voter_id FROM mod_votes
                                  WHERE target_author = ? AND value = -1 AND blocked = 0 AND created_at >= ?
                                  ORDER BY voter_id ASC');
            $vs->execute([$author, $sinceMeute]);
            $voters = array_map('intval', array_column($vs->fetchAll(), 'voter_id'));

            // Le graphe est quadratique : on borne, et on le DIT. Une troncature
            // muette se lirait comme une absence de meute.
            if (count($voters) > self::config()->meuteVotantsMax) {
                $tronques[] = ['target_author' => $author, 'voters' => count($voters), 'scanned' => self::config()->meuteVotantsMax];
                $voters = array_slice($voters, 0, self::config()->meuteVotantsMax);
            }

            foreach (self::linkedGroups($pdo, $voters) as $groupe) {
                if (count($groupe) < self::config()->meuteLiensMin) {
                    continue;
                }
                $ph = implode(',', array_fill(0, count($groupe), '?'));
                $ids = $pdo->prepare("SELECT id FROM mod_votes
                                       WHERE target_author = ? AND value = -1 AND blocked = 0
                                         AND created_at >= ? AND voter_id IN ($ph)");
                $ids->execute(array_merge([$author, $sinceMeute], $groupe));
                $voteIds = array_map('intval', array_column($ids->fetchAll(), 'id'));
                if (!$voteIds) {
                    continue;
                }
                $phv = implode(',', array_fill(0, count($voteIds), '?'));
                $pdo->prepare("UPDATE mod_votes SET blocked = 1, blocked_reason = 'pack_voting' WHERE id IN ($phv)")
                    ->execute($voteIds);
                $restore = count($voteIds);
                $pdo->prepare('UPDATE member_moderation SET reputation = MIN(reputation + ?, ?), updated_at = ? WHERE account_id = ?')
                    ->execute([$restore, self::config()->reputationMax, $maintenant, $author]);
                self::restoreAfterCancel($pdo, $author, $restore);
                $cancelled += $restore;
                // La victime est remise d'aplomb avant qu'on regarde qui a frappé :
                // sa protection ne dépend d'aucun rang de récidive.
                $sanctions = self::sanctionnerMeute($pdo, $groupe, $author, $voteIds);
                $packs[] = [
                    'target_author' => $author,
                    'voters'        => $groupe,
                    'cancelled'     => $restore,
                    'sanctions'     => $sanctions,
                ];
            }
        }

        // ── Salve rapide : fenêtre courte, aucun lien, aucune annulation ─────
        $sinceSalve = $maintenant - self::config()->fenetreSalveSecondes * 2;
        $minVotants = (int) self::config()->salveVotantsMin;
        $stmt = $pdo->prepare("
            SELECT target_author FROM mod_votes
             WHERE value = -1 AND blocked = 0 AND created_at >= ?
          GROUP BY target_author HAVING COUNT(DISTINCT voter_id) >= $minVotants
        ");
        $stmt->execute([$sinceSalve]);

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
            // 🔑 N'écrit QUE sur une case vide, et c'est une mesure de sûreté.
            //
            // Trois comptes ordinaires suffisent à déclencher cette branche
            // (`salveVotantsMin`), et elle tourne à chaque vote négatif. Sans la
            // clause, un tiers écrasait donc le motif d'arbitrage d'autrui par le
            // plus bénin de la liste. Deux pertes, dans les deux sens :
            //
            //  · BLANCHIMENT — un `meute_recidive`, dont ce fichier dit qu'« une
            //    récidive de meute ne se rachète pas », devenait « plusieurs
            //    votants sans lien ». L'arbitre lisait une autre situation que
            //    celle qui s'était produite ;
            //  · MARQUE INDÉLÉBILE — les deux chemins qui lèvent un signalement
            //    tout seuls n'acceptent que `reputation_zero` (`regenerate`,
            //    `restoreAfterCancel`) ou `ban_auto` (`cloreBanExpire`). Un motif
            //    écrasé par celui-ci ne se levait donc PLUS jamais sans un geste
            //    d'arbitre : le titulaire gardait un signalement permanent qu'un
            //    tiers lui avait posé.
            //
            // ⚠️ Le signal perdu quand la case est occupée ne coûte rien : un
            // compte déjà signalé est déjà sous les yeux de l'arbitre, et ce
            // libellé-ci est le plus faible des quatre. Ce qui coûtait, c'était
            // de remplacer une information par une moins précise.
            //
            // ⚠️ Et l'asymétrie subsiste pour les trois autres poseurs
            // (`meute_recidive`, `reputation_zero`, `ban_auto`) : aucun ne regarde
            // le motif en place. Ils ne sont pas déclenchables par un tiers aussi
            // directement, mais la propriété « un champ lu par un arbitre n'est
            // jamais écrasé par un chemin automatique » n'est tenue qu'ici.
            $pdo->prepare(
                "UPDATE member_moderation SET needs_review = 1, review_reason = 'salve_rapide', updated_at = ?
                  WHERE account_id = ? AND (review_reason IS NULL OR review_reason = 'salve_rapide')"
            )->execute([$maintenant, $author]);
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
     * Après annulation d'une meute : rend ce que la chute injuste avait coûté.
     * Le nombre de strikes retirés est borné à la taille de l'annulation, biais
     * assumé en faveur de la personne visée.
     */
    private static function restoreAfterCancel(PDO $pdo, int $author, int $restore): void
    {
        $rep = self::getReputation($pdo, $author);
        if ($rep['reputation'] >= self::config()->perteDroitDeVoteSous) {
            $pdo->prepare('UPDATE member_moderation SET voting_rights = 1 WHERE account_id = ?')->execute([$author]);
        }
        // Seul un ban automatique se lève ici : il est venu des votes que la
        // meute a faussés. Un ban d'arbitre n'en dépend pas. Pas de plancher non
        // plus : cette levée répare une injustice, elle n'en accorde aucune.
        if ($rep['reputation'] > self::config()->banA && $rep['banned'] && $rep['ban_origine'] === 'auto') {
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
                "UPDATE member_moderation
                    SET banned_until = 0, ban_debut = 0, ban_origine = NULL, ban_motif = NULL,
                        strikes = MAX(0, strikes - ?),
                        needs_review  = CASE WHEN review_reason = 'ban_auto' THEN 0 ELSE needs_review END,
                        review_reason = CASE WHEN review_reason = 'ban_auto' THEN NULL ELSE review_reason END
                  WHERE account_id = ?"
            )->execute([$restore, $author]);
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
            $pdo->prepare(
                "UPDATE member_moderation SET needs_review = 0, review_reason = NULL
                  WHERE account_id = ? AND review_reason = 'reputation_zero'"
            )->execute([$author]);
        }
    }

    /**
     * Rang de récidive d'un votant : le nombre d'épisodes de meute qu'il a à son
     * compte. Lu comme un MAX et non comme un COUNT — un épisode laisse une
     * ligne par cible, et trois victimes le même soir restent un épisode.
     */
    public static function rangDe(PDO $pdo, int $voterId): int
    {
        $stmt = $pdo->prepare('SELECT MAX(rang) FROM mod_pack_flags WHERE voter_id = ?');
        $stmt->execute([$voterId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Fait payer une meute à ceux qui l'ont formée. L'annulation des votes a
     * déjà eu lieu et ne dépend pas de ce qui suit : la victime est protégée dès
     * le premier passage, la peine attend la récidive.
     *
     * Un votant ne monte d'un rang qu'une fois par `meuteEpisodeCooldown`. Sans
     * cette borne, la boucle de detectPackVoting — qui traite toutes les cibles
     * d'un même passage — ferait franchir trois paliers d'un coup à un groupe
     * qui a frappé trois personnes, et l'avertissement du premier rang ne serait
     * jamais vu par personne. Les cibles supplémentaires sont enregistrées
     * quand même : l'admin doit voir l'ampleur, pas seulement le rang.
     *
     * Le rang appartient au VOTANT. Compté sur la cible, un groupe qui change de
     * proie resterait au premier palier indéfiniment, et une victime visée par
     * plusieurs groupes ferait punir des primo-délinquants.
     *
     * @param  int[] $voters
     * @param  int[] $voteIds
     * @return array<int, array{voter: int, rang: int, action: string}>
     */
    public static function sanctionnerMeute(PDO $pdo, array $voters, int $author, array $voteIds): array
    {
        $maintenant = time();
        $sanctions  = [];

        foreach ($voters as $voter) {
            $voter = (int) $voter;
            self::ensureRow($pdo, $voter);

            $stmt = $pdo->prepare('SELECT rang, detected_at FROM mod_pack_flags
                                    WHERE voter_id = ? ORDER BY detected_at DESC, id DESC LIMIT 1');
            $stmt->execute([$voter]);
            $dernier = $stmt->fetch();

            $memeEpisode = $dernier
                && ($maintenant - (int) $dernier['detected_at']) < self::config()->meuteEpisodeCooldown;
            $rang = $memeEpisode
                ? (int) $dernier['rang']
                : self::rangDe($pdo, $voter) + 1;

            $action = match (true) {
                $rang <= 1 => 'avertissement',
                $rang === 2 => 'suspension_7j',
                $rang === 3 => 'suspension_30j',
                default     => 'revue_admin',
            };

            $pdo->prepare(
                'INSERT INTO mod_pack_flags (voter_id, target_author, rang, action, vote_ids, detected_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$voter, $author, $rang, $action, json_encode(array_values($voteIds)), $maintenant]);

            // Une cible de plus dans un épisode déjà puni ne rejoue pas la peine :
            // la suspension ne se cumule pas avec elle-même.
            if (!$memeEpisode) {
                self::appliquerPeine($pdo, $voter, $rang, $maintenant);
            }
            $sanctions[] = ['voter' => $voter, 'rang' => $rang, 'action' => $action];
        }

        return $sanctions;
    }

    /**
     * La peine attachée à un rang. Le rang ne bannit jamais par lui-même : au
     * troisième, la perte de points peut mener au ban de la réputation zéro,
     * comme n'importe quelle autre chute.
     */
    private static function appliquerPeine(PDO $pdo, int $voter, int $rang, int $maintenant): void
    {
        if ($rang <= 1) {
            return;   // le premier épisode ne coûte que ses votes
        }

        // La suspension vaut du deuxième épisode jusqu'au dernier : au-delà du
        // troisième, le rang ajoute la revue humaine sans rien retirer. Sans ce
        // report, le quatrième épisode serait moins puni que le troisième — la
        // peine expirerait pendant que l'admin regarde.
        $duree = $rang === 2 ? self::config()->meuteMute2 : self::config()->meuteMute3;
        // MAX : une nouvelle suspension ne raccourcit jamais celle en cours.
        $pdo->prepare(
            'UPDATE member_moderation SET vote_muted_until = MAX(vote_muted_until, ?), updated_at = ?
              WHERE account_id = ?'
        )->execute([$maintenant + $duree, $maintenant, $voter]);

        if ($rang === 3) {
            // Une peine échue se referme avant la perte de points : refermée
            // après, elle remettrait la réputation au départ et effacerait la
            // pénalité qu'on vient de poser.
            self::cloreBanExpire($pdo, $voter);
            $pdo->prepare(
                'UPDATE member_moderation SET reputation = MAX(reputation - ?, 0), updated_at = ?
                  WHERE account_id = ?'
            )->execute([self::config()->meutePenalite3, $maintenant, $voter]);
            $stmt = $pdo->prepare('SELECT reputation FROM member_moderation WHERE account_id = ?');
            $stmt->execute([$voter]);
            // La perte de points ouvre la convalescence comme n'importe quelle
            // autre : la suspension, elle, tiendra sa durée par-dessus.
            self::enforceThresholds($pdo, $voter, (int) $stmt->fetchColumn());
        }

        if ($rang >= 4) {
            // R10-LAB-01 tient aussi ici : au-delà du troisième épisode, la
            // machine s'arrête et passe la main. Exclure quelqu'un reste un
            // geste humain, y compris quand il l'a bien cherché.
            $pdo->prepare(
                "UPDATE member_moderation SET needs_review = 1, review_reason = 'meute_recidive', updated_at = ?
                  WHERE account_id = ?"
            )->execute([$maintenant, $voter]);
        }
    }

    private static function enforceThresholds(PDO $pdo, int $accountId, int $reputation): void
    {
        if ($reputation < self::config()->perteDroitDeVoteSous) {
            $pdo->prepare('UPDATE member_moderation SET voting_rights = 0 WHERE account_id = ?')->execute([$accountId]);
        }
        // Une rep<=0 qui arrive ici vient d'une érosion ÉTALÉE : les salves rapides
        // sont déjà annulées et la réputation restaurée par detectPackVoting avant
        // ce point. R10-LAB-01 en concluait qu'aucun ban ne devait tomber seul, un
        // tel ban étant une arme d'escalade à la portée de qui n'a aucun droit
        // d'admin. Le signalement qui l'a remplacé ne protège toutefois personne
        // tant que l'arbitre n'est pas devant.
        //
        // Le ban automatique revient donc, tenu par trois bornes que R10-LAB-01
        // n'avait pas : la peine est graduée et FINIE, elle est visible dès
        // qu'elle tombe (`needs_review` reste levé), et ses deux bouts sont
        // inscrits au journal. L'escalade reste possible ; elle est désormais
        // datée, nominative et réversible d'un geste.
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
            $pdo->prepare(
                "UPDATE member_moderation SET needs_review = 1, review_reason = 'reputation_zero', updated_at = ?
                  WHERE account_id = ?"
            )->execute([$maintenant, $accountId]);

            return;
        }

        self::cloreBanExpire($pdo, $accountId);
        $stmt = $pdo->prepare('SELECT reputation, banned_until, strikes FROM member_moderation WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $etat = $stmt->fetch();
        if (!$etat || (int) $etat['reputation'] > self::config()->banA) {
            return;
        }
        // Une peine en cours ne se rallonge pas au downvote suivant. Sans cette
        // garde, sa fin reculerait aussi longtemps qu'on vote contre le compte :
        // la peine graduée existe précisément pour que l'escalade ait un terme.
        if ((int) $etat['banned_until'] > $maintenant) {
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
                    voting_rights = 0, strikes = ?, needs_review = 1, review_reason = 'ban_auto', updated_at = ?
              WHERE account_id = ?"
        )->execute([$jusqua, $maintenant, $episode, $maintenant, $accountId]);
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
     * réputation au départ et les strikes à zéro. Sans ban en cours, elle ne
     * fait que remettre la réputation au départ.
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
            ]);
        } else {
            self::$journal?->inscrire([
                'acte'       => 'grace',
                'compte'     => $accountId,
                'arbitre'    => $arbitre,
                'motif'      => $motif !== '' ? $motif : null,
                'reputation' => $etat['reputation'],
            ]);
        }

        $pdo->prepare(
            'UPDATE member_moderation
                SET banned_until = 0, ban_debut = 0, ban_origine = NULL, ban_motif = NULL,
                    voting_rights = 1, reputation = ?, strikes = 0, needs_review = 0, review_reason = NULL,
                    convalescent = 0, updated_at = ?
              WHERE account_id = ?'
        )->execute([self::config()->reputationInitiale, $maintenant, $accountId]);

        return ['ok' => true, 'anticipee' => $anticipee, 'message' => match (true) {
            $anticipee      => static::t('Ban levé avant son plancher : la levée anticipée et son motif sont au journal.'),
            $etat['banned'] => static::t('Ban levé.'),
            default         => static::t('Réputation remise au point de départ.'),
        }];
    }

    /**
     * Clôt la revue d'un ban sans le lever : l'arbitre a regardé, la peine
     * court. Le motif est exigé — un maintien sans raison ne se distingue pas
     * d'un oubli.
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

    /** Le compte purge-t-il un ban ? Pour l'hôte qui décide ce qu'un ban bloque au-delà du vote. */
    public static function estBanni(PDO $pdo, int $accountId): bool
    {
        return self::getReputation($pdo, $accountId)['banned'];
    }

    /**
     * Les bans en cours, avec ce qu'un arbitre doit voir avant de toucher à
     * l'un d'eux : l'origine, le motif, le temps restant et la date du plancher.
     *
     * @return list<array{account_id: int, username: string, origine: ?string, motif: ?string,
     *                    debut: int, jusqu_a: int, reste: int, plancher: ?int, episode: int, a_revoir: bool}>
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

        return array_map(static function (array $r) use ($maintenant): array {
            $debut = (int) $r['ban_debut'];
            $fin   = (int) $r['banned_until'];
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
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Membres à arbitrer par un admin. `review_reason` dit la cause :
     * `reputation_zero` (érosion jusqu'à zéro, sans journal branché), `ban_auto`
     * (ban automatique en cours), `salve_rapide` (plusieurs votants sans lien dans
     * la même fenêtre) ou `meute_recidive` (4e épisode de meute). Un drapeau sans
     * sa cause laisserait l'admin appliquer le même geste à des situations opposées.
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
