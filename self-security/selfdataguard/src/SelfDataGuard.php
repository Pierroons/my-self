<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard;

use InvalidArgumentException;
use Pierroons\SelfDataGuard\Escrow\AdminKey;
use Pierroons\SelfDataGuard\Escrow\EscrowFieldCrypter;
use Pierroons\SelfDataGuard\Escrow\EscrowVault;
use Pierroons\SelfDataGuard\Fields\BlindIndex;
use Pierroons\SelfDataGuard\Fields\FieldCrypter;
use Pierroons\SelfDataGuard\Storage\StorageInterface;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\StaleVaultException;
use Pierroons\SelfDataGuard\Vault\UnlockedArchive;
use Pierroons\SelfDataGuard\Vault\UnlockedVault;
use Pierroons\SelfDataGuard\Vault\UserVault;
use Pierroons\SelfDataGuard\Vault\VaultNotFoundException;
use Pierroons\SelfDataGuard\Vault\VaultRecord;
use RuntimeException;

/**
 * Public façade for SelfDataGuard.
 *
 * Wraps the Vault + Fields + Storage layers behind a minimal API:
 *
 *     $dg = new SelfDataGuard($storage, $blindKey);
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
 *     ['unlocked' => $session, 'archiveId' => $id] = $dg->reEnroll('user-1', $pwd, $memorized, $passphrase);
 *
 *     // Find a user by an indexed field (no plaintext lookup needed)
 *     $userId = $dg->findUserByField('email', 'a@b.c');
 *
 * The blindKey is a server-side secret used to derive deterministic field
 * indexes (HMAC). Store it in env/Vault/etc., separate from any per-user
 * cryptographic material. ≥32 bytes of high-entropy random.
 */
final class SelfDataGuard
{
    private UserVault $vault;
    private EscrowVault $escrow;

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $blindKey
    ) {
        if (strlen($blindKey) < 32) {
            throw new InvalidArgumentException(
                'blindKey must be ≥32 bytes of server-side secret'
            );
        }
        $this->vault  = new UserVault();
        $this->escrow = new EscrowVault();
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
            throw new RuntimeException("User '{$userId}' already exists");
        }
        $result = $this->vault->register($userId, $password, $memorized, $passphrase);
        $this->storage->saveVault($result['record']);
        $this->assertPassphrasePersisted($result['record']);
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
        $record = $this->storage->loadVault($userId);
        return $this->vault->unlockWithPassword($record, $password);
    }

    /**
     * Authenticate by memorized secret (recovery flow).
     *
     * @throws Vault\WrongSecretException     on wrong secret
     * @throws Vault\MissingEnvelopeException on a vault without recovery wrap
     * @throws Vault\VaultNotFoundException   on missing user
     */
    public function loginWithMemorized(string $userId, #[\SensitiveParameter] string $memorized): UnlockedVault
    {
        $record = $this->storage->loadVault($userId);
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
     * Call it only after SelfRecover has accepted the recovery: on its own it
     * is an Argon2id oracle with no rate limit.
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
        $record  = $this->storage->loadVault($userId);
        $session = $this->vault->unlock($record, $lock, $secret);
        $record  = $this->vault->changePassword($record, $session, $newPassword);
        if ($newPassphrase !== null) {
            $record = $this->vault->changePassphrase($record, $session, $newPassphrase);
        }
        $this->storage->updateVault($record);
        $this->assertPassphrasePersisted($record);
        return $session;
    }

    /**
     * Encrypt and persist a batch of field values for the user.
     * Optionally compute and store blind indexes for lookup-able fields.
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
            $payload[$name] = [
                'ciphertext' => FieldCrypter::encrypt($session, $name, $value),
                'blindIndex' => isset($indexed[$name])
                    ? BlindIndex::compute($value, $this->blindKey, $name)
                    : null,
            ];
        }
        $this->storage->saveFields($session->userId, $payload);
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
        return FieldCrypter::decryptBatch($session, $cipher);
    }

    /**
     * Lookup a userId by a known field value (e.g. email).
     * The field must have been registered as indexed via setFields(..., indexed: [...]).
     *
     * @return string|null userId if found, null otherwise.
     */
    public function findUserByField(string $fieldName, #[\SensitiveParameter] string $value): ?string
    {
        $index = BlindIndex::compute($value, $this->blindKey, $fieldName);
        return $this->storage->findUserIdByBlindIndex($fieldName, $index);
    }

    /**
     * Re-seal the password wrap with a new password. Session must be active.
     */
    public function changePassword(UnlockedVault $session, #[\SensitiveParameter] string $newPassword): void
    {
        $rotated = $this->vault->changePassword($this->currentRecord($session), $session, $newPassword);
        $this->storage->updateVault($rotated);
    }

    /**
     * Re-seal the recovery wrap. Pass null to remove recovery entirely.
     */
    public function changeMemorized(UnlockedVault $session, #[\SensitiveParameter] ?string $newMemorized): void
    {
        $rotated = $this->vault->changeMemorized($this->currentRecord($session), $session, $newMemorized);
        $this->storage->updateVault($rotated);
    }

    /**
     * Seal the passphrase wrap with a new passphrase — after SelfRecover issued
     * one, or to add the lock to an existing vault.
     */
    public function changePassphrase(UnlockedVault $session, #[\SensitiveParameter] string $newPassphrase): void
    {
        $rotated = $this->vault->changePassphrase($this->currentRecord($session), $session, $newPassphrase);
        $this->storage->updateVault($rotated);
        $this->assertPassphrasePersisted($rotated);
    }

    public function removePassphrase(UnlockedVault $session): void
    {
        $rotated = $this->vault->removePassphrase($this->currentRecord($session), $session);
        $this->storage->updateVault($rotated);
        $this->assertPassphrasePersisted($rotated);
    }

    // -- Re-enrolment and archives ---------------------------------------------

    /**
     * Give the userId a new vault and set the current one aside as an archive
     * — SelfRecover's level 3, where no old secret is left to re-seal with.
     * Every Argon2id runs first, then one transaction archives and inserts:
     * a failure leaves the old vault live. With no live vault, it simply
     * creates one.
     *
     * The new vault starts empty; the old data comes back through
     * openArchive().
     *
     * @return array{unlocked: UnlockedVault, archiveId: ?string}
     */
    public function reEnroll(
        string $userId,
        #[\SensitiveParameter] string $newPassword,
        #[\SensitiveParameter] ?string $newMemorized = null,
        #[\SensitiveParameter] ?string $newPassphrase = null
    ): array {
        $result    = $this->vault->register($userId, $newPassword, $newMemorized, $newPassphrase);
        $archiveId = $this->storage->replaceWithArchive($result['record']);
        $this->assertPassphrasePersisted($result['record']);
        return ['unlocked' => $result['unlocked'], 'archiveId' => $archiveId];
    }

    /**
     * @return list<array{id: string, archivedAt: \DateTimeImmutable, locks: list<Lock>}> oldest first
     */
    public function listArchives(string $userId): array
    {
        return $this->storage->listArchives($userId);
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
        $session = $this->vault->unlock(
            $archive->record,
            $lock,
            $oldSecret,
            $archive->kdfOpslimit,
            $archive->kdfMemlimit
        );
        return new UnlockedArchive($archive, $session);
    }

    /**
     * Decrypt an opened archive. `indexed` names the fields that had a blind
     * index: hand it back to setFields() when restoring into the new vault.
     *
     * @return array{private: array<string, string>, escrow: array<string, string>, indexed: list<string>}
     */
    public function readArchive(UnlockedArchive $opened): array
    {
        $archive = $opened->archive;
        $private = FieldCrypter::decryptBatch(
            $opened->session(),
            array_map(static fn (array $f): string => $f['ciphertext'], $archive->privateFields)
        );
        $escrow = [];
        if ($archive->escrow !== null) {
            $unlocked = $this->escrow->unlockAsUser($archive->escrow, $opened->session());
            $escrow   = EscrowFieldCrypter::decryptBatch($unlocked, $archive->escrowFields);
            $unlocked->lock();
        }
        return [
            'private' => $private,
            'escrow'  => $escrow,
            'indexed' => array_keys(array_filter($archive->privateFields, static fn (array $f): bool => $f['wasIndexed'])),
        ];
    }

    /**
     * Delete one archive, from a session on the current vault.
     */
    public function deleteArchive(UnlockedVault $current, string $archiveId): bool
    {
        $this->currentRecord($current);
        return $this->storage->deleteArchive($current->userId, $archiveId);
    }

    /**
     * Delete every archive of the userId, without a session — account erasure.
     *
     * delete() leaves archives on purpose, and erasing an account calls both.
     * Whoever exposes this decides who may call it: after a level-3 recovery,
     * the holder of the account is not necessarily the person the archives
     * belong to.
     */
    public function purgeArchives(string $userId): int
    {
        return $this->storage->purgeArchives($userId);
    }

    /**
     * Delete the user's live vault and all its encrypted fields. Archives stay:
     * see purgeArchives().
     */
    public function delete(string $userId): void
    {
        $this->storage->deleteVault($userId);
    }

    public function userExists(string $userId): bool
    {
        return $this->storage->vaultExists($userId);
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

    /**
     * Unseal the admin recovery secret key with the passphrase. Returns the raw
     * 32-byte secret key — caller MUST sodium_memzero() it after use. Meant for
     * the recovery-ceremony CLI, not for web request paths.
     */
    public static function unsealAdminRecoveryKey(string $sealedSecret, #[\SensitiveParameter] string $passphrase): string
    {
        return AdminKey::unseal($sealedSecret, $passphrase);
    }

    public function hasEscrow(string $userId): bool
    {
        return $this->storage->loadEscrow($userId) !== null;
    }

    /**
     * Encrypt and persist escrow fields for the active user. Creates the escrow
     * compartment on first use (sealed to $adminPublicKey); reuses it after.
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

        $record = $this->storage->loadEscrow($session->userId);
        if ($record === null) {
            $created  = $this->escrow->create($session, $adminPublicKey);
            $record   = $created['record'];
            $unlocked = $created['unlocked'];
            $this->storage->saveEscrow($record);
        } else {
            $unlocked = $this->escrow->unlockAsUser($record, $session);
        }

        $ciphertexts = EscrowFieldCrypter::encryptBatch($unlocked, $fields);
        $this->storage->saveEscrowFields($session->userId, $ciphertexts);
        $unlocked->lock();
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

    /**
     * Decrypt escrow fields as the ADMIN during a recovery ceremony, using the
     * recovery secret key (from unsealAdminRecoveryKey) + public key.
     *
     * SCOPE: yields ONLY escrow fields, never the private zone. The caller
     * (adapter/CLI) is responsible for the POLICY gates — an open litige and
     * writing the SU audit log. This method performs no policy check itself.
     *
     * @param array<string> $fieldNames Empty = all escrow fields
     * @return array<string, string>    field_name => plaintext
     */
    public function getEscrowFieldsAsAdmin(
        string $userId,
        string $adminSecretKey,
        string $adminPublicKey,
        array $fieldNames = []
    ): array {
        $record = $this->storage->loadEscrow($userId);
        if ($record === null) {
            throw new RuntimeException("No escrow compartment for user '{$userId}'");
        }
        $unlocked    = $this->escrow->unlockAsAdmin($record, $adminSecretKey, $adminPublicKey);
        $ciphertexts = $this->storage->loadEscrowFields($userId, $fieldNames);
        $plain       = EscrowFieldCrypter::decryptBatch($unlocked, $ciphertexts);
        $unlocked->lock();
        return $plain;
    }

    /**
     * getEscrowFieldsAsAdmin() for an archived vault. A user who lost every
     * secret is the one the backup memo exists for, and level 3 is when that
     * happens. Same scope, same policy duty for the caller.
     *
     * @param array<string> $fieldNames Empty = all escrow fields
     * @return array<string, string>    field_name => plaintext
     */
    public function getArchiveEscrowFieldsAsAdmin(
        string $userId,
        string $archiveId,
        string $adminSecretKey,
        string $adminPublicKey,
        array $fieldNames = []
    ): array {
        $archive = $this->storage->loadArchive($userId, $archiveId)
            ?? throw new VaultNotFoundException("No archive '{$archiveId}' for user '{$userId}'");
        if ($archive->escrow === null) {
            throw new RuntimeException("No escrow compartment in archive '{$archiveId}'");
        }
        $ciphertexts = $fieldNames === []
            ? $archive->escrowFields
            : array_intersect_key($archive->escrowFields, array_flip($fieldNames));
        $unlocked = $this->escrow->unlockAsAdmin($archive->escrow, $adminSecretKey, $adminPublicKey);
        $plain    = EscrowFieldCrypter::decryptBatch($unlocked, $ciphertexts);
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
        $record = $this->storage->loadVault($session->userId);
        if (!hash_equals($record->userSalt, $session->vaultSalt)) {
            throw new StaleVaultException(
                'This session was opened on a vault that has since been replaced — unlock the current one'
            );
        }
        return $record;
    }

    /**
     * A StorageInterface written before 0.5.0 compiles fine and silently drops
     * wrap_phrase. The passphrase SelfRecover just consumed would then keep
     * opening the vault — or the new one would not. Read it back once.
     */
    private function assertPassphrasePersisted(VaultRecord $written): void
    {
        $stored = $this->storage->loadVault($written->userId)->wrapPhrase;
        if ($stored?->toBase64() !== $written->wrapPhrase?->toBase64()) {
            throw new RuntimeException(
                'The storage did not persist wrap_phrase as written — '
                . 'does this StorageInterface implementation predate SelfDataGuard 0.5.0?'
            );
        }
    }
}
