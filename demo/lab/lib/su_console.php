<?php
/**
 * MySelf-Lab — Vitrine pédagogique SU→Admin→User (100 % SANDBOX).
 *
 * Le sélecteur de rôle (👤 user / 🛡️ admin / 🔑 SU) montre, pour chaque niveau,
 * ce qu'il PEUT et ce qu'il NE PEUT PAS — sur une base SQLite JETABLE (::memory:),
 * jamais data/lab.db, jamais un vrai privilège. Le rôle est un simple paramètre
 * de VUE : il n'est jamais écrit dans app_sessions ni comparé au vrai is_admin.
 * La séparation des pouvoirs réelle (session → is_admin → require_admin) est intacte.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

final class SuConsole
{
    public const ROLES = ['user', 'admin', 'su'];
    /** Mot de passe démo PUBLIC et assumé — clin d'œil « le SU s'authentifie ». Aucun pouvoir réel. */
    public const DEMO_SU_PASSWORD = 'test-su';

    /** Sandbox SQLite en mémoire : schéma appliqué, isolée, jetable (identique à attack_sim). */
    private static function sandbox(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));
        return $pdo;
    }

    /** Mini-écosystème jetable : un user lambda + un admin. Le SU n'existe PAS en base (secret hors DB). */
    private static function seed(PDO $pdo): array
    {
        $old = time() - 3 * 86400;
        $ins = $pdo->prepare('INSERT INTO accounts (username,pw_hash,pass_hash,recovery_hash,is_admin,created_at) VALUES (?,?,?,?,?,?)');
        $ins->execute(['user_lambda', 'x', 'x', 'x', 0, $old]);
        $user = (int) $pdo->lastInsertId();
        $ins->execute(['admin_demo', 'x', 'x', 'x', 1, $old]);
        $admin = (int) $pdo->lastInsertId();
        return ['user' => $user, 'admin' => $admin];
    }

    public static function run(string $role): array
    {
        if (!in_array($role, self::ROLES, true)) {
            return ['ok' => false, 'message' => 'Rôle inconnu.'];
        }
        $pdo = self::sandbox();
        $ids = self::seed($pdo);
        // Le mémo E2E de user_lambda : ce que le SERVEUR stocke = un blob chiffré (la clé n'est jamais côté serveur).
        $memoBlob = base64_encode(random_bytes(48));

        return match ($role) {
            'user'  => self::viewUser(),
            'admin' => self::viewAdmin($memoBlob),
            'su'    => self::viewSu($pdo, $ids, $memoBlob),
        };
    }

    /**
     * Terminal SU SIMULÉ (aucun pouvoir réel). Reproduit les commandes du vrai CLI selfrecover-su
     * sur une sandbox ::memory:. L'état (admins) est cohérent entre commandes car le client renvoie
     * l'historique des mutations déjà validées ($mutations), rejoué avant la commande courante.
     *
     * La sandbox part de l'état d'une instance en service : admin_demo a été nommé par
     * `first-admin` à l'installation, et user_lambda attend une promotion proposée par lui.
     */
    public static function terminal(array $mutations, string $cmd): array
    {
        $pdo = self::sandbox();
        self::seed($pdo);
        $log = ['first-admin admin_demo   (installation · opérateur=su · HMAC ✓)'];
        // Rejeu de l'historique (borné : anti-abus)
        foreach (array_slice($mutations, 0, 100) as $m) {
            self::applyMutation($pdo, (string) $m, $log);
        }

        $cmd   = trim($cmd);
        $parts = preg_split('/\s+/', $cmd) ?: [];
        $verb  = strtolower($parts[0] ?? '');
        $arg   = $parts[1] ?? '';
        $out = [];
        $mutating = false;

        switch ($verb) {
            case '':
                break;
            case 'help':
                $out = [
                    'Commandes SU (démo — sandbox, aucun effet réel) :',
                    '  list-admins                        liste les admins',
                    '  list-requests                      demandes de promotion en attente',
                    '  approve-request <n>                tranche une demande : le compte devient admin',
                    '  first-admin <user>                 nomme le PREMIER admin — une seule fois',
                    '  revoke-admin <user>                révoque un admin (+ coupe ses sessions)',
                    '  revoke-admin <user> --remplacant <autre>',
                    '                                     remplace le dernier admin sans jamais passer par zéro',
                    '  audit                              intégrité du log + admins fantômes',
                    '  show-log                           journal SU (append-only + HMAC)',
                    '  reset-shell | reset-db             les deux remises à zéro (non simulées)',
                    '  read-memo <user>                   tente de lire un mémo E2E  🔒',
                    '  whoami | help | clear',
                    'Comptes de la sandbox : user_lambda (user), admin_demo (admin).',
                ];
                break;
            case 'whoami':
                $out = ['SuperUser (SU) — juge de dernier recours. En vrai : CLI hors-ligne, jamais sur le web.'];
                break;
            case 'list-admins':
                $rows = $pdo->query('SELECT username FROM accounts WHERE is_admin = 1 ORDER BY username')->fetchAll();
                $out[] = 'Admins (' . count($rows) . ') :';
                foreach ($rows as $r) { $out[] = '  ● ' . $r['username']; }
                break;
            case 'list-requests':
                $out = self::demandeOuverte($pdo)
                    ? [
                        'Demandes de promotion en attente :',
                        '  #1  cible=user_lambda  proposé par=admin_demo  statut=pending',
                        '  → tranche avec : approve-request 1',
                    ]
                    : ['Demandes de promotion en attente : (aucune)'];
                break;
            case 'first-admin':
                $out = [
                    '✗ refusé — le premier admin a déjà été nommé (admin_demo, à l\'installation).',
                    '  Cette voie ne sert qu\'une fois. Elle ne se rouvre qu\'après reset-shell ou reset-db.',
                    '  Un admin de plus : un admin le propose, le SU tranche (list-requests).',
                ];
                break;
            case 'add-admin':
                $out = [
                    '✗ add-admin est retiré : le SU ne fabrique plus d\'admin seul.',
                    '  → first-admin pour le premier, puis approve-request sur la demande d\'un admin.',
                ];
                break;
            case 'approve-request':
            case 'revoke-admin':
                [$ok, $out] = self::mutation($pdo, $cmd, $log);
                $mutating = $ok;
                break;
            case 'reset-shell':
                $out = [
                    'reset-shell — non simulé ici. En vrai : le SU a perdu sa passphrase.',
                    '  Tous les admins sont révoqués, les comptes restent, le journal est figé.',
                    '  La voie first-admin se rouvre : c\'est le seul moment où la base n\'a aucun admin.',
                ];
                break;
            case 'reset-db':
                $out = [
                    'reset-db — non simulé ici. En vrai : une compromission, on met tout le monde dehors.',
                    '  La base et le secret SU sont figés (gardés comme pièces), une base vide repart.',
                    '  Le journal, lui, est gardé : il affiche le reset-db en bandeau.',
                    '  ⚠️ Il ne chasse pas un intrus qui tient encore le SERVEUR.',
                ];
                break;
            case 'audit':
                $out = [
                    '🔎 Audit SU :',
                    '  ✅ Log intègre (' . count($log) . ' entrée(s), chaîne HMAC vérifiée)',
                    '  ✅ Aucun admin fantôme : chaque admin en base correspond au log.',
                ];
                break;
            case 'show-log':
                $out = ['Journal SU (append-only + HMAC, hors DB/webroot) :'];
                foreach ($log as $i => $e) { $out[] = '  ' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '  ' . $e; }
                break;
            case 'read-memo':
                if ($arg === '') { $out = ['usage: read-memo <username>']; break; }
                $blob = base64_encode(random_bytes(48));
                $out = [
                    "Lecture du mémo E2E de « $arg » — ce que le serveur/SU voit :",
                    '  ' . $blob,
                    '  ⛔ Illisible : chiffré côté client, la clé n\'a JAMAIS touché le serveur.',
                    '  → Même le SuperUser ne lit pas ton secret. 🔒',
                ];
                break;
            case 'clear':
                $out = ['__CLEAR__'];
                break;
            default:
                $out = ["commande inconnue : « $verb » — tape « help »."];
        }

        return ['ok' => true, 'echo' => $cmd, 'output' => $out, 'mutating' => $mutating];
    }

    /** La demande #1 (user_lambda, proposée par admin_demo) est-elle encore ouverte ? */
    private static function demandeOuverte(PDO $pdo): bool
    {
        return (int) $pdo->query("SELECT is_admin FROM accounts WHERE username = 'user_lambda'")->fetchColumn() === 0;
    }

    private static function estAdmin(PDO $pdo, string $user): ?bool
    {
        $st = $pdo->prepare('SELECT is_admin FROM accounts WHERE username = ?');
        $st->execute([$user]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v === 1;
    }

    /** Rejoue une mutation déjà validée : seul l'effet compte, la sortie est jetée. */
    private static function applyMutation(PDO $pdo, string $m, array &$log): void
    {
        self::mutation($pdo, $m, $log);
    }

    /**
     * approve-request et revoke-admin, avec les gardes de la vraie console :
     * le dernier admin ne tombe que remplacé, et le remplaçant est promu AVANT
     * que l'autre soit révoqué — la base ne passe jamais par zéro admin.
     *
     * @return array{0: bool, 1: list<string>} [effet appliqué, lignes à afficher]
     */
    private static function mutation(PDO $pdo, string $m, array &$log): array
    {
        $p    = preg_split('/\s+/', trim($m)) ?: [];
        $verb = strtolower($p[0] ?? '');
        $arg  = $p[1] ?? '';
        $set  = $pdo->prepare('UPDATE accounts SET is_admin = ? WHERE username = ?');

        if ($verb === 'approve-request') {
            if ($arg === '') { return [false, ['usage: approve-request <n>']]; }
            if ($arg !== '1' || !self::demandeOuverte($pdo)) {
                return [false, ["✗ aucune demande #$arg en attente (list-requests)."]];
            }
            $set->execute([1, 'user_lambda']);
            $log[] = 'approve-request #1 user_lambda   (proposé par admin_demo · opérateur=su · HMAC ✓)';
            return [true, ['✅ demande #1 tranchée : « user_lambda » devient admin — action écrite au journal SU (HMAC).']];
        }

        if ($verb !== 'revoke-admin') {
            return [false, []];
        }
        if ($arg === '') { return [false, ['usage: revoke-admin <username> [--remplacant <username>]']]; }
        if (self::estAdmin($pdo, $arg) !== true) {
            return [false, ["✗ « $arg » n'est pas admin."]];
        }
        $nbAdmins = (int) $pdo->query('SELECT COUNT(*) FROM accounts WHERE is_admin = 1')->fetchColumn();
        $remplacant = ($p[2] ?? '') === '--remplacant' ? ($p[3] ?? '') : '';

        if ($remplacant === '') {
            if ($nbAdmins <= 1) {
                return [false, [
                    "✗ refusé — « $arg » est le dernier admin : la base en garde toujours un.",
                    "  → revoke-admin $arg --remplacant <user>  (remplacement atomique)",
                ]];
            }
            $set->execute([0, $arg]);
            $log[] = "revoke-admin $arg   (sessions coupées · opérateur=su · HMAC ✓)";
            return [true, ["✅ « $arg » n'est plus admin — sessions coupées, action tracée."]];
        }

        $etat = self::estAdmin($pdo, $remplacant);
        if ($etat === null) {
            return [false, ["✗ compte « $remplacant » introuvable dans la sandbox."]];
        }
        if ($etat) {
            return [false, ["✗ « $remplacant » est déjà admin : un remplaçant doit être promu."]];
        }
        $pdo->beginTransaction();
        $set->execute([1, $remplacant]);
        $set->execute([0, $arg]);
        $pdo->commit();
        $log[] = "replace-admin $arg → $remplacant   (une transaction · opérateur=su · HMAC ✓)";
        return [true, ["✅ « $remplacant » promu puis « $arg » révoqué, dans la même transaction — jamais zéro admin."]];
    }

    private static function viewUser(): array
    {
        return [
            'ok' => true, 'role' => 'user',
            'titre' => '👤 Utilisateur (user_lambda)',
            'sous_titre' => 'Un membre lambda — le socle du modèle',
            'etapes' => [
                ['action' => 'Ouvre SON mémo chiffré', 'resultat' => 'déchiffré côté client avec SA clé (jamais sur le serveur)'],
                ['action' => 'Tente de voir la file des promotions admin', 'resultat' => 'refusé — réservé aux admins'],
                ['action' => 'Tente de lire le mémo d\'un autre membre', 'resultat' => 'refusé — chiffré E2E, ce n\'est pas sa clé'],
            ],
            'peut' => ['label' => 'Ce qu\'un user PEUT', 'lignes' => [
                'Lire et écrire SON propre mémo (chiffré E2E côté client)',
                'Publier, voter, ouvrir un litige de récupération',
                'Gérer son profil',
            ]],
            'ne_peut_pas' => ['label' => 'Ce qu\'il NE PEUT PAS', 'lignes' => [
                'Modérer, bannir, gracier',
                'Voir ou trancher les demandes de promotion',
                'Lire les mémos / DM des autres membres',
            ]],
            'cle' => 'Un compte lambda n\'a AUCUN pouvoir sur les autres — la séparation des pouvoirs commence ici.',
        ];
    }

    private static function viewAdmin(string $blob): array
    {
        return [
            'ok' => true, 'role' => 'admin',
            'titre' => '🛡️ Administrateur (admin_demo)',
            'sous_titre' => 'Modère et PROPOSE — mais ne tranche pas les rôles',
            'etapes' => [
                ['action' => 'Bannit temporairement un membre abusif', 'resultat' => 'OK — action de modération'],
                ['action' => 'PROPOSE la promotion de user_lambda en admin', 'resultat' => 'OK — demande créée, en attente du SU'],
                ['action' => 'Tente de s\'auto-promouvoir SU', 'resultat' => 'refusé — un admin ne se promeut pas lui-même'],
                ['action' => 'Tente de lire le mémo E2E de user_lambda', 'resultat' => 'chiffré, illisible', 'valeur' => substr($blob, 0, 24) . '…'],
            ],
            'peut' => ['label' => 'Ce qu\'un admin PEUT', 'lignes' => [
                'Modérer : ban / grâce, arbitrer les litiges L3',
                'PROPOSER une promotion (jamais l\'appliquer seul)',
                'Consulter les signaux de modération',
            ]],
            'ne_peut_pas' => ['label' => 'Ce qu\'il NE PEUT PAS', 'lignes' => [
                'Trancher une promotion (c\'est le rôle du SU)',
                'Se promouvoir lui-même / fabriquer un admin',
                'Lire le mémo E2E d\'un membre (blob chiffré)',
            ]],
            'cle' => 'L\'admin propose, il ne dispose pas : impossible de fabriquer de nouveaux admins seul.',

            /*
             * Aperçu du panneau d'arbitrage — DONNÉES ENTIÈREMENT FICTIVES.
             *
             * 🔑 Rien n'est lu de data/lab.db. Un panneau réel en lecture seule
             * aurait exposé Admin::decryptReport, c'est-à-dire les rapports de
             * vulnérabilités non encore corrigées : le read-only protège des
             * écritures, or ici c'est la lecture qui est sensible.
             *
             * Ce qu'on montre est la seule chose qui mérite de l'être : comment
             * un faisceau de faits se présente à celui qui décide.
             */
            'apercu' => [
                'label' => 'Ce que voit un admin dans /admin.php',
                'intro' => 'Reproduction fidèle, données fictives. À retenir : l\'admin déchiffre '
                         . 'profils et messages privés, mais bute sur le mémo.',
                // Bandeau de compteurs, comme en tête du vrai panneau.
                'kpi' => [
                    ['n' => '7',   'l' => 'comptes'],
                    ['n' => '2',   'l' => 'nouveaux 24h'],
                    ['n' => '4/6', 'l' => 'sujets/posts'],
                    ['n' => '1',   'l' => 'dm'],
                    ['n' => '3',   'l' => 'échecs login 24h', 'ton' => 'warn'],
                    ['n' => '1',   'l' => 'votes bloqués',    'ton' => 'warn'],
                    ['n' => '1',   'l' => 'rapports neufs',   'ton' => 'warn'],
                ],
                'sections' => [
                    ['titre' => '🔓 Échecs de login récents', 'hint' => 'bruteforce ?', 'demi' => true,
                     'colonnes' => ['Compte visé', 'IP', 'Quand'],
                     'lignes' => [['demandeur_fictif', '203.0.113.4', '01/08 15:47'],
                                  ['demandeur_fictif', '203.0.113.4', '01/08 15:43']]],

                    ['titre' => '🗳️ Votes neutralisés', 'hint' => 'Sybil / pack', 'demi' => true,
                     'colonnes' => ['Votant', 'Cible', 'Raison', 'Quand'],
                     'lignes' => [['compte_a', 'compte_b', 'pack-voting', 'hier']]],

                    ['titre' => '👥 Comptes',
                     'colonnes' => ['ID', 'Identifiant', 'Réputation', 'Créé', 'Profil déchiffré', 'Modération'],
                     'lignes' => [
                        ['42', '@membre_fictif', '★ 20', '01/08 14:56', 'voir (mémo)', 'bannir · gracier'],
                        ['41', '@demandeur_fictif', '★ 18', '01/08 14:56', 'voir (mémo)', 'bannir · gracier'],
                     ],
                     'cle' => '⚠️ Le profil EST lisible : clé serveur, pas clé utilisateur. Limite assumée.'],

                    ['titre' => '🧑‍⚖️ Litiges — récupération niveau 3', 'faisceau' => true,
                     'colonnes' => ['Litige', 'Compte revendiqué', 'Statut', 'Actions'],
                     'lignes' => [['LIT-DEMO-0042', '@demandeur_fictif', 'awaiting_admin', 'confirmer · refuser']],
                     'cle' => 'Confirmer n\'ouvre aucun accès : le propriétaire repose lui-même ses secrets.'],

                    ['titre' => '📨 Rapports red team',
                     'colonnes' => ['#', 'Pseudo', 'Sévérité', 'Cible', 'Statut', 'Reçu', 'Actions'],
                     'lignes' => [['7', 'chercheur_fictif', 'moyen', 'memo', 'nouveau', '01/08 11:07', 'lire · valider']],
                     'cle' => '⚠️ Section la plus sensible : failles non corrigées. D\'où cette reproduction fictive.'],
                ],
                'faisceau' => [
                    'passif' => [
                        ['ok' => false, 'label' => 'IP déjà utilisée par ce compte',
                         'detail' => 'IP jamais vue pour ce compte'],
                    ],
                    'declaratif' => [
                        ['ok' => true,  'label' => 'Année de création',         'dit' => '2026',     'reel' => '2026'],
                        ['ok' => false, 'label' => 'Dernière connexion (mois)', 'dit' => '03/2024',  'reel' => 'jamais connecté'],
                        ['ok' => false, 'label' => 'Fréquence d\'usage',        'dit' => 'intensif', 'reel' => 'rare (~0 connexions)'],
                    ],
                    'resume' => '0/1 passifs · 1/3 déclaratifs concordants',
                ],
                'mur' => [
                    'label' => 'Ce sur quoi l\'admin bute',
                    'lignes' => [
                        'Mémo E2E : illisible même pour lui',
                        'Mot de récupération → jamais reçu, seule sa dérivée circule',
                        'Codes de secours → Argon2id, aucun moyen de les relire',
                    ],
                ],
            ],
        ];
    }

    private static function viewSu(PDO $pdo, array $ids, string $blob): array
    {
        // Dans la sandbox, le SU "approuve" la promotion → user_lambda devient admin (jetable, sans effet réel).
        $pdo->prepare('UPDATE accounts SET is_admin = 1 WHERE id = ?')->execute([$ids['user']]);
        return [
            'ok' => true, 'role' => 'su',
            'titre' => '🔑 SuperUser (SU)',
            'sous_titre' => 'Le juge de dernier recours — CLI / hors-ligne, jamais sur le web',
            'etapes' => [
                ['action' => 'Approuve la promotion de user_lambda', 'resultat' => 'OK — user_lambda devient admin (action tracée)'],
                ['action' => 'Révoque un admin compromis', 'resultat' => 'OK — is_admin=0 + sessions coupées (tracé)'],
                ['action' => 'Chaque action est écrite au journal', 'resultat' => 'log append-only + HMAC — infalsifiable'],
                ['action' => 'Tente de lire le mémo E2E de user_lambda', 'resultat' => 'MÊME le SU ne le déchiffre pas', 'valeur' => substr($blob, 0, 24) . '…'],
            ],
            'peut' => ['label' => 'Ce que le SU PEUT', 'lignes' => [
                'Nommer le premier admin — une seule fois, à l\'installation',
                'Approuver / rejeter les promotions (créer les admins)',
                'Révoquer un admin + couper ses sessions — jamais le dernier',
                'Auditer : tout est tracé, 0 admin fantôme',
            ]],
            'ne_peut_pas' => ['label' => 'Ce que MÊME le SU NE PEUT PAS', 'lignes' => [
                'Lire ton mémo chiffré E2E (la clé n\'a jamais touché le serveur)',
                'Agir sans laisser de trace (log append-only + HMAC)',
                'Exister sur le web : il vit en CLI, hors-ligne',
            ]],
            'cle' => 'Même le super-admin ne lit pas ton secret. Le pouvoir maximal reste borné par la crypto E2E et tracé par l\'audit.',
        ];
    }
}
