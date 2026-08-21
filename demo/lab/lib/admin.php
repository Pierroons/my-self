<?php
/**
 * MySelf-Lab — couche admin (panel blue team).
 *
 * Fournit le monitoring du site (activité, signaux d'attaque) et la gestion des
 * rapports red team + accès aux données chiffrées. L'admin PEUT déchiffrer car
 * en V1 le chiffrement est at-rest serveur (blind key) : l'admin légitime détient
 * la clé. Le vault per-user (V2) retirera cette capacité même à l'admin.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

require_once __DIR__ . '/dataguard.php';
require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/memo_vault.php';

final class Admin
{
    /** Compteurs globaux + signaux d'attaque. */
    public static function stats(PDO $pdo): array
    {
        $q = fn(string $sql): int => (int) $pdo->query($sql)->fetchColumn();
        $h24 = time() - 86400;
        return [
            'comptes'            => $q('SELECT COUNT(*) FROM accounts'),
            'admins'             => $q('SELECT COUNT(*) FROM accounts WHERE is_admin = 1'),
            'threads'            => $q('SELECT COUNT(*) FROM threads'),
            'posts'              => $q('SELECT COUNT(*) FROM posts'),
            'dm'                 => $q('SELECT COUNT(*) FROM dm'),
            'votes'              => $q('SELECT COUNT(*) FROM mod_votes'),
            'votes_bloques'      => $q('SELECT COUNT(*) FROM mod_votes WHERE blocked = 1'),
            'rapports'           => $q('SELECT COUNT(*) FROM redteam_reports'),
            'rapports_nouveaux'  => $q("SELECT COUNT(*) FROM redteam_reports WHERE status = 'nouveau'"),
            'comptes_24h'        => $q('SELECT COUNT(*) FROM accounts WHERE created_at > ' . $h24),
            'echecs_login_24h'   => $q("SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND username != '__register__' AND attempted_at > " . $h24),
        ];
    }

    /** Comptes récents + réputation (LEFT JOIN modération). */
    public static function accounts(PDO $pdo, int $limit = 50): array
    {
        $stmt = $pdo->prepare(
            'SELECT a.id, a.username, a.is_admin, a.created_at,
                    COALESCE(m.reputation, 20) AS reputation,
                    COALESCE(m.banned_until, 0) AS banned_until
               FROM accounts a
               LEFT JOIN member_moderation m ON m.account_id = a.id
              ORDER BY a.created_at DESC LIMIT ?'
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /** Derniers échecs de login (signal bruteforce). IP en clair = log admin. */
    public static function failedLogins(PDO $pdo, int $limit = 20): array
    {
        $stmt = $pdo->prepare(
            "SELECT username, ip, attempted_at FROM login_attempts
              WHERE success = 0 AND username != '__register__'
              ORDER BY attempted_at DESC LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /** Votes neutralisés par SelfModerate (signal Sybil / pack-voting). */
    public static function blockedVotes(PDO $pdo, int $limit = 20): array
    {
        $stmt = $pdo->prepare(
            'SELECT v.target_type, v.target_id, v.value, v.blocked_reason, v.created_at,
                    a.username AS voter
               FROM mod_votes v JOIN accounts a ON a.id = v.voter_id
              WHERE v.blocked = 1 ORDER BY v.created_at DESC LIMIT ?'
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Épisodes de meute, du plus récent au plus ancien. Une ligne par votant et
     * par cible : un même épisode en laisse plusieurs quand le groupe a frappé
     * plusieurs personnes le même jour, et c'est voulu — le rang ne dit pas
     * l'ampleur, seulement le nombre de fois où l'on a recommencé.
     */
    public static function packEpisodes(PDO $pdo, int $limit = 20): array
    {
        $stmt = $pdo->prepare(
            'SELECT f.rang, f.action, f.detected_at,
                    v.username AS votant, c.username AS cible
               FROM mod_pack_flags f
               JOIN accounts v ON v.id = f.voter_id
               JOIN accounts c ON c.id = f.target_author
              ORDER BY f.detected_at DESC, f.id DESC LIMIT ?'
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /** Profil déchiffré d'un compte (inclut le mémo perso — défi CTF). */
    public static function profile(PDO $pdo, int $accountId): ?array
    {
        $stmt = $pdo->prepare('SELECT username FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $u = $stmt->fetchColumn();
        if ($u === false) {
            return null;
        }
        return [
            'username' => $u,
            'profil'   => Profile::get($pdo, $accountId),
            // Le mémo est chiffré E2E côté client : l'admin n'a PAS la clé.
            'memo_e2e' => MemoVault::exists($pdo, $accountId),
        ];
    }

    /** Métadonnées des rapports red team (sans déchiffrer le corps). */
    public static function reports(PDO $pdo): array
    {
        return $pdo->query(
            'SELECT id, handle, severity, target, status, created_at
               FROM redteam_reports ORDER BY created_at DESC'
        )->fetchAll();
    }

    /** Corps déchiffré d'un rapport. */
    /**
     * Rend un rapport pour lecture — **sans le déchiffrer**.
     *
     * 🔑 Le contenu est chiffré vers la clé du programme, dont la privée n'est
     * pas sur ce serveur. Ce n'est pas une limite technique à contourner, c'est
     * la garantie : un attaquant qui prendrait cette machine ne lirait aucun
     * rapport, pas même celui d'un autre participant.
     *
     * Le panneau affiche donc le bloc PGP à copier ; le déchiffrement se fait
     * hors ligne, avec `gpg -d`. Ajouter une clé privée ici pour « rendre le
     * panneau pratique » annulerait tout l'intérêt.
     */
    public static function readReport(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT handle, severity, target, status, ciphertext, created_at FROM redteam_reports WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) {
            return null;
        }
        $r['pgp'] = (string) $r['ciphertext'];
        $r['chiffre'] = str_starts_with($r['pgp'], '-----BEGIN PGP MESSAGE-----');
        unset($r['ciphertext']);
        return $r;
    }

    /** Change le statut d'un rapport (nouveau|valide|rejete). */
    public static function setReportStatus(PDO $pdo, int $id, string $status): bool
    {
        if (!in_array($status, ['nouveau', 'valide', 'rejete'], true)) {
            return false;
        }
        $stmt = $pdo->prepare('UPDATE redteam_reports SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        return $stmt->rowCount() > 0;
    }

    /** Demandes de promotion en attente de décision du super-utilisateur. */
    public static function pendingRequests(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT id, requester_username, target_username, reason, created_at
               FROM admin_requests WHERE status = 'pending' ORDER BY id DESC"
        )->fetchAll();
    }

    /**
     * Dépose une demande de promotion. Un administrateur propose, le
     * super-utilisateur tranche — l'un ne peut pas faire le travail de l'autre.
     *
     * Cette méthode n'accorde aucun droit et n'écrit rien au journal SU : elle
     * range une demande. Le journal enregistre les franchissements de frontière,
     * c'est-à-dire la décision, avec le demandeur et son motif recopiés dedans.
     *
     * @return array{ok: bool, error?: string, message?: string, id?: int}
     */
    public static function requestPromotion(PDO $pdo, string $requester, string $target, string $reason): array
    {
        $target = strtolower(trim($target));
        $reason = trim($reason);

        if ($target === '') {
            return ['ok' => false, 'error' => 'target_missing', 'message' => 'Indique le compte à promouvoir.'];
        }
        // Aujourd'hui redondante — le dépôt exige déjà `require_admin`, donc un
        // demandeur est toujours administrateur et bute sur `already_admin`.
        // Elle est là pour le refus explicite, et parce qu'elle devient la seule
        // barrière le jour où le dépôt s'ouvre aux comptes ordinaires : la
        // séparation des pouvoirs reposerait alors sur elle seule.
        if ($target === strtolower($requester)) {
            return ['ok' => false, 'error' => 'self_promotion',
                    'message' => 'On ne propose pas sa propre promotion.'];
        }
        if (mb_strlen($reason) < 10) {
            return ['ok' => false, 'error' => 'reason_too_short',
                    'message' => 'Explique en une phrase pourquoi ce compte doit être promu : le super-utilisateur décide sur ce motif.'];
        }

        $stmt = $pdo->prepare('SELECT id, is_admin FROM accounts WHERE username = ?');
        $stmt->execute([$target]);
        $acc = $stmt->fetch();
        if (!$acc) {
            return ['ok' => false, 'error' => 'unknown_account', 'message' => "Compte « $target » introuvable."];
        }
        if ((int) $acc['is_admin'] === 1) {
            return ['ok' => false, 'error' => 'already_admin', 'message' => "« $target » est déjà administrateur."];
        }

        $stmt = $pdo->prepare("SELECT id FROM admin_requests WHERE target_username = ? AND status = 'pending'");
        $stmt->execute([$target]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'error' => 'already_pending',
                    'message' => "Une demande est déjà en attente pour « $target »."];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO admin_requests (requester_username, target_username, reason, created_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([strtolower($requester), $target, $reason, time()]);

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId(),
                'message' => "Demande déposée pour « $target ». Le super-utilisateur tranchera."];
    }
}
