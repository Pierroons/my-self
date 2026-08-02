<?php
/**
 * MySelf-Lab — facteur possession « CET APPAREIL » (device-bound, ECDSA P-256).
 *
 * Le navigateur détient une clé ECDSA P-256 dont la PRIVÉE est chiffrée au repos par une
 * clé dérivée du MOT MÉMORISÉ (côté client). Le serveur ne détient QUE la clé PUBLIQUE.
 * Récupération = signer un challenge : impossible sans l'appareil (le blob chiffré) ET le
 * mot (pour le déchiffrer) → 2FA cryptographiquement liée, le serveur vérifie une signature.
 *
 * Niveau « correct, pas max » : protégé logiciel (pas TPM), résistant au distant / phishing
 * (stockage navigateur cloisonné) / fuite DB (clé publique seule) / vol d'appareil (blob
 * inutile sans le mot, cassable seulement hors-ligne contre Argon2id).
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

final class Device
{
    private const CHALLENGE_TTL = 300; // 5 min
    private const ENROLL_WINDOW = 900;          // fenêtre de comptage (15 min)
    private const ENROLL_MAX_FAILS_PER_IP = 12; // aligné sur Auth::LOGIN_MAX_FAILS_PER_IP

    public static function b64urlEncode(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    /** Signature ECDSA P1363 (r||s brut de WebCrypto) → DER (attendu par openssl_verify). */
    public static function p1363ToDer(string $sig): string
    {
        $len = intdiv(strlen($sig), 2);
        if ($len === 0) {
            return '';
        }
        $r = ltrim(substr($sig, 0, $len), "\x00"); if ($r === '') { $r = "\x00"; }
        $s = ltrim(substr($sig, $len), "\x00");    if ($s === '') { $s = "\x00"; }
        if (ord($r[0]) & 0x80) { $r = "\x00" . $r; } // INTEGER positif
        if (ord($s[0]) & 0x80) { $s = "\x00" . $s; }
        $body = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;
        return "\x30" . chr(strlen($body)) . $body;
    }

    /** Clé publique SPKI (DER) → PEM pour openssl. */
    public static function spkiToPem(string $spkiDer): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spkiDer), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Enrôle un appareil pour un compte : stocke SA CLÉ PUBLIQUE (SPKI DER, base64url).
     *
     * 🔑 **L'enrôlement exige le mot mémorisé.** Sans cette preuve, n'importe qui
     * pouvait poser SA clé publique sur le compte d'autrui — il suffisait d'en
     * connaître le nom — puis signer un défi et récupérer un mot de passe neuf.
     * Trois requêtes, aucun secret, tous les comptes. Corrigé le 02/08/2026.
     *
     * Le mot arrive déjà dérivé du navigateur (HMAC, cf `sr-derive.js`) : le
     * serveur ne le voit jamais en clair et le compare à `recovery_hash`, le
     * même hachage que le niveau 2 de la récupération.
     */
    public static function enroll(
        PDO $pdo,
        string $username,
        string $credentialId,
        string $publicKeyB64url,
        string $recoveryDerivedKey = '',
        ?string $ip = null
    ): array {
        // Message unique pour tous les refus qui suivent la validation de forme :
        // distinguer « compte inconnu » de « mot incorrect » rendrait l'un des
        // deux facteurs testable seul, et transformerait cet endpoint en oracle
        // d'existence de comptes.
        $refus = ['ok' => false, 'message' => 'Compte ou mot mémorisé incorrect.'];

        $username     = strtolower(trim($username));
        $credentialId = trim($credentialId);
        $publicKey    = self::b64urlDecode($publicKeyB64url);
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $credentialId) || strlen($publicKey) < 50) {
            return ['ok' => false, 'message' => "Données d'enrôlement invalides."];
        }
        if (!Auth::isDerivedKey($recoveryDerivedKey)) {
            return ['ok' => false, 'error' => 'invalid_derived_key',
                    'message' => 'Mot mémorisé invalide : la dérivation doit se faire dans le navigateur.'];
        }
        if (openssl_pkey_get_public(self::spkiToPem($publicKey)) === false) {
            return ['ok' => false, 'message' => 'Clé publique invalide.'];
        }

        // Même compteur d'IP que la récupération : enrôler est un chemin vers le
        // compte au même titre, il doit se fatiguer aussi vite.
        if ($ip !== null) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at > ?'
            );
            $stmt->execute([$ip, time() - self::ENROLL_WINDOW]);
            if ((int) $stmt->fetchColumn() >= self::ENROLL_MAX_FAILS_PER_IP) {
                return ['ok' => false, 'message' => 'Trop de tentatives. Réessaie dans 15 minutes.'];
            }
        }

        $stmt = $pdo->prepare('SELECT id, recovery_hash FROM accounts WHERE username = ?');
        $stmt->execute([$username]);
        $acc = $stmt->fetch();

        // Argon2id exécuté même sur compte inconnu : sinon le temps de réponse
        // dirait ce que le message se garde de dire.
        $motOk = password_verify($recoveryDerivedKey, $acc ? $acc['recovery_hash'] : Auth::dummyHash());
        $ok    = (bool) $acc && $motOk;

        $pdo->prepare('INSERT INTO login_attempts (username, success, ip, attempted_at) VALUES (?, ?, ?, ?)')
            ->execute(['enroll:' . ($acc ? $username : 'inconnu'), $ok ? 1 : 0, $ip, time()]);

        if (!$ok) {
            usleep(300000);
            return $refus;
        }

        $pdo->prepare(
            'INSERT OR REPLACE INTO device_credentials (account_id, credential_id, public_key, created_at)
             VALUES (?, ?, ?, ?)'
        )->execute([(int) $acc['id'], $credentialId, self::b64urlEncode($publicKey), time()]);
        return ['ok' => true, 'message' => 'Appareil enrôlé.'];
    }

    /** Émet un challenge 32 octets pour la récupération « depuis cet appareil ». */
    public static function authBegin(PDO $pdo, string $credentialId): array
    {
        $credentialId = trim($credentialId);
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $credentialId)) {
            return ['ok' => false, 'message' => 'credential_id invalide.'];
        }
        $pdo->prepare('DELETE FROM device_challenges WHERE created_at < ?')->execute([time() - self::CHALLENGE_TTL]);
        $challenge = self::b64urlEncode(random_bytes(32));
        $pdo->prepare('INSERT OR REPLACE INTO device_challenges (challenge, credential_id, created_at) VALUES (?, ?, ?)')
            ->execute([$challenge, $credentialId, time()]);
        return ['ok' => true, 'challenge' => $challenge];
    }

    /** Vérifie la signature du challenge (appareil + mot) → nouveau mot de passe. */
    public static function authFinish(PDO $pdo, string $credentialId, string $challenge, string $signatureB64url): array
    {
        $credentialId = trim($credentialId);
        $challenge    = trim($challenge);
        $signature    = self::b64urlDecode($signatureB64url);
        if ($credentialId === '' || $challenge === '') {
            return ['ok' => false, 'message' => 'Données incomplètes.'];
        }

        $stmt = $pdo->prepare('SELECT 1 FROM device_challenges WHERE challenge = ? AND credential_id = ? AND created_at > ?');
        $stmt->execute([$challenge, $credentialId, time() - self::CHALLENGE_TTL]);
        if (!$stmt->fetch()) {
            return ['ok' => false, 'message' => 'Challenge invalide ou expiré.'];
        }
        $pdo->prepare('DELETE FROM device_challenges WHERE challenge = ?')->execute([$challenge]); // usage unique

        $stmt = $pdo->prepare(
            'SELECT dc.public_key, a.id AS account_id, a.username
               FROM device_credentials dc JOIN accounts a ON a.id = dc.account_id
              WHERE dc.credential_id = ?'
        );
        $stmt->execute([$credentialId]);
        $cred = $stmt->fetch();
        if (!$cred) {
            usleep(300000);
            return ['ok' => false, 'message' => 'Appareil ou mot mémorisé incorrect.'];
        }

        // Le navigateur a signé les octets de la chaîne base64url du challenge.
        $pem = self::spkiToPem(self::b64urlDecode($cred['public_key']));
        $der = self::p1363ToDer($signature);
        $ok  = ($der !== '') ? openssl_verify($challenge, $der, $pem, OPENSSL_ALGO_SHA256) : -1;
        if ($ok !== 1) {
            return ['ok' => false, 'message' => 'Appareil ou mot mémorisé incorrect.'];
        }

        // Succès : nouveau mot de passe généré + sessions coupées.
        $newPassword = Auth::generatePassword(16);
        $pdo->prepare('UPDATE accounts SET pw_hash = ? WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_ARGON2ID, Auth::argon2Options()), (int) $cred['account_id']]);
        $pdo->prepare('DELETE FROM app_sessions WHERE account_id = ?')->execute([(int) $cred['account_id']]);

        return ['ok' => true, 'credentials' => ['password' => $newPassword],
                'note' => 'Mot de passe réinitialisé (cet appareil + mot mémorisé). Copie-le maintenant.'];
    }
}
