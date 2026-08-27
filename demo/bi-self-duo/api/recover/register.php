<?php
/**
 * SelfRecover demo — Inscription.
 *
 * POST /demo/api/recover/register
 *   body: { "username": "alice", "recovery_derived_key": "<64 hex>", "recovery_salt": "<32 hex>" }
 *   → génère password random + passphrase diceware
 *   → Argon2id triplet (password, passphrase, derived_key)
 *   → INSERT accounts
 *   → retourne les credentials en clair pour que l'user les copie
 *
 * 🔑 **Le mot mémorisé n'arrive pas ici, et c'est le sujet.** Le navigateur le
 * garde, en dérive `HMAC(clé = mot, message = hostname|v2 + sel)` par
 * `srDerive`, et n'envoie que l'empreinte. Le serveur en range un Argon2id ; il
 * ne peut pas remonter au mot, même s'il le voulait.
 *
 * Jusqu'au 27/08/2026 cette route recevait le mot en clair et dérivait
 * elle-même, « pour la démo ». Ce que la démo montrait alors, c'est le geste
 * qu'il ne faut pas copier — et elle affirmait à l'écran le contraire.
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
$username     = is_array($body) ? (string) ($body['username'] ?? '') : '';
$derivedKey   = is_array($body) ? strtolower(trim((string) ($body['recovery_derived_key'] ?? ''))) : '';
$recoverySalt = is_array($body) ? trim((string) ($body['recovery_salt'] ?? '')) : '';

// Validation username
if (!preg_match('/^[a-z0-9]{3,20}$/', $username)) {
    $s->logger()->error('register', 'Username invalide', ['username' => $username]);
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_username','message'=>"L'identifiant doit faire 3 à 20 caractères minuscules alphanumériques."]);
    exit;
}

// 🔑 La forme de la clé dérivée est la seule chose que le serveur puisse
// contrôler, et elle est ce qui tient la promesse : sans ce refus, un client
// resté sur une version antérieure enverrait le mot en clair et le serveur
// l'accepterait sans que rien ne le signale.
if (!RecoverHelper::isDerivedKey($derivedKey)) {
    $s->logger()->error('register', 'Clé dérivée invalide — le mot doit être dérivé dans le navigateur');
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_derived_key','message'=>"La dérivation doit se faire dans ton navigateur : 64 caractères hexadécimaux attendus."]);
    exit;
}

// Le sel est EXIGÉ. Le tolérer vide rouvrirait ce qu'il ferme : deux personnes
// au même mot mémorisé, une seule empreinte, et une table précalculée qui sert
// pour tout le service. Il n'est pas secret, mais il doit exister.
if (!preg_match('/^[0-9a-f]{32}$/', $recoverySalt)) {
    $s->logger()->error('register', 'Sel de dérivation invalide');
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_salt','message'=>"Sel de dérivation invalide : 32 caractères hexadécimaux attendus."]);
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

$diceware = DicewareWordlist::generate(4, 'en');
$passphrase = implode(' ', $diceware['words']);
$log->info('register', 'Passphrase diceware générée depuis la liste officielle EFF (7776 mots, CC-BY 3.0)', [
    'words_count'  => count($diceware['words']),
    'entropy_bits' => $diceware['entropy_bits'],
    'wordlist'     => 'EFF large wordlist (2016)',
    'note'         => 'Mode avancé disponible : l\'utilisateur peut saisir ses propres 6+ mots pour monter jusqu\'à 77 bits (EFF recommandé) ou 103 bits (paranoïaque).',
]);

$log->crypto('register', 'Le mot mémorisé est arrivé DÉJÀ dérivé', [
    'derived_key' => $derivedKey,       // sera tronqué par le Redactor
    'sel_du_compte' => $recoverySalt,
    'formule'     => 'HMAC-SHA256(clé = mot mémorisé, message = hostname|v2 + sel)',
    'note'        => "Le mot lui-même n'a pas traversé le réseau : ton navigateur l'a gardé. Le hostname employé est celui qu'IL a lu — une page qui imite ce service porte un autre nom d'hôte, donc elle fait dériver une autre clé.",
]);

// Argon2id des trois secrets
$t0 = microtime(true);
$pwHash = RecoverHelper::hash($password);
$t1 = microtime(true);
$log->crypto('register', 'argon2id(password) — m=64 Mo, t=4, p=2', ['duration_ms' => (int) (($t1 - $t0) * 1000)]);

$t2 = microtime(true);
$passHash = RecoverHelper::hash($passphrase);
$t3 = microtime(true);
$log->crypto('register', 'argon2id(passphrase) — m=64 Mo, t=4, p=2', ['duration_ms' => (int) (($t3 - $t2) * 1000)]);

$t4 = microtime(true);
$recoveryHash = RecoverHelper::hash($derivedKey);
$t5 = microtime(true);
$log->crypto('register', 'argon2id(derived_key) — m=64 Mo, t=4, p=2', ['duration_ms' => (int) (($t5 - $t4) * 1000)]);

// INSERT
$stmt = $db->prepare('
    INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, recovery_salt, created_at)
    VALUES (:u, :pw, :pass, :rec, :sel, :t)
');
$stmt->bindValue(':u',    $username);
$stmt->bindValue(':pw',   $pwHash);
$stmt->bindValue(':pass', $passHash);
$stmt->bindValue(':rec',  $recoveryHash);
$stmt->bindValue(':sel',  $recoverySalt);
$stmt->bindValue(':t',    time());
$stmt->execute();
$accountId = $db->lastInsertRowID();

$log->info('register', "INSERT INTO accounts", ['id' => $accountId, 'username' => $username]);

// ── Lot de codes de secours — le facteur de possession portable du niveau 2 ──
//
// 🔑 Générés d'office, jamais à la demande. Un utilisateur qui doit penser à
// les créer ne les a pas le jour où il en a besoin — et ce jour-là, il ne peut
// plus se connecter pour les demander. Les remettre à l'inscription est le seul
// moment où l'on est sûr qu'il y a quelqu'un pour les lire.
//
// ⚠️ Affichés une seule fois. Le serveur n'en garde qu'un HMAC (pour retrouver
// le compte) et un Argon2id (pour vérifier) : il est incapable de les réafficher,
// et c'est voulu.
$codes   = [];
$insCode = $db->prepare(
    'INSERT INTO recovery_codes (account_id, code_lookup, code_hash, created_at) VALUES (:a, :l, :h, :t)'
);
$tCodes = microtime(true);
for ($i = 0; $i < 10; $i++) {
    $brut = bin2hex(random_bytes(5));                                  // 40 bits
    $code = substr($brut, 0, 5) . '-' . substr($brut, 5, 5);           // xxxxx-xxxxx
    $insCode->reset();
    $insCode->bindValue(':a', $accountId, SQLITE3_INTEGER);
    $insCode->bindValue(':l', RecoverHelper::indexCode($s, $code));
    $insCode->bindValue(':h', RecoverHelper::hash($code));
    $insCode->bindValue(':t', time(), SQLITE3_INTEGER);
    $insCode->execute();
    $codes[] = $code;
}
$log->crypto('register', '10 codes de secours générés', [
    'duration_ms' => (int) ((microtime(true) - $tCodes) * 1000),
    'entropie'    => '40 bits chacun (5 octets aléatoires)',
    'stockage'    => 'HMAC-SHA256 pour la recherche sans identifiant + Argon2id pour la vérification',
    'note'        => "Le serveur ne peut pas les réafficher : il n'en détient aucune forme réversible.",
]);

$log->success('register', 'HTTP 201 — compte créé', ['account_id' => $accountId, 'codes' => 10]);

echo json_encode([
    'ok'          => true,
    'account_id'  => $accountId,
    'username'    => $username,
    'credentials' => [
        'password'       => $password,
        'passphrase'     => $passphrase,
        'recovery_codes' => $codes,
    ],
    // Le mot mémorisé ne figure pas dans cette réponse, et ne le peut pas : le
    // serveur ne l'a jamais reçu. C'est ton navigateur qui te l'affiche.
    'note' => 'Copie tes credentials ET tes 10 codes de secours maintenant. Le serveur ne les montrera plus en clair. Pour les hash Argon2id, regarde les logs.',
], JSON_UNESCAPED_UNICODE);
