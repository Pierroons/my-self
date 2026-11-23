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
use Pierroons\SelfDataGuard\Storage\UnreadableEscrowException;
use Pierroons\SelfDataGuard\Vault\ArchivedVault;
use Pierroons\SelfDataGuard\Vault\VaultNotFoundException;
use Pierroons\SelfDataGuard\Vault\WrongSecretException;
use RuntimeException;
use Throwable;

/**
 * The only path of the library to an account's escrow on the administrator's
 * side. The admin key is unsealed here and nowhere else — proveAdminKey(), which
 * the operator's adoption of the key calls, wipes it at once —, and only after
 * these things, in this order:
 *
 *   1. the arbiter's verdict on the case, whatever it is — an arbiter that
 *      throws answers Unavailable —, and the intention — who, which account,
 *      which case, which arbiter, which witness — written to the audit log and
 *      forced to disk, once the log is found to still hold the last head the
 *      witness took: a log cut or rewritten since stops the ceremony here;
 *   2. the head of the log taken out of the machine by the witness, which
 *      stops the ceremony when it fails;
 *   3. a verdict of Open;
  *   4. the escrow's label compared with the admin key given: an escrow sealed
 *      to another key stops here; then the passphrase checked — the admin key
 *      unsealed, found to be the private half of the public key given, then
 *      erased. A typo, or the wrong key file, reaches no keeper;
 *   5. for a compartment sealed to a keeper, the keeper's release, given the
 *      intention's head: it records the read and hands back the inner box —
 *      or holds it until a delay ends, or refuses it to an objection. Without
 *      a keeper (NoKeeper), such a compartment does not open; with another
 *      than the one the database recorded, it does not either. The admin key
 *      is unsealed again to open it, and never held across the call to the
 *      keeper.
 *
 * Then the result, or the failure, goes to the log too. A refused intention is
 * in the log as much as a granted one.
 *
 * A read names the fields it reads, and so does a comparison: no act reaches
 * every field by omission, and the arbiter is asked about the fields named. A
 * comparison (compareEscrow()) answers, field by field, whether a declared
 * value matches the escrow's under a named normalization, and hands no field
 * out: it is a read all the same — same arbiter, same log, same keeper, same
 * delay, same objection, the same release in the holder's view.
 *
 * ⚠️ Without a keeper, this holds nothing against the administrator alone:
 * whoever has the sealed key file and its passphrase opens wrap_admin outside
 * the library, with no log. Under one, a compartment sealed to it opens only
 * once the keeper hands its inner box back — recorded, and shown to the
 * holder, whether the ceremony asked or not.
 */
final class EscrowCeremony
{
    /**
     * @param list<EscrowKeeper> $formerKeepers keepers the deployment replaced
     *        (SelfDataGuard::changeKeeper()): a compartment still sealed to one
     *        — an archive, or an escrow its holder has not re-sealed — is read
     *        through it. One the database did not record as former is refused.
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly AuditLog $log,
        private readonly EscrowArbiter $arbiter,
        private readonly HeadWitness $witness,
        private readonly EscrowKeeper $keeper,
        private readonly string $operator,
        private readonly array $formerKeepers = []
    ) {
    }

    /**
     * The escrow fields of the account's live vault — only escrow fields, never
     * the private zone.
     *
     * @param list<string> $fieldNames the fields to read — at least one; a field the escrow does not hold is not returned
     * @return array<string, string> field_name => plaintext
     * @throws InvalidArgumentException when $fieldNames is empty or names a field twice — before anything is logged
     */
    public function readEscrow(
        string $userId,
        string $caseRef,
        string $sealedSecret,
        #[\SensitiveParameter] string $passphrase,
        string $adminPublicKeyB64,
        array $fieldNames
    ): array {
        $fieldNames = self::fieldList($fieldNames);
        return $this->perform('escrow-unlock', $userId, $caseRef, null, $fieldNames, ['fields' => $fieldNames], function (array $intent) use (
            $userId, $caseRef, $sealedSecret, $passphrase, $adminPublicKeyB64, $fieldNames
        ): array {
            $plain = $this->withLiveEscrow($userId, $caseRef, $sealedSecret, $passphrase, $adminPublicKeyB64, $fieldNames, $intent, self::decrypted(...));
            return [$plain, ['returned' => array_keys($plain)]];
        });
    }

    /**
     * Whether each value $declared gives matches the live escrow's field of
     * that name, both put under $normalization first — without the field
     * leaving the ceremony. Its value is neither returned nor logged; the log
     * says which fields were compared, under which normalization, and what
     * each came out as.
     *
     * It narrows what an honest operator sees, and nothing more: the
     * administrator key opens the box outside the library, and the escrow key
     * is in this process once the box opens.
     *
     * @param array<string, string> $declared field_name => the value the case declares — at least one
     * @return array<string, Comparison> field_name => Matches, Differs (under $normalization), or Unavailable — a
     *         field the escrow does not hold, or one that does not open
     * @throws InvalidArgumentException when $declared is empty or holds a value that is not a string — before
     *         anything is logged
     */
    public function compareEscrow(
        string $userId,
        string $caseRef,
        string $sealedSecret,
        #[\SensitiveParameter] string $passphrase,
        string $adminPublicKeyB64,
        #[\SensitiveParameter] array $declared,
        Normalization $normalization
    ): array {
        $fieldNames = self::declaredList($declared);
        return $this->perform('escrow-compare', $userId, $caseRef, null, $fieldNames, ['fields' => $fieldNames, 'normalization' => $normalization->value], function (array $intent) use (
            $userId, $caseRef, $sealedSecret, $passphrase, $adminPublicKeyB64, $fieldNames, $declared, $normalization
        ): array {
            $compared = $this->withLiveEscrow($userId, $caseRef, $sealedSecret, $passphrase, $adminPublicKeyB64, $fieldNames, $intent,
                static fn (UnlockedEscrow $escrow, array $ciphertexts): array => self::compared($escrow, $ciphertexts, $declared, $normalization));
            return [$compared, ['returned' => self::outcomes($compared)]];
        });
    }

    /**
     * The escrow fields of one archive of the account — for a holder who lost
     * every secret, which is what level 3 is.
     *
     * @param list<string> $fieldNames the fields to read — at least one; a field the archive does not hold is not returned
     * @return array<string, string> field_name => plaintext
     * @throws InvalidArgumentException when $fieldNames is empty or names a field twice — before anything is logged
     * @throws UnreadableEscrowException when the archive keeps an escrow that does not parse
     */
    public function readArchiveEscrow(
        string $userId,
        string $archiveId,
        string $caseRef,
        string $sealedSecret,
        #[\SensitiveParameter] string $passphrase,
        string $adminPublicKeyB64,
        array $fieldNames
    ): array {
        $fieldNames = self::fieldList($fieldNames);
        return $this->perform('archive-escrow-unlock', $userId, $caseRef, $archiveId, $fieldNames, ['fields' => $fieldNames], function (array $intent) use (
            $userId, $archiveId, $caseRef, $sealedSecret, $passphrase, $adminPublicKeyB64, $fieldNames
        ): array {
            $plain = $this->withArchiveEscrow($userId, $archiveId, $caseRef, $sealedSecret, $passphrase, $adminPublicKeyB64, $fieldNames, $intent, self::decrypted(...));
            return [$plain, ['returned' => array_keys($plain)]];
        });
    }

    /**
     * compareEscrow(), on the escrow of one archive of the account.
     *
     * @param array<string, string> $declared field_name => the value the case declares — at least one
     * @return array<string, Comparison>
     * @throws InvalidArgumentException when $declared is empty or holds a value that is not a string — before
     *         anything is logged
     * @throws UnreadableEscrowException when the archive keeps an escrow that does not parse
     */
    public function compareArchiveEscrow(
        string $userId,
        string $archiveId,
        string $caseRef,
        string $sealedSecret,
        #[\SensitiveParameter] string $passphrase,
        string $adminPublicKeyB64,
        #[\SensitiveParameter] array $declared,
        Normalization $normalization
    ): array {
        $fieldNames = self::declaredList($declared);
        return $this->perform('archive-escrow-compare', $userId, $caseRef, $archiveId, $fieldNames, ['fields' => $fieldNames, 'normalization' => $normalization->value], function (array $intent) use (
            $userId, $archiveId, $caseRef, $sealedSecret, $passphrase, $adminPublicKeyB64, $fieldNames, $declared, $normalization
        ): array {
            $compared = $this->withArchiveEscrow($userId, $archiveId, $caseRef, $sealedSecret, $passphrase, $adminPublicKeyB64, $fieldNames, $intent,
                static fn (UnlockedEscrow $escrow, array $ciphertexts): array => self::compared($escrow, $ciphertexts, $declared, $normalization));
            return [$compared, ['returned' => self::outcomes($compared)]];
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
        return $this->perform('archives-list', $userId, $caseRef, null, [], [], function () use ($userId): array {
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
     * A failure is logged, with the account. An archive that keeps an escrow
     * that does not parse is one: the pass of the account stops there.
     *
     * @return array{live: bool, archives: list<string>, frozen: bool} what was sealed
     * @throws UnreadableEscrowException when an archive of the account keeps an escrow that does not parse
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
            $r->revision,
            $r->adminKey
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
            $archive = $this->storage->loadArchive($userId, $listed['id']);
            $escrow  = $archive === null ? null : self::archiveEscrow($archive);
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
     * The operator's pass after SelfDataGuard::changeEscrowAdmin(): each escrow
     * of $userIds — live, and in each archive — that the old administrator key
     * opens alone is sealed again to the key the database now records, and
     * labelled with it. The escrow key stays, and the fields with it; no field
     * is read. The old key is proven and unsealed once for the whole pass, and
     * wiped after it. Logged once per account: what it re-sealed, and what it
     * left with the reason.
     *
     * Left: an escrow sealed to a keeper (keeper-sealed: its box opens only by a
     * release — the holder's next deposit re-seals the live one to the new key),
     * one whose label names a third key (other-key), one the old key does not
     * open (not-opened), a frozen live vault (frozen), an escrow that does not
     * parse (unreadable), one a deposit replaced meanwhile (stale: run the pass
     * again), an account the pass failed on (failed, and logged as such). Keep
     * the old key while the pass leaves anything under it.
     *
     * Re-sealing serves a change of the key's holder. It repairs no leak: a
     * copy of the database taken before still opens with the old key.
     *
     * @param list<string> $userIds
     * @return array<string, array{live: bool, archives: list<string>, left: array<string, string>}> by account: what
     *         was re-sealed, and what was left — keyed 'live', by archive id, or 'account' — with its reason
     * @throws EscrowAdminException when $newPublicKeyB64 is not the key the database records, or the old files are
     *         two keys' halves
     * @throws WrongSecretException on a wrong passphrase of the old key
     */
    public function resealToAdmin(
        array $userIds,
        string $oldSealedSecret,
        #[\SensitiveParameter] string $oldPassphrase,
        string $oldPublicKeyB64,
        string $newPublicKeyB64
    ): array {
        $old  = AdminKey::fingerprint($oldPublicKeyB64);
        $new  = AdminKey::fingerprint($newPublicKeyB64);
        $held = $this->storage->loadSetting(SelfDataGuard::SETTING_ESCROW_ADMIN);
        if ($held === null || !hash_equals($held, $new)) {
            throw new EscrowAdminException(
                'admin-mismatch',
                'This database records the administrator key ' . ($held ?? 'none') . ", not {$new}: the pass re-seals to "
                . 'the key the database records — change it first (SelfDataGuard::changeEscrowAdmin())'
            );
        }
        return $this->withAdminKey($oldSealedSecret, $oldPassphrase, function (string $sk) use ($userIds, $oldPublicKeyB64, $old, $newPublicKeyB64, $new): array {
            self::assertPair($sk, $oldPublicKeyB64);
            $done = [];
            foreach ($userIds as $userId) {
                $userId = (string) $userId;
                try {
                    $done[$userId] = $this->resealAccount($userId, $sk, $oldPublicKeyB64, $old, $newPublicKeyB64, $new);
                } catch (Throwable $e) {
                    $this->log->append(['action' => 'reseal-admin-failed', 'target' => $userId, 'admin' => $new,
                        'reason' => get_class($e), 'operator' => $this->operator]);
                    $done[$userId] = ['live' => false, 'archives' => [], 'left' => ['account' => 'failed']];
                }
            }
            return $done;
        });
    }

    /** @return array{live: bool, archives: list<string>, left: array<string, string>} */
    private function resealAccount(string $userId, #[\SensitiveParameter] string $sk, string $oldPub, string $old, string $newPub, string $new): array
    {
        $done = ['live' => false, 'archives' => [], 'left' => []];
        try {
            $live = $this->storage->loadEscrow($userId);
        } catch (UnreadableEscrowException) {
            $live = null;
            $done['left']['live'] = 'unreadable';
        }
        $resealed = $live === null ? null : self::resealedTo($live, $sk, $oldPub, $old, $newPub, $new);
        if ($resealed instanceof EscrowRecord) {
            try {
                $this->storage->replaceEscrow($this->storage->loadVault($userId)->generation(), $resealed);
                $done['live'] = true;
            } catch (\Pierroons\SelfDataGuard\Vault\VaultFrozenException) {
                $done['left']['live'] = 'frozen';
            } catch (StaleEscrowException) {
                $done['left']['live'] = 'stale';
            }
        } elseif ($resealed !== null) {
            $done['left']['live'] = $resealed;
        }
        foreach ($this->storage->listArchives($userId) as $listed) {
            $archiveId = (string) $listed['id'];
            $archive   = $this->storage->loadArchive($userId, $archiveId);
            if ($archive !== null && $archive->escrowUnreadable) {
                $done['left'][$archiveId] = 'unreadable';
                continue;
            }
            $resealed = $archive?->escrow === null ? null : self::resealedTo($archive->escrow, $sk, $oldPub, $old, $newPub, $new);
            if ($resealed instanceof EscrowRecord) {
                try {
                    $this->storage->replaceArchiveEscrow($userId, $archiveId, $resealed);
                    $done['archives'][] = $archiveId;
                } catch (StaleEscrowException) {
                    $done['left'][$archiveId] = 'stale';
                }
            } elseif ($resealed !== null) {
                $done['left'][$archiveId] = $resealed;
            }
        }
        $this->log->append(['action' => 'reseal-admin', 'target' => $userId, 'admin' => $new, 'former' => $old,
            'live' => $done['live'], 'archives' => $done['archives'], 'left' => $done['left'], 'operator' => $this->operator]);
        return $done;
    }

    /**
     * $record sealed again to the new key and labelled with it; null when it is
     * under the new key already; or why it is left.
     */
    private static function resealedTo(
        EscrowRecord $record,
        #[\SensitiveParameter] string $sk,
        string $oldPub,
        string $old,
        string $newPub,
        string $new
    ): EscrowRecord|string|null {
        if ($record->adminKey !== null && hash_equals($record->adminKey, $new)) {
            return null;
        }
        if (EscrowVault::isKeeperSealed($record)) {
            return 'keeper-sealed';
        }
        if ($record->adminKey !== null && !hash_equals($record->adminKey, $old)) {
            return 'other-key';
        }
        try {
            $unlocked = self::openWrapAdmin($record, $sk, $oldPub);
        } catch (RuntimeException) {
            return 'not-opened';
        }
        try {
            return new EscrowRecord(
                $record->userId,
                $record->wrapUser,
                $unlocked->sealToAdmin($newPub),
                $record->createdAt,
                new \DateTimeImmutable(),
                $record->revision,
                $new
            );
        } finally {
            $unlocked->lock();
        }
    }

    /**
     * The escrow $archive keeps, or null when it keeps none.
     *
     * @throws UnreadableEscrowException when it keeps one that does not parse
     */
    private static function archiveEscrow(ArchivedVault $archive): ?EscrowRecord
    {
        if ($archive->escrowUnreadable) {
            throw new UnreadableEscrowException(
                "The escrow of archive '{$archive->archiveId}' of '{$archive->record->userId}' does not parse: nothing of it is read or sealed"
            );
        }
        return $archive->escrow;
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
     * @param list<string>                                          $fieldNames the fields the act reads or compares, for the arbiter
     * @param array<string, mixed>                                  $detail
     * @param callable(array<string, mixed>): array{0: mixed, 1: array<string, mixed>} $work
     *        given the intention as the log wrote it; returns the result, and what the log says of it
     */
    private function perform(string $act, string $userId, string $caseRef, ?string $archiveId, array $fieldNames, array $detail, callable $work): mixed
    {
        $context = ['target' => $userId, 'case' => $caseRef, 'archive' => $archiveId, 'operator' => $this->operator] + $detail;
        $failure = [];
        try {
            $verdict = $this->arbiter->verdict($userId, $caseRef, $archiveId, $fieldNames);
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
                $e instanceof EscrowAdminException               => $e->reason,
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

    /**
     * The former keepers this ceremony was given, each checked against the
     * database's record of former keepers.
     *
     * @return list<EscrowKeeper>
     */
    private function recordedFormerKeepers(): array
    {
        if ($this->formerKeepers === []) {
            return [];
        }
        $recorded = $this->storage->loadSetting(SelfDataGuard::SETTING_FORMER_KEEPERS);
        try {
            $prints = KeeperSeal::formerPrints($recorded);
        } catch (InvalidArgumentException $unreadable) {
            throw new EscrowKeeperException('keeper-mismatch', $unreadable->getMessage() . ' — the escrow stays sealed', $unreadable);
        }
        foreach ($this->formerKeepers as $former) {
            try {
                $print = KeeperSeal::fingerprint(KeeperSeal::checkKeys($former->publicKeys()));
            } catch (Throwable $e) {
                throw new EscrowKeeperException('keeper-failed', 'A former keeper gave no public keys: the escrow stays sealed', $e);
            }
            if (!in_array($print, $prints, true)) {
                throw new EscrowKeeperException(
                    'keeper-mismatch',
                    "This ceremony was given {$print} as a former keeper, and the database recorded no such one: the escrow stays sealed"
                );
            }
        }
        return $this->formerKeepers;
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
                "The escrow of '{$record->userId}' is sealed to a keeper, and this ceremony was given NoKeeper: the escrow stays sealed"
            );
        }
        try {
            $print = KeeperSeal::fingerprint(KeeperSeal::checkKeys($this->keeper->publicKeys()));
        } catch (Throwable $e) {
            throw new EscrowKeeperException('keeper-failed', 'The keeper gave no public keys: the escrow stays sealed', $e);
        }
        $held = $this->storage->loadSetting(SelfDataGuard::SETTING_KEEPER);
        if ($held !== null && !hash_equals($held, $print)) {
            throw new EscrowKeeperException(
                'keeper-mismatch',
                "This database recorded the escrow keeper {$held}, and this ceremony was given {$print}: the escrow stays sealed"
            );
        }
        $escrowId = (string) KeeperSeal::escrowIdOf($record->wrapAdmin);
        $outer    = KeeperSeal::outerOf($record->wrapAdmin);
        $seq      = (int) $intent['seq'];
        $hmac     = (string) $intent['hmac'];
        $failures = [];
        foreach ([$this->keeper, ...$this->recordedFormerKeepers()] as $i => $keeper) {
            try {
                $due = $keeper->announce($escrowId, $outer, $record->userId, $caseRef, $seq, $hmac);
            } catch (Throwable $e) {
                // A box sealed to another keeper does not open here: the next one may hold it.
                $failures[] = [$i === 0 ? 'the keeper' : "former keeper {$i}", $e];
                continue;
            }
            if ($due > time()) {
                throw new EscrowAnnouncedException($due, $record->userId, $caseRef);
            }
            try {
                $inner = $keeper->release($escrowId, $outer, $caseRef, $seq, $hmac);
            } catch (Throwable $e) {
                throw new EscrowKeeperException('keeper-failed', 'The keeper did not hand back the box: the escrow stays sealed', $e);
            }
            return new EscrowRecord($record->userId, $record->wrapUser, $inner, $record->createdAt, $record->updatedAt, $record->revision, $record->adminKey);
        }
        throw new EscrowKeeperException(
            'keeper-failed',
            'No keeper given recorded the read: the escrow stays sealed — '
            . implode('; ', array_map(static fn (array $f): string => "{$f[0]}: {$f[1]->getMessage()}", $failures)),
            $failures[0][1]
        );
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
     * The admin key unsealed, found to be the private half of $adminPublicKeyB64,
     * and erased at once — before any keeper is asked: WrongSecretException on a
     * wrong passphrase, EscrowAdminException (admin-not-the-pair) on the key file
     * of another key.
     */
    private function checkPassphrase(string $sealedSecret, #[\SensitiveParameter] string $passphrase, string $adminPublicKeyB64): void
    {
        self::proveAdminKey($sealedSecret, $passphrase, $adminPublicKeyB64);
    }

    /**
     * The escrow's label names the admin key it is sealed to: given another,
     * the ceremony stops here — before the passphrase, and before any keeper is
     * asked, so that a wrong key costs no release the holder would see as a
     * read. An escrow written before 0.10.0 names none, and goes on.
     *
     * @throws EscrowAdminException admin-mismatch
     */
    private static function assertLabel(EscrowRecord $record, string $adminPublicKeyB64): void
    {
        $given = AdminKey::fingerprint($adminPublicKeyB64);
        if ($record->adminKey !== null && !hash_equals($record->adminKey, $given)) {
            throw new EscrowAdminException(
                'admin-mismatch',
                "The escrow of '{$record->userId}' is sealed to the administrator key {$record->adminKey}, and this "
                . "ceremony was given {$given}: nothing is unsealed, and no keeper is asked"
            );
        }
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
     * The live escrow of $userId, opened — through the keeper when it is sealed
     * to one —, handed to $use with the ciphertexts of $fieldNames it holds, and
     * locked again whatever $use does.
     *
     * @template T
     * @param list<string>                                         $fieldNames
     * @param array<string, mixed>                                 $intent
     * @param callable(UnlockedEscrow, array<string, string>): T   $use
     * @return T
     */
    private function withLiveEscrow(
        string $userId,
        string $caseRef,
        string $sealedSecret,
        #[\SensitiveParameter] string $passphrase,
        string $adminPublicKeyB64,
        array $fieldNames,
        array $intent,
        callable $use
    ): mixed {
        [$snapshot, $ciphertexts] = EscrowSnapshot::read($this->storage, $userId, $fieldNames)
            ?? throw new RuntimeException("No escrow compartment for '{$userId}'");
        self::assertLabel($snapshot, $adminPublicKeyB64);
        $this->checkPassphrase($sealedSecret, $passphrase, $adminPublicKeyB64);
        $record = $this->throughKeeper($snapshot, $caseRef, $intent);
        return $this->withAdminKey($sealedSecret, $passphrase, fn (string $sk): mixed => self::using(
            self::openWrapAdmin($record, $sk, $adminPublicKeyB64),
            $ciphertexts,
            $use
        ));
    }

    /**
     * withLiveEscrow(), on the escrow of archive $archiveId.
     *
     * @template T
     * @param list<string>                                         $fieldNames
     * @param array<string, mixed>                                 $intent
     * @param callable(UnlockedEscrow, array<string, string>): T   $use
     * @return T
     */
    private function withArchiveEscrow(
        string $userId,
        string $archiveId,
        string $caseRef,
        string $sealedSecret,
        #[\SensitiveParameter] string $passphrase,
        string $adminPublicKeyB64,
        array $fieldNames,
        array $intent,
        callable $use
    ): mixed {
        $archive = $this->storage->loadArchive($userId, $archiveId)
            ?? throw new VaultNotFoundException("No archive '{$archiveId}' for '{$userId}'");
        if ($archive->record->userId !== $userId || self::archiveEscrow($archive) === null) {
            throw new RuntimeException("No escrow compartment in archive '{$archiveId}' of '{$userId}'");
        }
        $ciphertexts = array_intersect_key($archive->escrowFields, array_flip($fieldNames));
        self::assertLabel($archive->escrow, $adminPublicKeyB64);
        $this->checkPassphrase($sealedSecret, $passphrase, $adminPublicKeyB64);
        $escrow = $this->throughKeeper($archive->escrow, $caseRef, $intent);
        return $this->withAdminKey($sealedSecret, $passphrase, fn (string $sk): mixed => self::using(
            self::openWrapAdmin($escrow, $sk, $adminPublicKeyB64),
            $ciphertexts,
            $use
        ));
    }

    /**
     * @template T
     * @param array<string, string>                              $ciphertexts
     * @param callable(UnlockedEscrow, array<string, string>): T $use
     * @return T
     */
    private static function using(UnlockedEscrow $escrow, array $ciphertexts, callable $use): mixed
    {
        try {
            return $use($escrow, $ciphertexts);
        } finally {
            $escrow->lock();
        }
    }

    /**
     * @param array<string, string> $ciphertexts
     * @return array<string, string>
     */
    private static function decrypted(UnlockedEscrow $escrow, array $ciphertexts): array
    {
        return EscrowFieldCrypter::decryptBatch($escrow, $ciphertexts);
    }

    /**
     * Each declared value against the field of its name, both under
     * $normalization; the field's value is wiped once compared.
     *
     * @param array<string, string> $ciphertexts
     * @param array<string, string> $declared
     * @return array<string, Comparison>
     */
    private static function compared(
        UnlockedEscrow $escrow,
        array $ciphertexts,
        #[\SensitiveParameter] array $declared,
        Normalization $normalization
    ): array {
        $compared = [];
        foreach ($declared as $name => $value) {
            $name = (string) $name;
            if (!isset($ciphertexts[$name])) {
                $compared[$name] = Comparison::Unavailable;
                continue;
            }
            try {
                $plain = EscrowFieldCrypter::decrypt($escrow, $name, $ciphertexts[$name]);
            } catch (Throwable) {
                $compared[$name] = Comparison::Unavailable;
                continue;
            }
            $held  = $normalization->apply($plain);
            $given = $normalization->apply($value);
            $compared[$name] = hash_equals($held, $given) ? Comparison::Matches : Comparison::Differs;
            Primitives::zeroize($plain);
            Primitives::zeroize($held);
            Primitives::zeroize($given);
        }
        return $compared;
    }

    /**
     * @param array<string, Comparison> $compared
     * @return array<string, string> what the log says of a comparison: each field's outcome, never a value
     */
    private static function outcomes(array $compared): array
    {
        return array_map(static fn (Comparison $c): string => $c->value, $compared);
    }

    /**
     * $fieldNames as an act takes them: at least one, each a name, none twice —
     * an act on every field is never one by omission.
     *
     * @param array<mixed> $fieldNames
     * @return list<string>
     */
    private static function fieldList(array $fieldNames): array
    {
        if ($fieldNames === []) {
            throw new InvalidArgumentException('Name the escrow fields to read or compare: no act reaches every field by omission');
        }
        $names = [];
        foreach ($fieldNames as $name) {
            if (!is_string($name) || $name === '' || in_array($name, $names, true)) {
                throw new InvalidArgumentException('Escrow field names are non-empty strings, each named once');
            }
            $names[] = $name;
        }
        return $names;
    }

    /**
     * The fields $declared names, once each value is found to be a string.
     *
     * @param array<mixed> $declared
     * @return list<string>
     */
    private static function declaredList(#[\SensitiveParameter] array $declared): array
    {
        foreach ($declared as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('A declared value is a string');
            }
        }
        return self::fieldList(array_map('strval', array_keys($declared)));
    }

    /**
     * Proof that whoever calls holds the administrator key $publicKeyB64: the
     * passphrase unseals $sealedSecret, and the secret key it holds is the
     * private half of $publicKeyB64. The secret key is wiped before returning
     * — the operator's adoption of the key calls it (SelfDataGuard::adoptEscrowAdmin()),
     * and the ceremony before any keeper is asked.
     *
     * @throws WrongSecretException     on a wrong passphrase
     * @throws InvalidArgumentException on a sealed file, or a public key, that does not parse
     * @throws EscrowAdminException     admin-not-the-pair, when the secret key is another key's
     */
    public static function proveAdminKey(string $sealedSecret, #[\SensitiveParameter] string $passphrase, string $publicKeyB64): void
    {
        EscrowVault::decodePublicKey($publicKeyB64);
        $secretKey = self::unsealAdminKey($sealedSecret, $passphrase);
        try {
            self::assertPair($secretKey, $publicKeyB64);
        } finally {
            Primitives::zeroize($secretKey);
        }
    }

    /**
     * @throws EscrowAdminException admin-not-the-pair, when $secretKey is not the private half of $publicKeyB64
     */
    private static function assertPair(#[\SensitiveParameter] string $secretKey, string $publicKeyB64): void
    {
        $publicKey = EscrowVault::decodePublicKey($publicKeyB64);
        if (strlen($secretKey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES
            || !hash_equals(sodium_crypto_box_publickey_from_secretkey($secretKey), $publicKey)) {
            throw new EscrowAdminException(
                'admin-not-the-pair',
                'The sealed secret key is not the private half of the administrator public key given ('
                . AdminKey::fingerprint($publicKeyB64) . '): the two files belong to different keys'
            );
        }
    }

    /**
     * The admin secret key, from its sealed file and passphrase.
     *
     * @throws InvalidArgumentException on a sealed file that cannot be read
     * @throws WrongSecretException     on a wrong passphrase (auth tag mismatch)
     * @throws LegacyCipherUnavailableException on a key sealed before 0.4.0, on a machine without its cipher
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
