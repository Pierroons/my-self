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
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\StaleVaultException;
use Pierroons\SelfDataGuard\Vault\VaultExistsException;
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
 * ("conformance-<hex>-…"), and erases each of them when its case ends; the
 * settings it claims stay, under names of the same form, which nothing reads.
 * Each concurrent case is played ten times. Run it against a database you can
 * write to, before the storage serves anyone.
 *
 * The cases that need several processes run only when the connection comes
 * from a file — the child processes require it in turn. Without one, they are
 * reported skipped, not passed.
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

    /**
     * @param callable(): StorageInterface $connect a new connection to the database under test
     */
    private function __construct(callable $connect, private readonly ?string $connectFile)
    {
        $this->connect = $connect;
        $this->storage = $connect();
        $this->prefix  = 'conformance-' . bin2hex(random_bytes(6));
    }

    /**
     * @param callable(): StorageInterface $connect     a new connection to the database under test
     * @param string|null                  $connectFile a PHP file that loads its autoloader and
     *                                                  returns that same callable
     * @return list<array{case: string, outcome: string, detail: string}> outcome is pass, fail or skip
     */
    public static function run(callable $connect, ?string $connectFile = null): array
    {
        $suite = new self($connect, $connectFile);
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
            'delete-vault'                  => 'deleteVault',
            'replace-with-archive'          => 'replaceWithArchive',
            'delete-archive'                => 'deleteArchive',
            'erase-account'                 => 'eraseAccount',
            'concurrent-updates'            => 'concurrentUpdates',
            'concurrent-re-enrolments'      => 'concurrentReEnrolments',
            'concurrent-unique-index'       => 'concurrentUniqueIndex',
            'concurrent-escrow-creations'   => 'concurrentEscrowCreations',
            'concurrent-writes-and-re-enrolment' => 'concurrentWritesAndReEnrolment',
            'setting-claimed-once'          => 'settingClaimedOnce',
            'has-vaults'                    => 'hasVaultsCase',
            'indexed-fields'                => 'indexedFieldsCase',
            'concurrent-setting-claims'     => 'concurrentSettingClaims',
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
            $this->storage->replaceEscrow($generation, $escrow);
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

        $this->storage->replaceWithArchive($alice->generation(), $this->newVault('uniq-alice'));
        $this->storage->saveFields($bob->generation(), ['email' => ['ciphertext' => 'c-b4', 'blindIndex' => 'IdxTaken=']]);
        $this->assert(
            $this->storage->findUserIdsByIndex('email', 'IdxTaken=') === [$bob->userId],
            'an archived vault still holds its blind-index values'
        );
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
        $second = new EscrowRecord($vault->userId, $this->blob(), 'sealed-to-admin:second', $escrow->createdAt, $escrow->updatedAt);
        $this->expect(EscrowExistsException::class, fn () => $this->storage->createEscrow($vault->generation(), $second), 'a second createEscrow() of the same userId');
        $this->assert(
            $this->storage->loadEscrow($vault->userId)?->wrapAdmin === $escrow->wrapAdmin,
            'a second createEscrow() replaced the first escrow: the fields sealed under its key no longer open'
        );
        $rebound = new EscrowRecord($vault->userId, $escrow->wrapUser, 'sealed-to-admin:rebound', $escrow->createdAt, new DateTimeImmutable());
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
            $escrow = new Pierroons\SelfDataGuard\Escrow\EscrowRecord($uid, $r->wrapPwd, "sealed-by-{$me}", $now, $now);
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
                default => $s->createEscrow($g, new Pierroons\SelfDataGuard\Escrow\EscrowRecord($uid, $r->wrapPwd, 'sealed-late', $now, $now)),
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
            . ' | Pierroons\SelfDataGuard\Escrow\EscrowExistsException) { echo "refused"; }'
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
        return new EscrowRecord($userId, $this->escrowWrap, 'sealed-to-admin:' . $userId, $now, $now);
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
                $this->storage->eraseAccount($userId);
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
