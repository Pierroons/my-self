<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use DateTimeImmutable;
use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\LegacyCipherUnavailableException;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Escrow\AdminKey;
use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\Escrow\EscrowVault;
use Pierroons\SelfDataGuard\Escrow\KeeperSeal;
use Pierroons\SelfDataGuard\Escrow\UnlockedEscrow;
use Pierroons\SelfDataGuard\Fields\FieldCrypter;
use RuntimeException;

/**
 * Holds the unwrapped data_master_key in memory for the duration of an
 * authenticated session.
 *
 * After lock() (or destruction), the key is best-effort wiped via
 * sodium_memzero. PHP cannot guarantee true memory wiping, but combined with
 * short session timeouts and sensitive deployment hygiene (no swap, etc.),
 * this is acceptable for the threat model.
 *
 * It also carries the user_salt of the vault it was opened on. A vault
 * re-created for the same userId has another salt, so a session from the old
 * one cannot write into the new one (StaleVaultException).
 *
 * And the Argon2id profile it was opened under — proven by the opening, since
 * the key depends on it. A re-seal uses this one, not the profile read from
 * the database at that moment, which whoever writes the database can lower.
 *
 * It never hands the key out: what needs it is done here — a field encrypted
 * or decrypted under the AAD of its name, the escrow created or opened, a lock
 * re-sealed from its secret. A method that took an AAD or a key from the caller
 * would hand it out in two calls: wrap it under a key the caller holds, then
 * unwrap it there.
 *
 * NEVER serialize, log, or persist this object.
 */
final class UnlockedVault
{
    private const OBJECTION_SEED_TAG = "selfdataguard/objection-seed/v1\0";

    private string $masterKey;
    private bool $locked = false;

    public function __construct(
        public readonly string $userId,
        #[\SensitiveParameter] string $masterKey,
        public readonly string $vaultSalt,
        public readonly int $kdfOpslimit,
        public readonly int $kdfMemlimit,
        private readonly ?LockPepper $pepper = null
    ) {
        if (strlen($masterKey) !== Primitives::KEY_LEN) {
            throw new RuntimeException(
                'masterKey must be exactly ' . Primitives::KEY_LEN . ' bytes'
            );
        }
        if (strlen($vaultSalt) !== Primitives::SALT_LEN) {
            throw new RuntimeException(
                'vaultSalt must be exactly ' . Primitives::SALT_LEN . ' bytes'
            );
        }
        $this->masterKey = $masterKey;
    }

    /** A private field, bound to this account and to its name. */
    public function encryptField(string $fieldName, #[\SensitiveParameter] string $plaintext): EncryptedBlob
    {
        return Primitives::encrypt($plaintext, $this->key(), FieldCrypter::aadFor($this->userId, $fieldName));
    }

    /**
     * @throws RuntimeException if the tag fails: another key, another account,
     *                          another field name, or a tampered ciphertext
     */
    public function decryptField(string $fieldName, EncryptedBlob $blob): string
    {
        return Primitives::decryptAcrossFormats(
            $blob,
            $this->key(),
            FieldCrypter::aadFor($this->userId, $fieldName),
            FieldCrypter::legacyAadFor($this->userId, $fieldName)
        );
    }

    /**
     * The envelope of $lock, sealed anew from its secret under this vault's salt
     * and the profile this session was opened under.
     */
    public function sealLock(Lock $lock, #[\SensitiveParameter] string $secret): EncryptedBlob
    {
        if ($this->pepper !== null && LockPepper::covers($lock)) {
            return $this->pepper->seal(
                $lock, $secret, $this->key(), $this->userId, $this->vaultSalt, $this->kdfOpslimit, $this->kdfMemlimit
            );
        }
        $kek = UserVault::keyFor($lock, $secret, $this->vaultSalt, $this->kdfOpslimit, $this->kdfMemlimit);
        try {
            return Primitives::encrypt($this->key(), $kek, UserVault::wrapAadFor($this->userId));
        } finally {
            Primitives::zeroize($kek);
        }
    }

    /**
     * A new escrow compartment: its key drawn here, wrapped under the master key
     * for the user, sealed to the administrator's public key.
     *
     * Given a keeper's public keys, wrap_user also carries the objection seed,
     * derived from the master key — the same at every deposit of this vault —,
     * the escrowId, which is the fingerprint of its public key, and those keys;
     * wrap_admin is sealed again to the keeper (KeeperSeal). What the holder
     * sealed under the master key, the administrator cannot rewrite: the keeper
     * a session asks is the one named at the deposit, not the one the database
     * names today, and the escrowId is computed, not read from the database.
     *
     * $createdAt and $revision carry over from the compartment a new key
     * replaces; the storage compares the revision when it writes.
     *
     * @param array{sign: string, box: string}|null $keeperPublicKeys
     * @return array{record: EscrowRecord, unlocked: UnlockedEscrow}
     */
    public function createEscrow(
        string $adminPublicKeyB64,
        DateTimeImmutable $now,
        ?array $keeperPublicKeys = null,
        ?DateTimeImmutable $createdAt = null,
        int $revision = 0
    ): array {
        $escrowKey = Primitives::randomBytes(Primitives::KEY_LEN);
        try {
            return $this->sealEscrowKey($escrowKey, $adminPublicKeyB64, $now, $keeperPublicKeys, $createdAt ?? $now, $revision);
        } finally {
            Primitives::zeroize($escrowKey);
        }
    }

    /**
     * The compartment $record holds, sealed again to the keeper
     * $keeperPublicKeys under this vault's objection key, its escrow key kept:
     * the fields it encrypts stay as they are. It is how the escrow of an
     * archive, whose fields no write changes, comes under its holder's
     * objection key (SelfDataGuard::pinArchiveEscrow()). The record keeps its
     * creation date and its revision, which the storage compares.
     *
     * @param array{sign: string, box: string} $keeperPublicKeys
     * @throws RuntimeException if the record is another account's, or wrap_user does not open
     */
    public function pinEscrow(
        EscrowRecord $record,
        string $adminPublicKeyB64,
        array $keeperPublicKeys,
        DateTimeImmutable $now
    ): EscrowRecord {
        $plain = $this->wrapUserPlain($record);
        try {
            EscrowVault::unwrapUserPlain($record->userId, $plain)->lock();
            $escrowKey = substr($plain, 0, Primitives::KEY_LEN);
        } finally {
            Primitives::zeroize($plain);
        }
        try {
            $sealed = $this->sealEscrowKey($escrowKey, $adminPublicKeyB64, $now, $keeperPublicKeys, $record->createdAt, $record->revision);
        } finally {
            Primitives::zeroize($escrowKey);
        }
        $sealed['unlocked']->lock();
        return $sealed['record'];
    }

    /**
     * $escrowKey wrapped under the master key and sealed to the administrator
     * — and to the keeper, given its keys (createEscrow()).
     *
     * @param array{sign: string, box: string}|null $keeperPublicKeys
     * @return array{record: EscrowRecord, unlocked: UnlockedEscrow}
     */
    private function sealEscrowKey(
        #[\SensitiveParameter] string $escrowKey,
        string $adminPublicKeyB64,
        DateTimeImmutable $now,
        ?array $keeperPublicKeys,
        DateTimeImmutable $createdAt,
        int $revision
    ): array {
        $seed     = null;
        $escrowId = null;
        $plain    = $escrowKey;
        try {
            if ($keeperPublicKeys !== null) {
                $keeperPublicKeys = KeeperSeal::checkKeys($keeperPublicKeys);
                $seed     = $this->objectionSeed();
                $escrowId = $this->escrowId();
                $plain = $escrowKey . $escrowId . $keeperPublicKeys['sign'] . $keeperPublicKeys['box'] . $seed;
            }
            $wrapUser = Primitives::encrypt($plain, $this->key(), EscrowVault::wrapAad($this->userId));
            $unlocked = $keeperPublicKeys === null
                ? new UnlockedEscrow(userId: $this->userId, escrowKey: $escrowKey)
                : new UnlockedEscrow($this->userId, $escrowKey, $escrowId, $keeperPublicKeys, $seed);
        } finally {
            Primitives::zeroize($plain);
            if ($seed !== null) {
                Primitives::zeroize($seed);
            }
        }
        $record = new EscrowRecord(
            userId:    $this->userId,
            wrapUser:  $wrapUser,
            wrapAdmin: $keeperPublicKeys === null
                ? $unlocked->sealToAdmin($adminPublicKeyB64)
                : $unlocked->sealToKeeper($adminPublicKeyB64),
            createdAt: $createdAt,
            updatedAt: $now,
            revision:  $revision,
            adminKey:  AdminKey::fingerprint($adminPublicKeyB64)
        );
        return ['record' => $record, 'unlocked' => $unlocked];
    }

    /**
     * Open this account's escrow with the master key — the user's daily access.
     *
     * @throws RuntimeException if the record is another account's, or the wrap fails
     */
    public function openEscrow(EscrowRecord $record): UnlockedEscrow
    {
        $escrowKey = $this->wrapUserPlain($record);
        try {
            return EscrowVault::unwrapUserPlain($record->userId, $escrowKey);
        } finally {
            Primitives::zeroize($escrowKey);
        }
    }

    /**
     * The plaintext of wrap_user, under the master key: the escrow key, and
     * its keeper binding when it has one (EscrowVault::unwrapUserPlain()).
     *
     * @throws RuntimeException if the record is another account's, or the wrap fails
     */
    private function wrapUserPlain(EscrowRecord $record): string
    {
        if ($record->userId !== $this->userId) {
            throw new InvalidArgumentException('Session userId does not match escrow record');
        }
        try {
            return Primitives::decryptAcrossFormats(
                $record->wrapUser,
                $this->key(),
                EscrowVault::wrapAad($record->userId),
                $record->userId . EscrowVault::WRAP_AAD_SUFFIX
            );
        } catch (LegacyCipherUnavailableException $e) {
            // A RuntimeException too: without this, the next catch hides a machine limit.
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Could not unwrap escrow with user master key', previous: $e);
        }
    }

    /**
     * The escrowId of this vault's escrow under a keeper: the fingerprint of
     * its objection key, computed from the master key — what the holder asks
     * the keeper about, whatever the database holds.
     */
    public function escrowId(): string
    {
        $seed = $this->objectionSeed();
        try {
            return KeeperSeal::escrowIdOfSeed($seed);
        } finally {
            Primitives::zeroize($seed);
        }
    }

    /** The holder's objection to the read of this vault's escrow for $caseRef (KeeperSeal::verifyObjection()). */
    public function signEscrowObjection(string $caseRef): string
    {
        return $this->signEscrowToken(KeeperSeal::objectionMessage($this->escrowId(), $caseRef));
    }

    /** The holder's retirement of this vault's escrow from the keeper whose fingerprint is $keeperPrint. */
    public function signEscrowRetirement(string $keeperPrint): string
    {
        return $this->signEscrowToken(KeeperSeal::retirementMessage($this->escrowId(), $keeperPrint));
    }

    /**
     * The holder's consent to the read of this vault's escrow for $caseRef, at
     * the keeper whose fingerprint is $keeperPrint (KeeperSeal::verifyConsent()).
     * Only a session on the live vault signs one: neither an ObjectionKey nor
     * an archive does.
     */
    public function signEscrowConsent(string $caseRef, string $keeperPrint): string
    {
        return $this->signEscrowToken(KeeperSeal::consentMessage($this->escrowId(), $caseRef, $keeperPrint));
    }

    private function signEscrowToken(string $message): string
    {
        $seed = $this->objectionSeed();
        try {
            return KeeperSeal::tokenOfSeed($message, $seed);
        } finally {
            Primitives::zeroize($seed);
        }
    }

    /**
     * The same seed at every deposit and under every keeper: the holder objects
     * to any box of the escrow. SelfDataGuard::openForObjection() draws it out
     * of the session, into an ObjectionKey, before it wipes the master key.
     */
    private function objectionSeed(): string
    {
        return hash_hmac('sha256', self::OBJECTION_SEED_TAG . $this->userId, $this->key(), true);
    }

    private function key(): string
    {
        if ($this->locked) {
            throw new RuntimeException('Vault is locked — re-authenticate to unlock again');
        }
        return $this->masterKey;
    }

    /**
     * The vault this session was opened on. Writes made through it are refused
     * once the userId has been given another vault.
     */
    public function generation(): VaultGeneration
    {
        return new VaultGeneration($this->userId, $this->vaultSalt);
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    /**
     * Best-effort memory wipe + mark locked. Idempotent.
     */
    public function lock(): void
    {
        if ($this->locked) {
            return;
        }
        Primitives::zeroize($this->masterKey);
        $this->locked = true;
    }

    public function __destruct()
    {
        $this->lock();
    }

    /**
     * Forbid serialization of the unwrapped key.
     */
    public function __serialize(): array
    {
        throw new RuntimeException('UnlockedVault must not be serialized');
    }

    public function __unserialize(array $data): void
    {
        throw new RuntimeException('UnlockedVault must not be unserialized');
    }

    /**
     * Keep the key out of var_dump() and print_r(): a debug page or a log line is
     * where it would land first. var_export() reads properties directly and is
     * not covered — the rule above (never log this object) still holds.
     */
    public function __debugInfo(): array
    {
        return ['userId' => $this->userId, 'locked' => $this->locked, 'masterKey' => '[redacted]'];
    }

    /**
     * Forbid cloning: a clone carries its own copy of the key, which lock() on
     * the original never wipes.
     */
    public function __clone()
    {
        throw new RuntimeException('UnlockedVault must not be cloned');
    }
}
