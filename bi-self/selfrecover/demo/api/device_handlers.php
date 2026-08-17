<?php
/**
 * SelfRecover demo — facteur possession « CET APPAREIL » (device-bound, enveloppe souveraine).
 *
 * Le navigateur détient une clé ECDSA P-256 dont la privée est chiffrée AU REPOS par une clé
 * dérivée du MOT MÉMORISÉ (Argon2id, côté client). Le serveur ne détient QUE la clé publique.
 * Récup L2 = signer un challenge : impossible sans l'appareil (le blob) ET le mot (pour le
 * déchiffrer) → 2FA cryptographiquement liée, le serveur vérifie une seule signature.
 *
 * Niveau « correct pas max » : protégé LOGICIEL (pas TPM), device-bound (codes = plancher),
 * résistant au distant / phishing (IndexedDB cloisonnée) / fuite DB (clé publique seule) /
 * vol d'appareil (blob inutile sans le mot, cassable seulement hors-ligne contre Argon2id).
 */

function dev_b64url_encode(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function dev_b64url_decode(string $s): string { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** Signature ECDSA P1363 (r||s brut de WebCrypto) → DER (attendu par openssl_verify). */
function ecdsa_p1363_to_der(string $sig): string {
    $len = intdiv(strlen($sig), 2);
    if ($len === 0) return '';
    $r = ltrim(substr($sig, 0, $len), "\x00"); if ($r === '') $r = "\x00";
    $s = ltrim(substr($sig, $len),   "\x00"); if ($s === '') $s = "\x00";
    if (ord($r[0]) & 0x80) $r = "\x00" . $r; // INTEGER positif
    if (ord($s[0]) & 0x80) $s = "\x00" . $s;
    $body = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;
    return "\x30" . chr(strlen($body)) . $body;
}

/** Clé publique SPKI (DER) → PEM pour openssl. */
function spki_to_pem(string $spkiDer): string {
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spkiDer), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

// === ENROLL : enrôle cet appareil pour le compte CONNECTÉ ===
//
// 🔑 Session obligatoire, et le compte visé est celui de la session.
//
// Sans ces deux contraintes, la chaîne enroll → auth-begin → auth-finish
// permettait de prendre n'importe quel compte SANS AUCUN SECRET : il suffisait
// de nommer sa victime, d'enrôler sa propre clé publique sur son compte, de
// signer le défi avec sa clé privée, et `auth-finish` réécrivait le mot de
// passe. Le message de succès annonçait « appareil + mot, liés
// cryptographiquement » alors qu'aucun mot n'était vérifié.
//
// Le correctif équivalent avait été appliqué au lab le 02/08/2026 et jamais
// ici — sur l'instance publique, celle qui sert de vitrine. Constaté le
// 13/08/2026.
//
// ⚠️ Enrôler n'est pas récupérer. Qui a perdu son accès passe par L1
// (passphrase), L2 (code + mot) ou L3 (décision humaine).
function handleDeviceEnroll(): void {
    $in = getInput();
    checkHoneypot($in);
    $db = getDB();

    $session = currentSession($db);
    if ($session === null) {
        jsonError('Connecte-toi pour enrôler un appareil.', 401);
    }

    $credentialId = trim($in['credential_id'] ?? '');
    $publicKey    = dev_b64url_decode($in['public_key'] ?? ''); // SPKI DER
    if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $credentialId) || strlen($publicKey) < 50) {
        jsonError('Données d\'enrôlement invalides');
    }

    // Le nom vient de la session, jamais de la requête. Un envoi divergent se
    // refuse plutôt que d'être corrigé en silence : un client qui se trompe de
    // compte doit l'apprendre.
    $demande = trim($in['username'] ?? '');
    if ($demande !== '' && $demande !== (string) $session['username']) {
        jsonError("Un appareil ne s'enrôle que sur le compte connecté.", 403);
    }
    $user = ['id' => $session['user_id']];
    if (openssl_pkey_get_public(spki_to_pem($publicKey)) === false) jsonError('Clé publique invalide');
    $db->prepare("INSERT OR REPLACE INTO device_credentials (user_id, credential_id, public_key) VALUES (?, ?, ?)")
       ->execute([(int)$user['id'], $credentialId, dev_b64url_encode($publicKey)]);
    addTrace(sprintf('[device] enroll — appareil lié au compte user_id=%d (clé PUBLIQUE stockée ; privée chiffrée par le mot, jamais vue)', $user['id']));
    jsonResponse(['message' => 'Appareil enrôlé']);
}

// === AUTH BEGIN : challenge pour la récup « depuis cet appareil » ===
function handleDeviceAuthBegin(): void {
    $in = getInput();
    checkHoneypot($in);
    $credentialId = trim($in['credential_id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $credentialId)) jsonError('credential_id invalide');
    $db = getDB();
    $db->prepare("DELETE FROM device_challenges WHERE created_at < datetime('now', '-5 minutes')")->execute();
    $challenge = dev_b64url_encode(random_bytes(32));
    $db->prepare("INSERT OR REPLACE INTO device_challenges (challenge, credential_id) VALUES (?, ?)")
       ->execute([$challenge, $credentialId]);
    addTrace('[device] auth-begin — challenge émis (32 octets)');
    jsonResponse(['challenge' => $challenge]);
}

// === AUTH FINISH : vérifie la signature (appareil + mot) → reset ===
function handleDeviceAuthFinish(): void {
    $in = getInput();
    checkHoneypot($in);
    $credentialId = trim($in['credential_id'] ?? '');
    $challenge    = trim($in['challenge'] ?? '');
    $signature    = dev_b64url_decode($in['signature'] ?? ''); // P1363 (r||s)
    $newPassword  = $in['new_password'] ?? '';
    if (!$credentialId || !$challenge) jsonError('Données incomplètes');
    if (strlen($newPassword) < 8) jsonError('Nouveau mot de passe: 8 caractères minimum');

    $db = getDB();
    $stmt = $db->prepare("SELECT 1 FROM device_challenges WHERE challenge = ? AND credential_id = ? AND created_at > datetime('now', '-5 minutes')");
    $stmt->execute([$challenge, $credentialId]);
    if (!$stmt->fetch()) jsonError('Challenge invalide ou expiré', 400);
    $db->prepare("DELETE FROM device_challenges WHERE challenge = ?")->execute([$challenge]); // usage unique

    $stmt = $db->prepare("SELECT dc.public_key, u.id AS user_id, u.username
                          FROM device_credentials dc JOIN users u ON u.id = dc.user_id WHERE dc.credential_id = ?");
    $stmt->execute([$credentialId]);
    $cred = $stmt->fetch();
    if (!$cred) { usleep(500000); jsonError('Appareil inconnu', 401); }

    // Le navigateur a signé les octets de la chaîne base64url du challenge (mêmes deux côtés).
    $pem = spki_to_pem(dev_b64url_decode($cred['public_key']));
    $der = ecdsa_p1363_to_der($signature);
    $ok  = ($der !== '') ? openssl_verify($challenge, $der, $pem, OPENSSL_ALGO_SHA256) : -1;
    if ($ok !== 1) {
        logAttempt($db, $cred['username'], 2, false);
        addTrace('[device] auth-finish — signature INVALIDE (mauvais appareil, ou mot faux → clé mal déchiffrée)');
        jsonError('Appareil ou mot mémorisé incorrect', 401);
    }

    $newHash = password_hash($newPassword, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
    $db->prepare("UPDATE users SET password_hash = ?, l1_block_count = 0, l1_blocked_until = NULL WHERE id = ?")
       ->execute([$newHash, $cred['user_id']]);
    logAttempt($db, $cred['username'], 2, true);
    addTrace(sprintf('[device] auth-finish 2FA OK — reset user_id=%d (appareil + mot, liés cryptographiquement)', $cred['user_id']));
    jsonResponse(['message' => 'Mot de passe réinitialisé (L2 : cet appareil + mot mémorisé)', 'username' => $cred['username']]);
}
