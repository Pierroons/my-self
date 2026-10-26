<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard;

use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Escrow\AdminKey;
use Pierroons\SelfDataGuard\Escrow\EscrowExistsException;
use Pierroons\SelfDataGuard\Escrow\EscrowFieldCrypter;
use Pierroons\SelfDataGuard\Escrow\EscrowVault;
use Pierroons\SelfDataGuard\Fields\AmbiguousLookupException;
use Pierroons\SelfDataGuard\Fields\BlindIndex;
use Pierroons\SelfDataGuard\Fields\FieldCrypter;
use Pierroons\SelfDataGuard\Storage\StorageInterface;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\ProfileSeal;
use Pierroons\SelfDataGuard\Vault\StaleVaultException;
use Pierroons\SelfDataGuard\Vault\UnlockedArchive;
use Pierroons\SelfDataGuard\Vault\UnlockedVault;
use Pierroons\SelfDataGuard\Vault\UserVault;
use Pierroons\SelfDataGuard\Vault\VaultExistsException;
use Pierroons\SelfDataGuard\Vault\VaultGeneration;
use Pierroons\SelfDataGuard\Vault\VaultNotFoundException;
use Pierroons\SelfDataGuard\Vault\VaultRecord;
use Pierroons\SelfDataGuard\Vault\VaultTamperedException;
use RuntimeException;

/**
 * Public façade for SelfDataGuard.
 *
 * Wraps the Vault + Fields + Storage layers behind a minimal API:
 *
 *     $dg = new SelfDataGuard($storage, $blindKey, sharedIndexes: ['phone']);
 *
 *     // New user. Passwords are refused below UserVault::PASSWORD_MIN_LEN.
 *     $session = $dg->register('user-1', 'a-long-generated-password', 'memorized-secret', 'six word passphrase');
 *     $dg->setFields($session, ['email' => 'a@b.c'], indexed: ['email']);
 *
 *     // Returning user
 *     $session = $dg->loginWithPassword('user-1', 'password');
 *     $fields  = $dg->getFields($session);
 *
 *     // After a SelfRecover recovery: open with the secret the server holds,
 *     // re-seal with the secrets SelfRecover just issued — one write.
 *     $session = $dg->recover('user-1', Lock::Passphrase, $old, $newPassword, $newPassphrase);
 *
 *     // Level 3, no old secret left: the vault is archived, a new one created.
 *     $live = $dg->generationOf('user-1');
 *     ['unlocked' => $session, 'archiveId' => $id] = $dg->reEnroll($live, $pwd, $memorized, $passphrase);
 *
 *     // Find a user by an indexed field (no plaintext lookup needed)
 *     $userId  = $dg->findUserByField('email', 'a@b.c');
 *     $holders = $dg->findUsersByField('phone', '+33 6 …');   // a shared field: a list
 *
 * The blindKey is a server-side secret used to derive deterministic field
 * indexes (HMAC). Store it in env/Vault/etc., separate from any per-user
 * cryptographic material. ≥32 bytes of high-entropy random.
 */
final class SelfDataGuard
{
    private UserVault $vault;
    private EscrowVault $escrow;

    /** @var array<string, true> */
    private array $shared = [];

    private readonly string $sealKey;

    /**
     * @param list<string> $sharedIndexes the indexed fields several accounts may
     *        hold the same value of (a household phone). Every other indexed
     *        field names one account. Declared here, once: declared per call,
     *        two calls could write the same field under two rules.
     */
    public function __construct(
        private readonly StorageInterface $storage,
        #[\SensitiveParameter] private readonly string $blindKey,
        array $sharedIndexes = []
    ) {
        if (strlen($blindKey) < 32) {
            throw new InvalidArgumentException(
                'blindKey must be ≥32 bytes of server-side secret'
            );
        }
        foreach ($sharedIndexes as $fieldName) {
            if (!is_string($fieldName) || $fieldName === '') {
                throw new InvalidArgumentException('sharedIndexes lists field names');
            }
            $this->shared[$fieldName] = true;
        }
        $this->sealKey = ProfileSeal::key($blindKey);
        $this->vault   = new UserVault();
        $this->escrow  = new EscrowVault();
    }

    /**
     * Create a new user vault and persist it. Returns the UnlockedVault for
     * immediate field encryption (e.g. setting initial profile data).
     *
     * ⚠️ Without `$memorized` and `$passphrase`, the vault has one envelope and
     * dies with the password: see `UserVault::register()`.
     */
    public function register(
        string $userId,
        #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] ?string $memorized = null,
        #[\SensitiveParameter] ?string $passphrase = null
    ): UnlockedVault {
        if ($this->storage->vaultExists($userId)) {
            throw new VaultExistsException("User '{$userId}' already exists");
        }
        $result = $this->vault->register($userId, $password, $memorized, $passphrase);
        $record = $this->sealed($result['record']);
        $this->storage->saveVault($record);
        $this->assertVaultPersisted($record, 0);
        return $result['unlocked'];
    }

    /**
     * Authenticate by password. Returns an UnlockedVault for the session.
     *
     * @throws Vault\WrongSecretException     on wrong password
     * @throws Vault\VaultNotFoundException   on missing user
     */
    public function loginWithPassword(string $userId, #[\SensitiveParameter] string $password): UnlockedVault
    {
        $record = $this->load($userId);
        return $this->vault->unlockWithPassword($record, $password);
    }

    /**
     * Authenticate by memorized secret (recovery flow).
     *
     * @throws Vault\WrongSecretException     on wrong secret
     * @throws Vault\VaultNotFoundException   on missing user
     */
    public function loginWithMemorized(string $userId, #[\SensitiveParameter] string $memorized): UnlockedVault
    {
        $record = $this->load($userId);
        return $this->vault->unlockWithMemorized($record, $memorized);
    }

    /**
     * Open the vault with whichever secret the server holds, and re-seal it
     * with the password — and passphrase, if given — that replace the old
     * ones. One conditional write: a concurrent re-enrolment makes it fail
     * (StaleVaultException) instead of overwriting the new vault.
     *
     * Serves every SelfRecover path that keeps the data:
     *   - level 1      : Lock::Passphrase, the old passphrase; new password and passphrase
     *   - level 2/code : Lock::Memorized, the browser-side digest; new password and passphrase
     *   - level 2/device, or a vault whose password wrap fell behind: any lock
     *                    the user can give, the current password, $newPassphrase null
     *
     * On its own it is an Argon2id oracle with no rate limit. Call it right
     * after SelfRecover has accepted a recovery, behind SelfRecover's own
     * counters — or, for the catch-up after a device recovery, rate-limit it
     * as the login is.
     *
     * $newPassphrase null keeps the passphrase wrap as it is.
     */
    public function recover(
        string $userId,
        Lock $lock,
        #[\SensitiveParameter] string $secret,
        #[\SensitiveParameter] string $newPassword,
        #[\SensitiveParameter] ?string $newPassphrase = null
    ): UnlockedVault {
        $record  = $this->load($userId);
        $session = $this->vault->unlock($record, $lock, $secret);
        $record  = $this->vault->setLock($record, $session, Lock::Password, $newPassword);
        if ($newPassphrase !== null) {
            $record = $this->vault->setLock($record, $session, Lock::Passphrase, $newPassphrase);
        }
        $this->persist($record);
        return $session;
    }

    /**
     * Encrypt and persist a batch of field values for the user.
     * Optionally compute and store blind indexes for lookup-able fields.
     *
     * An indexed value names one account, unless its field is declared shared.
     * If another account holds it, DuplicateIndexException names the fields and
     * nothing of the batch is written. Index a value once the account has
     * proven it holds it (a confirmation mail, a code): whoever indexes it
     * first keeps it.
     *
     * @param UnlockedVault         $session  Active session
     * @param array<string, string> $fields   field_name => plaintext
     * @param array<string>         $indexed  Subset of $fields names to also index for lookup
     */
    public function setFields(UnlockedVault $session, array $fields, array $indexed = []): void
    {
        if ($fields === []) {
            return;
        }
        $this->currentRecord($session);
        $indexed = array_flip($indexed);
        $payload = [];
        foreach ($fields as $name => $value) {
            $index = isset($indexed[$name]) ? BlindIndex::compute($value, $this->blindKey, $name) : null;
            $payload[$name] = [
                'ciphertext'  => FieldCrypter::encrypt($session, $name, $value),
                'blindIndex'  => isset($this->shared[$name]) ? null : $index,
                'sharedIndex' => isset($this->shared[$name]) ? $index : null,
            ];
        }
        $this->storage->saveFields($session->generation(), $payload);
    }

    /**
     * Decrypt and return field plaintexts for the active session.
     *
     * @param UnlockedVault $session
     * @param array<string> $fieldNames Empty = all fields
     * @return array<string, string>    field_name => plaintext
     */
    public function getFields(UnlockedVault $session, array $fieldNames = []): array
    {
        $this->currentRecord($session);
        $cipher = $this->storage->loadFields($session->userId, $fieldNames);
        if ($fieldNames === []) {
            // A row under the reserved name is never decrypted. Read with every
            // other field, it is left out instead of refusing the whole account;
            // asked for by name, FieldCrypter refuses it.
            unset($cipher[EscrowVault::WRAP_AAD_TAG]);
        }
        return FieldCrypter::decryptBatch($session, $cipher);
    }

    /**
     * The account a field value names (e.g. email), or null. The field must
     * have been indexed via setFields(..., indexed: [...]).
     *
     * An index is only a pointer: whoever can write the database can move it.
     * The account it names still authenticates.
     *
     * @throws InvalidArgumentException  on a field declared shared — it names no single account
     * @throws AmbiguousLookupException  when several accounts hold the value
     */
    public function findUserByField(string $fieldName, #[\SensitiveParameter] string $value): ?string
    {
        if (isset($this->shared[$fieldName])) {
            throw new InvalidArgumentException(
                "'{$fieldName}' is declared shared: it names no single account — use findUsersByField()"
            );
        }
        $userIds = $this->findUsersByField($fieldName, $value);
        if (count($userIds) > 1) {
            throw new AmbiguousLookupException(
                count($userIds) . " accounts hold this value of '{$fieldName}': the lookup names none of them"
            );
        }
        return $userIds[0] ?? null;
    }

    /**
     * Every account that holds a field value — the lookup for a shared field.
     * Each call tells whoever makes it how many accounts hold a value: expose
     * it behind authentication and a rate limit.
     *
     * @return list<string>
     */
    public function findUsersByField(string $fieldName, #[\SensitiveParameter] string $value): array
    {
        return $this->storage->findUserIdsByIndex($fieldName, BlindIndex::compute($value, $this->blindKey, $fieldName));
    }

    /**
     * Seal a lock with a new secret — a new password, a memorized word renewed,
     * the passphrase SelfRecover just issued, or a lock the vault did not have
     * yet. One gesture for all three: the session must be on the current vault.
     */
    public function setLock(UnlockedVault $session, Lock $lock, #[\SensitiveParameter] string $secret): void
    {
        $this->persist($this->vault->setLock($this->currentRecord($session), $session, $lock, $secret));
    }

    /**
     * Remove a lock. The password is replaced with setLock(), never removed.
     * ⚠️ Removing the memorized lock leaves SelfRecover's level 2 without the
     * data.
     *
     * @return bool false if the vault did not have that lock — then nothing is written
     */
    public function removeLock(UnlockedVault $session, Lock $lock): bool
    {
        $record  = $this->currentRecord($session);
        $removed = $this->vault->removeLock($record, $session, $lock);
        if ($removed === $record) {
            return false;
        }
        $this->persist($removed);
        return true;
    }

    // -- Re-enrolment and archives ---------------------------------------------

    /**
     * Which vault the userId holds now, read without any secret — for an
     * operator or a level-3 flow, never for a route a visitor reaches. Null
     * when the userId has no live vault.
     */
    public function generationOf(string $userId): ?VaultGeneration
    {
        $record = $this->storage->findVault($userId);
        if ($record === null) {
            return null;
        }
        $this->assertSameUser($userId, $record->userId);
        return $record->generation();
    }

    /**
     * Give the userId a new vault and set the one $replacing names aside as an
     * archive — SelfRecover's level 3, where no old secret is left to re-seal
     * with. Every Argon2id runs first, then one transaction archives and
     * inserts: a failure leaves the old vault live.
     *
     * Only the vault $replacing names is archived. A second call with the same
     * generation — a retry, or a concurrent request — throws
     * StaleVaultException instead of archiving the vault the first call made.
     * After a StaleVaultException, read the generation again before retrying:
     * the userId may already hold the vault that was meant.
     *
     * The new vault starts empty; the old data comes back through
     * openArchive(). A userId with no live vault goes through register().
     *
     * @return array{unlocked: UnlockedVault, archiveId: string}
     */
    public function reEnroll(
        VaultGeneration $replacing,
        #[\SensitiveParameter] string $newPassword,
        #[\SensitiveParameter] ?string $newMemorized = null,
        #[\SensitiveParameter] ?string $newPassphrase = null
    ): array {
        $result    = $this->vault->register($replacing->userId, $newPassword, $newMemorized, $newPassphrase);
        $record    = $this->sealed($result['record']);
        $archiveId = $this->storage->replaceWithArchive($replacing, $record);
        $this->assertVaultPersisted($record, 0);
        return ['unlocked' => $result['unlocked'], 'archiveId' => $archiveId];
    }

    /**
     * The archives of the account, from a session on its current vault: how
     * many times it was re-enrolled, and which locks open each archive, are
     * the holder's to know.
     *
     * @return list<array{id: string, archivedAt: \DateTimeImmutable, locks: list<Lock>}> oldest first
     */
    public function listArchives(UnlockedVault $current): array
    {
        $this->currentRecord($current);
        return $this->storage->listArchives($current->userId);
    }

    /**
     * Open an archive with one of its OLD locks — and only from a session on
     * the CURRENT vault. Old secrets are what may have leaked before a level-3
     * recovery: on their own, they must not reach the old data.
     *
     * Each call costs an Argon2id and nothing here counts failures: the
     * integrator rate-limits it as it does its login.
     *
     * The memorized lock of an archive taken at level 3 needs the SelfRecover
     * salt of that time, which level 3 replaced. Keep it — as a field of the
     * new vault, say — for that lock to stay usable.
     *
     * @throws StaleVaultException    if $current is not a session on the live vault
     * @throws VaultNotFoundException if this userId has no such archive
     */
    public function openArchive(
        UnlockedVault $current,
        string $archiveId,
        Lock $lock,
        #[\SensitiveParameter] string $oldSecret
    ): UnlockedArchive {
        $this->currentRecord($current);
        $archive = $this->storage->loadArchive($current->userId, $archiveId)
            ?? throw new VaultNotFoundException("No archive '{$archiveId}' for this account");
        $this->assertSameUser($current->userId, $archive->record->userId);
        if ($archive->record->profileSeal !== null) {
            $this->assertProfileSealed($archive->record, $archive->kdfOpslimit, $archive->kdfMemlimit);
        }
        $session = $this->vault->unlock(
            $archive->record,
            $lock,
            $oldSecret,
            $archive->kdfOpslimit,
            $archive->kdfMemlimit
        );
        return new UnlockedArchive($archive, $session, $current->generation());
    }

    /**
     * Decrypt an opened archive.
     *
     * To restore it, write the private fields back with the fields your
     * application indexes — not a list read from the archive, which whoever
     * writes the database can extend to a field never meant to be looked up.
     * A DuplicateIndexException names the values another account took since
     * the archive was made: restore those fields without their index, and
     * settle who owns the value.
     *
     * @return array{private: array<string, string>, escrow: array<string, string>}
     */
    public function readArchive(UnlockedArchive $opened): array
    {
        $archive = $opened->archive;
        $fields  = array_diff_key($archive->privateFields, [EscrowVault::WRAP_AAD_TAG => true]);
        $private = $opened->decryptFields(array_map(static fn (array $f): string => $f['ciphertext'], $fields));
        $escrow  = [];
        if ($archive->escrow !== null) {
            $unlocked = $opened->openEscrow($archive->escrow);
            $escrow   = EscrowFieldCrypter::decryptBatch($unlocked, $archive->escrowFields);
            $unlocked->lock();
        }
        return ['private' => $private, 'escrow' => $escrow];
    }

    /**
     * Delete one archive, opened with one of its old locks. A session on the
     * current vault is not enough: after a fraudulent level 3, that session is
     * the fraudster's, and the archives hold the real holder's data.
     */
    public function deleteArchive(UnlockedArchive $opened): bool
    {
        return $this->storage->deleteArchive($opened->openedFrom, $opened->archive->archiveId);
    }

    /**
     * Erase the account — its live vault and every archive — without a
     * session. Works on a userId left with archives only.
     *
     * Whoever exposes this decides who may call it: after a level-3 recovery,
     * the holder of the account is not necessarily the person the archives
     * belong to.
     *
     * @return int how many vaults were erased, live and archived
     */
    public function eraseAccount(string $userId): int
    {
        return $this->storage->eraseAccount($userId);
    }

    /**
     * Delete the live vault $generation names and all its encrypted fields.
     * Archives stay: see eraseAccount(). The holder passes
     * $session->generation(), an operator generationOf().
     */
    public function delete(VaultGeneration $generation): void
    {
        $this->storage->deleteVault($generation);
    }

    public function userExists(string $userId): bool
    {
        return $this->storage->vaultExists($userId);
    }

    /**
     * Seal the profile of a vault written before 0.7.0 — the operator's step,
     * once per vault, after upgrading and before serving: until then, the vault
     * opens to no one. It trusts the row as it stands, so it runs on a database
     * nobody else is writing to.
     *
     * @return bool true if a seal was written; false if the vault already had a
     *              valid one, or the userId has no live vault
     * @throws VaultTamperedException if the vault carries a seal that does not hold
     */
    public function sealProfile(string $userId): bool
    {
        $record = $this->storage->findVault($userId);
        if ($record === null) {
            return false;
        }
        $this->assertSameUser($userId, $record->userId);
        if ($record->profileSeal !== null) {
            $this->assertProfileSealed($record, $record->kdfOpslimit, $record->kdfMemlimit);
            return false;
        }
        $this->persist($this->sealed($record));
        return true;
    }

    /**
     * Put back the profile a vault's seal vouches for, after the database was
     * made to claim another: each published profile is tried against the seal,
     * an HMAC apiece — no Argon2id, no secret of the holder. For an operator,
     * after a VaultTamperedException; the holder's locks open again as they were.
     *
     * @return bool true if the true profile was written back; false if the seal already holds
     * @throws VaultTamperedException if the vault has no seal, or no published profile matches it
     */
    public function repairProfile(string $userId): bool
    {
        $record = $this->storage->loadVault($userId);
        $this->assertSameUser($userId, $record->userId);
        if ($record->profileSeal === null) {
            throw new VaultTamperedException("The vault of '{$userId}' carries no profile seal to repair against");
        }
        if (ProfileSeal::holds($this->sealKey, $record->profileSeal, $userId, $record->userSalt, $record->kdfOpslimit, $record->kdfMemlimit)) {
            return false;
        }
        foreach (Primitives::PUBLISHED_PROFILES as [$opslimit, $memlimit]) {
            if (ProfileSeal::holds($this->sealKey, $record->profileSeal, $userId, $record->userSalt, $opslimit, $memlimit)) {
                $this->persist($record->withProfile($opslimit, $memlimit));
                return true;
            }
        }
        throw new VaultTamperedException(
            "No published Argon2id profile matches the seal of '{$userId}': the seal itself was changed"
        );
    }

    // -- Escrow compartment (consented, admin-recoverable sub-vault) -----------

    /**
     * Generate a fresh admin recovery keypair, sealing the secret key under an
     * admin passphrase (deploy-server storage, SU model). Run ONCE at setup.
     *
     * Persist BOTH returned values: the public key (clear, needed to enroll
     * escrows) and the sealed secret (on the deployment server, opened only
     * during a recovery ceremony via the passphrase).
     *
     * @return array{publicKey: string, sealedSecret: string} both base64
     */
    public static function generateAdminRecoveryKey(#[\SensitiveParameter] string $passphrase): array
    {
        return AdminKey::generate($passphrase);
    }


    public function hasEscrow(UnlockedVault $session): bool
    {
        $this->currentRecord($session);
        return $this->storage->loadEscrow($session->userId) !== null;
    }

    /**
     * Encrypt and persist escrow fields for the active user. Creates the escrow
     * compartment on first use (sealed to $adminPublicKey); reuses it after, and
     * then ignores $adminPublicKey — rebindEscrowAdmin() is the one call that
     * re-seals an existing compartment.
     *
     * These fields are the CONSENTED, admin-recoverable subset (e.g.
     * contact_secours) — kept in a sub-key distinct from the private zone.
     *
     * @param array<string, string> $fields field_name => plaintext
     */
    public function setEscrowFields(UnlockedVault $session, string $adminPublicKey, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $this->currentRecord($session);

        $record   = $this->storage->loadEscrow($session->userId);
        $unlocked = null;
        if ($record === null) {
            $created = $this->escrow->create($session, $adminPublicKey);
            try {
                $this->storage->createEscrow($session->generation(), $created['record']);
                $unlocked = $created['unlocked'];
            } catch (EscrowExistsException) {
                // Another request created it first: its key is the one to write under.
                $created['unlocked']->lock();
                $record = $this->storage->loadEscrow($session->userId)
                    ?? throw new RuntimeException("The escrow of '{$session->userId}' was created, then is gone");
            }
        }
        $unlocked ??= $this->escrow->unlockAsUser($record, $session);

        $ciphertexts = EscrowFieldCrypter::encryptBatch($unlocked, $fields);
        $this->storage->saveEscrowFields($session->generation(), $ciphertexts);
        $unlocked->lock();
    }

    /**
     * Re-seal a wrap_admin sealed before 0.6.0, which names no account, in the form
     * that does. Returns false when there is no escrow, or when it is already bound.
     *
     * ⚠️ The wrap is re-sealed to $adminPublicKey. Nothing can tell, without the
     * admin secret key, which key the old wrap was sealed to: pass the key the
     * escrow was created with, or the administrator who held the old one loses
     * access to this compartment.
     */
    public function rebindEscrowAdmin(UnlockedVault $session, string $adminPublicKey): bool
    {
        $this->currentRecord($session);
        $record = $this->storage->loadEscrow($session->userId);
        if ($record === null || EscrowVault::isAccountBound($record)) {
            return false;
        }
        $unlocked = $this->escrow->unlockAsUser($record, $session);
        $rebound  = $this->escrow->rebindAdmin($record, $unlocked, $adminPublicKey);
        $unlocked->lock();
        $this->storage->replaceEscrow($session->generation(), $rebound);
        return true;
    }

    /**
     * Decrypt escrow fields as the USER (daily access) via the active session.
     *
     * @param array<string> $fieldNames Empty = all escrow fields
     * @return array<string, string>    field_name => plaintext
     */
    public function getEscrowFieldsAsUser(UnlockedVault $session, array $fieldNames = []): array
    {
        $this->currentRecord($session);
        $record = $this->storage->loadEscrow($session->userId);
        if ($record === null) {
            return [];
        }
        $unlocked    = $this->escrow->unlockAsUser($record, $session);
        $ciphertexts = $this->storage->loadEscrowFields($session->userId, $fieldNames);
        $plain       = EscrowFieldCrypter::decryptBatch($unlocked, $ciphertexts);
        $unlocked->lock();
        return $plain;
    }



    // -------------------------------------------------------------------------

    /**
     * The live vault this session was opened on — or StaleVaultException if
     * the userId has since been given another vault.
     *
     * Every read and write goes through it. A session from a replaced vault
     * would otherwise store fields the new key cannot read, create an escrow
     * under the old master key, or fail on read with an authentication error
     * that looks like corruption.
     */
    private function currentRecord(UnlockedVault $session): VaultRecord
    {
        $record = $this->load($session->userId);
        if (!hash_equals($record->userSalt, $session->vaultSalt)) {
            throw new StaleVaultException(
                'This session was opened on a vault that has since been replaced — unlock the current one'
            );
        }
        return $record;
    }

    /**
     * The live vault of $userId — refused if the storage answers with another
     * account's. A case-insensitive collation on user_id finds 'alice' when
     * asked for 'ALICE', and the vault would open for that other spelling.
     */
    private function load(string $userId): VaultRecord
    {
        $record = $this->storage->loadVault($userId);
        $this->assertSameUser($userId, $record->userId);
        $this->assertProfileSealed($record, $record->kdfOpslimit, $record->kdfMemlimit);
        return $record;
    }

    /**
     * Before any derivation, the profile is the one the seal vouches for. A
     * profile changed in the database fails here, as tampering, instead of
     * failing every lock as a wrong secret.
     */
    private function assertProfileSealed(VaultRecord $record, int $opslimit, int $memlimit): void
    {
        if ($record->profileSeal === null) {
            throw new VaultTamperedException(
                "The vault of '{$record->userId}' carries no profile seal: an operator seals the vaults written "
                . 'before 0.7.0 with sealProfile(), once, before serving'
            );
        }
        if (!ProfileSeal::holds($this->sealKey, $record->profileSeal, $record->userId, $record->userSalt, $opslimit, $memlimit)) {
            throw new VaultTamperedException(
                "The Argon2id profile of '{$record->userId}' does not match its seal: it was changed in the database"
                . ' — repairProfile() puts back the one the seal vouches for'
            );
        }
    }

    private function sealed(VaultRecord $record): VaultRecord
    {
        return $record->withProfileSeal(ProfileSeal::of(
            $this->sealKey,
            $record->userId,
            $record->userSalt,
            $record->kdfOpslimit,
            $record->kdfMemlimit
        ));
    }

    private function assertSameUser(string $asked, string $returned): void
    {
        if ($returned !== $asked) {
            throw new RuntimeException(
                'The storage returned the vault of another userId than the one asked for — '
                . 'does it compare user_id byte for byte?'
            );
        }
    }

    /**
     * Store an updated record, then read it back.
     */
    private function persist(VaultRecord $updated): void
    {
        $this->assertVaultPersisted($updated, $this->storage->updateVault($updated));
    }

    /**
     * A StorageInterface implementation can drop a column without a word — an
     * update that does not name it, say. Without wrap_phrase, the passphrase
     * SelfRecover just consumed would keep opening the vault, or the new one
     * would not; without the Argon2id profile, the vault would be read under the
     * current constants and stop opening the day they change. Read it back once.
     *
     * The salt is compared first, whatever the revision: another salt means the
     * storage holds another vault than the one written — an insert that
     * overwrote, an archive of the wrong generation. A higher revision than
     * $expectedRevision means a later write landed in between: then the salt is
     * all there is left to compare.
     */
    private function assertVaultPersisted(VaultRecord $written, int $expectedRevision): void
    {
        $stored = $this->storage->loadVault($written->userId);
        $this->assertSameUser($written->userId, $stored->userId);
        if (!hash_equals($written->userSalt, $stored->userSalt)) {
            throw new RuntimeException(
                'The storage holds another vault than the one written — does its saveVault() refuse '
                . 'an existing userId, and its replaceWithArchive() archive the generation it is given?'
            );
        }
        if ($stored->revision > $expectedRevision) {
            return;
        }
        if ($stored->revision < $expectedRevision) {
            throw new RuntimeException(
                "The storage did not persist revision {$expectedRevision} — does its updateVault() bump it?"
            );
        }
        $wraps = [
            'wrap_pwd'   => [$written->wrapPwd, $stored->wrapPwd],
            'wrap_recov' => [$written->wrapRecov, $stored->wrapRecov],
        ];
        foreach ($wraps as $column => [$asWritten, $asStored]) {
            if ($asStored?->toBase64() !== $asWritten?->toBase64()) {
                throw new RuntimeException("The storage did not persist {$column} as written");
            }
        }
        if ($stored->wrapPhrase?->toBase64() !== $written->wrapPhrase?->toBase64()) {
            throw new RuntimeException(
                'The storage did not persist wrap_phrase as written — '
                . 'does its saveVault(), updateVault() and replaceWithArchive() write that column?'
            );
        }
        if ($stored->kdfOpslimit !== $written->kdfOpslimit || $stored->kdfMemlimit !== $written->kdfMemlimit) {
            throw new RuntimeException(
                'The storage did not persist the Argon2id profile as written — '
                . 'do its saveVault() and replaceWithArchive() write kdf_opslimit and kdf_memlimit?'
            );
        }
        if ($stored->profileSeal !== $written->profileSeal) {
            throw new RuntimeException('The storage did not persist profile_seal as written');
        }
    }

    /** Keep the blind-index key and the seal key out of var_dump() and print_r(). */
    public function __debugInfo(): array
    {
        return ['blindKey' => '[redacted]', 'sealKey' => '[redacted]'];
    }
}
