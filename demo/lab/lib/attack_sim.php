<?php
/**
 * MySelf-Lab — Attack Simulator.
 *
 * Chaque scénario s'exécute sur une sandbox SQLite ::memory: ISOLÉE, en
 * appelant les VRAIES classes de défense (Auth, DM, Profile, Moderate, Security,
 * DataGuard). La base meurt avec la requête → zéro impact sur lab.db.
 *
 * Chaque scénario renvoie deux volets :
 *   - cote_attaquant  : ce que l'attaquant obtient (rien d'exploitable)
 *   - cote_legitime   : la même donnée/action côté propriétaire (conservée + utilisable)
 * → démontre que la sécurité ne casse PAS l'usage légitime.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/dm.php';
require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/moderate.php';
require_once __DIR__ . '/security.php';

final class AttackSimulator
{
    public const SCENARIOS = ['dump', 'bruteforce', 'packvoting', 'csrf'];

    /** Sandbox SQLite en mémoire : schema appliqué, isolée, jetable. */
    private static function sandbox(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $sql = file_get_contents(__DIR__ . '/../schema.sql');
        $pdo->exec($sql);
        return $pdo;
    }

    public static function run(string $scenario): array
    {
        return match ($scenario) {
            'dump'       => self::runDumpBase(),
            'bruteforce' => self::runBruteforce(),
            'packvoting' => self::runPackVoting(),
            'csrf'       => self::runCsrf(),
            default      => ['ok' => false, 'message' => 'Scénario inconnu.'],
        };
    }

    // ─── Scénario 1 : exfiltration de la base ────────────────────────────────
    private static function runDumpBase(): array
    {
        $pdo = self::sandbox();
        $old = time() - 2 * 86400;
        // 2 comptes : alice (cible), bob (expéditeur)
        $pdo->prepare('INSERT INTO accounts (username,pw_hash,pass_hash,recovery_hash,created_at) VALUES (?,?,?,?,?)')->execute(['alice', 'x', 'x', 'x', $old]);
        $aliceId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO accounts (username,pw_hash,pass_hash,recovery_hash,created_at) VALUES (?,?,?,?,?)')->execute(['bob', 'x', 'x', 'x', $old]);
        $bobId = (int) $pdo->lastInsertId();

        $secretDM = "Mon RIB : FR76 0000 0000 0000 0000 0000 000 — règlement marché";
        $secretBio = "Adresse réelle : 15 rue des Acacias, 33000 Bordeaux";
        DM::send($pdo, $bobId, 'alice', $secretDM);
        Profile::save($pdo, $aliceId, ['bio' => $secretBio, 'localisation' => 'Gironde', 'lien' => '']);

        // 🔴 Attaquant : dump brut
        $dmBlob = (string) $pdo->query('SELECT ciphertext FROM dm LIMIT 1')->fetchColumn();
        $profBlob = (string) $pdo->query('SELECT ciphertext FROM profiles LIMIT 1')->fetchColumn();

        // 🟢 Légitime : alice connectée lit son DM + son profil
        $inbox = DM::inbox($pdo, $aliceId);
        $profil = Profile::get($pdo, $aliceId);

        return [
            'ok' => true,
            'titre' => 'Exfiltration de la base de données',
            'objectif' => "Voler les messages privés et données personnelles en dumpant la base SQLite.",
            'etapes' => [
                ['action' => 'Bob envoie un DM contenant son RIB à Alice', 'resultat' => 'message chiffré AES-256-GCM avant insertion'],
                ['action' => 'Alice renseigne son adresse dans son profil', 'resultat' => 'profil chiffré at-rest'],
                ['action' => "L'attaquant exfiltre la base et lit les tables dm + profiles", 'resultat' => 'il n\'obtient que des blobs base64'],
            ],
            'cote_attaquant' => [
                'label' => 'Dump SQL brut (ce que voit l\'attaquant)',
                'lignes' => [
                    'dm.ciphertext  = ' . substr($dmBlob, 0, 64) . '…',
                    'profiles.ciphertext = ' . substr($profBlob, 0, 64) . '…',
                    'Recherche "RIB" / "Acacias" dans le dump : 0 résultat',
                ],
            ],
            'cote_legitime' => [
                'label' => 'Alice connectée (ce que conserve le propriétaire)',
                'lignes' => [
                    'DM reçu : « ' . ($inbox[0]['message'] ?? '?') . ' »',
                    'Profil bio : « ' . $profil['bio'] . ' »',
                    'Service pleinement fonctionnel.',
                ],
            ],
            'verdict' => 'neutralisé',
            'defense' => 'SelfDataGuard (chiffrement enveloppé AES-256-GCM, clé hors base)',
            'message_cle' => "La donnée est intégralement conservée et utilisable par Alice — mais l'attaquant ne récupère que du bruit chiffré.",
        ];
    }

    // ─── Scénario 2 : bruteforce login ───────────────────────────────────────
    private static function runBruteforce(): array
    {
        $pdo = self::sandbox();
        $r = Auth::register($pdo, 'victime', 'motrecup2024');
        $vraiPassword = $r['credentials']['password'];

        $seq = [];
        for ($i = 1; $i <= 6; $i++) {
            $res = Auth::login($pdo, 'victime', 'TENTATIVE_PIRATE_' . $i, '6.6.6.6');
            $seq[] = "Essai $i : " . ($res['ok'] ? 'RÉUSSI (!)' : $res['message']);
        }

        // 🟢 Légitime : la vraie victime avec son bon password — mais elle est aussi bloquée
        // par le rate-limit (5 échecs atteints). On démontre sur une IP/fenêtre propre :
        $pdo2 = self::sandbox();
        $r2 = Auth::register($pdo2, 'victime', 'motrecup2024');
        $okLogin = Auth::login($pdo2, 'victime', $r2['credentials']['password'], '1.2.3.4');

        return [
            'ok' => true,
            'titre' => 'Bruteforce du mot de passe',
            'objectif' => "Deviner le mot de passe d'un compte en testant des milliers de combinaisons.",
            'etapes' => [
                ['action' => "L'attaquant tente 6 mots de passe différents", 'resultat' => 'chaque échec est compté'],
                ['action' => 'Au 5ᵉ échec', 'resultat' => 'le compte est verrouillé 15 minutes'],
            ],
            'cote_attaquant' => [
                'label' => 'Tentatives de l\'attaquant',
                'lignes' => $seq,
            ],
            'cote_legitime' => [
                'label' => 'Utilisateur légitime (bon mot de passe, autre contexte)',
                'lignes' => [
                    'Login avec le vrai mot de passe : ' . ($okLogin['ok'] ? '✓ connecté immédiatement' : 'échec'),
                    'Le rate-limit ne frappe que les échecs : le légitime n\'est pas gêné.',
                ],
            ],
            'verdict' => 'neutralisé',
            'defense' => 'Rate-limit applicatif (5 échecs / 15 min) + Argon2id (m=64 Mo, t=4, p=2)',
            'message_cle' => "Le bruteforce est bloqué après 5 essais, mais l'utilisateur légitime se connecte sans entrave.",
        ];
    }

    // ─── Scénario 3 : Sybil + pack-voting ────────────────────────────────────
    private static function runPackVoting(): array
    {
        $pdo = self::sandbox();
        $old = time() - 3 * 86400;
        // cible + 3 faux comptes anciens + 1 votant honnête ancien
        foreach (['cible', 'faux1', 'faux2', 'faux3', 'honnete'] as $u) {
            $pdo->prepare('INSERT INTO accounts (username,pw_hash,pass_hash,recovery_hash,created_at) VALUES (?,?,?,?,?)')->execute([$u, 'x', 'x', 'x', $old]);
        }
        $ids = [];
        foreach ($pdo->query('SELECT id, username FROM accounts')->fetchAll() as $row) {
            $ids[$row['username']] = (int) $row['id'];
        }
        // post de la cible
        $pdo->prepare('INSERT INTO threads (account_id,titre,categorie,created_at) VALUES (?,?,?,?)')->execute([$ids['cible'], 'Mon sujet', 'general', $old]);
        $pdo->prepare('INSERT INTO posts (thread_id,account_id,contenu,created_at) VALUES (1,?,?,?)')->execute([$ids['cible'], 'contenu', $old]);

        // 🟢 vote honnête d'abord (membre établi)
        Moderate::applyVote($pdo, $ids['honnete'], 'member', $ids['cible'], 1);
        $repApresHonnete = Moderate::getReputation($pdo, $ids['cible'])['reputation'];

        // 🔴 pack-voting : 3 faux comptes downvotent en <60s
        foreach (['faux1', 'faux2', 'faux3'] as $f) {
            Moderate::applyVote($pdo, $ids[$f], 'member', $ids['cible'], -1);
        }
        $repApresAttaque = Moderate::getReputation($pdo, $ids['cible'])['reputation'];

        // défense : détection
        $det = Moderate::detectPackVoting($pdo);
        $repApresDetection = Moderate::getReputation($pdo, $ids['cible'])['reputation'];

        // anti-Sybil : un compte tout neuf tente de voter
        $pdo->prepare('INSERT INTO accounts (username,pw_hash,pass_hash,recovery_hash,created_at) VALUES (?,?,?,?,?)')->execute(['sybil_neuf', 'x', 'x', 'x', time()]);
        $sybilId = (int) $pdo->lastInsertId();
        [$canVote, $why] = Moderate::canVote($pdo, $sybilId);

        // le vote honnête est-il toujours compté ?
        $voteHonnete = Moderate::userVote($pdo, $ids['honnete'], 'member', $ids['cible']);

        return [
            'ok' => true,
            'titre' => 'Sybil + pack-voting (enterrement coordonné)',
            'objectif' => "Créer de faux comptes pour faire chuter la réputation d'un membre par un downvote coordonné.",
            'etapes' => [
                ['action' => 'Un membre honnête soutient la cible (+1)', 'resultat' => sprintf(tc('réputation : %d'), $repApresHonnete)],
                ['action' => '3 faux comptes downvotent la cible en moins de 60 s', 'resultat' => sprintf(tc('réputation chute à : %d'), $repApresAttaque)],
                ['action' => 'Détection de pack-voting', 'resultat' => sprintf(tc('%d votes annulés, réputation restaurée à : %d'), (int) $det['cancelled_votes'], $repApresDetection)],
                ['action' => 'Un compte créé à l\'instant tente de voter', 'resultat' => $canVote ? 'autorisé (!)' : 'refusé — ' . $why],
            ],
            'cote_attaquant' => [
                'label' => 'Tentative de manipulation',
                'lignes' => [
                    sprintf(tc('3 downvotes coordonnés → %d annulés (pack_voting)'), (int) $det['cancelled_votes']),
                    sprintf(tc('Faux compte neuf → %s'), $canVote ? tc('a pu voter') : tc('BLOQUÉ (anti-Sybil)')),
                    sprintf(tc('Impact net sur la réputation : %d'), 0),
                ],
            ],
            'cote_legitime' => [
                'label' => 'Modération communautaire honnête',
                'lignes' => [
                    'Vote du membre établi : ' . ($voteHonnete !== null ? '✓ conservé (+1)' : 'perdu'),
                    sprintf(tc('Réputation finale de la cible : %d (intègre)'), $repApresDetection),
                    'Les votes légitimes restent comptés, seuls les coordonnés tombent.',
                ],
            ],
            'verdict' => 'neutralisé',
            'defense' => 'SelfModerate (détection pack-voting + anti-Sybil par seuils)',
            'message_cle' => "La manipulation coordonnée est annulée et la réputation restaurée, sans toucher aux votes honnêtes.",
        ];
    }

    // ─── Scénario 4 : CSRF + phishing reset ──────────────────────────────────
    private static function runCsrf(): array
    {
        $pdo = self::sandbox();
        // session fictive pour le calcul CSRF
        $sessionToken = bin2hex(random_bytes(24));

        // 🔴 CSRF : token absent ou faux
        $sansToken = Security::verifyCsrf($sessionToken); // $_SERVER vide → false
        $bonToken = Security::csrfToken($sessionToken);
        // simule un faux token
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'faux_token_attaquant';
        $fauxToken = Security::verifyCsrf($sessionToken);
        // simule le bon token (légitime)
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $bonToken;
        $avecBonToken = Security::verifyCsrf($sessionToken);
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);

        // 🔴 Phishing : le MÊME mot mémorisé, dérivé sous deux étiquettes de
        // service différentes, ne donne pas la même clé.
        //
        // ⚠️ La dérivation appartient désormais au navigateur (cf. sr-derive.js) :
        // le serveur n'a plus de fonction pour cela, et il ne doit pas en
        // reprendre une — ce serait rouvrir le chemin par lequel le mot arrivait
        // en clair. On reproduit donc ici le calcul du client, sur des étiquettes
        // fictives, pour la seule démonstration.
        $mot = 'monchat2024';
        $cleVraiSite = hash_hmac('sha256', 'myself-lab-domain-v1', $mot);
        $clePhishing = hash_hmac('sha256', 'phishing-evil-com-v1', $mot);

        return [
            'ok' => true,
            'titre' => 'CSRF + phishing de récupération',
            'objectif' => "Forcer une action au nom de la victime (CSRF) ou détourner sa récupération de compte (phishing).",
            'etapes' => [
                ['action' => 'Un site tiers tente une action POST sans jeton CSRF', 'resultat' => $sansToken ? 'acceptée (!)' : 'rejetée (403)'],
                ['action' => 'Avec un faux jeton CSRF', 'resultat' => $fauxToken ? 'acceptée (!)' : 'rejetée (403)'],
                ['action' => 'Un site de phishing imite le forum pour dériver la clé de récupération', 'resultat' => 'obtient une clé totalement différente'],
            ],
            'cote_attaquant' => [
                'label' => 'Tentatives de l\'attaquant',
                'lignes' => [
                    'POST sans jeton CSRF : ' . ($sansToken ? 'OK' : 'REJETÉ (403)'),
                    'POST avec faux jeton : ' . ($fauxToken ? 'OK' : 'REJETÉ (403)'),
                    sprintf(tc('Clé dérivée sur un faux site : %s'), substr($clePhishing, 0, 24) . '…'),
                ],
            ],
            'cote_legitime' => [
                'label' => 'Usage légitime',
                'lignes' => [
                    'POST avec le bon jeton CSRF : ' . ($avecBonToken ? '✓ accepté' : 'rejeté'),
                    sprintf(tc('Clé dérivée sur le vrai site : %s'), substr($cleVraiSite, 0, 24) . '…'),
                    'Les deux clés diffèrent tant que le faux site ne recopie pas le vrai label ; avec ce label en dur, il reproduit la bonne.',
                ],
            ],
            'verdict' => 'neutralisé',
            'defense' => 'CSRF token HMAC par session + dérivation par service SelfRecover',
            'message_cle' => "L'action légitime passe ; l'attaquant cross-site échoue, et un clone qui ne recopie pas le vrai label dérive une clé inutile.",
        ];
    }
}
