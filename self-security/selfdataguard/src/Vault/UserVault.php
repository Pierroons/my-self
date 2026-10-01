<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use DateTimeImmutable;
use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\LegacyCipherUnavailableException;
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
 *     recov_key        ←  Argon2id(memorized, sha256(user_salt || "/dataguard"))
 *     phrase_key       ←  Argon2id(normalize(passphrase), sha256(user_salt || "/dataguard/passphrase"))
 *
 *     wrap_pwd         ←  XChaCha20-Poly1305-encrypt(data_master_key, password_key)
 *     wrap_recov       ←  XChaCha20-Poly1305-encrypt(data_master_key, recov_key)
 *     wrap_phrase      ←  XChaCha20-Poly1305-encrypt(data_master_key, phrase_key)
 *
 * On authentication, the matching wrap is opened and the data_master_key is
 * loaded into an UnlockedVault for the duration of the session.
 */
final class UserVault
{
    /** Domain separator for SelfDataGuard usage of the memorized secret. */
    public const HMAC_CONTEXT_SUFFIX = '/dataguard';

    /**
     * Domain separator for the passphrase. Distinct from HMAC_CONTEXT_SUFFIX:
     * the same string sealed as memorized secret and as passphrase gives two
     * unrelated keys, so one envelope never opens the other.
     */
    public const PASSPHRASE_CONTEXT_SUFFIX = '/dataguard/passphrase';

    /**
     * Minimum password length, as promised by whitepaper §7.
     *
     * Length is NOT entropy — twelve identical letters clear this bar. It is a
     * floor against the worst, not a measure. What actually protects wrap_pwd is
     * Argon2id, plus the fact that the integrations generate the password rather
     * than letting someone pick it. The promise was in the whitepaper and in no
     * line of code until 0.3.0; a rule that only exists in a document is not a
     * rule, and reads as one.
     *
     * The passphrase gets the same floor, applied when sealing only: an older,
     * shorter passphrase must still open the vault it sealed.
     */
    public const PASSWORD_MIN_LEN = 12;

    public function __construct(
        private readonly ?\DateTimeImmutable $clock = null
    ) {
    }

    /**
     * The passphrase as the envelope is sealed on: edges trimmed, every run of
     * whitespace reduced to one space.
     *
     * Identical to SelfRecover's Recovery::normaliserPassphrase(), so that the
     * string SelfRecover accepts is the string that opens the vault —
     * bi-self/selfrecover/tests/sanity_couplage_dataguard.php holds the two
     * together. No /u modifier, on purpose: with it, \s also matches U+00A0 and
     * U+2003, and the two normalisations would part ways on exactly the
     * characters a copy-paste brings in.
     */
    public static function normalizePassphrase(#[\SensitiveParameter] string $passphrase): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $passphrase));
    }

    /**
     * Create a fresh vault for a new user. Returns the persistent record (to
     * be stored by the application) and the in-memory UnlockedVault that the
     * caller can use immediately to encrypt initial data.
     *
     * ⚠️ `$memorized` and `$passphrase` omitted = a single envelope, on the
     * password. Anything that replaces the password without re-sealing — a
     * SelfRecover level-1 or level-2 recovery does — leaves the vault
     * unreadable for good. The vault works, tests and demos fine until that
     * day: the loss only shows once the user already has a problem. See
     * README, « Coupling with SelfRecover ».
     *
     * @return array{record: VaultRecord, unlocked: UnlockedVault}
     */
    public function register(
        string $userId,
        #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] ?string $memorized = null,
        #[\SensitiveParameter] ?string $passphrase = null
    ): array {
        if ($userId === '') {
            throw new InvalidArgumentException('userId must not be empty');
        }
        self::assertPasswordLength($password);
        if ($memorized !== null && $memorized === '') {
            throw new InvalidArgumentException('memorized, if provided, must not be empty');
        }
        if ($passphrase !== null) {
            self::assertPassphraseLength($passphrase);
        }

        $userSalt      = Primitives::randomBytes(Primitives::SALT_LEN);
        $dataMasterKey = Primitives::randomBytes(Primitives::KEY_LEN);

        $wrapPwd    = self::seal(Lock::Password, $password, $userSalt, $dataMasterKey, $userId);
        $wrapRecov  = $memorized === null
            ? null
            : self::seal(Lock::Memorized, $memorized, $userSalt, $dataMasterKey, $userId);
        $wrapPhrase = $passphrase === null
            ? null
            : self::seal(Lock::Passphrase, $passphrase, $userSalt, $dataMasterKey, $userId);

        $now = $this->now();
        $record = new VaultRecord(
            userId:     $userId,
            userSalt:   $userSalt,
            wrapPwd:    $wrapPwd,
            wrapRecov:  $wrapRecov,
            wrapAdmin:  null,
            createdAt:  $now,
            updatedAt:  $now,
            wrapPhrase: $wrapPhrase
        );

        $unlocked = new UnlockedVault(userId: $userId, masterKey: $dataMasterKey, vaultSalt: $userSalt);
        Primitives::zeroize($dataMasterKey);

        return ['record' => $record, 'unlocked' => $unlocked];
    }

    /**
     * Unwrap data_master_key with any of the three secrets.
     *
     * @throws MissingEnvelopeException if the vault was never sealed for $lock
     * @throws WrongSecretException     if the secret does not open the envelope
     * @throws LegacyCipherUnavailableException if the envelope is an AES blob
     *         this machine cannot decrypt — the secret may well be right
     */
    public function unlock(VaultRecord $record, Lock $lock, #[\SensitiveParameter] string $secret): UnlockedVault
    {
        if ($secret === '') {
            throw new InvalidArgumentException(self::secretName($lock) . ' must not be empty');
        }
        $wrap = self::wrapOf($record, $lock);
        if ($wrap === null) {
            throw new MissingEnvelopeException(match ($lock) {
                Lock::Password   => 'Vault has no password wrap',
                Lock::Memorized  => 'Vault has no recovery wrap — memorized secret was not configured',
                Lock::Passphrase => 'Vault has no passphrase wrap — passphrase was not configured',
            });
        }

        $key = self::deriveKey($lock, $secret, $record->userSalt);
        try {
            $masterKey = Primitives::decrypt($wrap, $key, aad: $record->userId);
        } catch (LegacyCipherUnavailableException $e) {
            // A RuntimeException too: without this, the next catch calls it a wrong secret.
            throw $e;
        } catch (RuntimeException $e) {
            // Before answering "wrong secret", find out whether this wrap was
            // sealed by the pre-0.3.0 HMAC derivation. If the legacy key opens
            // it, the secret is RIGHT and the vault is old — a different problem
            // and a different sentence. Silence here would send someone hunting
            // for a typo in a secret that is perfectly correct.
            if ($lock === Lock::Memorized && $this->wrapIsLegacyV1($record, $secret)) {
                throw new RuntimeException(
                    'This vault predates the Argon2id recovery derivation '
                    . '(SelfDataGuard < 0.3.0). The memorized secret is correct; '
                    . 'unlock with another lock and call changeMemorized() to re-seal. '
                    . 'Access is refused here on purpose: the legacy derivation is '
                    . 'about 78 000 times cheaper to attack.',
                    previous: $e
                );
            }
            throw new WrongSecretException(
                'Invalid ' . self::secretName($lock) . ' — could not unwrap vault',
                previous: $e
            );
        } finally {
            Primitives::zeroize($key);
        }

        $unlocked = new UnlockedVault(userId: $record->userId, masterKey: $masterKey, vaultSalt: $record->userSalt);
        Primitives::zeroize($masterKey);
        return $unlocked;
    }

    /**
     * Unwrap data_master_key using the user's password.
     *
     * @throws WrongSecretException on wrong password (decryption auth failure)
     */
    public function unlockWithPassword(VaultRecord $record, #[\SensitiveParameter] string $password): UnlockedVault
    {
        return $this->unlock($record, Lock::Password, $password);
    }

    /**
     * Unwrap data_master_key using the user's memorized secret (recovery flow).
     *
     * @throws MissingEnvelopeException if the vault has no recovery wrap
     * @throws WrongSecretException     on wrong memorized secret
     */
    public function unlockWithMemorized(VaultRecord $record, #[\SensitiveParameter] string $memorized): UnlockedVault
    {
        return $this->unlock($record, Lock::Memorized, $memorized);
    }

    /**
     * Unwrap data_master_key using the SelfRecover passphrase.
     *
     * @throws MissingEnvelopeException if the vault has no passphrase wrap
     * @throws WrongSecretException     on wrong passphrase
     */
    public function unlockWithPassphrase(VaultRecord $record, #[\SensitiveParameter] string $passphrase): UnlockedVault
    {
        return $this->unlock($record, Lock::Passphrase, $passphrase);
    }

    /**
     * Re-seal the password wrap with a new password. The data does not need to
     * be re-encrypted — only the password_wrap is regenerated.
     *
     * Caller must already hold an UnlockedVault opened on this very vault
     * (any lock) to prove they currently know enough to access the data.
     */
    public function changePassword(
        VaultRecord $record,
        UnlockedVault $unlocked,
        #[\SensitiveParameter] string $newPassword
    ): VaultRecord {
        self::assertPasswordLength($newPassword, 'newPassword');
        self::assertSameVault($record, $unlocked);

        $newWrap = self::seal(Lock::Password, $newPassword, $record->userSalt, $unlocked->getMasterKey(), $record->userId);
        return $record->withWrapPwd($newWrap, $this->now());
    }

    /**
     * Re-seal the recovery wrap with a new memorized secret. Pass null to remove
     * the recovery wrap entirely (degrades to single-factor — discouraged).
     */
    public function changeMemorized(
        VaultRecord $record,
        UnlockedVault $unlocked,
        #[\SensitiveParameter] ?string $newMemorized
    ): VaultRecord {
        self::assertSameVault($record, $unlocked);
        if ($newMemorized !== null && $newMemorized === '') {
            throw new InvalidArgumentException('newMemorized, if provided, must not be empty');
        }

        if ($newMemorized === null) {
            return $record->withWrapRecov(null, $this->now());
        }

        $newWrap = self::seal(Lock::Memorized, $newMemorized, $record->userSalt, $unlocked->getMasterKey(), $record->userId);
        return $record->withWrapRecov($newWrap, $this->now());
    }

    /**
     * Seal (or re-seal) the passphrase wrap. To remove it, removePassphrase():
     * no null here, so that a recovery result without a new passphrase — the
     * device path returns none — cannot drop the lock by accident.
     */
    public function changePassphrase(
        VaultRecord $record,
        UnlockedVault $unlocked,
        #[\SensitiveParameter] string $newPassphrase
    ): VaultRecord {
        self::assertPassphraseLength($newPassphrase, 'newPassphrase');
        self::assertSameVault($record, $unlocked);

        $newWrap = self::seal(Lock::Passphrase, $newPassphrase, $record->userSalt, $unlocked->getMasterKey(), $record->userId);
        return $record->withWrapPhrase($newWrap, $this->now());
    }

    public function removePassphrase(VaultRecord $record, UnlockedVault $unlocked): VaultRecord
    {
        self::assertSameVault($record, $unlocked);
        return $record->withWrapPhrase(null, $this->now());
    }

    /**
     * Does this recovery wrap open under the pre-0.3.0 HMAC derivation?
     *
     * Diagnosis only — the master key obtained here is discarded immediately and
     * never handed to a caller. Its single purpose is to tell "your secret is
     * wrong" apart from "your vault is old", which look identical from outside.
     */
    private function wrapIsLegacyV1(VaultRecord $record, #[\SensitiveParameter] string $memorized): bool
    {
        $legacy = Primitives::deriveFromMemorizedLegacyV1(
            $memorized,
            $record->userSalt . self::HMAC_CONTEXT_SUFFIX
        );
        try {
            $probe = Primitives::decrypt($record->wrapRecov, $legacy, aad: $record->userId);
            Primitives::zeroize($probe);
            return true;
        } catch (RuntimeException) {
            return false;
        } finally {
            Primitives::zeroize($legacy);
        }
    }

    private static function deriveKey(Lock $lock, #[\SensitiveParameter] string $secret, string $userSalt): string
    {
        return match ($lock) {
            Lock::Password   => Primitives::deriveFromPassword($secret, $userSalt),
            Lock::Memorized  => Primitives::deriveFromMemorized($secret, $userSalt . self::HMAC_CONTEXT_SUFFIX),
            Lock::Passphrase => Primitives::deriveFromMemorized(
                self::normalizePassphrase($secret),
                $userSalt . self::PASSPHRASE_CONTEXT_SUFFIX
            ),
        };
    }

    private static function seal(
        Lock $lock,
        #[\SensitiveParameter] string $secret,
        string $userSalt,
        #[\SensitiveParameter] string $masterKey,
        string $userId
    ): EncryptedBlob {
        $key = self::deriveKey($lock, $secret, $userSalt);
        try {
            return Primitives::encrypt($masterKey, $key, aad: $userId);
        } finally {
            Primitives::zeroize($key);
        }
    }

    private static function wrapOf(VaultRecord $record, Lock $lock): ?EncryptedBlob
    {
        return match ($lock) {
            Lock::Password   => $record->wrapPwd,
            Lock::Memorized  => $record->wrapRecov,
            Lock::Passphrase => $record->wrapPhrase,
        };
    }

    private static function secretName(Lock $lock): string
    {
        return match ($lock) {
            Lock::Password   => 'password',
            Lock::Memorized  => 'memorized secret',
            Lock::Passphrase => 'passphrase',
        };
    }

    /**
     * The session must come from this very vault, not merely from this userId:
     * after a re-enrolment the userId holds a new vault, and sealing the old
     * master key into it would make everything written there since unreadable.
     */
    private static function assertSameVault(VaultRecord $record, UnlockedVault $unlocked): void
    {
        if ($unlocked->userId !== $record->userId) {
            throw new InvalidArgumentException('UnlockedVault userId does not match record');
        }
        if (!hash_equals($record->userSalt, $unlocked->vaultSalt)) {
            throw new StaleVaultException(
                'This session was opened on a vault that has since been replaced — unlock the current one'
            );
        }
    }

    private static function assertPasswordLength(#[\SensitiveParameter] string $password, string $name = 'password'): void
    {
        if ($password === '') {
            throw new InvalidArgumentException($name . ' must not be empty');
        }
        if (strlen($password) < self::PASSWORD_MIN_LEN) {
            throw new InvalidArgumentException(sprintf(
                '%s must be at least %d bytes; got %d. Length is not entropy, but '
                . 'below this the Argon2id cost is beside the point.',
                $name,
                self::PASSWORD_MIN_LEN,
                strlen($password)
            ));
        }
    }

    private static function assertPassphraseLength(#[\SensitiveParameter] string $passphrase, string $name = 'passphrase'): void
    {
        self::assertPasswordLength(self::normalizePassphrase($passphrase), $name);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock ?? new DateTimeImmutable();
    }
}
