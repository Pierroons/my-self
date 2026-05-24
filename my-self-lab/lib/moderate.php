<?php
/**
 * MySelf-Lab — SelfModerate : réputation distribuée + anti-manipulation.
 *
 * Adapté de bi-self/demo-backend/lib/moderate_helper.php (SQLite3 → PDO).
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

    // Anti-Sybil : un compte doit avoir cette ancienneté OU >=1 post pour voter
    public const MIN_AGE_TO_VOTE_SECONDS = 86400; // 24h

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

        // Insert + maj réputation
        $pdo->prepare(
            'INSERT INTO mod_votes (voter_id, target_type, target_id, target_author, value, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$voterId, $targetType, $targetId, $author, $value, time()]);

        $rep = self::getReputation($pdo, $author);
        $newRep = max(self::BAN_AT, min(self::MAX_REPUTATION, $rep['reputation'] + $value));
        $pdo->prepare('UPDATE member_moderation SET reputation = ?, updated_at = ? WHERE account_id = ?')
            ->execute([$newRep, time(), $author]);

        self::enforceThresholds($pdo, $author, $newRep);

        return ['ok' => true, 'blocked' => false, 'new_reputation' => $newRep,
                'message' => 'Vote pris en compte.'];
    }

    /** Détecte les pack-voting (3+ downvotes coordonnés/60s sur même cible) → annule + restaure. */
    public static function detectPackVoting(PDO $pdo): array
    {
        $since = time() - self::PACK_WINDOW_SECONDS * 2;
        // NB : la constante (int interne) est interpolée car un placeholder bindé
        // en TEXT comparé à un COUNT() (numeric) est toujours faux en SQLite.
        $min = (int) self::PACK_MIN_VOTERS;
        $stmt = $pdo->prepare("
            SELECT target_author, COUNT(*) AS c, MIN(created_at) AS min_t, MAX(created_at) AS max_t
              FROM mod_votes
             WHERE value = -1 AND blocked = 0 AND created_at >= ?
          GROUP BY target_author
            HAVING COUNT(*) >= $min
        ");
        $stmt->execute([$since]);
        $packs = [];
        $cancelled = 0;

        foreach ($stmt->fetchAll() as $row) {
            $spread = (int) $row['max_t'] - (int) $row['min_t'];
            if ($spread > self::PACK_WINDOW_SECONDS) {
                continue; // trop étalé, pas coordonné
            }
            $author = (int) $row['target_author'];
            // Récupère les votes du pack
            $vs = $pdo->prepare('
                SELECT v.id, a.username FROM mod_votes v JOIN accounts a ON a.id = v.voter_id
                 WHERE v.target_author = ? AND v.value = -1 AND v.blocked = 0
                   AND v.created_at BETWEEN ? AND ?
            ');
            $vs->execute([$author, (int) $row['min_t'], (int) $row['max_t']]);
            $rows = $vs->fetchAll();
            $voteIds = array_column($rows, 'id');
            $voters = array_column($rows, 'username');
            if (count($voteIds) < self::PACK_MIN_VOTERS) {
                continue;
            }
            // Annule + restaure
            $ph = implode(',', array_fill(0, count($voteIds), '?'));
            $pdo->prepare("UPDATE mod_votes SET blocked = 1, blocked_reason = 'pack_voting' WHERE id IN ($ph)")
                ->execute($voteIds);
            $restore = count($voteIds);
            $pdo->prepare('UPDATE member_moderation SET reputation = MIN(reputation + ?, ?), updated_at = ? WHERE account_id = ?')
                ->execute([$restore, self::MAX_REPUTATION, time(), $author]);
            // Restaure éventuellement le droit de vote si remonté au-dessus du seuil
            $rep = self::getReputation($pdo, $author);
            if ($rep['reputation'] >= self::LOSE_VOTING_AT) {
                $pdo->prepare('UPDATE member_moderation SET voting_rights = 1 WHERE account_id = ?')->execute([$author]);
            }
            $cancelled += $restore;
            $packs[] = ['target_author' => $author, 'voters' => $voters, 'spread_s' => $spread, 'cancelled' => $restore];
        }

        return ['pack_detected' => count($packs) > 0, 'cancelled_votes' => $cancelled, 'packs' => $packs];
    }

    private static function enforceThresholds(PDO $pdo, int $accountId, int $reputation): void
    {
        if ($reputation < self::LOSE_VOTING_AT) {
            $pdo->prepare('UPDATE member_moderation SET voting_rights = 0 WHERE account_id = ?')->execute([$accountId]);
        }
        if ($reputation <= self::BAN_AT) {
            $stmt = $pdo->prepare('SELECT strikes FROM member_moderation WHERE account_id = ?');
            $stmt->execute([$accountId]);
            $strikes = (int) $stmt->fetchColumn();
            $durations = [86400, 7 * 86400, 30 * 86400];
            $until = $strikes >= 3 ? PHP_INT_MAX : time() + $durations[$strikes];
            $pdo->prepare('UPDATE member_moderation SET banned_until = ?, strikes = strikes + 1 WHERE account_id = ?')
                ->execute([$until, $accountId]);
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
}
