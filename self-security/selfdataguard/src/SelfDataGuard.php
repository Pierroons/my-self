<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard;

use DateTimeImmutable;
use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Escrow\AdminKey;
use Pierroons\SelfDataGuard\Escrow\EscrowExistsException;
use Pierroons\SelfDataGuard\Escrow\EscrowFieldCrypter;
use Pierroons\SelfDataGuard\Escrow\EscrowKeeper;
use Pierroons\SelfDataGuard\Escrow\EscrowKeeperException;
use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\Escrow\EscrowSnapshot;
use Pierroons\SelfDataGuard\Escrow\EscrowVault;
use Pierroons\SelfDataGuard\Escrow\KeeperAttestationException;
use Pierroons\SelfDataGuard\Escrow\KeeperSeal;
use Pierroons\SelfDataGuard\Escrow\NoKeeper;
use Pierroons\SelfDataGuard\Escrow\StaleEscrowException;
use Pierroons\SelfDataGuard\Escrow\UnlockedEscrow;
use Pierroons\SelfDataGuard\Fields\AmbiguousLookupException;
use Pierroons\SelfDataGuard\Fields\BlindIndex;
use Pierroons\SelfDataGuard\Fields\FieldCrypter;
use Pierroons\SelfDataGuard\Storage\DuplicateIndexException;
use Pierroons\SelfDataGuard\Storage\StorageInterface;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\ProfileSeal;
use Pierroons\SelfDataGuard\Vault\RecoveryTicketException;
use Pierroons\SelfDataGuard\Vault\StaleVaultException;
use Pierroons\SelfDataGuard\Vault\UnlockedArchive;
use Pierroons\SelfDataGuard\Vault\UnlockedVault;
use Pierroons\SelfDataGuard\Vault\UserVault;
use Pierroons\SelfDataGuard\Vault\VaultExistsException;
use Pierroons\SelfDataGuard\Vault\VaultFrozenException;
use Pierroons\SelfDataGuard\Vault\VaultGeneration;
use Pierroons\SelfDataGuard\Vault\VaultNotFoundException;
use Pierroons\SelfDataGuard\Vault\VaultRecord;
use Pierroons\SelfDataGuard\Vault\VaultTamperedException;
use RuntimeException;
use Throwable;

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

    /** @var array{sign: string, box: string}|null the public keys of the keeper this façade seals escrows to, once checked */
    private ?array $keeperKeys = null;

    /** How many times a deposit is redone over a rotation that landed between its read and its write. */
    private const DEPOSIT_ATTEMPTS = 3;

    /** Settings the façade claims in the database: what the first façade opened on it recorded. */
    public const SETTING_BLIND_KEY      = 'selfdataguard.blind-key-check';
    public const SETTING_SHARED_INDEXES = 'selfdataguard.shared-indexes';
    public const SETTING_KEEPER         = 'selfdataguard.escrow-keeper';
    public const SETTING_FORMER_KEEPERS = 'selfdataguard.escrow-keeper-former';
    public const SETTING_ESCROW_ADMIN   = 'selfdataguard.escrow-admin';

    /**
     * The first façade opened on an empty database records there which
     * blind-index key it holds and which fields it shares; every later one is
     * refused when it differs. Under another key, every seal would read as
     * tampering and every lookup would miss; under another list, one field would
     * be written under two rules. A database that already holds vaults and never
     * recorded a key — one written before 0.7.0 — is refused until an operator
     * adopts its key with adoptBlindKey(), which proves it first: a wrong key
     * recorded there would make every lookup miss in silence.
     *
     * @param list<string> $sharedIndexes the indexed fields several accounts may
     *        hold the same value of (a household phone). Every other indexed
     *        field names one account.
     * @param RecoveryTickets|null $tickets where reEnroll(), reinstateArchive(),
     *        freeze() and unfreeze() check that a recovery was accepted. Given,
     *        each of them refuses without a ticket of its kind for the userId,
     *        before any Argon2id; null, they take none.
     * @param EscrowKeeper|null $keeper the keeper escrows are sealed to, at each
     *        deposit: the administrator alone no longer opens them. The first
     *        façade given one records its fingerprint; a façade given another,
     *        or none, is then refused — without it, the next deposit would seal
     *        to the administrator alone. A keeper whose public keys do not come
     *        is checked at the first act that needs it instead: a vault opens
     *        while the keeper is down, a deposit does not go through. null or
     *        NoKeeper: no keeper, as before 0.9.0, on a database that recorded
     *        none.
     * @param list<EscrowKeeper> $formerKeepers keepers the deployment replaced
     *        (changeKeeper()), the database's record of them checked at use: a
     *        holder sees and objects to what one of them still holds announced,
     *        and the re-sealing of an escrow sealed to one retires it there
     *        first. A former keeper the database did not record is ignored.
     *
     * @throws InvalidArgumentException when the key, or the shared fields, are
     *         not the ones the database recorded, or when it recorded none and
     *         holds vaults
     */
    public function __construct(
        private readonly StorageInterface $storage,
        #[\SensitiveParameter] private readonly string $blindKey,
        array $sharedIndexes = [],
        private readonly ?RecoveryTickets $tickets = null,
        private readonly ?EscrowKeeper $keeper = null,
        private readonly array $formerKeepers = [],
    ) {
        if (strlen($blindKey) < 32) {
            throw new InvalidArgumentException(
                'blindKey must be ≥32 bytes of server-side secret'
            );
        }
        $declared = self::sharedList($sharedIndexes);
        foreach (json_decode($declared, true) as $fieldName) {
            $this->shared[$fieldName] = true;
        }
        $check   = BlindIndex::keyCheck($blindKey);
        $keyHeld = $storage->loadSetting(self::SETTING_BLIND_KEY);
        if ($keyHeld === null) {
            if ($storage->hasVaults()) {
                throw new InvalidArgumentException(
                    'This database holds vaults and never recorded its blind-index key: an operator adopts it '
                    . 'once with SelfDataGuard::adoptBlindKey(), which proves the key against what the database holds'
                );
            }
            $keyHeld = $storage->claimSetting(self::SETTING_BLIND_KEY, $check);
        }
        if (!hash_equals($keyHeld, $check)) {
            throw new InvalidArgumentException(
                'This blind-index key is not the one this database recorded: every profile seal and every '
                . 'blind index in it is computed under the recorded one'
            );
        }
        $recorded = $storage->loadSetting(self::SETTING_SHARED_INDEXES)
            ?? $storage->claimSetting(self::SETTING_SHARED_INDEXES, $declared);
        if ($recorded !== $declared) {
            throw new InvalidArgumentException(
                "This database shares the fields {$recorded}, and this façade was given {$declared}: "
                . 'every façade opened on one database receives the same sharedIndexes'
            );
        }
        $keeperHeld = $storage->loadSetting(self::SETTING_KEEPER);
        if ($keeper === null || $keeper instanceof NoKeeper) {
            if ($keeperHeld !== null) {
                throw new InvalidArgumentException(
                    "This database recorded the escrow keeper {$keeperHeld}: every façade opened on it is given that "
                    . 'keeper — without it, the next deposit would seal the escrow to the administrator alone'
                );
            }
        } else {
            try {
                $this->keeperKeys();
            } catch (EscrowKeeperException) {
                // Checked again at the first act that needs the keeper.
            }
        }
        $this->sealKey = ProfileSeal::key($blindKey);
        $this->vault   = new UserVault();
        $this->escrow  = new EscrowVault();
    }

    /**
     * Record the blind-index key of a database that holds vaults and never
     * recorded one — written before 0.7.0 — after proving it against what the
     * database already holds. The operator's step, once, before any façade
     * opens the database:
     *
     *   - $proofUserId, $proofField and $proofValue: the blind index of that
     *     value under this key must name that account — the lookup a database
     *     written under this key answers;
     *   - $proofUserId alone: that account's seal must hold under this key;
     *   - no proof: for a database where no field carries an index, so that
     *     the key decides no lookup yet. The operator vouches for it; a
     *     database that indexes a field refuses it.
     *
     * It then records the key and $sharedIndexes, which names every field that
     * carries a shared index in the database, as the first façade on an empty
     * database does. Adopting the key already recorded does nothing.
     *
     * @param list<string> $sharedIndexes
     * @throws InvalidArgumentException when the proof does not hold, or is
     *         missing on a database that indexes a field; when $sharedIndexes
     *         omits a field that carries shared indexes; when the database
     *         recorded another key
     */
    public static function adoptBlindKey(
        StorageInterface $storage,
        #[\SensitiveParameter] string $blindKey,
        array $sharedIndexes = [],
        ?string $proofUserId = null,
        ?string $proofField = null,
        #[\SensitiveParameter] ?string $proofValue = null
    ): void {
        if (strlen($blindKey) < 32) {
            throw new InvalidArgumentException('blindKey must be ≥32 bytes of server-side secret');
        }
        $check = BlindIndex::keyCheck($blindKey);
        $held  = $storage->loadSetting(self::SETTING_BLIND_KEY);
        if ($held !== null) {
            if (!hash_equals($held, $check)) {
                throw new InvalidArgumentException('This database already recorded another blind-index key');
            }
            return;
        }
        $indexed = $storage->indexedFields();
        if ($proofField !== null || $proofValue !== null) {
            if ($proofUserId === null || $proofField === null || $proofValue === null) {
                throw new InvalidArgumentException('A lookup proof names the account, the field and the value');
            }
            $index = BlindIndex::compute($proofValue, $blindKey, $proofField);
            if (!in_array($proofUserId, $storage->findUserIdsByIndex($proofField, $index), true)) {
                throw new InvalidArgumentException(
                    "Under this key, the value given for '{$proofField}' does not lead to '{$proofUserId}': the key, "
                    . 'the field name, the value — compared exactly, case and spaces included — or the account is not '
                    . 'the one the database indexed. Nothing was recorded'
                );
            }
        } elseif ($proofUserId !== null) {
            $record = $storage->loadVault($proofUserId);
            if ($record->profileSeal === null) {
                throw new InvalidArgumentException(
                    "'{$proofUserId}' carries no seal — a database written before 0.7.0 holds none: prove the key by a lookup"
                );
            }
            if (!ProfileSeal::holds(ProfileSeal::key($blindKey), $record->profileSeal, $record, $record->kdfOpslimit, $record->kdfMemlimit)) {
                throw new InvalidArgumentException(
                    "The seal of '{$proofUserId}' does not hold under this key: the key is not the one this database "
                    . 'sealed under, or the row was changed since. Nothing was recorded'
                );
            }
        } elseif ($indexed !== []) {
            throw new InvalidArgumentException(
                'This database indexes ' . implode(', ', array_keys($indexed)) . ', and the key decides every lookup '
                . 'of them: it is adopted only on proof — an account, one of these fields and its value'
            );
        }
        $unshared = array_diff(array_keys(array_filter($indexed)), json_decode(self::sharedList($sharedIndexes), true));
        if ($unshared !== []) {
            throw new InvalidArgumentException(
                'The fields ' . implode(', ', $unshared) . ' carry shared indexes in this database: sharedIndexes names them'
            );
        }
        if (!hash_equals($storage->claimSetting(self::SETTING_BLIND_KEY, $check), $check)) {
            throw new InvalidArgumentException('Another blind-index key was recorded meanwhile');
        }
        $storage->claimSetting(self::SETTING_SHARED_INDEXES, self::sharedList($sharedIndexes));
    }

    /**
     * Replace the keeper the database recorded — the operator's act. $from
     * joins the former keepers the database keeps a record of, so that a
     * façade and the ceremony given it still reach what is sealed to it: the
     * archives, and every escrow until its holder's next deposit re-seals it
     * to $to. Each deposit retires the escrow from every former keeper, and is
     * refused without it: the façades keep $from for as long as the database
     * records it. An escrow retired from a keeper never reads there again, so
     * $to is never a keeper replaced before, nor $from itself.
     *
     * @throws InvalidArgumentException when $from is not the keeper the database
     *         recorded, $to is $from or a keeper replaced before, the record of
     *         former keepers does not read, or another operator changed the
     *         record meanwhile
     */
    public static function changeKeeper(StorageInterface $storage, EscrowKeeper $from, EscrowKeeper $to): void
    {
        $fromPrint = KeeperSeal::fingerprint(KeeperSeal::checkKeys($from->publicKeys()));
        $toPrint   = KeeperSeal::fingerprint(KeeperSeal::checkKeys($to->publicKeys()));
        $held = $storage->loadSetting(self::SETTING_KEEPER);
        if ($held === null || !hash_equals($held, $fromPrint)) {
            throw new InvalidArgumentException("This database recorded the escrow keeper {$held}, not {$fromPrint}");
        }
        $formerHeld = $storage->loadSetting(self::SETTING_FORMER_KEEPERS);
        $former     = KeeperSeal::formerPrints($formerHeld);
        if (hash_equals($fromPrint, $toPrint) || in_array($toPrint, $former, true)) {
            throw new InvalidArgumentException(
                "The keeper {$toPrint} is the one replaced, or one replaced before: escrows were retired from it, and "
                . 'a keeper is never taken back — change to a keeper this database never had'
            );
        }
        if (!in_array($fromPrint, $former, true)) {
            $former[] = $fromPrint;
            $written  = json_encode($former, JSON_THROW_ON_ERROR);
            $recorded = $formerHeld === null
                ? hash_equals($storage->claimSetting(self::SETTING_FORMER_KEEPERS, $written), $written)
                : $storage->replaceSetting(self::SETTING_FORMER_KEEPERS, $formerHeld, $written);
            if (!$recorded) {
                throw new InvalidArgumentException('The record of former keepers changed meanwhile: nothing was changed');
            }
        }
        if (!$storage->replaceSetting(self::SETTING_KEEPER, $fromPrint, $toPrint)) {
            throw new InvalidArgumentException('The recorded keeper changed meanwhile: it is no longer ' . $fromPrint);
        }
    }

    /**
     * The shared fields as the database records them: sorted, without
     * duplicates, JSON.
     *
     * @param array<mixed> $sharedIndexes
     */
    private static function sharedList(array $sharedIndexes): string
    {
        foreach ($sharedIndexes as $fieldName) {
            if (!is_string($fieldName) || $fieldName === '') {
                throw new InvalidArgumentException('sharedIndexes lists field names');
            }
        }
        $shared = array_values(array_unique($sharedIndexes));
        sort($shared, SORT_STRING);
        return json_encode($shared, JSON_THROW_ON_ERROR);
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
        if ($this->storage->listArchives($userId) !== []) {
            throw new VaultExistsException(
                "User '{$userId}' still holds the archives of a deleted vault: they would open to whoever registers "
                . 'the name with an old secret. eraseAccount() erases them first — an operator\'s decision'
            );
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
     * Given RecoveryTickets, $ticket is the level-3 ticket SelfRecover issued
     * for this account; the archive is recorded under it (archiveOfTicket()),
     * and a ticket already applied throws TicketAlreadyAppliedException.
     *
     * @return array{unlocked: UnlockedVault, archiveId: string}
     */
    public function reEnroll(
        VaultGeneration $replacing,
        #[\SensitiveParameter] string $newPassword,
        #[\SensitiveParameter] ?string $newMemorized = null,
        #[\SensitiveParameter] ?string $newPassphrase = null,
        ?string $ticket = null,
    ): array {
        $this->requireTicket($ticket, $replacing->userId, RecoveryTickets::LEVEL_3);
        // The vault set aside keeps its seal: one that does not hold would make
        // an archive nothing can repair, as an unsealed one would make one
        // without a seal.
        $this->load($replacing->userId);
        $result    = $this->vault->register($replacing->userId, $newPassword, $newMemorized, $newPassphrase);
        $record    = $this->sealed($result['record']);
        $archiveId = $this->storage->replaceWithArchive($replacing, $record, $ticket);
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
        [$opslimit, $memlimit] = [$archive->kdfOpslimit, $archive->kdfMemlimit];
        if ($archive->record->profileSeal !== null || ProfileSeal::expected($archive->record)) {
            [$opslimit, $memlimit] = $this->vouchedProfile($archive->record, $opslimit, $memlimit);
        }
        $session = $this->vault->unlock($archive->record, $lock, $oldSecret, $opslimit, $memlimit);
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
     * Bring an archive back live in place of the vault $replacing names, which
     * becomes an archive in turn — SelfRecover's window after a level 3, when
     * the holder comes back with a secret from before it: their vault returns
     * with its fields, indexes and escrow, and the vault the requester was
     * given waits as an archive. No secret is asked: for the level-3 flow or an
     * operator, never for a route a visitor reaches.
     *
     * The archive comes back as it was archived, locks included: its old
     * secrets open it again. An archive whose seal does not hold is refused,
     * as its opening would be. One taken before 0.7.0, without a seal, comes
     * back unsealed, and opens to no one until sealProfile().
     *
     * @return string the id of the archive the replaced vault became
     * @throws VaultNotFoundException   if this userId has no such archive
     * @throws VaultTamperedException   if the archive's seal does not hold
     * @throws DuplicateIndexException  naming the fields whose index another account took since
     * @throws VaultFrozenException     if the vault $replacing names is frozen
     */
    public function reinstateArchive(VaultGeneration $replacing, string $archiveId, ?string $ticket = null): string
    {
        $this->requireTicket($ticket, $replacing->userId, RecoveryTickets::HOLDER_RETURN);
        $record = $this->sealedArchive($replacing->userId, $archiveId);
        $setAside = $this->storage->swapWithArchive($replacing, $archiveId, $ticket);
        $this->assertVaultPersisted($record, $record->revision);
        return $setAside;
    }

    /**
     * Bring an archive back live for a userId left without a live vault — an
     * operator's step, after the live vault was deleted. As reinstateArchive(),
     * the replaced vault apart.
     *
     * @throws VaultExistsException if the userId holds a live vault
     */
    public function restoreArchive(string $userId, string $archiveId): void
    {
        $record = $this->sealedArchive($userId, $archiveId);
        $this->storage->restoreArchive($userId, $archiveId);
        $this->assertVaultPersisted($record, $record->revision);
    }

    /**
     * Freeze the live vault $generation names: it keeps opening and reading,
     * and every write refuses with VaultFrozenException — fields, escrow,
     * locks, deletion, re-enrolment — until unfreeze(). eraseAccount() still
     * erases. SelfRecover freezes the vault a returning holder took back, until
     * the arbiter closes that window.
     *
     * @param string $by who froze it, as the caller names them (an arbiter, a case)
     * @return bool true if this call froze it; false if it was frozen already, by whoever froze it first
     */
    public function freeze(VaultGeneration $generation, string $by, ?string $ticket = null): bool
    {
        $this->requireTicket($ticket, $generation->userId, RecoveryTickets::HOLDER_RETURN);
        return $this->storage->freezeVault($generation, $by, new DateTimeImmutable(), $ticket);
    }

    /** @return bool true if the vault was frozen */
    public function unfreeze(VaultGeneration $generation, ?string $ticket = null): bool
    {
        $this->requireTicket($ticket, $generation->userId, RecoveryTickets::CLOSURE);
        return $this->storage->unfreezeVault($generation, $ticket);
    }

    /**
     * The archive a re-enrolment set aside under $ticket — the level-3 ticket a
     * returning holder's window keeps —, or null.
     */
    public function archiveOfTicket(string $ticket): ?string
    {
        return $this->storage->archiveOfTicket($ticket);
    }

    /**
     * Who froze the live vault of $userId, and when; null when it is not frozen.
     *
     * @return array{by: string, at: DateTimeImmutable}|null
     */
    public function frozenState(string $userId): ?array
    {
        return $this->storage->frozenState($userId);
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
        if ($record->profileSeal !== null || ProfileSeal::expected($record)) {
            $this->assertProfileSealed($record, $record->kdfOpslimit, $record->kdfMemlimit);
            return false;
        }
        $this->persist($record);
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
        if (ProfileSeal::holds($this->sealKey, $record->profileSeal, $record, $record->kdfOpslimit, $record->kdfMemlimit)) {
            return false;
        }
        foreach (Primitives::PUBLISHED_PROFILES as [$opslimit, $memlimit]) {
            if (ProfileSeal::holds($this->sealKey, $record->profileSeal, $record, $opslimit, $memlimit)) {
                $this->persist($record->withProfile($opslimit, $memlimit));
                return true;
            }
        }
        throw new VaultTamperedException(
            "No published Argon2id profile matches the seal of '{$userId}': the seal itself was changed, "
            . 'or an envelope was — restore the row from a backup'
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
     * Encrypt and persist escrow fields for the active user, under a NEW escrow
     * key: every deposit draws one, re-encrypts the fields already there with
     * the new ones, and replaces the envelope in the same write
     * (StorageInterface::rotateEscrow()). A key the administrator obtained by a
     * ceremony, or kept from a copy of the database, opens nothing deposited
     * after it — a read the keeper still holds announced hands back the
     * compartment as it stands then. The compartment keeps its escrowId, so
     * that the keeper's record of it follows.
     *
     * Under a keeper, the escrow is first retired from every former keeper the
     * database recorded; nothing is written when the vault is frozen, or when
     * one of them was not given to this façade or does not take it.
     *
     * wrap_admin is sealed to $adminPublicKey at each deposit, and, with a
     * keeper, sealed again to it. The first deposit records which key; a
     * deposit given another is refused — it would take every compartment it
     * touches from the administrator who holds the recorded one. The key
     * changes by StorageInterface::replaceSetting(), the operator's act.
     *
     * These fields are the CONSENTED, admin-recoverable subset (e.g.
     * contact_secours) — kept in a sub-key distinct from the private zone.
     *
     * @param array<string, string> $fields field_name => plaintext
     * @throws StaleEscrowException when other deposits kept landing between this one's read and its write
     * @throws VaultFrozenException when a former keeper is recorded and the vault is frozen
     * @throws EscrowKeeperException keeper-mismatch — a former keeper not given, a record of former keepers
     *         unreadable or that lists the façade's own keeper —, or keeper-failed — a former keeper that does
     *         not take the retirement
     */
    public function setEscrowFields(UnlockedVault $session, string $adminPublicKey, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $this->currentRecord($session);
        $this->deposit($session, $adminPublicKey, $fields);
    }

    /**
     * Draw a new escrow key for the compartment, the fields kept: a key
     * obtained before opens nothing deposited after, and the compartment
     * follows a keeper the deployment changed. It does not stop a read the
     * keeper holds announced — that read hands back the compartment as it
     * stands then —: objectToEscrowRead() does. It is a deposit: it retires
     * the escrow from the former keepers first, and is refused as
     * setEscrowFields() is. False when there is no escrow.
     */
    public function rotateEscrow(UnlockedVault $session, string $adminPublicKey): bool
    {
        $this->currentRecord($session);
        if ($this->storage->loadEscrow($session->userId) === null) {
            return false;
        }
        $this->deposit($session, $adminPublicKey, []);
        return true;
    }

    /**
     * Seal the compartment to the façade's keeper, if it is not yet — sealed
     * before 0.9.0, sealed to another keeper, or wrapped by the operator's pass,
     * whose wrap_user still names no keeper. A new key is drawn, as at a
     * deposit. False when there is nothing to do: no keeper, no escrow, or one
     * already sealed to this keeper.
     */
    public function sealEscrowToKeeper(UnlockedVault $session, string $adminPublicKey): bool
    {
        $this->currentRecord($session);
        $record = $this->storage->loadEscrow($session->userId);
        if (!$this->hasKeeper() || $record === null) {
            return false;
        }
        $unlocked = $this->escrow->unlockAsUser($record, $session);
        try {
            if (EscrowVault::isKeeperSealed($record) && $this->boundToOurKeeper($unlocked)) {
                return false;
            }
        } finally {
            $unlocked->lock();
        }
        $this->deposit($session, $adminPublicKey, []);
        return true;
    }

    /**
     * What the keeper released of this account's escrow, and what it holds
     * announced, as it signs it for this session — read when the holder opens
     * their vault. The keeper is asked about the escrowId the session computes
     * from the master key, not the one the row names. `established` false
     * always says why (`reason`); it is never an empty list: an attestation
     * that does not arrive, or does not verify, is not the same as no read.
     *
     *   - reason `not-keeper-sealed`: the administrator alone can read it;
     *   - `not-pinned`: the operator's pass sealed it to a keeper, and no
     *     deposit from the holder's session named it since — the escrowId would
     *     come from the clear prefix of wrap_admin, which whoever writes the
     *     database rewrites;
     *   - `keeper-changed`: the deposit named another keeper than the façade's,
     *     and no former keeper answers for it — the façade was not given it,
     *     the database did not record it as one (a keeper changed by hand, not
     *     by changeKeeper()), or it gives no public keys; what it holds stays
     *     with it, out of view. Also when the row names no keeper for the
     *     escrowId, and a former keeper the database recorded was not given;
     *   - `keeper-mismatch`: the façade's keeper, whose keys came after it was
     *     built, is not the one the database recorded; or the record of former
     *     keepers does not read;
     *   - `retired`: the keeper the row pins retired the escrow, and releases
     *     nothing more — a deposit retired it there and did not land, or the
     *     row was put back from a backup;
     *   - `escrow-replaced`: the row names another escrowId; or it is absent,
     *     not keeper-sealed or not pinned, and a keeper holds reads of the
     *     escrowId the session computes — the row was deleted, put back from a
     *     backup or rewritten;
     *   - `no-keeper`, `keeper-unreachable`, and `unreadable`, `signature`,
     *     `escrow`, `nonce` (KeeperAttestationException);
     *   - `reads`, `pending`: with `established`, `retired` and
     *     `escrow-replaced`, what every keeper asked holds. When the row pins a
     *     former keeper, or none, the façade's and every former keeper the
     *     database recorded are asked too; `pending` leaves out a keeper that
     *     retired the escrow;
     *   - `pinned`: the keeper was named by the holder's own deposit;
     *   - `reseal`: the integrator should call sealEscrowToKeeper(), which is
     *     refused while a former keeper the database recorded is not given to
     *     the façade. False for an absent row: a deposit writes one.
     *
     * A release is the keeper handing the inner box back: the read that
     * follows may still fail — a wrong passphrase —, and is listed all the
     * same.
     *
     * @return array{established: bool, reason: ?string, keeper: ?string, pinned: bool,
     *               reads: list<array{case: string, seq: int, at: int}>,
     *               pending: list<array{case: string, due: int, objected: bool}>, reseal: bool}
     */
    public function escrowReads(UnlockedVault $session): array
    {
        $this->currentRecord($session);
        $record = $this->storage->loadEscrow($session->userId);
        return $this->readsOf($record, fn (EscrowRecord $r): UnlockedEscrow => $this->escrow->unlockAsUser($r, $session), false, $session->escrowId());
    }

    /**
     * escrowReads() for the escrow of an archive opened with one of its old
     * locks. `reseal` is always false: no act re-seals an archive's escrow.
     */
    public function archiveEscrowReads(UnlockedArchive $opened): array
    {
        return $this->readsOf($opened->archive->escrow, fn (EscrowRecord $r): UnlockedEscrow => $opened->openEscrow($r), true, $opened->escrowId());
    }

    /**
     * Object to the read the keeper holds announced for case $caseRef: signed
     * from the session under the escrow's objection key, which only the master
     * key derives — whatever the database holds —, so that the administrator
     * cannot object, or withdraw an objection, in the holder's place. It goes
     * to every keeper that holds the case announced, among the one the row
     * pins and, when that is not the façade's, the façade's and every former
     * keeper the database recorded. Throws RuntimeException when the façade
     * was given no keeper; EscrowKeeperException when a keeper cannot be asked,
     * when one that holds the case does not record the objection, or when none
     * holds it.
     */
    public function objectToEscrowRead(UnlockedVault $session, string $caseRef): void
    {
        $this->currentRecord($session);
        $escrowId = $session->escrowId();
        [$pinned] = $this->rowOf($this->storage->loadEscrow($session->userId), fn (EscrowRecord $r): UnlockedEscrow => $this->escrow->unlockAsUser($r, $session), $escrowId);
        $this->objectAt($pinned, $escrowId, $caseRef, $session->signEscrowObjection($caseRef));
    }

    /**
     * objectToEscrowRead() for the escrow of an archive opened with one of its
     * old locks: an archive's read waits the delay like any other, and its
     * objection key derives from the archived vault's own master key.
     */
    public function objectToArchiveEscrowRead(UnlockedArchive $opened, string $caseRef): void
    {
        $escrowId = $opened->escrowId();
        [$pinned] = $this->rowOf($opened->archive->escrow, fn (EscrowRecord $r): UnlockedEscrow => $opened->openEscrow($r), $escrowId);
        $this->objectAt($pinned, $escrowId, $caseRef, $opened->signEscrowObjection($caseRef));
    }

    /**
     * Re-seal a wrap_admin sealed before 0.6.0, which names no account, in the form
     * that does. Returns false when there is no escrow, or when it is already bound.
     *
     * Under a keeper, such a wrap_admin is never re-sealed to the administrator
     * alone — one written over a keeper's box would hand them the escrow key:
     * the compartment is sealed to the keeper instead, under a new key, as
     * sealEscrowToKeeper() does.
     *
     * ⚠️ The wrap is re-sealed to $adminPublicKey. Nothing can tell, without the
     * admin secret key, which key the old wrap was sealed to: pass the key the
     * escrow was created with, or the administrator who held the old one loses
     * access to this compartment.
     *
     * @throws \Pierroons\SelfDataGuard\Escrow\StaleEscrowException when another request re-sealed it after this one read it
     * @throws InvalidArgumentException when $adminPublicKey is not the one the database recorded
     */
    public function rebindEscrowAdmin(UnlockedVault $session, string $adminPublicKey): bool
    {
        $this->currentRecord($session);
        $this->assertEscrowAdmin($adminPublicKey);
        $record = $this->storage->loadEscrow($session->userId);
        if ($record === null || EscrowVault::isAccountBound($record)) {
            return false;
        }
        if ($this->hasKeeper()) {
            return $this->sealEscrowToKeeper($session, $adminPublicKey);
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
        $read = EscrowSnapshot::read($this->storage, $session->userId, $fieldNames);
        if ($read === null) {
            return [];
        }
        [$record, $ciphertexts] = $read;
        $unlocked = $this->escrow->unlockAsUser($record, $session);
        try {
            return EscrowFieldCrypter::decryptBatch($unlocked, $ciphertexts);
        } finally {
            $unlocked->lock();
        }
    }



    /**
     * $fields deposited over the escrow under a new key, in one write; the
     * first deposit too, so that a rotation landing meanwhile is not written
     * over. Redone when another deposit landed between the read and the write.
     *
     * @param array<string, string> $fields field_name => plaintext
     */
    private function deposit(UnlockedVault $session, string $adminPublicKey, array $fields): void
    {
        $this->assertEscrowAdmin($adminPublicKey);
        $keys = $this->keeperKeys();
        if ($keys !== null) {
            $this->retireFromFormerKeepers($session, $keys);
        }
        for ($attempt = 1; ; $attempt++) {
            $read = EscrowSnapshot::read($this->storage, $session->userId);
            if ($read === null) {
                $first = $this->escrow->create($session, $adminPublicKey, $keys);
                try {
                    $this->storage->createEscrow($session->generation(), $first['record']);
                } catch (EscrowExistsException) {
                    continue;
                } finally {
                    $first['unlocked']->lock();
                }
                $read = EscrowSnapshot::read($this->storage, $session->userId)
                    ?? throw new RuntimeException("The escrow of '{$session->userId}' was created, then is gone");
            }
            [$record, $ciphertexts] = $read;
            $old = $this->escrow->unlockAsUser($record, $session);
            try {
                $kept = EscrowFieldCrypter::decryptBatch($old, $ciphertexts);
            } finally {
                $old->lock();
            }
            $new = $this->escrow->create($session, $adminPublicKey, $keys, $record->createdAt, $record->revision);
            try {
                $this->storage->rotateEscrow(
                    $session->generation(),
                    $new['record'],
                    EscrowFieldCrypter::encryptBatch($new['unlocked'], array_replace($kept, $fields))
                );
                return;
            } catch (StaleEscrowException $e) {
                if ($attempt >= self::DEPOSIT_ATTEMPTS) {
                    throw $e;
                }
            } finally {
                $new['unlocked']->lock();
            }
        }
    }

    /**
     * $adminPublicKey is the one the database seals escrows to: recorded at the
     * first deposit, compared at every one after.
     */
    private function assertEscrowAdmin(string $adminPublicKey): void
    {
        $print = substr(hash('sha256', "selfdataguard/escrow-admin/v1\0" . EscrowVault::decodePublicKey($adminPublicKey)), 0, 32);
        $held  = $this->storage->loadSetting(self::SETTING_ESCROW_ADMIN)
            ?? $this->storage->claimSetting(self::SETTING_ESCROW_ADMIN, $print);
        if (!hash_equals($held, $print)) {
            throw new InvalidArgumentException(
                "Escrows here are sealed to the administrator key {$held}, and this call was given {$print}: each "
                . 'deposit re-seals the compartment to the key it is given, so another would take it from the '
                . 'administrator who holds the recorded one. The key changes by StorageInterface::replaceSetting()'
            );
        }
    }

    private function hasKeeper(): bool
    {
        return $this->keeper !== null && !$this->keeper instanceof NoKeeper;
    }

    /**
     * The keeper's public keys, checked against the fingerprint the database
     * recorded — the first façade to obtain them records it. null without a
     * keeper.
     *
     * @return array{sign: string, box: string}|null
     * @throws EscrowKeeperException    keeper-unreachable, when its public keys do not come
     * @throws InvalidArgumentException when it is not the keeper the database recorded
     */
    private function keeperKeys(): ?array
    {
        if ($this->keeperKeys !== null || !$this->hasKeeper()) {
            return $this->keeperKeys;
        }
        try {
            $keys = KeeperSeal::checkKeys($this->keeper->publicKeys());
        } catch (Throwable $e) {
            throw new EscrowKeeperException('keeper-unreachable', 'The keeper gave no public keys: nothing is sealed to it', $e);
        }
        $print = KeeperSeal::fingerprint($keys);
        $held  = $this->storage->loadSetting(self::SETTING_KEEPER) ?? $this->storage->claimSetting(self::SETTING_KEEPER, $print);
        if (!hash_equals($held, $print)) {
            throw new InvalidArgumentException(
                "This database recorded the escrow keeper {$held}, and this façade was given {$print}: "
                . 'the keeper changes by SelfDataGuard::changeKeeper(), the operator\'s act, and each '
                . 'escrow follows at its next deposit or rotateEscrow()'
            );
        }
        return $this->keeperKeys = $keys;
    }

    /** Whether $escrow names, in its own wrap_user, the keeper this façade was given. */
    private function boundToOurKeeper(UnlockedEscrow $escrow): bool
    {
        $ours = $this->keeperKeys();
        return $escrow->keeper !== null && $ours !== null && $this->samePrint($escrow->keeper, $ours);
    }

    /**
     * @param array{sign: string, box: string} $a
     * @param array{sign: string, box: string} $b
     */
    private function samePrint(array $a, array $b): bool
    {
        return hash_equals(KeeperSeal::fingerprint($a), KeeperSeal::fingerprint($b));
    }

    /** @param array{sign: string, box: string} $keys */
    private function samePrintAs(string $print, array $keys): bool
    {
        return hash_equals(KeeperSeal::fingerprint($keys), $print);
    }

    /**
     * The former keeper this façade was given whose fingerprint is $print,
     * with the keys it gave, when the database recorded it as one; null
     * otherwise, or when it gives no keys.
     *
     * @return ?array{0: EscrowKeeper, 1: array{sign: string, box: string}}
     * @throws InvalidArgumentException when the record of former keepers is unreadable
     */
    private function formerKeeperFor(string $print): ?array
    {
        if (!in_array($print, KeeperSeal::formerPrints($this->storage->loadSetting(self::SETTING_FORMER_KEEPERS)), true)) {
            return null;
        }
        foreach ($this->formerKeepers as $former) {
            try {
                $keys = KeeperSeal::checkKeys($former->publicKeys());
            } catch (Throwable) {
                continue;
            }
            if (hash_equals(KeeperSeal::fingerprint($keys), $print)) {
                return [$former, $keys];
            }
        }
        return null;
    }

    /**
     * The escrow of $session retired from every keeper the database recorded
     * as former, before anything is written: none of them serves it again.
     * Refused, nothing retired, when the vault is frozen, when the record lists
     * the façade's own keeper, or when one of them was not given to this
     * façade. Refused, nothing written, when one does not take the retirement:
     * those asked before it took it, and the view says `retired` until a
     * deposit goes through.
     *
     * @param array{sign: string, box: string} $keys the façade's keeper
     */
    private function retireFromFormerKeepers(UnlockedVault $session, array $keys): void
    {
        try {
            $prints = KeeperSeal::formerPrints($this->storage->loadSetting(self::SETTING_FORMER_KEEPERS));
        } catch (InvalidArgumentException $e) {
            throw new EscrowKeeperException('keeper-mismatch', $e->getMessage() . ' — nothing is retired, nothing re-sealed', $e);
        }
        if ($prints === []) {
            return;
        }
        if ($this->storage->frozenState($session->userId) !== null) {
            throw new VaultFrozenException("The vault of '{$session->userId}' is frozen: nothing is retired, nothing re-sealed");
        }
        if (in_array(KeeperSeal::fingerprint($keys), $prints, true)) {
            throw new EscrowKeeperException(
                'keeper-mismatch',
                'The record of former keepers lists the façade\'s own keeper: it was changed in the database — nothing is retired, nothing re-sealed'
            );
        }
        $formers = [];
        foreach ($prints as $print) {
            $formers[$print] = ($this->formerKeeperFor($print) ?? throw new EscrowKeeperException(
                'keeper-mismatch',
                "This database recorded the former keeper {$print}, and this façade was not given it, or it gives no keys: "
                . 'the escrow cannot be retired from it — nothing is retired, nothing re-sealed'
            ))[0];
        }
        foreach ($formers as $print => $former) {
            try {
                $former->retire($session->escrowId(), $session->signEscrowRetirement($print));
            } catch (Throwable $e) {
                throw new EscrowKeeperException('keeper-failed', "The former keeper {$print} did not take the retirement: nothing is re-sealed", $e);
            }
        }
    }

    /**
     * The keepers asked about the escrow whose row pins $pinned. The façade's
     * alone when the row pins it: the deposit that wrote that row retired the
     * escrow from every former keeper first. Otherwise, the former keeper the
     * row pins, when it pins one, then the façade's and every other former
     * keeper the database recorded: a row put back from a backup, or one that
     * pins no keeper (rowOf(): absent, not-keeper-sealed, not-pinned,
     * escrow-replaced), says nothing of where the escrow is sealed now. The
     * first is the keeper the escrow answers to.
     *
     * @param ?array{sign: string, box: string} $pinned the keys the row pins, when it names the escrowId the session computes
     * @return list<array{0: EscrowKeeper, 1: array{sign: string, box: string}}>
     * @throws EscrowKeeperException keeper-unreachable; keeper-mismatch, the record of former keepers unreadable;
     *         keeper-changed, a keeper to ask not given
     */
    private function keepersOf(?array $pinned): array
    {
        try {
            $ours = (array) $this->keeperKeys();
            if ($pinned !== null && $this->samePrint($pinned, $ours)) {
                return [[$this->keeper, $ours]];
            }
            $first   = $pinned === null ? null : KeeperSeal::fingerprint($pinned);
            $keepers = [];
            if ($first !== null) {
                $keepers[] = $this->formerKeeperFor($first) ?? throw new EscrowKeeperException(
                    'keeper-changed',
                    'The keeper this escrow names is not the façade\'s, nor a former one it was given'
                );
            }
            $keepers[] = [$this->keeper, $ours];
            foreach (KeeperSeal::formerPrints($this->storage->loadSetting(self::SETTING_FORMER_KEEPERS)) as $print) {
                if ($print !== $first && !$this->samePrintAs($print, $ours)) {
                    $keepers[] = $this->formerKeeperFor($print) ?? throw new EscrowKeeperException(
                        'keeper-changed',
                        "This database recorded the former keeper {$print}, and this façade was not given it"
                    );
                }
            }
            return $keepers;
        } catch (InvalidArgumentException $e) {
            throw new EscrowKeeperException('keeper-mismatch', $e->getMessage(), $e);
        }
    }

    /**
     * What $keeper signs for escrow $escrowId, verified under $keys.
     *
     * @param array{sign: string, box: string} $keys
     * @return array{releases: list<array{case: string, seq: int, at: int}>,
     *               pending: list<array{case: string, due: int, objected: bool}>, retired: bool}
     * @throws EscrowKeeperException keeper-unreachable, or the reason of KeeperAttestationException
     */
    private function attestAt(EscrowKeeper $keeper, array $keys, string $escrowId): array
    {
        $nonce = random_bytes(16);
        try {
            $attestation = $keeper->attest($escrowId, $nonce);
        } catch (Throwable $e) {
            throw new EscrowKeeperException('keeper-unreachable', 'The keeper gave no attestation', $e);
        }
        try {
            return KeeperSeal::verifyAttestation($attestation, $keys['sign'], $escrowId, $nonce);
        } catch (KeeperAttestationException $e) {
            throw new EscrowKeeperException($e->reason, $e->getMessage(), $e);
        }
    }

    /**
     * The keys $record pins to escrow $escrowId, and the state of the row when
     * it pins none: absent, not-keeper-sealed, not-pinned, escrow-replaced.
     *
     * @param callable(EscrowRecord): UnlockedEscrow $open
     * @return array{0: ?array{sign: string, box: string}, 1: ?string}
     */
    private function rowOf(?EscrowRecord $record, callable $open, string $escrowId): array
    {
        if ($record === null) {
            return [null, 'absent'];
        }
        if (!EscrowVault::isKeeperSealed($record)) {
            return [null, 'not-keeper-sealed'];
        }
        $unlocked = $open($record);
        try {
            $rowId = (string) $unlocked->escrowId;
            $keys  = $unlocked->keeper;
        } finally {
            $unlocked->lock();
        }
        if ($keys === null) {
            return [null, 'not-pinned'];
        }
        return hash_equals($rowId, $escrowId) ? [$keys, null] : [null, 'escrow-replaced'];
    }

    /**
     * The objection to case $caseRef, signed with $token, recorded by every
     * keeper of keepersOf() that holds a read announced for that case. Throws
     * RuntimeException when the façade was given no keeper; EscrowKeeperException
     * when one of them cannot be asked or does not record it, or when none
     * holds the case announced.
     *
     * @param ?array{sign: string, box: string} $pinned
     */
    private function objectAt(?array $pinned, string $escrowId, string $caseRef, string $token): void
    {
        if (!$this->hasKeeper()) {
            throw new RuntimeException('This façade was given no keeper: there is no read to object to');
        }
        $recorded = 0;
        foreach ($this->keepersOf($pinned) as [$keeper, $keys]) {
            $announced = array_filter($this->attestAt($keeper, $keys, $escrowId)['pending'], static fn (array $p): bool => $p['case'] === $caseRef);
            if ($announced === []) {
                continue;
            }
            try {
                $keeper->object($escrowId, $caseRef, $token);
            } catch (Throwable $e) {
                throw new EscrowKeeperException('keeper-failed', 'The keeper did not record the objection: the read it names may go through once due', $e);
            }
            $recorded++;
        }
        if ($recorded === 0) {
            throw new EscrowKeeperException('keeper-failed', "No keeper of this escrow holds a read announced for case '{$caseRef}': there is nothing to object to");
        }
    }

    /**
     * The view of an escrow whose escrowId, computed from the master key, is
     * $escrowId. Under a keeper, the keepers of keepersOf() are asked about
     * that escrowId, whatever the row holds: a row that is absent, put back
     * from a backup or rewritten cannot hide what a keeper released or holds
     * announced.
     *
     * @param callable(EscrowRecord): UnlockedEscrow $open
     * @return array<string, mixed> see escrowReads()
     */
    private function readsOf(?EscrowRecord $record, callable $open, bool $archive, string $escrowId): array
    {
        $out    = ['established' => false, 'reason' => null, 'keeper' => null, 'pinned' => false, 'reads' => [], 'pending' => [], 'reseal' => false];
        $reseal = !$archive && $this->hasKeeper();
        [$pinned, $row] = $this->rowOf($record, $open, $escrowId);
        if ($pinned !== null) {
            $out = ['pinned' => true, 'keeper' => KeeperSeal::fingerprint($pinned)] + $out;
        }
        if (!$this->hasKeeper()) {
            return match ($row) {
                'absent' => ['established' => true] + $out,
                null     => ['reason' => 'no-keeper'] + $out,
                default  => ['reason' => $row] + $out,
            };
        }
        $views = [];
        try {
            $keepers = $this->keepersOf($pinned);
            foreach ($keepers as [$keeper, $keys]) {
                $views[] = $this->attestAt($keeper, $keys, $escrowId);
            }
        } catch (EscrowKeeperException $e) {
            return ['reason' => $e->reason, 'reseal' => $e->reason === 'keeper-changed' && $reseal] + $out;
        }
        $out = ['keeper' => KeeperSeal::fingerprint($keepers[0][1])] + $out;
        if ($keepers[0][0] !== $this->keeper) {
            $out = ['reseal' => $reseal] + $out;
        }
        $reads = $pending = [];
        foreach ($views as $view) {
            $reads = [...$reads, ...$view['releases']];
            if (!$view['retired']) {
                $pending = [...$pending, ...$view['pending']];
            }
        }
        $seen = ['reads' => $reads, 'pending' => $pending];
        if ($views[0]['retired']) {
            return ['reason' => 'retired', 'reseal' => $reseal && $row !== 'absent'] + $seen + $out;
        }
        if ($row === null) {
            return ['established' => true] + $seen + $out;
        }
        if ($reads !== [] || $pending !== [] || $row === 'escrow-replaced') {
            return ['reason' => 'escrow-replaced', 'reseal' => $reseal && $row !== 'absent'] + $seen + $out;
        }
        return $row === 'absent'
            ? ['established' => true, 'keeper' => null] + $out
            : ['reason' => $row, 'reseal' => $reseal, 'keeper' => null] + $out;
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
    private function assertProfileSealed(VaultRecord $record, int $opslimit, int $memlimit, bool $archive = false): void
    {
        $what = $archive ? "An archive of '{$record->userId}'" : "The vault of '{$record->userId}'";
        if ($record->profileSeal === null) {
            throw new VaultTamperedException(ProfileSeal::expected($record)
                ? "{$what} carries no seal, though its envelopes were written by a version that seals every vault "
                    . 'it writes through its façade: the seal was removed in the database, or the row was written '
                    . 'outside the façade — restore it from a backup'
                : "{$what} carries no profile seal: an operator seals the vaults written before 0.7.0 with "
                    . 'sealProfile(), once, before serving');
        }
        if (!ProfileSeal::holds($this->sealKey, $record->profileSeal, $record, $opslimit, $memlimit)) {
            throw new VaultTamperedException(
                "{$what} does not match its seal: its Argon2id profile or its envelopes were changed in the database"
                . ($archive ? '' : ' — repairProfile() puts back a profile the seal vouches for; an envelope changed is restored from a backup')
            );
        }
    }

    /**
     * The profile an archive's seal vouches for: the one it records, or else
     * the published profile the seal holds under. A profile changed in the
     * database, while the archive was taken or since, is not followed, and
     * nothing reports it: the seal covers the true one.
     *
     * @return array{0: int, 1: int}
     */
    private function vouchedProfile(VaultRecord $record, int $opslimit, int $memlimit): array
    {
        if ($record->profileSeal !== null && !ProfileSeal::holds($this->sealKey, $record->profileSeal, $record, $opslimit, $memlimit)) {
            foreach (Primitives::PUBLISHED_PROFILES as [$publishedOps, $publishedMem]) {
                if (ProfileSeal::holds($this->sealKey, $record->profileSeal, $record, $publishedOps, $publishedMem)) {
                    return [$publishedOps, $publishedMem];
                }
            }
        }
        $this->assertProfileSealed($record, $opslimit, $memlimit, archive: true);
        return [$opslimit, $memlimit];
    }

    /**
     * The record of archive $archiveId, its seal checked under the profile it
     * records: brought back live, that is the profile it opens under. One
     * without a seal passes only if written before 0.7.0.
     */
    private function sealedArchive(string $userId, string $archiveId): VaultRecord
    {
        $archive = $this->storage->loadArchive($userId, $archiveId)
            ?? throw new VaultNotFoundException("No archive '{$archiveId}' for this account");
        $this->assertSameUser($userId, $archive->record->userId);
        if ($archive->record->profileSeal === null && !ProfileSeal::expected($archive->record)) {
            return $archive->record;
        }
        $this->assertProfileSealed($archive->record, $archive->record->kdfOpslimit, $archive->record->kdfMemlimit, archive: true);
        return $archive->record;
    }

    /**
     * With RecoveryTickets given, $ticket must be of $kind and issued for
     * $userId. Checked first, so that a call without one costs no Argon2id.
     */
    private function requireTicket(?string $ticket, string $userId, string $kind): void
    {
        if ($this->tickets === null) {
            return;
        }
        if ($ticket === null || $this->tickets->kindOf($ticket, $userId) !== $kind) {
            throw new RecoveryTicketException(
                "No accepted recovery of kind '{$kind}' for '{$userId}' carries this ticket: the gesture is refused"
            );
        }
    }

    private function sealed(VaultRecord $record): VaultRecord
    {
        return $record->withProfileSeal(ProfileSeal::of($this->sealKey, $record, $record->kdfOpslimit, $record->kdfMemlimit));
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
     * Seal an updated record over its envelopes as they now are, store it, then
     * read it back. Every record reaching here was loaded through its seal, but
     * the one sealProfile() passes: a row the operator trusts as it stands.
     */
    private function persist(VaultRecord $updated): void
    {
        $sealed = $this->sealed($updated);
        $this->assertVaultPersisted($sealed, $this->storage->updateVault($sealed));
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
