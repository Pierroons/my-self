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
    public const INITIAL_REPUTATION = 20;
    public const MAX_REPUTATION     = 30;
    public const LOSE_VOTING_AT     = 5;
    public const BAN_AT             = 0;

    // Salve rapide : plusieurs downvotes groupés dans le temps, SANS lien entre
    // les votants. Ce n'est pas une meute — c'est le plus souvent une réaction
    // spontanée au même message. Elle ne fait donc rien annuler : elle signale.
    // ⏱️ VALEUR DÉMO. PROD = 300 s.
    public const PACK_WINDOW_SECONDS = 60;
    public const PACK_MIN_VOTERS     = 3;

    // Meute : des votants LIÉS ENTRE EUX qui frappent la même cible. Le lien
    // seul déclenche l'annulation, sur une fenêtre longue — une meute prend son
    // temps. MEUTE_MAX_VOTERS borne le graphe, dont le coût est quadratique.
    public const MEUTE_WINDOW_DAYS = 30;
    public const MEUTE_MIN_LINKED  = 2;
    public const MEUTE_MAX_VOTERS  = 30;

    public const FARMING_WINDOW_DAYS = 60;
    public const FARMING_MAX_UPVOTES = 3;
    public const FARMING_MAX_DOWNVOTES = 3;   // R10-LAB-01 : plafond de downvotes voter->cible sur la fenêtre (anti slow-drip)

    // Anti-Sybil : un compte doit avoir cette ancienneté OU >=1 post pour voter
    // ⏱️ VALEUR DÉMO réduite à 120 s pour qu'un dev puisse tester sans attendre. PROD = 86400 (24 h).
    public const MIN_AGE_TO_VOTE_SECONDS = 120;
    // ⏱️ Durées de bannissement — VALEURS DÉMO (2/10/30 min). PROD = [86400, 604800, 2592000] (24 h / 7 j / 30 j).
    public const BAN_DURATIONS = [120, 600, 1800];
    public const ADMIN_BAN_SECONDS = 600; // ban manuel admin (démo 10 min ; prod : à définir)

    // Convalescence — la réputation remonte avec le temps, pas avec le mérite.
    // L'état est posé sous REGEN_ENTER_BELOW et levé à REGEN_EXIT_AT. Poser un
    // ÉTAT plutôt qu'un seuil est délibéré : conditionner la remontée à
    // « score < 5 » l'arrête pile au seuil qui rend le droit de vote, et laisse
    // le compte à vie sur le fil du rasoir.
    // ⏱️ VALEUR DÉMO : 1 jour. Le protocole décrit +1 par semaine.
    public const REGEN_INTERVAL_SECONDS = 86400;
    public const REGEN_ENTER_BELOW      = self::LOSE_VOTING_AT;
    public const REGEN_EXIT_AT          = self::INITIAL_REPUTATION;

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

    /** Crée la ligne de modération si absente. */
    public static function ensureRow(PDO $pdo, int $accountId): void
    {
        $pdo->prepare(
            'INSERT OR IGNORE INTO member_moderation (account_id, reputation, updated_at) VALUES (?, ?, ?)'
        )->execute([$accountId, self::INITIAL_REPUTATION, time()]);
    }

    public static function getReputation(PDO $pdo, int $accountId): array
    {
        self::ensureRow($pdo, $accountId);
        self::regenerate($pdo, $accountId);
        $stmt = $pdo->prepare(
            'SELECT reputation, strikes, voting_rights, banned_until, needs_review, review_reason, convalescent
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
            'needs_review'  => (bool) $row['needs_review'],
            'review_reason' => $row['review_reason'] !== null ? (string) $row['review_reason'] : null,
            'convalescent'  => (bool) $row['convalescent'],
        ];
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
        $gagnes = intdiv($now - $last, self::REGEN_INTERVAL_SECONDS);
        if ($gagnes < 1) {
            return;
        }
        $rep = min(self::REGEN_EXIT_AT, (int) $row['reputation'] + $gagnes);
        // L'horloge avance des intervalles consommés, pas jusqu'à maintenant : le
        // reste de temps est acquis et compte pour le point suivant.
        $pdo->prepare('UPDATE member_moderation SET reputation = ?, last_regen_at = ?, updated_at = ? WHERE account_id = ?')
            ->execute([$rep, $last + $gagnes * self::REGEN_INTERVAL_SECONDS, $now, $accountId]);

        if ($rep >= self::LOSE_VOTING_AT) {
            $pdo->prepare('UPDATE member_moderation SET voting_rights = 1 WHERE account_id = ?')->execute([$accountId]);
        }
        if ($rep >= self::REGEN_EXIT_AT) {
            // Revenu à son point de départ : l'état se lève, et le signalement
            // qui accompagnait la chute n'a plus d'objet.
            $pdo->prepare(
                'UPDATE member_moderation SET convalescent = 0, needs_review = 0, review_reason = NULL WHERE account_id = ?'
            )->execute([$accountId]);
        }
    }

    /** Anti-Sybil + sanctions : ce membre peut-il voter ? Retourne [bool, raison]. */
    public static function canVote(PDO $pdo, int $accountId): array
    {
        $rep = self::getReputation($pdo, $accountId);
        if ($rep['banned']) {
            return [false, static::t('Compte temporairement suspendu.')];
        }
        if (!$rep['voting_rights']) {
            return [false, static::t('Droit de vote retiré (réputation trop basse).')];
        }
        // Anti-Sybil : compte récent sans activité ne peut pas voter
        $stmt = $pdo->prepare('SELECT created_at FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $createdAt = (int) $stmt->fetchColumn();
        $age = time() - $createdAt;
        if ($age < self::MIN_AGE_TO_VOTE_SECONDS) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE account_id = ?');
            $stmt->execute([$accountId]);
            $nbPosts = (int) $stmt->fetchColumn();
            if ($nbPosts < 1) {
                return [false, static::t('Compte trop récent : publie au moins un message ou attends 24 h pour pouvoir voter (anti-Sybil).')];
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
     * FARMING_MAX_UPVOTES sur la fenêtre.
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

        $author = self::resolveAuthor($pdo, $targetType, $targetId);
        if ($author === null) {
            return ['ok' => false, 'message' => 'Cible introuvable.'];
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

        // Anti upvote-farming : >3 upvotes voter→author sur 60j
        if ($value === 1) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM mod_votes WHERE voter_id = ? AND target_author = ? AND value = 1 AND blocked = 0 AND created_at >= ?'
            );
            $stmt->execute([$voterId, $author, time() - self::FARMING_WINDOW_DAYS * 86400]);
            if ((int) $stmt->fetchColumn() >= self::FARMING_MAX_UPVOTES) {
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
            $stmt->execute([$voterId, $author, time() - self::FARMING_WINDOW_DAYS * 86400]);
            if ((int) $stmt->fetchColumn() >= self::FARMING_MAX_DOWNVOTES) {
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
        $newRep = max(self::BAN_AT, min(self::MAX_REPUTATION, $rep['reputation'] + $value));
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
        $stmt->execute([time() - self::MEUTE_WINDOW_DAYS * 86400, $a, $b, $b, $a]);
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
        $sinceMeute = $maintenant - self::MEUTE_WINDOW_DAYS * 86400;
        // Le seuil est interpolé, pas lié : un paramètre PDO arrive en TEXT, et
        // SQLite range tout INTEGER avant tout TEXT — `COUNT(*) >= '2'` est donc
        // toujours faux. Les colonnes INTEGER convertissent leur paramètre par
        // affinité ; une expression comme COUNT(*) n'a aucune affinité.
        $minLies = (int) self::MEUTE_MIN_LINKED;
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
            if (count($voters) > self::MEUTE_MAX_VOTERS) {
                $tronques[] = ['target_author' => $author, 'voters' => count($voters), 'scanned' => self::MEUTE_MAX_VOTERS];
                $voters = array_slice($voters, 0, self::MEUTE_MAX_VOTERS);
            }

            foreach (self::linkedGroups($pdo, $voters) as $groupe) {
                if (count($groupe) < self::MEUTE_MIN_LINKED) {
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
                    ->execute([$restore, self::MAX_REPUTATION, $maintenant, $author]);
                self::restoreAfterCancel($pdo, $author, $restore);
                $cancelled += $restore;
                $packs[] = ['target_author' => $author, 'voters' => $groupe, 'cancelled' => $restore];
            }
        }

        // ── Salve rapide : fenêtre courte, aucun lien, aucune annulation ─────
        $sinceSalve = $maintenant - self::PACK_WINDOW_SECONDS * 2;
        $minVotants = (int) self::PACK_MIN_VOTERS;
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
            // PACK_WINDOW_SECONDS. Raisonner par fenêtre glissante plutôt que sur
            // l'étalement global empêche un vote espacé de masquer le groupe.
            $best = [];
            for ($i = 0; $i < $n; $i++) {
                $cluster = [];
                for ($k = $i; $k < $n; $k++) {
                    if ((int) $votes[$k]['created_at'] - (int) $votes[$i]['created_at'] > self::PACK_WINDOW_SECONDS) {
                        break;
                    }
                    $cluster[(int) $votes[$k]['voter_id']] = true;
                }
                if (count($cluster) > count($best)) {
                    $best = $cluster;
                }
            }
            if (count($best) < self::PACK_MIN_VOTERS) {
                continue;
            }
            $pdo->prepare(
                "UPDATE member_moderation SET needs_review = 1, review_reason = 'salve_rapide', updated_at = ? WHERE account_id = ?"
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
        if ($rep['reputation'] >= self::LOSE_VOTING_AT) {
            $pdo->prepare('UPDATE member_moderation SET voting_rights = 1 WHERE account_id = ?')->execute([$author]);
        }
        if ($rep['reputation'] > self::BAN_AT && $rep['banned_until'] > 0) {
            $pdo->prepare('UPDATE member_moderation SET banned_until = 0, strikes = MAX(0, strikes - ?) WHERE account_id = ?')
                ->execute([$restore, $author]);
        }
        // La convalescence avait été ouverte par une chute qui n'aurait pas dû
        // avoir lieu : on la referme, plutôt que d'imposer une guérison au temps
        // pour une faute annulée.
        if ($rep['reputation'] >= self::REGEN_ENTER_BELOW) {
            $pdo->prepare(
                "UPDATE member_moderation SET convalescent = 0, needs_review = 0, review_reason = NULL
                  WHERE account_id = ? AND (review_reason IS NULL OR review_reason <> 'salve_rapide')"
            )->execute([$author]);
        }
    }

    private static function enforceThresholds(PDO $pdo, int $accountId, int $reputation): void
    {
        if ($reputation < self::LOSE_VOTING_AT) {
            $pdo->prepare('UPDATE member_moderation SET voting_rights = 0 WHERE account_id = ?')->execute([$accountId]);
        }
        // R10-LAB-01 — Plus de BAN AUTOMATIQUE à rep<=0. Les packs RAPIDES sont déjà annulés et la
        // réputation restaurée par detectPackVoting AVANT ce point ; une rep<=0 qui subsiste ici vient
        // donc toujours d'une érosion ÉTALÉE (slow-drip malveillant ou downvotes légitimes dispersés) —
        // trop ambiguë pour un ban auto, qui serait alors un vecteur d'escalade sans droits admin.
        // On FLAGGE pour revue humaine ; l'exclusion reste une décision admin explicite (adminBan).
        if ($reputation <= self::BAN_AT) {
            $pdo->prepare(
                "UPDATE member_moderation SET needs_review = 1, review_reason = 'reputation_zero' WHERE account_id = ?"
            )->execute([$accountId]);
        }

        // Sous le seuil de vote, la convalescence s'ouvre. `AND convalescent = 0`
        // n'est pas une précaution d'idempotence : sans lui, chaque nouveau
        // downvote remettrait l'horloge à zéro, et un votant patient suffirait à
        // repousser la remontée indéfiniment.
        if ($reputation < self::REGEN_ENTER_BELOW) {
            $pdo->prepare(
                'UPDATE member_moderation SET convalescent = 1, last_regen_at = ? WHERE account_id = ? AND convalescent = 0'
            )->execute([time(), $accountId]);
        }
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

    /** Liste des votes bloqués (pour la page modération). */
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
     * Action admin manuelle (« squizz ») — la machine pré-mâche, l'humain tranche.
     * Bannit un compte (durée démo), retire le droit de vote, +1 strike.
     */
    public static function adminBan(PDO $pdo, int $accountId, int $seconds = self::ADMIN_BAN_SECONDS): void
    {
        self::ensureRow($pdo, $accountId);
        $pdo->prepare(
            'UPDATE member_moderation SET banned_until = ?, voting_rights = 0, strikes = strikes + 1, needs_review = 0, updated_at = ? WHERE account_id = ?'
        )->execute([time() + $seconds, time(), $accountId]);
    }

    /** Grâce admin : lève le ban, restaure le droit de vote, remet la réputation initiale, strikes à 0. */
    public static function adminPardon(PDO $pdo, int $accountId): void
    {
        self::ensureRow($pdo, $accountId);
        $pdo->prepare(
            'UPDATE member_moderation SET banned_until = 0, voting_rights = 1, reputation = ?, strikes = 0, needs_review = 0, updated_at = ? WHERE account_id = ?'
        )->execute([self::INITIAL_REPUTATION, time(), $accountId]);
    }

    /**
     * Membres à arbitrer par un admin. `review_reason` dit lequel des deux cas :
     * `reputation_zero` (érosion étalée jusqu'à zéro) ou `salve_rapide` (plusieurs
     * votants sans lien dans la même minute). Un drapeau sans sa cause laisserait
     * l'admin appliquer le même geste à deux situations opposées.
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
