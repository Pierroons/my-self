<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\Aad;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\LegacyCipherUnavailableException;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Storage\StorageInterface;
use Pierroons\SelfDataGuard\Vault\VaultNotFoundException;
use Pierroons\SelfDataGuard\Vault\WrongSecretException;
use RuntimeException;
use Throwable;

/**
 * The only path of the library to an account's escrow on the administrator's
 * side. The admin key is unsealed here and nowhere else, and only after three
 * things, in this order:
 *
 *   1. the arbiter's verdict on the case, whatever it is, and the intention —
 *      who, which account, which case, which arbiter, which witness — written
 *      to the audit log and forced to disk;
 *   2. the head of the log taken out of the machine by the witness, which
 *      stops the ceremony when it fails;
 *   3. a verdict of Open.
 *
 * Then the result, or the failure, goes to the log too. A refused intention is
 * in the log as much as a granted one.
 *
 * ⚠️ Against the administrator alone this holds nothing: whoever has the sealed
 * key file and its passphrase opens wrap_admin outside the library, with no log.
 * The ceremony keeps everyone else out, and keeps a trace for an administrator
 * who wants one. A second secret is a later lot.
 */
final class EscrowCeremony
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly AuditLog $log,
        private readonly EscrowArbiter $arbiter,
        private readonly HeadWitness $witness,
        private readonly string $operator
    ) {
    }

    /**
     * The escrow fields of the account's live vault — only escrow fields, never
     * the private zone.
     *
     * @param list<string> $fieldNames empty = all escrow fields
     * @return array<string, string> field_name => plaintext
     */
    public function readEscrow(
        string $userId,
        string $caseRef,
        string $sealedSecret,
        #[\SensitiveParameter] string $passphrase,
        string $adminPublicKeyB64,
        array $fieldNames = []
    ): array {
        return $this->perform('escrow-unlock', $userId, $caseRef, null, ['fields' => $fieldNames], function () use (
            $userId, $sealedSecret, $passphrase, $adminPublicKeyB64, $fieldNames
        ): array {
            $record = $this->storage->loadEscrow($userId)
                ?? throw new RuntimeException("No escrow compartment for '{$userId}'");
            $plain = $this->withAdminKey($sealedSecret, $passphrase, fn (string $sk): array => $this->decrypt(
                self::openWrapAdmin($record, $sk, $adminPublicKeyB64),
                $this->storage->loadEscrowFields($userId, $fieldNames)
            ));
            return [$plain, ['returned' => array_keys($plain)]];
        });
    }

    /**
     * The escrow fields of one archive of the account — for a holder who lost
     * every secret, which is what level 3 is.
     *
     * @param list<string> $fieldNames empty = all escrow fields
     * @return array<string, string> field_name => plaintext
     */
    public function readArchiveEscrow(
        string $userId,
        string $archiveId,
        string $caseRef,
        string $sealedSecret,
        #[\SensitiveParameter] string $passphrase,
        string $adminPublicKeyB64,
        array $fieldNames = []
    ): array {
        return $this->perform('archive-escrow-unlock', $userId, $caseRef, $archiveId, ['fields' => $fieldNames], function () use (
            $userId, $archiveId, $sealedSecret, $passphrase, $adminPublicKeyB64, $fieldNames
        ): array {
            $archive = $this->storage->loadArchive($userId, $archiveId)
                ?? throw new VaultNotFoundException("No archive '{$archiveId}' for '{$userId}'");
            if ($archive->record->userId !== $userId || $archive->escrow === null) {
                throw new RuntimeException("No escrow compartment in archive '{$archiveId}' of '{$userId}'");
            }
            $ciphertexts = $fieldNames === []
                ? $archive->escrowFields
                : array_intersect_key($archive->escrowFields, array_flip($fieldNames));
            $plain = $this->withAdminKey($sealedSecret, $passphrase, fn (string $sk): array => $this->decrypt(
                self::openWrapAdmin($archive->escrow, $sk, $adminPublicKeyB64),
                $ciphertexts
            ));
            return [$plain, ['returned' => array_keys($plain)]];
        });
    }

    /**
     * The archives of an account, for the operator who must find the one a case
     * is about. Arbitrated and logged like a read: how many times an account was
     * re-enrolled is its holder's to know.
     *
     * @return list<array{id: string, archivedAt: \DateTimeImmutable, locks: list<\Pierroons\SelfDataGuard\Vault\Lock>}>
     */
    public function listArchives(string $userId, string $caseRef): array
    {
        return $this->perform('archives-list', $userId, $caseRef, null, [], function () use ($userId): array {
            $archives = $this->storage->listArchives($userId);
            return [$archives, ['returned' => array_column($archives, 'id')]];
        });
    }

    /**
     * @param array<string, mixed>                                  $detail
     * @param callable(): array{0: mixed, 1: array<string, mixed>} $work   the result, and what the log says of it
     */
    private function perform(string $act, string $userId, string $caseRef, ?string $archiveId, array $detail, callable $work): mixed
    {
        $context = ['target' => $userId, 'case' => $caseRef, 'archive' => $archiveId, 'operator' => $this->operator] + $detail;
        $verdict = $this->arbiter->verdict($userId, $caseRef, $archiveId);
        $intent  = $this->log->append(['action' => "{$act}-intent", 'arbiter' => get_class($this->arbiter),
            'verdict' => $verdict->value, 'witness' => get_class($this->witness)] + $context);
        try {
            $this->witness->witness((int) $intent['seq'], (string) $intent['hmac']);
        } catch (Throwable $e) {
            $this->log->append(['action' => "{$act}-denied", 'reason' => 'witness-failed'] + $context);
            throw new RuntimeException("The head witness did not take the log's head: nothing is unsealed", previous: $e);
        }
        if ($verdict !== Verdict::Open) {
            $this->log->append(['action' => "{$act}-denied", 'reason' => 'verdict', 'verdict' => $verdict->value] + $context);
            throw new EscrowRefusedException($verdict, $userId, $caseRef);
        }
        try {
            [$result, $said] = $work();
        } catch (Throwable $e) {
            $this->log->append(['action' => "{$act}-denied", 'reason' => match (true) {
                $e instanceof WrongSecretException               => 'bad-passphrase',
                $e instanceof LegacyCipherUnavailableException => 'legacy-cipher-unavailable',
                $e instanceof InvalidArgumentException         => 'sealed-key-unreadable',
                default                                        => 'open-failed',
            }] + $context);
            throw $e;
        }
        $this->log->append(['action' => $act] + $context + $said);
        return $result;
    }

    /**
     * @template T
     * @param callable(string): T $use
     * @return T
     */
    private function withAdminKey(string $sealedSecret, #[\SensitiveParameter] string $passphrase, callable $use): mixed
    {
        $sk = self::unsealAdminKey($sealedSecret, $passphrase);
        try {
            return $use($sk);
        } finally {
            Primitives::zeroize($sk);
        }
    }

    /**
     * @param array<string, string> $ciphertexts
     * @return array<string, string>
     */
    private function decrypt(UnlockedEscrow $escrow, array $ciphertexts): array
    {
        try {
            return EscrowFieldCrypter::decryptBatch($escrow, $ciphertexts);
        } finally {
            $escrow->lock();
        }
    }

    /**
     * The admin secret key, from its sealed file and passphrase.
     *
     * @throws InvalidArgumentException on a sealed file that cannot be read
     * @throws WrongSecretException     on a wrong passphrase (auth tag mismatch)
     */
    private static function unsealAdminKey(string $sealedSecret, #[\SensitiveParameter] string $passphrase): string
    {
        if ($passphrase === '') {
            throw new InvalidArgumentException('Admin passphrase must not be empty');
        }
        [$ops, $mem, $saltB64, $blobB64] = AdminKey::components($sealedSecret);
        $salt = base64_decode($saltB64, true);
        if ($salt === false || strlen($salt) < Primitives::SALT_LEN) {
            throw new InvalidArgumentException('Malformed sealed secret (invalid salt)');
        }

        try {
            $sealKey = Primitives::deriveFromPassword($passphrase, $salt, $ops, $mem);
        } catch (\SodiumException $e) {
            throw new InvalidArgumentException('Malformed sealed secret (Argon2id profile out of range)', previous: $e);
        }
        try {
            return Primitives::decryptAcrossFormats(
                EncryptedBlob::fromBase64($blobB64),
                $sealKey,
                Aad::of(AdminKey::SEAL_AAD),
                AdminKey::SEAL_AAD
            );
        } catch (LegacyCipherUnavailableException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new WrongSecretException('Wrong admin passphrase — the sealed key does not open', previous: $e);
        } finally {
            Primitives::zeroize($sealKey);
        }
    }

    /**
     * Open wrap_admin with the admin secret key. A wrap that names another
     * account than its record is refused: moved from one record to another, it
     * would open as the other's escrow.
     */
    private static function openWrapAdmin(EscrowRecord $record, #[\SensitiveParameter] string $adminSecretKey, string $adminPublicKeyB64): UnlockedEscrow
    {
        $adminPublicKey = EscrowVault::decodePublicKey($adminPublicKeyB64);
        if (strlen($adminSecretKey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            throw new InvalidArgumentException('adminSecretKey must be a raw box secret key');
        }
        $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($adminSecretKey, $adminPublicKey);
        $plain   = sodium_crypto_box_seal_open($record->wrapAdmin, $keypair);
        sodium_memzero($keypair);
        if ($plain === false) {
            throw new RuntimeException('Escrow sealed box failed to open — wrong admin key');
        }
        if (strlen($plain) === Primitives::KEY_LEN) {
            $escrowKey = $plain;   // sealed before 0.6.0: names no account
        } else {
            $head = EscrowVault::adminHead($record->userId);
            if (strlen($plain) !== strlen($head) + Primitives::KEY_LEN
                || !hash_equals($head, substr($plain, 0, strlen($head)))) {
                Primitives::zeroize($plain);
                throw new RuntimeException(
                    'Escrow admin wrap was sealed for another account than its record — refused'
                );
            }
            $escrowKey = substr($plain, strlen($head));
            Primitives::zeroize($plain);
        }
        try {
            return new UnlockedEscrow(userId: $record->userId, escrowKey: $escrowKey);
        } finally {
            Primitives::zeroize($escrowKey);
        }
    }
}
