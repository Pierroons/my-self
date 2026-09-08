<?php

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;
use Pierroons\SelfRecover\Device\Appareil;
use Pierroons\SelfRecover\Recovery\Litige;
use Pierroons\SelfRecover\Storage\StorageInterface;

/**
 * Le schéma du lab, présenté à la bibliothèque SelfRecover.
 *
 * 🔑 **C'est ici, et nulle part ailleurs, que les noms de colonnes du lab
 * apparaissent.** La bibliothèque ignore que le mot mémorisé se range dans
 * `recovery_hash` et la passphrase dans `pass_hash` : elle demande des
 * empreintes, cet adaptateur sait où les prendre. Un autre consommateur, avec
 * d'autres colonnes, écrit le sien sans qu'aucune base ait à migrer.
 */
final class StockageSelfRecover implements StorageInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

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
        $st = $this->pdo->prepare('SELECT id, pass_hash FROM accounts WHERE username = ?');
        $st->execute([$nomCompte]);
        $ligne = $st->fetch(PDO::FETCH_ASSOC);

        return $ligne === false
            ? null
            : ['id' => (int) $ligne['id'], 'empreinte_passphrase' => (string) $ligne['pass_hash']];
    }

    public function remplacerEmpreinteMotDePasse(int $compteId, string $empreinte): void
    {
        $this->pdo->prepare('UPDATE accounts SET pw_hash = ? WHERE id = ?')->execute([$empreinte, $compteId]);
    }

    public function remplacerEmpreintes(int $compteId, string $empreinteMotDePasse, string $empreintePassphrase): void
    {
        $this->pdo
            ->prepare('UPDATE accounts SET pw_hash = ?, pass_hash = ? WHERE id = ?')
            ->execute([$empreinteMotDePasse, $empreintePassphrase, $compteId]);
    }

    public function revoquerSessions(int $compteId): void
    {
        $this->pdo->prepare('DELETE FROM app_sessions WHERE account_id = ?')->execute([$compteId]);
    }

    // ── Appareil de confiance ──────────────────────────────────────────────

    public function enregistrerAppareil(int $compteId, string $credentialId, string $clePubliqueB64url, int $quand): void
    {
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

    public function commencerTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function validerTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function annulerTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    // ── Récupération de niveau 3 : dossier et arbitrage humain ─────────────
    //
    // La traduction des noms se fait ici : le lab dit `dispute_number` là où la
    // bibliothèque dit un numéro, et `init_collisions` là où elle dit des
    // demandeurs concurrents.

    private function litigeDepuis(array $l): Litige
    {
        return new Litige(
            id: (int) $l['id'],
            numero: (string) $l['dispute_number'],
            compteId: (int) $l['account_id'],
            nomCompte: (string) ($l['username'] ?? ''),
            statut: (string) $l['status'],
            empreinteSesame: (string) ($l['claim_hash'] ?? ''),
            creeLe: (int) $l['created_at'],
            expireLe: (int) $l['expires_at'],
            deposeLe: (int) ($l['submitted_at'] ?? 0),
            trancheLe: $l['decided_at'] === null ? null : (int) $l['decided_at'],
            tranchePar: $l['decided_by'] === null ? null : (string) $l['decided_by'],
            demandeursConcurrents: (int) $l['init_collisions'],
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
        // ⚠️ `accepted` compte comme actif ET n'expire pas, comme dans
        // `Escalade::recevable()` qui l'exempte du TTL : l'horloge ne doit pas
        // annuler le travail de l'arbitre. Sans cette exemption, une fenêtre
        // s'ouvre entre la 24e heure et le retour du titulaire, où un tiers ouvre
        // un dossier neuf sans que la collision soit comptée ni montrée.
        //
        // ⚠️ `accepted` compte comme actif. Entre l'accord et le ré-enrôlement,
        // le compte est au plus vulnérable : y laisser ouvrir un second dossier
        // sans le signaler priverait l'arbitre de l'information la plus utile
        // du moment — qu'un second demandeur se présente.
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
        // ⚠️ `claim_hash` passe à NULL : le sésame ne doit plus rien rouvrir, et
        // le fil, lui, ne regarde pas le statut du dossier.
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
        $v = (int) ($st->fetchColumn() ?: 0);

        return $v > $maintenant ? $v : 0;
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
            static fn (array $m): array => ['auteur' => (string) $m['sender'], 'texte' => (string) $m['body'],
                                            'ecrit_le' => (int) $m['created_at']],
            $st->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function listerLitiges(int $limite): array
    {
        // ⚠️ `source_ip` n'est pas sélectionné : l'adresse du demandeur n'a rien
        // à faire dans une console d'arbitrage.
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
        // « ouverture gelée jusqu'au <date passée> » sur un compte qui n'est plus
        // gelé — et propose de lever un gel qui n'existe plus. `gelJusqua()`
        // applique déjà la même règle ; les deux lectures doivent dire pareil.
        $st->execute([time(), $limite]);
        $lignes = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($lignes as &$l) {
            $l['faisceau'] = $l['signals_json'] === null ? null : json_decode((string) $l['signals_json'], true);
            unset($l['signals_json']);
        }

        return $lignes;
    }

    public function purgerLitigesExpires(int $avant): int
    {
        // ⚠️ Les dossiers REFUSÉS survivent à la purge : le gel se calcule en
        // comptant les refus d'une fenêtre de trente jours, et un dossier
        // expire au bout de vingt-quatre heures. Les effacer viderait le
        // compteur avant qu'il puisse atteindre son seuil, et le gel — seule
        // protection contre l'acharnement — deviendrait inatteignable sans
        // qu'aucune sonde ne rougisse.
        //
        // ⚠️ Les dossiers ACCEPTÉS y survivent aussi, pour la même raison que
        // `litigeActifDuCompte` les exempte du TTL : le titulaire qui revient
        // après vingt-quatre heures doit encore trouver son accord. Les purger
        // rendrait la porte définitivement close à qui a déjà tout perdu.
        $st = $this->pdo->prepare(
            "DELETE FROM disputes WHERE expires_at <= ? AND status NOT IN ('refused', 'accepted')"
        );
        $st->execute([$avant]);

        return $st->rowCount();
    }

    public function faitsDuCompte(int $compteId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, username, created_at, last_login_at, login_count FROM accounts WHERE id = ?'
        );
        $st->execute([$compteId]);
        $l = $st->fetch(PDO::FETCH_ASSOC);
        if ($l === false) {
            return null;
        }

        // 🔑 `login_count` est NOT NULL DEFAULT 0 dans ce schéma : « jamais
        // enregistré » y est indiscernable de « zéro connexion ». On rend donc
        // `null` pour les deux faits tant que `last_login_at` est vide, plutôt
        // que de laisser un compte non tracé produire « rare » — ce qui ferait
        // diverger la réponse honnête d'un titulaire légitime.
        $jamais = $l['last_login_at'] === null;

        return [
            'id'                 => (int) $l['id'],
            'nom_compte'         => (string) $l['username'],
            'cree_le'            => (int) $l['created_at'],
            'derniere_connexion' => $jamais ? null : (int) $l['last_login_at'],
            'nombre_connexions'  => $jamais ? null : (int) $l['login_count'],
        ];
    }

    public function reposerSecrets(
        int $compteId,
        string $empreinteMotDePasse,
        string $empreintePassphrase,
        string $empreinteMotDerive,
        string $sel,
    ): void {
        $this->pdo->prepare(
            'UPDATE accounts SET pw_hash = ?, pass_hash = ?, recovery_hash = ?, recovery_salt = ?,
                                 banned_until = 0 WHERE id = ?'
        )->execute([$empreinteMotDePasse, $empreintePassphrase, $empreinteMotDerive, $sel, $compteId]);
    }
}
