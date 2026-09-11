<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Storage;

use PDO;
use Pierroons\SelfRecover\Device\Appareil;
use Pierroons\SelfRecover\Recovery\Litige;
use RuntimeException;

/**
 * L'implémentation de référence de `StorageInterface`, sur le schéma canonique
 * de `schema.sql`.
 *
 * 🔑 **Fournie, jamais imposée.** Le contrat existe pour qu'une application
 * garde son schéma : celle qui a déjà ses tables écrit son propre adaptateur et
 * ne migre rien. Celle qui part de zéro prend ces deux fichiers et n'écrit rien.
 *
 * C'est ici, et nulle part ailleurs, que des noms de colonnes apparaissent : la
 * bibliothèque ignore que le mot mémorisé se range dans `recovery_hash`, elle
 * demande une empreinte et cet adaptateur sait où la prendre.
 *
 * Il sert le facteur « cet appareil », que les adaptateurs de démonstration ne
 * servent pas tous. Ce qui n'est qu'à lui : l'hôte de dérivation, exigé plutôt
 * que laissé vide, et remonté à l'arbitre par `faits_locaux`.
 *
 * ── Les instants ───────────────────────────────────────────────────────────
 *
 * Tout `int` qui porte un instant compte les SECONDES depuis 1970 UTC, comme
 * `StorageInterface` l'impose — son docblock fait autorité sur la liste.
 *
 * ⚠️ **Déclarer une colonne `INTEGER` ne protège de rien** — mesuré : SQLite
 * accepte `'2026-07-12 08:00:00'` dans une colonne `INTEGER`, la range en
 * `text`, et le cast rend alors `2026`. L'affinité de type n'est pas une
 * contrainte. La garde réelle est ailleurs : `Litige::PLANCHER_EPOQUE` refuse
 * ces valeurs à la construction. Les paramètres qui ne passent pas par un objet
 * de valeur traversent cet adaptateur sans être vérifiés.
 */
final class StockagePdo implements StorageInterface
{
    /**
     * @param string $hoteDerivation L'adresse sous laquelle le navigateur dérive le
     *   mot mémorisé — une constante de déploiement, jamais une valeur tirée de la
     *   requête. La prendre dans `$_SERVER['HTTP_HOST']` reviendrait à laisser
     *   l'attaquant choisir ce qu'on enregistre.
     *
     *   Vide par défaut : la plupart des sites d'instanciation comptent des codes ou
     *   révoquent des sessions et n'en ont aucun besoin. Le seul qui en a besoin est
     *   `reposerSecrets()`, et il REFUSE de s'exécuter sans, plutôt que d'écrire un
     *   marqueur vide.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $hoteDerivation = '',
    ) {
        // 🔑 `PRAGMA foreign_keys` vaut pour la CONNEXION, pas pour la base. Le
        // poser dans `schema.sql` ne sert que la connexion qui a chargé le
        // schéma — souvent `sqlite3(1)`, jetée aussitôt. Sans cette ligne, les
        // six cascades du schéma sont décoratives : mesuré, effacer un compte
        // laisse derrière lui ses codes, ses clés d'appareil, et le texte qu'il
        // a écrit à un arbitre. Rien n'échoue, rien ne prévient.
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            return;   // InnoDB et PostgreSQL appliquent leurs clés sans qu'on le demande
        }

        $this->pdo->exec('PRAGMA foreign_keys = ON');

        // 🔑 **Un `PRAGMA` posé n'est pas un `PRAGMA` appliqué.** SQLite documente
        // celui-ci comme un no-op À L'INTÉRIEUR d'une transaction : `exec()` réussit,
        // ne rend rien d'anormal, et les clés restent désactivées — mesuré, y compris
        // après le commit de cette transaction. Construire l'adaptateur sous une
        // transaction déjà ouverte suffirait donc à rendre les cascades décoratives
        // pour toute la vie de la connexion, en silence.
        if ((int) $this->pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new RuntimeException(
                'Les clés étrangères n\'ont pas pu être activées sur cette connexion. '
                . 'Cause la plus probable : une transaction était déjà ouverte au moment '
                . 'de construire l\'adaptateur — SQLite ignore alors ce PRAGMA. '
                . 'Construisez-le avant d\'ouvrir une transaction.'
            );
        }
    }

    // ── Freins ─────────────────────────────────────────────────────────────

    public function compterEchecsIp(string $ip, int $depuis): int
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at > ?'
        );
        $st->execute([$ip, $depuis]);

        return (int) $st->fetchColumn();
    }

    public function compterEchecsCompte(string $nomCompte, int $depuis): int
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND attempted_at > ?'
        );
        $st->execute([$nomCompte, $depuis]);

        return (int) $st->fetchColumn();
    }

    public function tracerTentative(string $etiquette, bool $succes, ?string $ip, int $quand): void
    {
        $this->pdo
            ->prepare('INSERT INTO login_attempts (username, success, ip, attempted_at) VALUES (?, ?, ?, ?)')
            ->execute([$etiquette, $succes ? 1 : 0, $ip, $quand]);
    }

    // ── Comptes ────────────────────────────────────────────────────────────

    public function trouverCompte(string $nomCompte): ?array
    {
        $st = $this->pdo->prepare('SELECT id, recovery_hash FROM accounts WHERE username = ?');
        $st->execute([$nomCompte]);
        $ligne = $st->fetch(PDO::FETCH_ASSOC);

        return $ligne === false
            ? null
            : ['id' => (int) $ligne['id'], 'empreinte_mot' => (string) $ligne['recovery_hash']];
    }

    public function trouverComptePourPassphrase(string $nomCompte): ?array
    {
        $st = $this->pdo->prepare('SELECT id, passphrase_hash, pass_emise_le FROM accounts WHERE username = ?');
        $st->execute([$nomCompte]);
        $ligne = $st->fetch(PDO::FETCH_ASSOC);
        if ($ligne === false) {
            return null;
        }

        // ⚠️ `null` reste `null`. Un compte antérieur à la colonne ne sait pas
        // quand sa passphrase a été émise, et le dire vaut mieux que rendre zéro,
        // qui afficherait un âge d'un demi-siècle à qui vient de s'inscrire.
        return [
            'id'                   => (int) $ligne['id'],
            'empreinte_passphrase' => (string) $ligne['passphrase_hash'],
            'emise_le'             => $ligne['pass_emise_le'] === null ? null : (int) $ligne['pass_emise_le'],
        ];
    }

    public function remplacerEmpreinteMotDePasse(int $compteId, string $empreinte): void
    {
        $this->pdo->prepare('UPDATE accounts SET pw_hash = ? WHERE id = ?')->execute([$empreinte, $compteId]);
    }

    public function remplacerEmpreintes(int $compteId, string $empreinteMotDePasse, string $empreintePassphrase): void
    {
        // ⚠️ La date d'émission se refait ici : une passphrase neuve est émise, et
        // garder l'ancienne date ferait vieillir un papier imprimé à l'instant.
        // C'est le geste que le contrat demande et que sa signature ne peut pas
        // imposer — même famille que l'hôte de dérivation dans `reposerSecrets()`.
        $this->pdo
            ->prepare('UPDATE accounts SET pw_hash = ?, passphrase_hash = ?, pass_emise_le = ? WHERE id = ?')
            ->execute([$empreinteMotDePasse, $empreintePassphrase, time(), $compteId]);
    }

    public function revoquerSessions(int $compteId): void
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE account_id = ?')->execute([$compteId]);
    }

    // ── Le facteur « cet appareil » ────────────────────────────────────────

    public function enregistrerAppareil(
        int $compteId,
        string $credentialId,
        string $clePubliqueB64url,
        int $quand,
    ): void {
        // `INSERT OR REPLACE` est propre à SQLite : sur MariaDB, c'est
        // `INSERT … ON DUPLICATE KEY UPDATE`, sur PostgreSQL `ON CONFLICT … DO UPDATE`.
        // Le remplacement est voulu — ré-enrôler le même appareil remplace sa clé
        // plutôt que d'en accumuler une seconde qui ouvrirait toujours.
        $this->pdo->prepare(
            'INSERT OR REPLACE INTO device_credentials (account_id, credential_id, public_key, created_at)
             VALUES (?, ?, ?, ?)'
        )->execute([$compteId, $credentialId, $clePubliqueB64url, $quand]);
    }

    public function trouverAppareil(string $credentialId): ?Appareil
    {
        $st = $this->pdo->prepare(
            'SELECT dc.public_key, a.id AS account_id, a.username
               FROM device_credentials dc
               JOIN accounts a ON a.id = dc.account_id
              WHERE dc.credential_id = ?'
        );
        $st->execute([$credentialId]);
        $ligne = $st->fetch(PDO::FETCH_ASSOC);

        return $ligne === false ? null : new Appareil(
            $credentialId,
            (string) $ligne['public_key'],
            (int) $ligne['account_id'],
            (string) $ligne['username'],
        );
    }

    public function purgerDefisExpires(int $avant): void
    {
        $this->pdo->prepare('DELETE FROM device_challenges WHERE created_at < ?')->execute([$avant]);
    }

    public function enregistrerDefi(string $defi, string $credentialId, int $quand): void
    {
        $this->pdo->prepare(
            'INSERT OR REPLACE INTO device_challenges (challenge, credential_id, created_at) VALUES (?, ?, ?)'
        )->execute([$defi, $credentialId, $quand]);
    }

    public function defiEnCours(string $defi, string $credentialId, int $depuis): bool
    {
        // Les trois conditions comptent ensemble : un défi valable pour UN
        // `credential_id` ne doit pas servir à en authentifier un autre, et la borne
        // d'âge est ce qui empêche de rejouer un défi ramassé la veille.
        $st = $this->pdo->prepare(
            'SELECT 1 FROM device_challenges WHERE challenge = ? AND credential_id = ? AND created_at > ?'
        );
        $st->execute([$defi, $credentialId, $depuis]);

        return $st->fetchColumn() !== false;
    }

    public function consommerDefi(string $defi): void
    {
        $this->pdo->prepare('DELETE FROM device_challenges WHERE challenge = ?')->execute([$defi]);
    }

    // ── Codes de récupération ──────────────────────────────────────────────

    public function purgerCodes(int $compteId): void
    {
        $this->pdo->prepare('DELETE FROM recovery_codes WHERE account_id = ?')->execute([$compteId]);
    }

    public function enregistrerCode(int $compteId, string $indexRecherche, string $empreinteCode, int $quand): void
    {
        $this->pdo->prepare(
            'INSERT INTO recovery_codes (account_id, code_lookup, code_hash, created_at) VALUES (?, ?, ?, ?)'
        )->execute([$compteId, $indexRecherche, $empreinteCode, $quand]);
    }

    public function trouverCodeParIndex(string $indexRecherche): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT c.id AS code_id, c.code_hash, c.used, a.id AS account_id, a.username, a.recovery_hash
               FROM recovery_codes c
               JOIN accounts a ON a.id = c.account_id
              WHERE c.code_lookup = ?'
        );
        $st->execute([$indexRecherche]);
        $ligne = $st->fetch(PDO::FETCH_ASSOC);

        return $ligne === false ? null : [
            'code_id'        => (int) $ligne['code_id'],
            'empreinte_code' => (string) $ligne['code_hash'],
            'deja_utilise'   => (bool) $ligne['used'],
            'compte_id'      => (int) $ligne['account_id'],
            'nom_compte'     => (string) $ligne['username'],
            'empreinte_mot'  => (string) $ligne['recovery_hash'],
        ];
    }

    public function consommerCode(int $codeId, int $quand): void
    {
        $this->pdo->prepare('UPDATE recovery_codes SET used = 1, used_at = ? WHERE id = ?')
                  ->execute([$quand, $codeId]);
    }

    public function compterCodesRestants(int $compteId): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM recovery_codes WHERE account_id = ? AND used = 0');
        $st->execute([$compteId]);

        return (int) $st->fetchColumn();
    }

    // ── Atomicité ──────────────────────────────────────────────────────────
    //
    // 🔑 **On ne valide et on n'annule que ce qu'on a soi-même ouvert.**
    //
    // PDO ne sait pas imbriquer : `beginTransaction()` sur une transaction déjà
    // ouverte lève. La parade évidente — « ne rien faire si une transaction
    // existe déjà » — traite le symptôme et fabrique un défaut pire : le
    // `commit()` qui suit valide alors la transaction de l'APPELANT, à moitié
    // remplie. Une application qui enveloppe son inscription dans sa propre
    // transaction et appelle la bibliothèque au milieu se retrouve avec un
    // travail partiel rendu durable, puis un « There is no active transaction »
    // au moment de son propre rollback — quand il est déjà trop tard.
    //
    // La réponse est le point de reprise : la transaction extérieure reste celle
    // de l'appelant, et nos écritures s'annulent dans son dos sans la toucher.
    // `SAVEPOINT` est du SQL standard, servi par SQLite, PostgreSQL et MySQL.

    /** Profondeur d'imbrication. 0 = aucune transaction ouverte par nous. */
    private int $profondeur = 0;

    /** Vrai quand la transaction la plus externe est la NÔTRE, pas celle de l'appelant. */
    private bool $transactionAutonome = false;

    private function nomDuPoint(int $profondeur): string
    {
        // ⚠️ Le nom porte l'INSTANCE, pas seulement la profondeur. Deux adaptateurs
        // construits sur la même connexion démarrent tous deux à zéro : avec un nom
        // tiré de la seule profondeur, le `RELEASE` de l'un libère le point de
        // l'autre, et son `ROLLBACK TO` retombe sur un point étranger — une écriture
        // disparaît alors sans qu'aucune exception ne le dise.
        //
        // Le préfixe, lui, sépare nos points de ceux que l'appelant pose.
        return 'selfrecover_' . spl_object_id($this) . '_' . $profondeur;
    }

    public function commencerTransaction(): void
    {
        if ($this->profondeur === 0 && !$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $this->transactionAutonome = true;
        } else {
            $this->pdo->exec('SAVEPOINT ' . $this->nomDuPoint($this->profondeur + 1));
        }

        $this->profondeur++;
    }

    public function validerTransaction(): void
    {
        if ($this->profondeur === 0) {
            return;   // rien d'ouvert de notre fait : il n'y a rien à valider
        }

        // 🔑 Un état déclaré ouvert qu'on retrouve fermé est une anomalie, pas un
        // cas nominal : SQLite a pu défaire la transaction seul (disque plein,
        // erreur d'E/S), ou l'appelant l'a annulée sur un chemin d'erreur à lui.
        // Se taire ici ferait rendre « accès rendu » sur une base inchangée.
        if (!$this->pdo->inTransaction()) {
            $this->profondeur = 0;
            $this->transactionAutonome = false;

            throw new RuntimeException(
                'La transaction a disparu avant sa validation : rien n\'a été écrit. '
                . 'Cause probable — un échec du moteur, ou un rollback posé par l\'appelant.'
            );
        }

        if ($this->profondeur > 1) {
            // ⚠️ `RELEASE` ne valide rien par lui-même : il abandonne seulement la
            // possibilité de revenir à ce point. Ce qui décide reste le `commit()`
            // de la transaction la plus externe — ou, si elle appartient à
            // l'appelant, la décision de l'appelant.
            try {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $this->nomDuPoint($this->profondeur));
            } finally {
                // ⚠️ Le décompte se fait même si l'ordre a échoué. Sans ce `finally`,
                // une exception laisse la profondeur figée : l'instance ne retrouve
                // jamais le niveau 1, donc `commit()` n'est plus jamais appelé, et
                // tout ce qu'elle écrit ensuite est défait à la fermeture.
                $this->profondeur--;
            }

            return;
        }

        if ($this->transactionAutonome && $this->pdo->inTransaction()) {
            $this->pdo->commit();
        } elseif ($this->pdo->inTransaction()) {
            // Transaction de l'appelant : il décidera. On libère seulement notre
            // point d'entrée, qui resterait sinon ouvert jusqu'à sa décision.
            $point = $this->nomDuPoint(1);

            try {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $point);
            } catch (\PDOException $e) {
                throw $this->pointDisparu($point, $e);
            }
        }

        $this->profondeur = 0;
        $this->transactionAutonome = false;
    }

    /**
     * 🔑 **Deux composants ne peuvent pas entrelacer leurs transactions sur une
     * même connexion, et aucun code ne peut le rattraper.** `RELEASE` libère le
     * point nommé ET tous ceux posés après lui : si un second adaptateur a ouvert
     * le sien entre-temps, notre validation emporte le sien, et son annulation
     * tombe sur un point qui n'existe plus.
     *
     * C'est la sémantique de SQL, pas un défaut d'ici — une base ne sait pas
     * imbriquer deux fils de transaction indépendants sur une connexion unique.
     * Ce qui est de notre ressort, c'est que l'échec le DISE : `no such savepoint`
     * nu enverrait chercher un bug dans le stockage.
     *
     * La règle qui l'évite : un seul adaptateur par connexion, ou des appels qui
     * ne s'entrelacent pas.
     */
    private function pointDisparu(string $point, \PDOException $cause): RuntimeException
    {
        return new RuntimeException(
            "Le point de reprise « {$point} » n'existe plus. Cause la plus probable : "
            . 'un autre composant a validé ou annulé sa propre transaction sur cette '
            . 'connexion entre-temps, ce qui libère aussi les points posés après le '
            . "sien. Un seul adaptateur par connexion, ou pas d'appels entrelacés.",
            0,
            $cause,
        );
    }

    public function annulerTransaction(): void
    {
        if ($this->profondeur === 0) {
            return;
        }

        if ($this->profondeur > 1) {
            $point = $this->nomDuPoint($this->profondeur);

            try {
                // Les deux ordres comptent : `ROLLBACK TO` défait les écritures mais
                // GARDE le point, qui resterait ouvert jusqu'à la fin de la
                // transaction extérieure.
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $point);
                $this->pdo->exec('RELEASE SAVEPOINT ' . $point);
            } catch (\PDOException $e) {
                throw $this->pointDisparu($point, $e);
            } finally {
                $this->profondeur--;
            }

            return;
        }

        if ($this->transactionAutonome && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        } elseif ($this->pdo->inTransaction()) {
            // 🔑 La transaction est celle de l'appelant et nous devons annuler.
            // On ne la défait pas — ce n'est pas la nôtre — mais on ne peut pas
            // non plus se taire : l'appelant validerait nos écritures ratées avec
            // les siennes. Le point de reprise posé à l'entrée porte exactement
            // nos écritures, donc on revient à lui.
            $point = $this->nomDuPoint(1);

            try {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $point);
                $this->pdo->exec('RELEASE SAVEPOINT ' . $point);
            } catch (\PDOException $e) {
                $this->profondeur = 0;
                $this->transactionAutonome = false;

                throw $this->pointDisparu($point, $e);
            }
        }

        $this->profondeur = 0;
        $this->transactionAutonome = false;
    }

    // ── Niveau 3 : dossier et arbitrage humain ─────────────────────────────

    /**
     * Une ligne de `disputes`, jointe à son compte, rendue comme la bibliothèque
     * l'attend. `claim_hash` vidé à la clôture ramène à `''` : plus aucun sésame ne
     * peut correspondre, puisqu'un SHA-256 n'est jamais vide.
     */
    private function litigeDepuis(array $ligne): Litige
    {
        return new Litige(
            id: (int) $ligne['id'],
            numero: (string) $ligne['dispute_number'],
            compteId: (int) $ligne['account_id'],
            nomCompte: (string) ($ligne['username'] ?? ''),
            statut: (string) $ligne['status'],
            empreinteSesame: (string) ($ligne['claim_hash'] ?? ''),
            creeLe: (int) $ligne['created_at'],
            expireLe: (int) $ligne['expires_at'],
            deposeLe: (int) ($ligne['submitted_at'] ?? 0),
            trancheLe: $ligne['decided_at'] === null ? null : (int) $ligne['decided_at'],
            tranchePar: $ligne['decided_by'] === null ? null : (string) $ligne['decided_by'],
            demandeursConcurrents: (int) $ligne['init_collisions'],
        );
    }

    public function trouverLitigeParNumero(string $numero): ?Litige
    {
        $st = $this->pdo->prepare(
            'SELECT d.*, a.username FROM disputes d
             LEFT JOIN accounts a ON a.id = d.account_id
             WHERE d.dispute_number = ?'
        );
        $st->execute([$numero]);
        $ligne = $st->fetch(PDO::FETCH_ASSOC);

        return $ligne === false ? null : $this->litigeDepuis($ligne);
    }

    public function litigeActifDuCompte(int $compteId, int $maintenant): ?Litige
    {
        // ⚠️ `accepted` compte comme actif ET échappe au TTL, comme dans
        // `Escalade::recevable()`. Deux raisons distinctes :
        //
        // — l'horloge ne doit pas annuler le travail de l'arbitre : entre l'accord
        //   et le retour du titulaire, il peut s'écouler plus que le TTL ;
        // — entre l'accord et le ré-enrôlement, le compte est au plus vulnérable, et
        //   y laisser ouvrir un second dossier sans le signaler priverait l'arbitre
        //   de l'information la plus utile du moment.
        $st = $this->pdo->prepare(
            "SELECT d.*, a.username FROM disputes d
             LEFT JOIN accounts a ON a.id = d.account_id
             WHERE d.account_id = ? AND d.status IN ('open', 'awaiting_admin', 'accepted')
               AND (d.status = 'accepted' OR d.expires_at > ?)
             ORDER BY d.id DESC LIMIT 1"
        );
        $st->execute([$compteId, $maintenant]);
        $ligne = $st->fetch(PDO::FETCH_ASSOC);

        return $ligne === false ? null : $this->litigeDepuis($ligne);
    }

    public function ouvrirLitige(
        int $compteId,
        string $numero,
        string $empreinteSesame,
        int $quand,
        int $expireLe,
    ): void {
        $this->pdo->prepare(
            "INSERT INTO disputes (dispute_number, account_id, status, claim_hash, expires_at, created_at, updated_at)
             VALUES (?, ?, 'open', ?, ?, ?, ?)"
        )->execute([$numero, $compteId, $empreinteSesame, $expireLe, $quand, $quand]);
    }

    public function compterDemandeurConcurrent(int $litigeId): void
    {
        $this->pdo->prepare('UPDATE disputes SET init_collisions = init_collisions + 1 WHERE id = ?')
                  ->execute([$litigeId]);
    }

    public function enregistrerFaisceau(int $litigeId, string $faisceauJson, int $quand): void
    {
        $this->pdo->prepare(
            "UPDATE disputes SET signals_json = ?, status = 'awaiting_admin', submitted_at = ?, updated_at = ?
             WHERE id = ?"
        )->execute([$faisceauJson, $quand, $quand, $litigeId]);
    }

    public function trancherLitige(int $litigeId, string $statut, string $par, int $quand): void
    {
        $this->pdo->prepare(
            'UPDATE disputes SET status = ?, decided_at = ?, decided_by = ?, updated_at = ? WHERE id = ?'
        )->execute([$statut, $quand, $par, $quand, $litigeId]);
    }

    public function cloreLitige(int $litigeId, int $quand): void
    {
        // ⚠️ `claim_hash` passe à NULL : le sésame ne doit plus rien rouvrir. Le fil
        // de messages, lui, reste lisible — il ne regarde pas le statut du dossier.
        $this->pdo->prepare(
            "UPDATE disputes SET status = 'closed', claim_hash = NULL, updated_at = ? WHERE id = ?"
        )->execute([$quand, $litigeId]);
    }

    public function compterRefusRecents(int $compteId, int $depuis): int
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM disputes WHERE account_id = ? AND status = 'refused' AND decided_at > ?"
        );
        $st->execute([$compteId, $depuis]);

        return (int) $st->fetchColumn();
    }

    public function poserGel(int $compteId, int $jusqua, int $quand): void
    {
        $this->pdo->prepare(
            'INSERT INTO l3_gel (account_id, gele_jusqu_a, pose_le) VALUES (?, ?, ?)
             ON CONFLICT(account_id) DO UPDATE SET gele_jusqu_a = excluded.gele_jusqu_a,
                                                   pose_le      = excluded.pose_le,
                                                   degele_par   = NULL,
                                                   degele_le    = NULL'
        )->execute([$compteId, $jusqua, $quand]);
    }

    public function gelJusqua(int $compteId, int $maintenant): int
    {
        $st = $this->pdo->prepare('SELECT gele_jusqu_a FROM l3_gel WHERE account_id = ?');
        $st->execute([$compteId]);
        $valeur = (int) ($st->fetchColumn() ?: 0);

        return $valeur > $maintenant ? $valeur : 0;
    }

    public function leverGel(int $compteId, string $par, int $quand): void
    {
        // La ligne est gardée, pas supprimée : qui a dégelé et quand vaut d'être
        // conservé, y compris pour l'arbitre suivant.
        $this->pdo->prepare(
            'UPDATE l3_gel SET gele_jusqu_a = 0, degele_par = ?, degele_le = ? WHERE account_id = ?'
        )->execute([$par, $quand, $compteId]);
    }

    public function ajouterMessageLitige(int $litigeId, string $auteur, string $texte, int $quand): void
    {
        $this->pdo->prepare(
            'INSERT INTO dispute_messages (dispute_id, sender, body, created_at) VALUES (?, ?, ?, ?)'
        )->execute([$litigeId, $auteur, $texte, $quand]);
    }

    public function messagesDuLitige(int $litigeId): array
    {
        $st = $this->pdo->prepare(
            'SELECT sender, body, created_at FROM dispute_messages WHERE dispute_id = ? ORDER BY id'
        );
        $st->execute([$litigeId]);

        return array_map(
            static fn (array $m): array => [
                'auteur'   => (string) $m['sender'],
                'texte'    => (string) $m['body'],
                'ecrit_le' => (int) $m['created_at'],
            ],
            $st->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function listerLitiges(int $limite): array
    {
        // ⚠️ Aucune adresse IP n'est sélectionnée : elle n'a rien à faire dans une
        // console d'arbitrage, et ce schéma n'en garde d'ailleurs pas sur `disputes`.
        $st = $this->pdo->prepare(
            'SELECT d.dispute_number, d.status, d.signals_json, d.init_collisions, d.created_at,
                    d.submitted_at, d.decided_at, d.decided_by, a.username,
                    (SELECT COUNT(*) FROM dispute_messages m WHERE m.dispute_id = d.id) AS messages,
                    (SELECT g.gele_jusqu_a FROM l3_gel g
                      WHERE g.account_id = a.id AND g.gele_jusqu_a > ?) AS gele_jusqu_a
               FROM disputes d JOIN accounts a ON a.id = d.account_id
              ORDER BY d.id DESC LIMIT ?'
        );
        // ⚠️ Un gel ÉCHU n'est pas un gel. Sans cette borne, la console affiche
        // « ouverture gelée jusqu'au <date passée> » sur un compte qui ne l'est plus,
        // et propose de lever un gel qui n'existe pas. `gelJusqua()` applique déjà la
        // même règle ; les deux lectures doivent dire pareil.
        $st->execute([time(), $limite]);
        $lignes = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lignes as &$ligne) {
            $ligne['faisceau'] = $ligne['signals_json'] === null
                ? null
                : json_decode((string) $ligne['signals_json'], true);
            unset($ligne['signals_json']);
        }

        return $lignes;
    }

    public function purgerLitigesExpires(int $avant): int
    {
        // ⚠️ Les dossiers REFUSÉS survivent à la purge : le gel se calcule en comptant
        // les refus d'une fenêtre glissante, bien plus longue que la durée de vie
        // d'un dossier (les deux sont réglables au constructeur d'`Escalade`). Les
        // effacer viderait le compteur avant qu'il atteigne son seuil, et le gel —
        // seule protection contre l'acharnement — deviendrait inatteignable sans
        // qu'aucune sonde ne rougisse.
        //
        // ⚠️ Les dossiers ACCEPTÉS y survivent aussi, pour la raison qui fait que
        // `litigeActifDuCompte` les exempte du TTL : le titulaire qui revient après
        // l'expiration doit encore trouver son accord. Les purger fermerait la porte
        // définitivement à qui a déjà tout perdu.
        $st = $this->pdo->prepare(
            "DELETE FROM disputes WHERE expires_at <= ? AND status NOT IN ('refused', 'accepted')"
        );
        $st->execute([$avant]);

        return $st->rowCount();
    }

    public function faitsDuCompte(int $compteId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, username, created_at, last_login_at, login_count, pass_emise_le, derivation_host
               FROM accounts WHERE id = ?'
        );
        $st->execute([$compteId]);
        $ligne = $st->fetch(PDO::FETCH_ASSOC);
        if ($ligne === false) {
            return null;
        }

        // 🔑 `login_count` est NOT NULL DEFAULT 0 : « jamais enregistré » y est
        // indiscernable de « zéro connexion ». On rend donc `null` pour les deux
        // faits tant que `last_login_at` est vide, plutôt que de laisser un compte
        // non tracé produire « rare » — ce qui ferait diverger la réponse honnête
        // d'un titulaire légitime.
        $jamais = $ligne['last_login_at'] === null;

        return [
            'id'                 => (int) $ligne['id'],
            'nom_compte'         => (string) $ligne['username'],
            'cree_le'            => (int) $ligne['created_at'],
            'derniere_connexion' => $jamais ? null : (int) $ligne['last_login_at'],
            'nombre_connexions'  => $jamais ? null : (int) $ligne['login_count'],
            // Les faits que seul ce déploiement connaît. `Escalade` les rend tels
            // quels sous `contexte.local`, sans les interpréter : l'arbitre les lit,
            // la bibliothèque ne sait pas ce qu'ils veulent dire.
            //
            // ⚠️ Jamais un secret, jamais une empreinte — ce qui entre ici est montré
            // à un humain.
            'faits_locaux'       => [
                // « Passphrase émise il y a trois ans, jamais utilisée » dit quelque
                // chose à un arbitre : qui perd tout après des années n'a pas le
                // profil de qui s'est inscrit la semaine dernière.
                'passphrase_emise_le' => $ligne['pass_emise_le'] === null
                    ? null
                    : gmdate('Y-m-d', (int) $ligne['pass_emise_le']),
                // L'adresse d'enrôlement. Après un changement de domaine, elle
                // distingue les comptes de l'ancienne génération de ceux de la
                // nouvelle — et un dossier ouvert sur un compte dont l'hôte ne
                // ressemble à rien de connu mérite un regard.
                'hote_derivation'     => ($ligne['derivation_host'] ?? '') === ''
                    ? null
                    : (string) $ligne['derivation_host'],
            ],
        ];
    }

    public function reposerSecrets(
        int $compteId,
        string $empreinteMotDePasse,
        string $empreintePassphrase,
        string $empreinteMotDerive,
        string $sel,
    ): void {
        // 🔑 Refus plutôt qu'écriture d'un marqueur vide. Le contrat ne transporte pas
        // l'hôte — c'est une constante de déploiement, pas une donnée d'appel — donc
        // il arrive par le constructeur, et son absence est une erreur de câblage, pas
        // un cas d'usage. Laisser passer écrirait `derivation_host = ''` sur un compte
        // dont le navigateur vient de dériver sur une adresse bien réelle : le
        // marqueur mentirait, et il mentirait au moment précis où quelqu'un revient
        // d'une perte totale.
        if ($this->hoteDerivation === '') {
            throw new RuntimeException(
                'StockagePdo a été construit sans hôte de dérivation : reposerSecrets() '
                . 'écrirait un derivation_host vide sur un compte qui vient d\'en '
                . 'utiliser un réel. Passez-le au constructeur.'
            );
        }

        // ⚠️ `pass_emise_le` se refait ici aussi : ce chemin émet une passphrase
        // neuve, exactement comme `remplacerEmpreintes()`. L'oublier afficherait
        // l'âge de l'ancienne sur celle qui vient d'être imprimée.
        $this->pdo->prepare(
            'UPDATE accounts SET pw_hash = ?, passphrase_hash = ?, recovery_hash = ?, recovery_salt = ?,
                                 derivation_host = ?, pass_emise_le = ? WHERE id = ?'
        )->execute([
            $empreinteMotDePasse, $empreintePassphrase, $empreinteMotDerive, $sel,
            $this->hoteDerivation, time(), $compteId,
        ]);
    }
}
