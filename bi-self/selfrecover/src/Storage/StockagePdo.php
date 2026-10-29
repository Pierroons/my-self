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
final class StockagePdo implements StorageInterface, SelParCodeInterface
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

    public function compterEchecsIp(string $ip, int $depuis, array $prefixes): int
    {
        if ($prefixes === []) {
            throw new \InvalidArgumentException(
                'compterEchecsIp() sans préfixe compterait zéro : un frein muet est pire que le défaut qu il corrige.'
            );
        }

        $filtre = implode(' OR ', array_fill(0, count($prefixes), 'username LIKE ?'));
        $st     = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts'
            . ' WHERE ip = ? AND success = 0 AND attempted_at > ?'
            . ' AND (' . $filtre . ')'
        );
        $st->execute([$ip, $depuis, ...array_map(static fn (string $p): string => $p . '%', $prefixes)]);

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

    public function tracerTentative(?string $etiquette, bool $succes, ?string $ip, int $quand): void
    {
        $this->pdo
            ->prepare('INSERT INTO login_attempts (username, success, ip, attempted_at) VALUES (?, ?, ?, ?)')
            ->execute([$etiquette, $succes ? 1 : 0, $ip, $quand]);
    }

    public function dateDernierCodeEmis(int $compteId): ?int
    {
        $st = $this->pdo->prepare('SELECT MAX(created_at) FROM recovery_codes WHERE account_id = ?');
        $st->execute([$compteId]);
        $quand = $st->fetchColumn();

        return $quand === null || $quand === false ? null : (int) $quand;
    }

    public function dateDerniereReussite(string $etiquette): ?int
    {
        $st = $this->pdo->prepare(
            'SELECT MAX(attempted_at) FROM login_attempts WHERE username = ? AND success = 1'
        );
        $st->execute([$etiquette]);
        $quand = $st->fetchColumn();

        return $quand === null || $quand === false ? null : (int) $quand;
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

    public function remplacerEmpreintes(int $compteId, string $empreinteMotDePasse, string $empreintePassphrase, ?string $passphraseLue): void
    {
        // ⚠️ La date d'émission se refait ici : une passphrase neuve est émise, et
        // garder l'ancienne date ferait vieillir un papier imprimé à l'instant.
        // C'est le geste que le contrat demande et que sa signature ne peut pas
        // imposer — même famille que l'hôte de dérivation dans `reposerSecrets()`.
        if ($passphraseLue === null) {
            $this->pdo
                ->prepare('UPDATE accounts SET pw_hash = ?, passphrase_hash = ?, pass_emise_le = ? WHERE id = ?')
                ->execute([$empreinteMotDePasse, $empreintePassphrase, time(), $compteId]);

            return;
        }
        $st = $this->pdo->prepare(
            'UPDATE accounts SET pw_hash = ?, passphrase_hash = ?, pass_emise_le = ? WHERE id = ? AND passphrase_hash = ?'
        );
        $st->execute([$empreinteMotDePasse, $empreintePassphrase, time(), $compteId, $passphraseLue]);
        if ($st->rowCount() !== 1) {
            throw new PassphraseDejaConsommee('la passphrase lue n\'est plus celle du compte');
        }
    }

    public function revoquerSessions(int $compteId): void
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE account_id = ?')->execute([$compteId]);
    }

    public function revoquerAppareils(int $compteId): int
    {
        // Les défis en vol partent d'abord : ils désignent un appareil par son
        // `credential_id`, et non un compte. Les retirer après la suppression
        // des appareils laisserait des lignes que plus aucune jointure n'atteint.
        $this->pdo->prepare(
            'DELETE FROM device_challenges
              WHERE credential_id IN (SELECT credential_id FROM device_credentials WHERE account_id = ?)'
        )->execute([$compteId]);

        $st = $this->pdo->prepare('DELETE FROM device_credentials WHERE account_id = ?');
        $st->execute([$compteId]);

        return $st->rowCount();
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

    public function consommerEpreuve(string $empreinte, int $expire, int $maintenant): bool
    {
        $this->pdo->prepare('DELETE FROM l3_epreuves WHERE expire_at < ?')->execute([$maintenant]);
        $insere = $this->pdo->prepare('INSERT OR IGNORE INTO l3_epreuves (empreinte, expire_at) VALUES (?, ?)');
        $insere->execute([$empreinte, $expire]);
        return $insere->rowCount() === 1;
    }

    // ── La fenêtre d'après un niveau 3 ─────────────────────────────────────

    public function ouvrirFenetre(int $compteId, int $ouverteLe, int $fermeLe, string $ticketNiveau3): void
    {
        $existante = $this->fenetreDuCompte($compteId);
        if ($existante !== null && $existante['reprise_le'] === null && $existante['close_le'] === null
            && $existante['ferme_le'] > $ouverteLe) {
            $this->pdo->prepare('UPDATE l3_fenetres SET ferme_le = MAX(ferme_le, ?) WHERE account_id = ?')
                ->execute([$fermeLe, $compteId]);
            return;
        }
        $this->pdo->prepare('DELETE FROM l3_anciens_codes WHERE account_id = ?')->execute([$compteId]);
        $this->pdo->prepare(
            'INSERT OR REPLACE INTO l3_fenetres
                    (account_id, ouverte_le, ferme_le, passphrase_hash, recovery_hash, recovery_salt, reprise_le, close_le, close_par,
                     ticket_niveau3)
             SELECT id, ?, ?, passphrase_hash, recovery_hash, recovery_salt, NULL, NULL, NULL, ? FROM accounts WHERE id = ?'
        )->execute([$ouverteLe, $fermeLe, $ticketNiveau3, $compteId]);
        $this->pdo->prepare(
            'INSERT INTO l3_anciens_codes (account_id, code_lookup, code_hash)
             SELECT account_id, code_lookup, code_hash FROM recovery_codes WHERE account_id = ? AND used = 0'
        )->execute([$compteId]);
    }

    public function fenetreDuCompte(int $compteId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM l3_fenetres WHERE account_id = ?');
        $st->execute([$compteId]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        $entier = static fn ($v): ?int => $v === null ? null : (int) $v;

        return $f === false ? null : [
            'ouverte_le'           => (int) $f['ouverte_le'],
            'ferme_le'             => (int) $f['ferme_le'],
            'empreinte_passphrase' => $f['passphrase_hash'],
            'empreinte_mot'        => $f['recovery_hash'],
            'sel'                  => $f['recovery_salt'],
            'reprise_le'           => $entier($f['reprise_le']),
            'close_le'             => $entier($f['close_le']),
            'close_par'            => $f['close_par'],
            'ticket_niveau3'       => $f['ticket_niveau3'],
        ];
    }

    public function emettreTicket(string $ticket, int $compteId, string $genre, int $quand): void
    {
        $this->pdo->prepare('INSERT INTO l3_tickets (ticket, account_id, genre, emis_le) VALUES (?, ?, ?, ?)')
            ->execute([$ticket, $compteId, $genre, $quand]);
    }

    public function ticketEmis(string $ticket): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT t.account_id, a.username, t.genre, t.emis_le FROM l3_tickets t JOIN accounts a ON a.id = t.account_id
              WHERE t.ticket = ?'
        );
        $st->execute([$ticket]);
        $ligne = $st->fetch(PDO::FETCH_ASSOC);

        return $ligne === false ? null : ['compte_id' => (int) $ligne['account_id'], 'nom_compte' => (string) $ligne['username'],
                                          'genre' => (string) $ligne['genre'], 'emis_le' => (int) $ligne['emis_le']];
    }

    public function trouverAncienCodeParIndex(string $indexRecherche): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT c.account_id, a.username, c.code_hash, f.recovery_hash, f.ferme_le
               FROM l3_anciens_codes c
               JOIN l3_fenetres f ON f.account_id = c.account_id
               JOIN accounts a ON a.id = c.account_id
              WHERE c.code_lookup = ? AND f.recovery_hash IS NOT NULL AND f.reprise_le IS NULL AND f.close_le IS NULL'
        );
        $st->execute([$indexRecherche]);
        $ligne = $st->fetch(PDO::FETCH_ASSOC);

        return $ligne === false ? null : [
            'compte_id'      => (int) $ligne['account_id'],
            'nom_compte'     => (string) $ligne['username'],
            'empreinte_code' => (string) $ligne['code_hash'],
            'empreinte_mot'  => (string) $ligne['recovery_hash'],
            'ferme_le'       => (int) $ligne['ferme_le'],
        ];
    }

    public function reprendreFenetre(int $compteId, int $quand): bool
    {
        $st = $this->pdo->prepare(
            'UPDATE l3_fenetres SET reprise_le = ?, passphrase_hash = NULL, recovery_hash = NULL, recovery_salt = NULL
              WHERE account_id = ? AND reprise_le IS NULL AND close_le IS NULL AND ferme_le > ?'
        );
        $st->execute([$quand, $compteId, $quand]);
        if ($st->rowCount() !== 1) {
            return false;
        }
        $this->pdo->prepare('DELETE FROM l3_anciens_codes WHERE account_id = ?')->execute([$compteId]);
        return true;
    }

    public function cloreFenetre(int $compteId, string $par, int $quand): bool
    {
        $st = $this->pdo->prepare(
            'UPDATE l3_fenetres SET close_le = ?, close_par = ?
              WHERE account_id = ? AND reprise_le IS NOT NULL AND close_le IS NULL'
        );
        $st->execute([$quand, $par, $compteId]);
        return $st->rowCount() === 1;
    }

    public function purgerFenetresEchues(int $avant): int
    {
        $this->pdo->prepare(
            'DELETE FROM l3_anciens_codes WHERE account_id IN
                    (SELECT account_id FROM l3_fenetres WHERE ferme_le <= ? AND reprise_le IS NULL)'
        )->execute([$avant]);
        $st = $this->pdo->prepare(
            'UPDATE l3_fenetres SET passphrase_hash = NULL, recovery_hash = NULL, recovery_salt = NULL WHERE '
            . self::FENETRES_ECHUES
        );
        $st->execute([$avant]);
        return $st->rowCount();
    }

    public function compterFenetresEchues(int $avant): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM l3_fenetres WHERE ' . self::FENETRES_ECHUES);
        $st->execute([$avant]);
        return (int) $st->fetchColumn();
    }

    /** Une fenêtre fermée sans ancien secret servi, qui porte encore des secrets. */
    private const FENETRES_ECHUES = 'ferme_le <= ? AND reprise_le IS NULL
        AND (passphrase_hash IS NOT NULL OR recovery_hash IS NOT NULL OR recovery_salt IS NOT NULL)';

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

    public function selDuCompteParIndexCode(string $indexRecherche): ?string
    {
        $st = $this->pdo->prepare(
            'SELECT a.recovery_salt
               FROM recovery_codes c
               JOIN accounts a ON a.id = c.account_id
              WHERE c.code_lookup = ?'
        );
        $st->execute([$indexRecherche]);
        $sel = $st->fetchColumn();
        if ($sel === false) {
            // Un ancien code d'une fenêtre : son mot mémorisé est dérivé sous l'ancien sel.
            $st = $this->pdo->prepare(
                'SELECT f.recovery_salt FROM l3_anciens_codes c JOIN l3_fenetres f ON f.account_id = c.account_id
                  WHERE c.code_lookup = ?'
            );
            $st->execute([$indexRecherche]);
            $sel = $st->fetchColumn();
        }

        return $sel === false || $sel === null ? null : (string) $sel;
    }

    public function consommerCode(int $codeId, int $quand): void
    {
        $st = $this->pdo->prepare('UPDATE recovery_codes SET used = 1, used_at = ? WHERE id = ? AND used = 0');
        $st->execute([$quand, $codeId]);
        if ($st->rowCount() !== 1) {
            throw new CodeDejaConsomme('aucun code libre à ce numéro');
        }
    }

    public function compterCodesRestants(int $compteId): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM recovery_codes WHERE account_id = ? AND used = 0');
        $st->execute([$compteId]);

        return (int) $st->fetchColumn();
    }

    use TransactionsPdo;

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
    ): bool {
        // La condition reprend mot pour mot celle de `litigeActifDuCompte` : les
        // deux disent ce qu'est un dossier actif, et un banc les confronte.
        $st = $this->pdo->prepare(
            "INSERT INTO disputes (dispute_number, account_id, status, claim_hash, expires_at, created_at, updated_at)
             SELECT ?, ?, 'open', ?, ?, ?, ?
             WHERE NOT EXISTS (
                 SELECT 1 FROM disputes
                 WHERE account_id = ? AND status IN ('open', 'awaiting_admin', 'accepted')
                   AND (status = 'accepted' OR expires_at > ?)
             )"
        );
        $st->execute([$numero, $compteId, $empreinteSesame, $expireLe, $quand, $quand, $compteId, $quand]);

        return $st->rowCount() === 1;
    }

    public function compterDemandeurConcurrent(int $litigeId): void
    {
        $this->pdo->prepare('UPDATE disputes SET init_collisions = init_collisions + 1 WHERE id = ?')
                  ->execute([$litigeId]);
    }

    public function enregistrerFaisceau(int $litigeId, string $faisceauJson, int $quand): void
    {
        $st = $this->pdo->prepare(
            "UPDATE disputes SET signals_json = ?, status = 'awaiting_admin', submitted_at = ?, updated_at = ?
             WHERE id = ? AND status IN ('open', 'awaiting_admin')"
        );
        $st->execute([$faisceauJson, $quand, $quand, $litigeId]);
        // ⚠️ MySQL compte les lignes CHANGÉES : un second dépôt identique au
        // premier, dans la même seconde, en change zéro. Seule une relecture
        // distingue ce cas d'un dossier tranché entre-temps.
        if ($st->rowCount() !== 1) {
            $etat = $this->pdo->prepare('SELECT status FROM disputes WHERE id = ?');
            $etat->execute([$litigeId]);
            if (!in_array($etat->fetchColumn(), ['open', 'awaiting_admin'], true)) {
                throw new LitigeDejaTranche('aucun dossier en cours à ce numéro');
            }
        }
    }

    public function trancherLitige(int $litigeId, string $statut, string $par, int $quand): void
    {
        $st = $this->pdo->prepare(
            "UPDATE disputes SET status = ?, decided_at = ?, decided_by = ?, updated_at = ?
             WHERE id = ? AND status IN ('open', 'awaiting_admin')"
        );
        $st->execute([$statut, $quand, $par, $quand, $litigeId]);
        if ($st->rowCount() !== 1) {
            throw new LitigeDejaTranche('aucun dossier en cours à ce numéro');
        }
    }

    public function cloreLitige(int $litigeId, int $quand): void
    {
        // ⚠️ `claim_hash` passe à NULL : le sésame ne doit plus rien rouvrir. Le fil
        // de messages, lui, reste lisible — il ne regarde pas le statut du dossier.
        // La condition sur l'état est la garde de la course : de deux clôtures du
        // même dossier, la seconde ne trouve plus de ligne active.
        $st = $this->pdo->prepare(
            "UPDATE disputes SET status = 'closed', claim_hash = NULL, updated_at = ?
             WHERE id = ? AND status IN ('open', 'awaiting_admin', 'accepted')"
        );
        $st->execute([$quand, $litigeId]);
        if ($st->rowCount() !== 1) {
            throw new LitigeDejaClos('aucun dossier actif à ce numéro');
        }
    }

    public function compterRefusRecents(int $compteId, int $depuis): int
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM disputes WHERE account_id = ? AND status = 'refused' AND decided_at > ?"
        );
        $st->execute([$compteId, $depuis]);

        return (int) $st->fetchColumn();
    }

    public function poserGel(int $compteId, int $jusqua, int $quand, string $par): void
    {
        $this->pdo->prepare(
            'INSERT INTO l3_gel (account_id, gele_jusqu_a, pose_le, gele_par) VALUES (?, ?, ?, ?)
             ON CONFLICT(account_id) DO UPDATE SET gele_jusqu_a = excluded.gele_jusqu_a,
                                                   pose_le      = excluded.pose_le,
                                                   gele_par     = excluded.gele_par'
        )->execute([$compteId, $jusqua, $quand, $par]);
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

    /**
     * La clause des dossiers périmés — pour la purge ET pour son compte.
     *
     * ⚠️ Elle nomme ce qui part plutôt que ce qui reste : les dossiers épargnés le
     * sont pour les raisons que `purgerLitigesExpires()` porte juste dessous. Les
     * écrire deux fois, c'est accepter qu'un jour l'un des deux les oublie.
     */
    private const OU_LITIGES_PERIMES = "(expires_at <= ? AND (status = 'closed' OR (status = 'open'
        AND submitted_at = 0
        AND NOT EXISTS (SELECT 1 FROM dispute_messages m WHERE m.dispute_id = disputes.id))))
        OR (expires_at <= ? AND status IN ('open', 'awaiting_admin'))";

    /** Les deux bornes de la clause : l'échéance, et l'échéance moins la rétention des dossiers déposés. */
    private static function bornesPerimes(int $avant): array
    {
        return [$avant, $avant - self::RETENTION_DOSSIER_DEPOSE];
    }

    public function purgerLitigesExpires(int $avant): int
    {
        // ⚠️ Les dossiers REFUSÉS survivent à la purge : l'arbitre lit combien un
        // compte en porte sur une fenêtre glissante, bien plus longue que la durée
        // de vie d'un dossier (les deux sont réglables au constructeur
        // d'`Escalade`). Les effacer retirerait de son faisceau le seul fait qui
        // distingue un premier recours d'un acharnement, sans qu'aucune sonde ne
        // rougisse. Ce compte ne décide rien : c'est pour informer qu'il se garde.
        //
        // ⚠️ Les dossiers ACCEPTÉS y survivent aussi, pour la raison qui fait que
        // `litigeActifDuCompte` les exempte du TTL : le titulaire qui revient après
        // l'expiration doit encore trouver son accord. Les purger fermerait la porte
        // définitivement à qui a déjà tout perdu.
        //
        // ⚠️ Les dossiers DÉPOSÉS y survivent `RETENTION_DOSSIER_DEPOSE` après leur
        // échéance : des réponses ou un message attendaient un arbitre, et l'échéance
        // ne mesure que le temps qu'il a mis à venir. Les effacer aussitôt emportait
        // le fil avec eux (`ON DELETE CASCADE`). Ils ne se tranchent plus
        // (`Escalade::trancher()`) ; ils se lisent à côté du dossier suivant.
        // L'échéance continue de libérer la PLACE : `litigeActifDuCompte` ne rend
        // plus un dossier périmé, quel que soit son contenu.
        $st = $this->pdo->prepare('DELETE FROM disputes WHERE ' . self::OU_LITIGES_PERIMES);
        $st->execute(self::bornesPerimes($avant));

        return $st->rowCount();
    }

    public function compterLitigesExpires(int $avant): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM disputes WHERE ' . self::OU_LITIGES_PERIMES);
        $st->execute(self::bornesPerimes($avant));

        return (int) $st->fetchColumn();
    }

    public function purgerEchecs(int $avant, array $prefixes): int
    {
        [$ou, $parametres] = $this->ouEchecsPurgeables($avant, $prefixes);
        $st = $this->pdo->prepare('DELETE FROM login_attempts WHERE ' . $ou);
        $st->execute($parametres);

        return $st->rowCount();
    }

    public function compterEchecsPurgeables(int $avant, array $prefixes): int
    {
        [$ou, $parametres] = $this->ouEchecsPurgeables($avant, $prefixes);
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ' . $ou);
        $st->execute($parametres);

        return (int) $st->fetchColumn();
    }

    /**
     * La clause des lignes d'échec purgeables, et ses paramètres.
     *
     * 🔑 **Un seul endroit construit ce filtre**, pour la purge comme pour son
     * compte. Recopié, il divergerait au premier préfixe ajouté d'un côté — et
     * c'est le mode d'essai qui annoncerait alors un chiffre que la purge ne
     * tient pas.
     *
     * @param  list<string> $prefixes
     * @return array{0: string, 1: list<int|string>}
     */
    private function ouEchecsPurgeables(int $avant, array $prefixes): array
    {
        if ($prefixes === []) {
            throw new \InvalidArgumentException(
                'purgerEchecs() sans préfixe n effacerait rien en rendant zéro : une purge muette passe pour une purge faite.'
            );
        }

        $filtre = implode(' OR ', array_fill(0, count($prefixes), 'username LIKE ?'));

        return [
            'attempted_at <= ? AND success = 0 AND (' . $filtre . ')',
            [$avant, ...array_map(static fn (string $p): string => $p . '%', $prefixes)],
        ];
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
