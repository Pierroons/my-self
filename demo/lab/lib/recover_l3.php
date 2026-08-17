<?php
/**
 * MySelf-Lab — SelfRecover L3 : récupération assistée par un HUMAIN (chat admin obligatoire).
 *
 * On arrive en L3 après échec de L1 (passphrase) ET L2 (mot mémorisé + code) : ces secrets
 * sont perdus, on ne les REDEMANDE PAS. Le L3 collecte un FAISCEAU DE FAITS BRUTS que le
 * serveur connaît déjà (année de création, dernière connexion, IP déjà vue…), présentés à
 * l'admin — JAMAIS agrégés en un score. RÈGLE ABSOLUE : ces signaux n'ouvrent jamais le
 * compte, ils aident seulement l'admin. Toute récup L3 passe par une décision humaine, et
 * même accordée, c'est le PROPRIÉTAIRE (porteur du sésame d'init) qui re-choisit ses secrets.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

require_once __DIR__ . '/diceware/wordlist.php';

final class RecoverL3
{
    private const DISPUTE_TTL = 86400;   // 24 h — durée de vie de la capability (n° + sésame)
    private const COOLDOWN     = 3600;   // 1 h entre deux soumissions L3
    private const IP_THRESHOLD = 10;     // échecs de sésame/n° → blocage IP
    private const IP_BLOCK     = 86400;  // 24 h

    /** Questions contextuelles — AUCUN secret demandé. */
    public static function questions(): array
    {
        return [
            ['key' => 'creation_year',     'label' => 'En quelle année as-tu créé ce compte ?',            'type' => 'year'],
            ['key' => 'last_login_period', 'label' => 'À quand remonte ta dernière connexion ? (MM/AAAA)', 'type' => 'month'],
            ['key' => 'login_freq',        'label' => 'À peu près, combien t\'es-tu connecté ?',            'type' => 'select', 'options' => ['rare', 'regulier', 'intensif']],
        ];
    }

    private static function byNumber(PDO $pdo, string $number): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM disputes WHERE dispute_number = ?');
        $stmt->execute([$number]);
        return $stmt->fetch() ?: null;
    }

    private static function activeDispute(PDO $pdo, int $accountId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM disputes WHERE account_id = ? AND status IN ('open','awaiting_admin','granted')
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$accountId]);
        return $stmt->fetch() ?: null;
    }

    private static function expired(?array $d): bool
    {
        return $d && !empty($d['expires_at']) && (int) $d['expires_at'] < time();
    }

    /** Le sésame (généré côté demandeur à l'init) autorise le fil : hash_equals(claim_hash, sha256(secret)). */
    private static function claimValid(?array $d, string $claimSecret): bool
    {
        if (!$d || $claimSecret === '' || empty($d['claim_hash'])) {
            return false;
        }
        return hash_equals($d['claim_hash'], hash('sha256', $claimSecret));
    }

    private static function isBlockedIP(PDO $pdo, ?string $ip): bool
    {
        if (!$ip) {
            return false;
        }
        $stmt = $pdo->prepare(
            'SELECT blocked_until FROM suspicious_fingerprints WHERE ip = ? AND blocked_until IS NOT NULL
             ORDER BY last_seen DESC LIMIT 1'
        );
        $stmt->execute([$ip]);
        $until = $stmt->fetchColumn();
        return $until && (int) $until > time();
    }

    private static function trackSuspiciousIP(PDO $pdo, ?string $ip, string $ua): void
    {
        if (!$ip) {
            return;
        }
        $stmt = $pdo->prepare('SELECT id, attempt_count FROM suspicious_fingerprints WHERE ip = ?');
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        if ($row) {
            $n = (int) $row['attempt_count'] + 1;
            $blocked = $n >= self::IP_THRESHOLD ? time() + self::IP_BLOCK : null;
            $pdo->prepare('UPDATE suspicious_fingerprints SET attempt_count = ?, blocked_until = ?, last_seen = ? WHERE id = ?')
                ->execute([$n, $blocked, time(), (int) $row['id']]);
        } else {
            $pdo->prepare('INSERT INTO suspicious_fingerprints (ip, user_agent, attempt_count, created_at, last_seen) VALUES (?, ?, 1, ?, ?)')
                ->execute([$ip, $ua, time(), time()]);
        }
    }

    /** L3 INIT — username + claim_hash (SHA-256 du sésame client) → litige LIT-XXXX + questions. */
    public static function init(PDO $pdo, string $username, string $claimHash, ?string $ip = null): array
    {
        if (self::isBlockedIP($pdo, $ip)) {
            return ['ok' => false, 'code' => 429, 'message' => 'Trop de tentatives depuis cette connexion. Réessaie plus tard.'];
        }
        $username = strtolower(trim($username));
        if (!preg_match('/^[a-f0-9]{64}$/', $claimHash)) {
            return ['ok' => false, 'code' => 400, 'message' => 'Code de suivi invalide.'];
        }
        $stmt = $pdo->prepare('SELECT * FROM accounts WHERE username = ?');
        $stmt->execute([$username]);
        $acc = $stmt->fetch();
        usleep(300000); // anti-timing
        if (!$acc) {
            return ['ok' => false, 'code' => 404, 'message' => 'Aucun compte à ce nom.'];
        }
        if (!empty($acc['banned_until']) && (int) $acc['banned_until'] > time()) {
            return ['ok' => false, 'code' => 429, 'message' => 'Compte temporairement bloqué suite à un refus de litige.'];
        }

        // Litige déjà ouvert : on NE re-divulgue PAS le numéro (anti-fuite de capability),
        // on enregistre juste un signal « multi-demandeur » pour l'admin.
        $existing = self::activeDispute($pdo, (int) $acc['id']);
        if ($existing && !self::expired($existing)) {
            $pdo->prepare('UPDATE disputes SET init_collisions = init_collisions + 1, updated_at = ? WHERE id = ?')
                ->execute([time(), (int) $existing['id']]);
            return ['ok' => true, 'dispute_number' => null, 'already_open' => true, 'questions' => self::questions(),
                    'note' => 'Une procédure est déjà en cours pour cet identifiant. Reprends-la avec ton code de suivi, sinon un administrateur a été alerté.'];
        }

        $number  = 'LIT-' . strtoupper(bin2hex(random_bytes(8)));
        $expires = time() + self::DISPUTE_TTL;
        $pdo->prepare(
            "INSERT INTO disputes (dispute_number, account_id, status, claim_hash, expires_at, source_ip, created_at, updated_at)
             VALUES (?, ?, 'open', ?, ?, ?, ?, ?)"
        )->execute([$number, (int) $acc['id'], $claimHash, $expires, $ip, time(), time()]);

        return ['ok' => true, 'dispute_number' => $number, 'questions' => self::questions(),
                'note' => 'Aucun secret ne t\'est demandé. Garde le code de suivi affiché : il protège ta procédure. Un administrateur examinera ta demande.'];
    }

    /** L3 SUBMIT — sésame + réponses → faisceau de faits bruts → attente décision admin. */
    public static function submit(PDO $pdo, string $number, string $claimSecret, array $answers, ?string $ip = null): array
    {
        if (self::isBlockedIP($pdo, $ip)) {
            return ['ok' => false, 'code' => 429, 'message' => 'Trop de tentatives depuis cette connexion.'];
        }
        $d = self::byNumber($pdo, $number);
        if (!$d || self::expired($d) || !in_array($d['status'], ['open', 'awaiting_admin'], true)) {
            usleep(300000);
            return ['ok' => false, 'code' => 404, 'message' => 'Litige introuvable ou clos.'];
        }
        if (!self::claimValid($d, $claimSecret)) {
            self::trackSuspiciousIP($pdo, $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');
            usleep(300000);
            return ['ok' => false, 'code' => 403, 'message' => 'Code de suivi invalide.'];
        }
        $stmt = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
        $stmt->execute([(int) $d['account_id']]);
        $acc = $stmt->fetch();
        if (!$acc) {
            return ['ok' => false, 'code' => 404, 'message' => 'Compte introuvable.'];
        }

        // Cooldown 1h entre soumissions L3
        $stmt = $pdo->prepare('SELECT attempted_at FROM login_attempts WHERE username = ? AND level = 3 ORDER BY attempted_at DESC LIMIT 1');
        $stmt->execute([$acc['username']]);
        $last = $stmt->fetchColumn();
        if ($last && (time() - (int) $last) < self::COOLDOWN) {
            $min = (int) ceil((self::COOLDOWN - (time() - (int) $last)) / 60);
            return ['ok' => false, 'code' => 429, 'message' => "Patiente encore {$min} min avant une nouvelle soumission."];
        }

        $signals = self::gatherSignals($answers, $acc, $pdo, $ip);
        $pdo->prepare("UPDATE disputes SET signals_json = ?, status = 'awaiting_admin', updated_at = ? WHERE id = ?")
            ->execute([json_encode($signals, JSON_UNESCAPED_UNICODE), time(), (int) $d['id']]);
        // Un L3 ne « réussit » jamais seul.
        $pdo->prepare('INSERT INTO login_attempts (username, success, level, ip, attempted_at) VALUES (?, 0, 3, ?, ?)')
            ->execute([$acc['username'], $ip, time()]);

        return ['ok' => true, 'dispute_number' => $number, 'status' => 'awaiting_admin',
                'message' => 'Ta demande est transmise à un administrateur. Ouvre le chat pour échanger avec lui.'];
    }

    /** Faisceau de FAITS BRUTS (jamais un score). Axe passif (non falsifiable) + déclaratif (dit/réel). */
    private static function gatherSignals(array $answers, array $acc, PDO $pdo, ?string $ip): array
    {
        $passive = [];
        $declarative = [];

        // PASSIF : IP déjà utilisée par ce compte pour une action réussie (login/recover).
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND ip = ? AND success = 1');
        $stmt->execute([$acc['username'], $ip]);
        $ipCount = (int) $stmt->fetchColumn();
        $passive[] = ['label' => 'IP déjà utilisée par ce compte', 'ok' => $ipCount > 0,
                      'detail' => $ipCount > 0 ? "$ipCount action(s) réussie(s) depuis cette IP" : 'IP jamais vue pour ce compte'];

        // DÉCLARATIF : dit vs réel (devinable → plus faible ; l'humain juge).
        $dit  = trim((string) ($answers['creation_year'] ?? ''));
        $reel = !empty($acc['created_at']) ? date('Y', (int) $acc['created_at']) : '?';
        $declarative[] = ['label' => 'Année de création', 'dit' => $dit ?: '—', 'reel' => $reel, 'ok' => ($dit !== '' && $dit === $reel)];

        $dit  = trim((string) ($answers['last_login_period'] ?? ''));
        // « ? » n'apprenait rien au relecteur : un compte jamais utilisé est un fait,
        // et c'en est un qui pèse dans sa décision.
        $reel = !empty($acc['last_login_at']) ? date('m/Y', (int) $acc['last_login_at']) : 'jamais connecté';
        $declarative[] = ['label' => 'Dernière connexion (mois)', 'dit' => $dit ?: '—', 'reel' => $reel,
                          'ok' => ($dit !== '' && !empty($acc['last_login_at']) && $dit === $reel)];

        $dit  = trim((string) ($answers['login_freq'] ?? ''));
        $lc   = (int) ($acc['login_count'] ?? 0);
        $band = $lc < 10 ? 'rare' : ($lc < 50 ? 'regulier' : 'intensif');
        $declarative[] = ['label' => 'Fréquence d\'usage', 'dit' => $dit ?: '—', 'reel' => "$band (~$lc connexions)", 'ok' => ($dit === $band)];

        $passOk = count(array_filter($passive, fn($s) => $s['ok']));
        $declOk = count(array_filter($declarative, fn($s) => $s['ok']));
        return [
            'passive' => $passive, 'declarative' => $declarative,
            'summary' => sprintf('%d/%d passifs · %d/%d déclaratifs concordants', $passOk, count($passive), $declOk, count($declarative)),
        ];
    }

    /** Chat user↔admin. Lecture = pas de champ message ; écriture sinon. Auth : admin (session) OU sésame. */
    public static function chat(PDO $pdo, string $number, string $claimSecret, ?string $message, bool $isAdmin, ?string $ip = null): array
    {
        $d = self::byNumber($pdo, $number);
        if (!$d || $d['status'] === 'closed' || self::expired($d)) {
            return ['ok' => false, 'code' => 404, 'message' => 'Litige introuvable ou fermé.'];
        }
        if (!$isAdmin && !self::claimValid($d, $claimSecret)) {
            self::trackSuspiciousIP($pdo, $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');
            return ['ok' => false, 'code' => 403, 'message' => 'Accès au litige refusé (code de suivi invalide).'];
        }
        if ($message === null) {
            $stmt = $pdo->prepare('SELECT sender, body, created_at FROM dispute_messages WHERE dispute_id = ? ORDER BY created_at ASC');
            $stmt->execute([(int) $d['id']]);
            return ['ok' => true, 'status' => $d['status'], 'messages' => $stmt->fetchAll()];
        }
        $message = trim($message);
        if ($message === '') {
            return ['ok' => false, 'code' => 400, 'message' => 'Message vide.'];
        }
        if (mb_strlen($message) > 2000) {
            return ['ok' => false, 'code' => 400, 'message' => 'Message trop long.'];
        }
        $sender = $isAdmin ? 'admin' : 'user';
        $pdo->prepare('INSERT INTO dispute_messages (dispute_id, sender, body, created_at) VALUES (?, ?, ?, ?)')
            ->execute([(int) $d['id'], $sender, $message, time()]);
        return ['ok' => true, 'sent' => true, 'sender' => $sender];
    }

    /** Liste des litiges pour l'admin — l'IP source ne sort JAMAIS (OPSEC). */
    public static function adminList(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT d.dispute_number, d.status, d.signals_json, d.refusal_count, d.init_collisions, d.created_at, d.source_ip,
                    a.username, a.created_at AS account_created, a.last_login_at, a.login_count
               FROM disputes d JOIN accounts a ON a.id = d.account_id
              WHERE d.status != 'closed' ORDER BY d.updated_at DESC"
        )->fetchAll();
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE level = 2 AND success = 0 AND ip = ?');
        foreach ($rows as &$r) {
            $r['signals'] = json_decode($r['signals_json'] ?? '{}', true);
            unset($r['signals_json']);
            $cnt->execute([$r['source_ip'] ?? '']);
            $r['l2_prior_attempts'] = (int) $cnt->fetchColumn(); // corrélation sans exposer l'IP
            unset($r['source_ip']);
        }
        return ['ok' => true, 'disputes' => $rows];
    }

    /** Décision admin : grant (l'humain confirme, le propriétaire re-choisira) | refuse (démo : suppression). */
    public static function adminDecide(PDO $pdo, string $number, string $decision): array
    {
        $d = self::byNumber($pdo, $number);
        if (!$d) {
            return ['ok' => false, 'code' => 404, 'message' => 'Litige introuvable.'];
        }
        $stmt = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
        $stmt->execute([(int) $d['account_id']]);
        $acc = $stmt->fetch();
        if (!$acc) {
            return ['ok' => false, 'code' => 404, 'message' => 'Compte introuvable.'];
        }

        if ($decision === 'grant') {
            // AUCUN credential livré par le chat. Le propriétaire (sésame d'init) re-définit son secret via reset.
            $pdo->prepare("UPDATE disputes SET status = 'granted', updated_at = ? WHERE id = ?")->execute([time(), (int) $d['id']]);
            $pdo->prepare("INSERT INTO dispute_messages (dispute_id, sender, body, created_at) VALUES (?, 'admin', ?, ?)")
                ->execute([(int) $d['id'], "Identité confirmée. Reprends ta procédure de récupération pour définir un nouveau mot de passe — aucun mot de passe n'est transmis par ce canal.", time()]);
            return ['ok' => true, 'decision' => 'granted',
                    'note' => 'Le demandeur re-définira lui-même son secret. Aucun mot de passe généré ni transmis.'];
        }

        if ($decision === 'refuse') {
            // Démo : 1 refus = clôture + suppression du compte (montre le cycle complet).
            //
            // ⚠️ Le tout dans UNE transaction. Le verdict et le message doivent être
            // écrits avant la suppression — celle-ci purge le litige en cascade — mais
            // un échec de la suppression laisserait sinon en base un message annonçant
            // une destruction qui n'a pas eu lieu. C'est exactement ce qui s'est produit
            // tant que recovery_codes référençait accounts sans cascade : la contrainte
            // rejetait le DELETE, et le fil affirmait au demandeur que son compte était
            // supprimé alors qu'il était intact.
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE disputes SET status = 'refused', refusal_count = 1, updated_at = ? WHERE id = ?")
                    ->execute([time(), (int) $d['id']]);
                $pdo->prepare("INSERT INTO dispute_messages (dispute_id, sender, body, created_at) VALUES (?, 'admin', ?, ?)")
                    ->execute([(int) $d['id'], "Preuve insuffisante. Compte clôturé et données supprimées.", time()]);
                $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([(int) $acc['id']]); // CASCADE : litige, codes, sessions…
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 500,
                        'message' => "La suppression a échoué : le compte est intact et le litige inchangé."];
            }
            return ['ok' => true, 'decision' => 'refused', 'account_deleted' => true];
        }

        return ['ok' => false, 'code' => 400, 'message' => 'Décision invalide (grant|refuse).'];
    }

    /** RESET — après accord admin, le PROPRIÉTAIRE (sésame) re-choisit ses secrets. Le serveur
     *  ne génère ni ne diffuse de mot de passe : il est choisi par l'utilisateur. Capability one-shot. */
    public static function reset(PDO $pdo, string $number, string $claimSecret, string $password, string $recoveryDerivedKey): array
    {
        $d = self::byNumber($pdo, $number);
        if (!$d || self::expired($d)) {
            usleep(200000);
            return ['ok' => false, 'code' => 404, 'message' => 'Litige introuvable ou expiré.'];
        }
        if (!self::claimValid($d, $claimSecret)) {
            usleep(200000);
            return ['ok' => false, 'code' => 403, 'message' => 'Code de suivi invalide.'];
        }
        if ($d['status'] !== 'granted') {
            return ['ok' => false, 'code' => 409, 'message' => "Cette procédure n'a pas (encore) été accordée par un administrateur."];
        }
        if (strlen($password) < 8) {
            return ['ok' => false, 'code' => 400, 'message' => 'Mot de passe : 8 caractères minimum.'];
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $recoveryDerivedKey)) {
            return ['ok' => false, 'code' => 400, 'message' => 'Nouveau mot de récupération invalide.'];
        }

        $stmt = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
        $stmt->execute([(int) $d['account_id']]);
        $acc = $stmt->fetch();
        if (!$acc) {
            return ['ok' => false, 'code' => 404, 'message' => 'Compte introuvable.'];
        }

        // Re-enrôlement : secrets choisis par le propriétaire. Passphrase L1 régénérée (perdue).
        $diceware = \DicewareWordlist::generate(4, 'en');
        $passphrase = implode(' ', $diceware['words']);
        $opts = Auth::argon2Options();
        $pdo->prepare('UPDATE accounts SET pw_hash = ?, pass_hash = ?, recovery_hash = ?, banned_until = 0 WHERE id = ?')
            ->execute([
                password_hash($password, PASSWORD_ARGON2ID, $opts),
                password_hash($passphrase, PASSWORD_ARGON2ID, $opts),
                password_hash($recoveryDerivedKey, PASSWORD_ARGON2ID, $opts),
                (int) $acc['id'],
            ]);
        $pdo->prepare('DELETE FROM app_sessions WHERE account_id = ?')->execute([(int) $acc['id']]);

        // Capability consommée (one-shot) + litige clos + nouveau lot de recovery codes.
        $pdo->prepare("UPDATE disputes SET status = 'resolved', claim_hash = NULL, updated_at = ? WHERE id = ?")
            ->execute([time(), (int) $d['id']]);
        $pdo->prepare("INSERT INTO dispute_messages (dispute_id, sender, body, created_at) VALUES (?, 'admin', ?, ?)")
            ->execute([(int) $d['id'], "Accès rétabli par le propriétaire (re-enrôlement). Litige clos.", time()]);
        $pdo->prepare('INSERT INTO login_attempts (username, success, level, ip, attempted_at) VALUES (?, 1, 3, ?, ?)')
            ->execute([$acc['username'], null, time()]);
        $recoveryCodes = Auth::generateRecoveryCodes($pdo, (int) $acc['id']);

        return ['ok' => true, 'message' => 'Accès rétabli. Ton mot de passe (que tu as choisi) est actif.',
                'credentials' => ['passphrase' => $passphrase, 'recovery_codes' => $recoveryCodes],
                'note' => 'Note ta nouvelle passphrase L1 et tes codes — ils ne seront plus affichés. Le serveur n\'a ni généré ni diffusé de mot de passe.'];
    }
}
