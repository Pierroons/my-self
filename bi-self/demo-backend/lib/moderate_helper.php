<?php
/**
 * SelfModerate demo — helpers (reputation math, pack-voting detection).
 */

declare(strict_types=1);

require_once __DIR__ . '/session_manager.php';

final class ModerateHelper {
    public const INITIAL_REPUTATION = 20;
    public const MAX_REPUTATION     = 30;
    public const LOSE_VOTING_AT     = 5;
    public const BAN_AT             = 0;

    public const PACK_WINDOW_SECONDS = 60;
    public const PACK_MIN_VOTERS     = 3;

    /**
     * Applique un vote et met à jour la réputation de la cible.
     *
     * @return array{ok: bool, error?: string, blocked?: bool, blocked_reason?: string, new_reputation?: int}
     */
    public static function applyVote(DemoSession $s, int $invitationId, int $voterId, int $targetId, int $value, string $reason): array {
        $db = $s->db();
        $log = $s->logger();

        // 1. Vérifier que l'invitation existe et lie voter & target
        $stmt = $db->prepare('SELECT from_user, to_user FROM invitations WHERE id = :id');
        $stmt->bindValue(':id', $invitationId);
        $inv = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!is_array($inv)) return ['ok' => false, 'error' => 'invitation_not_found'];

        $involved = [$inv['from_user'], $inv['to_user']];
        if (!in_array($voterId, $involved) || !in_array($targetId, $involved) || $voterId === $targetId) {
            return ['ok' => false, 'error' => 'vote_not_linked_to_invitation'];
        }

        // 2. Pas de double vote pour la même invitation
        $stmt = $db->prepare('SELECT id FROM votes WHERE invitation_id = :inv AND voter_id = :v');
        $stmt->bindValue(':inv', $invitationId);
        $stmt->bindValue(':v', $voterId);
        if ($stmt->execute()->fetchArray()) {
            return ['ok' => false, 'error' => 'already_voted'];
        }

        // 3. Détection upvote farming : si voter→target déjà upvoté 3 fois ces 2 derniers mois, bloque
        if ($value === 1) {
            $stmt = $db->prepare('SELECT COUNT(*) as c FROM votes WHERE voter_id = :v AND target_id = :t AND value = 1 AND created_at >= :since');
            $stmt->bindValue(':v', $voterId);
            $stmt->bindValue(':t', $targetId);
            $stmt->bindValue(':since', time() - (60 * 86400));
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if (is_array($row) && (int) $row['c'] >= 3) {
                // Vote bloqué
                $stmt = $db->prepare('INSERT INTO votes (invitation_id, voter_id, target_id, value, reason, blocked, blocked_reason, created_at) VALUES (:i, :v, :t, :val, :r, 1, :br, :at)');
                $stmt->bindValue(':i', $invitationId);
                $stmt->bindValue(':v', $voterId);
                $stmt->bindValue(':t', $targetId);
                $stmt->bindValue(':val', $value);
                $stmt->bindValue(':r', $reason);
                $stmt->bindValue(':br', 'upvote_farming');
                $stmt->bindValue(':at', time());
                $stmt->execute();
                $log->warning('vote', 'Vote positif bloqué : upvote farming détecté (>3 upvotes mutuels ces 60 derniers jours)', ['voter_id' => $voterId, 'target_id' => $targetId]);
                return ['ok' => true, 'blocked' => true, 'blocked_reason' => 'upvote_farming'];
            }
        }

        // 4. Insertion du vote
        $stmt = $db->prepare('INSERT INTO votes (invitation_id, voter_id, target_id, value, reason, created_at) VALUES (:i, :v, :t, :val, :r, :at)');
        $stmt->bindValue(':i', $invitationId);
        $stmt->bindValue(':v', $voterId);
        $stmt->bindValue(':t', $targetId);
        $stmt->bindValue(':val', $value);
        $stmt->bindValue(':r', $reason);
        $stmt->bindValue(':at', time());
        $stmt->execute();

        // 5. Mise à jour de la réputation cible
        $stmt = $db->prepare('SELECT reputation FROM users WHERE id = :id');
        $stmt->bindValue(':id', $targetId);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $oldRep = (int) ($row['reputation'] ?? self::INITIAL_REPUTATION);
        $newRep = max(0, min(self::MAX_REPUTATION, $oldRep + $value));

        $stmt = $db->prepare('UPDATE users SET reputation = :r WHERE id = :id');
        $stmt->bindValue(':r', $newRep);
        $stmt->bindValue(':id', $targetId);
        $stmt->execute();

        $log->info('vote', sprintf('Réputation: %d → %d', $oldRep, $newRep), [
            'target_id' => $targetId, 'delta' => $value, 'reason' => $reason,
        ]);

        // 6. Vérifier les seuils (perte vote / ban)
        self::enforceThresholds($s, $targetId, $newRep);

        return ['ok' => true, 'blocked' => false, 'new_reputation' => $newRep];
    }

    /**
     * Détecte et annule les pack-voting patterns sur la fenêtre récente.
     * Appelé manuellement par l'endpoint detect-abuse pour la démo.
     *
     * @return array{pack_detected: bool, cancelled_votes: int, pack: array}
     */
    public static function detectPackVoting(DemoSession $s): array {
        $db = $s->db();
        $log = $s->logger();

        // Cible potentielles : users qui ont reçu >=3 votes -1 dans les dernières N secondes,
        // non encore marqués blocked.
        $stmt = $db->prepare('
            SELECT target_id, COUNT(*) as c, MIN(created_at) as min_t, MAX(created_at) as max_t
              FROM votes
             WHERE value = -1 AND blocked = 0 AND created_at >= :since
          GROUP BY target_id
            HAVING c >= :min
        ');
        $stmt->bindValue(':since', time() - self::PACK_WINDOW_SECONDS * 2);
        $stmt->bindValue(':min', self::PACK_MIN_VOTERS);
        $suspicious = $stmt->execute();

        $cancelled = 0;
        $packs = [];
        while ($row = $suspicious->fetchArray(SQLITE3_ASSOC)) {
            $targetId = (int) $row['target_id'];
            $spread = (int) $row['max_t'] - (int) $row['min_t'];
            if ($spread > self::PACK_WINDOW_SECONDS) continue; // trop étalé, ignore

            // Qui sont les voters ?
            $stmt2 = $db->prepare('
                SELECT v.id as vote_id, v.voter_id, u.username
                  FROM votes v JOIN users u ON u.id = v.voter_id
                 WHERE v.target_id = :t AND v.value = -1 AND v.blocked = 0
                   AND v.created_at BETWEEN :a AND :b
            ');
            $stmt2->bindValue(':t', $targetId);
            $stmt2->bindValue(':a', $row['min_t']);
            $stmt2->bindValue(':b', $row['max_t']);
            $res = $stmt2->execute();
            $voters = [];
            $voteIds = [];
            while ($v = $res->fetchArray(SQLITE3_ASSOC)) {
                $voters[] = $v['username'];
                $voteIds[] = (int) $v['vote_id'];
            }

            // Vérifier qu'ils se connaissent (cross-invitations)
            if (self::votersAreConnected($db, array_column($res2 = [], 'voter_id'))) {
                // rien
            }

            // Pack détecté ! Annule les votes ET restore la réputation
            $stmt3 = $db->prepare('UPDATE votes SET blocked = 1, blocked_reason = "pack_voting" WHERE id IN (' . implode(',', array_fill(0, count($voteIds), '?')) . ')');
            foreach ($voteIds as $i => $vid) $stmt3->bindValue($i + 1, $vid);
            $stmt3->execute();

            // Annule l'impact sur la réputation : +N points (autant de -1 annulés)
            $restore = count($voteIds);
            $stmt4 = $db->prepare('UPDATE users SET reputation = MIN(reputation + :n, :max) WHERE id = :id');
            $stmt4->bindValue(':n', $restore);
            $stmt4->bindValue(':max', self::MAX_REPUTATION);
            $stmt4->bindValue(':id', $targetId);
            $stmt4->execute();

            $cancelled += $restore;
            $packs[] = ['target_id' => $targetId, 'voters' => $voters, 'spread_s' => $spread, 'cancelled' => $restore];

            $log->warning('pack-voting', 'Pack-voting détecté et annulé', [
                'target_id' => $targetId,
                'voters'    => $voters,
                'spread_s'  => $spread,
                'cancelled' => $restore,
            ]);
        }

        return [
            'pack_detected'   => count($packs) > 0,
            'cancelled_votes' => $cancelled,
            'packs'           => $packs,
        ];
    }

    private static function votersAreConnected(SQLite3 $db, array $voterIds): bool {
        if (count($voterIds) < 2) return false;
        $placeholders = implode(',', array_fill(0, count($voterIds), '?'));
        $sql = "SELECT COUNT(*) as c FROM invitations WHERE from_user IN ($placeholders) AND to_user IN ($placeholders)";
        $stmt = $db->prepare($sql);
        $i = 1;
        foreach ($voterIds as $id) $stmt->bindValue($i++, $id);
        foreach ($voterIds as $id) $stmt->bindValue($i++, $id);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return is_array($row) && (int) $row['c'] >= 2;
    }

    private static function enforceThresholds(DemoSession $s, int $userId, int $reputation): void {
        $db = $s->db();
        $log = $s->logger();

        if ($reputation < self::LOSE_VOTING_AT) {
            // Perte droit de vote
            $stmt = $db->prepare('UPDATE users SET voting_rights = 0 WHERE id = :id');
            $stmt->bindValue(':id', $userId);
            $stmt->execute();
            if ($db->changes() > 0) {
                $log->warning('sanctions', 'Perte du droit de vote (réputation < ' . self::LOSE_VOTING_AT . ')', ['user_id' => $userId]);
            }
        }

        if ($reputation <= self::BAN_AT) {
            // Ban progressif : compter les strikes
            $stmt = $db->prepare('SELECT strikes FROM users WHERE id = :id');
            $stmt->bindValue(':id', $userId);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            $strikes = (int) ($row['strikes'] ?? 0);

            $durations = [86400, 7 * 86400, 30 * 86400]; // 24h, 7j, 30j
            if ($strikes >= 3) {
                $log->error('sanctions', 'Ban PERMANENT — 3 strikes cumulés', ['user_id' => $userId]);
                $until = PHP_INT_MAX;
            } else {
                $until = time() + $durations[$strikes];
                $log->warning('sanctions', sprintf('Ban temporaire strike #%d — durée %s', $strikes + 1, $durations[$strikes] >= 86400 ? ($durations[$strikes] / 86400) . 'j' : '24h'), ['user_id' => $userId]);
            }

            $stmt = $db->prepare('UPDATE users SET banned_until = :u, strikes = strikes + 1 WHERE id = :id');
            $stmt->bindValue(':u', $until);
            $stmt->bindValue(':id', $userId);
            $stmt->execute();
        }
    }
}
