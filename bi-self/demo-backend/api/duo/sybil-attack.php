<?php
/**
 * Bi-Self demo — Attaque Sybil simulée.
 *
 * Démo de la synergie SelfRecover + SelfModerate : un attaquant tente de créer
 * 5 faux comptes SelfRecover puis de les utiliser pour pack-voter dans
 * SelfModerate. Chacun des deux modules pris isolément laisserait passer
 * une partie de l'attaque. Ensemble, ils la neutralisent :
 *
 *   - SelfRecover : chaque compte nécessite une passphrase diceware distincte
 *     et un bcrypt cost=12 (~250ms × 5 = ~1.25s de ralentissement). Ça ne
 *     bloque pas totalement mais rend l'attaque coûteuse à scaler.
 *   - SelfModerate : les 5 votes coordonnés déclenchent pack-voting
 *     detection → votes annulés, réputation restaurée.
 *
 * L'attaque doit être lancée sur une session selfmoderate existante (le
 * visiteur doit avoir créé son identité + les 5 bots doivent exister).
 *
 * POST /demo/api/duo/sybil-attack
 *   body: { "target_id": <int> }
 *   → execute les 3 phases et renvoie un résumé complet.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/session_manager.php';
require_once __DIR__ . '/../../lib/moderate_helper.php';
require_once __DIR__ . '/../../lib/recover_helper.php';
require_once __DIR__ . '/../../lib/diceware/wordlist.php';

header('Content-Type: application/json; charset=utf-8');

$s = DemoSession::current();
if ($s === null) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'no_session']); exit; }

if ($s->module !== 'selfmoderate') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'wrong_module','message'=>'Cette attaque nécessite une session selfmoderate (lance-la depuis /moderate avant).']);
    exit;
}

if (!RateLimit::checkAndIncrementActions($s->dir)) {
    http_response_code(429);
    echo json_encode(['ok'=>false,'error'=>'quota_exceeded']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
$targetId = (int) ($body['target_id'] ?? 0);
if ($targetId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid_target']); exit; }

$log = $s->logger();
$db = $s->db();

$stmt = $db->prepare('SELECT username, reputation FROM users WHERE id = :id');
$stmt->bindValue(':id', $targetId);
$target = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if (!is_array($target)) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'target_not_found']); exit; }

$targetStart = (int) $target['reputation'];

// ------------------------------------------------------------
// Phase 1 : Tentative de création de 5 identités SelfRecover
// ------------------------------------------------------------
$log->warning('sybil', '⚔ ATTAQUE SYBIL SIMULÉE contre ' . $target['username']);
$log->info('sybil', 'Phase 1/3 — L\'attaquant tente de créer 5 faux comptes SelfRecover');

$sybilAccounts = [];
$totalBcryptMs = 0;

for ($i = 1; $i <= 5; $i++) {
    $username = 'sybil_' . $i . '_' . bin2hex(random_bytes(2));
    $password = RecoverHelper::generatePassword(16);
    $diceware = DicewareWordlist::generate(4, 'en');
    $passphrase = implode(' ', $diceware['words']);
    $recoveryWord = bin2hex(random_bytes(3));

    $domain = 'bi-self.my-self.fr';
    $siteSalt = RecoverHelper::siteSalt($s);
    $derivedKey = RecoverHelper::deriveKey($recoveryWord, $domain, $siteSalt);

    $t0 = microtime(true);
    $pwHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $passHash = password_hash($passphrase, PASSWORD_BCRYPT, ['cost' => 12]);
    $recoveryHash = password_hash($derivedKey, PASSWORD_BCRYPT, ['cost' => 12]);
    $totalBcryptMs += (int) ((microtime(true) - $t0) * 1000);

    // Dans ce schéma moderate, pas de stockage SelfRecover — on log juste la tentative.
    $log->crypto('sybil', "Compte Sybil #{$i} généré : {$username}", [
        'passphrase_entropy_bits' => $diceware['entropy_bits'],
        'cumulative_bcrypt_ms'    => $totalBcryptMs,
    ]);

    // Création de l'user côté SelfModerate (comme s'il avait enregistré)
    $stmt = $db->prepare('INSERT INTO users (username, is_bot, bot_profile, reputation, created_at) VALUES (:u, 0, NULL, 20, :t)');
    $stmt->bindValue(':u', '@' . $username);
    $stmt->bindValue(':t', time());
    $stmt->execute();
    $sybilId = $db->lastInsertRowID();
    $sybilAccounts[] = ['id' => (int) $sybilId, 'username' => '@' . $username];
}

$log->warning('sybil', sprintf('Phase 1 terminée — 5 comptes créés avec %d ms de bcrypt cumulé (ralentissement SelfRecover)', $totalBcryptMs));
$log->info('sybil', 'SelfRecover ne bloque pas l\'attaque, mais impose un coût cryptographique. À 100 comptes → ~25 secondes de crunch.');

// ------------------------------------------------------------
// Phase 2 : Les 5 Sybils votent -1 coordonnés contre la cible
// ------------------------------------------------------------
$log->warning('sybil', 'Phase 2/3 — Les 5 Sybils coordonnent un pack-voting');

$appliedVotes = 0;
foreach ($sybilAccounts as $sybil) {
    // Crée une invitation acceptée entre le Sybil et la cible
    $stmt = $db->prepare('INSERT INTO invitations (from_user, to_user, accepted_at) VALUES (:a, :b, :t)');
    $stmt->bindValue(':a', $sybil['id']);
    $stmt->bindValue(':b', $targetId);
    $stmt->bindValue(':t', time() - 60);
    $stmt->execute();
    $invId = $db->lastInsertRowID();

    // Crée aussi des invitations entre les Sybils pour qu'ils aient le graph de cross-invitations
    foreach ($sybilAccounts as $other) {
        if ($other['id'] === $sybil['id']) continue;
        $stmt2 = $db->prepare('INSERT OR IGNORE INTO invitations (from_user, to_user, accepted_at) VALUES (:a, :b, :t)');
        $stmt2->bindValue(':a', $sybil['id']);
        $stmt2->bindValue(':b', $other['id']);
        $stmt2->bindValue(':t', time() - 120);
        $stmt2->execute();
    }

    $res = ModerateHelper::applyVote($s, $invId, $sybil['id'], $targetId, -1, "sybil attack coordinated vote");
    if ($res['ok'] && empty($res['blocked'])) {
        $appliedVotes++;
    }
    usleep(100000);
}
$log->info('sybil', "Phase 2 terminée — {$appliedVotes} votes -1 appliqués (réputation cible en baisse)");

// ------------------------------------------------------------
// Phase 3 : SelfModerate détecte pack-voting et annule
// ------------------------------------------------------------
$log->info('sybil', 'Phase 3/3 — SelfModerate scanne les votes récents pour pack-voting');
$detection = ModerateHelper::detectPackVoting($s);

// État final de la cible
$stmt = $db->prepare('SELECT reputation FROM users WHERE id = :id');
$stmt->bindValue(':id', $targetId);
$finalRow = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
$finalRep = (int) ($finalRow['reputation'] ?? $targetStart);

if ($detection['pack_detected']) {
    $log->success('sybil', '✓ ATTAQUE SYBIL NEUTRALISÉE', [
        'votes_cancelled'    => $detection['cancelled_votes'],
        'reputation_restored'=> $finalRep,
        'target_start_rep'   => $targetStart,
    ]);
    $verdict = 'defeated';
} else {
    $log->error('sybil', 'Attaque passée (bug démo ?)', [
        'reputation_final' => $finalRep,
    ]);
    $verdict = 'succeeded';
}

// Nettoyage : on laisse les comptes Sybil pour visualisation dans la liste des users
// (ils apparaîtront en is_bot=0 avec @sybil_... — pédagogique)

echo json_encode([
    'ok'                    => true,
    'verdict'               => $verdict,
    'sybil_accounts_created'=> count($sybilAccounts),
    'total_bcrypt_ms'       => $totalBcryptMs,
    'votes_attempted'       => $appliedVotes,
    'pack_detected'         => $detection['pack_detected'],
    'votes_cancelled'       => $detection['cancelled_votes'],
    'target_start_rep'      => $targetStart,
    'target_final_rep'      => $finalRep,
    'sybils'                => $sybilAccounts,
    'message' => $verdict === 'defeated'
        ? "Synergie Bi-Self : SelfRecover a ralenti (coût bcrypt cumulé), SelfModerate a détecté et annulé. L'attaque échoue."
        : "Résultat inattendu — bug démo.",
]);
