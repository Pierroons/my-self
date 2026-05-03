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
    $words = array_values(array_filter($words, 'strlen'));
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
    $username = trim($in['username'] ?? '');
    $identifier = trim($in['identifier'] ?? '');
    $password = $in['password'] ?? '';
    $recoveryDerivedKey = $in['recovery_derived_key'] ?? '';

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

    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR identifier = ?");
    $stmt->execute([$username, $identifier]);
    if ($stmt->fetch()) jsonError('Username or identifier already taken');

    // Generate passphrase server-side (diceware)
    $passphrase = generateDiceware(4);

    $pwdHash = password_hash($password, PASSWORD_BCRYPT);
    $ppHash = password_hash($passphrase, PASSWORD_BCRYPT);
    $rcHash = password_hash($recoveryDerivedKey, PASSWORD_BCRYPT);

    $stmt = $db->prepare("
        INSERT INTO users (username, identifier, password_hash, passphrase_hash, recovery_derived_hash)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$username, $identifier, $pwdHash, $ppHash, $rcHash]);

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
    $username = trim($in['username'] ?? '');
    $password = $in['password'] ?? '';
    if (!$username || !$password) jsonError('Username and password required');

    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonError('Invalid credentials', 401);
    }
    jsonResponse(['message' => 'Logged in', 'username' => $user['username']]);
}

// === RECOVER L1 ===
function handleRecoverL1(): void {
    $in = getInput();
    $username = trim($in['username'] ?? '');
    $passphrase = trim($in['passphrase'] ?? '');
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

    if (!password_verify($passphrase, $user['passphrase_hash'])) {
        logAttempt($db, $username, 1, false);
        jsonError('Incorrect passphrase', 401);
    }

    $newPwd = generatePassword();
    $newHash = password_hash($newPwd, PASSWORD_BCRYPT);
    $db->prepare("UPDATE users SET password_hash = ?, l1_block_count = 0, l1_blocked_until = NULL WHERE id = ?")
       ->execute([$newHash, $user['id']]);
    logAttempt($db, $username, 1, true);

    jsonResponse([
        'message' => 'Password reset',
        'new_password' => $newPwd,
    ]);
}

// === RECOVER L2 ===
function handleRecoverL2(): void {
    $in = getInput();
    $identifier = trim($in['identifier'] ?? '');
    $recoveryKey = trim($in['recovery_key'] ?? ''); // Already HMAC-derived client-side

    if (!$identifier || !$recoveryKey) jsonError('Identifier and recovery key required');

    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, recovery_derived_hash FROM users WHERE identifier = ?");
    $stmt->execute([$identifier]);
    $user = $stmt->fetch();
    if (!$user) {
        sleep(1);
        jsonError('Invalid credentials', 401);
    }

    if (!password_verify($recoveryKey, $user['recovery_derived_hash'])) {
        logAttempt($db, $user['username'], 2, false);
        jsonError('Incorrect recovery word', 401);
    }

    $newPwd = generatePassword();
    $newHash = password_hash($newPwd, PASSWORD_BCRYPT);
    $db->prepare("UPDATE users SET password_hash = ?, l1_block_count = 0, l1_blocked_until = NULL WHERE id = ?")
       ->execute([$newHash, $user['id']]);
    logAttempt($db, $user['username'], 2, true);

    jsonResponse([
        'message' => 'Password reset via L2',
        'new_password' => $newPwd,
    ]);
}

function logAttempt(PDO $db, string $username, int $level, bool $success): void {
    $db->prepare("INSERT INTO recovery_attempts (username, level, success) VALUES (?, ?, ?)")
       ->execute([$username, $level, $success ? 1 : 0]);
}
