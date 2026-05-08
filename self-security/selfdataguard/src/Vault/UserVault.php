<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use DateTimeImmutable;
use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use RuntimeException;

/**
 * Stateless service implementing the SelfDataGuard envelope-encryption protocol
 * described in whitepaper §2.2.
 *
 * Per-user envelope:
 *
 *     data_master_key  ←  random(256 bits)
 *
 *     password_key     ←  Argon2id(password, user_salt)
 *     recov_key        ←  HMAC-SHA256(memorized, user_salt || "/dataguard")
 *
 *     wrap_pwd         ←  AES-256-GCM-encrypt(data_master_key, password_key)
 *     wrap_recov       ←  AES-256-GCM-encrypt(data_master_key, recov_key)
 *
 * On authentication, the matching wrap is opened and the data_master_key is
 * loaded into an UnlockedVault for the duration of the session.
 */
final class UserVault
{
    /** Whitepaper §3.1 — domain separator for SelfDataGuard usage of the memorized secret. */
    public const HMAC_CONTEXT_SUFFIX = '/dataguard';

    public function __construct(
        private readonly ?\DateTimeImmutable $clock = null
    ) {
    }

    /**
     * Create a fresh vault for a new user. Returns the persistent record (to
     * be stored by the application) and the in-memory UnlockedVault that the
     * caller can use immediately to encrypt initial data.
     *
     * @return array{record: VaultRecord, unlocked: UnlockedVault}
     */
    public function register(
        string $userId,
        string $password,
        ?string $memorized = null
    ): array {
        if ($userId === '') {
            throw new InvalidArgumentException('userId must not be empty');
        }
        if ($password === '') {
            throw new InvalidArgumentException('password must not be empty');
        }
        if ($memorized !== null && $memorized === '') {
            throw new InvalidArgumentException('memorized, if provided, must not be empty');
        }

        $userSalt      = Primitives::randomBytes(Primitives::SALT_LEN);
        $dataMasterKey = Primitives::randomBytes(Primitives::KEY_LEN);

        $passwordKey = Primitives::deriveFromPassword($password, $userSalt);
        $wrapPwd     = Primitives::aesGcmEncrypt($dataMasterKey, $passwordKey, aad: $userId);
        Primitives::zeroize($passwordKey);

        $wrapRecov = null;
        if ($memorized !== null) {
            $recovKey = Primitives::deriveFromMemorized(
                $memorized,
                $userSalt . self::HMAC_CONTEXT_SUFFIX
            );
            $wrapRecov = Primitives::aesGcmEncrypt($dataMasterKey, $recovKey, aad: $userId);
            Primitives::zeroize($recovKey);
        }

        $now = $this->now();
        $record = new VaultRecord(
            userId:    $userId,
            userSalt:  $userSalt,
            wrapPwd:   $wrapPwd,
            wrapRecov: $wrapRecov,
            wrapAdmin: null,
            createdAt: $now,
            updatedAt: $now
        );

        $unlocked = new UnlockedVault(userId: $userId, masterKey: $dataMasterKey);
        Primitives::zeroize($dataMasterKey);

        return ['record' => $record, 'unlocked' => $unlocked];
    }

    /**
     * Unwrap data_master_key using the user's password.
     *
     * @throws RuntimeException on wrong password (decryption auth failure)
     */
    public function unlockWithPassword(VaultRecord $record, string $password): UnlockedVault
    {
        if ($password === '') {
            throw new InvalidArgumentException('password must not be empty');
        }

        $passwordKey = Primitives::deriveFromPassword($password, $record->userSalt);
        try {
            $masterKey = Primitives::aesGcmDecrypt(
                $record->wrapPwd,
                $passwordKey,
                aad: $record->userId
            );
        } catch (RuntimeException $e) {
            Primitives::zeroize($passwordKey);
            throw new RuntimeException(
                'Invalid password — could not unwrap vault',
                previous: $e
            );
        }
        Primitives::zeroize($passwordKey);

        $unlocked = new UnlockedVault(userId: $record->userId, masterKey: $masterKey);
        Primitives::zeroize($masterKey);
        return $unlocked;
    }

    /**
     * Unwrap data_master_key using the user's memorized secret (recovery flow).
     *
     * @throws RuntimeException on wrong memorized secret OR if vault has no recovery wrap.
     */
    public function unlockWithMemorized(VaultRecord $record, string $memorized): UnlockedVault
    {
        if ($memorized === '') {
            throw new InvalidArgumentException('memorized must not be empty');
        }
        if ($record->wrapRecov === null) {
            throw new RuntimeException(
                'Vault has no recovery wrap — memorized secret was not configured'
            );
        }

        $recovKey = Primitives::deriveFromMemorized(
            $memorized,
            $record->userSalt . self::HMAC_CONTEXT_SUFFIX
        );
        try {
            $masterKey = Primitives::aesGcmDecrypt(
                $record->wrapRecov,
                $recovKey,
                aad: $record->userId
            );
        } catch (RuntimeException $e) {
            Primitives::zeroize($recovKey);
            throw new RuntimeException(
                'Invalid memorized secret — could not unwrap vault',
                previous: $e
            );
        }
        Primitives::zeroize($recovKey);

        $unlocked = new UnlockedVault(userId: $record->userId, masterKey: $masterKey);
        Primitives::zeroize($masterKey);
        return $unlocked;
    }

    /**
     * Re-seal the password wrap with a new password. The data does not need to
     * be re-encrypted — only the password_wrap is regenerated.
     *
     * Caller must already hold an UnlockedVault (e.g. obtained via unlockWithPassword
     * or unlockWithMemorized) to prove they currently know enough to access the data.
     */
    public function changePassword(
        VaultRecord $record,
        UnlockedVault $unlocked,
        string $newPassword
    ): VaultRecord {
        if ($newPassword === '') {
            throw new InvalidArgumentException('newPassword must not be empty');
        }
        if ($unlocked->userId !== $record->userId) {
            throw new InvalidArgumentException('UnlockedVault userId does not match record');
        }

        $newPwdKey = Primitives::deriveFromPassword($newPassword, $record->userSalt);
        $masterKey = $unlocked->getMasterKey();
        $newWrap   = Primitives::aesGcmEncrypt($masterKey, $newPwdKey, aad: $record->userId);
        Primitives::zeroize($newPwdKey);

        return $record->withWrapPwd($newWrap, $this->now());
    }

    /**
     * Re-seal the recovery wrap with a new memorized secret. Pass null to remove
     * the recovery wrap entirely (degrades to single-factor — discouraged).
     */
    public function changeMemorized(
        VaultRecord $record,
        UnlockedVault $unlocked,
        ?string $newMemorized
    ): VaultRecord {
        if ($unlocked->userId !== $record->userId) {
            throw new InvalidArgumentException('UnlockedVault userId does not match record');
        }
        if ($newMemorized !== null && $newMemorized === '') {
            throw new InvalidArgumentException('newMemorized, if provided, must not be empty');
        }

        if ($newMemorized === null) {
            return $record->withWrapRecov(null, $this->now());
        }

        $newRecovKey = Primitives::deriveFromMemorized(
            $newMemorized,
            $record->userSalt . self::HMAC_CONTEXT_SUFFIX
        );
        $masterKey = $unlocked->getMasterKey();
        $newWrap   = Primitives::aesGcmEncrypt($masterKey, $newRecovKey, aad: $record->userId);
        Primitives::zeroize($newRecovKey);

        return $record->withWrapRecov($newWrap, $this->now());
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock ?? new DateTimeImmutable();
    }
}
