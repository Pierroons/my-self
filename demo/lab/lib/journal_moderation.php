<?php
/**
 * MySelf-Lab — le journal de modération : les actes de ban, chaînés et signés.
 *
 * Il est séparé du journal du super-utilisateur, et ce n'est pas du rangement :
 * pour écrire dans le journal SU, le site devrait en détenir la clé, et pourrait
 * alors y forger une nomination d'admin que `selfrecover-su audit` tiendrait pour
 * légitime. Celui-ci a sa propre clé, tirée par l'instance à sa première écriture.
 *
 * Le fichier naît en 0640 : dans un `data/` partagé par groupe avec la console,
 * l'opérateur peut le lire sans pouvoir l'écrire.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use Pierroons\SelfDataGuard\Escrow\AuditLog;
use Pierroons\SelfModerate\Journal;

require_once __DIR__ . '/secret_instance.php';

final class JournalModeration implements Journal
{
    private ?AuditLog $log = null;

    public function __construct(private readonly ?string $chemin = null)
    {
    }

    /** `LAB_MODERATION_LOG` déroute le journal, comme `LAB_DB_PATH` déroute la base. */
    public function chemin(): string
    {
        return $this->chemin ?? (getenv('LAB_MODERATION_LOG') ?: __DIR__ . '/../data/moderation.log');
    }

    public function inscrire(array $acte): void
    {
        $neuf = !is_file($this->chemin());
        $this->log()->append($acte);
        if ($neuf) {
            @chmod($this->chemin(), 0640);
        }
    }

    /** @return array{ok: bool, count: int, brokenAt: ?int} */
    public function verifier(): array
    {
        return $this->log()->verify();
    }

    /** @return list<array<string, mixed>> les entrées, de la plus ancienne à la plus récente */
    public function entrees(): array
    {
        return $this->log()->readAll();
    }

    /** La clé n'est lue qu'au premier acte : une page qui ne sanctionne personne ne la touche pas. */
    private function log(): AuditLog
    {
        return $this->log ??= new AuditLog(
            $this->chemin(),
            SecretInstance::lire('.modaudit', 48, SecretInstance::PLANCHER, 'LAB_MODAUDIT_PATH')
        );
    }
}
