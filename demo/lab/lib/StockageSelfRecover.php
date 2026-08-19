<?php

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;
use Pierroons\SelfRecover\Device\Appareil;
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
}
