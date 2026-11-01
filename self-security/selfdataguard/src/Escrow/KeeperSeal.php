<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use InvalidArgumentException;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use RuntimeException;

/**
 * The formats an escrow keeper reads and writes, for the library and for the
 * keeper alike: a keeper an integrator writes calls open(), attestation() and
 * verifyObjection() instead of parsing bytes itself.
 *
 *     inner     ←  wrap_admin as before 0.9.0 (sealed to the admin, names the account)
 *     outer     ←  crypto_box_seal(tag ‖ escrowId ‖ objection key ‖ len(userId) ‖ userId ‖ inner, keeper box key)
 *     wrap_admin ← "k1:" ‖ hex(escrowId) ‖ ":" ‖ outer
 *
 * The administrator alone opens neither box in turn: the outer one is sealed
 * to the keeper, which opens it and hands back the inner one, after recording
 * the release. The keeper never opens the inner one. The escrowId stands in
 * clear before the box so that code can tell the form without any key; the
 * keeper attests on the copy inside, which nobody can change without the
 * keeper's secret.
 *
 * An attestation is base64(json) . "." . base64(Ed25519 signature over the tag
 * and the json): what the keeper released for one escrow, and what it holds
 * announced, for one nonce. An objection is an Ed25519 signature, under the
 * key whose secret lives in wrap_user, over the escrowId and a case.
 */
final class KeeperSeal
{
    public const ESCROW_ID_LEN = 16;

    private const OUTER_TAG  = "selfdataguard/escrow-keeper/v1\0";
    private const ATTEST_TAG = "selfdataguard/keeper-attestation/v1\0";
    private const OBJECT_TAG = "selfdataguard/keeper-objection/v1\0";
    private const PRINT_TAG  = "selfdataguard/keeper/v1\0";
    private const FORM       = '/\Ak1:([0-9a-f]{32}):/';
    private const PREFIX_LEN = 3 + 2 * self::ESCROW_ID_LEN + 1;

    /** An objection key of zeros: the box carries none (sealed by the operator, who never holds wrap_user). */
    private const NO_OBJECTION = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";

    /**
     * @param array{sign: string, box: string} $keys a keeper's public keys, raw
     * @return array{sign: string, box: string}
     */
    public static function checkKeys(array $keys): array
    {
        $sign = $keys['sign'] ?? null;
        $box  = $keys['box'] ?? null;
        if (!is_string($sign) || strlen($sign) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !is_string($box) || strlen($box) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('A keeper gives two raw public keys of 32 bytes: sign (Ed25519) and box (X25519)');
        }
        return ['sign' => $sign, 'box' => $box];
    }

    /**
     * What names a keeper: its two public keys, hashed. Shown to the holder at
     * each session, and recorded by the database as the keeper it was given.
     *
     * @param array{sign: string, box: string} $keys
     */
    public static function fingerprint(array $keys): string
    {
        $keys = self::checkKeys($keys);
        return substr(hash('sha256', self::PRINT_TAG . $keys['sign'] . $keys['box']), 0, 32);
    }

    public static function newEscrowId(): string
    {
        return Primitives::randomBytes(self::ESCROW_ID_LEN);
    }

    /** wrap_admin under the keeper: $inner sealed again, to the keeper's box key. */
    public static function wrap(string $escrowId, string $userId, ?string $objectionPublic, string $inner, string $keeperBoxPublic): string
    {
        if (strlen($escrowId) !== self::ESCROW_ID_LEN) {
            throw new InvalidArgumentException('An escrowId is ' . self::ESCROW_ID_LEN . ' bytes');
        }
        if ($objectionPublic !== null && strlen($objectionPublic) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('An objection key is a raw Ed25519 public key of 32 bytes');
        }
        $plain = self::OUTER_TAG . $escrowId . ($objectionPublic ?? self::NO_OBJECTION)
            . pack('N', strlen($userId)) . $userId . $inner;
        return 'k1:' . bin2hex($escrowId) . ':' . sodium_crypto_box_seal($plain, $keeperBoxPublic);
    }

    public static function isSealed(string $wrapAdmin): bool
    {
        return preg_match(self::FORM, $wrapAdmin) === 1 && strlen($wrapAdmin) > self::PREFIX_LEN;
    }

    /** The escrowId a keeper-sealed wrap_admin names before its box, raw; null in another form. */
    public static function escrowIdOf(string $wrapAdmin): ?string
    {
        return preg_match(self::FORM, $wrapAdmin, $m) === 1 ? (string) hex2bin($m[1]) : null;
    }

    /** The outer box of a keeper-sealed wrap_admin. */
    public static function outerOf(string $wrapAdmin): string
    {
        if (!self::isSealed($wrapAdmin)) {
            throw new InvalidArgumentException('This wrap_admin is not sealed to a keeper');
        }
        return substr($wrapAdmin, self::PREFIX_LEN);
    }

    /**
     * The keeper's side: the outer box opened with its box keypair. Throws
     * when it does not open, or holds no box of this form.
     *
     * @return array{escrowId: string, userId: string, objection: ?string, inner: string}
     */
    public static function open(string $outer, #[\SensitiveParameter] string $boxSecret, string $boxPublic): array
    {
        $pair  = sodium_crypto_box_keypair_from_secretkey_and_publickey($boxSecret, $boxPublic);
        $plain = sodium_crypto_box_seal_open($outer, $pair);
        Primitives::zeroize($pair);
        $head = strlen(self::OUTER_TAG) + self::ESCROW_ID_LEN + SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES + 4;
        if ($plain === false || strlen($plain) < $head || !str_starts_with($plain, self::OUTER_TAG)) {
            throw new RuntimeException('The box does not open under this keeper, or is not an escrow box');
        }
        $at        = strlen(self::OUTER_TAG);
        $escrowId  = substr($plain, $at, self::ESCROW_ID_LEN);
        $objection = substr($plain, $at += self::ESCROW_ID_LEN, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $length    = unpack('N', substr($plain, $at += SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 4))[1];
        $userId    = substr($plain, $at += 4, $length);
        if (strlen($userId) !== $length || strlen($plain) <= $at + $length) {
            throw new RuntimeException('The escrow box is cut short');
        }
        return [
            'escrowId'  => $escrowId,
            'userId'    => $userId,
            'objection' => hash_equals(self::NO_OBJECTION, $objection) ? null : $objection,
            'inner'     => substr($plain, $at + $length),
        ];
    }

    /**
     * The keeper's side: what it did for $escrowId, signed.
     *
     * @param list<array{case: string, seq: int, at: int}>          $releases
     * @param list<array{case: string, due: int, objected: bool}>   $pending
     */
    public static function attestation(
        string $escrowId,
        string $nonce,
        array $releases,
        array $pending,
        #[\SensitiveParameter] string $signSecret
    ): string {
        $json = json_encode([
            'v'        => 1,
            'escrowId' => bin2hex($escrowId),
            'nonce'    => bin2hex($nonce),
            'releases' => array_values($releases),
            'pending'  => array_values($pending),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return base64_encode($json) . '.' . base64_encode(sodium_crypto_sign_detached(self::ATTEST_TAG . $json, $signSecret));
    }

    /**
     * The holder's side: the attestation, verified under the keeper's sign key
     * and for this escrow and nonce. Throws KeeperAttestationException, naming
     * why, when it does not hold.
     *
     * @return array{releases: list<array{case: string, seq: int, at: int}>, pending: list<array{case: string, due: int, objected: bool}>}
     */
    public static function verifyAttestation(string $attestation, string $signPublic, string $escrowId, string $nonce): array
    {
        $parts = explode('.', $attestation);
        $json  = count($parts) === 2 ? base64_decode($parts[0], true) : false;
        $sig   = count($parts) === 2 ? base64_decode($parts[1], true) : false;
        if ($json === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new KeeperAttestationException('unreadable', 'the attestation is not in the keeper format');
        }
        if (!sodium_crypto_sign_verify_detached($sig, self::ATTEST_TAG . $json, $signPublic)) {
            throw new KeeperAttestationException('signature', 'the attestation is not signed by the keeper this escrow names');
        }
        $body = json_decode($json, true);
        if (!is_array($body) || ($body['v'] ?? null) !== 1
            || !is_array($body['releases'] ?? null) || !is_array($body['pending'] ?? null)) {
            throw new KeeperAttestationException('unreadable', 'the signed attestation is not in the keeper format');
        }
        if (!hash_equals(bin2hex($escrowId), (string) ($body['escrowId'] ?? ''))) {
            throw new KeeperAttestationException('escrow', 'the attestation is about another escrow');
        }
        if (!hash_equals(bin2hex($nonce), (string) ($body['nonce'] ?? ''))) {
            throw new KeeperAttestationException('nonce', 'the attestation answers another request: it may be an old one, replayed');
        }
        $releases = [];
        foreach ($body['releases'] as $r) {
            $releases[] = ['case' => (string) ($r['case'] ?? ''), 'seq' => (int) ($r['seq'] ?? 0), 'at' => (int) ($r['at'] ?? 0)];
        }
        $pending = [];
        foreach ($body['pending'] as $p) {
            $pending[] = ['case' => (string) ($p['case'] ?? ''), 'due' => (int) ($p['due'] ?? 0), 'objected' => (bool) ($p['objected'] ?? false)];
        }
        return ['releases' => $releases, 'pending' => $pending];
    }

    /** What an objection signs. */
    public static function objectionMessage(string $escrowId, string $caseRef): string
    {
        return self::OBJECT_TAG . $escrowId . pack('N', strlen($caseRef)) . $caseRef;
    }

    /** The keeper's side: whether $signature objects to case $caseRef of $escrowId, under the objection key its box carried. */
    public static function verifyObjection(string $signature, string $objectionPublic, string $escrowId, string $caseRef): bool
    {
        return strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($signature, self::objectionMessage($escrowId, $caseRef), $objectionPublic);
    }
}
