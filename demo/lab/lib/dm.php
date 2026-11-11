<?php
/**
 * MySelf-Lab — messages privés chiffrés (DM).
 * Le contenu est chiffré via DataGuard avant insertion en base.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;
use Pierroons\SelfDataGuard\Crypto\Aad;

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
        $stmt->closeCursor();
        if ($recipientId === false) {
            return ['ok' => false, 'error' => 'destinataire_inconnu', 'message' => 'Destinataire introuvable.'];
        }
        if ((int) $recipientId === $senderId) {
            return ['ok' => false, 'error' => 'self', 'message' => 'Tu ne peux pas t\'écrire à toi-même.'];
        }
        // Rien n'entre dans `dm` entre deux membres dont l'un a bloqué l'autre :
        // SelfModerate y lit les liens entre votants (`areLinked()`).
        if (self::aBloque($pdo, (int) $recipientId, $senderId)) {
            return ['ok' => false, 'error' => 'bloque', 'message' => 'Ce membre ne reçoit pas tes messages.'];
        }
        if (self::aBloque($pdo, $senderId, (int) $recipientId)) {
            return ['ok' => false, 'error' => 'bloque', 'message' => 'Tu as bloqué ce membre : débloque-le pour lui écrire.'];
        }

        $ciphertext = DataGuard::encrypt($message, DataGuard::contexteDm($pdo, $senderId, (int) $recipientId));
        $pdo->prepare(
            'INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)'
        )->execute([$senderId, (int) $recipientId, $ciphertext, time()]);

        return ['ok' => true, 'dm_id' => (int) $pdo->lastInsertId()];
    }

    /**
     * Le clair d'un message, ou `null` s'il ne s'ouvre pas ici.
     *
     * Un blob scellé pour une autre conversation échoue au déchiffrement, et
     * c'est le but. Laisser l'exception remonter ferait tomber la boîte entière
     * sur un seul blob déplacé : qui obtient une écriture pourrait alors fermer
     * la messagerie de n'importe qui. La ligne reste donc affichée, illisible,
     * et les autres messages s'affichent.
     *
     * 🔑 L'état se rend à part, il n'habite pas le texte : reconnu à son libellé, un
     * message dont le clair EST ce libellé passait pour illisible, et deux suffisaient à
     * écrire au journal l'alarme d'une clé remplacée (mesuré par la neuvième passe
     * d'audit). C'est le défaut que `Profile::lire()` a déjà fermé pour les profils.
     *
     * ⚠️ **Le texte ne nomme pas de cause.** Un tag qui ne correspond pas, un blob
     * tronqué et un chiffre indisponible sortent tous par ici ; la panne de secret,
     * elle, est écartée en amont par `DataGuard::exigerCle()`. Accuser le
     * déplacement enverrait chercher un attaquant là où la donnée est seulement
     * abîmée.
     */
    private static function clair(string $ciphertext, Aad $contexte): ?string
    {
        try {
            return DataGuard::decrypt($ciphertext, $contexte);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Journalise le seul signe d'un secret remplacé par un autre valide.
     *
     * 🔑 **C'est la troisième panne de clé, et la seule que rien ne refuse.** Un
     * secret absent ou trop court est rejeté par `DataGuard::exigerCle()` ; un
     * secret *remplacé* se dérive sans erreur et rend chaque ligne illisible.
     *
     * ⚠️ C'est un indice, pas une détection : qui écrit la colonne `ciphertext` d'une
     * boîte entière la fait écrire au journal, et une clé réellement remplacée ne la
     * déclenche pas sur une boîte d'un seul message, ni par `sent()`.
     */
    private static function signalerSiToutIllisible(int $accountId, int $total, int $illisibles): void
    {
        if ($total >= 2 && $illisibles === $total) {
            error_log(sprintf(
                'messagerie du compte #%d : %d message(s) sur %d illisibles — un déplacement de blob '
                . 'ne touche pas tout : vérifier le secret .blindkey, le numéro de la base (table '
                . 'lab_generation), ou une base restaurée d\'avant la passe de migration (relancer '
                . 'tools/migrer-aad.php).',
                $accountId,
                $illisibles,
                $total
            ));
        }
    }

    /** `$bloqueur` a-t-il bloqué `$bloque` ? */
    public static function aBloque(PDO $pdo, int $bloqueur, int $bloque): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM dm_blocages WHERE bloqueur_id = ? AND bloque_id = ?');
        $stmt->execute([$bloqueur, $bloque]);
        $oui = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();

        return $oui;
    }

    /**
     * Bloque ou débloque un membre, désigné par son identifiant public. Bloquer ne
     * touche à aucun message : ceux déjà reçus ne s'affichent plus dans la boîte de
     * qui bloque, et reviennent s'il débloque.
     */
    public static function bloquer(PDO $pdo, int $bloqueur, string $username, bool $bloquer = true): array
    {
        $stmt = $pdo->prepare('SELECT id FROM accounts WHERE username = ?');
        $stmt->execute([strtolower(trim($username))]);
        $bloque = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($bloque === false) {
            return ['ok' => false, 'error' => 'membre_inconnu', 'message' => 'Membre introuvable.'];
        }
        if ((int) $bloque === $bloqueur) {
            return ['ok' => false, 'error' => 'self', 'message' => 'Tu ne peux pas te bloquer toi-même.'];
        }
        if ($bloquer) {
            $pdo->prepare('INSERT OR IGNORE INTO dm_blocages (bloqueur_id, bloque_id, created_at) VALUES (?, ?, ?)')
                ->execute([$bloqueur, (int) $bloque, time()]);
        } else {
            $pdo->prepare('DELETE FROM dm_blocages WHERE bloqueur_id = ? AND bloque_id = ?')->execute([$bloqueur, (int) $bloque]);
        }

        return ['ok' => true, 'bloque' => $bloquer];
    }

    /** Les identifiants publics des membres que `$bloqueur` a bloqués. @return list<string> */
    public static function bloques(PDO $pdo, int $bloqueur): array
    {
        $stmt = $pdo->prepare(
            'SELECT a.username FROM dm_blocages b JOIN accounts a ON a.id = b.bloque_id WHERE b.bloqueur_id = ? ORDER BY a.username'
        );
        $stmt->execute([$bloqueur]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Boîte de réception : messages reçus par l'account, déchiffrés. */
    public static function inbox(PDO $pdo, int $accountId): array
    {
        $stmt = $pdo->prepare('
            SELECT d.id, d.sender_id, d.ciphertext, d.created_at, d.lu, a.username AS expediteur
              FROM dm d JOIN accounts a ON a.id = d.sender_id
             WHERE d.recipient_id = ?
               AND NOT EXISTS (SELECT 1 FROM dm_blocages b WHERE b.bloqueur_id = d.recipient_id AND b.bloque_id = d.sender_id)
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
            $clair = self::clair($row['ciphertext'], DataGuard::contexteDm($pdo, (int) $row['sender_id'], $accountId));
            $illisibles += $clair === null ? 1 : 0;
            $out[] = [
                'id'         => (int) $row['id'],
                'expediteur' => $row['expediteur'],
                'message'    => $clair ?? self::ILLISIBLE,
                'illisible'  => $clair === null,
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
            $clair = self::clair($row['ciphertext'], DataGuard::contexteDm($pdo, $accountId, (int) $row['recipient_id']));
            $out[] = [
                'id'           => (int) $row['id'],
                'destinataire' => $row['destinataire'],
                'message'      => $clair ?? self::ILLISIBLE,
                'illisible'    => $clair === null,
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
