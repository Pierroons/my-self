<?php
/**
 * MySelf-Lab — soumission de rapports red team + hall of fame.
 *
 * Le corps du rapport (titre, description, étapes de repro, contact) est chiffré
 * en PGP dans le navigateur avant envoi : un dump de `redteam_reports` ne
 * révèle qu'un message PGP. handle/severity/target restent en clair (tri + crédit public).
 * L'IP du soumetteur n'est jamais stockée en clair, seulement un HMAC (rate-limit).
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

require_once __DIR__ . '/dataguard.php';

final class Redteam
{
    public const SEVERITES = ['info', 'faible', 'moyen', 'eleve', 'critique'];

    /**
     * Ce que vaut un rapport valide au classement.
     *
     * 🔑 L'ecart est volontairement large : un critique vaut cent infos. Un
     * bareme plat recompenserait le volume, et trente rapports creux
     * passeraient devant une seule vraie trouvaille — exactement ce qu'un
     * programme de divulgation ne doit pas encourager.
     */
    public const POINTS = ['info' => 1, 'faible' => 5, 'moyen' => 20, 'eleve' => 50, 'critique' => 100];
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
        Notify::nouveauRapport($id, $severity);

        return ['ok' => true, 'id' => $id, 'suivi' => self::jetonSuivi($id)];
    }

    /**
     * Le jeton qui laisse un chercheur consulter l'etat de SON rapport.
     *
     * Il se derive du numero, il ne se stocke pas : une colonne de plus serait
     * une colonne a voler. Et il est indispensable — sans lui, /api/report_status
     * laisserait enumerer les rapports des autres en comptant de 1 a n.
     */
    public static function jetonSuivi(int $id): string
    {
        return substr(DataGuard::hmac((string) $id, 'report-track'), 0, 32);
    }

    /**
     * L'etat d'un rapport, pour celui qui tient son jeton.
     *
     * Ne rend jamais le rapport lui-meme : il est chiffre pour une seule cle PGP,
     * et le serveur ne saurait pas le lire meme s'il le voulait.
     *
     * @return array{ok: bool, message?: string, statut?: string, severite?: string, depose_le?: int}
     */
    public static function etat(PDO $pdo, int $id, string $jeton): array
    {
        if ($id <= 0 || !hash_equals(self::jetonSuivi($id), trim($jeton))) {
            // Un numero inconnu et un jeton faux rendent le meme refus : sinon
            // la difference dirait combien de rapports ont ete deposes.
            return ['ok' => false, 'message' => tc('Numero ou jeton de suivi invalide.')];
        }

        $stmt = $pdo->prepare('SELECT severity, status, created_at FROM redteam_reports WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) {
            return ['ok' => false, 'message' => tc('Numero ou jeton de suivi invalide.')];
        }

        return [
            'ok'        => true,
            'statut'    => (string) $r['status'],
            'severite'  => (string) $r['severity'],
            'depose_le' => (int) $r['created_at'],
        ];
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
            $byHandle[$h] ??= ['handle' => $h, 'nb' => 0, 'sev' => 'info', 'points' => 0];
            $byHandle[$h]['nb']++;
            $byHandle[$h]['points'] += self::POINTS[$r['severity']] ?? 0;
            if (self::sevRank($r['severity']) > self::sevRank($byHandle[$h]['sev'])) {
                $byHandle[$h]['sev'] = $r['severity'];
            }
        }
        // Les drapeaux comptent au meme tableau : quelqu'un qui en sort un sans
        // deposer de rapport merite d'y figurer. Son pseudo arrive tel quel.
        require_once __DIR__ . '/flags.php';
        foreach (Flags::pointsParPseudo($pdo) as $h => $pts) {
            $byHandle[$h] ??= ['handle' => $h, 'nb' => 0, 'sev' => 'info', 'points' => 0];
            $byHandle[$h]['points'] += $pts;
        }

        $out = array_values($byHandle);
        usort($out, fn($a, $b) => $b['points'] <=> $a['points']
            ?: self::sevRank($b['sev']) <=> self::sevRank($a['sev'])
            ?: $b['nb'] <=> $a['nb']);
        return $out;
    }
}
