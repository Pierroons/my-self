<?php
/**
 * MySelf-Lab — SelfModerate : réputation distribuée + anti-manipulation.
 *
 * Vote sur posts (±1, affecte la réputation de l'auteur) ET sur membres.
 * Défenses : anti-Sybil (seuils), upvote-farming, pack-voting, sanctions graduées.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

final class Moderate
{
    public const INITIAL_REPUTATION = 20;
    public const MAX_REPUTATION     = 30;
    public const LOSE_VOTING_AT     = 5;
    public const BAN_AT             = 0;

    public const PACK_WINDOW_SECONDS = 60;
    public const PACK_MIN_VOTERS     = 3;
    public const FARMING_WINDOW_DAYS = 60;
    public const FARMING_MAX_UPVOTES = 3;
    public const FARMING_MAX_DOWNVOTES = 3;   // R10-LAB-01 : plafond de downvotes voter->cible sur la fenêtre (anti slow-drip)

    // Anti-Sybil : un compte doit avoir cette ancienneté OU >=1 post pour voter
    // ⏱️ VALEUR DÉMO réduite à 120 s pour qu'un dev puisse tester sans attendre. PROD = 86400 (24 h).
    public const MIN_AGE_TO_VOTE_SECONDS = 120;
    // ⏱️ Durées de bannissement — VALEURS DÉMO (2/10/30 min). PROD = [86400, 604800, 2592000] (24 h / 7 j / 30 j).
    public const BAN_DURATIONS = [120, 600, 1800];
    public const ADMIN_BAN_SECONDS = 600; // ban manuel admin (démo 10 min ; prod : à définir)

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
        $stmt = $pdo->prepare('SELECT reputation, strikes, voting_rights, banned_until FROM member_moderation WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        return [
            'reputation'    => (int) $row['reputation'],
            'strikes'       => (int) $row['strikes'],
            'voting_rights' => (bool) $row['voting_rights'],
            'banned'        => ((int) $row['banned_until']) > time(),
            'banned_until'  => (int) $row['banned_until'],
        ];
    }

    /** Anti-Sybil + sanctions : ce membre peut-il voter ? Retourne [bool, raison]. */
    public static function canVote(PDO $pdo, int $accountId): array
    {
        $rep = self::getReputation($pdo, $accountId);
        if ($rep['banned']) {
            return [false, 'Compte temporairement suspendu.'];
        }
        if (!$rep['voting_rights']) {
            return [false, 'Droit de vote retiré (réputation trop basse).'];
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
                return [false, 'Compte trop récent : publie au moins un message ou attends 24 h pour pouvoir voter (anti-Sybil).'];
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

    /** Applique un vote. Retourne ['ok'=>..., 'blocked'=>..., 'new_reputation'=>..., 'message'=>...]. */
    public static function applyVote(PDO $pdo, int $voterId, string $targetType, int $targetId, int $value): array
    {
        if (!in_array($targetType, ['post', 'member'], true) || !in_array($value, [-1, 1], true)) {
            return ['ok' => false, 'message' => 'Paramètres de vote invalides.'];
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
            return ['ok' => false, 'message' => 'Tu ne peux pas voter pour toi-même.'];
        }
        self::ensureRow($pdo, $author);

        // Double vote ?
        $stmt = $pdo->prepare('SELECT id FROM mod_votes WHERE voter_id = ? AND target_type = ? AND target_id = ?');
        $stmt->execute([$voterId, $targetType, $targetId]);
        if ($stmt->fetchColumn()) {
            return ['ok' => false, 'message' => 'Tu as déjà voté ici.'];
        }

        // Anti upvote-farming : >3 upvotes voter→author sur 60j
        if ($value === 1) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM mod_votes WHERE voter_id = ? AND target_author = ? AND value = 1 AND blocked = 0 AND created_at >= ?'
            );
            $stmt->execute([$voterId, $author, time() - self::FARMING_WINDOW_DAYS * 86400]);
            if ((int) $stmt->fetchColumn() >= self::FARMING_MAX_UPVOTES) {
                $pdo->prepare(
                    'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, blocked, blocked_reason, created_at)
                     VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
                )->execute([$voterId, $targetType, $targetId, $author, $value, 'upvote_farming', time()]);
                return ['ok' => true, 'blocked' => true, 'blocked_reason' => 'upvote_farming',
                        'message' => 'Vote enregistré mais neutralisé : trop d\'upvotes répétés vers ce membre (anti-farming).'];
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
                    'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, blocked, blocked_reason, created_at)
                     VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
                )->execute([$voterId, $targetType, $targetId, $author, $value, 'downvote_farming', time()]);
                return ['ok' => true, 'blocked' => true, 'blocked_reason' => 'downvote_farming',
                        'message' => 'Vote enregistré mais neutralisé : trop de downvotes répétés vers ce membre (anti-farming).'];
            }
        }

        // Insert + maj réputation
        $pdo->prepare(
            'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$voterId, $targetType, $targetId, $author, $value, time()]);

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

    /** Détecte les pack-voting (3+ downvotes coordonnés/60s sur même cible) → annule + restaure. */
    public static function detectPackVoting(PDO $pdo): array
    {
        // On examine les downvotes récents puis on cherche un CLUSTER dense (>= PACK_MIN_VOTERS
        // votants distincts dans une fenêtre GLISSANTE de PACK_WINDOW_SECONDS). Raisonner par
        // fenêtre glissante — et non sur l'étalement global — empêche qu'un seul downvote espacé
        // (légitime ou de camouflage) ne masque un pack coordonné, et épargne les votes hors cluster.
        $since = time() - self::PACK_WINDOW_SECONDS * 2;
        $min = (int) self::PACK_MIN_VOTERS;
        $stmt = $pdo->prepare("
            SELECT target_author FROM mod_votes
             WHERE value = -1 AND blocked = 0 AND created_at >= ?
          GROUP BY target_author HAVING COUNT(*) >= $min
        ");
        $stmt->execute([$since]);
        $packs = [];
        $cancelled = 0;

        foreach ($stmt->fetchAll() as $row) {
            $author = (int) $row['target_author'];
            $vs = $pdo->prepare('SELECT id, voter_id, created_at FROM mod_votes
                                  WHERE target_author = ? AND value = -1 AND blocked = 0 AND created_at >= ?
                                  ORDER BY created_at ASC');
            $vs->execute([$author, $since]);
            $votes = $vs->fetchAll();
            $n = count($votes);

            // Plus gros cluster de votes contenu dans une fenêtre de PACK_WINDOW_SECONDS.
            $best = [];
            for ($i = 0; $i < $n; $i++) {
                $cluster = [];
                for ($j = $i; $j < $n; $j++) {
                    if ((int) $votes[$j]['created_at'] - (int) $votes[$i]['created_at'] <= self::PACK_WINDOW_SECONDS) {
                        $cluster[] = $votes[$j];
                    } else {
                        break;
                    }
                }
                if (count($cluster) > count($best)) {
                    $best = $cluster;
                }
            }
            // Pack = au moins PACK_MIN_VOTERS VOTANTS DISTINCTS dans la fenêtre dense.
            $voters = array_values(array_unique(array_map('intval', array_column($best, 'voter_id'))));
            if (count($voters) < self::PACK_MIN_VOTERS) {
                continue;
            }
            $voteIds = array_column($best, 'id');

            // Annule UNIQUEMENT les votes du cluster (les downvotes hors fenêtre sont épargnés).
            $ph = implode(',', array_fill(0, count($voteIds), '?'));
            $pdo->prepare("UPDATE mod_votes SET blocked = 1, blocked_reason = 'pack_voting' WHERE id IN ($ph)")
                ->execute($voteIds);
            $restore = count($voteIds);
            $pdo->prepare('UPDATE member_moderation SET reputation = MIN(reputation + ?, ?), updated_at = ? WHERE account_id = ?')
                ->execute([$restore, self::MAX_REPUTATION, time(), $author]);

            // Restaure les conséquences injustes du pack annulé : droit de vote, et le ban +
            // les strikes que enforceThresholds a pu poser pendant la chute. Le nombre de strikes
            // retirés est borné à la taille du cluster annulé (biais en faveur de la victime).
            $rep = self::getReputation($pdo, $author);
            if ($rep['reputation'] >= self::LOSE_VOTING_AT) {
                $pdo->prepare('UPDATE member_moderation SET voting_rights = 1 WHERE account_id = ?')->execute([$author]);
            }
            if ($rep['reputation'] > self::BAN_AT && $rep['banned_until'] > 0) {
                $pdo->prepare('UPDATE member_moderation SET banned_until = 0, strikes = MAX(0, strikes - ?) WHERE account_id = ?')
                    ->execute([$restore, $author]);
            }
            $cancelled += $restore;
            $packs[] = ['target_author' => $author, 'voters' => $voters, 'cancelled' => $restore];
        }

        return ['pack_detected' => count($packs) > 0, 'cancelled_votes' => $cancelled, 'packs' => $packs];
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
            $pdo->prepare('UPDATE member_moderation SET needs_review = 1 WHERE account_id = ?')
                ->execute([$accountId]);
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

    /** R10-LAB-01 — Membres dont la réputation est tombée à 0 sans pack détecté : à arbitrer par un admin. */
    public static function flaggedForReview(PDO $pdo, int $limit = 50): array
    {
        $stmt = $pdo->prepare(
            'SELECT m.account_id, a.username, m.reputation, m.strikes
               FROM member_moderation m JOIN accounts a ON a.id = m.account_id
              WHERE m.needs_review = 1 ORDER BY m.updated_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
