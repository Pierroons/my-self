<?php
/**
 * SelfRecover demo — core protocol logic
 *
 * Reference implementation of the full protocol: L1/L2/L3 recovery, dispute
 * system, admin decision flow, anti-abuse (honeypot, per-IP blocking,
 * suspicious-fingerprint tracking).
 *
 * Use this to understand the core protocol, not as-is in production.
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
    $userSalt = $in['user_salt'] ?? ''; // R9-02 : sel par-utilisateur (généré côté client)

    addTrace(sprintf(
        "[register] input recu — username:%dch, identifier:%dch, password:%dch, recovery_derived_key:%dch, user_salt:%dch",
        strlen($username), strlen($identifier), strlen($password), strlen($recoveryDerivedKey), strlen($userSalt)
    ));

    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        jsonError('Username: 3-20 chars alphanumeric/underscore');
    }
    // Identifier : PLUS nécessaire côté user (le recovery code localise le compte en L2).
    // Validé seulement s'il est fourni (compat ancien flux) ; sinon généré en interne
    // pour satisfaire la contrainte UNIQUE NOT NULL du schéma.
    if ($identifier !== '' && (strlen($identifier) < 3 || strlen($identifier) > 50)) {
        jsonError('Identifier: 3-50 chars');
    }
    if ($identifier === '') $identifier = 'u-' . bin2hex(random_bytes(8));
    if (strlen($password) < 8) {
        jsonError('Password: 8 chars minimum');
    }
    if (!$recoveryDerivedKey) {
        jsonError('Recovery word required');
    }
    // user_salt : vestige de l'ancienne dérivation par-identifier. Le nouveau flux dérive
    // le mot mémorisé par domaine → généré si absent (un sel n'est pas un secret).
    if (!preg_match('/^[a-f0-9]{32}$/', $userSalt)) $userSalt = bin2hex(random_bytes(16));
    addTrace("[register] validation OK (username pattern, longueurs, user_salt)");

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
        INSERT INTO users (username, identifier, password_hash, passphrase_hash, recovery_derived_hash, user_salt)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$username, $identifier, $pwdHash, $ppHash, $rcHash, $userSalt]);
    $userId = (int)$db->lastInsertId();
    addTrace(sprintf("[register] DB INSERT users (id=%d) — done", $userId));

    // Foyer possession L2 : lot de 10 recovery codes générés d'office, affichés UNE SEULE FOIS
    $recoveryCodes = generateRecoveryCodes($db, $userId, 10);
    addTrace(sprintf("[register] %d recovery codes générés (Argon2id + lookup HMAC) — affichés une seule fois", count($recoveryCodes)));

    jsonResponse([
        'message' => 'Account created',
        'username' => $username,
        'passphrase' => $passphrase,
        'recovery_codes' => $recoveryCodes,
        'note' => 'Sauvegarde ta passphrase (L1) ET tes recovery codes (possession L2) — affichés une seule fois.',
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
    // Signaux contextuels L3 : on garde trace de la dernière connexion + compteur
    $db->prepare("UPDATE users SET last_login_at = CURRENT_TIMESTAMP, login_count = COALESCE(login_count,0) + 1 WHERE id = ?")
       ->execute([$user['id']]);
    jsonResponse(['message' => 'Logged in', 'username' => $user['username']]);
}

// === RECOVER L1 ===
function handleRecoverL1(): void {
    $in = getInput();
    checkHoneypot($in); // le blocage L1 est géré par sa propre logique (l1_blocked_until) — pas le blocage IP générique qui masquerait l'escalade
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
        jsonResponse(['error' => 'Trop d\'échecs au niveau 1. Passe au niveau 2 (mot mémorisé + recovery code).', 'escalate_l2' => true], 429);
    }
    if ($user['l1_blocked_until'] && strtotime($user['l1_blocked_until']) > time()) {
        jsonResponse(['error' => 'Niveau 1 temporairement bloqué. Passe au niveau 2 (mot mémorisé + recovery code).', 'escalate_l2' => true], 429);
    }

    // 3 échecs dans la fenêtre → blocage (durées configurables : 30s en mode démo)
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM recovery_attempts
        WHERE username = ? AND level = 1 AND success = 0
        AND datetime(attempted_at) > datetime('now', ?)
    ");
    $stmt->execute([$username, '-' . L1_WINDOW_SEC . ' seconds']);
    if ((int)$stmt->fetchColumn() >= 3) {
        $db->prepare("UPDATE users SET l1_blocked_until = datetime('now', ?), l1_block_count = l1_block_count + 1 WHERE id = ?")
           ->execute(['+' . L1_BLOCK_SEC . ' seconds', $user['id']]);
        logAttempt($db, $username, 1, false);
        jsonResponse(['error' => 'Trop d\'échecs au niveau 1. Passe au niveau 2 (mot mémorisé + recovery code).', 'escalate_l2' => true], 429);
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

// === RECOVERY CODES (foyer possession portable de L2) ===
// Génère un lot de N codes, stocke lookup HMAC (recherche O(1) sans identifier) +
// Argon2id (vérif). Retourne le clair — à afficher UNE SEULE FOIS.
function generateRecoveryCodes(PDO $db, int $userId, int $n = 10): array {
    $db->prepare("DELETE FROM recovery_codes WHERE user_id = ?")->execute([$userId]); // purge lot précédent (régén)
    $ins = $db->prepare("INSERT INTO recovery_codes (user_id, code_lookup, code_hash) VALUES (?, ?, ?)");
    $codes = [];
    for ($i = 0; $i < $n; $i++) {
        $raw  = bin2hex(random_bytes(5));                        // 10 hex = 40 bits
        $code = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);  // format xxxxx-xxxxx
        $ins->execute([
            $userId,
            hash_hmac('sha256', $code, SERVER_SECRET),               // lookup O(1) (pepper)
            password_hash($code, PASSWORD_ARGON2ID, ARGON2_OPTIONS), // hash Argon2id
        ]);
        $codes[] = $code;
    }
    return $codes;
}

// === RECOVER L2 RENFORCÉ (2FA, SANS identifier) : mot mémorisé + recovery code ===
// Le recovery code (possession) LOCALISE le compte via lookup HMAC (plus besoin
// d'identifier → plus d'énumération) ET l'autorise. Le mot mémorisé (connaissance)
// est le 2e facteur. Erreur générique : ne révèle jamais lequel des deux a échoué.
function handleRecoverL2Code(): void {
    $in = getInput();
    checkHoneypot($in);
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // Escalade L2 → L3 : après 3 échecs L2 depuis cette IP dans la fenêtre, on oriente
    // vers le niveau 3 (récupération assistée par un humain) — comme L1 → L2.
    if ($ip) {
        $c = $db->prepare("SELECT COUNT(*) FROM recovery_attempts WHERE level=2 AND success=0 AND ip_address=? AND datetime(attempted_at) > datetime('now', ?)");
        $c->execute([$ip, '-' . L1_WINDOW_SEC . ' seconds']);
        if ((int)$c->fetchColumn() >= 3) {
            addTrace('[recover-l2-code] 3 échecs L2 → escalade niveau 3 (récupération assistée)');
            jsonResponse(['error' => 'Trop d\'échecs au niveau 2. Passe au niveau 3 (récupération assistée par un humain).', 'escalate_l3' => true], 429);
        }
    }
    $memorizedDerived = trim($in['memorized_derived'] ?? ''); // HMAC(domain_salt, mot) côté client
    $codeInput        = strtolower(trim($in['recovery_code'] ?? ''));
    $newPassword      = $in['new_password'] ?? '';

    addTrace(sprintf("[recover-l2-code] input — memorized_derived:%dch, recovery_code:%dch, new_password:%dch",
        strlen($memorizedDerived), strlen($codeInput), strlen($newPassword)));

    if (strlen($memorizedDerived) !== 64) jsonError('Mot mémorisé (dérivée HMAC 64 hex) requis');
    if (!preg_match('/^[a-f0-9]{5}-[a-f0-9]{5}$/', $codeInput)) jsonError('Recovery code invalide (format xxxxx-xxxxx)');
    if (strlen($newPassword) < 8) jsonError('Nouveau mot de passe: 8 caractères minimum');

    $stmt = $db->prepare("
        SELECT rc.id AS code_id, rc.code_hash, rc.used,
               u.id AS user_id, u.username, u.recovery_derived_hash
        FROM recovery_codes rc JOIN users u ON u.id = rc.user_id
        WHERE rc.code_lookup = ?
    ");
    $stmt->execute([hash_hmac('sha256', $codeInput, SERVER_SECRET)]);
    $row = $stmt->fetch();

    if (!$row || $row['used']) {
        addTrace("[recover-l2-code] code inconnu ou déjà utilisé");
        logAttempt($db, $row['username'] ?? 'unknown', 2, false); // track IP même si code inconnu (éjection)
        usleep(500000);
        jsonError('Recovery code ou mot mémorisé incorrect', 401);
    }

    $t0 = microtime(true);
    $codeOk = password_verify($codeInput, $row['code_hash']);                    // possession
    $wordOk = password_verify($memorizedDerived, $row['recovery_derived_hash']); // connaissance
    addTrace(sprintf("[recover-l2-code] Argon2id verify — code:%s, mot mémorisé:%s (%.0f ms)",
        $codeOk ? 'OK' : 'FAIL', $wordOk ? 'OK' : 'FAIL', (microtime(true) - $t0) * 1000));

    if (!$codeOk || !$wordOk) {
        logAttempt($db, $row['username'], 2, false);
        usleep(500000);
        jsonError('Recovery code ou mot mémorisé incorrect', 401); // ne dit pas lequel (2FA)
    }

    $newHash = password_hash($newPassword, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
    $db->prepare("UPDATE users SET password_hash = ?, l1_block_count = 0, l1_blocked_until = NULL WHERE id = ?")
       ->execute([$newHash, $row['user_id']]);
    $db->prepare("UPDATE recovery_codes SET used = 1, used_at = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([$row['code_id']]);
    logAttempt($db, $row['username'], 2, true);

    $stmtL = $db->prepare("SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used = 0");
    $stmtL->execute([$row['user_id']]);
    $left = (int)$stmtL->fetchColumn();
    addTrace(sprintf("[recover-l2-code] 2FA OK — reset user_id=%d, code used, %d codes restants", $row['user_id'], $left));

    jsonResponse([
        'message'           => 'Mot de passe réinitialisé (L2 2FA : mot mémorisé + recovery code)',
        'username'          => $row['username'],
        'codes_remaining'   => $left,
        'low_codes_warning' => $left <= 2,
    ]);
}

// === REGENERATE RECOVERY CODES (user authentifié par son mot mémorisé) ===
function handleRegenerateCodes(): void {
    $in = getInput();
    securityChecks($in);
    $username         = trim($in['username'] ?? '');
    $memorizedDerived = trim($in['memorized_derived'] ?? '');
    if (!$username || strlen($memorizedDerived) !== 64) jsonError('Username + mot mémorisé requis');

    $db = getDB();
    $stmt = $db->prepare("SELECT id, recovery_derived_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($memorizedDerived, $user['recovery_derived_hash'])) {
        logAttempt($db, $username, 2, false);
        usleep(500000);
        jsonError('Identifiants incorrects', 401);
    }
    $codes = generateRecoveryCodes($db, (int)$user['id'], 10);
    addTrace(sprintf("[regenerate-codes] lot régénéré (user_id=%d) — ancien lot invalidé", $user['id']));
    jsonResponse([
        'message'        => 'Recovery codes régénérés — l\'ancien lot est invalidé',
        'recovery_codes' => $codes,
    ]);
}

// === USER-SALT (R9-02) : sel par-utilisateur pour la dérivation HMAC côté client ===
// Le sel n'est PAS un secret (comme un sel bcrypt). Anti-énumération : si l'identifiant
// n'existe pas, on renvoie un sel FACTICE déterministe — indistinguable d'un vrai —
// pour ne pas révéler l'existence du compte.
function handleUserSalt(): void {
    $identifier = trim($_GET['identifier'] ?? '');
    if ($identifier === '') jsonError('Identifiant requis');
    $db = getDB();
    $stmt = $db->prepare("SELECT user_salt FROM users WHERE identifier = ?");
    $stmt->execute([$identifier]);
    $row = $stmt->fetch();
    usleep(120000); // anti-timing léger
    $salt = ($row && !empty($row['user_salt']))
        ? $row['user_salt']
        : substr(hash_hmac('sha256', $identifier, SERVER_SECRET), 0, 32); // factice déterministe (32 hex)
    jsonResponse(['salt' => $salt]);
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
        $blockedUntil = $newCount >= IP_THRESHOLD ? date('Y-m-d H:i:s', time() + IP_BLOCK_SEC) : null;
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
        jsonError('Trop de tentatives depuis votre IP. Réessayez plus tard.', 429);
    }
}


// =====================================================================
// SelfRecover L3 — récupération assistée (chat admin OBLIGATOIRE)
// =====================================================================
//
// On arrive en L3 après échec L1 (passphrase) ET L2 (mot de récup) : ces secrets
// sont oubliés, on ne les REDEMANDE PAS. Le L3 collecte un FAISCEAU DE SIGNAUX
// CONTEXTUELS vérifiables côté serveur (ce que le serveur sait déjà de l'utilisateur),
// présentés à l'admin comme des FAITS BRUTS — jamais agrégés en un score chiffré.
//
// RÈGLE ABSOLUE : ces signaux n'ouvrent JAMAIS le compte. Ils aident seulement l'admin
// à décider dans le chat. Toute récupération L3 passe par une décision humaine.

/** Hook site-spécifique : signaux d'activité propres au site (posts, achats…).
 *  Vide par défaut. Un site le surcharge pour enrichir le scoring (contexte
 *  comportemental). Format : [['label'=>str, 'ok'=>bool, 'weight'=>int], …]. */
function siteActivitySignals(array $user, array $answers, PDO $db): array {
    return []; // démo générique : aucun signal site-spécifique
}

function getDisputeByNumber(PDO $db, string $number): ?array {
    $stmt = $db->prepare("SELECT * FROM disputes WHERE dispute_number = ?");
    $stmt->execute([$number]);
    return $stmt->fetch() ?: null;
}

/** Litige actif (non clos) d'un compte. R9-01b : 'granted' compte comme actif
 *  (re-enrôlement en attente) pour empêcher l'ouverture d'un litige parallèle. */
function getActiveDispute(PDO $db, int $userId): ?array {
    $stmt = $db->prepare("SELECT * FROM disputes WHERE user_id = ? AND status IN ('open','awaiting_admin','granted') ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

/** Crée un litige avec une capability non devinable (R9-01), un sésame propriétaire
 *  (R9-01b : claim_hash) et un TTL (R9-01b : 24h). */
function createDispute(PDO $db, array $user, string $claimHash): array {
    if (!empty($user['banned_until']) && strtotime($user['banned_until']) > time()) {
        jsonError('Compte temporairement bloqué suite à un refus de litige. Réessaie plus tard.', 429);
    }
    $number = 'LIT-' . strtoupper(bin2hex(random_bytes(8)));        // R9-01 : fin de l'énumération séquentielle
    $expires = date('Y-m-d H:i:s', time() + 86400);                 // R9-01b : capability à durée de vie limitée
    $db->prepare("INSERT INTO disputes (dispute_number, user_id, identifier, status, claim_hash, expires_at) VALUES (?, ?, ?, 'open', ?, ?)")
       ->execute([$number, $user['id'], $user['identifier'] ?? '', $claimHash, $expires]);
    addTrace("[L3] litige $number ouvert (user id={$user['id']}, claim lié, TTL 24h)");
    return getDisputeByNumber($db, $number);
}

/** R9-01b : un litige expiré n'est plus accessible (capability à durée de vie limitée). */
function disputeExpired(?array $dispute): bool {
    return $dispute && !empty($dispute['expires_at']) && strtotime($dispute['expires_at']) < time();
}

/** R9-01b : vérifie le sésame du demandeur contre le claim_hash stocké (SHA-256).
 *  En L3 le propriétaire n'a PAS de session (secrets perdus) : ce sésame, généré dans
 *  SON navigateur à l'init, autorise lecture/écriture du fil — l'identifiant (semi-public)
 *  ne suffit plus. Ferme la fuite de capability. */
function disputeClaimValid(?array $dispute, string $claimSecret): bool {
    if (!$dispute || $claimSecret === '' || empty($dispute['claim_hash'])) return false;
    return hash_equals($dispute['claim_hash'], hash('sha256', $claimSecret));
}

/** Questions contextuelles posées à l'utilisateur — AUCUN secret. */
function l3Questions(): array {
    return [
        ['key' => 'creation_year',     'label' => 'En quelle année as-tu créé ce compte ?',           'type' => 'year'],
        ['key' => 'last_login_period', 'label' => 'À quand remonte ta dernière connexion ? (MM/AAAA)', 'type' => 'month'],
        ['key' => 'login_freq',        'label' => 'À peu près, combien t\'es-tu connecté ?',           'type' => 'select', 'options' => ['rare', 'regulier', 'intensif']],
    ];
}

// === RECOVER L3 INIT : identifiant → litige + questions contextuelles ===
function handleRecoverL3Init(): void {
    $in = getInput();
    securityChecks($in);
    $identifier = trim($in['identifier'] ?? '');
    $claimHash  = trim($in['claim_hash'] ?? ''); // R9-01b : SHA-256 du sésame généré côté client
    if (!$identifier) jsonError('Identifiant requis');
    if (!preg_match('/^[a-f0-9]{64}$/', $claimHash)) jsonError('claim_hash invalide (SHA-256 hex requis)');

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE identifier = ?");
    $stmt->execute([$identifier]);
    $user = $stmt->fetch();
    usleep(300000); // anti-timing / anti-énumération

    if (!$user) {
        // Ne révèle pas l'absence du compte
        jsonResponse([
            'dispute_number' => null,
            'questions' => l3Questions(),
            'note' => 'Si cet identifiant existe, un litige sera ouvert ; un administrateur tranchera.',
        ]);
    }

    // R9-01b : un litige est déjà ouvert pour ce compte → on ne RE-DIVULGUE PAS le numéro
    // au nouvel appelant (sinon la capability serait dérivable de l'identifiant semi-public).
    // L'init concurrente est enregistrée comme signal « multi-demandeur » pour l'admin.
    $existing = getActiveDispute($db, (int)$user['id']);
    if ($existing && !disputeExpired($existing)) {
        $db->prepare("UPDATE disputes SET init_collisions = init_collisions + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$existing['id']]);
        addTrace("[L3-init] litige déjà ouvert pour ce compte — numéro NON divulgué (anti-fuite de capability), signal multi-demandeur +1");
        jsonResponse([
            'dispute_number' => null,
            'already_open' => true,
            'questions' => l3Questions(),
            'note' => 'Une procédure de récupération est déjà en cours pour cet identifiant. Si c\'est toi, reprends-la avec le code de suivi enregistré sur cet appareil. Sinon, un administrateur a été alerté.',
        ]);
    }

    $dispute = createDispute($db, $user, $claimHash);
    addTrace("[L3-init] litige {$dispute['dispute_number']} ouvert — questions contextuelles renvoyées (aucun secret demandé)");
    jsonResponse([
        'dispute_number' => $dispute['dispute_number'],
        'questions' => l3Questions(),
        'note' => 'Aucun secret ne t\'est demandé. Garde le code de suivi affiché : il protège ta procédure. Un administrateur examinera ta demande.',
    ]);
}

// === RECOVER L3 : signaux contextuels → faisceau de faits bruts → chat admin ===
function handleRecoverL3(): void {
    $in = getInput();
    securityChecks($in);
    $number = trim($in['dispute_number'] ?? '');
    $claim  = trim($in['claim_secret'] ?? ''); // R9-01b : sésame d'init
    $answers = (isset($in['answers']) && is_array($in['answers'])) ? $in['answers'] : [];
    if (!$number || !$claim) jsonError('Litige et code de suivi requis (relance la procédure depuis le début).');

    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $fp = $_SERVER['HTTP_X_CLIENT_FINGERPRINT'] ?? ($in['fingerprint'] ?? '');

    $dispute = getDisputeByNumber($db, $number);
    if (!$dispute || disputeExpired($dispute) || !in_array($dispute['status'], ['open','awaiting_admin'], true)) {
        usleep(300000); jsonError('Litige introuvable ou clos', 404);
    }
    // R9-01b : seul le porteur du sésame d'init peut alimenter le faisceau de ce litige.
    if (!disputeClaimValid($dispute, $claim)) {
        if ($ip) trackSuspiciousIP($db, $ip, $fp, $_SERVER['HTTP_USER_AGENT'] ?? '');
        usleep(300000); jsonError('Code de suivi invalide', 403);
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$dispute['user_id']]);
    $user = $stmt->fetch();
    if (!$user) jsonError('Compte introuvable', 404);

    // Cooldown 1h entre soumissions L3
    $stmt = $db->prepare("SELECT attempted_at FROM recovery_attempts WHERE username = ? AND level = 3 ORDER BY attempted_at DESC LIMIT 1");
    $stmt->execute([$user['username']]);
    $last = $stmt->fetchColumn();
    if ($last && (time() - strtotime($last)) < 3600) {
        $min = (int)ceil((3600 - (time() - strtotime($last))) / 60);
        jsonError("Patiente encore {$min} min avant une nouvelle soumission.", 429);
    }

    // === FAISCEAU DE SIGNAUX (preuves factuelles, PAS un score chiffré) ===
    $signals = gatherSignals($answers, $user, $db, $ip, $fp);

    $db->prepare("UPDATE disputes SET signals_json = ?, status = 'awaiting_admin', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([json_encode($signals, JSON_UNESCAPED_UNICODE), $dispute['id']]);
    logAttempt($db, $user['username'], 3, false); // un L3 ne "réussit" jamais seul

    addTrace("[L3] faisceau collecté ({$signals['summary']}) — litige {$dispute['dispute_number']} en attente admin (AUCUN score, AUCUN mot de passe : décision humaine)");
    jsonResponse([
        'dispute_number' => $dispute['dispute_number'],
        'status' => 'awaiting_admin',
        'message' => 'Ta demande est transmise à un administrateur. Ouvre le chat pour échanger avec lui.',
        // Volontairement : zéro mot de passe. La décision est humaine.
    ]);
}

/** Collecte un FAISCEAU DE PREUVES factuelles — PAS de score chiffré (biais d'ancrage évité).
 *  Deux axes séparés pour l'admin :
 *    - PASSIF      : constaté par le serveur, l'utilisateur ne peut pas le falsifier (IP, empreinte).
 *    - DÉCLARATIF  : ce que l'utilisateur affirme connaître, comparé dit/réel (devinable → plus faible).
 *  L'admin lit les faits et décide ; rien n'est agrégé en un nombre. */
function gatherSignals(array $answers, array $user, PDO $db, string $ip, string $fp): array {
    $passive = []; $declarative = [];

    // --- PASSIF (non falsifiable par l'utilisateur) ---
    $stmt = $db->prepare("SELECT COUNT(*) FROM recovery_attempts WHERE username = ? AND ip_address = ? AND success = 1");
    $stmt->execute([$user['username'], $ip]);
    $ipCount = (int)$stmt->fetchColumn();
    $passive[] = ['label' => 'IP déjà utilisée par ce compte', 'ok' => $ipCount > 0,
                  'detail' => $ipCount > 0 ? "$ipCount connexion(s) réussie(s) depuis cette IP" : 'IP jamais vue pour ce compte'];

    $fpKnown = false;
    if ($fp) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM recovery_attempts WHERE username = ? AND fingerprint = ?");
        $stmt->execute([$user['username'], $fp]);
        $fpKnown = (int)$stmt->fetchColumn() > 0;
    }
    $passive[] = ['label' => 'Empreinte navigateur connue', 'ok' => $fpKnown,
                  'detail' => $fpKnown ? 'empreinte déjà observée' : 'empreinte inconnue'];

    // --- DÉCLARATIF (dit / réel) ---
    $dit = trim($answers['creation_year'] ?? '');
    $reel = !empty($user['created_at']) ? date('Y', strtotime($user['created_at'])) : '?';
    $declarative[] = ['label' => 'Année de création', 'dit' => $dit ?: '—', 'reel' => $reel, 'ok' => ($dit !== '' && $dit === $reel)];

    $dit = trim($answers['last_login_period'] ?? '');
    $reel = !empty($user['last_login_at']) ? date('m/Y', strtotime($user['last_login_at'])) : '?';
    $okLast = ($dit !== '' && !empty($user['last_login_at'])
        && ($dit === $reel || substr($dit, 0, 7) === date('Y-m', strtotime($user['last_login_at']))));
    $declarative[] = ['label' => 'Dernière connexion (mois)', 'dit' => $dit ?: '—', 'reel' => $reel, 'ok' => $okLast];

    $dit = trim($answers['login_freq'] ?? '');
    $lc = (int)($user['login_count'] ?? 0);
    $band = $lc < 10 ? 'rare' : ($lc < 50 ? 'regulier' : 'intensif');
    $declarative[] = ['label' => 'Fréquence d\'usage', 'dit' => $dit ?: '—', 'reel' => "$band (~$lc connexions)", 'ok' => ($dit === $band)];

    // Hook site-spécifique (déclaratif fort) : peut fournir label/dit/reel/ok
    foreach (siteActivitySignals($user, $answers, $db) as $sig) {
        $declarative[] = ['label' => $sig['label'] ?? 'activité site', 'dit' => $sig['dit'] ?? '—',
                          'reel' => $sig['reel'] ?? '—', 'ok' => !empty($sig['ok'])];
    }

    $passOk = count(array_filter($passive, fn($s) => $s['ok']));
    $declOk = count(array_filter($declarative, fn($s) => $s['ok']));
    return [
        'passive' => $passive,
        'declarative' => $declarative,
        'summary' => sprintf('%d/%d passifs · %d/%d déclaratifs concordants', $passOk, count($passive), $declOk, count($declarative)),
        'passive_ok' => $passOk, 'passive_total' => count($passive),
        'declarative_ok' => $declOk, 'declarative_total' => count($declarative),
    ];
}

// === DISPUTE CHAT (user ↔ admin, polling) ===
function handleDisputeChat(): void {
    // R9-01b : POST uniquement — le numéro + le sésame ne transitent jamais en query-string (loggable nginx).
    $in = getInput();
    securityChecks($in); // honeypot + IP bloquée
    $number = trim($in['dispute_number'] ?? '');
    if (!$number) jsonError('Numéro de litige requis');

    $db = getDB();
    $dispute = getDisputeByNumber($db, $number);
    if (!$dispute || $dispute['status'] === 'closed' || disputeExpired($dispute)) {
        // tentative sur un numéro inexistant/expiré = suspecte (anti-énumération/brute du numéro)
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip) trackSuspiciousIP($db, $ip, $_SERVER['HTTP_X_CLIENT_FINGERPRINT'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '');
        jsonError('Litige introuvable ou fermé', 404);
    }

    // R9-01b : autorisation du fil — soit l'admin (token), soit le propriétaire (sésame d'init).
    // L'identifiant seul (semi-public) ne donne plus accès au chat ni au verdict.
    $adminTok = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($in['admin_token'] ?? '');
    $expectedTok = adminTokenExpected(); // R9-05 : jeton via env uniquement, aucun fallback en dur
    $isAdmin = ($expectedTok !== null && $adminTok !== '' && hash_equals($expectedTok, (string)$adminTok));
    if (!$isAdmin && !disputeClaimValid($dispute, trim($in['claim_secret'] ?? ''))) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip) trackSuspiciousIP($db, $ip, $_SERVER['HTTP_X_CLIENT_FINGERPRINT'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '');
        jsonError('Accès au litige refusé (code de suivi invalide)', 403);
    }

    // Lecture (polling) = absence de champ 'message' ; écriture sinon.
    if (!isset($in['message'])) {
        $stmt = $db->prepare("SELECT sender, body, created_at FROM dispute_messages WHERE dispute_id = ? ORDER BY created_at ASC");
        $stmt->execute([$dispute['id']]);
        jsonResponse(['status' => $dispute['status'], 'messages' => $stmt->fetchAll()]);
    }

    $body = trim($in['message'] ?? '');
    if ($body === '') jsonError('Message vide');
    if (mb_strlen($body) > 2000) jsonError('Message trop long');
    $sender = $isAdmin ? 'admin' : 'user';
    $db->prepare("INSERT INTO dispute_messages (dispute_id, sender, body) VALUES (?, ?, ?)")
       ->execute([$dispute['id'], $sender, $body]);
    jsonResponse(['sent' => true, 'sender' => $sender]);
}

// === ADMIN — litiges (auth minimale démo : header X-Admin-Token) ===
/** Jeton admin attendu : variable d'environnement UNIQUEMENT (R9-05, fail-closed — aucun fallback en dur). */
function adminTokenExpected(): ?string {
    $t = getenv('SELFRECOVER_ADMIN_TOKEN');
    return ($t === false || $t === '') ? null : $t;
}

function requireAdmin(): void {
    $token = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($_GET['admin_token'] ?? '');
    $expected = adminTokenExpected();
    if ($expected === null) jsonError('Console admin non configurée (SELFRECOVER_ADMIN_TOKEN absent)', 503);
    if (!hash_equals($expected, (string)$token)) jsonError('Accès admin refusé', 403);
}

function handleAdminDisputes(): void {
    requireAdmin();
    $db = getDB();
    $rows = $db->query("
        SELECT d.dispute_number, d.status, d.signals_json, d.refusal_count, d.init_collisions, d.created_at,
               u.username, u.identifier, u.created_at AS account_created, u.last_login_at, u.login_count
        FROM disputes d JOIN users u ON u.id = d.user_id
        WHERE d.status != 'closed' ORDER BY d.updated_at DESC
    ")->fetchAll();
    foreach ($rows as &$r) { $r['signals'] = json_decode($r['signals_json'] ?? '{}', true); unset($r['signals_json']); }
    jsonResponse(['disputes' => $rows]);
}

function handleAdminDisputeDecide(): void {
    requireAdmin();
    $in = getInput();
    $number = trim($in['dispute_number'] ?? '');
    $decision = $in['decision'] ?? ''; // 'grant' | 'refuse'
    $db = getDB();
    $dispute = getDisputeByNumber($db, $number);
    if (!$dispute) jsonError('Litige introuvable', 404);

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$dispute['user_id']]);
    $user = $stmt->fetch();
    if (!$user) jsonError('Utilisateur introuvable', 404);

    if ($decision === 'grant') {
        // R9-01b : AUCUN credential livré par le chat. L'admin confirme l'identité ;
        // le compte n'est PAS réinitialisé ici. Le propriétaire (porteur du sésame d'init)
        // re-définit lui-même son secret via l3-reset → le serveur ne génère ni ne diffuse de mot de passe.
        $db->prepare("UPDATE disputes SET status = 'granted', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$dispute['id']]);
        $db->prepare("INSERT INTO dispute_messages (dispute_id, sender, body) VALUES (?, 'admin', ?)")
           ->execute([$dispute['id'], "Identité confirmée. Tu peux maintenant définir un nouveau mot de passe depuis ta page de récupération (bouton « Reprendre l'accès »). Aucun mot de passe n'est transmis par ce canal."]);
        addTrace("[L3-admin] litige $number ACCORDÉ — statut 'granted' (re-enrôlement par le propriétaire ; aucun mot de passe généré ni posté dans le chat)");
        jsonResponse(['decision' => 'granted', 'note' => 'Le demandeur va re-définir lui-même son secret. Aucun mot de passe généré ni transmis côté serveur.']);
    }

    if ($decision === 'refuse') {
        $refusals = (int)$dispute['refusal_count'] + 1;
        if ($refusals >= 3) {
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$user['id']]);
            $db->prepare("UPDATE disputes SET status = 'closed', refusal_count = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
               ->execute([$refusals, $dispute['id']]);
            addTrace("[L3-admin] litige $number 3e REFUS → compte supprimé (identifiant libéré)");
            jsonResponse(['decision' => 'refused', 'account_deleted' => true, 'refusal_count' => $refusals]);
        }
        $bannedUntil = date('Y-m-d H:i:s', time() + 86400);
        $db->prepare("UPDATE users SET banned_until = ? WHERE id = ?")->execute([$bannedUntil, $user['id']]);
        $db->prepare("UPDATE disputes SET status = 'refused', refusal_count = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$refusals, $dispute['id']]);
        $db->prepare("INSERT INTO dispute_messages (dispute_id, sender, body) VALUES (?, 'admin', ?)")
           ->execute([$dispute['id'], "Preuve insuffisante. Nouvelle tentative possible dans 24h (refus $refusals/3)."]);
        addTrace("[L3-admin] litige $number REFUSÉ ($refusals/3) — ban 24h");
        jsonResponse(['decision' => 'refused', 'refusal_count' => $refusals, 'banned_24h' => true]);
    }

    jsonError('Décision invalide (grant|refuse)');
}

// === RECOVER L3 — RESET : re-enrôlement par le propriétaire après accord admin ===
// R9-01b : ferme la chaîne d'ATO. Aucun mot de passe n'est généré ni diffusé par le serveur ;
// le propriétaire — identifié par le sésame (claim_secret) de SON init — choisit lui-même un
// nouveau secret, exactement comme à l'inscription. La capability est consommée (one-shot).
function handleL3Reset(): void {
    $in = getInput();
    securityChecks($in);
    $number   = trim($in['dispute_number'] ?? '');
    $claim    = trim($in['claim_secret'] ?? '');
    $password = $in['password'] ?? '';
    $recoveryDerivedKey = $in['recovery_derived_key'] ?? ''; // déjà HMAC-dérivé côté client
    $userSalt = $in['user_salt'] ?? '';

    if (!$number || !$claim) jsonError('Litige et code de suivi requis', 400);
    if (strlen($password) < 8) jsonError('Mot de passe : 8 caractères minimum');
    if (!$recoveryDerivedKey) jsonError('Nouveau mot de récupération requis');
    if (!preg_match('/^[a-f0-9]{32}$/', $userSalt)) jsonError('user_salt invalide (32 hex requis)');

    $db = getDB();
    $dispute = getDisputeByNumber($db, $number);
    if (!$dispute || disputeExpired($dispute)) { usleep(200000); jsonError('Litige introuvable ou expiré', 404); }
    if (!disputeClaimValid($dispute, $claim)) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip) trackSuspiciousIP($db, $ip, $_SERVER['HTTP_X_CLIENT_FINGERPRINT'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '');
        usleep(200000); jsonError('Code de suivi invalide', 403);
    }
    if ($dispute['status'] !== 'granted') jsonError('Cette procédure n\'a pas (encore) été accordée par un administrateur.', 409);

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$dispute['user_id']]);
    $user = $stmt->fetch();
    if (!$user) jsonError('Compte introuvable', 404);

    // Re-enrôlement (même mécanisme que l'inscription) : secrets choisis par le propriétaire.
    // L1 (passphrase) régénérée car perdue ; renvoyée une seule fois, jamais via le chat.
    $newPassphrase = generateDiceware(4);
    $pwdHash = password_hash($password, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
    $ppHash  = password_hash($newPassphrase, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
    $rcHash  = password_hash($recoveryDerivedKey, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
    $db->prepare("UPDATE users SET password_hash = ?, passphrase_hash = ?, recovery_derived_hash = ?, user_salt = ?, l1_block_count = 0, l1_blocked_until = NULL, banned_until = NULL WHERE id = ?")
       ->execute([$pwdHash, $ppHash, $rcHash, $userSalt, $user['id']]);

    // Capability consommée : claim invalidé (one-shot) + litige clos.
    $db->prepare("UPDATE disputes SET status = 'resolved', claim_hash = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$dispute['id']]);
    $db->prepare("INSERT INTO dispute_messages (dispute_id, sender, body) VALUES (?, 'admin', ?)")
       ->execute([$dispute['id'], "Accès rétabli par le propriétaire (re-enrôlement). Litige clos."]);
    logAttempt($db, $user['username'], 3, true);
    addTrace("[L3-reset] litige $number — propriétaire a re-défini ses secrets (L1 régénérée, L2/login renouvelés). Sésame consommé (one-shot).");

    jsonResponse([
        'message' => 'Accès rétabli. Ton nouveau mot de passe est actif.',
        'passphrase' => $newPassphrase,
        'note' => 'Note ta nouvelle passphrase L1 — elle ne sera plus affichée. Le serveur n\'a ni généré ni diffusé de mot de passe : tu l\'as choisi toi-même.',
    ]);
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
    $userSalt = $in['user_salt'] ?? ''; // R9-02

    addTrace(sprintf("[lite-register] input — username:%dch, email:%dch, password:%dch, memorized_derived:%dch",
        strlen($username), strlen($email), strlen($password), strlen($memorizedDerived)));

    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) jsonError('Username: 3-20 chars alphanumeric/underscore');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Email invalide');
    if (strlen($password) < 8) jsonError('Password: 8 chars minimum');
    if (strlen($memorizedDerived) !== 64) jsonError('Memorized word derivation must be 64 hex chars (HMAC-SHA256)');
    if (!preg_match('/^[a-f0-9]{32}$/', $userSalt)) jsonError('user_salt invalide (32 hex requis)');

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
                           recovery_derived_hash, email, memorized_word_hash, user_salt)
        VALUES (?, ?, ?, '', '', ?, ?, ?)
    ");
    $stmt->execute([$username, $placeholderId, $pwdHash, $email, $memHash, $userSalt]);
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
    $stmt = $db->prepare("SELECT r.id, r.salt, r.expires_at, r.used, u.user_salt
                          FROM reset_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ?");
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
        'user_salt' => $req['user_salt'], // R9-02 : sel par-utilisateur pour la dérivation client
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
