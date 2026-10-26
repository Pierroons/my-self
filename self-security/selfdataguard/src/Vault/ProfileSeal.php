<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Vault;

/**
 * An HMAC over a vault's Argon2id profile and its envelopes, under a server key
 * kept out of the database.
 *
 * The profile is an input of every envelope key: change it in the database,
 * and each lock fails as a wrong secret would. An AAD would not help — it is
 * checked after the derivation, by the same failing tag. The seal is checked
 * before: whoever writes the database without the key cannot make a false
 * profile pass, and the operator finds the true one again by trying the
 * published profiles against the seal, without the holder's secret.
 *
 * It covers the userId and the salt, so that a seal copied from another row
 * does not vouch for this one, and every envelope, present or absent: one
 * removed, or brought from another row, is tampering too, and an archive —
 * which keeps the row as it was — keeps a seal over all of it. It does not
 * tell a whole row put back from an old backup: that row carries its old seal.
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

    /** The seal of $record, read under the profile $opslimit / $memlimit. */
    public static function of(#[\SensitiveParameter] string $key, VaultRecord $record, int $opslimit, int $memlimit): string
    {
        return hash_hmac('sha256', self::message($record, $opslimit, $memlimit), $key, true);
    }

    public static function holds(#[\SensitiveParameter] string $key, string $seal, VaultRecord $record, int $opslimit, int $memlimit): bool
    {
        return hash_equals(self::of($key, $record, $opslimit, $memlimit), $seal);
    }

    /**
     * True when an envelope of $record was written by a version that seals
     * every vault it writes: then a missing seal was removed, not never set.
     */
    public static function expected(VaultRecord $record): bool
    {
        foreach ([$record->wrapPwd, $record->wrapRecov, $record->wrapPhrase, $record->wrapAdmin] as $wrap) {
            if ($wrap !== null && $wrap->bindsItsContext()) {
                return true;
            }
        }
        return false;
    }

    private static function message(VaultRecord $record, int $opslimit, int $memlimit): string
    {
        $part = static fn (?string $bytes): string => $bytes === null ? "\0" : "\1" . pack('N', strlen($bytes)) . $bytes;
        return $part($record->userId) . $part($record->userSalt) . pack('JJ', $opslimit, $memlimit)
            . $part($record->wrapPwd->toBase64()) . $part($record->wrapRecov?->toBase64())
            . $part($record->wrapPhrase?->toBase64()) . $part($record->wrapAdmin?->toBase64());
    }
}
