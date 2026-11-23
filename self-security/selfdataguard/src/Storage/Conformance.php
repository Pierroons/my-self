<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Storage;

use DateTimeImmutable;
use Pierroons\SelfDataGuard\Crypto\Aad;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Escrow\EscrowExistsException;
use Pierroons\SelfDataGuard\Escrow\EscrowNotFoundException;
use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\Escrow\StaleEscrowException;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\StaleVaultException;
use Pierroons\SelfDataGuard\Vault\VaultExistsException;
use Pierroons\SelfDataGuard\Vault\VaultFrozenException;
use Pierroons\SelfDataGuard\Vault\VaultGeneration;
use Pierroons\SelfDataGuard\Vault\VaultNotFoundException;
use Pierroons\SelfDataGuard\Vault\VaultRecord;
use RuntimeException;
use Throwable;

/**
 * The StorageInterface contract, played against an implementation.
 *
 * The interface fixes signatures; it cannot fix behaviour. A storage that
 * receives a generation and never compares it, that upserts on insert, or
 * that finds 'alice' when asked for 'ALICE', satisfies every signature and
 * breaks the vault in silence. These cases are what tells it apart.
 *
 * It writes to the database it is given, under userIds drawn at random
 * ("conformance-<hex>-…"), and erases each of them when its case ends —
 * an archive eraseAccount() keeps included, through ErasesArchives when the
 * storage implements it, otherwise brought back live and erased. The settings
 * it claims stay, under names of the same form, which nothing reads; so do the
 * tickets its cases apply, which no longer name an account once it is erased.
 * Each concurrent case is played ten times. Run it against a database you can
 * write to, before the storage serves anyone.
 *
 * The cases that need several processes run only when the connection comes
 * from a file — the child processes require it in turn. Without one, they are
 * reported skipped, not passed.
 *
 * An integrator that writes its account and the vault in one commit opens the
 * transaction itself: the storage must write inside it, and leave the commit or
 * the rollback to the caller. That case needs a connection the suite cannot
 * open through the contract, hence the third argument of run().
 *
 * A freeze is checked in sequence only: no case races it against a write. A
 * storage that reads the freeze, then writes in a second transaction, passes;
 * compare it where the generation is compared.
 */
final class Conformance
{
    /** Processes that race in the concurrent cases. */
    private const RACERS = 4;

    /**
     * Times each concurrent case is played. One race catches a storage that
     * checks, then writes without a pause, about nine times in ten: a pass is a
     * presumption, which ten rounds make a strong one.
     */
    private const ROUNDS = 10;

    /** The columns of the escrow row the rewrite of escrow-unreadable-row-named makes unreadable, one account each. */
    private const UNPARSED_COLUMNS = ['wrap_admin', 'wrap_user', 'created_at', 'updated_at', 'revision'];

    /**
     * The date columns, which a rewrite may decline when its typed date column holds only values PHP reads; one that
     * also holds a value PHP does not read (PostgreSQL's 'infinity') writes that value instead.
     */
    private const DECLINABLE_COLUMNS = ['created_at', 'updated_at'];

    /** Argon2id profile written by the cases: not the constants, so a storage that substitutes them shows. */
    private const OPSLIMIT = 7;
    private const MEMLIMIT = 12_345_678;

    /** @var callable(): StorageInterface */
    private $connect;

    private StorageInterface $storage;

    /** @var list<string> userIds to erase when the current case ends */
    private array $created = [];

    private string $prefix;

    private ?EncryptedBlob $escrowWrap = null;

    /** @var (callable(callable(StorageInterface): void): void)|null */
    private $withCallerTransaction;

    /** @var (callable(string, string): bool)|null */
    private $unparseEscrow;

    /**
     * @param callable(): StorageInterface $connect a new connection to the database under test
     */
    private function __construct(
        callable $connect,
        private readonly ?string $connectFile,
        ?callable $withCallerTransaction,
        ?callable $unparseEscrow
    ) {
        $this->withCallerTransaction = $withCallerTransaction;
        $this->unparseEscrow = $unparseEscrow;
        $this->connect = $connect;
        $this->storage = $connect();
        $this->prefix  = 'conformance-' . bin2hex(random_bytes(6));
    }

    /**
     * @param callable(): StorageInterface $connect     a new connection to the database under test
     * @param string|null                  $connectFile a PHP file that loads its autoloader and
     *                                                  returns that same callable
     * @param (callable(callable(StorageInterface): void): void)|null $withCallerTransaction
     *        opens a transaction on a new connection to the database under test, hands the storage
     *        built on that connection to its argument, then rolls the transaction back. Without it,
     *        the case that needs it is reported skipped.
     * @param (callable(string, string): bool)|null $unparseEscrow rewrites, in the database under
     *        test, the column it is given — one of UNPARSED_COLUMNS — of the escrow row of the userId
     *        it is given, so that the row no longer parses, and returns true; it may return false for a
     *        column of DECLINABLE_COLUMNS, which the case leaves out. Any other return fails the case.
     *        Without it, the case that needs it is reported skipped.
     * @return list<array{case: string, outcome: string, detail: string}> outcome is pass, fail or skip
     */
    public static function run(
        callable $connect,
        ?string $connectFile = null,
        ?callable $withCallerTransaction = null,
        ?callable $unparseEscrow = null
    ): array {
        $suite = new self($connect, $connectFile, $withCallerTransaction, $unparseEscrow);
        $results = [];
        foreach (self::cases() as $case => $method) {
            try {
                $rounds = str_starts_with($case, 'concurrent-') ? self::ROUNDS : 1;
                for ($round = 0; $round < $rounds; $round++) {
                    if ($round > 0) {
                        $suite->eraseCreated();
                    }
                    $detail = $suite->{$method}();
                    if ($detail !== null) {
                        break;
                    }
                }
                $outcome = $detail === null ? 'pass' : 'skip';
            } catch (Throwable $e) {
                $outcome = 'fail';
                $detail  = $e->getMessage();
            } finally {
                $suite->eraseCreated();
            }
            $results[] = ['case' => $case, 'outcome' => $outcome, 'detail' => $detail ?? ''];
        }
        return $results;
    }

    /** @return array<string, string> case name => method */
    private static function cases(): array
    {
        return [
            'vault-round-trip'              => 'vaultRoundTrip',
            'vault-insert-never-replaces'   => 'insertNeverReplaces',
            'userid-byte-for-byte'          => 'userIdByteForByte',
            'update-compare-and-swap'       => 'updateCompareAndSwap',
            'writes-check-their-generation' => 'writesCheckGeneration',
            'rewrites-are-idempotent'       => 'rewritesIdempotent',
            'fields-round-trip'             => 'fieldsRoundTrip',
            'blind-index-unique'            => 'blindIndexUnique',
            'escrow-round-trip'             => 'escrowRoundTrip',
            'escrow-compare-and-swap'       => 'escrowCompareAndSwap',
            'rotate-escrow'                 => 'rotateEscrowCase',
            'escrow-unreadable-row-named'   => 'unreadableEscrowNamed',
            'escrow-admin-label'            => 'escrowAdminLabel',
            'delete-vault'                  => 'deleteVault',
            'replace-with-archive'          => 'replaceWithArchive',
            'delete-archive'                => 'deleteArchive',
            'erase-account'                 => 'eraseAccount',
            'joins-callers-transaction'     => 'joinsCallersTransaction',
            'swap-with-archive'             => 'swapWithArchive',
            'restore-archive'               => 'restoreArchive',
            'archive-reserves-its-indexes'  => 'archiveReservesItsIndexes',
            'archive-escrow-unreadable'     => 'archiveEscrowUnreadable',
            'erase-account-keeps-recovery-archives' => 'eraseKeepsRecoveryArchives',
            'erase-account-keeps-tickets'   => 'eraseKeepsTickets',
            'replace-archive-escrow'        => 'replaceArchiveEscrowCase',
            'replace-archive-vault'         => 'replaceArchiveVaultCase',
            'freeze-refuses-writes'         => 'freezeRefusesWrites',
            'frozen-vault-takes-its-locks'  => 'frozenVaultTakesItsLocks',
            'tickets-applied-once'          => 'ticketsAppliedOnce',
            'concurrent-updates'            => 'concurrentUpdates',
            'concurrent-re-enrolments'      => 'concurrentReEnrolments',
            'concurrent-unique-index'       => 'concurrentUniqueIndex',
            'concurrent-escrow-creations'   => 'concurrentEscrowCreations',
            'concurrent-escrow-rotations'   => 'concurrentEscrowRotations',
            'concurrent-writes-and-re-enrolment' => 'concurrentWritesAndReEnrolment',
            'concurrent-archive-swaps'      => 'concurrentArchiveSwaps',
            'setting-claimed-once'          => 'settingClaimedOnce',
            'setting-replaced-over-expected' => 'settingReplacedOverExpected',
            'has-vaults'                    => 'hasVaultsCase',
            'indexed-fields'                => 'indexedFieldsCase',
            'account-ids'                   => 'accountIdsCase',
            'concurrent-setting-claims'     => 'concurrentSettingClaims',
            'concurrent-setting-replacements' => 'concurrentSettingReplacements',
        ];
    }

    // -- Cases: each returns null when it passes, a reason when it is skipped,
    //    and throws when it fails. -------------------------------------------

    private function vaultRoundTrip(): ?string
    {
        $full = $this->newVault('full', withRecov: true, withPhrase: true);
        $bare = $this->newVault('bare', withRecov: false, withPhrase: false);
        foreach ([$full, $bare] as $written) {
            $this->storage->saveVault($written);
            $this->assert($this->storage->vaultExists($written->userId), 'vaultExists() is false right after saveVault()');
            foreach (['loadVault' => $this->storage->loadVault($written->userId), 'findVault' => $this->storage->findVault($written->userId)] as $how => $read) {
                $this->assert($read !== null, "{$how}() found nothing right after saveVault()");
                $this->assertSameVault($written, $read, $how . '()');
                $this->assert($read->revision === 0, "{$how}(): a new vault is at revision 0, not {$read->revision}");
            }
        }
        $absent = $this->id('absent');
        $this->assert(!$this->storage->vaultExists($absent), 'vaultExists() is true for a userId never saved');
        $this->assert($this->storage->findVault($absent) === null, 'findVault() returned a vault for a userId never saved');
        $this->expect(VaultNotFoundException::class, fn () => $this->storage->loadVault($absent), 'loadVault() of a userId never saved');
        return null;
    }

    private function insertNeverReplaces(): ?string
    {
        $first = $this->newVault('dup');
        $this->storage->saveVault($first);
        $second = $this->newVault('dup');
        $this->expect(VaultExistsException::class, fn () => $this->storage->saveVault($second), 'a second saveVault() of the same userId');
        $this->assert(
            hash_equals($first->userSalt, $this->storage->loadVault($first->userId)->userSalt),
            'a refused saveVault() replaced the vault in place: an insert must never be an upsert'
        );
        return null;
    }

    private function userIdByteForByte(): ?string
    {
        $vault = $this->newVault('Case');
        $this->storage->saveVault($vault);
        foreach ([strtoupper($vault->userId), strtolower($vault->userId), $vault->userId . ' '] as $other) {
            $this->assert(!$this->storage->vaultExists($other), "vaultExists('{$other}') is true: user_id is not compared byte for byte");
            $this->assert($this->storage->findVault($other) === null, "findVault('{$other}') returned '{$vault->userId}': user_id is not compared byte for byte");
        }
        return null;
    }

    private function updateCompareAndSwap(): ?string
    {
        $vault = $this->newVault('cas', withPhrase: true);
        $this->storage->saveVault($vault);
        $read = $this->storage->loadVault($vault->userId);

        $first = $read->withWrapPwd($this->blob(), new DateTimeImmutable())
            ->withProfile(self::OPSLIMIT + 1, self::MEMLIMIT + 1)
            ->withProfileSeal(Primitives::randomBytes(32));
        $this->assert($this->storage->updateVault($first) === 1, 'updateVault() did not return the revision it stored (1)');
        $stored = $this->storage->loadVault($vault->userId);
        $this->assert($stored->revision === 1, "the stored revision is {$stored->revision} after one update, not 1");
        $this->assertSameVault($first, $stored, 'after updateVault()', ignoreRevision: true);

        $lost = $read->withWrapPhrase(null, new DateTimeImmutable());
        $this->expect(StaleVaultException::class, fn () => $this->storage->updateVault($lost), 'an update from a record read before the last one');
        $this->assert(
            $this->storage->loadVault($vault->userId)->wrapPhrase?->toBase64() === $vault->wrapPhrase?->toBase64(),
            'a refused update still removed the passphrase wrap: the revision is not compared'
        );

        $foreign = $this->withSalt($stored, Primitives::randomBytes(Primitives::SALT_LEN));
        $this->expect(StaleVaultException::class, fn () => $this->storage->updateVault($foreign), 'an update naming another salt');

        $missing = $this->newVault('cas-missing');
        $this->expect(VaultNotFoundException::class, fn () => $this->storage->updateVault($missing), 'an update of a userId with no vault');
        return null;
    }

    private function writesCheckGeneration(): ?string
    {
        $vault = $this->newVault('gen');
        $this->storage->saveVault($vault);
        $archiveId = $this->storage->replaceWithArchive($vault->generation(), $live = $this->newVault('gen'));
        $foreign = new VaultGeneration($vault->userId, Primitives::randomBytes(Primitives::SALT_LEN));
        $absent  = new VaultGeneration($this->id('gen-absent'), Primitives::randomBytes(Primitives::SALT_LEN));

        foreach ([StaleVaultException::class => $foreign, VaultNotFoundException::class => $absent] as $expected => $generation) {
            foreach ($this->writesUnder($generation, $archiveId) as $write => $call) {
                $this->expect($expected, $call, "{$write}() naming " . ($generation === $foreign ? 'a replaced salt' : 'a userId without a live vault'));
            }
        }
        $this->assert(
            $this->storage->loadFields($vault->userId) === []
                && $this->storage->loadEscrow($vault->userId) === null
                && $this->storage->loadEscrowFields($vault->userId) === [],
            'a refused write left a field or an escrow behind'
        );
        $this->assert(
            hash_equals($live->userSalt, $this->storage->loadVault($vault->userId)->userSalt)
                && count($this->storage->listArchives($vault->userId)) === 1,
            'a refused deleteVault(), replaceWithArchive() or deleteArchive() changed the vault or its archives'
        );
        $this->assert(
            $this->storage->loadFields($absent->userId) === [] && !$this->storage->vaultExists($absent->userId),
            'a write for a userId without a live vault left an orphan behind'
        );
        return null;
    }

    private function rewritesIdempotent(): ?string
    {
        $vault = $this->newVault('same');
        $this->storage->saveVault($vault);
        $generation = $vault->generation();
        $escrow = $this->escrow($vault->userId);
        $fields = ['note' => ['ciphertext' => 'c-note', 'blindIndex' => null]];
        $this->storage->createEscrow($generation, $escrow);
        for ($i = 0; $i < 2; $i++) {
            // Read again each time: the revision is what a rewrite must name.
            $this->storage->replaceEscrow(
                $generation,
                $this->storage->loadEscrow($vault->userId) ?? throw new RuntimeException('loadEscrow() lost the escrow it was given')
            );
            $this->storage->saveFields($generation, $fields);
            $this->storage->saveEscrowFields($generation, ['contact' => 'c-contact']);
        }
        $this->assert(
            $this->storage->loadFields($vault->userId) === ['note' => 'c-note']
                && $this->storage->loadEscrowFields($vault->userId) === ['contact' => 'c-contact']
                && $this->storage->loadEscrow($vault->userId)?->wrapAdmin === $escrow->wrapAdmin,
            'writing the same values twice changed them — or a write that changes no row was taken for a stale one'
        );
        return null;
    }

    private function fieldsRoundTrip(): ?string
    {
        $alice = $this->newVault('alice');
        $bob   = $this->newVault('bob');
        $this->storage->saveVault($alice);
        $this->storage->saveVault($bob);
        $this->storage->saveFields($alice->generation(), [
            'email' => ['ciphertext' => 'c-email-1', 'blindIndex' => 'IdxAbC='],
            'note'  => ['ciphertext' => 'c-note-1'],
            'city'  => ['ciphertext' => 'c-city-1', 'blindIndex' => null],
        ]);
        $this->storage->saveFields($bob->generation(), ['email' => ['ciphertext' => 'c-email-bob', 'blindIndex' => 'IdxBob=']]);

        $all = $this->storage->loadFields($alice->userId);
        ksort($all);
        $this->assert($all === ['city' => 'c-city-1', 'email' => 'c-email-1', 'note' => 'c-note-1'], 'loadFields() did not return every field as written');
        $some = $this->storage->loadFields($alice->userId, ['note', 'email']);
        ksort($some);
        $this->assert($some === ['email' => 'c-email-1', 'note' => 'c-note-1'], 'loadFields() with names did not return exactly those fields');

        $this->storage->saveFields($alice->generation(), ['note' => ['ciphertext' => 'c-note-2']]);
        $this->assert($this->storage->loadFields($alice->userId, ['note']) === ['note' => 'c-note-2'], 'saving a field again did not replace it');
        $this->assert(count($this->storage->loadFields($alice->userId)) === 3, 'saving a field again added a row instead of replacing it');

        $this->assert($this->storage->findUserIdsByIndex('email', 'IdxAbC=') === [$alice->userId], 'findUserIdsByIndex() did not find the account by its index');
        $this->assert($this->storage->findUserIdsByIndex('email', 'IdxBob=') === [$bob->userId], 'findUserIdsByIndex() returned the wrong account');
        $this->assert($this->storage->findUserIdsByIndex('email', 'idxabc=') === [], 'an index matched another case: it is base64, compare it byte for byte');
        $this->assert($this->storage->findUserIdsByIndex('phone', 'IdxAbC=') === [], 'an index matched under another field name');
        $this->assert($this->storage->findUserIdsByIndex('email', 'IdxNone=') === [], 'findUserIdsByIndex() returned an account on a miss');
        return null;
    }

    private function blindIndexUnique(): ?string
    {
        $alice = $this->newVault('uniq-alice');
        $bob   = $this->newVault('uniq-bob');
        $this->storage->saveVault($alice);
        $this->storage->saveVault($bob);
        $this->storage->saveFields($alice->generation(), [
            'email'     => ['ciphertext' => 'c-a', 'blindIndex' => 'IdxTaken='],
            'alt_email' => ['ciphertext' => 'c-a-alt', 'blindIndex' => 'IdxAlt='],
        ]);

        try {
            $this->storage->saveFields($bob->generation(), [
                'email'     => ['ciphertext' => 'c-b', 'blindIndex' => 'IdxTaken='],
                'note'      => ['ciphertext' => 'c-b-note'],
                'alt_email' => ['ciphertext' => 'c-b-alt', 'blindIndex' => 'IdxAlt='],
            ]);
            throw new RuntimeException('a second account was given a blind-index value another account holds');
        } catch (DuplicateIndexException $e) {
            $this->assert(
                $e->fields === ['email', 'alt_email'],
                'DuplicateIndexException names [' . implode(', ', $e->fields) . '], not every taken field of the batch'
            );
        }
        $this->assert($this->storage->loadFields($bob->userId) === [], 'a refused batch was written in part: the field beside the taken one is there');
        $this->assert(
            $this->storage->findUserIdsByIndex('email', 'IdxTaken=') === [$alice->userId],
            'the taken value no longer names its holder alone'
        );

        $this->storage->saveFields($alice->generation(), ['email' => ['ciphertext' => 'c-a2', 'blindIndex' => 'IdxTaken=']]);
        $this->storage->saveFields($bob->generation(), [
            'backup_email' => ['ciphertext' => 'c-b2', 'blindIndex' => 'IdxTaken='],
            'city'         => ['ciphertext' => 'c-b3'],
        ]);
        $this->storage->saveFields($alice->generation(), ['city' => ['ciphertext' => 'c-a3']]);

        $this->storage->saveFields($alice->generation(), ['phone' => ['ciphertext' => 'c-pa', 'sharedIndex' => 'IdxShared=']]);
        $this->storage->saveFields($bob->generation(), ['phone' => ['ciphertext' => 'c-pb', 'sharedIndex' => 'IdxShared=']]);
        $holders  = $this->storage->findUserIdsByIndex('phone', 'IdxShared=');
        $expected = [$alice->userId, $bob->userId];
        sort($holders);
        sort($expected);
        $this->assert($holders === $expected, 'a shared index does not list every account that holds the value');
        return null;
    }

    private function escrowRoundTrip(): ?string
    {
        $vault = $this->newVault('escrow');
        $this->storage->saveVault($vault);
        $this->assert($this->storage->loadEscrow($vault->userId) === null, 'loadEscrow() returned an escrow before any was saved');
        $this->expect(
            EscrowNotFoundException::class,
            fn () => $this->storage->replaceEscrow($vault->generation(), $this->escrow($vault->userId)),
            'replaceEscrow() of an account without escrow'
        );
        $this->assert($this->storage->loadEscrow($vault->userId) === null, 'a refused replaceEscrow() created an escrow');
        $escrow = $this->escrow($vault->userId);
        $this->storage->createEscrow($vault->generation(), $escrow);
        $read = $this->storage->loadEscrow($vault->userId);
        $this->assert(
            $read !== null && $read->userId === $vault->userId
                && $read->wrapUser->toBase64() === $escrow->wrapUser->toBase64()
                && $read->wrapAdmin === $escrow->wrapAdmin,
            'loadEscrow() did not return the escrow as written'
        );
        $second = new EscrowRecord($vault->userId, $this->blob(), 'sealed-to-admin:second', $escrow->createdAt, $escrow->updatedAt, 0, null);
        $this->expect(EscrowExistsException::class, fn () => $this->storage->createEscrow($vault->generation(), $second), 'a second createEscrow() of the same userId');
        $this->assert(
            $this->storage->loadEscrow($vault->userId)?->wrapAdmin === $escrow->wrapAdmin,
            'a second createEscrow() replaced the first escrow: the fields sealed under its key no longer open'
        );
        $rebound = new EscrowRecord($vault->userId, $escrow->wrapUser, 'sealed-to-admin:rebound', $escrow->createdAt, new DateTimeImmutable(), 0, null);
        $this->storage->replaceEscrow($vault->generation(), $rebound);
        $this->assert(
            $this->storage->loadEscrow($vault->userId)?->wrapAdmin === 'sealed-to-admin:rebound',
            'replaceEscrow() did not replace the envelope'
        );
        $this->storage->saveEscrowFields($vault->generation(), ['contact' => 'c-1', 'hint' => 'c-2']);
        $this->assert($this->storage->loadEscrowFields($vault->userId, ['hint']) === ['hint' => 'c-2'], 'loadEscrowFields() with a name did not return exactly that field');
        $this->storage->saveEscrowFields($vault->generation(), ['contact' => 'c-3']);
        $all = $this->storage->loadEscrowFields($vault->userId);
        ksort($all);
        $this->assert($all === ['contact' => 'c-3', 'hint' => 'c-2'], 'saving an escrow field again did not replace it');
        return null;
    }

    /**
     * The adminKey label an escrow record carries comes back as written — by
     * a creation, a replacement, a rotation (null as null), in the archive a
     * re-enrolment sets aside, and after a replacement of that archive's
     * escrow. The ceremony compares it before asking the keeper: a label lost
     * or kept from an older record sends a release to a key that cannot open it.
     */
    private function escrowAdminLabel(): ?string
    {
        $vault = $this->newVault('escrow-label');
        $this->storage->saveVault($vault);
        $first  = str_repeat('a1', 16);
        $second = str_repeat('b2', 16);
        $now    = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $this->storage->createEscrow($vault->generation(), new EscrowRecord($vault->userId, $this->blob(), 'sealed-to-admin:label', $now, $now, 0, $first));
        $read = $this->storage->loadEscrow($vault->userId) ?? throw new RuntimeException('loadEscrow() lost the escrow it was given');
        $this->assert($read->adminKey === $first, 'loadEscrow() did not give back the adminKey label createEscrow() wrote');
        $this->storage->replaceEscrow($vault->generation(), new EscrowRecord($vault->userId, $read->wrapUser, 'sealed-to-admin:label-2', $read->createdAt, new DateTimeImmutable(), $read->revision, $second));
        $read = $this->storage->loadEscrow($vault->userId) ?? throw new RuntimeException('loadEscrow() lost the escrow');
        $this->assert($read->adminKey === $second, 'replaceEscrow() did not write the adminKey label of its record');
        $this->storage->rotateEscrow($vault->generation(), new EscrowRecord($vault->userId, $this->blob(), 'sealed-to-admin:label-3', $read->createdAt, new DateTimeImmutable(), $read->revision, null), ['c' => 'c-1']);
        $read = $this->storage->loadEscrow($vault->userId) ?? throw new RuntimeException('loadEscrow() lost the escrow');
        $this->assert($read->adminKey === null, 'rotateEscrow() kept a label its record does not carry: null is written as null');
        $this->storage->replaceEscrow($vault->generation(), new EscrowRecord($vault->userId, $read->wrapUser, 'sealed-to-admin:label-4', $read->createdAt, new DateTimeImmutable(), $read->revision, $first));
        $archiveId = $this->storage->replaceWithArchive($vault->generation(), $this->newVault('escrow-label'));
        $kept = $this->storage->loadArchive($vault->userId, $archiveId)?->escrow ?? throw new RuntimeException('the archive lost its escrow');
        $this->assert($kept->adminKey === $first, 'the archive a re-enrolment set aside lost the adminKey label of its escrow');
        $this->storage->replaceArchiveEscrow($vault->userId, $archiveId, new EscrowRecord($vault->userId, $kept->wrapUser, 'sealed-to-admin:archived', $kept->createdAt, new DateTimeImmutable(), $kept->revision, $second));
        $this->assert(
            $this->storage->loadArchive($vault->userId, $archiveId)?->escrow?->adminKey === $second,
            'replaceArchiveEscrow() did not write the adminKey label of its record'
        );
        return null;
    }

    private function unreadableEscrowNamed(): ?string
    {
        if ($this->unparseEscrow === null) {
            return 'no rewrite of the escrow row given';
        }
        foreach (self::UNPARSED_COLUMNS as $column) {
            $vault = $this->newVault("escrow-unreadable-{$column}");
            $this->storage->saveVault($vault);
            $this->storage->createEscrow($vault->generation(), $this->escrow($vault->userId));
            $before = $this->storage->loadEscrow($vault->userId)
                ?? throw new RuntimeException('loadEscrow() lost the escrow it was given');
            $done = ($this->unparseEscrow)($vault->userId, $column);
            if ($done === false) {
                if (!in_array($column, self::DECLINABLE_COLUMNS, true)) {
                    throw new RuntimeException(
                        "the rewrite declined {$column}, which any storage can make unreadable: only a date column may be declined"
                    );
                }
                continue;
            }
            if ($done !== true) {
                throw new RuntimeException(
                    'the rewrite of the escrow row returned ' . get_debug_type($done) . ', not true or false: it must '
                    . 'rewrite the column it is given, and say so'
                );
            }
            try {
                $read = $this->storage->loadEscrow($vault->userId);
            } catch (UnreadableEscrowException) {
                $read = false;
            } catch (Throwable $e) {
                throw new RuntimeException(
                    "loadEscrow() of a row whose {$column} does not parse raised " . $this->short($e::class)
                    . ', not UnreadableEscrowException: the library takes it for an outage, and the holder loses the '
                    . 'view and the objection'
                );
            }
            if ($read !== false) {
                throw new RuntimeException($read === null
                    ? "the rewrite of {$column} deleted the escrow row instead of making it unreadable"
                    : "loadEscrow() read the row whose {$column} was rewritten as a record");
            }
            if ($column === 'revision') {
                $this->expect(
                    StaleEscrowException::class,
                    fn () => $this->storage->replaceEscrow($vault->generation(), $before),
                    'replaceEscrow() over a row whose revision was rewritten (it must test the row is there without parsing it)'
                );
                $this->expect(
                    StaleEscrowException::class,
                    fn () => $this->storage->rotateEscrow($vault->generation(), $before, []),
                    'rotateEscrow() over a row whose revision was rewritten (it must test the row is there without parsing it)'
                );
            }
        }
        return null;
    }

    private function escrowCompareAndSwap(): ?string
    {
        $vault = $this->newVault('escrow-cas');
        $this->storage->saveVault($vault);
        $this->storage->createEscrow($vault->generation(), $this->escrow($vault->userId));
        $read = $this->storage->loadEscrow($vault->userId) ?? throw new RuntimeException('loadEscrow() lost the escrow it was given');
        $this->assert($read->revision === 0, "a new escrow is read at revision {$read->revision}, not 0");

        $this->storage->replaceEscrow($vault->generation(), $this->escrowLike($read, 'sealed-to-admin:first'));
        $stored = $this->storage->loadEscrow($vault->userId);
        $this->assert(
            $stored?->revision === 1 && $stored->wrapAdmin === 'sealed-to-admin:first',
            'replaceEscrow() did not store the envelope at revision 1'
        );
        $this->expect(
            StaleEscrowException::class,
            fn () => $this->storage->replaceEscrow($vault->generation(), $this->escrowLike($read, 'sealed-to-admin:lost')),
            'a replacement from a record read before the last one'
        );
        $this->assert(
            $this->storage->loadEscrow($vault->userId)?->wrapAdmin === 'sealed-to-admin:first',
            'a refused replacement still replaced the envelope: the revision is not compared'
        );
        return null;
    }

    private function rotateEscrowCase(): ?string
    {
        $vault = $this->newVault('rotate');
        $this->storage->saveVault($vault);
        $this->expect(
            EscrowNotFoundException::class,
            fn () => $this->storage->rotateEscrow($vault->generation(), $this->escrow($vault->userId), ['a' => 'c-a']),
            'rotateEscrow() of an account without escrow'
        );
        $this->assert($this->storage->loadEscrowFields($vault->userId) === [], 'a refused rotateEscrow() wrote escrow fields');

        $this->storage->createEscrow($vault->generation(), $this->escrow($vault->userId));
        $this->storage->saveEscrowFields($vault->generation(), ['kept' => 'c-old', 'dropped' => 'c-old-2']);
        $read = $this->storage->loadEscrow($vault->userId) ?? throw new RuntimeException('loadEscrow() lost the escrow it was given');
        $rotated = new EscrowRecord($vault->userId, $this->blob(), 'sealed-to-admin:rotated', $read->createdAt, new DateTimeImmutable(), $read->revision, null);
        $this->storage->rotateEscrow($vault->generation(), $rotated, ['kept' => 'c-new', 'added' => 'c-added']);

        $stored = $this->storage->loadEscrow($vault->userId);
        $this->assert(
            $stored?->revision === 1 && $stored->wrapAdmin === 'sealed-to-admin:rotated'
                && $stored->wrapUser->toBase64() === $rotated->wrapUser->toBase64(),
            'rotateEscrow() did not store the new envelope at revision 1'
        );
        $fields = $this->storage->loadEscrowFields($vault->userId);
        ksort($fields);
        $this->assert(
            $fields === ['added' => 'c-added', 'kept' => 'c-new'],
            'after rotateEscrow(), the escrow fields are not exactly the ones given: one it does not name was kept under the replaced key, or one was lost'
        );

        $stale = new EscrowRecord($vault->userId, $this->blob(), 'sealed-to-admin:stale', $read->createdAt, new DateTimeImmutable(), $read->revision, null);
        $this->expect(
            StaleEscrowException::class,
            fn () => $this->storage->rotateEscrow($vault->generation(), $stale, ['stale' => 'c-stale']),
            'a rotation from a record read before the last one'
        );
        $after = $this->storage->loadEscrowFields($vault->userId);
        ksort($after);
        $this->assert(
            $after === $fields && $this->storage->loadEscrow($vault->userId)?->wrapAdmin === 'sealed-to-admin:rotated',
            'a refused rotation still changed the escrow: its fields were written before the revision was compared, or outside its transaction'
        );
        return null;
    }

    private function deleteVault(): ?string
    {
        $vault = $this->newVault('del');
        $this->storage->saveVault($vault);
        $live = $this->newVault('del');
        $this->storage->replaceWithArchive($vault->generation(), $live);
        $this->fill($live);
        $other = $this->newVault('del-other');
        $this->storage->saveVault($other);
        $this->fill($other);

        $this->storage->deleteVault($live->generation());
        $this->assert(!$this->storage->vaultExists($live->userId), 'deleteVault() left the vault');
        $this->assert(
            $this->storage->loadFields($live->userId) === []
                && $this->storage->loadEscrow($live->userId) === null
                && $this->storage->loadEscrowFields($live->userId) === [],
            'deleteVault() left fields or an escrow behind'
        );
        $this->assert(count($this->storage->listArchives($live->userId)) === 1, 'deleteVault() touched the archives: removing them is eraseAccount()');
        $this->assert(
            $this->storage->vaultExists($other->userId) && $this->storage->loadFields($other->userId) !== [],
            'deleteVault() reached another account'
        );
        $this->expect(VaultNotFoundException::class, fn () => $this->storage->deleteVault($live->generation()), 'deleteVault() of a vault already deleted');
        return null;
    }

    private function replaceWithArchive(): ?string
    {
        $old = $this->newVault('arch', withRecov: true, withPhrase: true);
        $this->storage->saveVault($old);
        $this->fill($old);
        $new = $this->newVault('arch');

        $id = $this->storage->replaceWithArchive($old->generation(), $new);
        $this->assert($id !== '', 'replaceWithArchive() returned an empty archive id');
        $live = $this->storage->loadVault($old->userId);
        $this->assertSameVault($new, $live, 'the live vault after replaceWithArchive()');
        $this->assert($live->revision === 0, 'the new vault is not at revision 0');
        $this->assert(
            $this->storage->loadFields($old->userId) === []
                && $this->storage->loadEscrow($old->userId) === null
                && $this->storage->loadEscrowFields($old->userId) === [],
            'the old fields or escrow are still live after replaceWithArchive()'
        );

        $list = $this->storage->listArchives($old->userId);
        $locks = array_map(static fn (Lock $l): string => $l->value, $list[0]['locks'] ?? []);
        sort($locks);
        $expected = [Lock::Memorized->value, Lock::Passphrase->value, Lock::Password->value];
        sort($expected);
        $this->assert(
            count($list) === 1 && $list[0]['id'] === $id && $locks === $expected,
            'listArchives() does not list the archive with the three locks that still open it'
        );
        $archive = $this->storage->loadArchive($old->userId, $id);
        $this->assert($archive !== null, 'loadArchive() did not find the archive');
        $this->assertSameVault($old, $archive->record, 'the archived vault', ignoreRevision: true);
        $this->assert(
            $archive->kdfOpslimit === self::OPSLIMIT && $archive->kdfMemlimit === self::MEMLIMIT,
            'the archive does not carry the Argon2id profile of the vault it was taken from'
        );
        $private = $archive->privateFields;
        ksort($private);
        $this->assert(
            $private === [
                'email' => ['ciphertext' => 'c-email', 'wasIndexed' => true],
                'note'  => ['ciphertext' => 'c-note', 'wasIndexed' => false],
            ],
            'the archive did not keep the fields, or which of them were indexed'
        );
        $this->assert(
            $archive->escrow?->wrapAdmin === $this->escrow($old->userId)->wrapAdmin
                && $archive->escrowFields === ['contact' => 'c-contact'],
            'the archive did not keep the escrow and its fields'
        );
        $this->assert($this->storage->findUserIdsByIndex('email', $this->indexOf($old->userId)) === [], 'the archived vault still answers index lookups');
        $this->assert($this->storage->loadArchive($this->id('arch-other'), $id) === null, 'loadArchive() returned the archive under another userId');

        $this->expect(
            StaleVaultException::class,
            fn () => $this->storage->replaceWithArchive($old->generation(), $this->newVault('arch')),
            'a second replaceWithArchive() naming the generation already replaced'
        );
        $this->assert(
            count($this->storage->listArchives($old->userId)) === 1
                && hash_equals($new->userSalt, $this->storage->loadVault($old->userId)->userSalt),
            'a second re-enrolment naming the same generation archived the vault the first one made'
        );
        return null;
    }

    private function deleteArchive(): ?string
    {
        $vault = $this->newVault('darch');
        $this->storage->saveVault($vault);
        $live = $this->newVault('darch');
        $id = $this->storage->replaceWithArchive($vault->generation(), $live);
        $other = $this->newVault('darch-other');
        $this->storage->saveVault($other);

        $this->assert(!$this->storage->deleteArchive($other->generation(), $id), 'deleteArchive() under another account deleted the archive');
        $this->assert($this->storage->loadArchive($live->userId, $id) !== null, 'the archive is gone after a refused deleteArchive()');
        $this->assert($this->storage->deleteArchive($live->generation(), $id), 'deleteArchive() did not delete the archive');
        $this->assert($this->storage->loadArchive($live->userId, $id) === null, 'deleteArchive() returned true and left the archive');
        $this->assert(!$this->storage->deleteArchive($live->generation(), $id), 'deleteArchive() of an archive already deleted returned true');
        return null;
    }

    private function eraseAccount(): ?string
    {
        $vault = $this->newVault('erase');
        $this->storage->saveVault($vault);
        $second = $this->newVault('erase');
        $this->storage->replaceWithArchive($vault->generation(), $second);
        $third = $this->newVault('erase');
        $this->storage->replaceWithArchive($second->generation(), $third);
        $this->fill($third);
        $orphan = $this->newVault('erase-orphan');
        $this->storage->saveVault($orphan);
        $this->storage->replaceWithArchive($orphan->generation(), $orphanLive = $this->newVault('erase-orphan'));
        $this->storage->deleteVault($orphanLive->generation());
        $other = $this->newVault('erase-other');
        $this->storage->saveVault($other);

        $this->assert($this->storage->eraseAccount($vault->userId) === 3, 'eraseAccount() did not count the live vault and its two archives');
        $this->assert(
            !$this->storage->vaultExists($vault->userId)
                && $this->storage->listArchives($vault->userId) === []
                && $this->storage->loadFields($vault->userId) === []
                && $this->storage->loadEscrow($vault->userId) === null,
            'eraseAccount() left part of the account behind'
        );
        $this->assert($this->storage->eraseAccount($orphan->userId) === 1, 'eraseAccount() of a userId left with archives only did not erase them');
        $this->assert($this->storage->listArchives($orphan->userId) === [], 'eraseAccount() left archives behind');
        $this->assert($this->storage->eraseAccount($this->id('erase-never')) === 0, 'eraseAccount() of an unknown userId did not return 0');
        $this->assert($this->storage->vaultExists($other->userId), 'eraseAccount() reached another account');
        return null;
    }

    /**
     * The archive comes back live as it was archived, indexes included, and
     * the live vault becomes an archive — or, on any refusal, nothing moves.
     * The index an archive keeps is archive-reserves-its-indexes'.
     */
    private function swapWithArchive(): ?string
    {
        $holder = $this->newVault('swap', withRecov: true, withPhrase: true);
        $this->storage->saveVault($holder);
        $this->fill($holder);
        $requester = $this->newVault('swap');
        $id = $this->storage->replaceWithArchive($holder->generation(), $requester);
        $this->storage->saveFields($requester->generation(), ['note' => ['ciphertext' => 'c-note-requester']]);

        foreach ([
            'the generation of a vault that is not live' => [$holder->generation(), $id, StaleVaultException::class],
            'an archive id that does not exist'          => [$requester->generation(), bin2hex(random_bytes(16)), VaultNotFoundException::class],
        ] as $what => [$generation, $archiveId, $expected]) {
            $this->expect($expected, fn () => $this->storage->swapWithArchive($generation, $archiveId), "swapWithArchive() naming {$what}");
        }

        $setAside = $this->storage->swapWithArchive($requester->generation(), $id);
        $this->assertSameVault($holder, $this->storage->loadVault($holder->userId), 'the live vault after swapWithArchive()');
        $fields = $this->storage->loadFields($holder->userId);
        ksort($fields);
        $this->assert($fields === ['email' => 'c-email', 'note' => 'c-note'], 'swapWithArchive() did not bring the archived fields back live');
        $this->assert(
            $this->storage->findUserIdsByIndex('email', $this->indexOf($holder->userId)) === [$holder->userId],
            'swapWithArchive() brought the fields back without their blind index'
        );
        $this->assert(
            $this->storage->loadEscrow($holder->userId)?->wrapAdmin === $this->escrow($holder->userId)->wrapAdmin
                && $this->storage->loadEscrowFields($holder->userId) === ['contact' => 'c-contact'],
            'swapWithArchive() did not bring the escrow and its fields back live'
        );
        $this->assert(
            array_column($this->storage->listArchives($holder->userId), 'id') === [$setAside] && $setAside !== $id,
            'after swapWithArchive(), the archive brought back is still listed, or the replaced vault is not'
        );
        $archived = $this->storage->loadArchive($holder->userId, $setAside);
        $this->assert(
            $archived !== null && hash_equals($requester->userSalt, $archived->record->userSalt)
                && ($archived->privateFields['note']['ciphertext'] ?? null) === 'c-note-requester',
            'the vault swapWithArchive() replaced was not archived as it stood'
        );
        return null;
    }

    private function restoreArchive(): ?string
    {
        $old = $this->newVault('restore', withRecov: true, withPhrase: true);
        $this->storage->saveVault($old);
        $this->fill($old);
        $new = $this->newVault('restore');
        $id = $this->storage->replaceWithArchive($old->generation(), $new);

        $this->expect(VaultExistsException::class, fn () => $this->storage->restoreArchive($old->userId, $id), 'restoreArchive() over a live vault');
        $this->assert(
            hash_equals($new->userSalt, $this->storage->loadVault($old->userId)->userSalt)
                && $this->storage->loadArchive($old->userId, $id) !== null,
            'a refused restoreArchive() changed the live vault or the archive'
        );
        $this->storage->deleteVault($new->generation());
        $this->expect(VaultNotFoundException::class, fn () => $this->storage->restoreArchive($this->id('restore-other'), $id), 'restoreArchive() of an archive under another userId');
        $this->storage->restoreArchive($old->userId, $id);
        $this->assertSameVault($old, $this->storage->loadVault($old->userId), 'the live vault after restoreArchive()');
        $this->assert(
            $this->storage->listArchives($old->userId) === []
                && $this->storage->findUserIdsByIndex('email', $this->indexOf($old->userId)) === [$old->userId]
                && $this->storage->loadEscrowFields($old->userId) === ['contact' => 'c-contact'],
            'restoreArchive() did not bring the archive back live, indexes and escrow included, or left it listed'
        );
        return null;
    }

    /**
     * An archive keeps the blind indexes of its fields, hands them out
     * (loadArchive()), and, made a moment ago, holds each value against every
     * other userId: saveFields() refuses it there as it refuses a value held
     * live, and writes nothing of the batch. The userId the archive belongs
     * to indexes the value again; once the archive is gone, any account may.
     * The end of the reservation — RecoveryTickets::RETURN_WINDOW, or the
     * freeze lifted — takes time the contract cannot make pass, and the field
     * that comes back without the index another account took meanwhile
     * cannot be made through it: SqliteAdapter's bench plays both.
     */
    private function archiveReservesItsIndexes(): ?string
    {
        $holder = $this->newVault('reserve', withRecov: true, withPhrase: true);
        $this->storage->saveVault($holder);
        $this->fill($holder);
        $this->storage->saveFields($holder->generation(), ['phone' => ['ciphertext' => 'c-phone', 'sharedIndex' => 'IdxShared=']]);
        $requester = $this->newVault('reserve');
        $id = $this->storage->replaceWithArchive($holder->generation(), $requester);
        $index = $this->indexOf($holder->userId);
        $archive = $this->storage->loadArchive($holder->userId, $id);
        $this->assert(
            $archive?->blindIndexes === ['email' => $index] && $archive->sharedIndexes === ['phone' => 'IdxShared='],
            'loadArchive() does not hand out the index each field of the archive held: the façade cannot name a field that came back without it'
        );

        $taker = $this->newVault('reserve-taker');
        $this->storage->saveVault($taker);
        try {
            $this->storage->saveFields($taker->generation(), [
                'note'  => ['ciphertext' => 'c-taker-note'],
                'email' => ['ciphertext' => 'c-taker', 'blindIndex' => $index],
            ]);
            throw new RuntimeException('saveFields() gave another account the blind index an archive keeps: the holder would come back without it');
        } catch (DuplicateIndexException $e) {
            $this->assert($e->fields === ['email'], 'the DuplicateIndexException over an index an archive keeps does not name exactly that field');
        }
        $this->assert(
            $this->storage->loadFields($taker->userId) === [] && $this->storage->findUserIdsByIndex('email', $index) === [],
            'a batch refused over an index an archive keeps wrote something'
        );
        $this->storage->saveFields($requester->generation(), ['email' => ['ciphertext' => 'c-requester', 'blindIndex' => $index]]);
        $this->assert(
            $this->storage->findUserIdsByIndex('email', $index) === [$holder->userId],
            'the userId an archive belongs to could not index the value its archive keeps'
        );

        $this->storage->deleteArchive($requester->generation(), $id);
        $this->storage->saveFields($requester->generation(), ['email' => ['ciphertext' => 'c-requester-2']]);
        $this->storage->saveFields($taker->generation(), ['email' => ['ciphertext' => 'c-taker', 'blindIndex' => $index]]);
        $this->assert(
            $this->storage->findUserIdsByIndex('email', $index) === [$taker->userId],
            'once the archive is gone, its index stays held: no other account can take the value'
        );
        return null;
    }

    /**
     * An archive whose escrow row does not parse is read all the same, its
     * escrow named unreadable, and comes back live with that row as it was
     * kept — the archive is the holder's vault; its escrow is what is lost.
     */
    private function archiveEscrowUnreadable(): ?string
    {
        if ($this->unparseEscrow === null) {
            return 'no rewrite of the escrow row given';
        }
        $control = $this->newVault('archive-escrow-parses');
        $this->storage->saveVault($control);
        $this->fill($control);
        $controlId = $this->storage->replaceWithArchive($control->generation(), $this->newVault('archive-escrow-parses'));
        $readable = $this->storage->loadArchive($control->userId, $controlId);
        $this->assert(
            $readable?->escrow !== null && $readable->escrowUnreadable === false,
            'loadArchive() names unreadable an escrow that parses'
        );
        foreach (self::UNPARSED_COLUMNS as $i => $column) {
            $holder = $this->newVault("archive-escrow-unreadable-{$column}");
            $this->storage->saveVault($holder);
            $this->fill($holder);
            $done = ($this->unparseEscrow)($holder->userId, $column);
            if ($done === false && in_array($column, self::DECLINABLE_COLUMNS, true)) {
                continue;
            }
            $this->assert($done === true, "the rewrite of the escrow row did not rewrite {$column}: see escrow-unreadable-row-named");
            $requester = $this->newVault("archive-escrow-unreadable-{$column}");
            $id = $this->storage->replaceWithArchive($holder->generation(), $requester);
            try {
                $archive = $this->storage->loadArchive($holder->userId, $id);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    "loadArchive() of an archive whose escrow {$column} does not parse raised " . $this->short($e::class)
                    . ': the archive must read, its escrow null and escrowUnreadable true'
                );
            }
            $this->assert($archive !== null, "loadArchive() lost the archive whose escrow {$column} does not parse");
            $this->assert(
                $archive->escrow === null && $archive->escrowUnreadable === true,
                $archive->escrow === null
                    ? "loadArchive() reads an archive whose escrow {$column} does not parse as an archive without escrow"
                    : "loadArchive() read the escrow whose {$column} was rewritten as a record"
            );
            $this->assertSameVault($holder, $archive->record, "the archive whose escrow {$column} does not parse", ignoreRevision: true);
            $this->assert(
                $archive->escrowFields === ['contact' => 'c-contact'] && count($archive->privateFields) === 2,
                "the archive whose escrow {$column} does not parse lost its fields or its escrow fields"
            );
            if ($i % 2 === 0) {
                $this->storage->swapWithArchive($requester->generation(), $id);
                $how = 'swapWithArchive()';
            } else {
                $this->storage->deleteVault($requester->generation());
                $this->storage->restoreArchive($holder->userId, $id);
                $how = 'restoreArchive()';
            }
            $this->assertSameVault($holder, $this->storage->loadVault($holder->userId), "the live vault after {$how} of an archive whose escrow does not parse");
            try {
                $back = $this->storage->loadEscrow($holder->userId);
            } catch (UnreadableEscrowException) {
                $back = false;
            }
            $this->assert(
                $back === false,
                $back === null
                    ? "{$how} dropped the escrow row whose {$column} does not parse: it comes back as it was kept"
                    : "{$how} brought back as a readable row an escrow whose {$column} did not parse"
            );
            $this->assert(
                $this->storage->loadEscrowFields($holder->userId) === ['contact' => 'c-contact'],
                "{$how} did not bring back the escrow fields of an archive whose escrow does not parse"
            );
        }
        return null;
    }

    /**
     * eraseAccount() erases the live vault, and keeps every archive of the
     * userId once a replaceWithArchive() applied a ticket to it — the archive a
     * swap set aside, and one set aside without a ticket, included. Without
     * one, every archive goes (erase-account); under a freeze, nothing does
     * (freeze-refuses-writes).
     */
    private function eraseKeepsRecoveryArchives(): ?string
    {
        $holder = $this->newVault('erase-keep');
        $this->storage->saveVault($holder);
        $this->fill($holder);
        $requester = $this->newVault('erase-keep');
        $byTicket = $this->storage->replaceWithArchive($holder->generation(), $requester, "{$this->prefix}-erase-keep-l3");
        $swapped = $this->storage->swapWithArchive($requester->generation(), $byTicket, "{$this->prefix}-erase-keep-return");
        $third = $this->newVault('erase-keep');
        $without = $this->storage->replaceWithArchive($holder->generation(), $third);
        $erased = $this->storage->eraseAccount($holder->userId);
        $this->assert($erased === 1, "eraseAccount() of an account a level-3 ticket was applied to erased {$erased} vaults, not the live one alone");
        $this->assert(!$this->storage->vaultExists($holder->userId), 'eraseAccount() left the live vault');
        $kept = array_column($this->storage->listArchives($holder->userId), 'id');
        sort($kept);
        $expected = [$swapped, $without];
        sort($expected);
        $this->assert(
            $kept === $expected,
            'eraseAccount() of an account a level-3 ticket was applied to erased an archive — the one a swap set aside, or one set aside without a ticket: each may be the holder\'s vault'
        );
        $archive = $this->storage->loadArchive($holder->userId, $without);
        $this->assert(
            $archive !== null && hash_equals($holder->userSalt, $archive->record->userSalt) && $archive->escrowFields === ['contact' => 'c-contact'],
            'an archive eraseAccount() kept is not the vault as it was archived'
        );
        return null;
    }

    /**
     * eraseAccount() deletes no recovery ticket: replayed after the erasure,
     * each one is refused. Once the userId holds no live vault and no archive,
     * the tickets no longer name it, nor an archive: archiveOfTicket() answers
     * null, and whoever registers the userId next does not inherit them — its
     * archives go through eraseAccount() as any. A storage that implements
     * ErasesArchives erases a kept archive under its userId only, leaves the
     * tickets, and lists the archives whose userId holds no live vault.
     */
    private function eraseKeepsTickets(): ?string
    {
        $l3 = "{$this->prefix}-erase-tickets-l3";
        $return = "{$this->prefix}-erase-tickets-return";
        $close = "{$this->prefix}-erase-tickets-close";
        $holder = $this->newVault('erase-tickets');
        $this->storage->saveVault($holder);
        $requester = $this->newVault('erase-tickets');
        $kept = $this->storage->replaceWithArchive($holder->generation(), $requester, $l3);
        $this->storage->freezeVault($requester->generation(), 'arbiter', new DateTimeImmutable(), $return);
        $this->storage->unfreezeVault($requester->generation(), $close);
        $this->storage->eraseAccount($holder->userId);
        $this->assert($this->storage->archiveOfTicket($l3) === $kept, 'eraseAccount() erased the level-3 ticket of the archive it kept, or no longer names the archive');

        $again = $this->vaultFor($holder->userId);
        $this->storage->saveVault($again);
        foreach ([
            'replaceWithArchive()' => fn () => $this->storage->replaceWithArchive($again->generation(), $this->vaultFor($holder->userId), $l3),
            'freezeVault()'        => fn () => $this->storage->freezeVault($again->generation(), 'arbiter', new DateTimeImmutable(), $return),
            'unfreezeVault()'      => fn () => $this->storage->unfreezeVault($again->generation(), $close),
        ] as $write => $replay) {
            $this->expect(TicketAlreadyAppliedException::class, $replay, "the ticket of {$write}, replayed after eraseAccount() under a vault registered since");
        }
        $this->assert(
            hash_equals($again->userSalt, $this->storage->loadVault($holder->userId)->userSalt)
                && $this->storage->frozenState($holder->userId) === null
                && array_column($this->storage->listArchives($holder->userId), 'id') === [$kept],
            'a ticket replayed after the erasure changed the vault of whoever registered the userId next'
        );
        $this->storage->deleteVault($again->generation());

        if ($this->storage instanceof ErasesArchives) {
            $listed = array_values(array_filter(
                $this->storage->keptArchives(),
                static fn (array $a): bool => $a['userId'] === $holder->userId
            ));
            $this->assert(
                array_column($listed, 'id') === [$kept] && array_column($this->storage->keptArchives($holder->userId), 'id') === [$kept],
                'keptArchives() does not list the archive of a userId left without a live vault'
            );
            $live = $this->newVault('erase-tickets-live');
            $this->storage->saveVault($live);
            $this->storage->replaceWithArchive($live->generation(), $this->newVault('erase-tickets-live'));
            $this->assert($this->storage->keptArchives($live->userId) === [], 'keptArchives() lists the archive of a userId that holds a live vault');
            $this->assert(!$this->storage->eraseArchive($this->id('erase-tickets-other'), $kept), 'eraseArchive() under another userId returned true');
            $this->assert($this->storage->loadArchive($holder->userId, $kept) !== null, 'eraseArchive() under another userId erased the archive');
            $this->assert($this->storage->eraseArchive($holder->userId, $kept), 'eraseArchive() did not erase the archive eraseAccount() kept');
            $this->assert($this->storage->listArchives($holder->userId) === [], 'eraseArchive() returned true and left the archive');
            $this->assert(!$this->storage->eraseArchive($holder->userId, $kept), 'eraseArchive() of an archive already erased returned true');
        } else {
            $this->storage->restoreArchive($holder->userId, $kept);
            $this->storage->eraseAccount($holder->userId);
        }

        $this->assert($this->storage->archiveOfTicket($l3) === null, 'the level-3 ticket still names the archive of an account that holds nothing any more');
        $newcomer = $this->vaultFor($holder->userId);
        $this->storage->saveVault($newcomer);
        $this->expect(
            TicketAlreadyAppliedException::class,
            fn () => $this->storage->replaceWithArchive($newcomer->generation(), $this->vaultFor($holder->userId), $l3),
            'the level-3 ticket replayed once the account holds nothing, under a vault registered since'
        );
        $this->storage->replaceWithArchive($newcomer->generation(), $next = $this->vaultFor($holder->userId));
        $this->assert(
            $this->storage->eraseAccount($holder->userId) === 2 && $this->storage->listArchives($holder->userId) === [],
            'the newcomer under the userId inherited the tickets of the account erased before: eraseAccount() kept its archive'
        );
        return null;
    }

    private function replaceArchiveEscrowCase(): ?string
    {
        $vault = $this->newVault('archive-escrow');
        $this->storage->saveVault($vault);
        $this->fill($vault);
        $read = $this->storage->loadEscrow($vault->userId) ?? throw new RuntimeException('loadEscrow() lost the escrow it was given');
        $this->storage->replaceEscrow($vault->generation(), $this->escrowLike($read, 'sealed-to-admin:live-1'));
        $live = $this->newVault('archive-escrow');
        $archiveId = $this->storage->replaceWithArchive($vault->generation(), $live);

        $archived = $this->storage->loadArchive($vault->userId, $archiveId);
        $this->assert(
            $archived?->escrow?->revision === 1 && $archived->escrow->wrapAdmin === 'sealed-to-admin:live-1',
            'the archive did not keep the revision of its escrow: a column of the escrow row was lost on the way into the archive'
        );
        $this->storage->replaceArchiveEscrow($vault->userId, $archiveId, $this->escrowLike($archived->escrow, 'sealed-to-admin:resealed'));
        $after = $this->storage->loadArchive($vault->userId, $archiveId);
        $this->assert(
            $after?->escrow?->wrapAdmin === 'sealed-to-admin:resealed' && $after->escrow->revision === 2,
            'replaceArchiveEscrow() did not store the envelope at the next revision'
        );
        $this->assert(
            $after->escrowFields === $archived->escrowFields && $after->privateFields === $archived->privateFields
                && $after->record->wrapPwd->toBase64() === $archived->record->wrapPwd->toBase64()
                && $after->escrow->wrapUser->toBase64() === $archived->escrow->wrapUser->toBase64(),
            'replaceArchiveEscrow() changed more of the archive than its escrow envelope'
        );
        $this->expect(
            StaleEscrowException::class,
            fn () => $this->storage->replaceArchiveEscrow($vault->userId, $archiveId, $this->escrowLike($archived->escrow, 'sealed-to-admin:lost')),
            'a re-sealing of an archive from a record read before the last one'
        );
        $this->assert(
            $this->storage->loadArchive($vault->userId, $archiveId)?->escrow?->wrapAdmin === 'sealed-to-admin:resealed',
            'a refused re-sealing of an archive still replaced its envelope: the revision is not compared'
        );
        $this->expect(
            VaultNotFoundException::class,
            fn () => $this->storage->replaceArchiveEscrow($vault->userId, 'no-such-archive', $this->escrowLike($archived->escrow, 'x')),
            'replaceArchiveEscrow() of an archive that does not exist'
        );
        $stranger = $this->id('archive-escrow-stranger');
        $this->expect(
            VaultNotFoundException::class,
            fn () => $this->storage->replaceArchiveEscrow($stranger, $archiveId, $this->escrow($stranger)),
            "replaceArchiveEscrow() of another account's archive"
        );
        $bare = $this->newVault('archive-bare');
        $this->storage->saveVault($bare);
        $bareId = $this->storage->replaceWithArchive($bare->generation(), $this->newVault('archive-bare'));
        $this->expect(
            EscrowNotFoundException::class,
            fn () => $this->storage->replaceArchiveEscrow($bare->userId, $bareId, $this->escrow($bare->userId)),
            'replaceArchiveEscrow() of an archive that holds no escrow'
        );

        $this->storage->swapWithArchive($live->generation(), $archiveId);
        $back = $this->storage->loadEscrow($vault->userId);
        $this->assert(
            $back?->revision === 2 && $back->wrapAdmin === 'sealed-to-admin:resealed',
            'an archive brought back live lost the revision or the envelope of its escrow'
        );
        return null;
    }

    /**
     * An archived vault takes new lock envelopes, at the next revision, and
     * nothing else of the archive changes; brought back live, it carries them.
     */
    private function replaceArchiveVaultCase(): ?string
    {
        $vault = $this->newVault('archive-vault');
        $this->storage->saveVault($vault);
        $this->fill($vault);
        $live = $this->newVault('archive-vault');
        $archiveId = $this->storage->replaceWithArchive($vault->generation(), $live);
        $archived = $this->storage->loadArchive($vault->userId, $archiveId)
            ?? throw new RuntimeException('loadArchive() lost the archive replaceWithArchive() made');

        $relaid = $this->relaid($archived->record);
        $revision = $this->storage->replaceArchiveVault($vault->userId, $archiveId, $relaid);
        $after = $this->storage->loadArchive($vault->userId, $archiveId);
        $this->assert(
            $revision === $archived->record->revision + 1 && $after?->record->revision === $revision,
            'replaceArchiveVault() did not store the archived vault at the next revision'
        );
        $this->assertSameVault($relaid, $after->record, 'the archived vault after replaceArchiveVault()', ignoreRevision: true);
        $this->assert(
            $after->privateFields === $archived->privateFields && $after->escrowFields === $archived->escrowFields
                && $after->escrow?->wrapAdmin === $archived->escrow?->wrapAdmin
                && $after->escrow?->revision === $archived->escrow?->revision,
            'replaceArchiveVault() changed more of the archive than its vault envelopes'
        );
        $this->expect(
            StaleVaultException::class,
            fn () => $this->storage->replaceArchiveVault($vault->userId, $archiveId, $this->relaid($archived->record)),
            'a replacement of an archived vault from a record read before the last one'
        );
        $this->expect(
            StaleVaultException::class,
            fn () => $this->storage->replaceArchiveVault(
                $vault->userId,
                $archiveId,
                $this->withSalt($this->relaid($after->record), Primitives::randomBytes(Primitives::SALT_LEN))
            ),
            'a replacement of an archived vault by the record of another vault'
        );
        $this->assertSameVault($relaid, $this->storage->loadArchive($vault->userId, $archiveId)?->record
            ?? throw new RuntimeException('the archive is gone after a refused replacement'), 'the archived vault after two refused replacements', ignoreRevision: true);
        $this->expect(
            VaultNotFoundException::class,
            fn () => $this->storage->replaceArchiveVault($vault->userId, 'no-such-archive', $this->relaid($after->record)),
            'replaceArchiveVault() of an archive that does not exist'
        );
        $stranger = $this->id('archive-vault-stranger');
        $this->expect(
            VaultNotFoundException::class,
            fn () => $this->storage->replaceArchiveVault($stranger, $archiveId, $this->vaultFor($stranger)),
            "replaceArchiveVault() of another account's archive"
        );

        $this->storage->swapWithArchive($live->generation(), $archiveId);
        $this->assertSameVault($relaid, $this->storage->loadVault($vault->userId), 'the archive brought back live', ignoreRevision: true);
        return null;
    }

    /**
     * A frozen vault takes one write — its lock envelopes and its seal, none
     * of the rest of the row —, and a vault that is not frozen refuses it.
     */
    private function frozenVaultTakesItsLocks(): ?string
    {
        $vault = $this->newVault('frozen-locks', withPhrase: true);
        $this->storage->saveVault($vault);
        $read = $this->storage->loadVault($vault->userId);
        $this->expect(
            \LogicException::class,
            fn () => $this->storage->updateFrozenLocks($this->relaid($read)),
            'updateFrozenLocks() of a vault that is not frozen'
        );
        $this->assertSameVault($vault, $this->storage->loadVault($vault->userId), 'a vault after a refused updateFrozenLocks()');

        $this->storage->freezeVault($read->generation(), 'conformance', new DateTimeImmutable());
        $relaid = $this->relaid($read);
        $revision = $this->storage->updateFrozenLocks($relaid);
        $after = $this->storage->loadVault($vault->userId);
        $this->assert(
            $revision === $read->revision + 1 && $after->revision === $revision,
            'updateFrozenLocks() did not store the vault at the next revision'
        );
        $this->assertSameVault(
            $relaid->withProfile($read->kdfOpslimit, $read->kdfMemlimit),
            $after,
            'a frozen vault after updateFrozenLocks() — its locks and seal written, its profile kept',
            ignoreRevision: true
        );
        $this->assert($this->storage->frozenState($vault->userId) !== null, 'updateFrozenLocks() lifted the freeze');
        $this->expect(
            StaleVaultException::class,
            fn () => $this->storage->updateFrozenLocks($relaid),
            'updateFrozenLocks() from a record read before the last write'
        );
        $this->expect(
            VaultFrozenException::class,
            fn () => $this->storage->updateVault($after),
            'updateVault() of a frozen vault, once updateFrozenLocks() wrote it'
        );
        $stranger = $this->id('frozen-locks-stranger');
        $this->expect(
            VaultNotFoundException::class,
            fn () => $this->storage->updateFrozenLocks($this->vaultFor($stranger)),
            'updateFrozenLocks() of a userId that holds no vault'
        );
        return null;
    }

    /**
     * A frozen vault reads, and refuses every write of the contract that names
     * it — eraseAccount() of its userId, and eraseArchive() on a storage that
     * implements ErasesArchives, included —, leaving everything as it was.
     */
    private function freezeRefusesWrites(): ?string
    {
        $vault = $this->newVault('freeze');
        $this->storage->saveVault($vault);
        $this->fill($vault);
        $live = $this->newVault('freeze');
        $id = $this->storage->replaceWithArchive($vault->generation(), $live);
        $this->fill($live);
        $g = $live->generation();
        $at = new DateTimeImmutable('2026-03-29T01:30:00+00:00');

        $this->expect(VaultNotFoundException::class, fn () => $this->storage->freezeVault(new VaultGeneration($this->id('freeze-none'), random_bytes(16)), 'x', $at), 'freezeVault() of a userId without vault');
        $this->expect(StaleVaultException::class, fn () => $this->storage->freezeVault($vault->generation(), 'x', $at), 'freezeVault() naming a replaced generation');
        $this->assert($this->storage->frozenState($live->userId) === null, 'frozenState() reports a freeze before any');
        $this->assert($this->storage->freezeVault($g, 'arbiter-1', $at), 'freezeVault() returned false on a vault not frozen');
        $this->assert(!$this->storage->freezeVault($g, 'arbiter-2', new DateTimeImmutable()), 'a second freezeVault() returned true');
        $state = $this->storage->frozenState($live->userId);
        $this->assert(
            ($state['by'] ?? null) === 'arbiter-1' && ($state['at'] ?? null) instanceof DateTimeImmutable && $state['at'] == $at,
            'frozenState() does not return who froze the vault first, and when'
        );

        $stored = $this->storage->loadVault($live->userId);
        $fields = $this->storage->loadFields($live->userId);
        $writes = $this->writesUnder($g, $id) + [
            'updateVault'     => fn () => $this->storage->updateVault($stored->withWrapPwd($this->blob(), new DateTimeImmutable())),
            'swapWithArchive' => fn () => $this->storage->swapWithArchive($g, $id),
        ];
        if ($this->storage instanceof ErasesArchives) {
            $writes['eraseArchive'] = fn () => $this->storage->eraseArchive($live->userId, $id);
        }
        $writes['eraseAccount'] = fn () => $this->storage->eraseAccount($live->userId);
        foreach ($writes as $write => $call) {
            $this->expect(VaultFrozenException::class, $call, "{$write}() on a frozen vault");
        }
        $this->assertSameVault($stored, $this->storage->loadVault($live->userId), 'a frozen vault after its refused writes');
        $this->assert(
            $this->storage->loadFields($live->userId) === $fields
                && $this->storage->loadEscrowFields($live->userId) === ['contact' => 'c-contact']
                && count($this->storage->listArchives($live->userId)) === 1,
            'a write refused on a frozen vault changed its fields, its escrow or its archives'
        );

        $this->expect(StaleVaultException::class, fn () => $this->storage->unfreezeVault($vault->generation()), 'unfreezeVault() naming a replaced generation');
        $this->assert($this->storage->frozenState($live->userId) !== null, 'a refused unfreezeVault() lifted the freeze');
        $this->assert($this->storage->unfreezeVault($g), 'unfreezeVault() of a frozen vault returned false');
        $this->assert(!$this->storage->unfreezeVault($g) && $this->storage->frozenState($live->userId) === null, 'unfreezeVault() did not lift the freeze, or said it lifted one twice');
        $this->storage->saveFields($g, ['after' => ['ciphertext' => 'c-after']]);
        $this->assert($this->storage->loadFields($live->userId, ['after']) === ['after' => 'c-after'], 'an unfrozen vault still refuses writes');

        return null;
    }

    /**
     * Writes made inside a transaction the caller opened go with the caller's
     * rollback, and the storage leaves that transaction open to the caller.
     */
    private function joinsCallersTransaction(): ?string
    {
        if ($this->withCallerTransaction === null) {
            return 'needs a caller transaction: pass the third argument of run()';
        }
        $vault = $this->newVault('caller-tx');
        ($this->withCallerTransaction)(function (StorageInterface $storage) use ($vault): void {
            $storage->saveVault($vault);
            $storage->saveFields($vault->generation(), ['note' => ['ciphertext' => 'c-note']]);
            $storage->createEscrow($vault->generation(), $this->escrow($vault->userId));
            $storage->freezeVault($vault->generation(), 'caller', new DateTimeImmutable());
        });
        $this->assert(
            !$this->storage->vaultExists($vault->userId)
                && $this->storage->loadFields($vault->userId) === []
                && $this->storage->loadEscrow($vault->userId) === null,
            'writes made inside the caller\'s transaction survived its rollback: the storage committed them itself'
        );
        return null;
    }

    /**
     * A recovery ticket is recorded in the transaction of the write it names,
     * once per operation; a replay writes nothing. The archive a level-3
     * ticket set aside is found by the ticket, and erasing the account leaves
     * that ticket (erase-account-keeps-tickets).
     */
    private function ticketsAppliedOnce(): ?string
    {
        $old = $this->newVault('ticket');
        $this->storage->saveVault($old);
        $new = $this->newVault('ticket');
        $ticket = "{$this->prefix}-ticket-l3";
        $id = $this->storage->replaceWithArchive($old->generation(), $new, $ticket);
        $this->assert($this->storage->archiveOfTicket($ticket) === $id, 'archiveOfTicket() does not name the archive its ticket set aside');
        $this->assert($this->storage->archiveOfTicket("{$this->prefix}-ticket-unknown") === null, 'archiveOfTicket() names an archive for a ticket never applied');
        $this->expect(
            TicketAlreadyAppliedException::class,
            fn () => $this->storage->replaceWithArchive($new->generation(), $this->newVault('ticket'), $ticket),
            'a second replaceWithArchive() under the same ticket'
        );
        $this->assert(
            hash_equals($new->userSalt, $this->storage->loadVault($new->userId)->userSalt)
                && count($this->storage->listArchives($new->userId)) === 1,
            'a replayed ticket re-enrolled the vault again: the ticket is not recorded inside the write'
        );
        $g = $new->generation();
        $this->storage->freezeVault($g, 'arbiter', new DateTimeImmutable(), "{$this->prefix}-ticket-return");
        $this->storage->unfreezeVault($g, "{$this->prefix}-ticket-close");
        $this->expect(
            TicketAlreadyAppliedException::class,
            fn () => $this->storage->freezeVault($g, 'arbiter', new DateTimeImmutable(), "{$this->prefix}-ticket-return"),
            'a second freezeVault() under the same ticket'
        );
        $this->assert($this->storage->frozenState($new->userId) === null, 'a replayed freeze froze the vault again');
        $this->storage->eraseAccount($new->userId);
        $this->assert($this->storage->archiveOfTicket($ticket) === $id, 'eraseAccount() erased the level-3 ticket: replayed under the same userId, it would re-enrol whoever registers it next');
        return null;
    }

    private function concurrentUpdates(): ?string
    {
        if ($this->connectFile === null) {
            return 'needs a connection file: the racers are separate processes';
        }
        $vault = $this->newVault('race-update');
        $this->storage->saveVault($vault);
        $outcomes = $this->race(<<<'PHP'
            $r = $s->loadVault($uid);
            $ready();
            $s->updateVault($r->withWrapPwd($r->wrapPwd, new DateTimeImmutable()));
            PHP, $vault->userId);
        $this->assertOneWinner($outcomes, 'updateVault() on the same revision');
        $this->assert(
            $this->storage->loadVault($vault->userId)->revision === 1,
            'several updates read at revision 0 were all applied: the revision is not compared inside the write'
        );
        return null;
    }

    private function concurrentReEnrolments(): ?string
    {
        if ($this->connectFile === null) {
            return 'needs a connection file: the racers are separate processes';
        }
        $vault = $this->newVault('race-reenrol');
        $this->storage->saveVault($vault);
        $outcomes = $this->race(<<<'PHP'
            $r = $s->loadVault($uid);
            $new = new Pierroons\SelfDataGuard\Vault\VaultRecord(
                userId: $uid, userSalt: random_bytes(16), wrapPwd: $r->wrapPwd, wrapRecov: null, wrapAdmin: null,
                createdAt: new DateTimeImmutable(), updatedAt: new DateTimeImmutable(),
                kdfOpslimit: $r->kdfOpslimit, kdfMemlimit: $r->kdfMemlimit
            );
            $ready();
            $s->replaceWithArchive($r->generation(), $new);
            PHP, $vault->userId);
        $this->assertOneWinner($outcomes, 'replaceWithArchive() of the same generation');
        $this->assert(
            count($this->storage->listArchives($vault->userId)) === 1,
            'several re-enrolments of the same generation each made an archive: the generation is not compared inside the write'
        );
        return null;
    }

    private function concurrentUniqueIndex(): ?string
    {
        if ($this->connectFile === null) {
            return 'needs a connection file: the racers are separate processes';
        }
        $base = $this->id('race-index');
        for ($i = 0; $i < self::RACERS; $i++) {
            $this->storage->saveVault($this->vaultFor($this->id("race-index-{$i}")));
        }
        $outcomes = $this->race(<<<'PHP'
            $g = $s->loadVault("{$uid}-{$me}")->generation();
            $ready();
            $s->saveFields($g, ['email' => ['ciphertext' => 'c', 'blindIndex' => 'IdxRace=']]);
            PHP, $base);
        $this->assertOneWinner($outcomes, 'saveFields() of the same blind-index value from different accounts');
        $this->assert(
            count($this->storage->findUserIdsByIndex('email', 'IdxRace=')) === 1,
            'several accounts hold the same blind-index value: the uniqueness is checked before the write, not enforced by it'
        );
        return null;
    }

    private function settingReplacedOverExpected(): ?string
    {
        $name = $this->setting('replaced');
        $this->storage->claimSetting($name, 'first');
        $this->assert(!$this->storage->replaceSetting($name, 'not-held', 'second'), 'replaceSetting() over a value the database does not hold returned true');
        $this->assert(
            $this->storage->loadSetting($name) === 'first',
            'replaceSetting() over a value the database does not hold replaced it: the expected value is not compared'
        );
        $this->assert($this->storage->replaceSetting($name, 'first', 'second'), 'replaceSetting() over the value held returned false');
        $this->assert($this->storage->loadSetting($name) === 'second', 'replaceSetting() over the value held did not replace it');
        $this->assert(
            $this->storage->replaceSetting($name, 'second', 'second'),
            'replaceSetting() with the value already held returned false: the storage counts changed rows, not matched ones'
        );
        $this->assert(
            !$this->storage->replaceSetting($name, 'first', 'first') && $this->storage->loadSetting($name) === 'second',
            'replaceSetting() with an equal value the name does not hold returned true: the expected value is not compared'
        );
        $never = $this->setting('never-replaced');
        $this->assert(
            !$this->storage->replaceSetting($never, 'x', 'y') && $this->storage->loadSetting($never) === null,
            'replaceSetting() of a name never claimed wrote a value'
        );
        return null;
    }

    private function settingClaimedOnce(): ?string
    {
        $name  = $this->setting('once');
        $other = $this->setting('other');
        $this->assert($this->storage->claimSetting($name, 'first') === 'first', 'claimSetting() did not return the value it wrote');
        $this->assert(
            $this->storage->claimSetting($name, 'second') === 'first',
            'a second claimSetting() replaced the value: the first one written must stay, and be returned'
        );
        $this->assert($this->storage->claimSetting($other, 'its own') === 'its own', 'a setting under another name was mixed up with the first');
        $this->assert($this->storage->claimSetting($name, 'third') === 'first', 'claimSetting() lost the first value');
        $this->assert($this->storage->loadSetting($name) === 'first', 'loadSetting() did not return the value claimed');
        $this->assert($this->storage->loadSetting($this->setting('never')) === null, 'loadSetting() returned a value for a name never claimed');
        return null;
    }

    private function hasVaultsCase(): ?string
    {
        // An archive left alone tells a storage that counts live vaults only
        // apart — on a database that held nothing before this case.
        $empty = !$this->storage->hasVaults();
        $vault = $this->newVault('has');
        $this->storage->saveVault($vault);
        $this->assert($this->storage->hasVaults(), 'hasVaults() is false on a database holding a vault');
        $this->storage->replaceWithArchive($vault->generation(), $live = $this->newVault('has'));
        $this->storage->deleteVault($live->generation());
        $this->assert(!$empty || $this->storage->hasVaults(), 'hasVaults() is false on a database that keeps only an archive');
        return null;
    }

    /**
     * Every userId with a live vault or an archive, once, in byte order, page
     * after page: a collation that folds case or follows a locale puts "a"
     * before "B", and a pass that walks the pages skips or repeats accounts.
     */
    private function accountIdsCase(): ?string
    {
        $expected = [];
        foreach (['B', 'a', 'ab', 'é'] as $label) {
            $vault = $this->newVault("ids-{$label}");
            $this->storage->saveVault($vault);
            $expected[] = $vault->userId;
        }
        $both = $this->newVault('ids-both');
        $this->storage->saveVault($both);
        $this->storage->replaceWithArchive($both->generation(), $this->vaultFor($both->userId));
        $expected[] = $both->userId;
        $archived = $this->newVault('ids-archived');
        $this->storage->saveVault($archived);
        $next = $this->vaultFor($archived->userId);
        $this->storage->replaceWithArchive($archived->generation(), $next);
        $this->storage->deleteVault($next->generation());
        $expected[] = $archived->userId;
        sort($expected, SORT_STRING);

        $mine  = "{$this->prefix}-ids-";
        $seen  = [];
        $after = $mine;
        for ($pages = 0; $pages < count($expected) + 2; $pages++) {
            $page = $this->storage->accountIds($after, 2);
            $this->assert(count($page) <= 2, 'accountIds() returned more userIds than its limit');
            $ours = array_values(array_filter($page, static fn (string $u): bool => str_starts_with($u, $mine)));
            $seen = [...$seen, ...$ours];
            if (count($page) < 2 || count($ours) < count($page)) {
                break;
            }
            $after = (string) end($page);
        }
        $this->assert(
            $seen === $expected,
            'accountIds() did not return each userId with a live vault or an archive once, in byte order, page after page: got '
                . json_encode($seen, JSON_UNESCAPED_UNICODE)
        );
        $this->expect(\InvalidArgumentException::class, fn () => $this->storage->accountIds('', 0), 'accountIds() with a limit of 0');
        return null;
    }

    private function indexedFieldsCase(): ?string
    {
        $alice = $this->newVault('indexed-alice');
        $bob   = $this->newVault('indexed-bob');
        $this->storage->saveVault($alice);
        $this->storage->saveVault($bob);
        [$blind, $shared, $mixed, $plain] = array_map(fn ($f) => "{$this->prefix}-field-{$f}", ['blind', 'shared', 'mixed', 'plain']);
        $this->storage->saveFields($alice->generation(), [
            $blind => ['ciphertext' => 'c-b', 'blindIndex' => 'IdxFieldB='],
            $mixed => ['ciphertext' => 'c-m', 'blindIndex' => 'IdxFieldM='],
            $plain => ['ciphertext' => 'c-p'],
        ]);
        $this->storage->saveFields($bob->generation(), [
            $shared => ['ciphertext' => 'c-s', 'sharedIndex' => 'IdxFieldS='],
            $mixed  => ['ciphertext' => 'c-m2', 'sharedIndex' => 'IdxFieldM2='],
        ]);
        $ours = array_filter(
            $this->storage->indexedFields(),
            fn ($name) => str_starts_with((string) $name, "{$this->prefix}-field-"),
            ARRAY_FILTER_USE_KEY
        );
        ksort($ours);
        $this->assert(
            $ours === [$blind => false, $mixed => true, $shared => true],
            'indexedFields() did not name exactly the fields that carry an index, each with whether one of its values is shared'
        );
        return null;
    }

    private function concurrentSettingClaims(): ?string
    {
        if ($this->connectFile === null) {
            return 'needs a connection file: the racers are separate processes';
        }
        $name = $this->setting('race-' . bin2hex(random_bytes(3)));
        $outcomes = $this->race(<<<'PHP'
            $ready();
            echo $s->claimSetting($uid, "claimed-by-{$me}"), ' ';
            PHP, $name);
        $held = $this->storage->claimSetting($name, 'claimed-after');
        $this->assert(
            array_count_values($outcomes) === ["{$held} ok" => self::RACERS],
            sprintf('%d processes claimed one setting at once: each must be given the value the database holds, %s — got %s',
                self::RACERS, $held, implode(' | ', $outcomes))
        );
        return null;
    }

    /**
     * A setting name for this run. The contract has no way to remove a
     * setting: the few the cases write stay, under these names, and nothing
     * reads them.
     */
    private function setting(string $label): string
    {
        return "{$this->prefix}-setting-{$label}";
    }

    private function concurrentEscrowCreations(): ?string
    {
        if ($this->connectFile === null) {
            return 'needs a connection file: the racers are separate processes';
        }
        $vault = $this->newVault('race-escrow');
        $this->storage->saveVault($vault);
        $outcomes = $this->race(<<<'PHP'
            $r = $s->loadVault($uid);
            $now = new DateTimeImmutable();
            $escrow = new Pierroons\SelfDataGuard\Escrow\EscrowRecord($uid, $r->wrapPwd, "sealed-by-{$me}", $now, $now, 0, null);
            $ready();
            $s->createEscrow($r->generation(), $escrow);
            PHP, $vault->userId);
        $this->assertOneWinner($outcomes, 'createEscrow() of the same account');
        $winner = array_search('ok', $outcomes, true);
        $this->assert(
            $this->storage->loadEscrow($vault->userId)?->wrapAdmin === "sealed-by-{$winner}",
            'the escrow stored is not the one whose creation succeeded: a refused creation replaced it'
        );
        return null;
    }

    private function concurrentEscrowRotations(): ?string
    {
        if ($this->connectFile === null) {
            return 'needs a connection file: the racers are separate processes';
        }
        $vault = $this->newVault('race-rotate');
        $this->storage->saveVault($vault);
        $this->storage->createEscrow($vault->generation(), $this->escrow($vault->userId));
        $this->storage->saveEscrowFields($vault->generation(), ['before' => 'c-before']);
        $outcomes = $this->race(<<<'PHP'
            $r = $s->loadVault($uid);
            $e = $s->loadEscrow($uid);
            $rotated = new Pierroons\SelfDataGuard\Escrow\EscrowRecord($uid, $e->wrapUser, "rotated-by-{$me}", $e->createdAt, new DateTimeImmutable(), $e->revision, null);
            $ready();
            $s->rotateEscrow($r->generation(), $rotated, ["by-{$me}" => "c-{$me}"]);
            PHP, $vault->userId);
        $this->assertOneWinner($outcomes, 'rotateEscrow() of one escrow from the same read');
        $winner = array_search('ok', $outcomes, true);
        $this->assert(
            $this->storage->loadEscrow($vault->userId)?->wrapAdmin === "rotated-by-{$winner}"
                && $this->storage->loadEscrowFields($vault->userId) === ["by-{$winner}" => "c-{$winner}"],
            'the escrow stored is not exactly the winning rotation: a refused one left its envelope or its fields'
        );
        return null;
    }

    private function concurrentSettingReplacements(): ?string
    {
        if ($this->connectFile === null) {
            return 'needs a connection file: the racers are separate processes';
        }
        $name = $this->setting('race-replace-' . bin2hex(random_bytes(3)));
        $this->storage->claimSetting($name, 'start');
        $outcomes = $this->race(<<<'PHP'
            $ready();
            echo $s->replaceSetting($uid, 'start', "replaced-by-{$me}") ? 'won ' : 'lost ';
            PHP, $name);
        $counts = array_count_values($outcomes);
        $winner = array_search('won ok', $outcomes, true);
        $this->assert(
            ($counts['won ok'] ?? 0) === 1 && ($counts['lost ok'] ?? 0) === self::RACERS - 1
                && $this->storage->loadSetting($name) === "replaced-by-{$winner}",
            sprintf('%d processes replaced one setting over the same value at once: exactly one must succeed — got %s',
                self::RACERS, implode(' | ', $outcomes))
        );
        return null;
    }

    /**
     * A re-enrolment and writes that name the generation it replaces, at the
     * same instant. Each write lands in the old vault, archived with it, or is
     * refused: none may land in the vault that replaced it.
     */
    private function concurrentWritesAndReEnrolment(): ?string
    {
        if ($this->connectFile === null) {
            return 'needs a connection file: the racers are separate processes';
        }
        $vault = $this->newVault('race-writes');
        $this->storage->saveVault($vault);
        $outcomes = $this->race(<<<'PHP'
            $r = $s->loadVault($uid);
            $g = $r->generation();
            $now = new DateTimeImmutable();
            $new = new Pierroons\SelfDataGuard\Vault\VaultRecord(
                userId: $uid, userSalt: random_bytes(16), wrapPwd: $r->wrapPwd, wrapRecov: null, wrapAdmin: null,
                createdAt: $now, updatedAt: $now, kdfOpslimit: $r->kdfOpslimit, kdfMemlimit: $r->kdfMemlimit
            );
            $ready();
            match ($me) {
                0       => $s->replaceWithArchive($g, $new),
                1       => $s->saveFields($g, ['late' => ['ciphertext' => 'c-late']]),
                2       => $s->saveEscrowFields($g, ['late' => 'c-late']),
                default => $s->createEscrow($g, new Pierroons\SelfDataGuard\Escrow\EscrowRecord($uid, $r->wrapPwd, 'sealed-late', $now, $now, 0, null)),
            };
            PHP, $vault->userId);
        $this->assert(
            $outcomes[0] === 'ok' && array_diff($outcomes, ['ok', 'refused']) === [],
            'the re-enrolment, or a write racing it, failed in another way than a stale generation — got ' . implode(' | ', $outcomes)
        );
        $this->assert(
            !hash_equals($vault->userSalt, $this->storage->loadVault($vault->userId)->userSalt),
            'the re-enrolment did not replace the vault'
        );
        $this->assert(
            $this->storage->loadFields($vault->userId) === []
                && $this->storage->loadEscrowFields($vault->userId) === []
                && $this->storage->loadEscrow($vault->userId) === null,
            'a write that named the replaced generation landed in the vault that replaced it: '
            . 'the generation is compared before the write, not inside its transaction'
        );
        return null;
    }

    private function concurrentArchiveSwaps(): ?string
    {
        if ($this->connectFile === null) {
            return 'needs a connection file: the racers are separate processes';
        }
        $old = $this->newVault('race-swap');
        $this->storage->saveVault($old);
        $id = $this->storage->replaceWithArchive($old->generation(), $this->newVault('race-swap'));
        $outcomes = $this->race(<<<PHP
            \$g = \$s->loadVault(\$uid)->generation();
            \$ready();
            \$s->swapWithArchive(\$g, '{$id}');
            PHP, $old->userId);
        $this->assertOneWinner($outcomes, 'swapWithArchive() of the same generation');
        $this->assert(
            hash_equals($old->userSalt, $this->storage->loadVault($old->userId)->userSalt)
                && count($this->storage->listArchives($old->userId)) === 1,
            'several swaps of the same generation went through: the generation is not compared inside the swap'
        );
        return null;
    }

    // -- Racing processes -------------------------------------------------------

    /**
     * Runs $body in RACERS processes. Each reads, reports ready, and waits until
     * all are ready before it writes, so that every one of them writes over the
     * same state.
     *
     * @return list<string> one outcome per process: "ok", "refused", or an error
     */
    private function race(string $body, string $userId): array
    {
        $dir = sys_get_temp_dir() . '/sdg-conformance-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700)) {
            throw new RuntimeException("Cannot create {$dir}");
        }
        $script = sprintf(
            '$connect = require %s; $s = $connect(); $uid = %s; $dir = %s; $me = (int) $argv[1];'
            . ' $ready = static function () use ($dir, $me): void {'
            . '   touch("{$dir}/ready-{$me}"); $t = microtime(true);'
            . '   while (!is_file("{$dir}/go")) { if (microtime(true) - $t > 20) { throw new RuntimeException("never released"); } usleep(1000); }'
            . ' };'
            . ' try { %s echo "ok"; }'
            . ' catch (Pierroons\SelfDataGuard\Vault\StaleVaultException'
            . ' | Pierroons\SelfDataGuard\Storage\DuplicateIndexException'
            . ' | Pierroons\SelfDataGuard\Escrow\EscrowExistsException'
            . ' | Pierroons\SelfDataGuard\Escrow\StaleEscrowException) { echo "refused"; }'
            . ' catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(); }',
            var_export($this->connectFile, true),
            var_export($userId, true),
            var_export($dir, true),
            $body
        );
        $children = [];
        try {
            for ($i = 0; $i < self::RACERS; $i++) {
                $process = proc_open([PHP_BINARY, '-r', $script, '--', (string) $i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (!is_resource($process)) {
                    throw new RuntimeException('Cannot start a racing process');
                }
                $children[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 20;
            while (count(glob("{$dir}/ready-*") ?: []) < self::RACERS) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('The racing processes never all read the vault');
                }
                usleep(1000);
            }
            touch("{$dir}/go");
            $outcomes = [];
            foreach ($children as [$process, $pipes]) {
                $outcomes[] = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
                proc_close($process);
            }
            return $outcomes;
        } finally {
            foreach (glob("{$dir}/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    /** @param list<string> $outcomes */
    private function assertOneWinner(array $outcomes, string $what): void
    {
        $counts = array_count_values($outcomes);
        $this->assert(
            ($counts['ok'] ?? 0) === 1 && ($counts['refused'] ?? 0) === self::RACERS - 1,
            sprintf('%d processes ran %s: exactly one must succeed, the others are refused — got %s',
                self::RACERS, $what, implode(' | ', $outcomes))
        );
    }

    // -- Fixtures ---------------------------------------------------------------

    /** A userId for this run; every one handed out is erased when its case ends. */
    private function id(string $label): string
    {
        $userId = "{$this->prefix}-{$label}";
        if (!in_array($userId, $this->created, true)) {
            $this->created[] = $userId;
        }
        return $userId;
    }

    /**
     * A vault record with real ciphertexts under a throwaway key: the storage
     * keeps bytes, it never opens them.
     */
    private function newVault(string $label, bool $withRecov = true, bool $withPhrase = false): VaultRecord
    {
        return $this->vaultFor($this->id($label), $withRecov, $withPhrase);
    }

    private function vaultFor(string $userId, bool $withRecov = true, bool $withPhrase = false): VaultRecord
    {
        $now = new DateTimeImmutable();
        return new VaultRecord(
            userId:      $userId,
            userSalt:    Primitives::randomBytes(Primitives::SALT_LEN),
            wrapPwd:     $this->blob(),
            wrapRecov:   $withRecov ? $this->blob() : null,
            wrapAdmin:   null,
            createdAt:   $now,
            updatedAt:   $now,
            wrapPhrase:  $withPhrase ? $this->blob() : null,
            revision:    0,
            kdfOpslimit: self::OPSLIMIT,
            kdfMemlimit: self::MEMLIMIT,
            profileSeal: Primitives::randomBytes(32)
        );
    }

    private function withSalt(VaultRecord $record, string $salt): VaultRecord
    {
        return new VaultRecord(
            userId: $record->userId, userSalt: $salt, wrapPwd: $record->wrapPwd, wrapRecov: $record->wrapRecov,
            wrapAdmin: $record->wrapAdmin, createdAt: $record->createdAt, updatedAt: $record->updatedAt,
            wrapPhrase: $record->wrapPhrase, revision: $record->revision,
            kdfOpslimit: $record->kdfOpslimit, kdfMemlimit: $record->kdfMemlimit, profileSeal: $record->profileSeal
        );
    }

    /** The same vault in new lock envelopes, under another profile and seal. */
    private function relaid(VaultRecord $record): VaultRecord
    {
        return new VaultRecord(
            userId: $record->userId, userSalt: $record->userSalt, wrapPwd: $this->blob(),
            wrapRecov: $record->wrapRecov === null ? null : $this->blob(), wrapAdmin: $record->wrapAdmin,
            createdAt: $record->createdAt, updatedAt: new DateTimeImmutable(),
            wrapPhrase: $record->wrapPhrase === null ? null : $this->blob(), revision: $record->revision,
            kdfOpslimit: $record->kdfOpslimit + 1, kdfMemlimit: $record->kdfMemlimit + 1,
            profileSeal: Primitives::randomBytes(32)
        );
    }

    private function blob(): EncryptedBlob
    {
        return Primitives::encrypt(
            Primitives::randomBytes(Primitives::KEY_LEN),
            Primitives::randomBytes(Primitives::KEY_LEN),
            Aad::of('conformance')
        );
    }

    private function escrow(string $userId): EscrowRecord
    {
        $this->escrowWrap ??= $this->blob();
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        return new EscrowRecord($userId, $this->escrowWrap, 'sealed-to-admin:' . $userId, $now, $now, 0, null);
    }

    /** $read again, with another wrap_admin, at the revision it was read at. */
    private function escrowLike(EscrowRecord $read, string $wrapAdmin): EscrowRecord
    {
        return new EscrowRecord($read->userId, $read->wrapUser, $wrapAdmin, $read->createdAt, new DateTimeImmutable(), $read->revision, $read->adminKey);
    }

    private function indexOf(string $userId): string
    {
        return base64_encode(hash('sha256', $userId, true));
    }

    /** One indexed field, one plain field, an escrow and one escrow field. */
    private function fill(VaultRecord $vault): void
    {
        $this->storage->saveFields($vault->generation(), [
            'email' => ['ciphertext' => 'c-email', 'blindIndex' => $this->indexOf($vault->userId)],
            'note'  => ['ciphertext' => 'c-note'],
        ]);
        $this->storage->createEscrow($vault->generation(), $this->escrow($vault->userId));
        $this->storage->saveEscrowFields($vault->generation(), ['contact' => 'c-contact']);
    }

    /** @return array<string, callable(): mixed> each write of the contract, under $generation */
    private function writesUnder(VaultGeneration $generation, string $archiveId): array
    {
        return [
            'saveFields'         => fn () => $this->storage->saveFields($generation, ['f' => ['ciphertext' => 'c']]),
            'createEscrow'       => fn () => $this->storage->createEscrow($generation, $this->escrow($generation->userId)),
            'replaceEscrow'      => fn () => $this->storage->replaceEscrow($generation, $this->escrow($generation->userId)),
            'rotateEscrow'       => fn () => $this->storage->rotateEscrow($generation, $this->escrow($generation->userId), ['e' => 'c']),
            'saveEscrowFields'   => fn () => $this->storage->saveEscrowFields($generation, ['e' => 'c']),
            'deleteVault'        => fn () => $this->storage->deleteVault($generation),
            'deleteArchive'      => fn () => $this->storage->deleteArchive($generation, $archiveId),
            'replaceWithArchive' => fn () => $this->storage->replaceWithArchive($generation, $this->vaultFor($generation->userId)),
        ];
    }

    private function eraseCreated(): void
    {
        foreach ($this->created as $userId) {
            try {
                $live = $this->storage->findVault($userId);
                if ($live !== null && $this->storage->frozenState($userId) !== null) {
                    $this->storage->unfreezeVault($live->generation());
                }
                $this->storage->eraseAccount($userId);
                foreach ($this->storage->listArchives($userId) as $kept) {
                    if ($this->storage instanceof ErasesArchives) {
                        $this->storage->eraseArchive($userId, $kept['id']);
                    } else {
                        $this->storage->restoreArchive($userId, $kept['id']);
                        $this->storage->eraseAccount($userId);
                    }
                }
            } catch (Throwable) {
            }
        }
        $this->created = [];
        $this->storage = ($this->connect)();
    }

    // -- Assertions -------------------------------------------------------------

    private function assert(bool $condition, string $failure): void
    {
        if (!$condition) {
            throw new RuntimeException($failure);
        }
    }

    /** @param class-string<Throwable> $expected */
    private function expect(string $expected, callable $call, string $what): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            if ($e instanceof $expected) {
                return;
            }
            throw new RuntimeException("{$what}: expected " . $this->short($expected) . ', got ' . get_class($e) . ': ' . $e->getMessage());
        }
        throw new RuntimeException("{$what}: expected " . $this->short($expected) . ', nothing was thrown');
    }

    private function short(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }

    private function assertSameVault(VaultRecord $written, VaultRecord $read, string $where, bool $ignoreRevision = false): void
    {
        $columns = [
            'user_id'      => [$written->userId, $read->userId],
            'user_salt'    => [base64_encode($written->userSalt), base64_encode($read->userSalt)],
            'wrap_pwd'     => [$written->wrapPwd->toBase64(), $read->wrapPwd->toBase64()],
            'wrap_recov'   => [$written->wrapRecov?->toBase64(), $read->wrapRecov?->toBase64()],
            'wrap_phrase'  => [$written->wrapPhrase?->toBase64(), $read->wrapPhrase?->toBase64()],
            'kdf_opslimit' => [$written->kdfOpslimit, $read->kdfOpslimit],
            'kdf_memlimit' => [$written->kdfMemlimit, $read->kdfMemlimit],
            'profile_seal' => [
                $written->profileSeal === null ? null : base64_encode($written->profileSeal),
                $read->profileSeal === null ? null : base64_encode($read->profileSeal),
            ],
        ];
        if (!$ignoreRevision) {
            $columns['revision'] = [$written->revision, $read->revision];
        }
        foreach ($columns as $column => [$expected, $actual]) {
            $this->assert($expected === $actual, "{$where}: {$column} differs from what was written");
        }
    }
}
