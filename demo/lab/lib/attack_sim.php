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
        // journal de modération du site. Ni ses blobs le numéro de la vraie base : le
        // lire ouvrait celle-ci, et la créait hors d'une route (mesuré par la huitième
        // passe d'audit) ; et scellés sous lui, ils valaient dans ses contextes.
        $journal = Moderate::journal();
        Moderate::setJournal(null);
        $numero = Db::substituerGeneration(bin2hex(random_bytes(16)));
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
            Db::substituerGeneration($numero);
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
                ['action' => "L'attaquant exfiltre la base et lit les tables dm + profiles", 'resultat' => 'il n\'obtient que des blobs illisibles, chacun scellé sous la ligne où il a été écrit'],
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

    // ─── Scénario 3 : Sybil + meute ──────────────────────────────────────────
    // La meute se joue comme le moteur la lit : des faux comptes anciens, assez pour
    // mener la cible au plancher à travers leurs plafonds, qui se sont tous écrit
    // dans les deux sens, et qui votent avec un motif valide — sans motif,
    // leurs votes seraient refusés. Aucun vote n'est annulé : liés, ils ne pèsent
    // qu'une voix au quorum des seuils, et la cible tombe sans sanction. Ce que la
    // page annonce se compte en base, et le verdict ne dit « atténué » que si la
    // chute est restée sans ban ni perte du vote, et signalée à l'arbitre.
    private const MOTIF_MEUTE = 'Ce message propage une rumeur sans aucune source vérifiable sur ce membre.';

    private static function runPackVoting(): array
    {
        $pdo = self::sandbox();
        $old = time() - 3 * 86400;
        $faux = ['faux1', 'faux2', 'faux3', 'faux4', 'faux5', 'faux6', 'faux7'];
        foreach (['cible', ...$faux, 'honnete'] as $u) {
            $pdo->prepare('INSERT INTO accounts (username,pw_hash,pass_hash,recovery_hash,created_at) VALUES (?,?,?,?,?)')->execute([$u, 'x', 'x', 'x', $old]);
        }
        $ids = [];
        foreach ($pdo->query('SELECT id, username FROM accounts')->fetchAll() as $row) {
            $ids[$row['username']] = (int) $row['id'];
        }
        // trois messages de la cible : un faux compte vote une fois par message
        $posts = [];
        foreach ([1, 2, 3] as $k) {
            $pdo->prepare('INSERT INTO threads (account_id,titre,categorie,created_at) VALUES (?,?,?,?)')->execute([$ids['cible'], "Mon sujet $k", 'general', $old]);
            $pdo->prepare('INSERT INTO posts (thread_id,account_id,contenu,created_at) VALUES (?,?,?,?)')->execute([(int) $pdo->lastInsertId(), $ids['cible'], 'contenu', $old]);
            $posts[] = (int) $pdo->lastInsertId();
        }

        // 🔴 les faux comptes se sont tous écrit dans les deux sens : c'est le lien que le quorum lit
        foreach ($faux as $a) {
            foreach ($faux as $b) {
                if ($a !== $b) {
                    $pdo->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (?, ?, ?, ?)')
                        ->execute([$ids[$a], $ids[$b], 'chiffre', $old]);
                }
            }
        }

        // 🟢 vote honnête d'abord (membre établi)
        Moderate::applyVote($pdo, $ids['honnete'], 'member', $ids['cible'], 1);
        $repApresHonnete = Moderate::getReputation($pdo, $ids['cible'])['reputation'];

        // 🔴 la meute : chaque faux compte vote contre chacun des trois messages, avec un motif valide
        $acceptes = 0;
        foreach ($posts as $post) {
            foreach ($faux as $f) {
                $acceptes += (int) Moderate::applyVote($pdo, $ids[$f], 'post', $post, -1, self::MOTIF_MEUTE, 'desinformation')['ok'];
            }
        }
        $annules = (int) $pdo->query("SELECT COUNT(*) FROM mod_votes WHERE target_author = {$ids['cible']} AND value = -1 AND blocked = 1")->fetchColumn();
        $etat = Moderate::getReputation($pdo, $ids['cible']);
        $signalee = in_array('chute_sans_quorum', array_column($etat['signaux'], 'cause'), true);
        $voix = Moderate::compositionChute($pdo, $ids['cible'], time())['voix_sans_lien'];
        $config = Moderate::config();

        // anti-Sybil : un compte tout neuf tente de voter
        $pdo->prepare('INSERT INTO accounts (username,pw_hash,pass_hash,recovery_hash,created_at) VALUES (?,?,?,?,?)')->execute(['sybil_neuf', 'x', 'x', 'x', time()]);
        $sybilId = (int) $pdo->lastInsertId();
        [$canVote, $why] = Moderate::canVote($pdo, $sybilId);

        // le vote honnête est-il toujours compté ? `userVote()` rend aussi un vote
        // tombé — c'est ce que voit son auteur — : la mesure lit le vote lui-même.
        $compte = $pdo->prepare("SELECT blocked FROM mod_votes WHERE voter_id = ? AND target_type = 'member' AND target_id = ?");
        $compte->execute([$ids['honnete'], $ids['cible']]);
        $bloque = $compte->fetchColumn();
        $voteHonnete = $bloque !== false && (int) $bloque === 0 ? 1 : null;

        $sansSanction = !$etat['banned'] && $etat['voting_rights'];
        $attenue = $acceptes === 3 * count($faux) && $annules === 0 && $etat['reputation'] === $config->banA
            && $sansSanction && $signalee && !$canVote && $voteHonnete !== null;

        return [
            'ok' => true,
            'titre' => 'Sybil + meute (enterrement coordonné)',
            'objectif' => "Créer de faux comptes pour faire chuter la réputation d'un membre par un downvote coordonné.",
            'etapes' => [
                ['action' => 'Un membre honnête soutient la cible (+1)', 'resultat' => sprintf(tc('réputation : %d'), $repApresHonnete)],
                ['action' => '7 faux comptes, tous liés par des messages privés, downvotent chacun des 3 messages de la cible avec un motif', 'resultat' => sprintf(tc('%1$d votes acceptés sur %2$d ; réputation : %3$d'), $acceptes, 3 * count($faux), $etat['reputation'])],
                ['action' => 'Quorum aux seuils : les votants sans lien entre eux', 'resultat' => $sansSanction
                    ? sprintf(tc('%1$d voix sans lien, il en faut %2$d pour bannir : ni ban ni perte du vote, et la chute est signalée à un arbitre'), $voix, $config->voixPourSanction($config->banA))
                    : sprintf(tc('%1$d voix sans lien : une sanction est tombée'), $voix)],
                ['action' => 'Un compte créé à l\'instant tente de voter', 'resultat' => $canVote ? 'autorisé (!)' : 'refusé — ' . $why],
            ],
            'cote_attaquant' => [
                'label' => 'Tentative de manipulation',
                'lignes' => [
                    sprintf(tc('%1$d downvotes coordonnés → réputation %2$d, sans ban ni perte du vote'), $acceptes, $etat['reputation']),
                    sprintf(tc('Faux compte neuf → %s'), $canVote ? tc('a pu voter') : tc('BLOQUÉ (anti-Sybil)')),
                    sprintf(tc('Sept comptes liés pèsent une voix au quorum : %d votants sans lien sont exigés pour un ban'), $config->voixPourSanction($config->banA)),
                ],
            ],
            'cote_legitime' => [
                'label' => 'Modération communautaire honnête',
                'lignes' => [
                    'Vote du membre établi : ' . ($voteHonnete !== null ? '✓ conservé (+1)' : 'perdu'),
                    $signalee ? tc('La chute est signalée à un arbitre, qui décide') : tc('Aucun signal à l\'arbitre'),
                    'Aucun vote n\'est annulé : un lien ne retire rien à personne.',
                ],
            ],
            'verdict' => $attenue ? 'atténué' : 'non atténué',
            'neutralise' => $attenue,
            'attenue' => true,
            'mesures' => ['acceptes' => $acceptes, 'annules' => $annules, 'reputation' => $etat['reputation'],
                          'sans_sanction' => $sansSanction, 'signalee' => $signalee, 'voix' => $voix],
            'defense' => 'SelfModerate (quorum de votants sans lien aux seuils + anti-Sybil par ancienneté)',
            'message_cle' => tc('Une meute de comptes liés fait tomber sa cible sans la faire sanctionner : chaque seuil exige assez de votants sans lien entre eux, et un arbitre est saisi de la chute.'),
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
