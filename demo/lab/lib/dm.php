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
    /** Ce qu'une ligne affiche quand elle ne s'ouvre pas ici. */
    private const ILLISIBLE = '(message illisible ici)';

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

        $ciphertext = DataGuard::encrypt($message, DataGuard::contexteDm($senderId, (int) $recipientId));
        $pdo->prepare(
            'INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)'
        )->execute([$senderId, (int) $recipientId, $ciphertext, time()]);

        return ['ok' => true, 'dm_id' => (int) $pdo->lastInsertId()];
    }

    /**
     * Le clair d'un message, ou un texte de remplacement s'il ne s'ouvre pas ici.
     *
     * Un blob scellé pour une autre conversation échoue au déchiffrement, et
     * c'est le but. Laisser l'exception remonter ferait tomber la boîte entière
     * sur un seul blob déplacé : qui obtient une écriture pourrait alors fermer
     * la messagerie de n'importe qui. La ligne reste donc affichée, illisible,
     * et les autres messages s'affichent.
     *
     * ⚠️ **Le texte ne nomme pas de cause.** Un tag qui ne correspond pas, un blob
     * tronqué et un chiffre indisponible sortent tous par ici ; la panne de secret,
     * elle, est écartée en amont par `DataGuard::exigerCle()`. Accuser le
     * déplacement enverrait chercher un attaquant là où la donnée est seulement
     * abîmée.
     */
    private static function clair(string $ciphertext, string $contexte): string
    {
        try {
            return DataGuard::decrypt($ciphertext, $contexte);
        } catch (\Throwable) {
            return self::ILLISIBLE;
        }
    }

    /**
     * Journalise le seul signe d'un secret remplacé par un autre valide.
     *
     * 🔑 **C'est la troisième panne de clé, et la seule que rien ne refuse.** Un
     * secret absent ou trop court est rejeté par `DataGuard::exigerCle()` ; un
     * secret *remplacé* se dérive sans erreur et rend chaque ligne illisible. Un
     * blob déplacé touche une ligne ou deux, une boîte entière désigne la clé — et
     * l'exploitant n'avait aucune trace pour faire la différence.
     */
    private static function signalerSiToutIllisible(int $accountId, int $total, int $illisibles): void
    {
        if ($total >= 2 && $illisibles === $total) {
            error_log(sprintf(
                'messagerie du compte #%d : %d message(s) sur %d illisibles — un déplacement de blob '
                . 'ne touche pas tout : vérifier le secret .blindkey, ou une base restaurée d\'avant la '
                . 'passe de migration (relancer tools/migrer-aad.php).',
                $accountId,
                $illisibles,
                $total
            ));
        }
    }

    /** Boîte de réception : messages reçus par l'account, déchiffrés. */
    public static function inbox(PDO $pdo, int $accountId): array
    {
        $stmt = $pdo->prepare('
            SELECT d.id, d.sender_id, d.ciphertext, d.created_at, d.lu, a.username AS expediteur
              FROM dm d JOIN accounts a ON a.id = d.sender_id
             WHERE d.recipient_id = ?
             ORDER BY d.created_at DESC
        ');
        $stmt->execute([$accountId]);
        $lignes = $stmt->fetchAll();
        if ($lignes !== []) {
            DataGuard::exigerCle();   // une panne de secret tombe ici, hors du `catch` de `clair()`
        }
        $out = [];
        $illisibles = 0;
        foreach ($lignes as $row) {
            $clair = self::clair($row['ciphertext'], DataGuard::contexteDm((int) $row['sender_id'], $accountId));
            $illisibles += $clair === self::ILLISIBLE ? 1 : 0;
            $out[] = [
                'id'         => (int) $row['id'],
                'expediteur' => $row['expediteur'],
                'message'    => $clair,
                'created_at' => (int) $row['created_at'],
                'lu'         => (bool) $row['lu'],
            ];
        }
        self::signalerSiToutIllisible($accountId, count($out), $illisibles);

        return $out;
    }

    /** Messages envoyés par l'account. */
    public static function sent(PDO $pdo, int $accountId): array
    {
        $stmt = $pdo->prepare('
            SELECT d.id, d.recipient_id, d.ciphertext, d.created_at, a.username AS destinataire
              FROM dm d JOIN accounts a ON a.id = d.recipient_id
             WHERE d.sender_id = ?
             ORDER BY d.created_at DESC
        ');
        $stmt->execute([$accountId]);
        $lignes = $stmt->fetchAll();
        if ($lignes !== []) {
            DataGuard::exigerCle();
        }
        $out = [];
        foreach ($lignes as $row) {
            $out[] = [
                'id'           => (int) $row['id'],
                'destinataire' => $row['destinataire'],
                'message'      => self::clair($row['ciphertext'], DataGuard::contexteDm($accountId, (int) $row['recipient_id'])),
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
