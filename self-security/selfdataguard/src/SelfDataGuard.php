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
use Pierroons\SelfDataGuard\Vault\UnlockedVault;
use Pierroons\SelfDataGuard\Vault\UserVault;
use RuntimeException;

/**
 * Public façade for SelfDataGuard.
 *
 * Wraps the Vault + Fields + Storage layers behind a minimal API:
 *
 *     $dg = new SelfDataGuard($storage, $blindKey);
 *
 *     // New user. Passwords are refused below UserVault::PASSWORD_MIN_LEN.
 *     $session = $dg->register('user-1', 'a-long-generated-password', 'memorized-secret');
 *     $dg->setFields($session, ['email' => 'a@b.c'], indexed: ['email']);
 *
 *     // Returning user
 *     $session = $dg->loginWithPassword('user-1', 'password');
 *     $fields  = $dg->getFields($session);
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
     */
    public function register(
        string $userId,
        string $password,
        ?string $memorized = null
    ): UnlockedVault {
        if ($this->storage->vaultExists($userId)) {
            throw new RuntimeException("User '{$userId}' already exists");
        }
        $result = $this->vault->register($userId, $password, $memorized);
        $this->storage->saveVault($result['record']);
        return $result['unlocked'];
    }

    /**
     * Authenticate by password. Returns an UnlockedVault for the session.
     *
     * @throws RuntimeException on wrong password or missing user.
     */
    public function loginWithPassword(string $userId, string $password): UnlockedVault
    {
        $record = $this->storage->loadVault($userId);
        return $this->vault->unlockWithPassword($record, $password);
    }

    /**
     * Authenticate by memorized secret (recovery flow).
     *
     * @throws RuntimeException on wrong secret, missing user, or vault without recovery wrap.
     */
    public function loginWithMemorized(string $userId, string $memorized): UnlockedVault
    {
        $record = $this->storage->loadVault($userId);
        return $this->vault->unlockWithMemorized($record, $memorized);
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
        $cipher = $this->storage->loadFields($session->userId, $fieldNames);
        return FieldCrypter::decryptBatch($session, $cipher);
    }

    /**
     * Lookup a userId by a known field value (e.g. email).
     * The field must have been registered as indexed via setFields(..., indexed: [...]).
     *
     * @return string|null userId if found, null otherwise.
     */
    public function findUserByField(string $fieldName, string $value): ?string
    {
        $index = BlindIndex::compute($value, $this->blindKey, $fieldName);
        return $this->storage->findUserIdByBlindIndex($fieldName, $index);
    }

    /**
     * Re-seal the password wrap with a new password. Session must be active.
     */
    public function changePassword(UnlockedVault $session, string $newPassword): void
    {
        $record = $this->storage->loadVault($session->userId);
        $rotated = $this->vault->changePassword($record, $session, $newPassword);
        $this->storage->updateVault($rotated);
    }

    /**
     * Re-seal the recovery wrap. Pass null to remove recovery entirely.
     */
    public function changeMemorized(UnlockedVault $session, ?string $newMemorized): void
    {
        $record = $this->storage->loadVault($session->userId);
        $rotated = $this->vault->changeMemorized($record, $session, $newMemorized);
        $this->storage->updateVault($rotated);
    }

    /**
     * Delete the user's vault and all encrypted fields.
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
    public static function generateAdminRecoveryKey(string $passphrase): array
    {
        return AdminKey::generate($passphrase);
    }

    /**
     * Unseal the admin recovery secret key with the passphrase. Returns the raw
     * 32-byte secret key — caller MUST sodium_memzero() it after use. Meant for
     * the recovery-ceremony CLI, not for web request paths.
     */
    public static function unsealAdminRecoveryKey(string $sealedSecret, string $passphrase): string
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
}
