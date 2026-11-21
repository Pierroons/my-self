<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

use Pierroons\SelfDataGuard\Crypto\Aad;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\LegacyCipherUnavailableException;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Pepper\Pepper;
use Pierroons\SelfDataGuard\Pepper\PepperSource;
use RuntimeException;

/**
 * The pepper on the locks it covers — wrap_pwd and wrap_recov; the
 * passphrase stays without it, the lock that survives the loss of the pepper.
 *
 * An envelope under the pepper takes one of two forms, both stored as a v3
 * blob that 0.9.x reads, so that a return to it closes the peppered locks and
 * never the row:
 *
 * - layer: a v3 blob under the layer key — the pepper and the account's
 *   salt, no secret of the user —, whose plaintext is the string of an
 *   envelope without the pepper. The operator's pass lays it over every lock
 *   with no secret in hand, and takes it off again for a return to 0.9.x.
 * - native: a layer whose plaintext is "SDG4.p1:<check>:<blob>", the blob
 *   sealed under Argon2id of the pepper's lock input. Sealed once the upgrade
 *   is declared over (PepperForms::LayeredOnly), by the gestures that hold
 *   the secret.
 *
 * An envelope with neither is bare: what 0.9.x sealed.
 *
 * Opening derives one Argon2id whatever the form, and computes the lock input
 * before it, the lock absent included: the time tells neither the form nor
 * the presence of the lock.
 */
final class LockPepper
{
    private const NATIVE = 'SDG4.p1:';

    private ?string $check = null;

    public function __construct(
        public readonly PepperSource $source,
        public readonly PepperForms $forms
    ) {
    }

    /** Whether the pepper covers $lock. */
    public static function covers(Lock $lock): bool
    {
        return $lock !== Lock::Passphrase;
    }

    /** The witness of the pepper, as 32 hex, computed once. */
    public function check(): string
    {
        return $this->check ??= Pepper::check($this->source);
    }

    /**
     * The envelope of $lock for $masterKey: a layer over a bare envelope under
     * AcceptBare, a native one under LayeredOnly.
     */
    public function seal(
        Lock $lock,
        #[\SensitiveParameter] string $secret,
        #[\SensitiveParameter] string $masterKey,
        string $userId,
        string $userSalt,
        int $opslimit,
        int $memlimit
    ): EncryptedBlob {
        if ($this->forms === PepperForms::LayeredOnly) {
            $key = $this->nativeKey($lock, $secret, $userSalt, $opslimit, $memlimit);
            try {
                $inner = self::NATIVE . $this->check() . ':'
                    . Primitives::encrypt($masterKey, $key, $this->nativeAad($userId))->toBase64();
            } finally {
                Primitives::zeroize($key);
            }
        } else {
            $key = UserVault::keyFor($lock, $secret, $userSalt, $opslimit, $memlimit);
            try {
                $inner = Primitives::encrypt($masterKey, $key, UserVault::wrapAadFor($userId))->toBase64();
            } finally {
                Primitives::zeroize($key);
            }
        }
        return $this->layer($lock, $inner, $userId, $userSalt);
    }

    /**
     * The master key $wrap holds, opened with $secret — one Argon2id whatever
     * the form. A null $wrap derives all the same, then answers as a wrong
     * secret does.
     *
     * @throws WrongSecretException          the secret does not open it, or the lock is absent
     *                                       (MissingEnvelopeException)
     * @throws UnlayeredLockException        a bare envelope under LayeredOnly
     * @throws LegacyCipherUnavailableException a bare AES envelope this machine cannot decrypt
     */
    public function open(
        Lock $lock,
        ?EncryptedBlob $wrap,
        #[\SensitiveParameter] string $secret,
        string $userId,
        string $userSalt,
        int $opslimit,
        int $memlimit,
        string $wrongSecret
    ): string {
        $input = Pepper::lockInput($this->source, self::usage($lock), $userSalt, $secret);
        $inner = $wrap === null ? null : $this->peel($lock, $wrap, $userId, $userSalt);
        $native = $inner !== null && str_starts_with($inner, self::NATIVE);
        $check  = $native ? $this->check() : '';
        $key = $native
            ? self::argon2For($lock, $input, $userSalt, $opslimit, $memlimit)
            : UserVault::keyFor($lock, $secret, $userSalt, $opslimit, $memlimit);
        try {
            if ($wrap === null) {
                throw new MissingEnvelopeException($wrongSecret);
            }
            if ($inner === null && $this->forms === PepperForms::LayeredOnly) {
                throw new UnlayeredLockException($wrongSecret);
            }
            if ($native) {
                [$sealedUnder, $blob] = self::nativeParts($inner);
                if (!hash_equals($check, $sealedUnder)) {
                    throw new WrongSecretException($wrongSecret);
                }
                return Primitives::decrypt($blob, $key, $this->nativeAad($userId));
            }
            return Primitives::decryptAcrossFormats(
                $inner === null ? $wrap : EncryptedBlob::fromBase64($inner),
                $key,
                UserVault::wrapAadFor($userId),
                $userId
            );
        } catch (WrongSecretException | LegacyCipherUnavailableException $e) {
            throw $e;
        } catch (RuntimeException | \InvalidArgumentException $e) {
            throw new WrongSecretException($wrongSecret, previous: $e);
        } finally {
            Primitives::zeroize($key);
            sodium_memzero($input);
        }
    }

    /**
     * The form of $wrap, without any Argon2id: native, layer, bare, or
     * unreadable — a layer whose plaintext is no envelope, or a native one
     * sealed under another pepper.
     */
    public function formOf(Lock $lock, EncryptedBlob $wrap, string $userId, string $userSalt): string
    {
        $inner = $this->peel($lock, $wrap, $userId, $userSalt);
        if ($inner === null) {
            return 'bare';
        }
        try {
            if (str_starts_with($inner, self::NATIVE)) {
                return hash_equals($this->check(), self::nativeParts($inner)[0]) ? 'native' : 'unreadable';
            }
            EncryptedBlob::fromBase64($inner);
            return 'layer';
        } catch (RuntimeException | \InvalidArgumentException) {
            return 'unreadable';
        }
    }

    /** A bare envelope under the layer: what the operator's pass writes, with no secret in hand. */
    public function lay(Lock $lock, EncryptedBlob $bare, string $userId, string $userSalt): EncryptedBlob
    {
        return $this->layer($lock, $bare->toBase64(), $userId, $userSalt);
    }

    /**
     * The bare envelope under the layer of $wrap, for a return to 0.9.x.
     *
     * @throws RuntimeException when $wrap is not a layer over a bare envelope — a native one is not taken off
     */
    public function unlay(Lock $lock, EncryptedBlob $wrap, string $userId, string $userSalt): EncryptedBlob
    {
        $inner = $this->peel($lock, $wrap, $userId, $userSalt)
            ?? throw new RuntimeException('This envelope carries no layer of this pepper');
        if (str_starts_with($inner, self::NATIVE)) {
            throw new RuntimeException('This envelope takes the pepper into its Argon2id input: no layer comes off it');
        }
        return EncryptedBlob::fromBase64($inner);
    }

    // -- Internals ----------------------------------------------------------------

    private function layer(Lock $lock, string $inner, string $userId, string $userSalt): EncryptedBlob
    {
        $key = Pepper::layerKey($this->source, self::layerUsage($lock), $userSalt);
        try {
            return Primitives::encrypt($inner, $key, self::layerAad($lock, $userId));
        } finally {
            sodium_memzero($key);
        }
    }

    /** The plaintext under the layer of this pepper, or null when $wrap carries none. */
    private function peel(Lock $lock, EncryptedBlob $wrap, string $userId, string $userSalt): ?string
    {
        if (!$wrap->bindsItsContext()) {
            return null;
        }
        $key = Pepper::layerKey($this->source, self::layerUsage($lock), $userSalt);
        try {
            return Primitives::decrypt($wrap, $key, self::layerAad($lock, $userId));
        } catch (RuntimeException) {
            return null;
        } finally {
            sodium_memzero($key);
        }
    }

    private function nativeKey(Lock $lock, #[\SensitiveParameter] string $secret, string $userSalt, int $ops, int $mem): string
    {
        $input = Pepper::lockInput($this->source, self::usage($lock), $userSalt, $secret);
        try {
            return self::argon2For($lock, $input, $userSalt, $ops, $mem);
        } finally {
            sodium_memzero($input);
        }
    }

    /** Argon2id of the lock input, under the salt the lock takes without the pepper. */
    private static function argon2For(Lock $lock, #[\SensitiveParameter] string $input, string $userSalt, int $ops, int $mem): string
    {
        return $lock === Lock::Password
            ? Primitives::deriveFromPassword($input, $userSalt, $ops, $mem)
            : Primitives::deriveFromMemorized($input, $userSalt . UserVault::HMAC_CONTEXT_SUFFIX, $ops, $mem);
    }

    /** @return array{0: string, 1: EncryptedBlob} */
    private static function nativeParts(string $inner): array
    {
        if (preg_match('/^SDG4\.p1:([0-9a-f]{32}):(.+)$/sD', $inner, $m) !== 1) {
            throw new RuntimeException('A native envelope that does not parse');
        }
        return [$m[1], EncryptedBlob::fromBase64($m[2])];
    }

    private function nativeAad(string $userId): Aad
    {
        return Aad::of('vault-wrap', $userId, 'p1:' . $this->check());
    }

    private static function layerAad(Lock $lock, string $userId): Aad
    {
        return Aad::of('vault-wrap-layer', $userId, $lock->value);
    }

    private static function usage(Lock $lock): string
    {
        return $lock === Lock::Password ? Pepper::USAGE_WRAP_PWD : Pepper::USAGE_WRAP_RECOV;
    }

    private static function layerUsage(Lock $lock): string
    {
        return $lock === Lock::Password ? Pepper::USAGE_WRAP_PWD_LAYER : Pepper::USAGE_WRAP_RECOV_LAYER;
    }
}
