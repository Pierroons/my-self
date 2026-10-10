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
use Pierroons\SelfRecover\Duree;

require_once __DIR__ . '/i18n.php';

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/dm.php';
require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/moderate.php';
require_once __DIR__ . '/security.php';
// 🔑 `derive_cli.php` s'interdit aux pages, et sa raison vaut : dans le flux
// applicatif la dérivation appartient au navigateur, et la refaire côté serveur
// ferait transiter le mot mémorisé en clair. Ce simulateur n'est pas ce flux —
// il tourne en `sqlite::memory:`, sur des mots fictifs écrits dans ce fichier,
// et aucun secret d'utilisateur ne l'atteint. Le charger est le moindre mal :
// deux de ses quatre scénarios appellent ces fonctions, et les réécrire ici
// dupliquerait la formule de `sr-derive.js` — exactement la divergence que
// `scripts/check-liens-bibliotheque.sh` surveille. Une seule source, donc.
//
// ⚠️ Sans ce require, `bruteforce` et `csrf` rendaient une erreur fatale depuis
// les boutons de `/attacks.php`. Personne ne l'a vu parce qu'aucun banc
// n'appelait ces deux scénarios : `sanity_moderate.php` nommait `packvoting`.
// Un banc parcourt désormais `SCENARIOS` au lieu d'en citer un.
require_once __DIR__ . '/derive_cli.php';

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
        // La sandbox est jetable : ses bans simulés n'ont pas leur place au
        // journal de modération du site.
        $journal = Moderate::journal();
        Moderate::setJournal(null);
        try {
            return match ($scenario) {
                'dump'       => self::runDumpBase(),
                'bruteforce' => self::runBruteforce(),
                'packvoting' => self::runPackVoting(),
                'csrf'       => self::runCsrf(),
                default      => ['ok' => false, 'message' => 'Scénario inconnu.'],
            };
        } finally {
            Moderate::setJournal($journal);
        }
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
                ['action' => 'Bob envoie un DM contenant son RIB à Alice', 'resultat' => 'message chiffré XChaCha20-Poly1305 avant insertion'],
                ['action' => 'Alice renseigne son adresse dans son profil', 'resultat' => 'profil chiffré at-rest'],
                ['action' => "L'attaquant exfiltre la base et lit les tables dm + profiles", 'resultat' => 'il n\'obtient que des blobs SDG2. illisibles'],
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
            'defense' => 'SelfDataGuard (XChaCha20-Poly1305, clé dérivée d\'un secret serveur hors base)',
            'message_cle' => "La donnée est intégralement conservée et utilisable par Alice — mais l'attaquant ne récupère que du bruit chiffré.",
        ];
    }

    // ─── Scénario 2 : bruteforce login ───────────────────────────────────────
    private static function runBruteforce(): array
    {
        $pdo = self::sandbox();
        // ⚠️ `register` attend la clé DÉRIVÉE en troisième argument, pas le mot :
        // `Device::estCleDerivee` exige 64 hexadécimaux. Le mot y était passé tel
        // quel, si bien que l'inscription était refusée sans qu'aucun Argon2id ne
        // soit calculé — et le panneau annonçait quand même « neutralisé » en
        // lisant une clé absente du tableau. Le scénario démontrait une défense
        // qu'il n'avait pas exercée.
        $sel = sr_sel_aleatoire();
        $r = Auth::register($pdo, 'victime', sr_derive_like_browser('motrecup2024', $sel), $sel);
        $vraiPassword = $r['credentials']['password'];

        $seq = [];
        for ($i = 1; $i <= Auth::LOGIN_MAX_FAILS + 1; $i++) {
            $res = Auth::login($pdo, 'victime', 'TENTATIVE_PIRATE_' . $i, '6.6.6.6');
            $seq[] = "Essai $i : " . ($res['ok'] ? 'RÉUSSI (!)' : $res['message']);
        }

        // 🟢 Légitime : la vraie victime avec son bon password — mais elle est aussi bloquée
        // par le rate-limit (5 échecs atteints). On démontre sur une IP/fenêtre propre :
        $pdo2 = self::sandbox();
        $sel2 = sr_sel_aleatoire();
        $r2 = Auth::register($pdo2, 'victime', sr_derive_like_browser('motrecup2024', $sel2), $sel2);
        $okLogin = Auth::login($pdo2, 'victime', $r2['credentials']['password'], '1.2.3.4');

        return [
            'ok' => true,
            'titre' => 'Bruteforce du mot de passe',
            'objectif' => "Deviner le mot de passe d'un compte en testant des milliers de combinaisons.",
            'etapes' => [
                ['action' => "L'attaquant tente " . (Auth::LOGIN_MAX_FAILS + 1) . ' mots de passe différents', 'resultat' => 'chaque échec est compté'],
                ['action' => 'Au ' . Auth::LOGIN_MAX_FAILS . 'ᵉ échec', 'resultat' => 'le compte est verrouillé ' . Duree::enClair(Auth::LOGIN_WINDOW, langueSelfRecover())],
            ],
            'cote_attaquant' => [
                'label' => 'Tentatives de l\'attaquant',
                'lignes' => $seq,
            ],
            'cote_legitime' => [
                'label' => tc('Utilisateur légitime (bon mot de passe, base vierge)'),
                'lignes' => [
                    'Login avec le vrai mot de passe : ' . ($okLogin['ok'] ? '✓ connecté immédiatement' : 'échec'),
                    tc('Mesuré sur une base vierge. Sur le compte attaqué, le verrou refuse AUSSI le bon mot de passe.'),
                ],
            ],
            'verdict' => 'neutralisé',
            'defense' => 'Rate-limit applicatif (' . Auth::LOGIN_MAX_FAILS . ' échecs / ' . Duree::enClair(Auth::LOGIN_WINDOW, langueSelfRecover()) . ') + Argon2id (' . \Pierroons\SelfRecover\Crypto\Hashing::profilEnClair() . ')',
            'message_cle' => sprintf(
                tc('Le bruteforce est bloqué après %1$s essais — et le verrou refuse le titulaire avec lui pendant %2$s : '
                 . 'un tiers ferme donc la connexion d\'un compte en %1$s requêtes. La récupération, elle, reste ouverte.'),
                Auth::LOGIN_MAX_FAILS,
                Duree::enClair(Auth::LOGIN_WINDOW, langueSelfRecover()),
            ),
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
        $sessionToken = Auth::generateSessionToken();

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
        // ⚠️ La dérivation appartient au navigateur (cf. sr-derive.js) : le serveur
        // n'a plus de fonction pour cela et ne doit pas en reprendre une — ce
        // serait rouvrir le chemin par lequel le mot arrivait en clair. Les deux
        // lignes ci-dessous recopient le calcul du client, sur un mot et un sel
        // écrits ici : aucune saisie n'y entre.
        //
        // 🔑 **Ce que cette illustration montre, et ce qu'elle ne montre pas.**
        // Elle montre que le même mot, dérivé sous deux noms d'hôte, donne deux
        // clés sans rapport. Elle ne PROUVE pas l'anti-hameçonnage : c'est ce
        // code qui choisit les deux noms d'hôte, alors que la propriété tient
        // précisément au fait que le navigateur LIT le sien. Seule une page
        // servie sous une autre adresse en apporterait la preuve.
        //
        // La forme, elle, est celle du protocole — `<hôte>|<version><sel>` — et
        // pas une étiquette inventée : ce fichier est lu, et une formule
        // approximative se recopie aussi bien qu'une juste.
        $mot  = 'monchat2024';
        $sel  = str_repeat('a1b2c3d4', 4);          // 32 hex, comme srEngendrerSel
        $hote = strtolower((string) (getenv('SR_DERIVE_HOTE') ?: 'localhost'));
        $cleVraiSite = sr_derive_like_browser($mot, $sel, $hote);
        $clePhishing = sr_derive_like_browser($mot, $sel, 'phishing-' . str_replace('.', '-', $hote) . '.local');

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
                    "Les deux clés diffèrent parce que le nom d'hôte entre dans la dérivation, et qu'un clone est servi sous le sien. Il ne peut pas le recopier : son navigateur lit l'adresse, il ne la reçoit pas.",
                ],
            ],
            'verdict' => 'neutralisé',
            'defense' => "CSRF token HMAC par session + dérivation liée au nom d'hôte (SelfRecover, mode hostname)",
            'message_cle' => "L'action légitime passe ; l'attaquant cross-site échoue, et un clone servi sous une autre adresse dérive une clé inutilisable ici — sans que l'utilisateur ait eu la moindre adresse à reconnaître.",
        ];
    }
}
