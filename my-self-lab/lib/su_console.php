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
     */
    public static function terminal(array $mutations, string $cmd): array
    {
        $pdo = self::sandbox();
        self::seed($pdo);
        $log = [];
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
                    '  list-admins            liste les admins',
                    '  list-requests          demandes de promotion en attente',
                    '  add-admin <user>       promeut un user en admin (tracé)',
                    '  revoke-admin <user>    révoque un admin (+ coupe ses sessions)',
                    '  audit                  intégrité du log + admins fantômes',
                    '  show-log               journal SU (append-only + HMAC)',
                    '  read-memo <user>       tente de lire un mémo E2E  🔒',
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
                if (!$rows) { $out[] = '  (aucun)'; }
                break;
            case 'list-requests':
                $out = [
                    'Demandes de promotion en attente :',
                    '  #1  cible=user_lambda  proposé par=admin_demo  statut=pending',
                    '  → approuve avec : add-admin user_lambda',
                ];
                break;
            case 'add-admin':
                if ($arg === '') { $out = ['usage: add-admin <username>']; break; }
                $ok = self::applyMutation($pdo, "add-admin $arg", $log);
                $out = $ok
                    ? ["✅ « $arg » promu admin — action écrite au journal SU (HMAC)."]
                    : ["✗ compte « $arg » introuvable dans la sandbox (essaie: user_lambda)."];
                $mutating = $ok;
                break;
            case 'revoke-admin':
                if ($arg === '') { $out = ['usage: revoke-admin <username>']; break; }
                $ok = self::applyMutation($pdo, "revoke-admin $arg", $log);
                $out = $ok
                    ? ["✅ « $arg » n'est plus admin — sessions coupées, action tracée."]
                    : ["✗ « $arg » n'était pas admin."];
                $mutating = $ok;
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
                if (!$log) { $out[] = '  (aucune action encore)'; }
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

    /** Applique une mutation (add/revoke-admin) sur la sandbox + trace au log. Retourne true si effet. */
    private static function applyMutation(PDO $pdo, string $m, array &$log): bool
    {
        $p    = preg_split('/\s+/', trim($m)) ?: [];
        $verb = strtolower($p[0] ?? '');
        $arg  = $p[1] ?? '';
        if ($arg === '' || !in_array($verb, ['add-admin', 'revoke-admin'], true)) {
            return false;
        }
        $val  = $verb === 'add-admin' ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE accounts SET is_admin = ? WHERE username = ?');
        $stmt->execute([$val, $arg]);
        if ($stmt->rowCount() > 0) {
            $log[] = "$verb $arg   (opérateur=su · HMAC ✓)";
            return true;
        }
        return false;
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
                ['action' => 'Tente de lire le mémo E2E de user_lambda', 'resultat' => 'obtient « ' . substr($blob, 0, 24) . '… » — chiffré, illisible'],
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
                'label'   => 'Ce que voit l\'admin quand un litige L3 arrive',
                'litige'  => 'LIT-DEMO-0000000000000000',
                'compte'  => 'demandeur_fictif',
                'statut'  => 'awaiting_admin',
                'passif'  => [
                    ['ok' => false, 'label' => 'IP déjà utilisée par ce compte',
                     'detail' => 'IP jamais vue pour ce compte'],
                ],
                'declaratif' => [
                    ['ok' => true,  'label' => 'Année de création',        'dit' => '2026',     'reel' => '2026'],
                    ['ok' => false, 'label' => 'Dernière connexion (mois)', 'dit' => '03/2024',  'reel' => 'jamais connecté'],
                    ['ok' => false, 'label' => 'Fréquence d\'usage',        'dit' => 'intensif', 'reel' => 'rare (~0 connexions)'],
                ],
                'resume' => '0/1 passifs · 1/3 déclaratifs concordants',
                'note'   => 'Aucun score n\'est calculé. Un nombre invite à l\'entériner ; '
                          . 'des faits obligent à les lire. Et confirmer n\'ouvre pas le compte : '
                          . 'le propriétaire repose lui-même ses secrets ensuite.',
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
                ['action' => 'Tente de lire le mémo E2E de user_lambda', 'resultat' => 'obtient « ' . substr($blob, 0, 24) . '… » — MÊME le SU ne le déchiffre pas'],
            ],
            'peut' => ['label' => 'Ce que le SU PEUT', 'lignes' => [
                'Approuver / rejeter les promotions (créer les admins)',
                'Révoquer un admin + couper ses sessions',
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
