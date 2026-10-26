<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

/**
 * An HMAC over a vault's Argon2id profile, under a server key kept out of the
 * database.
 *
 * The profile is an input of every envelope key: change it in the database,
 * and each lock fails as a wrong secret would. An AAD would not help — it is
 * checked after the derivation, by the same failing tag. The seal is checked
 * before: whoever writes the database without the key cannot make a false
 * profile pass, and the operator finds the true one again by trying the
 * published profiles against the seal, without the holder's secret.
 *
 * It covers the userId and the salt with the profile, so that a seal copied
 * from another row does not vouch for this one.
 */
final class ProfileSeal
{
    private const DOMAIN = 'selfdataguard/profile-seal';

    private function __construct()
    {
    }

    /**
     * The seal key, derived from the blind-index key by HKDF. Not an HMAC of a
     * name under that key: HMAC(blindKey, name) is how BlindIndex keys a field,
     * and a field named after the domain would have its index computed as a seal.
     */
    public static function key(#[\SensitiveParameter] string $blindKey): string
    {
        return hash_hkdf('sha256', $blindKey, 32, self::DOMAIN);
    }

    public static function of(#[\SensitiveParameter] string $key, string $userId, string $userSalt, int $opslimit, int $memlimit): string
    {
        return hash_hmac(
            'sha256',
            pack('N', strlen($userId)) . $userId . pack('N', strlen($userSalt)) . $userSalt . pack('JJ', $opslimit, $memlimit),
            $key,
            true
        );
    }

    public static function holds(#[\SensitiveParameter] string $key, string $seal, string $userId, string $userSalt, int $opslimit, int $memlimit): bool
    {
        return hash_equals(self::of($key, $userId, $userSalt, $opslimit, $memlimit), $seal);
    }
}
