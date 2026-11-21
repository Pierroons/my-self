<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Pepper;

use InvalidArgumentException;

/**
 * The form of the pepper — one construction, written out once, which
 * SelfRecover keeps a copy of, held equal by the shared vectors
 * (`tests/vecteurs-poivre.json`, from a second implementation):
 *
 *   pre(binding, secret)  SHA-256(TAG || "/input\0" || len(binding) on 4 bytes || binding || secret)
 *   mac(usage, data)      HMAC-SHA256(pepper, TAG || "\0" || len(usage) on 1 byte || usage || data)
 *   lock input            hex(mac(usage, pre(user_salt, secret))), what Argon2id takes
 *   layer key             mac(usage of the layer, pre(user_salt, "")), with no secret of the user
 *   check                 the first 16 bytes of mac("check", ""), as 32 hex
 *
 * The binding is the account's salt: a lock input computed for one account
 * opens nothing of another.
 */
final class Pepper
{
    public const TAG = 'myself-pepper/v1';

    public const USAGE_CHECK            = 'check';
    public const USAGE_WRAP_PWD         = 'selfdataguard/wrap-pwd';
    public const USAGE_WRAP_RECOV       = 'selfdataguard/wrap-recov';
    public const USAGE_WRAP_PWD_LAYER   = 'selfdataguard/wrap-pwd-layer';
    public const USAGE_WRAP_RECOV_LAYER = 'selfdataguard/wrap-recov-layer';

    private function __construct()
    {
    }

    /** The 32-byte pre-image of a secret, bound to the account it is for. */
    public static function pre(string $binding, #[\SensitiveParameter] string $secret): string
    {
        return hash('sha256', self::TAG . "/input\0" . pack('N', strlen($binding)) . $binding . $secret, true);
    }

    /** What the pepper signs for $usage: its length on one byte, then the usage, then $data. */
    public static function message(string $usage, string $data): string
    {
        if ($usage === '' || strlen($usage) > 255) {
            throw new InvalidArgumentException('A pepper usage takes 1 to 255 bytes, got ' . strlen($usage));
        }
        return self::TAG . "\0" . chr(strlen($usage)) . $usage . $data;
    }

    /** The witness of a pepper, as 32 hex: what the database records to know it again. */
    public static function check(PepperSource $source): string
    {
        return bin2hex(substr(self::macOf($source, self::USAGE_CHECK, ''), 0, 16));
    }

    /** What Argon2id takes for a lock under the pepper: 64 lower-case hex. */
    public static function lockInput(
        PepperSource $source,
        string $usage,
        string $binding,
        #[\SensitiveParameter] string $secret
    ): string {
        return bin2hex(self::macOf($source, $usage, self::pre($binding, $secret)));
    }

    /** The key of the layer the operator's pass lays over an envelope: the pepper and the account, no secret of the user. */
    public static function layerKey(PepperSource $source, string $usage, string $binding): string
    {
        return self::macOf($source, $usage, self::pre($binding, ''));
    }

    private static function macOf(PepperSource $source, string $usage, string $data): string
    {
        $mac = $source->mac($usage, $data);
        if (strlen($mac) !== 32) {
            throw new PepperUnavailableException('The pepper source returned ' . strlen($mac) . ' bytes, where a computation is 32');
        }
        return $mac;
    }
}
