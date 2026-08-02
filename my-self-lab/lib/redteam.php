<?php
/**
 * MySelf-Lab — soumission de rapports red team + hall of fame.
 *
 * Le corps du rapport (titre, description, étapes de repro, contact) est chiffré
 * at-rest via SelfDataGuard avant stockage : un dump de `redteam_reports` ne
 * révèle qu'un blob. handle/severity/target restent en clair (tri + crédit public).
 * L'IP du soumetteur n'est jamais stockée en clair, seulement un HMAC (rate-limit).
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

require_once __DIR__ . '/dataguard.php';

final class Redteam
{
    public const SEVERITES = ['info', 'faible', 'moyen', 'eleve', 'critique'];
    public const CIBLES    = ['memo', 'auth', 'dm', 'moderation', 'web', 'autre'];

    /** Anti-spam : max soumissions par IP sur une fenêtre. */
    private const RL_MAX    = 5;
    private const RL_WINDOW = 3600; // 1 h

    private static function sevRank(string $s): int
    {
        $i = array_search($s, self::SEVERITES, true);
        return $i === false ? 0 : (int) $i;
    }

    /**
     * Enregistre un rapport (corps chiffré). Retourne ['ok'=>bool, 'message'=>?, 'id'=>?].
     */
    public static function submit(PDO $pdo, array $champs, ?string $ip): array
    {
        // Le contenu arrive DÉJÀ chiffré, vers une clé dont la privée n'est pas
        // sur cette machine. Le serveur ne valide donc plus ni le titre ni la
        // description : il ne les voit pas. C'est le prix de la garantie, et il
        // est assumé — la longueur est bornée côté navigateur.
        $pgp = trim((string) ($champs['pgp'] ?? ''));
        if ($pgp === '') {
            return ['ok' => false, 'message' => tc('Rapport vide ou chiffrement absent.')];
        }
        if (!str_starts_with($pgp, '-----BEGIN PGP MESSAGE-----')) {
            // Un envoi en clair serait une régression silencieuse : on refuse.
            return ['ok' => false, 'message' => tc('Le rapport doit être chiffré. Recharge la page et réessaie.')];
        }
        if (strlen($pgp) > 200000) {
            return ['ok' => false, 'message' => tc('Rapport trop volumineux.')];
        }

        $handle = mb_substr(trim((string) ($champs['handle'] ?? '')), 0, 60);
        $severity = (string) ($champs['severity'] ?? 'info');
        if (!in_array($severity, self::SEVERITES, true)) {
            $severity = 'info';
        }
        $target = (string) ($champs['target'] ?? 'autre');
        if (!in_array($target, self::CIBLES, true)) {
            $target = 'autre';
        }

        $ipHash = DataGuard::hmac($ip ?? 'unknown', 'redteam-ip');
        $since = time() - self::RL_WINDOW;
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM redteam_reports WHERE ip_hash = ? AND created_at >= ?');
        $stmt->execute([$ipHash, $since]);
        if ((int) $stmt->fetchColumn() >= self::RL_MAX) {
            return ['ok' => false, 'message' => 'Trop de soumissions récentes. Réessaie dans une heure.'];
        }

        // Stocké TEL QUEL. Aucun rechiffrement : le passer par DataGuard
        // n'ajouterait rien et donnerait l'illusion que le serveur détient
        // quelque chose de lisible.
        $ciphertext = $pgp;

        $pdo->prepare(
            'INSERT INTO redteam_reports (handle, severity, target, ciphertext, status, ip_hash, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$handle, $severity, $target, $ciphertext, 'nouveau', $ipHash, time()]);

        $id = (int) $pdo->lastInsertId();
        // Alerte le lecteur du panneau. Volontairement après l'écriture et sans
        // condition de succès : un canal muet ne doit pas perdre un rapport.
        Notify::nouveauRapport($id, (string) ($champs['severite'] ?? '?'));

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Hall of fame : chercheurs dont au moins un rapport est validé, avec un
     * pseudo public. Groupé par handle, trié par sévérité max puis nombre.
     */
    public static function hallOfFame(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT handle, severity FROM redteam_reports
             WHERE status = 'valide' AND handle IS NOT NULL AND handle != ''"
        )->fetchAll();

        $byHandle = [];
        foreach ($rows as $r) {
            $h = $r['handle'];
            $byHandle[$h] ??= ['handle' => $h, 'nb' => 0, 'sev' => 'info'];
            $byHandle[$h]['nb']++;
            if (self::sevRank($r['severity']) > self::sevRank($byHandle[$h]['sev'])) {
                $byHandle[$h]['sev'] = $r['severity'];
            }
        }
        $out = array_values($byHandle);
        usort($out, fn($a, $b) => self::sevRank($b['sev']) <=> self::sevRank($a['sev']) ?: $b['nb'] <=> $a['nb']);
        return $out;
    }
}
