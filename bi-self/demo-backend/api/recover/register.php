<?php
/**
 * SelfRecover demo — Inscription.
 *
 * POST /demo/api/recover/register
 *   body: { "username": "alice" }
 *   → génère password random + passphrase diceware + recovery word random
 *   → HMAC-SHA256 sur le recovery word (côté serveur pour démo)
 *   → bcrypt triplet (password, passphrase, derived_key)
 *   → INSERT accounts
 *   → retourne les credentials en clair pour que l'user les copie
 *
 * En vrai SelfRecover : le recovery word est choisi par l'user, le HMAC est fait
 * côté client. Ici pour la démo on simule tout côté serveur pour pouvoir montrer
 * les logs détaillés (on refait l'exercice côté client lors de l'étape recover-L2).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/session_manager.php';
require_once __DIR__ . '/../../lib/recover_helper.php';

header('Content-Type: application/json; charset=utf-8');

$s = DemoSession::current();
if ($s === null) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'no_session']); exit; }

if (!RateLimit::checkAndIncrementActions($s->dir)) {
    http_response_code(429);
    echo json_encode(['ok'=>false,'error'=>'quota_exceeded','message'=>'Tu as atteint les 50 actions de cette session. Recharge la page pour en ouvrir une nouvelle.']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
$username = is_array($body) ? (string) ($body['username'] ?? '') : '';

// Validation username
if (!preg_match('/^[a-z0-9]{3,20}$/', $username)) {
    $s->logger()->error('register', 'Username invalide', ['username' => $username]);
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_username','message'=>"L'identifiant doit faire 3 à 20 caractères minuscules alphanumériques."]);
    exit;
}

$log = $s->logger();
$log->info('register', "POST /demo/api/recover/register");
$log->info('register', "Body parsed", ['username' => $username]);

// Vérifie unicité
$db = $s->db();
$stmt = $db->prepare('SELECT 1 FROM accounts WHERE username = :u');
$stmt->bindValue(':u', $username);
if ($stmt->execute()->fetchArray()) {
    $log->warning('register', 'Username déjà pris dans cette session', ['username' => $username]);
    http_response_code(409);
    echo json_encode(['ok'=>false,'error'=>'username_taken','message'=>'Cet identifiant est déjà pris dans ta session démo. Essaie-en un autre.']);
    exit;
}

// Génère les secrets
$password = RecoverHelper::generatePassword(16);
$log->info('register', 'Password généré côté serveur (16 chars alphanum + symbols)');

$diceware = DicewareWordlist::generate(4);
$passphrase = implode(' ', $diceware['words']);
$log->info('register', 'Passphrase diceware générée', [
    'words_count'  => count($diceware['words']),
    'entropy_bits' => $diceware['entropy_bits'],
    'is_demo_list' => $diceware['is_demo'],
    'note'         => 'La vraie EFF wordlist donne 51 bits pour 4 mots. Ici 32 bits pour la démo (mots plus courts / mémorisables).',
]);

// Recovery word : 6 chars alphanum, pour la démo. En vrai l'user choisit son propre mot.
$recoveryWord = bin2hex(random_bytes(3)); // 6 hex chars
$log->info('register', 'Recovery word généré côté serveur pour la démo', [
    'recovery_word' => $recoveryWord,
    'note'          => "Dans le vrai SelfRecover, c'est TOI qui choisis ce mot. On le génère ici pour montrer le flux.",
]);

// Calcul HMAC côté serveur (simulation)
$domain = 'bi-self.my-self.fr';
$siteSalt = RecoverHelper::siteSalt($s);
$log->crypto('register', 'HMAC-SHA256 derivation', [
    'input'     => $recoveryWord,
    'key_material' => $domain . '[SITE_SALT]',
    'note'      => 'Le site_salt est propre à chaque déploiement. Il change tout : un phishing site aurait un autre salt et donc une autre dérivée.',
]);
$derivedKey = RecoverHelper::deriveKey($recoveryWord, $domain, $siteSalt);
$log->crypto('register', 'derived_key = HMAC(recovery_word, domain || site_salt)', [
    'derived_key' => $derivedKey, // sera tronqué par le Redactor
]);

// Bcrypt des trois secrets
$t0 = microtime(true);
$pwHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$t1 = microtime(true);
$log->crypto('register', 'bcrypt(password, cost=12)', ['duration_ms' => (int) (($t1 - $t0) * 1000)]);

$t2 = microtime(true);
$passHash = password_hash($passphrase, PASSWORD_BCRYPT, ['cost' => 12]);
$t3 = microtime(true);
$log->crypto('register', 'bcrypt(passphrase, cost=12)', ['duration_ms' => (int) (($t3 - $t2) * 1000)]);

$t4 = microtime(true);
$recoveryHash = password_hash($derivedKey, PASSWORD_BCRYPT, ['cost' => 12]);
$t5 = microtime(true);
$log->crypto('register', 'bcrypt(derived_key, cost=12)', ['duration_ms' => (int) (($t5 - $t4) * 1000)]);

// INSERT
$stmt = $db->prepare('
    INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, recovery_word, created_at)
    VALUES (:u, :pw, :pass, :rec, :word, :t)
');
$stmt->bindValue(':u',    $username);
$stmt->bindValue(':pw',   $pwHash);
$stmt->bindValue(':pass', $passHash);
$stmt->bindValue(':rec',  $recoveryHash);
$stmt->bindValue(':word', $recoveryWord);
$stmt->bindValue(':t',    time());
$stmt->execute();
$accountId = $db->lastInsertRowID();

$log->info('register', "INSERT INTO accounts", ['id' => $accountId, 'username' => $username]);
$log->success('register', 'HTTP 201 — compte créé', ['account_id' => $accountId]);

echo json_encode([
    'ok'          => true,
    'account_id'  => $accountId,
    'username'    => $username,
    'credentials' => [
        'password'       => $password,
        'passphrase'     => $passphrase,
        'recovery_word'  => $recoveryWord,
    ],
    'note' => 'Copie tes credentials maintenant. Le serveur ne les montrera plus en clair. Pour les bcrypt hashés, regarde les logs.',
], JSON_UNESCAPED_UNICODE);
