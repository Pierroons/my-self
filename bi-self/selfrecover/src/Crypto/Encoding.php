<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Crypto;

/**
 * Conversions entre ce que produit le navigateur et ce qu'attend OpenSSL.
 *
 * Le facteur « cet appareil » signe un défi avec WebCrypto, qui rend une
 * signature P1363 et une clé publique SPKI, toutes deux en base64url. PHP
 * vérifie avec `openssl_verify`, qui attend du DER et du PEM. Ces quatre
 * fonctions font le passage, et rien d'autre.
 */
final class Encoding
{
    public static function b64urlEncode(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    /**
     * Signature ECDSA P1363 (r||s brut de WebCrypto) → DER, attendu par
     * `openssl_verify`.
     *
     * ⚠️ Un INTEGER DER est signé : un octet de tête ≥ 0x80 se lirait comme un
     * nombre négatif, d'où le 0x00 préfixé. Sans lui, une signature sur deux
     * environ échoue à la vérification — celles dont r ou s commence haut.
     */
    public static function p1363ToDer(string $sig): string
    {
        $len = intdiv(strlen($sig), 2);
        if ($len === 0) {
            return '';
        }
        $r = ltrim(substr($sig, 0, $len), "\x00"); if ($r === '') { $r = "\x00"; }
        $s = ltrim(substr($sig, $len), "\x00");    if ($s === '') { $s = "\x00"; }
        if (ord($r[0]) & 0x80) { $r = "\x00" . $r; }
        if (ord($s[0]) & 0x80) { $s = "\x00" . $s; }
        $body = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;

        return "\x30" . chr(strlen($body)) . $body;
    }

    /** Clé publique SPKI (DER) → PEM, pour `openssl_pkey_get_public`. */
    public static function spkiToPem(string $spkiDer): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($spkiDer), 64, "\n")
             . "-----END PUBLIC KEY-----\n";
    }
}
