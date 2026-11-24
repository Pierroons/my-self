<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Pierroons\SelfDataGuard\Crypto\LegacyCipherUnavailableException;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Escrow\AdminKey;
use Pierroons\SelfDataGuard\Escrow\EscrowAdminException;
use Pierroons\SelfDataGuard\Escrow\EscrowCeremony;
use Pierroons\SelfDataGuard\Escrow\EscrowEnvelopeMismatchException;
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
use Pierroons\SelfDataGuard\Storage\ErasesArchives;
use Pierroons\SelfDataGuard\Storage\StorageInterface;
use Pierroons\SelfDataGuard\Storage\TracesLostIndexes;
use Pierroons\SelfDataGuard\Storage\UnreadableEscrowException;
use Pierroons\SelfDataGuard\Throttle\Door;
use Pierroons\SelfDataGuard\Throttle\Outcome;
use Pierroons\SelfDataGuard\Throttle\Throttle;
use Pierroons\SelfDataGuard\Vault\ArchivedVault;
use Pierroons\SelfDataGuard\Pepper\PepperSource;
use Pierroons\SelfDataGuard\Pepper\RemotePepperSource;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\LockPepper;
use Pierroons\SelfDataGuard\Vault\ObjectionKey;
use Pierroons\SelfDataGuard\Vault\PepperForms;
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
use Pierroons\SelfDataGuard\Vault\WrongSecretException;
use RuntimeException;
use Throwable;

/**
 * Public façade for SelfDataGuard.
 *
 * Wraps the Vault + Fields + Storage layers behind a minimal API:
 *
 *     $dg = new SelfDataGuard(
 *         $storage, $blindKey,
 *         pepper: new LocalPepperFile('/etc/myself/pepper', $_SERVER['DOCUMENT_ROOT'] ?? ''),
 *         forms: PepperForms::LayeredOnly,
 *         throttle: $hostThrottle,               // or new NoThrottle(): the host brakes elsewhere
 *         sharedIndexes: ['phone'],
 *     );
 *
 *     // New user. Passwords are refused below UserVault::PASSWORD_MIN_LEN; the
 *     // memorized secret is the 64-hex fingerprint SelfRecover derives.
 *     $session = $dg->register('user-1', 'a-long-generated-password', $fingerprint, 'six word passphrase');
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
    private readonly LockPepper $pepper;
    private bool $pepperChecked = false;
    private EscrowVault $escrow;

    /** @var array<string, true> */
    private array $shared = [];

    private readonly string $sealKey;

    /** @var array{sign: string, box: string}|null the public keys of the keeper this façade seals escrows to, once checked */
    private ?array $keeperKeys = null;

    /** @var array<int, array{0: string, 1: array{sign: string, box: string}}> the fingerprint and keys of each former keeper given, once they came */
    private array $formerKeys = [];

    /** How many times a deposit is redone over a rotation that landed between its read and its write. */
    private const DEPOSIT_ATTEMPTS = 3;

    /** Settings the façade claims in the database: what the first façade opened on it recorded. */
    public const SETTING_BLIND_KEY      = 'selfdataguard.blind-key-check';
    public const SETTING_SHARED_INDEXES = 'selfdataguard.shared-indexes';
    public const SETTING_KEEPER         = 'selfdataguard.escrow-keeper';
    public const SETTING_FORMER_KEEPERS = 'selfdataguard.escrow-keeper-former';
    public const SETTING_FORGOTTEN_KEEPERS = 'selfdataguard.escrow-keeper-forgotten';
    public const SETTING_ESCROW_ADMIN   = 'selfdataguard.escrow-admin';
    public const SETTING_PEPPER         = 'selfdataguard.pepper-check';
    public const SETTING_PEPPER_PASS    = 'selfdataguard.pepper-pass';

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
     * @param PepperSource $pepper where the pepper lives — a file out of the
     *        database (LocalPepperFile), or another machine. wrap_pwd and
     *        wrap_recov take it; the passphrase does not. The first façade on
     *        an empty database records its check; a façade given another is
     *        refused, and so is one on a database that holds vaults and never
     *        recorded one, until the operator's pass (adoptPepper()). Checked
     *        here, or at the first use for a RemotePepperSource.
     * @param PepperForms $forms AcceptBare during an upgrade from 0.9.x,
     *        LayeredOnly once it is declared over, and from the start on a new
     *        installation: see PepperForms.
     * @param Throttle $throttle the host's brake on the doors where a secret
     *        from outside costs an Argon2id — loginWithPassword(),
     *        loginWithMemorized(), recover(), openArchive(),
     *        setLockWhileFrozen(), openForObjection(), pinArchiveEscrow().
     *        Each attempt is
     *        admitted before anything is read, then settled with what the
     *        secret did. NoThrottle when the host brakes before the call.
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
     *        (changeKeeper()). With those the database recorded, they are the
     *        former keepers: a holder sees what each still attests and objects
     *        there, and each deposit writes them into the record, then retires
     *        the escrow from every one of them — refused while one recorded is
     *        not given, one was forgotten, or one does not answer. A keeper given
     *        here, once a deposit wrote it into the record, is refused by
     *        changeKeeper() as the new keeper: give only keepers the deployment
     *        replaced.
     *
     * @throws InvalidArgumentException when the key, or the shared fields, are
     *         not the ones the database recorded, or when it recorded none and
     *         holds vaults
     */
    public function __construct(
        private readonly StorageInterface $storage,
        #[\SensitiveParameter] private readonly string $blindKey,
        PepperSource $pepper,
        PepperForms $forms,
        private readonly Throttle $throttle,
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
        $this->pepper = new LockPepper($pepper, $forms);
        if (!$pepper instanceof RemotePepperSource) {
            $this->assertPepper();
        }
        $this->sealKey = ProfileSeal::key($blindKey);
        $this->vault   = new UserVault(pepper: $this->pepper);
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
     * Record $adminPublicKey as the administrator key escrows are sealed to —
     * the operator's act, and the only one that records it: no deposit does.
     * Whoever records it proves they hold it: the passphrase unseals
     * $sealedSecret, and the secret key it holds is the private half of
     * $adminPublicKey (EscrowCeremony::proveAdminKey()). Adopting the key already recorded
     * does nothing.
     *
     * A database that recorded another key refuses it: escrows sealed to the
     * recorded one stay readable by its holder alone. On a database whose
     * record was erased, the key adopted is the one every later deposit seals
     * to; escrows sealed before keep the key their label names.
     *
     * @throws WrongSecretException     on a wrong passphrase
     * @throws InvalidArgumentException on a sealed file or a public key that does not parse
     * @throws EscrowAdminException     admin-not-the-pair, when the two files are two keys' halves;
     *                                  admin-mismatch, when the database recorded another key — before or meanwhile
     */
    public static function adoptEscrowAdmin(
        StorageInterface $storage,
        string $sealedSecret,
        #[\SensitiveParameter] string $passphrase,
        string $adminPublicKey
    ): void {
        EscrowCeremony::proveAdminKey($sealedSecret, $passphrase, $adminPublicKey);
        $print = AdminKey::fingerprint($adminPublicKey);
        $held  = $storage->loadSetting(self::SETTING_ESCROW_ADMIN) ?? $storage->claimSetting(self::SETTING_ESCROW_ADMIN, $print);
        if (!hash_equals($held, $print)) {
            throw new EscrowAdminException(
                'admin-mismatch',
                "This database recorded the administrator key {$held}, not {$print}: nothing was changed"
            );
        }
    }

    /**
     * Replace the administrator key the database recorded with $newPublicKey —
     * the operator's act. $oldPublicKey must be the key recorded; the new one is
     * proven as adoptEscrowAdmin() proves it. Every later deposit seals to the
     * new key. What is sealed to the old one stays so — its label says so —
     * until the operator's pass re-seals it (EscrowCeremony::resealToAdmin()),
     * or, for a live escrow, its holder's next deposit: keep the old key until
     * the pass leaves nothing under it.
     *
     * A change of key serves a change of its holder. It repairs no leak: a copy
     * of the database taken before opens with the old key all the same.
     *
     * @throws WrongSecretException     on a wrong passphrase of the new key
     * @throws InvalidArgumentException on a sealed file or a public key that does not parse
     * @throws EscrowAdminException     admin-mismatch, when the database recorded none, another key than
     *                                  $oldPublicKey, or changed it meanwhile; admin-not-the-pair for the new key's files
     */
    public static function changeEscrowAdmin(
        StorageInterface $storage,
        string $oldPublicKey,
        string $sealedSecret,
        #[\SensitiveParameter] string $passphrase,
        string $newPublicKey
    ): void {
        $old  = AdminKey::fingerprint($oldPublicKey);
        $new  = AdminKey::fingerprint($newPublicKey);
        $held = $storage->loadSetting(self::SETTING_ESCROW_ADMIN);
        if ($held === null || !hash_equals($held, $old)) {
            throw new EscrowAdminException(
                'admin-mismatch',
                'This database recorded the administrator key ' . ($held ?? 'none') . ", not {$old}: nothing was changed"
            );
        }
        EscrowCeremony::proveAdminKey($sealedSecret, $passphrase, $newPublicKey);
        if (!hash_equals($old, $new) && !$storage->replaceSetting(self::SETTING_ESCROW_ADMIN, $old, $new)) {
            throw new EscrowAdminException(
                'admin-mismatch',
                "The recorded administrator key changed meanwhile: it is no longer {$old}, and nothing was changed"
            );
        }
    }

    /**
     * Replace the keeper the database recorded — the operator's act. $from
     * joins the former keepers the database keeps a record of, so that a
     * façade and the ceremony given it still reach what is sealed to it: the
     * archives, and every escrow until its holder's next deposit re-seals it
     * to $to. Each deposit retires the escrow from every former keeper, and is
     * refused without it: the façades keep $from for as long as the database
     * records it. An escrow retired from a keeper never reads there again, so
     * $to is never a keeper a record of former or forgotten keepers lists —
     * replaced before, or given to a façade as former and written there by a
     * deposit —, nor $from itself. A forgetFormerKeeper() of $to landing at
     * the same time is seen: the keeper goes back to $from, and the act is
     * refused.
     *
     * @throws InvalidArgumentException when $from is not the keeper the database
     *         recorded, $to is $from or a keeper the records list, a record of
     *         former or forgotten keepers is unreadable, or the record changed
     *         meanwhile — another operator, or a deposit writing a former keeper
     *         into it
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
        $forgotten  = KeeperSeal::formerPrints($storage->loadSetting(self::SETTING_FORGOTTEN_KEEPERS), 'forgotten keepers');
        if (hash_equals($fromPrint, $toPrint) || in_array($toPrint, $former, true) || in_array($toPrint, $forgotten, true)) {
            throw new InvalidArgumentException(
                "The keeper {$toPrint} is the one replaced, or one the records of former or forgotten keepers list — "
                . 'replaced before, or given to a façade as former and written there by a deposit: escrows were retired '
                . 'from it — change to a keeper this database never had'
            );
        }
        if (!in_array($fromPrint, $former, true)) {
            $former[] = $fromPrint;
        }
        $written  = json_encode($former, JSON_THROW_ON_ERROR);
        $recorded = $formerHeld === null
            ? hash_equals($storage->claimSetting(self::SETTING_FORMER_KEEPERS, $written), $written)
            : $storage->replaceSetting(self::SETTING_FORMER_KEEPERS, $formerHeld, $written);
        if (!$recorded) {
            throw new InvalidArgumentException(
                'The record of former keepers changed meanwhile, or the storage returned false for a value replaced with '
                . 'itself (see StorageInterface::replaceSetting()): nothing was changed'
            );
        }
        if (!$storage->replaceSetting(self::SETTING_KEEPER, $fromPrint, $toPrint)) {
            throw new InvalidArgumentException('The recorded keeper changed meanwhile: it is no longer ' . $fromPrint);
        }
        // forgetFormerKeeper() reads the keeper again once it has written the record of forgotten keepers; this
        // reads that record again once the keeper is written: of two such acts at once, one sees the other.
        if (in_array($toPrint, KeeperSeal::formerPrints($storage->loadSetting(self::SETTING_FORGOTTEN_KEEPERS), 'forgotten keepers'), true)) {
            $storage->replaceSetting(self::SETTING_KEEPER, $toPrint, $fromPrint);
            $storage->replaceSetting(self::SETTING_FORMER_KEEPERS, $written, $formerHeld ?? '[]');
            throw new InvalidArgumentException(
                "The keeper {$toPrint} was forgotten meanwhile (forgetFormerKeeper()): the database's keeper is "
                . "{$fromPrint} again — change to a keeper this database never had"
            );
        }
    }

    /**
     * Take the former keeper whose fingerprint is $print off the database's
     * record of former keepers — the operator's act, for a former keeper that
     * is gone for good: each deposit asks every former keeper to retire the
     * escrow, and is refused while one does not answer. The operator stops
     * giving it to the façades, which would keep asking it, and to the
     * ceremony, which refuses a former keeper the database does not record —
     * every read, not only those sealed to it. What is still sealed to it —
     * the archives, the escrows no deposit re-sealed since — then no longer
     * reads through the ceremony. Its fingerprint moves to the record of
     * forgotten keepers, which changeKeeper() refuses as well: escrows were
     * retired from it. A forgotten keeper that still lives keeps releasing
     * what is still sealed to it, out of the holder's view, like a keeper
     * replaced without changeKeeper(): forget only a keeper gone for good.
     * Nothing else logs the act. Interrupted after the record of forgotten
     * keepers took $print, it leaves every deposit refused until called
     * again, which ends the act. Refused for the database's keeper: the
     * operator changes first to a keeper this database never had — and, once
     * the forgotten record took $print, when a changeKeeper() to it landed at
     * the same time.
     *
     * @throws InvalidArgumentException when the database records no former keeper
     *         $print, $print is the database's keeper, a record is unreadable,
     *         or another operator changed one meanwhile — the message says which
     */
    public static function forgetFormerKeeper(StorageInterface $storage, string $print): void
    {
        $held   = $storage->loadSetting(self::SETTING_FORMER_KEEPERS);
        $former = KeeperSeal::formerPrints($held);
        if (!in_array($print, $former, true)) {
            throw new InvalidArgumentException("This database records no former keeper {$print}: nothing was changed");
        }
        $keeper = $storage->loadSetting(self::SETTING_KEEPER);
        if ($keeper !== null && hash_equals($keeper, $print)) {
            throw new InvalidArgumentException(
                "The former keeper {$print} is also the database's keeper: nothing was changed — change first to a keeper "
                . 'this database never had (changeKeeper()), then forget it if it is gone for good'
            );
        }
        $forgottenHeld = $storage->loadSetting(self::SETTING_FORGOTTEN_KEEPERS);
        $forgotten     = KeeperSeal::formerPrints($forgottenHeld, 'forgotten keepers');
        if (!in_array($print, $forgotten, true)) {
            $forgotten[] = $print;
            $written     = json_encode($forgotten, JSON_THROW_ON_ERROR);
            $recorded    = $forgottenHeld === null
                ? hash_equals($storage->claimSetting(self::SETTING_FORGOTTEN_KEEPERS, $written), $written)
                : $storage->replaceSetting(self::SETTING_FORGOTTEN_KEEPERS, $forgottenHeld, $written);
            if (!$recorded) {
                throw new InvalidArgumentException('The record of forgotten keepers changed meanwhile: nothing was changed');
            }
        }
        // changeKeeper() reads the record of forgotten keepers again once it has written the keeper; this reads the
        // keeper again once that record is written: of two such acts at once, one sees the other.
        $keeperNow = $storage->loadSetting(self::SETTING_KEEPER);
        if ($keeperNow !== null && hash_equals($keeperNow, $print)) {
            throw new InvalidArgumentException(
                "The database's keeper became {$print} meanwhile (changeKeeper()): the record of forgotten keepers took "
                . 'it, and it is still among the former ones — change to a keeper this database never had, then call again'
            );
        }
        $kept = json_encode(array_values(array_diff($former, [$print])), JSON_THROW_ON_ERROR);
        if (!$storage->replaceSetting(self::SETTING_FORMER_KEEPERS, (string) $held, $kept)) {
            throw new InvalidArgumentException(
                "The record of forgotten keepers took {$print}, and the record of former keepers changed meanwhile: it "
                . 'is still there — call again to take it off'
            );
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
        $this->assertPepper();
        if ($this->storage->vaultExists($userId)) {
            throw new VaultExistsException("User '{$userId}' already exists");
        }
        if ($this->storage->listArchives($userId) !== []) {
            throw new VaultExistsException(
                "User '{$userId}' still holds the archives of a deleted vault: they would open to whoever registers "
                . 'the name with an old secret. eraseAccount() erases them first, and eraseArchive() those it keeps — '
                . 'an operator\'s decision'
            );
        }
        $result = $this->vault->register($userId, $password, $memorized, $passphrase);
        $record = $this->sealed($result['record']);
        $this->storage->saveVault($record);
        $this->assertVaultPersisted($record, 0);
        return $result['unlocked'];
    }

    /**
     * Authenticate by password, behind the throttle. Returns an UnlockedVault
     * for the session.
     *
     * An account that does not exist costs the derivation a wrong password
     * costs: only the exception tells the two apart, and the host answers both
     * alike.
     *
     * @throws Vault\WrongSecretException     on wrong password
     * @throws Vault\VaultNotFoundException   on missing user
     * @throws Throttle\ThrottledException   when the throttle refuses, before anything is read
     */
    public function loginWithPassword(string $userId, #[\SensitiveParameter] string $password): UnlockedVault
    {
        return $this->openThrottled(Door::LoginPassword, $userId, Lock::Password, $password)[1];
    }

    /**
     * Authenticate by memorized secret (recovery flow), behind the throttle,
     * as loginWithPassword() is.
     *
     * @throws Vault\WrongSecretException     on wrong secret
     * @throws Vault\VaultNotFoundException   on missing user
     * @throws Throttle\ThrottledException   when the throttle refuses, before anything is read
     */
    public function loginWithMemorized(string $userId, #[\SensitiveParameter] string $memorized): UnlockedVault
    {
        return $this->openThrottled(Door::LoginMemorized, $userId, Lock::Memorized, $memorized)[1];
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
     * Each call costs an Argon2id, behind the throttle as the logins are, and
     * an account that does not exist costs the same. Call it right after
     * SelfRecover has accepted a recovery: its counters are a second brake,
     * on the secret it verified.
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
        [$record, $session] = $this->openThrottled(Door::Recover, $userId, $lock, $secret);
        $record = $this->vault->setLock($record, $session, Lock::Password, $newPassword);
        if ($newPassphrase !== null) {
            $record = $this->vault->setLock($record, $session, Lock::Passphrase, $newPassphrase);
        }
        // Opened by the memorized secret, the session holds it: once the
        // upgrade is over, wrap_recov takes the pepper into its Argon2id input
        // too. A secret that is not the canonical fingerprint stays as sealed.
        if (
            $lock === Lock::Memorized && $this->pepper->forms === PepperForms::LayeredOnly
            && preg_match('/^[0-9a-f]{64}$/D', $secret) === 1
            && $this->pepper->formOf(Lock::Memorized, $record->wrapRecov, $userId, $record->userSalt) !== 'native'
        ) {
            $record = $this->vault->setLock($record, $session, Lock::Memorized, $secret);
        }
        $this->persist($record);
        return $session;
    }

    /**
     * Encrypt and persist a batch of field values for the user.
     * Optionally compute and store blind indexes for lookup-able fields.
     *
     * An indexed value names one account, unless its field is declared shared.
     * If another account holds it — live, or in an archive that can still
     * come back: made less than RecoveryTickets::RETURN_WINDOW ago, or of an
     * account whose vault is frozen —, DuplicateIndexException names the
     * fields and nothing of the batch is written. Index a value once the account has
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

    /**
     * Re-seal a lock of a FROZEN vault: the one write a freeze lets through,
     * and only from an opening by the memorized secret, given here each time.
     *
     * SelfRecover freezes the vault a returning holder took back, until the
     * arbiter closes the window. Whoever held the account meanwhile holds its
     * password and its passphrase, not the holder's memorized secret: through
     * any other lock, they would re-seal the memorized one to their profit
     * before the arbiter looks. A session does not say which lock opened it;
     * the secret is asked instead.
     *
     * Only the envelopes and the seal are written
     * (StorageInterface::updateFrozenLocks()): fields, escrow and removeLock()
     * stay refused. A holder who forgot the memorized secret re-seals nothing
     * until unfreeze() — the password still opens the vault, SelfRecover's
     * level 2 does not follow.
     *
     * Behind the throttle, as recover() is. A vault that is not frozen is
     * refused after the opening, so that whoever lacks the memorized secret
     * is not told whether it is frozen.
     *
     * @throws WrongSecretException          the memorized secret does not open the vault
     * @throws LogicException                the vault is not frozen: setLock() re-seals it from any session
     * @throws Throttle\ThrottledException   when the throttle refuses, before anything is read
     */
    public function setLockWhileFrozen(
        string $userId,
        #[\SensitiveParameter] string $memorized,
        Lock $lock,
        #[\SensitiveParameter] string $secret
    ): UnlockedVault {
        [$record, $session] = $this->openThrottled(Door::SetLockWhileFrozen, $userId, Lock::Memorized, $memorized);
        if ($this->storage->frozenState($userId) === null) {
            throw new LogicException("The vault of '{$userId}' is not frozen: setLock() re-seals a lock from any session");
        }
        $sealed = $this->sealed($this->vault->setLock($record, $session, $lock, $secret));
        $this->assertVaultPersisted($sealed, $this->storage->updateFrozenLocks($sealed));
        return $session;
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
     * Each call costs an Argon2id, behind the throttle as the logins are.
     *
     * The memorized lock of an archive taken at level 3 needs the SelfRecover
     * salt of that time, which level 3 replaced. Keep it — as a field of the
     * new vault, say — for that lock to stay usable.
     *
     * @throws StaleVaultException    if $current is not a session on the live vault
     * @throws VaultNotFoundException if this userId has no such archive
     * @throws Throttle\ThrottledException when the throttle refuses, before anything is read
     */
    public function openArchive(
        UnlockedVault $current,
        string $archiveId,
        Lock $lock,
        #[\SensitiveParameter] string $oldSecret
    ): UnlockedArchive {
        return $this->throttled(Door::OpenArchive, $current->userId, function () use ($current, $archiveId, $lock, $oldSecret): UnlockedArchive {
            $this->currentRecord($current);
            $archive = $this->storage->loadArchive($current->userId, $archiveId)
                ?? throw new VaultNotFoundException("No archive '{$archiveId}' for this account");
            $this->assertSameUser($current->userId, $archive->record->userId);
            [$opslimit, $memlimit] = [$archive->kdfOpslimit, $archive->kdfMemlimit];
            if ($archive->record->profileSeal !== null || ProfileSeal::expected($archive->record)) {
                [$opslimit, $memlimit] = $this->vouchedProfile($archive->record, $opslimit, $memlimit);
            }
            $session = $this->vault->unlock($archive->record, $lock, $oldSecret, $opslimit, $memlimit);
            $openedFrom = $current->generation();
            return self::inScope(UnlockedArchive::class, static fn (): UnlockedArchive => new UnlockedArchive($archive, $session, $openedFrom));
        });
    }

    /**
     * Decrypt an opened archive.
     *
     * To restore it, write the private fields back with the fields your
     * application indexes — not a list read from the archive, which whoever
     * writes the database can extend to a field never meant to be looked up.
     * While it can still come back, the archive reserves its values to this
     * account: a DuplicateIndexException names those another account held
     * when no reservation stood — restore those fields without their index,
     * and settle who owns the value.
     *
     * An escrow the archive keeps and that does not read — a row that does
     * not parse, an envelope that does not open, a field that does not
     * decrypt — is not handed out: the private fields come all the same,
     * `escrow` is empty and `escrowUnreadable` true.
     *
     * @return array{private: array<string, string>, escrow: array<string, string>, escrowUnreadable: bool}
     * @throws LegacyCipherUnavailableException an escrow field written before 0.4.0, on a machine without its cipher
     */
    public function readArchive(UnlockedArchive $opened): array
    {
        $archive = $opened->archive;
        $record  = $this->archiveEscrow($archive);
        $fields  = array_diff_key($archive->privateFields, [EscrowVault::WRAP_AAD_TAG => true]);
        $private = $opened->decryptFields(array_map(static fn (array $f): string => $f['ciphertext'], $fields));
        $escrow  = [];
        $read    = $record !== false;
        if ($record instanceof EscrowRecord) {
            try {
                $unlocked = $opened->openEscrow($record);
            } catch (Throwable) {
                $unlocked = null;
                $read     = false;
            }
            if ($unlocked !== null) {
                try {
                    $escrow = EscrowFieldCrypter::decryptBatch($unlocked, $archive->escrowFields);
                } catch (LegacyCipherUnavailableException $e) {
                    throw $e;
                } catch (Throwable) {
                    $read = false;
                } finally {
                    $unlocked->lock();
                }
            }
        }
        return ['private' => $private, 'escrow' => $escrow, 'escrowUnreadable' => !$read];
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
     * While it can come back, the archive reserves its blind indexes to this
     * account (setFields()), so every value comes back with its index — but
     * one another account held when no reservation stood (an archive older
     * than RecoveryTickets::RETURN_WINDOW, brought back by an operator, or a
     * database written before 0.9.1): that field comes back without its index,
     * the other account keeps the value, and the storage keeps a trace of it
     * (lostIndexes()). $restoredWithoutIndex names the fields the archive
     * indexed and that came back without an index — those, and the indexed
     * fields of an archive written before 0.8.0, which kept no index. Settle
     * who owns each value, then index it again with setFields().
     *
     * An escrow the archive keeps and that does not parse comes back as a row
     * that does not parse, and reads as a row rewritten (escrowReads():
     * escrow-replaced). Its fields then open to no one, and a deposit is
     * refused (EscrowEnvelopeMismatchException) until the operator puts back,
     * from a backup, the escrow row that wrote them.
     *
     * With no live vault left — the account was erased while its archive was
     * kept —, generationOf() is null: the archive comes back by restoreArchive().
     *
     * @param list<string>|null $restoredWithoutIndex set, once the archive is live, to those fields; null when the
     *        storage could not be asked which indexes came back — the archive is live all the same. A variable that
     *        holds something else than an array or null raises TypeError before anything is done
     * @return string the id of the archive the replaced vault became
     * @throws VaultNotFoundException   if this userId has no such archive
     * @throws VaultTamperedException   if the archive's seal does not hold
     * @throws VaultFrozenException     if the vault $replacing names is frozen
     */
    public function reinstateArchive(
        VaultGeneration $replacing,
        string $archiveId,
        ?string $ticket = null,
        ?array &$restoredWithoutIndex = null
    ): string {
        $this->requireTicket($ticket, $replacing->userId, RecoveryTickets::HOLDER_RETURN);
        $archive  = $this->sealedArchive($replacing->userId, $archiveId);
        $setAside = $this->storage->swapWithArchive($replacing, $archiveId, $ticket);
        $this->assertVaultPersisted($archive->record, $archive->record->revision);
        $restoredWithoutIndex = $this->indexesNotBack($archive);
        return $setAside;
    }

    /**
     * Bring an archive back live for a userId left without a live vault — an
     * operator's step, after the live vault was deleted, or the account erased
     * while the archive was kept. As reinstateArchive(), the replaced vault
     * apart, $restoredWithoutIndex included. It takes no ticket: in a
     * holder's return after an erasure, the return ticket goes to freeze()
     * on the vault it brought back.
     *
     * @param list<string>|null $restoredWithoutIndex see reinstateArchive()
     * @throws VaultExistsException if the userId holds a live vault
     */
    public function restoreArchive(string $userId, string $archiveId, ?array &$restoredWithoutIndex = null): void
    {
        $archive = $this->sealedArchive($userId, $archiveId);
        $this->storage->restoreArchive($userId, $archiveId);
        $this->assertVaultPersisted($archive->record, $archive->record->revision);
        $restoredWithoutIndex = $this->indexesNotBack($archive);
    }

    /**
     * Freeze the live vault $generation names: it keeps opening and reading,
     * and every write refuses with VaultFrozenException — fields, escrow,
     * locks, deletion, re-enrolment, the erasure of the account — until
     * unfreeze(), but one: setLockWhileFrozen() re-seals a lock from an
     * opening by the memorized secret. SelfRecover freezes the vault a
     * returning holder took back, until the arbiter closes that window.
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
     * Erase the account without a session: its live vault, and its archives —
     * unless a re-enrolment applied a level-3 ticket to the account: then
     * every archive stays, whichever gesture set it aside, the holder's vault
     * and the requester's alike. Works on a userId left with archives only.
     *
     * While the live vault is frozen — a holder's return waits for the
     * arbiter —, nothing is erased and VaultFrozenException is raised, $kept
     * left as it was: keep the request, and run it once unfreeze() has closed
     * the window.
     *
     * No ticket is erased: a replay of any of them under this userId — after
     * the name is registered again — is refused. Once the account keeps no
     * archive, its tickets no longer name it.
     *
     * An erasure request is then finished only once the operator erased the
     * archives $kept lists with eraseArchive(), when the case they were kept
     * for is closed; until then the archives, their escrow included, stay
     * readable to the ceremony, and register() refuses the name. Without a
     * RecoveryTickets source, a level-3 ticket is recorded only when the
     * caller hands one to reEnroll(); without one, the archives go with the
     * account.
     *
     * Whoever exposes this decides who may call it: after a level-3 recovery,
     * the holder of the account is not necessarily the person the archives
     * belong to.
     *
     * @param list<array{id: string, archivedAt: \DateTimeImmutable}>|null $kept set, once the account is erased, to
     *        the archives left, oldest first. A variable that holds something else than an array or null raises
     *        TypeError before anything is done
     * @return int how many vaults were erased, live and archived
     * @throws VaultFrozenException if the live vault of the account is frozen: nothing is erased
     */
    public function eraseAccount(string $userId, ?array &$kept = null): int
    {
        $this->refuseWhileFrozen($userId, 'the account is not erased, nothing of it is');
        $erased = $this->storage->eraseAccount($userId);
        $kept   = array_map(
            static fn (array $a): array => ['id' => $a['id'], 'archivedAt' => $a['archivedAt']],
            $this->storage instanceof ErasesArchives ? $this->storage->keptArchives($userId) : $this->storage->listArchives($userId)
        );
        return $erased;
    }

    /**
     * Erase one archive of the account, without a session and whatever vault
     * is live — the operator's act on an archive eraseAccount() kept, once the
     * case it was kept for is closed; never for a route a visitor reaches. The
     * tickets of the account stay; once it keeps no archive and no live vault,
     * they no longer name it. While the live vault is frozen, nothing is
     * erased; before a holder's return, nothing is frozen, and this erases
     * the archive — it is the operator's act.
     *
     * @return bool false if the userId has no such archive
     * @throws LogicException when the storage does not implement ErasesArchives
     * @throws VaultFrozenException if the live vault of the account is frozen: nothing is erased
     */
    public function eraseArchive(string $userId, string $archiveId): bool
    {
        $storage = $this->erasing();
        $this->refuseWhileFrozen($userId, 'no archive of it is erased until the case is closed');
        return $storage->eraseArchive($userId, $archiveId);
    }

    /**
     * Every archive whose account holds no live vault — those eraseAccount()
     * kept, and those a deleted vault left —, oldest first: the erasures the
     * operator has not finished. Read without opening the archives. For the
     * operator, never for a route a visitor reaches.
     *
     * @return list<array{userId: string, id: string, archivedAt: DateTimeImmutable}>
     * @throws LogicException when the storage does not implement ErasesArchives
     */
    public function keptArchives(): array
    {
        return $this->erasing()->keptArchives();
    }

    /**
     * The fields of this account that an archive brought back live without
     * their index, because another account held the value — indexed by both
     * once the archive's reservation had ended, or before 0.9.1. Each stays
     * listed until it is indexed again with setFields(), once who owns the
     * value is settled.
     *
     * @return list<string>
     * @throws LogicException when the storage does not implement TracesLostIndexes
     */
    public function lostIndexes(UnlockedVault $session): array
    {
        $this->currentRecord($session);
        return $this->tracing()->lostIndexes($session->userId)[$session->userId] ?? [];
    }

    /**
     * lostIndexes() for every account, by userId: for the operator who settles
     * who owns each value, never for a route a visitor reaches.
     *
     * @return array<string, list<string>>
     * @throws LogicException when the storage does not implement TracesLostIndexes
     */
    public function allLostIndexes(): array
    {
        return $this->tracing()->lostIndexes();
    }

    /**
     * The reservations in force — each archive that still reserves its blind
     * indexes, each field it reserves, until when, and whether a freeze holds
     * it past that — so that the operator sees which account keeps a value
     * no lookup answers for. Names and dates only. For the operator, never for
     * a route a visitor reaches.
     *
     * @return list<array{userId: string, archiveId: string, field: string, until: DateTimeImmutable, frozen: bool}>
     * @throws LogicException when the storage does not implement TracesLostIndexes
     */
    public function reservedIndexes(): array
    {
        return $this->tracing()->reservedIndexes();
    }

    /**
     * VaultFrozenException when the live vault of $userId is frozen, asked of
     * the storage before an erasure: the storage refuses it in the erasure's
     * own transaction too, and this keeps a storage that does not from
     * erasing during the case.
     */
    private function refuseWhileFrozen(string $userId, string $what): void
    {
        if ($this->storage->frozenState($userId) !== null) {
            throw new VaultFrozenException("The vault of '{$userId}' is frozen: {$what}");
        }
    }

    private function erasing(): ErasesArchives
    {
        if (!$this->storage instanceof ErasesArchives) {
            throw new LogicException(
                'This storage does not implement ' . ErasesArchives::class . ': it lists and erases no archive on its '
                . 'own, and an archive eraseAccount() kept stays'
            );
        }
        return $this->storage;
    }

    private function tracing(): TracesLostIndexes
    {
        if (!$this->storage instanceof TracesLostIndexes) {
            throw new LogicException(
                'This storage does not implement ' . TracesLostIndexes::class . ': it keeps no trace of a field that came '
                . 'back without its index'
            );
        }
        return $this->storage;
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


    /** Whether the account holds an escrow: its row — readable or not —, or fields left without one. */
    public function hasEscrow(UnlockedVault $session): bool
    {
        $this->currentRecord($session);
        return $this->rowRecord($session->userId) !== null || $this->storage->loadEscrowFields($session->userId) !== [];
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
     * Under a keeper, once the fields of before open, the escrow is retired
     * from every former keeper — recorded by the database or given to this
     * façade, the given ones written into the record first —; the escrow is
     * not written when the vault is frozen, or when one of them cannot be
     * asked or does not take the retirement.
     *
     * wrap_admin is sealed to $adminPublicKey at each deposit, and, with a
     * keeper, sealed again to it; the escrow is labelled with its fingerprint.
     * $adminPublicKey is the key the operator recorded (adoptEscrowAdmin()): a
     * deposit records nothing, and one given another key is refused — it would
     * take every compartment it touches from the administrator who holds the
     * recorded one.
     *
     * These fields are the CONSENTED, admin-recoverable subset (e.g.
     * contact_secours) — kept in a sub-key distinct from the private zone.
     *
     * @param array<string, string> $fields field_name => plaintext
     * @throws EscrowAdminException when the database recorded no administrator key, or another
     * @throws StaleEscrowException when other deposits kept landing between this one's read and its write
     * @throws VaultFrozenException when there is a former keeper and the vault is frozen
     * @throws EscrowKeeperException keeper-mismatch — a recorded former keeper not given, a forgotten one given, a
     *         record of former or forgotten keepers unreadable, the façade's own keeper among the former ones, one
     *         given as former that became the database's keeper meanwhile —, keeper-unreachable — a former keeper
     *         given without keys —, or keeper-failed — a former keeper that
     *         does not take the retirement, or a record of former keepers that keeps changing
     * @throws EscrowEnvelopeMismatchException when the escrow row does not open the fields the database holds:
     *         nothing is written, nothing retired
     * @throws \Pierroons\SelfDataGuard\Escrow\EscrowNotFoundException when the escrow row is deleted between this
     *         deposit's read and its write
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
     * setEscrowFields() is — EscrowEnvelopeMismatchException for a row deleted
     * while its fields stayed. False when there is no escrow.
     */
    public function rotateEscrow(UnlockedVault $session, string $adminPublicKey): bool
    {
        $this->currentRecord($session);
        if ($this->rowRecord($session->userId) === null && $this->storage->loadEscrowFields($session->userId) === []) {
            return false;
        }
        $this->deposit($session, $adminPublicKey, []);
        return true;
    }

    /**
     * Seal the compartment to the façade's keeper, if it is not yet — sealed
     * before 0.9.0, sealed to another keeper, or wrapped by the operator's pass,
     * whose wrap_user still names no keeper. A new key is drawn, as at a
     * deposit, and refused as one: EscrowEnvelopeMismatchException when the
     * row does not open its fields. False when there is nothing to do: no
     * keeper, no escrow, or one already sealed to this keeper.
     */
    public function sealEscrowToKeeper(UnlockedVault $session, string $adminPublicKey): bool
    {
        $this->currentRecord($session);
        $record = $this->rowRecord($session->userId);
        if (!$this->hasKeeper() || $record === null) {
            return false;
        }
        if ($record === false) {
            throw $this->mismatch($session->userId);
        }
        try {
            $unlocked = $this->escrow->unlockAsUser($record, $session);
        } catch (Throwable $e) {
            throw $this->mismatch($session->userId, $e);
        }
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
     * The objection key of the live vault of $userId — or of its archive
     * $archiveId —, opened by one of its secrets without a session, whatever
     * the seal says (ObjectionKey). escrowReads() and objectToEscrowRead()
     * take it in place of a session; nothing that reads or writes a field
     * does. The vault row and its escrow row are read, nothing of the account
     * is written, and the master key is wiped before the key is handed out.
     *
     * It is the holder's door when no session opens: a vault whose seal no
     * longer holds — its profile or an envelope changed in the database —, or
     * an archive after a level 3 they did not ask for, whose current vault is
     * someone else's. The seal names the profile to derive under; when it
     * does not hold, each published profile is tried, never the one the row
     * names.
     *
     * Each call costs an Argon2id for each profile tried, behind the throttle;
     * an account or an archive that does not exist costs one all the same.
     * Whoever holds a secret of the vault or of the archive, an old one
     * included, objects with it to every read of that escrow: an objection
     * blocks a read, and reads nothing.
     *
     * @throws WrongSecretException   if the secret opens no envelope under the profiles tried
     * @throws VaultNotFoundException if this userId has no live vault, or no such archive
     * @throws Throttle\ThrottledException when the throttle refuses, before anything is read
     */
    public function openForObjection(
        string $userId,
        Lock $lock,
        #[\SensitiveParameter] string $secret,
        ?string $archiveId = null
    ): ObjectionKey {
        [$session, $escrow] = $this->openForEscrow(Door::Objection, $userId, $archiveId, $lock, $secret);
        try {
            $escrowId = $session->escrowId();
            $open     = $archiveId === null
                ? fn (EscrowRecord $r): UnlockedEscrow => $this->escrow->unlockAsUser($r, $session)
                : static fn (EscrowRecord $r): UnlockedEscrow => $session->openEscrow($r);
            [$pinned, $row, $box] = $this->rowOf($escrow, $open, $escrowId);
            $seed = self::inScope(UnlockedVault::class, static fn (): string => $session->objectionSeed());
            return self::inScope(ObjectionKey::class, static fn (): ObjectionKey => new ObjectionKey($userId, $archiveId, $seed, $pinned, $row, $box));
        } finally {
            $session->lock();
        }
    }

    /**
     * Seal the escrow of archive $archiveId to the façade's keeper, under the
     * archive's own objection key, from one of its old secrets — without a
     * session on the current vault, which after a level 3 the holder did not
     * ask for is someone else's. The escrow key stays, and the fields with it:
     * an escrow the operator's pass sealed to the keeper, or one sealed to the
     * administrator alone, becomes the holder's to object to. The view of the
     * archive is then established, an objection goes through, and a read
     * objected to is refused once due.
     *
     * The archive is opened as openForObjection() opens it, behind the
     * throttle. The administrator key must be the one the operator recorded,
     * as for a deposit.
     *
     * A copy of the database taken before keeps the box of before: a read
     * announced from it is neither shown nor opposable — the keeper is asked
     * about the archive's escrowId, not the one the operator's pass drew.
     *
     * False when there is nothing to do: no keeper, no escrow, or one already
     * pinned to a keeper — this façade's or a former one.
     *
     * @throws EscrowAdminException when $adminPublicKey is not the one the database recorded, or none is
     * @throws EscrowEnvelopeMismatchException when the escrow of the archive does not parse or does not open
     * @throws WrongSecretException   if the old secret opens no envelope of the archive
     * @throws VaultNotFoundException if this userId has no such archive
     * @throws Throttle\ThrottledException when the throttle refuses, before anything is read
     */
    public function pinArchiveEscrow(
        string $userId,
        string $archiveId,
        Lock $lock,
        #[\SensitiveParameter] string $oldSecret,
        string $adminPublicKey
    ): bool {
        if (!$this->hasKeeper()) {
            return false;
        }
        [$session, $escrow] = $this->openForEscrow(Door::PinArchive, $userId, $archiveId, $lock, $oldSecret);
        try {
            [, $row] = $this->rowOf($escrow, static fn (EscrowRecord $r): UnlockedEscrow => $session->openEscrow($r), $session->escrowId());
            if ($row === 'unreadable' || $row === 'escrow-replaced') {
                throw $this->mismatch($userId);
            }
            if ($row !== 'not-keeper-sealed' && $row !== 'not-pinned') {
                return false;
            }
            $this->assertEscrowAdmin($adminPublicKey);
            $pinned = $session->pinEscrow($escrow, $adminPublicKey, (array) $this->keeperKeys(), new DateTimeImmutable());
            $this->storage->replaceArchiveEscrow($userId, $archiveId, $pinned);
            return true;
        } finally {
            $session->lock();
        }
    }

    /**
     * What the keepers released of this account's escrow, and what they hold
     * announced, as they sign it for this session — read when the holder opens
     * their vault. The keepers are asked about the escrowId the session
     * computes from the master key, not the one the row names: the keeper the
     * row pins first, then the façade's and every former keeper — those the
     * database recorded and those the façade was given. `established` false
     * always says why (`reason`); it is never an empty list: an attestation
     * that does not arrive, or does not verify, is not the same as no read.
     *
     *   - reason `not-keeper-sealed`: the administrator alone can read it;
     *   - `not-pinned`: the operator's pass sealed it to a keeper, and no
     *     deposit from the holder's session named it since — the escrowId would
     *     come from the clear prefix of wrap_admin, which whoever writes the
     *     database rewrites;
     *   - `keeper-changed`: a keeper to ask is neither the façade's nor a
     *     former one it was given — the row pins one the façade does not know
     *     (a keeper changed by hand, not by changeKeeper(), or one the operator
     *     forgot), or the database records a former keeper the façade was not
     *     given; `keeper` names it;
     *   - `keeper-unreachable`: a keeper gave no public keys or no attestation;
     *     `keeper` names it — the façade's, by the fingerprint the database
     *     recorded, a former one, or the class of a former keeper given without
     *     keys (a recorded former keeper then reads unreachable too: it may be
     *     that one);
     *   - `unreadable`, `signature`, `escrow`, `nonce`: an attestation that does
     *     not verify (KeeperAttestationException); `keeper` names its keeper;
     *   - `keeper-mismatch`: the façade's keeper, whose keys came after it was
     *     built, is not the one the database recorded; or the record of former
     *     keepers is unreadable;
     *   - `retired`: the keeper the escrow answers to retired it, and releases
     *     nothing more — a deposit retired it there and did not land, the row
     *     was put back from a backup, or the façade's own keeper took a
     *     retirement;
     *   - `escrow-replaced`: the row does not parse, does not open, or names
     *     another escrowId, or it is absent while its fields stayed; or it is
     *     absent, not keeper-sealed or not pinned, and a keeper holds reads of
     *     the escrowId the session computes — the row was deleted, put back
     *     from a backup or rewritten;
     *   - `no-keeper`: the façade was given none, and the row pins one;
     *   - `reads`, `pending`: what every keeper that answered attests, each
     *     entry naming its keeper by fingerprint, and its box by
     *     KeeperSeal::boxPrint() — '' from a keeper that names none —, under
     *     `established`, and under every reason above that asked the keepers:
     *     a keeper that cannot be asked does not hide what the others attest.
     *     `pending` leaves out a keeper that retired the escrow. A keeper
     *     announces each box on its own: a read of the same case lists once
     *     per box;
     *   - `box`: the KeeperSeal::boxPrint() of the box the row holds, null
     *     when it holds none sealed to a keeper — an entry of another box was
     *     read from a deposit made before, an archive's row, or a copy of the
     *     database;
     *   - `unanswered`: every keeper that could not be asked, or whose
     *     attestation does not verify, `{keeper, reason}` with its own reason.
     *     The top-level `reason` is `retired` or `escrow-replaced` when one
     *     applies; then `not-keeper-sealed` or `not-pinned`, `keeper` null;
     *     otherwise that of the first unanswered keeper, which `keeper` then
     *     names;
     *   - `keeper`: the keeper the escrow answers to, or the one the reason is
     *     about;
     *   - `pinned`: the keeper was named by the holder's own deposit;
     *   - `reseal`: the integrator should call sealEscrowToKeeper(), which is
     *     refused while a former keeper cannot be asked, and while the row does
     *     not open its fields (EscrowEnvelopeMismatchException) — a row put
     *     back from a backup without them. False for an absent row — there is
     *     nothing to re-seal —, for a row that does not parse or open, and for
     *     a row that pins the façade's own keeper, retired there or not: no
     *     re-sealing changes it. For an archive, it says pinArchiveEscrow()
     *     has something to do: a row that pins no keeper.
     *
     * A release is the keeper handing the inner box back: the read that
     * follows may still fail — a wrong passphrase —, and is listed all the
     * same. A vault that refuses every session — rewritten in the database —
     * gives neither this view nor an objection until the operator repairs it
     * — openForObjection() gives both from a secret —; an outage of the
     * storage comes out of both as it is. A field rewritten alone leaves the
     * view as the row says: reading the fields refuses.
     *
     * An ObjectionKey is taken in place of a session, of the live vault or of
     * an archive: the row is then the one its opening read.
     *
     * @return array{established: bool, reason: ?string, keeper: ?string, pinned: bool, box: ?string,
     *               reads: list<array{case: string, seq: int, at: int, box: string, keeper: string}>,
     *               pending: list<array{case: string, due: int, objected: bool, box: string, keeper: string}>,
     *               unanswered: list<array{keeper: string, reason: string}>, reseal: bool}
     */
    public function escrowReads(UnlockedVault|ObjectionKey $session): array
    {
        if ($session instanceof ObjectionKey) {
            return $this->readsOf(self::rowAtOpening($session), $session->archiveId !== null, $session->escrowId());
        }
        $this->currentRecord($session);
        $escrowId = $session->escrowId();
        $row      = $this->rowOf($this->liveRow($session->userId), fn (EscrowRecord $r): UnlockedEscrow => $this->escrow->unlockAsUser($r, $session), $escrowId);
        return $this->readsOf($row, false, $escrowId);
    }

    /**
     * escrowReads() for the escrow of an archive opened with one of its old
     * locks. `reseal` says pinArchiveEscrow() would seal it under the
     * archive's objection key: a row that pins no keeper. An escrow the
     * archive keeps and that does not parse reads as a live row that does
     * not: `escrow-replaced`, the keepers asked all the same.
     */
    public function archiveEscrowReads(UnlockedArchive $opened): array
    {
        $escrowId = $opened->escrowId();
        $row      = $this->rowOf($this->archiveEscrow($opened->archive), fn (EscrowRecord $r): UnlockedEscrow => $opened->openEscrow($r), $escrowId);
        return $this->readsOf($row, true, $escrowId);
    }

    /**
     * Object to the read the keeper holds announced for case $caseRef: signed
     * from the session under the escrow's objection key, which only the master
     * key derives — whatever the database holds —, so that the administrator
     * cannot object, or withdraw an objection, in the holder's place. It goes
     * to every keeper that holds the case announced and has not retired the
     * escrow, among those escrowReads() asks. Throws RuntimeException when the
     * façade was given no keeper; EscrowKeeperException keeper-mismatch, before
     * any keeper is asked, when the façade's keeper is not the recorded one or
     * the record of former keepers is unreadable; otherwise, after asking them
     * all, when one could not be asked or did not record it — reason
     * `objection-partial` when others did, the message naming both —, or when
     * none holds it.
     *
     * An ObjectionKey is taken in place of a session, of the live vault or of
     * an archive (openForObjection()).
     */
    public function objectToEscrowRead(UnlockedVault|ObjectionKey $session, string $caseRef): void
    {
        if ($session instanceof ObjectionKey) {
            [$pinned] = self::rowAtOpening($session);
            $this->objectAt($pinned, $session->escrowId(), $caseRef, $session->signEscrowObjection($caseRef));
            return;
        }
        $this->currentRecord($session);
        $escrowId = $session->escrowId();
        [$pinned] = $this->rowOf($this->rowRecord($session->userId), fn (EscrowRecord $r): UnlockedEscrow => $this->escrow->unlockAsUser($r, $session), $escrowId);
        $this->objectAt($pinned, $escrowId, $caseRef, $session->signEscrowObjection($caseRef));
    }

    /**
     * objectToEscrowRead() for the escrow of an archive opened with one of its
     * old locks: an archive's read waits the delay like any other, and its
     * objection key derives from the archived vault's own master key — an
     * escrow the archive keeps and that does not parse is objected to all the
     * same, at every keeper.
     */
    public function objectToArchiveEscrowRead(UnlockedArchive $opened, string $caseRef): void
    {
        $escrowId = $opened->escrowId();
        [$pinned] = $this->rowOf($this->archiveEscrow($opened->archive), fn (EscrowRecord $r): UnlockedEscrow => $opened->openEscrow($r), $escrowId);
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
     * @throws EscrowAdminException when $adminPublicKey is not the one the database recorded, or none is
     * @throws EscrowEnvelopeMismatchException when the escrow row does not parse, or, without a keeper, does not open
     */
    public function rebindEscrowAdmin(UnlockedVault $session, string $adminPublicKey): bool
    {
        $this->currentRecord($session);
        $this->assertEscrowAdmin($adminPublicKey);
        $record = $this->rowRecord($session->userId);
        if ($record === false) {
            throw $this->mismatch($session->userId);
        }
        if ($record === null || EscrowVault::isAccountBound($record)) {
            return false;
        }
        if ($this->hasKeeper()) {
            return $this->sealEscrowToKeeper($session, $adminPublicKey);
        }
        try {
            $unlocked = $this->escrow->unlockAsUser($record, $session);
        } catch (Throwable $e) {
            throw $this->mismatch($session->userId, $e);
        }
        $rebound  = $this->escrow->rebindAdmin($record, $unlocked, $adminPublicKey);
        $unlocked->lock();
        $this->storage->replaceEscrow($session->generation(), $rebound);
        return true;
    }

    /**
     * Decrypt escrow fields as the USER (daily access) via the active session.
     *
     * @param array<string> $fieldNames Empty = all escrow fields: the holder's own
     * @return array<string, string>    field_name => plaintext
     * @throws EscrowEnvelopeMismatchException when the escrow row does not open its fields, or was deleted while
     *         they stayed
     */
    public function getEscrowFieldsAsUser(UnlockedVault $session, array $fieldNames = []): array
    {
        $this->currentRecord($session);
        $names = $fieldNames === [] ? null : array_values($fieldNames);
        $read  = $this->snapshot($session->userId, $names);
        if ($read === null) {
            if ($this->storage->loadEscrowFields($session->userId) === []) {
                return [];
            }
            // As in escrowReads(): only a row still absent is one deleted.
            $read = $this->snapshot($session->userId, $names) ?? throw $this->mismatch($session->userId);
        }
        return $this->openFields($session, ...$read);
    }

    /**
     * The fields $ciphertexts holds, opened by the envelope $record.
     *
     * @param array<string, string> $ciphertexts
     * @return array<string, string>
     * @throws EscrowEnvelopeMismatchException when the envelope does not open, or does not open them — a field
     *         rewritten too
     * @throws LegacyCipherUnavailableException a field written before 0.4.0, on a machine without its cipher
     */
    private function openFields(UnlockedVault $session, EscrowRecord $record, array $ciphertexts): array
    {
        try {
            $unlocked = $this->escrow->unlockAsUser($record, $session);
        } catch (Throwable $e) {
            throw $this->mismatch($session->userId, $e);
        }
        try {
            return EscrowFieldCrypter::decryptBatch($unlocked, $ciphertexts);
        } catch (LegacyCipherUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->mismatch($session->userId, $e);
        } finally {
            $unlocked->lock();
        }
    }

    private function mismatch(string $userId, ?Throwable $previous = null): EscrowEnvelopeMismatchException
    {
        return new EscrowEnvelopeMismatchException(
            "The escrow row of '{$userId}' does not open its fields — deleted, put back from a backup without them, or "
            . 'rewritten, or one of its fields rewritten: nothing is written, nothing retired, and putting back the row '
            . 'that wrote them, or the field as it was before it was rewritten, opens them again',
            0,
            $previous
        );
    }

    /**
     * EscrowSnapshot::read(), a row that does not parse raised as one that
     * does not open its fields.
     *
     * @param list<string>|null $fieldNames null for every field
     * @return array{0: EscrowRecord, 1: array<string, string>}|null
     */
    private function snapshot(string $userId, ?array $fieldNames = null): ?array
    {
        try {
            return EscrowSnapshot::read($this->storage, $userId, $fieldNames);
        } catch (UnreadableEscrowException $e) {
            throw $this->mismatch($userId, $e);
        }
    }

    /** The escrow $archive keeps; false for one that does not parse, as rowRecord() reads a live row; null for none. */
    private function archiveEscrow(ArchivedVault $archive): EscrowRecord|false|null
    {
        return $archive->escrowUnreadable ? false : $archive->escrow;
    }

    /** The escrow row of $userId; false for a row that does not parse — an outage of the storage passes as it is. */
    private function rowRecord(string $userId): EscrowRecord|false|null
    {
        try {
            return $this->storage->loadEscrow($userId);
        } catch (UnreadableEscrowException) {
            return false;
        }
    }

    /**
     * The escrow row of the live vault of $userId, as rowRecord() reads it —
     * and false for a row absent while its fields stayed: deleted. A first
     * deposit can land between the two reads: only a row still absent is one
     * deleted.
     */
    private function liveRow(string $userId): EscrowRecord|false|null
    {
        $record = $this->rowRecord($userId);
        if ($record === null && $this->storage->loadEscrowFields($userId) !== []) {
            $record = $this->rowRecord($userId) ?? false;
        }
        return $record;
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
        $keys    = $this->keeperKeys();
        $retired = $keys === null;
        for ($attempt = 1; ; $attempt++) {
            if ($attempt > self::DEPOSIT_ATTEMPTS) {
                throw new StaleEscrowException(
                    "The escrow of '{$session->userId}' kept appearing between this deposit's reads: nothing is written"
                );
            }
            $read = $this->snapshot($session->userId);
            if ($read === null) {
                if ($this->storage->loadEscrowFields($session->userId) !== []) {
                    if ($this->snapshot($session->userId) !== null) {
                        continue;
                    }
                    throw $this->mismatch($session->userId);
                }
                if (!$retired) {
                    $this->retireFromFormerKeepers($session, (array) $keys);
                    $retired = true;
                }
                $first = $this->escrow->create($session, $adminPublicKey, $keys);
                try {
                    $this->storage->createEscrow($session->generation(), $first['record']);
                } catch (EscrowExistsException) {
                    continue;
                } finally {
                    $first['unlocked']->lock();
                }
                $read = $this->snapshot($session->userId)
                    ?? throw new RuntimeException("The escrow of '{$session->userId}' was created, then is gone");
            }
            [$record, $ciphertexts] = $read;
            $kept = $this->openFields($session, $record, $ciphertexts);
            if (!$retired) {
                $this->retireFromFormerKeepers($session, (array) $keys);
                $retired = true;
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
     * $adminPublicKey is the one the database seals escrows to, as the operator
     * recorded it (adoptEscrowAdmin()). Nothing here records it: a key a
     * deposit recorded would be whichever the first deposit was given — on a
     * database whose record was erased too.
     *
     * @throws EscrowAdminException admin-unrecorded, or admin-mismatch
     */
    private function assertEscrowAdmin(string $adminPublicKey): void
    {
        $print = AdminKey::fingerprint($adminPublicKey);
        $held  = $this->storage->loadSetting(self::SETTING_ESCROW_ADMIN);
        if ($held === null) {
            throw new EscrowAdminException(
                'admin-unrecorded',
                "This database recorded no administrator key, and this call was given {$print}: nothing is sealed. The "
                . 'operator records it, proving it holds the key — SelfDataGuard::adoptEscrowAdmin(), or '
                . '`php bin/escrow-ceremony.php adopt-admin`'
            );
        }
        if (!hash_equals($held, $print)) {
            throw new EscrowAdminException(
                'admin-mismatch',
                "Escrows here are sealed to the administrator key {$held}, and this call was given {$print}: each "
                . 'deposit re-seals the compartment to the key it is given, so another would take it from the '
                . 'administrator who holds the recorded one'
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
     * The former keepers this façade was given, by fingerprint; those whose
     * keys did not come, by their index. Keys that came are kept for the life
     * of the façade.
     *
     * @return array{0: array<string, array{0: EscrowKeeper, 1: array{sign: string, box: string}}>, 1: list<int>}
     */
    private function givenFormers(): array
    {
        $byPrint = [];
        $silent  = [];
        foreach ($this->formerKeepers as $i => $former) {
            if (!isset($this->formerKeys[$i])) {
                try {
                    $keys = KeeperSeal::checkKeys($former->publicKeys());
                } catch (Throwable) {
                    $silent[] = $i;
                    continue;
                }
                $this->formerKeys[$i] = [KeeperSeal::fingerprint($keys), $keys];
            }
            [$print, $keys] = $this->formerKeys[$i];
            $byPrint[$print] = [$former, $keys];
        }
        return [$byPrint, $silent];
    }

    /**
     * Every former keeper: those the database recorded, and those this façade
     * was given — emptying the record hides none the façade knows. Each entry
     * carries the keeper and its keys, or the reason it cannot be asked:
     * keeper-changed, recorded and not given; keeper-unreachable, given and
     * gave no public keys — and a recorded one no given keeper answers to,
     * while one of them gives no keys: it may be that one.
     *
     * @return list<array{print: string, keeper: ?EscrowKeeper, keys: ?array{sign: string, box: string}, reason: ?string}>
     * @throws InvalidArgumentException when the record of former keepers is unreadable
     */
    private function formerEntries(): array
    {
        [$given, $silent] = $this->givenFormers();
        $unmatched = $silent === [] ? 'keeper-changed' : 'keeper-unreachable';
        $entries = [];
        foreach (KeeperSeal::formerPrints($this->storage->loadSetting(self::SETTING_FORMER_KEEPERS)) as $print) {
            $entries[$print] = isset($given[$print])
                ? ['print' => $print, 'keeper' => $given[$print][0], 'keys' => $given[$print][1], 'reason' => null]
                : ['print' => $print, 'keeper' => null, 'keys' => null, 'reason' => $unmatched];
        }
        foreach ($given as $print => [$keeper, $keys]) {
            $entries[$print] ??= ['print' => $print, 'keeper' => $keeper, 'keys' => $keys, 'reason' => null];
        }
        foreach ($silent as $i) {
            $name = get_class($this->formerKeepers[$i]) . " (former keeper {$i}, without public keys)";
            $entries[$name] = ['print' => $name, 'keeper' => null, 'keys' => null, 'reason' => 'keeper-unreachable'];
        }
        return array_values($entries);
    }

    /**
     * The escrow of $session retired from every former keeper — recorded or
     * given, the given ones written into the record first —, before the
     * escrow is written: none of them serves it again. Refused, nothing
     * retired, when the vault is frozen, when the façade's own keeper is among
     * them, when a record of former or forgotten keepers is unreadable, when
     * one of them cannot be asked — recorded and not given, or given and
     * without keys —, when one given was forgotten, or when the record of
     * former keepers keeps changing. Refused, nothing retired, the record
     * keeping the keepers written into it, when one of them became the
     * database's keeper meanwhile. Refused, the escrow untouched, when one
     * does not take the retirement: the record keeps the keepers written into
     * it, those asked before it took the retirement, and when the keeper the
     * row pins is among them, the view says `retired` until a deposit goes
     * through.
     *
     * @param array{sign: string, box: string} $keys the façade's keeper
     */
    private function retireFromFormerKeepers(UnlockedVault $session, array $keys): void
    {
        try {
            $formers = $this->formerEntries();
        } catch (InvalidArgumentException $e) {
            throw new EscrowKeeperException('keeper-mismatch', $e->getMessage() . ' — nothing is retired, nothing re-sealed', $e);
        }
        if ($formers === []) {
            return;
        }
        if ($this->storage->frozenState($session->userId) !== null) {
            throw new VaultFrozenException("The vault of '{$session->userId}' is frozen: nothing is retired, nothing re-sealed");
        }
        foreach ($formers as $former) {
            if ($this->samePrintAs($former['print'], $keys)) {
                throw new EscrowKeeperException(
                    'keeper-mismatch',
                    'The former keepers include the façade\'s own keeper — the record was changed in the database, or the '
                    . 'façade was given its keeper as a former one: nothing is retired, nothing re-sealed'
                );
            }
        }
        foreach ($formers as $former) {
            if ($former['reason'] !== null) {
                throw new EscrowKeeperException(
                    $former['reason'] === 'keeper-changed' ? 'keeper-mismatch' : $former['reason'],
                    ($former['reason'] === 'keeper-changed'
                        ? "The former keeper {$former['print']} was not given to this façade — the record holds it from "
                          . 'changeKeeper(), or from the deposit of a façade given it as former'
                        : "The former keeper {$former['print']} gives no public keys")
                    . ': the escrow cannot be retired from it — nothing is retired, nothing re-sealed'
                );
            }
        }
        $this->recordFormers(array_column($formers, 'print'));
        foreach ($formers as $former) {
            try {
                $former['keeper']->retire($session->escrowId(), $session->signEscrowRetirement($former['print']));
            } catch (Throwable $e) {
                throw new EscrowKeeperException('keeper-failed', "The former keeper {$former['print']} did not take the retirement: nothing is re-sealed", $e);
            }
        }
    }

    /**
     * $prints written into the database's record of former keepers before any
     * of them takes a retirement: changeKeeper() then refuses to promote one —
     * a keeper given as former that the deployment never replaced included —,
     * loudly, instead of a promotion that leaves the escrows retired there out
     * of the ceremony's reach. A keeper the operator forgot is refused.
     *
     * @param list<string> $prints
     * @throws EscrowKeeperException keeper-mismatch, a forgotten keeper, an unreadable record, or — once the
     *         record is written — one of $prints that became the database's keeper meanwhile; keeper-failed, the
     *         record kept changing under other writers
     */
    private function recordFormers(array $prints): void
    {
        try {
            for ($attempt = 1; ; $attempt++) {
                // The former keepers first, the forgotten ones after: forgetFormerKeeper() writes them the other way
                // round, so an act under way shows here before the write below can undo it.
                $held      = $this->storage->loadSetting(self::SETTING_FORMER_KEEPERS);
                $recorded  = KeeperSeal::formerPrints($held);
                $forgotten = array_values(array_intersect($prints, KeeperSeal::formerPrints(
                    $this->storage->loadSetting(self::SETTING_FORGOTTEN_KEEPERS),
                    'forgotten keepers'
                )));
                if ($forgotten !== []) {
                    throw new EscrowKeeperException(
                        'keeper-mismatch',
                        'This façade was given ' . implode(', ', $forgotten) . ' as a former keeper, and the operator forgot it: '
                        . 'nothing is retired, nothing re-sealed'
                    );
                }
                $missing = array_values(array_diff($prints, $recorded));
                if ($missing === []) {
                    break;
                }
                $written = json_encode([...$recorded, ...$missing], JSON_THROW_ON_ERROR);
                $done    = $held === null
                    ? hash_equals($this->storage->claimSetting(self::SETTING_FORMER_KEEPERS, $written), $written)
                    : $this->storage->replaceSetting(self::SETTING_FORMER_KEEPERS, $held, $written);
                if ($done) {
                    break;
                }
                if ($attempt >= self::DEPOSIT_ATTEMPTS) {
                    throw new EscrowKeeperException('keeper-failed', 'The record of former keepers kept changing: nothing is retired, nothing re-sealed');
                }
            }
        } catch (InvalidArgumentException $e) {
            throw new EscrowKeeperException('keeper-mismatch', $e->getMessage() . ' — nothing is retired, nothing re-sealed', $e);
        }
        $current = $this->storage->loadSetting(self::SETTING_KEEPER);
        if ($current !== null && in_array($current, $prints, true)) {
            throw new EscrowKeeperException(
                'keeper-mismatch',
                "The keeper {$current} became the database's keeper meanwhile, and this façade was given it as a former one: "
                . 'nothing is retired, nothing re-sealed'
            );
        }
    }

    /**
     * The keepers asked about the escrow whose row pins $pinned: the one it
     * pins first — the façade's, or a former one —, then the façade's and every
     * former keeper, recorded or given. When the row pins none (rowOf():
     * absent, not-keeper-sealed, not-pinned, escrow-replaced), the façade's
     * comes first. The first is the keeper the escrow answers to. A row pinned
     * to a keeper neither recorded nor given puts it first, as keeper-changed.
     *
     * @param ?array{sign: string, box: string} $pinned the keys the row pins, when it names the escrowId the session computes
     * @return list<array{print: string, keeper: ?EscrowKeeper, keys: ?array{sign: string, box: string}, reason: ?string}>
     * @throws EscrowKeeperException keeper-mismatch, the façade's keeper is not the recorded one, or the record of
     *         former keepers is unreadable — a façade's keeper without keys is an entry, keeper-unreachable
     */
    private function keepersOf(?array $pinned): array
    {
        try {
            try {
                $ours    = (array) $this->keeperKeys();
                $current = ['print' => KeeperSeal::fingerprint($ours), 'keeper' => $this->keeper, 'keys' => $ours, 'reason' => null];
            } catch (EscrowKeeperException) {
                $recorded = $this->storage->loadSetting(self::SETTING_KEEPER) ?? get_class($this->keeper) . ' (without public keys)';
                $current  = ['print' => $recorded, 'keeper' => null, 'keys' => null, 'reason' => 'keeper-unreachable'];
            }
            $formers = array_values(array_filter(
                $this->formerEntries(),
                static fn (array $former): bool => $former['print'] !== $current['print']
            ));
        } catch (InvalidArgumentException $e) {
            throw new EscrowKeeperException('keeper-mismatch', $e->getMessage(), $e);
        }
        if ($pinned === null || KeeperSeal::fingerprint($pinned) === $current['print']) {
            return [$current, ...$formers];
        }
        $first = KeeperSeal::fingerprint($pinned);
        $head  = ['print' => $first, 'keeper' => null, 'keys' => null, 'reason' => 'keeper-changed'];
        $rest  = [];
        foreach ($formers as $former) {
            if ($former['print'] === $first) {
                $head = $former;
            } else {
                $rest[] = $former;
            }
        }
        return [$head, $current, ...$rest];
    }

    /**
     * What $keeper signs for escrow $escrowId, verified under $keys.
     *
     * @param array{sign: string, box: string} $keys
     * @return array{releases: list<array{case: string, seq: int, at: int, box: string}>,
     *               pending: list<array{case: string, due: int, objected: bool, box: string}>, retired: bool}
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
     * it pins none: absent, not-keeper-sealed, not-pinned, escrow-replaced, or
     * unreadable — a row that does not open, or $record false: one that does
     * not parse, or one absent while its fields stayed (escrowReads()) —,
     * and the KeeperSeal::boxPrint() of the box a keeper-sealed row holds.
     *
     * @param callable(EscrowRecord): UnlockedEscrow $open
     * @return array{0: ?array{sign: string, box: string}, 1: ?string, 2: ?string}
     */
    private function rowOf(EscrowRecord|false|null $record, callable $open, string $escrowId): array
    {
        $box = $record instanceof EscrowRecord && EscrowVault::isKeeperSealed($record)
            ? KeeperSeal::boxPrint(KeeperSeal::outerOf($record->wrapAdmin))
            : null;
        if ($record === null) {
            return [null, 'absent', $box];
        }
        if ($record === false) {
            return [null, 'unreadable', $box];
        }
        if (!EscrowVault::isKeeperSealed($record)) {
            return [null, 'not-keeper-sealed', $box];
        }
        try {
            $unlocked = $open($record);
        } catch (Throwable) {
            return [null, 'unreadable', $box];
        }
        try {
            $rowId = (string) $unlocked->escrowId;
            $keys  = $unlocked->keeper;
        } finally {
            $unlocked->lock();
        }
        if ($keys === null) {
            return [null, 'not-pinned', $box];
        }
        return hash_equals($rowId, $escrowId) ? [$keys, null, $box] : [null, 'escrow-replaced', $box];
    }

    /**
     * The objection to case $caseRef, signed with $token, sent to the keepers
     * of keepersOf(): see objectToEscrowRead().
     *
     * @param ?array{sign: string, box: string} $pinned
     */
    private function objectAt(?array $pinned, string $escrowId, string $caseRef, string $token): void
    {
        if (!$this->hasKeeper()) {
            throw new RuntimeException('This façade was given no keeper: there is no read to object to');
        }
        $recorded = [];
        $failed   = [];
        foreach ($this->keepersOf($pinned) as $k) {
            try {
                if ($k['reason'] !== null) {
                    throw new EscrowKeeperException($k['reason'], 'This keeper cannot be asked');
                }
                $view = $this->attestAt($k['keeper'], $k['keys'], $escrowId);
            } catch (EscrowKeeperException $e) {
                $failed[] = [$k['print'], $e->reason];
                continue;
            }
            if ($view['retired'] || !in_array($caseRef, array_column($view['pending'], 'case'), true)) {
                continue;
            }
            try {
                $k['keeper']->object($escrowId, $caseRef, $token);
                $recorded[] = $k['print'];
            } catch (Throwable) {
                $failed[] = [$k['print'], 'keeper-failed'];
            }
        }
        if ($failed !== []) {
            throw new EscrowKeeperException(
                $recorded !== [] ? 'objection-partial' : (in_array('keeper-failed', array_column($failed, 1), true) ? 'keeper-failed' : $failed[0][1]),
                'The objection was recorded by ' . ($recorded === [] ? 'no keeper' : implode(', ', $recorded))
                . '; these keepers could not be asked or did not record it, and the read they hold may go through once due: '
                . implode(', ', array_map(static fn (array $f): string => "{$f[0]} ({$f[1]})", $failed))
            );
        }
        if ($recorded === []) {
            throw new EscrowKeeperException('keeper-failed', "No keeper of this escrow holds a read announced for case '{$caseRef}': there is nothing to object to");
        }
    }

    /**
     * The view of an escrow whose escrowId, computed from the master key, is
     * $escrowId. Under a keeper, every keeper of keepersOf() is asked about
     * that escrowId, whatever the row holds: a row that is absent, put back
     * from a backup or rewritten cannot hide what a keeper released or holds
     * announced. A keeper that cannot be asked does not hide what the others
     * attest: the view is then not established, names it, and lists the rest.
     *
     * @param array{0: ?array{sign: string, box: string}, 1: ?string, 2: ?string} $rowState what rowOf() said of the row
     * @return array<string, mixed> see escrowReads()
     */
    private function readsOf(array $rowState, bool $archive, string $escrowId): array
    {
        $out = ['established' => false, 'reason' => null, 'keeper' => null, 'pinned' => false, 'box' => $rowState[2], 'reads' => [], 'pending' => [], 'unanswered' => [], 'reseal' => false];
        [$pinned, $row] = $rowState;
        if ($pinned !== null) {
            $out = ['pinned' => true, 'keeper' => KeeperSeal::fingerprint($pinned)] + $out;
        }
        if (!$this->hasKeeper()) {
            return match ($row) {
                'absent'     => ['established' => true] + $out,
                null         => ['reason' => 'no-keeper'] + $out,
                'unreadable' => ['reason' => 'escrow-replaced'] + $out,
                default      => ['reason' => $row] + $out,
            };
        }
        try {
            $keepers = $this->keepersOf($pinned);
        } catch (EscrowKeeperException $e) {
            return ['reason' => $e->reason] + $out;
        }
        $reads       = [];
        $pending     = [];
        $missing     = [];
        $headRetired = false;
        foreach ($keepers as $i => $k) {
            try {
                if ($k['reason'] !== null) {
                    throw new EscrowKeeperException($k['reason'], 'This keeper cannot be asked');
                }
                $view = $this->attestAt($k['keeper'], $k['keys'], $escrowId);
            } catch (EscrowKeeperException $e) {
                $missing[] = [$k['print'], $e->reason];
                continue;
            }
            $from  = static fn (array $entry): array => $entry + ['keeper' => $k['print']];
            $reads = [...$reads, ...array_map($from, $view['releases'])];
            if (!$view['retired']) {
                $pending = [...$pending, ...array_map($from, $view['pending'])];
            }
            $headRetired = $headRetired || ($i === 0 && $view['retired']);
        }
        $ownHead = $keepers[0]['keeper'] === $this->keeper || $keepers[0]['print'] === $this->storage->loadSetting(self::SETTING_KEEPER);
        $seen    = [
            'keeper'     => $keepers[0]['print'],
            'reads'      => $reads,
            'pending'    => $pending,
            'unanswered' => array_map(static fn (array $m): array => ['keeper' => $m[0], 'reason' => $m[1]], $missing),
            'reseal'  => $archive
                ? $row === 'not-keeper-sealed' || $row === 'not-pinned'
                : $row !== 'absent' && $row !== 'unreadable' && !($row === null && $ownHead),
        ] + $out;
        if ($headRetired) {
            return ['reason' => 'retired'] + $seen;
        }
        if ($row !== null && $row !== 'absent' && ($reads !== [] || $pending !== [] || $row === 'escrow-replaced' || $row === 'unreadable')) {
            return ['reason' => 'escrow-replaced'] + $seen;
        }
        if ($row === 'absent' && ($reads !== [] || $pending !== [])) {
            return ['reason' => 'escrow-replaced'] + $seen;
        }
        if ($row === 'not-keeper-sealed' || $row === 'not-pinned') {
            return ['reason' => $row, 'keeper' => null] + $seen;
        }
        if ($missing !== []) {
            return ['reason' => $missing[0][1], 'keeper' => $missing[0][0]] + $seen;
        }
        if ($row === null) {
            return ['established' => true] + $seen;
        }
        return $row === 'absent'
            ? ['established' => true, 'keeper' => null] + $seen
            : ['reason' => $row, 'keeper' => null] + $seen;
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
    /**
     * The pepper given is the one the database recorded — recorded here on a
     * database that holds no vault yet. Once per façade.
     */
    private function assertPepper(): void
    {
        if ($this->pepperChecked) {
            return;
        }
        $check = $this->pepper->check();
        $held  = $this->storage->loadSetting(self::SETTING_PEPPER);
        if ($held === null) {
            if ($this->storage->hasVaults()) {
                throw new InvalidArgumentException(
                    'This database holds vaults and never recorded its pepper: the operator lays it over every lock '
                    . 'once, with no secret of the users in hand — bin/pepper.php adopt (SelfDataGuard::adoptPepper())'
                );
            }
            $held = $this->storage->claimSetting(self::SETTING_PEPPER, $check);
        }
        if (!hash_equals($held, $check)) {
            throw new InvalidArgumentException(
                "This pepper is not the one this database recorded (recorded {$held}, given {$check}): every lock "
                . 'it covers was sealed under the recorded one'
            );
        }
        $this->pepperChecked = true;
    }

    /**
     * The form of $lock in the live vault of $userId — native, layer, bare,
     * absent or unreadable —, without any Argon2id. Once the upgrade is over,
     * a memorized lock still in the layer form is one whose holder should set
     * the memorized word again: sealed from a string that is not the canonical
     * fingerprint, it is never converted.
     */
    public function lockState(string $userId, Lock $lock): string
    {
        $record = $this->load($userId);
        $wrap   = match ($lock) {
            Lock::Password   => $record->wrapPwd,
            Lock::Memorized  => $record->wrapRecov,
            Lock::Passphrase => $record->wrapPhrase,
        };
        if ($wrap === null) {
            return 'absent';
        }
        return LockPepper::covers($lock) ? $this->pepper->formOf($lock, $wrap, $userId, $record->userSalt) : 'bare';
    }

    /**
     * The operator's pass of an upgrade from 0.9.x: a layer under the pepper
     * over every envelope of wrap_pwd and wrap_recov that has none — live
     * vaults and archives, with no secret of the users in hand —, then the
     * check of the pepper recorded. Run with the blind-index key the database
     * recorded, before any façade of 0.10.0 serves it. Run again, it lays only
     * what is still bare. A frozen vault is left as it is and named; a vault
     * or an archive whose seal does not hold is never laid over, and named. A
     * live vault written before 0.7.0 carries no seal: the pass seals it as
     * sealProfile() does, trusting the row as it stands — run it while nothing
     * else writes to the database.
     *
     * The pass records which pepper it runs under before it writes: a pass
     * under another pepper is refused. Over layers of a wrong pepper, a second
     * layer would close every lock it covered.
     *
     * @return array{vaults: int, archives: int, frozen: list<string>, refused: list<string>}
     *         how many live vaults and archives were laid over; the userIds left out
     * @throws InvalidArgumentException when the blind-index key or the pepper is not the one the database recorded
     */
    public static function adoptPepper(StorageInterface $storage, #[\SensitiveParameter] string $blindKey, PepperSource $source): array
    {
        [$layers, $sealKey] = self::passOver($storage, $blindKey, $source);
        $check = $layers->check();
        $pass  = $storage->loadSetting(self::SETTING_PEPPER_PASS) ?? $storage->claimSetting(self::SETTING_PEPPER_PASS, $check);
        if (!hash_equals($pass, $check)) {
            throw new InvalidArgumentException(
                "A pass under another pepper ({$pass}) was run on this database: nothing is laid over under {$check}"
            );
        }
        $report = ['vaults' => 0, 'archives' => 0, 'frozen' => [], 'refused' => []];
        self::eachLockSet($storage, $sealKey, static function (VaultRecord $record) use ($layers): ?VaultRecord {
            $laid = $record;
            foreach ([Lock::Password, Lock::Memorized] as $lock) {
                $wrap = $lock === Lock::Password ? $laid->wrapPwd : $laid->wrapRecov;
                if ($wrap !== null && $layers->formOf($lock, $wrap, $record->userId, $record->userSalt) === 'bare') {
                    $over = $layers->lay($lock, $wrap, $record->userId, $record->userSalt);
                    $laid = $lock === Lock::Password ? $laid->withWrapPwd($over, $laid->updatedAt) : $laid->withWrapRecov($over, $laid->updatedAt);
                }
            }
            return $laid === $record ? null : $laid;
        }, $report, sealsOld: true);
        $recorded = $storage->loadSetting(self::SETTING_PEPPER) ?? $storage->claimSetting(self::SETTING_PEPPER, $check);
        if (!hash_equals($recorded, $check)) {
            throw new InvalidArgumentException("This database recorded the pepper {$recorded}: the pass ran under {$check}");
        }
        return $report;
    }

    /**
     * The inverse pass, for a return to 0.9.x before the upgrade is declared
     * over: every layer of this pepper taken off, the bare envelope under it
     * put back. A native envelope has no bare one under it: its vault is
     * named, and stays. The check of the pepper stays recorded.
     *
     * Until the inverse pass is run, 0.9.x opens no lock laid over: the
     * password fails as a wrong one. The host closes its recovery route as
     * long as it serves 0.9.x — under it, the right memorized word on a
     * layered lock answers as a wrong one.
     *
     * @return array{vaults: int, archives: int, frozen: list<string>, refused: list<string>}
     */
    public static function unlayPepper(StorageInterface $storage, #[\SensitiveParameter] string $blindKey, PepperSource $source): array
    {
        [$layers, $sealKey] = self::passOver($storage, $blindKey, $source);
        $report = ['vaults' => 0, 'archives' => 0, 'frozen' => [], 'refused' => []];
        self::eachLockSet($storage, $sealKey, static function (VaultRecord $record) use ($layers): ?VaultRecord {
            $bare = $record;
            foreach ([Lock::Password, Lock::Memorized] as $lock) {
                $wrap = $lock === Lock::Password ? $bare->wrapPwd : $bare->wrapRecov;
                if ($wrap === null) {
                    continue;
                }
                $form = $layers->formOf($lock, $wrap, $record->userId, $record->userSalt);
                if ($form === 'native' || $form === 'unreadable') {
                    throw new RuntimeException("{$lock->value}: {$form}, no bare envelope under it");
                }
                if ($form === 'layer') {
                    $under = $layers->unlay($lock, $wrap, $record->userId, $record->userSalt);
                    $bare  = $lock === Lock::Password ? $bare->withWrapPwd($under, $bare->updatedAt) : $bare->withWrapRecov($under, $bare->updatedAt);
                }
            }
            return $bare === $record ? null : $bare;
        }, $report);
        return $report;
    }

    /**
     * How many envelopes of wrap_pwd and wrap_recov are in each form, live and
     * archived, without any Argon2id: the end of the upgrade is declared once
     * nothing is bare.
     *
     * @return array{native: int, layer: int, bare: int, unreadable: int, unsealed: list<string>}
     */
    public static function pepperStatus(StorageInterface $storage, #[\SensitiveParameter] string $blindKey, PepperSource $source): array
    {
        [$layers, $sealKey] = self::passOver($storage, $blindKey, $source);
        $count = ['native' => 0, 'layer' => 0, 'bare' => 0, 'unreadable' => 0, 'unsealed' => []];
        $report = ['vaults' => 0, 'archives' => 0, 'frozen' => [], 'refused' => []];
        self::eachLockSet($storage, $sealKey, static function (VaultRecord $record) use ($layers, &$count): ?VaultRecord {
            foreach ([[Lock::Password, $record->wrapPwd], [Lock::Memorized, $record->wrapRecov]] as [$lock, $wrap]) {
                if ($wrap !== null) {
                    $count[$layers->formOf($lock, $wrap, $record->userId, $record->userSalt)]++;
                }
            }
            return null;
        }, $report);
        $count['unsealed'] = $report['refused'];
        return $count;
    }

    /**
     * The pepper and the seal key a pass works under, once the blind-index
     * key is the one the database recorded — under another, every seal would
     * read as tampering.
     *
     * @return array{0: LockPepper, 1: string}
     */
    private static function passOver(StorageInterface $storage, #[\SensitiveParameter] string $blindKey, PepperSource $source): array
    {
        $held = $storage->loadSetting(self::SETTING_BLIND_KEY);
        if ($held === null || !hash_equals($held, BlindIndex::keyCheck($blindKey))) {
            throw new InvalidArgumentException(
                'The pass runs with the blind-index key this database recorded: it checks every seal before it writes'
            );
        }
        $layers = new LockPepper($source, PepperForms::AcceptBare);
        $recorded = $storage->loadSetting(self::SETTING_PEPPER);
        if ($recorded !== null && !hash_equals($recorded, $layers->check())) {
            throw new InvalidArgumentException(
                "This database recorded the pepper {$recorded}, and the pass was given {$layers->check()}"
            );
        }
        return [$layers, ProfileSeal::key($blindKey)];
    }

    /**
     * $change on every live vault and every archive whose seal holds, page
     * after page; what it returns, when not null, is sealed and written back.
     * A frozen live vault and a record whose seal does not hold are named in
     * $report, and the pass goes on. A live vault written before 0.7.0, which
     * carries no seal, is handed to $change as well; $sealsOld seals it even
     * when $change leaves it as it is.
     *
     * @param callable(VaultRecord): ?VaultRecord $change
     * @param array{vaults: int, archives: int, frozen: list<string>, refused: list<string>} $report
     */
    private static function eachLockSet(StorageInterface $storage, string $sealKey, callable $change, array &$report, bool $sealsOld = false): void
    {
        $holds = static fn (VaultRecord $r): bool => $r->profileSeal !== null
            && ProfileSeal::holds($sealKey, $r->profileSeal, $r, $r->kdfOpslimit, $r->kdfMemlimit);
        $seal = static fn (VaultRecord $r): VaultRecord => $r->withProfileSeal(ProfileSeal::of($sealKey, $r, $r->kdfOpslimit, $r->kdfMemlimit));
        for ($after = ''; ($page = $storage->accountIds($after)) !== []; $after = (string) end($page)) {
            foreach ($page as $userId) {
                $live = $storage->findVault($userId);
                if ($live !== null) {
                    $old = $live->profileSeal === null && !ProfileSeal::expected($live);
                    try {
                        if (!$old && !$holds($live)) {
                            $report['refused'][] = $userId;
                        } elseif (($changed = $change($live) ?? ($old && $sealsOld ? $live : null)) !== null) {
                            $storage->updateVault($seal($changed));
                            $report['vaults']++;
                        }
                    } catch (VaultFrozenException) {
                        $report['frozen'][] = $userId;
                    } catch (RuntimeException $e) {
                        $report['refused'][] = "{$userId} ({$e->getMessage()})";
                    }
                }
                foreach ($storage->listArchives($userId) as $listed) {
                    $archive = $storage->loadArchive($userId, $listed['id']);
                    if ($archive === null) {
                        continue;
                    }
                    try {
                        if (!$holds($archive->record)) {
                            $report['refused'][] = "{$userId}/{$listed['id']}";
                        } elseif (($changed = $change($archive->record)) !== null) {
                            $storage->replaceArchiveVault($userId, $listed['id'], $seal($changed));
                            $report['archives']++;
                        }
                    } catch (RuntimeException $e) {
                        $report['refused'][] = "{$userId}/{$listed['id']} ({$e->getMessage()})";
                    }
                }
            }
        }
    }

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
        $this->assertPepper();
        $record = $this->storage->loadVault($userId);
        $this->assertSameUser($userId, $record->userId);
        $this->assertProfileSealed($record, $record->kdfOpslimit, $record->kdfMemlimit);
        return $record;
    }

    /**
     * $attempt behind the throttle: admitted before it reads anything, then
     * settled once with what the secret did — Refused for a wrong secret, an
     * absent lock, an account or an archive that does not exist; Unavailable
     * for anything that tells nothing of the secret.
     *
     * @template T
     * @param callable(): T $attempt
     * @return T
     */
    private function throttled(Door $door, string $userId, callable $attempt): mixed
    {
        $this->throttle->admit($door, $userId);
        try {
            $result = $attempt();
        } catch (WrongSecretException | VaultNotFoundException $e) {
            $this->throttle->settle($door, $userId, Outcome::Refused);
            throw $e;
        } catch (Throwable $e) {
            $this->throttle->settle($door, $userId, Outcome::Unavailable);
            throw $e;
        }
        $this->throttle->settle($door, $userId, Outcome::Opened);
        return $result;
    }

    /**
     * The live vault of $userId, opened by $secret behind the throttle. What
     * follows the opening — a re-seal, a write — is not the throttle's to hear.
     *
     * @return array{0: VaultRecord, 1: UnlockedVault}
     */
    private function openThrottled(Door $door, string $userId, Lock $lock, #[\SensitiveParameter] string $secret): array
    {
        return $this->throttled($door, $userId, function () use ($userId, $lock, $secret): array {
            $record = $this->orInVain(fn (): VaultRecord => $this->load($userId), $userId, $lock, $secret);
            return [$record, $this->vault->unlock($record, $lock, $secret)];
        });
    }

    /**
     * $load() — or, for an account or an archive that does not exist, the
     * derivation a wrong secret costs, then its VaultNotFoundException: the
     * time does not tell which.
     *
     * @template T
     * @param callable(): T $load
     * @return T
     */
    private function orInVain(callable $load, string $userId, Lock $lock, #[\SensitiveParameter] string $secret): mixed
    {
        try {
            return $load();
        } catch (VaultNotFoundException $e) {
            $this->vault->deriveInVain($userId, $lock, $secret);
            throw $e;
        }
    }

    /**
     * The live vault of $userId or its archive $archiveId, opened by $secret
     * behind the throttle whatever its seal says, and its escrow row. What
     * follows the opening is not the throttle's to hear.
     *
     * @return array{0: UnlockedVault, 1: EscrowRecord|false|null}
     */
    private function openForEscrow(Door $door, string $userId, ?string $archiveId, Lock $lock, #[\SensitiveParameter] string $secret): array
    {
        return $this->throttled($door, $userId, function () use ($userId, $archiveId, $lock, $secret): array {
            [$record, $profiles, $escrow] = $this->orInVain(fn (): array => $this->forObjection($userId, $archiveId), $userId, $lock, $secret);
            return [$this->unlockUnderAny($record, $lock, $secret, $profiles), $escrow];
        });
    }

    /**
     * For openForEscrow(): the live vault of $userId or its archive
     * $archiveId, read without its seal, the profiles to derive under, and its
     * escrow row.
     *
     * @return array{0: VaultRecord, 1: non-empty-list<array{0: int, 1: int}>, 2: EscrowRecord|false|null}
     */
    private function forObjection(string $userId, ?string $archiveId): array
    {
        $this->assertPepper();
        if ($archiveId === null) {
            $record = $this->storage->loadVault($userId);
            $this->assertSameUser($userId, $record->userId);
            return [$record, $this->profilesToTry($record, $record->kdfOpslimit, $record->kdfMemlimit), $this->liveRow($userId)];
        }
        $archive = $this->storage->loadArchive($userId, $archiveId)
            ?? throw new VaultNotFoundException("No archive '{$archiveId}' for this account");
        $this->assertSameUser($userId, $archive->record->userId);
        return [$archive->record, $this->profilesToTry($archive->record, $archive->kdfOpslimit, $archive->kdfMemlimit), $this->archiveEscrow($archive)];
    }

    /**
     * The profile the seal of $record vouches for — the one recorded, or a
     * published one it holds under —; with no seal that holds, every published
     * profile. Never one the seal does not vouch for, which whoever writes the
     * database chooses.
     *
     * @return non-empty-list<array{0: int, 1: int}>
     */
    private function profilesToTry(VaultRecord $record, int $opslimit, int $memlimit): array
    {
        $published = array_values(array_unique(Primitives::PUBLISHED_PROFILES, SORT_REGULAR));
        if ($record->profileSeal !== null) {
            foreach ([[$opslimit, $memlimit], ...$published] as [$ops, $mem]) {
                if (ProfileSeal::holds($this->sealKey, $record->profileSeal, $record, $ops, $mem)) {
                    return [[$ops, $mem]];
                }
            }
        }
        return $published;
    }

    /**
     * $record opened by $secret under the first of $profiles it opens under.
     *
     * @param non-empty-list<array{0: int, 1: int}> $profiles
     * @throws WrongSecretException when it opens under none
     */
    private function unlockUnderAny(VaultRecord $record, Lock $lock, #[\SensitiveParameter] string $secret, array $profiles): UnlockedVault
    {
        $wrong = null;
        foreach ($profiles as [$opslimit, $memlimit]) {
            try {
                return $this->vault->unlock($record, $lock, $secret, $opslimit, $memlimit);
            } catch (WrongSecretException $e) {
                $wrong = $e;
            }
        }
        throw $wrong;
    }

    /**
     * What the escrow row said when $key was opened (rowOf()).
     *
     * @return array{0: ?array{sign: string, box: string}, 1: ?string, 2: ?string}
     */
    private static function rowAtOpening(ObjectionKey $key): array
    {
        return self::inScope(ObjectionKey::class, static fn (): array => [$key->pinned, $key->row, $key->box]);
    }

    /**
     * $fn run in the scope of $class. The façade alone builds an
     * UnlockedArchive or an ObjectionKey, whose constructors are private, and
     * alone draws the objection seed out of a session into an ObjectionKey: the
     * type of each says which door it came through. That is the façade's rule,
     * no barrier against code that does the same.
     *
     * @template T
     * @param class-string $class
     * @param Closure(): T $fn
     * @return T
     */
    private static function inScope(string $class, Closure $fn): mixed
    {
        return Closure::bind($fn, null, $class)();
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
     * Archive $archiveId, its seal checked under the profile it records:
     * brought back live, that is the profile it opens under. One without a
     * seal passes only if written before 0.7.0.
     */
    private function sealedArchive(string $userId, string $archiveId): ArchivedVault
    {
        $archive = $this->storage->loadArchive($userId, $archiveId)
            ?? throw new VaultNotFoundException("No archive '{$archiveId}' for this account");
        $this->assertSameUser($userId, $archive->record->userId);
        if ($archive->record->profileSeal === null && !ProfileSeal::expected($archive->record)) {
            return $archive;
        }
        $this->assertProfileSealed($archive->record, $archive->record->kdfOpslimit, $archive->record->kdfMemlimit, archive: true);
        return $archive;
    }

    /**
     * The fields $archive indexed that came back live without an index: one
     * whose index, once the archive is live, does not name its account, and
     * one it kept no index of — an archive written before 0.8.0, or a field
     * that had already lost its own. Null when the storage could not be asked:
     * the archive is live by then, and the caller keeps the id the swap
     * returned.
     *
     * @return list<string>|null
     */
    private function indexesNotBack(ArchivedVault $archive): ?array
    {
        $lost = [];
        try {
            foreach ($archive->privateFields as $fieldName => $field) {
                $index = $archive->blindIndexes[$fieldName] ?? $archive->sharedIndexes[$fieldName] ?? null;
                if ($index === null
                    ? $field['wasIndexed']
                    : !in_array($archive->record->userId, $this->storage->findUserIdsByIndex((string) $fieldName, $index), true)
                ) {
                    $lost[] = (string) $fieldName;
                }
            }
        } catch (\Exception) {
            return null;
        }
        return $lost;
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
        return ['blindKey' => '[redacted]', 'sealKey' => '[redacted]', 'pepper' => '[redacted]'];
    }
}
