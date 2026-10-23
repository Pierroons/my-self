<?php

declare(strict_types=1);

use Pierroons\SelfDataGuard\Escrow\AuditLog;
use Pierroons\SelfDataGuard\Escrow\EscrowArbiter;
use Pierroons\SelfDataGuard\Escrow\EscrowCeremony;
use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\Escrow\HeadWitness;
use Pierroons\SelfDataGuard\Escrow\NoHeadWitness;
use Pierroons\SelfDataGuard\Escrow\UnlockedEscrow;
use Pierroons\SelfDataGuard\Escrow\Verdict;
use Pierroons\SelfDataGuard\Storage\StorageInterface;

/** The same verdict for every case — a bench fixture, never an arbiter to deploy. */
final class FixedArbiter implements EscrowArbiter
{
    public function __construct(private readonly Verdict $verdict = Verdict::Open)
    {
    }

    public function verdict(string $userId, string $caseRef, ?string $archiveId): Verdict
    {
        return $this->verdict;
    }
}

/** A ceremony on $storage, with its own log in the temp directory. */
function benchCeremony(
    StorageInterface $storage,
    Verdict $verdict = Verdict::Open,
    ?HeadWitness $witness = null,
    ?string $logPath = null
): EscrowCeremony {
    $logPath ??= sys_get_temp_dir() . '/sdg-ceremony-' . bin2hex(random_bytes(4)) . '.log';
    register_shutdown_function(static fn () => @unlink($logPath));
    return new EscrowCeremony(
        $storage,
        new AuditLog($logPath, str_repeat('s', 32)),
        new FixedArbiter($verdict),
        $witness ?? new NoHeadWitness(),
        'bench'
    );
}

/** The ceremony's private unsealing, for the benches that test the sealed-key formats. */
function unsealAdminKey(string $sealed, string $passphrase): string
{
    return (new ReflectionMethod(EscrowCeremony::class, 'unsealAdminKey'))->invoke(null, $sealed, $passphrase);
}

/** The ceremony's private opening of wrap_admin, for the benches that test it. */
function openWrapAdmin(EscrowRecord $record, string $adminSecretKey, string $adminPublicKeyB64): UnlockedEscrow
{
    return (new ReflectionMethod(EscrowCeremony::class, 'openWrapAdmin'))->invoke(null, $record, $adminSecretKey, $adminPublicKeyB64);
}
