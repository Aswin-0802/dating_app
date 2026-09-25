<?php

declare(strict_types=1);

namespace App\Services\Store;

use OpenSSLAsymmetricKey;

/**
 * Just enough JOSE to talk to the stores without a library.
 *
 * Apple signs transactions and notifications as ES256 JWS carrying an x5c
 * certificate chain that must end at Apple's root; Google's Pub/Sub push
 * tokens are RS256 signed by keys Google publishes; and the App Store Server
 * API and Google's service accounts are authenticated with JWTs WE sign.
 * All of that is a few hundred bytes of DER handling and openssl calls.
 */
final class Jws
{
    /**
     * Split and decode a compact JWS without verifying anything.
     *
     * @return array{header: array<string, mixed>, payload: array<string, mixed>, signature: string, input: string}
     *
     * @throws StoreFailed
     */
    public static function decode(string $jws): array
    {
        $parts = explode('.', trim($jws));

        if (count($parts) !== 3) {
            throw new StoreFailed('Malformed JWS: expected three segments.', 'bad_signature');
        }

        [$h, $p, $s] = $parts;
        $header = json_decode(self::b64d($h), true);
        $payload = json_decode(self::b64d($p), true);

        if (! is_array($header) || ! is_array($payload)) {
            throw new StoreFailed('Malformed JWS: header or payload is not JSON.', 'bad_signature');
        }

        return ['header' => $header, 'payload' => $payload, 'signature' => self::b64d($s), 'input' => "{$h}.{$p}"];
    }

    /**
     * Verify an Apple-signed JWS and return its payload.
     *
     * The header's x5c chain is checked link by link and must end at the
     * pinned Apple Root CA G3; only then is the leaf trusted to have signed
     * the payload. A valid signature from an untrusted chain is worthless —
     * anyone can mint a chain.
     *
     * @return array<string, mixed>
     *
     * @throws StoreFailed reason bad_signature
     */
    public static function verifyApple(string $jws, string $rootPem): array
    {
        $decoded = self::decode($jws);

        if (($decoded['header']['alg'] ?? null) !== 'ES256') {
            throw new StoreFailed('Apple JWS is not ES256.', 'bad_signature');
        }

        $x5c = $decoded['header']['x5c'] ?? null;

        if (! is_array($x5c) || count($x5c) < 2) {
            throw new StoreFailed('Apple JWS carries no certificate chain.', 'bad_signature');
        }

        $leaf = self::verifyChain($x5c, $rootPem);
        $key = openssl_pkey_get_public($leaf);

        if ($key === false || ! self::verifyEs256($decoded['input'], $decoded['signature'], $key)) {
            throw new StoreFailed('Apple JWS signature does not verify.', 'bad_signature');
        }

        return $decoded['payload'];
    }

    /**
     * Check an x5c chain (leaf first) ends at the pinned root and that each
     * certificate was signed by the next and is within its validity dates.
     * Returns the leaf as PEM.
     *
     * @param  array<int, string>  $x5c  base64 DER certificates, leaf first
     *
     * @throws StoreFailed
     */
    public static function verifyChain(array $x5c, string $rootPem): string
    {
        $certs = array_map(fn (string $der): string => self::derToPem($der), array_values($x5c));

        $pinned = openssl_x509_fingerprint($rootPem, 'sha256');
        $last = openssl_x509_fingerprint($certs[array_key_last($certs)], 'sha256');

        if ($pinned === false || $last === false || ! hash_equals($pinned, $last)) {
            throw new StoreFailed('Certificate chain does not end at the pinned root.', 'bad_signature');
        }

        $now = time();

        for ($i = 0; $i < count($certs) - 1; $i++) {
            $issuerKey = openssl_pkey_get_public($certs[$i + 1]);

            if ($issuerKey === false || openssl_x509_verify($certs[$i], $issuerKey) !== 1) {
                throw new StoreFailed("Certificate {$i} in the chain was not signed by its issuer.", 'bad_signature');
            }

            $info = openssl_x509_parse($certs[$i]);

            if (! is_array($info) || ($info['validFrom_time_t'] ?? PHP_INT_MAX) > $now || ($info['validTo_time_t'] ?? 0) < $now) {
                throw new StoreFailed("Certificate {$i} in the chain is outside its validity period.", 'bad_signature');
            }
        }

        return $certs[0];
    }

    /** ES256: the JWS signature is raw r||s (64 bytes); openssl wants DER. */
    public static function verifyEs256(string $input, string $rawSignature, OpenSSLAsymmetricKey $publicKey): bool
    {
        if (strlen($rawSignature) !== 64) {
            return false;
        }

        return openssl_verify($input, self::rawToDer($rawSignature), $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    public static function verifyRs256(string $input, string $signature, OpenSSLAsymmetricKey $publicKey): bool
    {
        return openssl_verify($input, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Sign a JWT with an EC P-256 key (App Store Server API).
     *
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $payload
     *
     * @throws StoreFailed reason store_unavailable when the key is unusable
     */
    public static function signEs256(array $header, array $payload, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new StoreFailed('The EC private key could not be read.', 'store_unavailable');
        }

        $input = self::b64e((string) json_encode($header)).'.'.self::b64e((string) json_encode($payload));

        if (! openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new StoreFailed('ES256 signing failed.', 'store_unavailable');
        }

        return $input.'.'.self::b64e(self::derToRaw($der));
    }

    /**
     * Sign a JWT with an RSA key (Google service accounts).
     *
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $payload
     *
     * @throws StoreFailed reason store_unavailable when the key is unusable
     */
    public static function signRs256(array $header, array $payload, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new StoreFailed('The RSA private key could not be read.', 'store_unavailable');
        }

        $input = self::b64e((string) json_encode($header)).'.'.self::b64e((string) json_encode($payload));

        if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new StoreFailed('RS256 signing failed.', 'store_unavailable');
        }

        return $input.'.'.self::b64e($signature);
    }

    public static function b64e(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function b64d(string $text): string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4), true);

        return $decoded === false ? '' : $decoded;
    }

    public static function derToPem(string $base64Der): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split(trim($base64Der), 64, "\n").'-----END CERTIFICATE-----';
    }

    /** r||s (32+32 bytes) -> DER SEQUENCE { INTEGER r, INTEGER s }. */
    public static function rawToDer(string $raw): string
    {
        $body = self::derInteger(substr($raw, 0, 32)).self::derInteger(substr($raw, 32, 32));

        return "\x30".self::derLength(strlen($body)).$body;
    }

    /** DER SEQUENCE { INTEGER r, INTEGER s } -> r||s (32+32 bytes). */
    public static function derToRaw(string $der): string
    {
        $offset = 2;

        if (ord($der[1]) & 0x80) {
            $offset += ord($der[1]) & 0x7F; // long-form length
        }

        [$r, $offset] = self::readDerInteger($der, $offset);
        [$s] = self::readDerInteger($der, $offset);

        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT).str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");

        if ($bytes === '') {
            $bytes = "\0";
        }

        if (ord($bytes[0]) & 0x80) {
            $bytes = "\0".$bytes; // keep it positive
        }

        return "\x02".self::derLength(strlen($bytes)).$bytes;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\0");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    /** @return array{0: string, 1: int} the integer's bytes and the offset after it */
    private static function readDerInteger(string $der, int $offset): array
    {
        if (($der[$offset] ?? null) !== "\x02") {
            throw new StoreFailed('Malformed DER signature.', 'bad_signature');
        }

        $length = ord($der[$offset + 1]);
        $offset += 2;

        if ($length & 0x80) {
            $count = $length & 0x7F;
            $length = 0;

            for ($i = 0; $i < $count; $i++) {
                $length = ($length << 8) | ord($der[$offset + $i]);
            }

            $offset += $count;
        }

        return [substr($der, $offset, $length), $offset + $length];
    }
}
