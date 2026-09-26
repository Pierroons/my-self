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
        'kdf_salt', 'kdf', 'memo_iv', 'memo_ct',
        'wrap_pw_iv', 'wrap_pw_ct', 'wrap_rec_iv', 'wrap_rec_ct',
    ];

    /** Le coffre opaque du compte, ou null s'il n'existe pas encore. */
    public static function get(PDO $pdo, int $accountId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT kdf_salt, kdf, memo_iv, memo_ct, wrap_pw_iv, wrap_pw_ct,
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
            if ($c === 'kdf') {
                $kdf = self::kdf($v);
                if ($kdf === null) {
                    return ['ok' => false, 'message' => 'Paramètres KDF invalides.'];
                }
                $clean[$c] = $kdf;
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
               (account_id, kdf_salt, kdf_iter, kdf, memo_iv, memo_ct,
                wrap_pw_iv, wrap_pw_ct, wrap_rec_iv, wrap_rec_ct, updated_at)
             VALUES (:id,:salt,0,:kdf,:miv,:mct,:pwiv,:pwct,:reciv,:recct,:ts)
             ON CONFLICT(account_id) DO UPDATE SET
               kdf_salt=excluded.kdf_salt, kdf_iter=0, kdf=excluded.kdf,
               memo_iv=excluded.memo_iv, memo_ct=excluded.memo_ct,
               wrap_pw_iv=excluded.wrap_pw_iv, wrap_pw_ct=excluded.wrap_pw_ct,
               wrap_rec_iv=excluded.wrap_rec_iv, wrap_rec_ct=excluded.wrap_rec_ct,
               updated_at=excluded.updated_at'
        )->execute([
            ':id' => $accountId, ':salt' => $clean['kdf_salt'], ':kdf' => $clean['kdf'],
            ':miv' => $clean['memo_iv'], ':mct' => $clean['memo_ct'],
            ':pwiv' => $clean['wrap_pw_iv'], ':pwct' => $clean['wrap_pw_ct'],
            ':reciv' => $clean['wrap_rec_iv'], ':recct' => $clean['wrap_rec_ct'],
            ':ts' => time(),
        ]);
        return ['ok' => true];
    }

    /**
     * Les paramètres Argon2id du scellement, normalisés, ou null. Seule la FORME est
     * vérifiée ici, avec des bornes contre l'abus de stockage : le plancher de lecture
     * vit dans sr-kdf.js, qui refuse à la relecture des paramètres affaiblis.
     */
    private static function kdf(string $json): ?string
    {
        $k = json_decode($json, true);
        if (!is_array($k) || ($k['alg'] ?? null) !== 'argon2id') {
            return null;
        }
        foreach (['t' => [1, 20], 'm' => [8, 4194304], 'p' => [1, 16]] as $c => [$min, $max]) {
            if (!is_int($k[$c] ?? null) || $k[$c] < $min || $k[$c] > $max) {
                return null;
            }
        }

        return json_encode(['alg' => 'argon2id', 't' => $k['t'], 'm' => $k['m'], 'p' => $k['p']]);
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
