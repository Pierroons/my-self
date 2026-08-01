<?php
/**
 * MySelf-Lab — niveau 3 de SelfRecover : l'escalade humaine.
 *
 * 🔑 **Aucune automatisation ne rend un compte à ce niveau.** C'est le point
 * central : un mécanisme automatique de dernier recours serait précisément la
 * faille par laquelle on prendrait les comptes. Un humain lit, recoupe, décide.
 * La lenteur n'est pas un défaut d'implémentation, c'est la propriété.
 *
 * Le corps du litige est chiffré at-rest, comme les rapports red team. Il
 * contient ce que la personne sait de son compte — dates, sujets ouverts,
 * correspondants — soit exactement ce qui aiderait un usurpateur.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

// DataGuard n est pas charge par le bootstrap : chaque module qui chiffre
// l inclut lui-meme, comme le fait redteam.php.
require_once __DIR__ . '/dataguard.php';

final class Dispute
{
    /** Fenêtre de limitation, en secondes. */
    private const WINDOW = 86400;

    /** Litiges ouverts tolérés par compte et par jour. */
    private const MAX_PER_USER = 3;

    /**
     * Dépose un litige.
     *
     * Volontairement acceptée même pour un compte inexistant : refuser
     * révélerait quels comptes existent, ce que tout le reste du protocole
     * s'applique à masquer. Le tri se fait à la lecture, par un humain.
     */
    public static function open(PDO $pdo, string $username, array $faits, ?string $ip): array
    {
        $username = strtolower(trim($username));
        if ($username === '' || mb_strlen($username) > 40) {
            return ['ok' => false, 'message' => 'Identifiant manquant ou trop long.'];
        }

        $recit = trim((string) ($faits['recit'] ?? ''));
        if (mb_strlen($recit) < 40) {
            return ['ok' => false, 'message' => 'Décris ce que tu sais de ton compte — au moins quelques phrases.'];
        }
        if (mb_strlen($recit) > 4000) {
            return ['ok' => false, 'message' => 'Récit trop long (4000 caractères maximum).'];
        }

        // Limite par compte revendiqué : empêche d'inonder un administrateur de
        // demandes pour un même compte, sans jamais bloquer un titulaire
        // légitime qui n'en dépose qu'un.
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM disputes WHERE username = ? AND created_at > ?'
        );
        $stmt->execute([$username, time() - self::WINDOW]);
        if ((int) $stmt->fetchColumn() >= self::MAX_PER_USER) {
            return ['ok' => false, 'message' => 'Trop de litiges ouverts pour ce compte. Réessaie demain.'];
        }

        $corps = [
            'recit'   => $recit,
            'contact' => mb_substr(trim((string) ($faits['contact'] ?? '')), 0, 200),
        ];

        $pdo->prepare(
            'INSERT INTO disputes (username, ciphertext, ip_hash, created_at) VALUES (?, ?, ?, ?)'
        )->execute([
            $username,
            DataGuard::encrypt(json_encode($corps, JSON_UNESCAPED_UNICODE)),
            $ip !== null ? hash_hmac('sha256', $ip, Auth::siteSalt()) : null,
            time(),
        ]);

        return [
            'ok' => true,
            'message' => 'Litige enregistré. Un administrateur l\'examinera — compte plusieurs jours, '
                       . 'et aucune réponse automatique ne viendra : c\'est le principe.',
        ];
    }

    /** Litiges en attente, pour le panneau d'administration. */
    public static function pending(PDO $pdo, int $limit = 50): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, username, status, created_at, decided_at, admin_note
               FROM disputes ORDER BY (status = \'ouvert\') DESC, created_at DESC LIMIT ?'
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /** Déchiffre un litige — réservé à l'administration. */
    public static function reveal(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT ciphertext FROM disputes WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $clair = json_decode(DataGuard::decrypt($row['ciphertext']), true);
        return is_array($clair) ? $clair : null;
    }

    /**
     * Tranche un litige.
     *
     * ⚠️ Accepter ne rend PAS l'accès automatiquement : la décision est
     * enregistrée, la remise en main se fait hors ligne. Brancher une
     * régénération de mot de passe sur ce bouton recréerait le chemin
     * automatique que ce niveau existe pour éviter.
     */
    public static function decide(PDO $pdo, int $id, bool $accepte, string $note): bool
    {
        $stmt = $pdo->prepare(
            'UPDATE disputes SET status = ?, admin_note = ?, decided_at = ? WHERE id = ? AND status = \'ouvert\''
        );
        $stmt->execute([$accepte ? 'accepte' : 'refuse', mb_substr($note, 0, 500), time(), $id]);
        return $stmt->rowCount() > 0;
    }
}
