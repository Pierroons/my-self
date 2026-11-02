<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\Aad;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\LegacyCipherUnavailableException;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\SelfDataGuard;
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
 *   1. the arbiter's verdict on the case, whatever it is — an arbiter that
 *      throws answers Unavailable —, and the intention — who, which account,
 *      which case, which arbiter, which witness — written to the audit log and
 *      forced to disk, once the log is found to still hold the last head the
 *      witness took: a log cut or rewritten since stops the ceremony here;
 *   2. the head of the log taken out of the machine by the witness, which
 *      stops the ceremony when it fails;
 *   3. a verdict of Open;
 *   4. for a compartment sealed to a keeper, the keeper's release, given the
 *      intention's head: it records the read and hands back the inner box —
 *      or holds it until a delay ends, or refuses it to an objection. Without
 *      a keeper (NoKeeper), such a compartment does not open; with another
 *      than the one the database recorded, it does not either.
 *
 * Then the result, or the failure, goes to the log too. A refused intention is
 * in the log as much as a granted one.
 *
 * ⚠️ Without a keeper, this holds nothing against the administrator alone:
 * whoever has the sealed key file and its passphrase opens wrap_admin outside
 * the library, with no log. Under one, a compartment sealed to it opens only
 * once the keeper hands its inner box back — recorded, and shown to the
 * holder, whether the ceremony asked or not.
 */
final class EscrowCeremony
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly AuditLog $log,
        private readonly EscrowArbiter $arbiter,
        private readonly HeadWitness $witness,
        private readonly EscrowKeeper $keeper,
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
        return $this->perform('escrow-unlock', $userId, $caseRef, null, ['fields' => $fieldNames], function (array $intent) use (
            $userId, $caseRef, $sealedSecret, $passphrase, $adminPublicKeyB64, $fieldNames
        ): array {
            [$snapshot, $ciphertexts] = EscrowSnapshot::read($this->storage, $userId, $fieldNames)
                ?? throw new RuntimeException("No escrow compartment for '{$userId}'");
            $record = $this->throughKeeper($snapshot, $caseRef, $intent);
            $plain = $this->withAdminKey($sealedSecret, $passphrase, fn (string $sk): array => $this->decrypt(
                self::openWrapAdmin($record, $sk, $adminPublicKeyB64),
                $ciphertexts
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
        return $this->perform('archive-escrow-unlock', $userId, $caseRef, $archiveId, ['fields' => $fieldNames], function (array $intent) use (
            $userId, $archiveId, $caseRef, $sealedSecret, $passphrase, $adminPublicKeyB64, $fieldNames
        ): array {
            $archive = $this->storage->loadArchive($userId, $archiveId)
                ?? throw new VaultNotFoundException("No archive '{$archiveId}' for '{$userId}'");
            if ($archive->record->userId !== $userId || $archive->escrow === null) {
                throw new RuntimeException("No escrow compartment in archive '{$archiveId}' of '{$userId}'");
            }
            $ciphertexts = $fieldNames === []
                ? $archive->escrowFields
                : array_intersect_key($archive->escrowFields, array_flip($fieldNames));
            $escrow = $this->throughKeeper($archive->escrow, $caseRef, $intent);
            $plain = $this->withAdminKey($sealedSecret, $passphrase, fn (string $sk): array => $this->decrypt(
                self::openWrapAdmin($escrow, $sk, $adminPublicKeyB64),
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
     * The operator's pass: every escrow of $userId — live, and in each archive —
     * that the administrator alone still opens, sealed again to this
     * ceremony's keeper. No secret is in hand: the keeper's public key is all
     * it takes, and the box inside is the wrap_admin as it was. Logged once per
     * account, with what it sealed. A frozen live vault refuses the write, and
     * is reported, not sealed.
     *
     * The escrow sealed here carries no objection key — only the holder's
     * session holds wrap_user —, and its wrap_user names no keeper until its
     * next deposit, or SelfDataGuard::sealEscrowToKeeper().
     *
     * The keeper is the one the database recorded, or recorded here when none
     * is: given another, nothing is sealed — a box sealed to a keeper the
     * deployment does not run opens for no one, and an archive's would stay so.
     * A failure is logged, with the account.
     *
     * @return array{live: bool, archives: list<string>, frozen: bool} what was sealed
     */
    public function sealToKeeper(string $userId): array
    {
        if ($this->keeper instanceof NoKeeper) {
            throw new RuntimeException('NoKeeper: there is no keeper to seal to');
        }
        try {
            return $this->sealAccountToKeeper($userId);
        } catch (Throwable $e) {
            $this->log->append(['action' => 'seal-to-keeper-failed', 'target' => $userId, 'keeper' => $this->keeperName(),
                'reason' => get_class($e), 'operator' => $this->operator]);
            throw $e;
        }
    }

    /** @return array{live: bool, archives: list<string>, frozen: bool} */
    private function sealAccountToKeeper(string $userId): array
    {
        $keys  = KeeperSeal::checkKeys($this->keeper->publicKeys());
        $print = KeeperSeal::fingerprint($keys);
        $held  = $this->storage->loadSetting(SelfDataGuard::SETTING_KEEPER)
            ?? $this->storage->claimSetting(SelfDataGuard::SETTING_KEEPER, $print);
        if (!hash_equals($held, $print)) {
            throw new InvalidArgumentException(
                "This database recorded the escrow keeper {$held}, and this ceremony was given {$print}: nothing is "
                . 'sealed — the ceremony and every façade are given the keeper the database recorded'
            );
        }
        $box  = $keys['box'];
        $wrap = static fn (EscrowRecord $r): EscrowRecord => new EscrowRecord(
            $r->userId,
            $r->wrapUser,
            KeeperSeal::wrap(KeeperSeal::newEscrowId(), $r->userId, null, $r->wrapAdmin, $box),
            $r->createdAt,
            new \DateTimeImmutable(),
            $r->revision
        );
        $done = ['live' => false, 'archives' => [], 'frozen' => false];
        $live = $this->storage->loadEscrow($userId);
        if ($live !== null && !EscrowVault::isKeeperSealed($live)) {
            try {
                $this->storage->replaceEscrow($this->storage->loadVault($userId)->generation(), $wrap($live));
                $done['live'] = true;
            } catch (\Pierroons\SelfDataGuard\Vault\VaultFrozenException) {
                $done['frozen'] = true;
            }
        }
        foreach ($this->storage->listArchives($userId) as $listed) {
            $escrow = $this->storage->loadArchive($userId, $listed['id'])?->escrow;
            if ($escrow !== null && !EscrowVault::isKeeperSealed($escrow)) {
                $this->storage->replaceArchiveEscrow($userId, $listed['id'], $wrap($escrow));
                $done['archives'][] = $listed['id'];
            }
        }
        $this->log->append(['action' => 'seal-to-keeper', 'target' => $userId, 'keeper' => $print,
            'live' => $done['live'], 'archives' => $done['archives'], 'frozen' => $done['frozen'], 'operator' => $this->operator]);
        return $done;
    }

    /**
     * Hands the witness the log's head, once the log is found to hold $anchor —
     * the head noted outside this machine at the last verification. The
     * operator's act for a witness that holds no head on a log a witness
     * already served: a new one, or one that lost its store. Logged like a
     * ceremony: its intention, then the witness, then the act.
     *
     * @throws InvalidArgumentException when $anchor is not a head
     * @throws RuntimeException         when the witness holds a head or takes none,
     *                                  or the log no longer holds $anchor
     */
    public function seedWitness(string $anchor): void
    {
        if ($this->witness instanceof NoHeadWitness) {
            throw new RuntimeException('NoHeadWitness takes no head: there is no witness to seed');
        }
        $held = $this->witness->lastHead();
        if ($held !== null) {
            throw new RuntimeException("The head witness holds a head ({$held}): it is seeded already");
        }
        $context = ['anchor' => $anchor, 'operator' => $this->operator];
        try {
            $intent = $this->log->append(['action' => 'seed-witness-intent', 'witness' => get_class($this->witness)] + $context, $anchor);
        } catch (AuditAnchorException $e) {
            $this->log->append(['action' => 'seed-witness-denied', 'reason' => 'log-behind-anchor', 'found' => $e->found] + $context);
            throw new RuntimeException(
                "The audit log no longer holds the anchor {$anchor}: it was {$e->found} — the witness is not seeded, "
                . 'the log is an incident to examine',
                previous: $e
            );
        }
        try {
            $this->witness->witness((int) $intent['seq'], (string) $intent['hmac']);
        } catch (Throwable $e) {
            $this->log->append(['action' => 'seed-witness-denied', 'reason' => 'witness-failed'] + $context);
            throw new RuntimeException("The head witness did not take the log's head: it is not seeded", previous: $e);
        }
        $this->log->append(['action' => 'seed-witness'] + $context);
    }

    /**
     * @param array<string, mixed>                                  $detail
     * @param callable(array<string, mixed>): array{0: mixed, 1: array<string, mixed>} $work
     *        given the intention as the log wrote it; returns the result, and what the log says of it
     */
    private function perform(string $act, string $userId, string $caseRef, ?string $archiveId, array $detail, callable $work): mixed
    {
        $context = ['target' => $userId, 'case' => $caseRef, 'archive' => $archiveId, 'operator' => $this->operator] + $detail;
        $failure = [];
        try {
            $verdict = $this->arbiter->verdict($userId, $caseRef, $archiveId);
        } catch (Throwable $e) {
            // A case table that cannot be read is the answer Unavailable names.
            $verdict = Verdict::Unavailable;
            $failure = ['arbiterFailed' => get_class($e)];
        }
        try {
            $lastHead = $this->witness->lastHead();
        } catch (Throwable $e) {
            $this->log->append(['action' => "{$act}-denied", 'reason' => 'witness-failed'] + $context);
            throw new RuntimeException("The head witness did not give back the last head it took: nothing is unsealed", previous: $e);
        }
        if ($lastHead === null && !$this->witness instanceof NoHeadWitness && $this->witnessTookAHead()) {
            // A witness took a head, and this one holds none: a log cut since would hide in what it lacks.
            $this->log->append(['action' => "{$act}-denied", 'reason' => 'witness-forgot'] + $context);
            throw new RuntimeException(
                'The head witness holds no head, though this log shows a witness took one — nothing is unsealed. '
                . 'One that lost its store, or a new one, is seeded by the operator: seedWitness(), after verifying the log against its anchor'
            );
        }
        try {
            $intent = $this->log->append(['action' => "{$act}-intent", 'arbiter' => get_class($this->arbiter),
                'verdict' => $verdict->value, 'witness' => get_class($this->witness), 'keeper' => $this->keeperName()]
                + $failure + $context, $lastHead);
        } catch (AuditAnchorException $e) {
            $this->log->append(['action' => "{$act}-denied", 'reason' => 'log-behind-witness',
                'found' => $e->found, 'witnessHead' => $lastHead] + $context);
            throw new RuntimeException(
                "The audit log no longer holds the head the witness took last ({$lastHead}): it was {$e->found}"
                . ' — nothing is unsealed, the log is an incident to examine',
                previous: $e
            );
        } catch (InvalidArgumentException $e) {
            $this->log->append(['action' => "{$act}-denied", 'reason' => 'witness-failed'] + $context);
            throw new RuntimeException('The head witness gave back a head that is not one: nothing is unsealed', previous: $e);
        }
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
            [$result, $said] = $work($intent);
        } catch (EscrowAnnouncedException $e) {
            $this->log->append(['action' => "{$act}-announced", 'due' => gmdate('c', $e->due)] + $context);
            throw $e;
        } catch (Throwable $e) {
            $this->log->append(['action' => "{$act}-denied", 'reason' => match (true) {
                $e instanceof EscrowKeeperException              => $e->reason,
                $e instanceof StaleEscrowException               => 'stale-escrow',
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

    /** The keeper as the log names it: its fingerprint, which tells two keepers of one class apart. */
    private function keeperName(): string
    {
        if ($this->keeper instanceof NoKeeper) {
            return 'NoKeeper';
        }
        try {
            return KeeperSeal::fingerprint(KeeperSeal::checkKeys($this->keeper->publicKeys()));
        } catch (Throwable) {
            return get_class($this->keeper) . ', without public keys';
        }
    }

    /**
     * $record with wrap_admin as the administrator opens it: for a compartment
     * sealed to a keeper, the inner box the keeper hands back, once it has
     * recorded the read at the head of this intention. Any other is returned as
     * it is — readable by the administrator alone, as before 0.9.0.
     *
     * @param array<string, mixed> $intent
     */
    private function throughKeeper(EscrowRecord $record, string $caseRef, array $intent): EscrowRecord
    {
        if (!EscrowVault::isKeeperSealed($record)) {
            return $record;
        }
        if ($this->keeper instanceof NoKeeper) {
            throw new EscrowKeeperException(
                'keeper-missing',
                "The escrow of '{$record->userId}' is sealed to a keeper, and this ceremony was given NoKeeper: nothing is unsealed"
            );
        }
        try {
            $print = KeeperSeal::fingerprint(KeeperSeal::checkKeys($this->keeper->publicKeys()));
        } catch (Throwable $e) {
            throw new EscrowKeeperException('keeper-failed', 'The keeper gave no public keys: nothing is unsealed', $e);
        }
        $held = $this->storage->loadSetting(SelfDataGuard::SETTING_KEEPER);
        if ($held !== null && !hash_equals($held, $print)) {
            throw new EscrowKeeperException(
                'keeper-mismatch',
                "This database recorded the escrow keeper {$held}, and this ceremony was given {$print}: nothing is unsealed"
            );
        }
        $escrowId = (string) KeeperSeal::escrowIdOf($record->wrapAdmin);
        $outer    = KeeperSeal::outerOf($record->wrapAdmin);
        $seq      = (int) $intent['seq'];
        $hmac     = (string) $intent['hmac'];
        try {
            $due = $this->keeper->announce($escrowId, $outer, $record->userId, $caseRef, $seq, $hmac);
        } catch (Throwable $e) {
            throw new EscrowKeeperException('keeper-failed', 'The keeper did not record the read: nothing is unsealed', $e);
        }
        if ($due > time()) {
            throw new EscrowAnnouncedException($due, $record->userId, $caseRef);
        }
        try {
            $inner = $this->keeper->release($escrowId, $outer, $caseRef, $seq, $hmac);
        } catch (Throwable $e) {
            throw new EscrowKeeperException('keeper-failed', 'The keeper did not hand back the box: nothing is unsealed', $e);
        }
        return new EscrowRecord($record->userId, $record->wrapUser, $inner, $record->createdAt, $record->updatedAt, $record->revision);
    }

    /**
     * Whether a witness took a head here before: an intention naming any
     * witness but NoHeadWitness, not followed by the refusal a witness that
     * failed leaves. An intention on the last line counts: the process may have
     * stopped right after the witness took it.
     */
    private function witnessTookAHead(): bool
    {
        $entries = $this->log->readAll();
        foreach ($entries as $i => $entry) {
            $event = $entry['event'] ?? [];
            if (str_ends_with((string) ($event['action'] ?? ''), '-intent')
                && ($event['witness'] ?? NoHeadWitness::class) !== NoHeadWitness::class
                && ($entries[$i + 1]['event']['reason'] ?? null) !== 'witness-failed') {
                return true;
            }
        }
        return false;
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
