<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard;

use InvalidArgumentException;
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
 *     // New user
 *     $session = $dg->register('user-1', 'password', 'memorized-secret');
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

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $blindKey
    ) {
        if (strlen($blindKey) < 32) {
            throw new InvalidArgumentException(
                'blindKey must be ≥32 bytes of server-side secret'
            );
        }
        $this->vault = new UserVault();
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
}
