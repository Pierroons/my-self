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
}
