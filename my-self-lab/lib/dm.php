<?php
/**
 * MySelf-Lab — messages privés chiffrés (DM).
 * Le contenu est chiffré via DataGuard avant insertion en base.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

require_once __DIR__ . '/dataguard.php';

final class DM
{
    public static function send(PDO $pdo, int $senderId, string $recipientUsername, string $message): array
    {
        $message = trim($message);
        if (mb_strlen($message) < 1) {
            return ['ok' => false, 'error' => 'message_vide', 'message' => 'Le message ne peut pas être vide.'];
        }
        if (mb_strlen($message) > 5000) {
            return ['ok' => false, 'error' => 'trop_long', 'message' => 'Message limité à 5000 caractères.'];
        }
        $recipientUsername = strtolower(trim($recipientUsername));
        $stmt = $pdo->prepare('SELECT id FROM accounts WHERE username = ?');
        $stmt->execute([$recipientUsername]);
        $recipientId = $stmt->fetchColumn();
        if ($recipientId === false) {
            return ['ok' => false, 'error' => 'destinataire_inconnu', 'message' => 'Destinataire introuvable.'];
        }
        if ((int) $recipientId === $senderId) {
            return ['ok' => false, 'error' => 'self', 'message' => 'Tu ne peux pas t\'écrire à toi-même.'];
        }

        $ciphertext = DataGuard::encrypt($message);
        $pdo->prepare(
            'INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)'
        )->execute([$senderId, (int) $recipientId, $ciphertext, time()]);

        return ['ok' => true, 'dm_id' => (int) $pdo->lastInsertId()];
    }

    /** Boîte de réception : messages reçus par l'account, déchiffrés. */
    public static function inbox(PDO $pdo, int $accountId): array
    {
        $stmt = $pdo->prepare('
            SELECT d.id, d.ciphertext, d.created_at, d.lu, a.username AS expediteur
              FROM dm d JOIN accounts a ON a.id = d.sender_id
             WHERE d.recipient_id = ?
             ORDER BY d.created_at DESC
        ');
        $stmt->execute([$accountId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id'         => (int) $row['id'],
                'expediteur' => $row['expediteur'],
                'message'    => DataGuard::decrypt($row['ciphertext']), // déchiffré à la lecture
                'created_at' => (int) $row['created_at'],
                'lu'         => (bool) $row['lu'],
            ];
        }
        return $out;
    }

    /** Messages envoyés par l'account. */
    public static function sent(PDO $pdo, int $accountId): array
    {
        $stmt = $pdo->prepare('
            SELECT d.id, d.ciphertext, d.created_at, a.username AS destinataire
              FROM dm d JOIN accounts a ON a.id = d.recipient_id
             WHERE d.sender_id = ?
             ORDER BY d.created_at DESC
        ');
        $stmt->execute([$accountId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id'           => (int) $row['id'],
                'destinataire' => $row['destinataire'],
                'message'      => DataGuard::decrypt($row['ciphertext']),
                'created_at'   => (int) $row['created_at'],
            ];
        }
        return $out;
    }

    public static function markRead(PDO $pdo, int $accountId): void
    {
        $pdo->prepare('UPDATE dm SET lu = 1 WHERE recipient_id = ? AND lu = 0')->execute([$accountId]);
    }
}
