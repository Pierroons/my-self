<?php
/**
 * SelfRecover demo — core protocol logic
 *
 * This is a minimalist reference implementation.
 * Compared to the production version of a community platform, this omits:
 *   - Level 3 scoring recovery
 *   - Dispute system
 *   - Push notifications
 *   - Rate limiting per IP (only per username)
 *   - Anti-bot honeypot
 *   - Suspicious fingerprint tracking
 *
 * Use this to understand the core protocol, not for production.
 */

/**
 * Charge la wordlist EFF officielle (7776 mots) depuis le fichier versionné.
 * Convention MySelf : sources officielles dès le départ — pas de mini-version
 * "pour la démo". Tronquer la wordlist dégrade silencieusement l'entropie
 * (4,9 bits par mot perdus en passant de 7776 à 256 mots).
 *
 * Fichier : data/eff_large_wordlist_<lang>.txt — un mot par ligne.
 * Source originale : https://www.eff.org/files/2016/07/18/eff_large_wordlist.txt
 *
 * @param string $lang 'en' ou 'fr' (par défaut 'en')
 * @return string[] tableau de 7776 mots
 */
function loadEffWordlist(string $lang = 'en'): array {
    static $cache = [];
    if (isset($cache[$lang])) return $cache[$lang];

    $path = __DIR__ . "/../data/eff_large_wordlist_{$lang}.txt";
    if (!is_readable($path)) {
        throw new RuntimeException("Wordlist EFF introuvable : {$path}");
    }
    $words = preg_split('/\r?\n/', trim(file_get_contents($path)));
    $words = array_values(array_filter($words, fn($w) => $w !== ''));
    if (count($words) !== 7776) {
        throw new RuntimeException(
            "Wordlist EFF '{$lang}' invalide : " . count($words) . " mots au lieu de 7776"
        );
    }
    $cache[$lang] = $words;
    return $words;
}

/**
 * Génère une passphrase diceware depuis la wordlist EFF officielle.
 *
 * Tirage aléatoire crypto-secure via random_int() + rejection sampling
 * pour garantir une distribution uniforme sur les 7776 mots.
 *
 * Entropie produite (avec EFF 7776 mots) :
 *   - 4 mots → 51,7 bits  (recommandé minimal)
 *   - 5 mots → 64,6 bits  (recommandé courant)
 *   - 6 mots → 77,5 bits  (sensibles : compte admin, sudo)
 *   - 7 mots → 90,5 bits  (paranoïa raisonnable)
 *   - 10 mots → 129,3 bits (équivalent AES-128)
 *
 * @param int $count nombre de mots (par défaut 4)
 * @param string $lang 'en' ou 'fr'
 * @param string $sep séparateur entre mots (par défaut '-')
 * @return string passphrase générée
 */
function generateDiceware(int $count = 4, string $lang = 'en', string $sep = '-'): string {
    if ($count < 3 || $count > 12) {
        throw new InvalidArgumentException("Diceware count hors bornes (3-12) : {$count}");
    }
    $words = loadEffWordlist($lang);
    $size = count($words);
    $bound = intdiv(PHP_INT_MAX, $size) * $size; // anti-biais rejection sampling

    $picked = [];
    for ($i = 0; $i < $count; $i++) {
        do {
            $r = random_int(0, PHP_INT_MAX);
        } while ($r >= $bound);
        $picked[] = $words[$r % $size];
    }
    return implode($sep, $picked);
}

/**
 * Calcule l'entropie en bits d'une passphrase diceware (mots EFF uniformes).
 * Formule : count * log2(7776) ≈ count * 12,9248
 */
function dicewareEntropyBits(int $count): float {
    return round($count * log(7776, 2), 2);
}

function generatePassword(int $len = 10): string {
    $alphabet = 'abcdefghkmnpqrstuvwxyzABCDEFGHKMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

// === REGISTER ===
function handleRegister(): void {
    $in = getInput();
    securityChecks($in);
    $username = trim($in['username'] ?? '');
    $identifier = trim($in['identifier'] ?? '');
    $password = $in['password'] ?? '';
    $recoveryDerivedKey = $in['recovery_derived_key'] ?? '';

    addTrace(sprintf(
        "[register] input recu — username:%dch, identifier:%dch, password:%dch, recovery_derived_key:%dch",
        strlen($username), strlen($identifier), strlen($password), strlen($recoveryDerivedKey)
    ));

    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        jsonError('Username: 3-20 chars alphanumeric/underscore');
    }
    if (strlen($identifier) < 3 || strlen($identifier) > 50) {
        jsonError('Identifier: 3-50 chars');
    }
    if (strlen($password) < 8) {
        jsonError('Password: 8 chars minimum');
    }
    if (!$recoveryDerivedKey) {
        jsonError('Recovery word required');
    }
    addTrace("[register] validation OK (username pattern, longueurs)");

    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR identifier = ?");
    $stmt->execute([$username, $identifier]);
    if ($stmt->fetch()) jsonError('Username or identifier already taken');
    addTrace("[register] DB unique check OK (no collision)");

    $t0 = microtime(true);
    $passphrase = generateDiceware(4);
    addTrace(sprintf(
        "[register] diceware generated: 4 mots EFF (51.7 bits) en %.1f ms — passphrase brute non loguee",
        (microtime(true) - $t0) * 1000
    ));

    $t0 = microtime(true);
    $pwdHash = password_hash($password, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
    addTrace(sprintf(
        "[register] Argon2id(password) en %.0f ms (hash sortie: %dch, prefix: %s)",
        (microtime(true) - $t0) * 1000, strlen($pwdHash), substr($pwdHash, 0, 7)
    ));

    $t0 = microtime(true);
    $ppHash = password_hash($passphrase, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
    addTrace(sprintf(
        "[register] Argon2id(passphrase) en %.0f ms — hash stocke, jamais en clair",
        (microtime(true) - $t0) * 1000
    ));

    $t0 = microtime(true);
    $rcHash = password_hash($recoveryDerivedKey, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
    addTrace(sprintf(
        "[register] Argon2id(recovery_derived_key) en %.0f ms — note: input deja HMAC-SHA256 cote client",
        (microtime(true) - $t0) * 1000
    ));

    $stmt = $db->prepare("
        INSERT INTO users (username, identifier, password_hash, passphrase_hash, recovery_derived_hash)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$username, $identifier, $pwdHash, $ppHash, $rcHash]);
    addTrace(sprintf("[register] DB INSERT users (id=%d) — done", $db->lastInsertId()));

    jsonResponse([
        'message' => 'Account created',
        'username' => $username,
        'passphrase' => $passphrase,
        'note' => 'Save your passphrase — it will never be shown again. This is your L1 recovery secret.',
    ]);
}

// === LOGIN ===
function handleLogin(): void {
    $in = getInput();
    securityChecks($in);
    $username = trim($in['username'] ?? '');
    $password = $in['password'] ?? '';
    addTrace(sprintf("[login] input recu — username:%dch, password:%dch", strlen($username), strlen($password)));

    if (!$username || !$password) jsonError('Username and password required');

    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    addTrace($user ? "[login] DB SELECT users — found id=" . $user['id'] : "[login] DB SELECT users — no match");

    $t0 = microtime(true);
    $verified = $user && password_verify($password, $user['password_hash']);
    addTrace(sprintf("[login] password_verify en %.0f ms — result: %s", (microtime(true) - $t0) * 1000, $verified ? 'OK' : 'FAIL'));

    if (!$verified) {
        jsonError('Invalid credentials', 401);
    }
    jsonResponse(['message' => 'Logged in', 'username' => $user['username']]);
}

// === RECOVER L1 ===
function handleRecoverL1(): void {
    $in = getInput();
    securityChecks($in);
    $username = trim($in['username'] ?? '');
    $passphrase = trim($in['passphrase'] ?? '');
    addTrace(sprintf("[recover-l1] input recu — username:%dch, passphrase:%dch (mots: %d)",
        strlen($username), strlen($passphrase), substr_count($passphrase, '-') + 1));

    if (!$username || !$passphrase) jsonError('Username and passphrase required');

    $db = getDB();

    // Check block
    $stmt = $db->prepare("SELECT id, passphrase_hash, l1_block_count, l1_blocked_until FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user) {
        logAttempt($db, $username, 1, false);
        sleep(1);
        jsonError('Invalid credentials', 401);
    }
    if ($user['l1_block_count'] >= 3) {
        jsonError('Too many failed attempts. Use recovery level 2.', 429);
    }
    if ($user['l1_blocked_until'] && strtotime($user['l1_blocked_until']) > time()) {
        jsonError('Blocked. Try again later.', 429);
    }

    // 3 failed attempts in 15 min → block 1h + increment block count
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM recovery_attempts
        WHERE username = ? AND level = 1 AND success = 0
        AND datetime(attempted_at) > datetime('now', '-15 minutes')
    ");
    $stmt->execute([$username]);
    if ((int)$stmt->fetchColumn() >= 3) {
        $db->prepare("UPDATE users SET l1_blocked_until = datetime('now', '+1 hour'), l1_block_count = l1_block_count + 1 WHERE id = ?")
           ->execute([$user['id']]);
        logAttempt($db, $username, 1, false);
        jsonError('Too many attempts. Blocked 1 hour.', 429);
    }

    $t0 = microtime(true);
    $verified = password_verify($passphrase, $user['passphrase_hash']);
    addTrace(sprintf("[recover-l1] Argon2id verify(passphrase) en %.0f ms — result: %s",
        (microtime(true) - $t0) * 1000, $verified ? 'OK' : 'FAIL'));

    if (!$verified) {
        logAttempt($db, $username, 1, false);
        jsonError('Incorrect passphrase', 401);
    }

    $newPwd = generatePassword();
    addTrace("[recover-l1] new password generated (10 chars alphabet) — pas logue en clair");
    $newHash = password_hash($newPwd, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
    $db->prepare("UPDATE users SET password_hash = ?, l1_block_count = 0, l1_blocked_until = NULL WHERE id = ?")
       ->execute([$newHash, $user['id']]);
    addTrace(sprintf("[recover-l1] DB UPDATE users (id=%d) — pwd_hash, block_count=0, blocked_until=NULL", $user['id']));
    logAttempt($db, $username, 1, true);

    jsonResponse([
        'message' => 'Password reset',
        'new_password' => $newPwd,
    ]);
}

// === RECOVER L2 ===
function handleRecoverL2(): void {
    $in = getInput();
    securityChecks($in);
    $identifier = trim($in['identifier'] ?? '');
    $recoveryKey = trim($in['recovery_key'] ?? ''); // Already HMAC-derived client-side
    addTrace(sprintf("[recover-l2] input recu — identifier:%dch, recovery_key:%dch (deja HMAC client)",
        strlen($identifier), strlen($recoveryKey)));

    if (!$identifier || !$recoveryKey) jsonError('Identifier and recovery key required');

    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, recovery_derived_hash FROM users WHERE identifier = ?");
    $stmt->execute([$identifier]);
    $user = $stmt->fetch();
    if (!$user) {
        addTrace("[recover-l2] DB SELECT — no match for identifier (anti-timing: sleep 1s)");
        sleep(1);
        jsonError('Invalid credentials', 401);
    }
    addTrace("[recover-l2] DB SELECT — found user id=" . $user['id']);

    $t0 = microtime(true);
    $verified = password_verify($recoveryKey, $user['recovery_derived_hash']);
    addTrace(sprintf("[recover-l2] Argon2id verify(HMAC_recovery_key) en %.0f ms — result: %s",
        (microtime(true) - $t0) * 1000, $verified ? 'OK' : 'FAIL'));

    if (!$verified) {
        logAttempt($db, $user['username'], 2, false);
        jsonError('Incorrect recovery word', 401);
    }

    $newPwd = generatePassword();
    $newHash = password_hash($newPwd, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
    $db->prepare("UPDATE users SET password_hash = ?, l1_block_count = 0, l1_blocked_until = NULL WHERE id = ?")
       ->execute([$newHash, $user['id']]);
    addTrace(sprintf("[recover-l2] DB UPDATE users (id=%d) — new pwd hash, block_count reset", $user['id']));
    logAttempt($db, $user['username'], 2, true);

    jsonResponse([
        'message' => 'Password reset via L2',
        'new_password' => $newPwd,
    ]);
}

function logAttempt(PDO $db, string $username, int $level, bool $success): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $fingerprint = $_SERVER['HTTP_X_CLIENT_FINGERPRINT'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $db->prepare("INSERT INTO recovery_attempts (username, level, success, ip_address, fingerprint, user_agent) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$username, $level, $success ? 1 : 0, $ip, $fingerprint, $userAgent]);
    if (!$success && $ip) {
        trackSuspiciousIP($db, $ip, $fingerprint, $userAgent);
    }
}

/**
 * Rate limit per-IP : retourne true si l'IP est dans une fenêtre de blocage active.
 * Stratégie : 10 tentatives ratées (toutes confondues) → blocage 24 h.
 */
function isBlockedIP(PDO $db, string $ip): bool {
    if (!$ip) return false;
    $stmt = $db->prepare("
        SELECT blocked_until FROM suspicious_fingerprints
        WHERE ip = ? AND blocked_until IS NOT NULL
        ORDER BY last_seen DESC LIMIT 1
    ");
    $stmt->execute([$ip]);
    $blocked = $stmt->fetchColumn();
    return $blocked && strtotime($blocked) > time();
}

function trackSuspiciousIP(PDO $db, string $ip, string $fingerprint, string $userAgent): void {
    if (!$ip) return;
    $stmt = $db->prepare("SELECT id, attempt_count FROM suspicious_fingerprints WHERE ip = ? AND fingerprint = ?");
    $stmt->execute([$ip, $fingerprint]);
    $row = $stmt->fetch();
    if ($row) {
        $newCount = (int)$row['attempt_count'] + 1;
        $blockedUntil = $newCount >= 10 ? date('Y-m-d H:i:s', time() + 86400) : null;
        $db->prepare("UPDATE suspicious_fingerprints SET attempt_count = ?, blocked_until = ?, last_seen = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$newCount, $blockedUntil, $row['id']]);
    } else {
        $db->prepare("INSERT INTO suspicious_fingerprints (ip, fingerprint, user_agent) VALUES (?, ?, ?)")
           ->execute([$ip, $fingerprint, $userAgent]);
    }
}

/**
 * Honeypot anti-bot : champ caché 'website' dans les formulaires côté client.
 * Un humain ne le remplit jamais ; un bot scrape et le remplit.
 * Si rempli → 3 sec de tempo (gaspille la session bot) puis erreur générique.
 */
function checkHoneypot(array $in): void {
    if (!empty($in['website'])) {
        sleep(3);
        jsonError('Identifiants incorrects', 401);
    }
}

/**
 * Wrapper d'entrée pour tous les handlers : honeypot + check IP blocked.
 * À appeler immédiatement après getInput() dans chaque endpoint.
 */
function securityChecks(array $in): void {
    checkHoneypot($in);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip && isBlockedIP(getDB(), $ip)) {
        jsonError('Trop de tentatives depuis votre IP. Réessayez dans 24 heures.', 429);
    }
}


// =====================================================================
// SelfRecover LITE (V0.1.1) — variante avec SMTP + mot mémorisé HMAC
// =====================================================================
//
// Concept : palier d'adoption progressive pour les sites qui ne peuvent pas
// abandonner SMTP. La reset par email est conservée mais protégée par un
// mot mémorisé dont la dérivée HMAC n'a jamais à transiter en clair.
//
// Flow :
//   1. Inscription Lite : client envoie HMAC(domain_salt, memorized_word)
//      au serveur. Serveur stocke Argon2id de la dérivée.
//   2. Reset request : user entre email → serveur génère request_id (32 bytes)
//      + salt_request (32 bytes) → insert reset_requests TTL 15 min →
//      retourne URL "mail simulé" à afficher dans la démo.
//   3. Reset confirm : user clique URL avec request_id, saisit nouveau pwd +
//      mot mémorisé. Client recalcule HMAC(domain_salt, memorized_word).
//      Serveur valide via password_verify contre Argon2id stocké.
//
// Sécurité :
//   - Email intercepté seul = insuffisant (manque mot mémorisé) ✓
//   - Mot mémorisé deviné seul = insuffisant (manque mail-URL) ✓
//   - Phishing = bloqué (HMAC dérivé du domaine côté client) ✓
//   - DB leak = Argon2id non réversible ✓


// === LITE REGISTER ===
function handleLiteRegister(): void {
    $in = getInput();
    securityChecks($in);
    $username = trim($in['username'] ?? '');
    $email = trim($in['email'] ?? '');
    $password = $in['password'] ?? '';
    // Le client envoie déjà la dérivée HMAC — pas le mot brut
    $memorizedDerived = $in['memorized_derived_key'] ?? '';

    addTrace(sprintf("[lite-register] input — username:%dch, email:%dch, password:%dch, memorized_derived:%dch",
        strlen($username), strlen($email), strlen($password), strlen($memorizedDerived)));

    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) jsonError('Username: 3-20 chars alphanumeric/underscore');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Email invalide');
    if (strlen($password) < 8) jsonError('Password: 8 chars minimum');
    if (strlen($memorizedDerived) !== 64) jsonError('Memorized word derivation must be 64 hex chars (HMAC-SHA256)');

    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) jsonError('Username ou email déjà utilisé');
    addTrace("[lite-register] DB unique check OK");

    $t0 = microtime(true);
    $pwdHash = password_hash($password, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
    $memHash = password_hash($memorizedDerived, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
    addTrace(sprintf("[lite-register] Argon2id(password) + Argon2id(memorized_derived) en %.0f ms",
        (microtime(true) - $t0) * 1000));

    // Pour la démo Lite : on ne stocke pas de passphrase ni d'identifier (champs full SelfRecover non utilisés)
    // mais le schema users les requiert NOT NULL — on met des placeholders inutilisés
    $placeholderId = 'lite-' . bin2hex(random_bytes(8));
    $stmt = $db->prepare("
        INSERT INTO users (username, identifier, password_hash, passphrase_hash,
                           recovery_derived_hash, email, memorized_word_hash)
        VALUES (?, ?, ?, '', '', ?, ?)
    ");
    $stmt->execute([$username, $placeholderId, $pwdHash, $email, $memHash]);
    addTrace(sprintf("[lite-register] DB INSERT users (id=%d, mode=lite)", $db->lastInsertId()));

    jsonResponse([
        'message' => 'Compte Lite créé',
        'username' => $username,
        'email' => $email,
        'note' => 'Mot mémorisé enregistré (sa dérivée HMAC). En cas de reset, il sera nécessaire en plus du mail.',
    ]);
}


// === LITE RESET REQUEST ===
function handleLiteResetRequest(): void {
    $in = getInput();
    securityChecks($in);
    $email = trim($in['email'] ?? '');
    addTrace(sprintf("[lite-reset-request] email:%dch", strlen($email)));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Email invalide');

    $db = getDB();
    $stmt = $db->prepare("SELECT id, username FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Anti-enumeration : on retourne toujours le même message, qu'il y ait un user ou non
    // (anti-timing : on simule la même latence)
    if (!$user) {
        addTrace("[lite-reset-request] no user (fake response anti-enum)");
        usleep(150000);
        jsonResponse([
            'message' => 'Si cet email correspond à un compte, un lien de réinitialisation a été envoyé.',
            'simulated_email' => null,
        ]);
    }

    // Génération request_id + salt cryptographiques
    $requestId = bin2hex(random_bytes(32));
    $saltRequest = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 min

    $stmt = $db->prepare("INSERT INTO reset_requests (id, user_id, salt, expires_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$requestId, $user['id'], $saltRequest, $expiresAt]);
    addTrace(sprintf("[lite-reset-request] request inseree (request_id:32B hex, salt:32B hex, TTL 15min)"));

    // En production : envoi mail SMTP. Ici : URL simulée retournée dans la réponse pour la démo
    $simulatedURL = sprintf('/selfrecover/lite-reset.html?id=%s&salt=%s', $requestId, $saltRequest);

    addTrace("[lite-reset-request] mail SIMULE — URL retournee directement pour la demo");

    jsonResponse([
        'message' => 'Si cet email correspond à un compte, un lien de réinitialisation a été envoyé.',
        'simulated_email' => [
            'to' => $email,
            'subject' => '[SelfRecover Lite Demo] Réinitialisation de mot de passe',
            'body_text' => "Bonjour,\n\nCliquez sur ce lien pour réinitialiser votre mot de passe :\n{HOST}{$simulatedURL}\n\nCe lien expire dans 15 minutes. Vous aurez besoin de votre mot mémorisé.\n",
            'expires_in_seconds' => 900,
            'reset_url' => $simulatedURL,
        ],
    ]);
}


// === LITE RESET INFO (validation que le lien est encore valide avant d'afficher le formulaire) ===
function handleLiteResetInfo(): void {
    $requestId = trim($_GET['id'] ?? '');
    addTrace(sprintf("[lite-reset-info] request_id:%dch", strlen($requestId)));

    if (strlen($requestId) !== 64) jsonError('Request ID invalide', 400);

    $db = getDB();
    $stmt = $db->prepare("SELECT id, salt, expires_at, used FROM reset_requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();

    if (!$req) {
        addTrace("[lite-reset-info] not found");
        jsonError('Lien invalide', 404);
    }
    if ($req['used']) {
        addTrace("[lite-reset-info] already used");
        jsonError('Lien déjà utilisé', 410);
    }
    if (strtotime($req['expires_at']) < time()) {
        addTrace("[lite-reset-info] expired");
        jsonError('Lien expiré (15 min depuis émission)', 410);
    }

    addTrace("[lite-reset-info] OK — request valide");
    jsonResponse([
        'valid' => true,
        'salt' => $req['salt'],
        'expires_at' => $req['expires_at'],
    ]);
}


// === LITE RESET CONFIRM ===
function handleLiteResetConfirm(): void {
    $in = getInput();
    securityChecks($in);
    $requestId = trim($in['request_id'] ?? '');
    $memorizedDerived = $in['memorized_derived_key'] ?? '';  // HMAC client-side
    $newPassword = $in['new_password'] ?? '';

    addTrace(sprintf("[lite-reset-confirm] request_id:%dch, memorized_derived:%dch, new_password:%dch",
        strlen($requestId), strlen($memorizedDerived), strlen($newPassword)));

    if (strlen($requestId) !== 64) jsonError('Request ID invalide');
    if (strlen($memorizedDerived) !== 64) jsonError('Memorized derivation must be 64 hex chars');
    if (strlen($newPassword) < 8) jsonError('New password: 8 chars minimum');

    $db = getDB();
    $stmt = $db->prepare("SELECT r.id, r.user_id, r.expires_at, r.used, u.memorized_word_hash, u.username
                          FROM reset_requests r
                          JOIN users u ON u.id = r.user_id
                          WHERE r.id = ?");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();

    if (!$req) {
        addTrace("[lite-reset-confirm] request not found");
        usleep(800000); // anti-timing 800ms
        jsonError('Lien invalide', 404);
    }
    if ($req['used']) jsonError('Lien déjà utilisé', 410);
    if (strtotime($req['expires_at']) < time()) jsonError('Lien expiré', 410);

    // Vérification du mot mémorisé via Argon2id sur la dérivée HMAC client-side
    $t0 = microtime(true);
    $verified = password_verify($memorizedDerived, $req['memorized_word_hash']);
    addTrace(sprintf("[lite-reset-confirm] Argon2id verify(memorized_derived) en %.0f ms — result: %s",
        (microtime(true) - $t0) * 1000, $verified ? 'OK' : 'FAIL'));

    if (!$verified) {
        usleep(500000); // anti-timing 500ms
        jsonError('Mot mémorisé incorrect', 401);
    }

    // Reset password
    $newHash = password_hash($newPassword, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
    $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
       ->execute([$newHash, $req['user_id']]);
    $db->prepare("UPDATE reset_requests SET used = 1 WHERE id = ?")->execute([$requestId]);
    addTrace(sprintf("[lite-reset-confirm] password reset OK pour user_id=%d, request marquee used", $req['user_id']));

    jsonResponse([
        'message' => 'Mot de passe réinitialisé avec succès',
        'username' => $req['username'],
    ]);
}
