<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../bi-self/selfrecover/src/autoload.php';

use Pierroons\SelfRecover\Device\Appareil;
use Pierroons\SelfRecover\Storage\StorageInterface;

/**
 * Le schéma de cette démo, présenté à la bibliothèque SelfRecover.
 *
 * 🔑 **C'est ici, et nulle part ailleurs, que ses noms de colonnes vivent.** La
 * bibliothèque ignore que le mot mémorisé se range dans `recovery_hash` et la
 * passphrase dans `pass_hash` ; elle demande des empreintes, cet adaptateur sait
 * où les prendre.
 *
 * ⚠️ Cette démo parle à SQLite par l'extension `SQLite3`, pas par PDO — contrairement
 * au lab. C'est précisément ce qu'une interface de stockage permet : deux
 * consommateurs, deux façons d'atteindre la base, un seul protocole.
 */
final class StockageSelfRecover implements StorageInterface
{
    public function __construct(private readonly SQLite3 $db)
    {
    }

    /** @param array<string, mixed> $params */
    private function un(string $sql, array $params): ?array
    {
        $st = $this->db->prepare($sql);
        foreach ($params as $cle => $valeur) {
            $st->bindValue($cle, $valeur);
        }
        $ligne = $st->execute()->fetchArray(SQLITE3_ASSOC);

        return $ligne === false ? null : $ligne;
    }

    /** @param array<string, mixed> $params */
    private function executer(string $sql, array $params): void
    {
        $st = $this->db->prepare($sql);
        foreach ($params as $cle => $valeur) {
            $st->bindValue($cle, $valeur);
        }
        $st->execute();
    }

    /**
     * ⚠️ Cette démo ne trace pas l'origine : sa table `login_attempts` n'a pas
     * de colonne `ip`, et son frein est ailleurs — `RateLimit` compte les
     * actions par session, sur disque.
     *
     * Rendre 0 ferait passer un garde-fou inactif pour un garde-fou vide, ce
     * qui est le défaut que ce dépôt poursuit depuis une semaine. On refuse
     * donc : les appelants d'ici passent `null` comme origine, et la
     * bibliothèque ne consulte alors jamais ce compteur.
     */
    public function compterEchecsIp(string $ip, int $depuis): int
    {
        throw new RuntimeException(
            "Cette démo ne trace pas l'IP (login_attempts n'a pas cette colonne) : "
            . 'son frein est RateLimit, par session. Passer null comme origine.'
        );
    }

    public function compterEchecsCompte(string $nomCompte, int $depuis): int
    {
        $l = $this->un(
            'SELECT COUNT(*) AS n FROM login_attempts WHERE username = :u AND success = 0 AND attempted_at > :d',
            [':u' => $nomCompte, ':d' => $depuis],
        );

        return (int) ($l['n'] ?? 0);
    }

    public function tracerTentative(string $etiquette, bool $succes, ?string $ip, int $quand): void
    {
        // L'origine est ignorée : la table ne la porte pas, et le frein de
        // cette démo est ailleurs (RateLimit, par session).
        $this->executer(
            'INSERT INTO login_attempts (username, success, attempted_at) VALUES (:u, :s, :t)',
            [':u' => $etiquette, ':s' => $succes ? 1 : 0, ':t' => $quand],
        );
    }

    public function trouverCompte(string $nomCompte): ?array
    {
        $l = $this->un('SELECT id, recovery_hash FROM accounts WHERE username = :u', [':u' => $nomCompte]);

        return $l === null ? null : ['id' => (int) $l['id'], 'empreinte_mot' => (string) $l['recovery_hash']];
    }

    public function trouverComptePourPassphrase(string $nomCompte): ?array
    {
        $l = $this->un('SELECT id, pass_hash FROM accounts WHERE username = :u', [':u' => $nomCompte]);

        return $l === null ? null : ['id' => (int) $l['id'], 'empreinte_passphrase' => (string) $l['pass_hash']];
    }

    public function remplacerEmpreinteMotDePasse(int $compteId, string $empreinte): void
    {
        $this->executer('UPDATE accounts SET pw_hash = :h WHERE id = :i', [':h' => $empreinte, ':i' => $compteId]);
    }

    public function remplacerEmpreintes(int $compteId, string $empreinteMotDePasse, string $empreintePassphrase): void
    {
        $this->executer(
            'UPDATE accounts SET pw_hash = :h, pass_hash = :p WHERE id = :i',
            [':h' => $empreinteMotDePasse, ':p' => $empreintePassphrase, ':i' => $compteId],
        );
    }

    public function revoquerSessions(int $compteId): void
    {
        $this->executer('DELETE FROM app_sessions WHERE account_id = :i', [':i' => $compteId]);
    }

    // ── Codes de récupération ──────────────────────────────────────────────

    public function purgerCodes(int $compteId): void
    {
        $this->executer('DELETE FROM recovery_codes WHERE account_id = :i', [':i' => $compteId]);
    }

    public function enregistrerCode(int $compteId, string $indexRecherche, string $empreinteCode, int $quand): void
    {
        $this->executer(
            'INSERT INTO recovery_codes (account_id, code_lookup, code_hash, created_at) VALUES (:i, :l, :h, :t)',
            [':i' => $compteId, ':l' => $indexRecherche, ':h' => $empreinteCode, ':t' => $quand],
        );
    }

    public function trouverCodeParIndex(string $indexRecherche): ?array
    {
        $l = $this->un(
            'SELECT rc.id AS code_id, rc.code_hash, rc.used, a.id AS account_id, a.username, a.recovery_hash
               FROM recovery_codes rc JOIN accounts a ON a.id = rc.account_id
              WHERE rc.code_lookup = :l',
            [':l' => $indexRecherche],
        );

        return $l === null ? null : [
            'code_id'        => (int) $l['code_id'],
            'empreinte_code' => (string) $l['code_hash'],
            'deja_utilise'   => (bool) $l['used'],
            'compte_id'      => (int) $l['account_id'],
            'nom_compte'     => (string) $l['username'],
            'empreinte_mot'  => (string) $l['recovery_hash'],
        ];
    }

    public function consommerCode(int $codeId, int $quand): void
    {
        $this->executer('UPDATE recovery_codes SET used = 1, used_at = :t WHERE id = :i',
            [':t' => $quand, ':i' => $codeId]);
    }

    public function compterCodesRestants(int $compteId): int
    {
        $l = $this->un('SELECT COUNT(*) AS n FROM recovery_codes WHERE account_id = :i AND used = 0',
            [':i' => $compteId]);

        return (int) ($l['n'] ?? 0);
    }

    // ── Facteur « cet appareil » — absent de cette démo ─────────────────────
    //
    // Le schéma n'a ni `device_credentials` ni `device_challenges` : ce facteur
    // se démontre dans le lab. Ces méthodes refusent plutôt que de rendre une
    // valeur vide — un stockage qui répond « rien » à une question qu'il ne sait
    // pas traiter ferait échouer l'enrôlement comme un mot incorrect, et le
    // message le dirait à tort.

    private function pasDeFacteurAppareil(string $operation): never
    {
        throw new RuntimeException(
            "Cette démo n'implémente pas le facteur « cet appareil » ({$operation}) : "
            . 'son schéma n\'a pas de table device. Voir demo/lab.'
        );
    }

    public function enregistrerAppareil(int $compteId, string $credentialId, string $clePubliqueB64url, int $quand): void
    {
        $this->pasDeFacteurAppareil('enregistrerAppareil');
    }

    public function trouverAppareil(string $credentialId): ?Appareil
    {
        $this->pasDeFacteurAppareil('trouverAppareil');
    }

    public function purgerDefisExpires(int $avant): void
    {
        $this->pasDeFacteurAppareil('purgerDefisExpires');
    }

    public function enregistrerDefi(string $defi, string $credentialId, int $quand): void
    {
        $this->pasDeFacteurAppareil('enregistrerDefi');
    }

    public function defiEnCours(string $defi, string $credentialId, int $depuis): bool
    {
        $this->pasDeFacteurAppareil('defiEnCours');
    }

    public function consommerDefi(string $defi): void
    {
        $this->pasDeFacteurAppareil('consommerDefi');
    }

    // ── Atomicité ──────────────────────────────────────────────────────────
    //
    // `BEGIN IMMEDIATE` plutôt que `BEGIN` : il prend le verrou d'écriture tout
    // de suite. Sans lui, deux récupérations simultanées passent la lecture
    // toutes les deux avant que l'une n'écrive.

    private bool $ouverte = false;

    public function commencerTransaction(): void
    {
        if (!$this->ouverte) {
            $this->db->exec('BEGIN IMMEDIATE');
            $this->ouverte = true;
        }
    }

    public function validerTransaction(): void
    {
        if ($this->ouverte) {
            $this->db->exec('COMMIT');
            $this->ouverte = false;
        }
    }

    public function annulerTransaction(): void
    {
        if ($this->ouverte) {
            $this->db->exec('ROLLBACK');
            $this->ouverte = false;
        }
    }
}
