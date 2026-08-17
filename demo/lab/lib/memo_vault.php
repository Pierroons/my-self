<?php
/**
 * MySelf-Lab — stockage du coffre E2E du mémo.
 *
 * IMPORTANT : cette couche ne fait AUCUNE cryptographie. Toute la dérivation de
 * clé et le chiffrement/déchiffrement se passent dans le NAVIGATEUR (WebCrypto).
 * Le serveur ne voit que des blobs opaques : aucune clé, aucun plaintext.
 * Un dump de la table `memo_vault` ne révèle rien d'exploitable sans le secret
 * de l'utilisateur — qui n'a jamais quitté son poste.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

final class MemoVault
{
    private const CHAMPS = [
        'kdf_salt', 'kdf_iter', 'memo_iv', 'memo_ct',
        'wrap_pw_iv', 'wrap_pw_ct', 'wrap_rec_iv', 'wrap_rec_ct',
    ];

    /** Le coffre opaque du compte, ou null s'il n'existe pas encore. */
    public static function get(PDO $pdo, int $accountId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT kdf_salt, kdf_iter, memo_iv, memo_ct, wrap_pw_iv, wrap_pw_ct,
                    wrap_rec_iv, wrap_rec_ct, updated_at
               FROM memo_vault WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Existe-t-il un coffre pour ce compte ? (sans renvoyer le contenu) */
    public static function exists(PDO $pdo, int $accountId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM memo_vault WHERE account_id = ?');
        $stmt->execute([$accountId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Enregistre/écrase le coffre opaque. Valide seulement le format (base64,
     * tailles), JAMAIS le contenu (le serveur ne peut pas le lire de toute façon).
     */
    public static function save(PDO $pdo, int $accountId, array $blobs): array
    {
        $clean = [];
        foreach (self::CHAMPS as $c) {
            $v = (string) ($blobs[$c] ?? '');
            if ($c === 'kdf_iter') {
                $iter = (int) $v;
                if ($iter < 100000 || $iter > 5000000) {
                    return ['ok' => false, 'message' => 'Paramètre KDF hors bornes.'];
                }
                $clean[$c] = $iter;
                continue;
            }
            // base64 strict, longueur raisonnable (anti-abus de stockage)
            if ($v === '' || !preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $v) || strlen($v) > 20000) {
                return ['ok' => false, 'message' => "Champ '$c' invalide."];
            }
            $clean[$c] = $v;
        }

        $pdo->prepare(
            'INSERT INTO memo_vault
               (account_id, kdf_salt, kdf_iter, memo_iv, memo_ct,
                wrap_pw_iv, wrap_pw_ct, wrap_rec_iv, wrap_rec_ct, updated_at)
             VALUES (:id,:salt,:iter,:miv,:mct,:pwiv,:pwct,:reciv,:recct,:ts)
             ON CONFLICT(account_id) DO UPDATE SET
               kdf_salt=excluded.kdf_salt, kdf_iter=excluded.kdf_iter,
               memo_iv=excluded.memo_iv, memo_ct=excluded.memo_ct,
               wrap_pw_iv=excluded.wrap_pw_iv, wrap_pw_ct=excluded.wrap_pw_ct,
               wrap_rec_iv=excluded.wrap_rec_iv, wrap_rec_ct=excluded.wrap_rec_ct,
               updated_at=excluded.updated_at'
        )->execute([
            ':id' => $accountId, ':salt' => $clean['kdf_salt'], ':iter' => $clean['kdf_iter'],
            ':miv' => $clean['memo_iv'], ':mct' => $clean['memo_ct'],
            ':pwiv' => $clean['wrap_pw_iv'], ':pwct' => $clean['wrap_pw_ct'],
            ':reciv' => $clean['wrap_rec_iv'], ':recct' => $clean['wrap_rec_ct'],
            ':ts' => time(),
        ]);
        return ['ok' => true];
    }

    /**
     * Met à jour SEULEMENT le mémo chiffré (cas « édition après déverrouillage » :
     * la vault_key est inchangée, donc les enveloppes ne bougent pas).
     */
    public static function updateMemo(PDO $pdo, int $accountId, string $iv, string $ct): array
    {
        if (!preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $iv) || !preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $ct) || strlen($ct) > 20000) {
            return ['ok' => false, 'message' => 'Blob mémo invalide.'];
        }
        $stmt = $pdo->prepare('UPDATE memo_vault SET memo_iv=?, memo_ct=?, updated_at=? WHERE account_id=?');
        $stmt->execute([$iv, $ct, time(), $accountId]);
        return ['ok' => $stmt->rowCount() > 0 ? true : self::exists($pdo, $accountId)];
    }
}
