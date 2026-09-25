<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Store\Jws;
use App\Services\Store\StoreFailed;
use PHPUnit\Framework\TestCase;

/**
 * The JOSE corner of the store drivers: ES256/RS256 signatures, DER<->raw
 * conversion, and the x5c chain check that makes an Apple JWS trustworthy.
 */
class JwsTest extends TestCase
{
    private ?string $opensslConfig = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Windows builds of PHP need to be told where openssl.cnf is before
        // they can mint certificates.
        foreach ([getenv('OPENSSL_CONF') ?: '', 'C:\\xampp\\apache\\conf\\openssl.cnf', 'C:\\xampp\\php\\extras\\openssl\\openssl.cnf', '/etc/ssl/openssl.cnf'] as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                $this->opensslConfig = $candidate;
                break;
            }
        }
    }

    public function test_es256_signatures_round_trip_and_tampering_is_caught(): void
    {
        [$private, $public] = $this->ecKeyPair();

        $jwt = Jws::signEs256(['alg' => 'ES256', 'kid' => 'k1', 'typ' => 'JWT'], ['iss' => 'me', 'exp' => time() + 60], $private);
        $decoded = Jws::decode($jwt);

        $this->assertSame('ES256', $decoded['header']['alg']);
        $this->assertSame('me', $decoded['payload']['iss']);
        $this->assertSame(64, strlen($decoded['signature']), 'EXPECTED a raw r||s signature.');
        $this->assertTrue(Jws::verifyEs256($decoded['input'], $decoded['signature'], openssl_pkey_get_public($public)));

        $tampered = explode('.', $jwt);
        $tampered[1] = Jws::b64e('{"iss":"somebody else"}');
        $forged = Jws::decode(implode('.', $tampered));
        $this->assertFalse(Jws::verifyEs256($forged['input'], $forged['signature'], openssl_pkey_get_public($public)));
    }

    public function test_rs256_signatures_round_trip(): void
    {
        [$private, $public] = $this->rsaKeyPair();

        $jwt = Jws::signRs256(['alg' => 'RS256', 'typ' => 'JWT'], ['aud' => 'https://example.test/webhooks/store/google'], $private);
        $decoded = Jws::decode($jwt);

        $this->assertTrue(Jws::verifyRs256($decoded['input'], $decoded['signature'], openssl_pkey_get_public($public)));
        $this->assertFalse(Jws::verifyRs256($decoded['input'].'x', $decoded['signature'], openssl_pkey_get_public($public)));
    }

    public function test_der_and_raw_signature_forms_convert_both_ways(): void
    {
        // High bits set on both halves, so the DER form needs padding bytes.
        $raw = str_repeat("\xff", 32).str_repeat("\x80", 32);
        $der = Jws::rawToDer($raw);

        $this->assertSame("\x30", $der[0]);
        $this->assertSame($raw, Jws::derToRaw($der));

        // Leading zeros are stripped in DER and restored in raw.
        $short = str_repeat("\0", 31)."\x01".str_repeat("\0", 30)."\x02\x03";
        $this->assertSame($short, Jws::derToRaw(Jws::rawToDer($short)));
    }

    public function test_an_apple_style_jws_verifies_only_against_the_pinned_root(): void
    {
        if ($this->opensslConfig === null) {
            $this->markTestSkipped('No openssl.cnf available to mint test certificates.');
        }

        [$rootKey, $rootCert] = $this->certificate('Test Root CA');
        [$intermediateKey, $intermediateCert] = $this->certificate('Test Intermediate CA', $rootCert, $rootKey);
        [$leafKey, $leafCert] = $this->certificate('Test Leaf', $intermediateCert, $intermediateKey);
        [, $otherRoot] = $this->certificate('Somebody Else Root');

        $x5c = array_map(fn (string $pem): string => $this->pemToBase64Der($pem), [$leafCert, $intermediateCert, $rootCert]);
        $jws = Jws::signEs256(['alg' => 'ES256', 'x5c' => $x5c], ['transactionId' => '42', 'bundleId' => 'com.example.dating'], $leafKey);

        $payload = Jws::verifyApple($jws, $rootCert);
        $this->assertSame('42', $payload['transactionId']);

        // The same JWS against a different pinned root is worthless.
        try {
            Jws::verifyApple($jws, $otherRoot);
            $this->fail('EXPECTED a chain ending at an unpinned root to be refused.');
        } catch (StoreFailed $e) {
            $this->assertSame('bad_signature', $e->reason);
        }

        // A chain with the intermediate removed does not link up.
        $broken = Jws::signEs256(['alg' => 'ES256', 'x5c' => [$x5c[0], $x5c[2]]], ['transactionId' => '42'], $leafKey);

        try {
            Jws::verifyApple($broken, $rootCert);
            $this->fail('EXPECTED a broken chain to be refused.');
        } catch (StoreFailed $e) {
            $this->assertSame('bad_signature', $e->reason);
        }

        // A payload signed by a key that is not the leaf's does not verify.
        [$strangerKey] = $this->ecKeyPair();
        $forged = Jws::signEs256(['alg' => 'ES256', 'x5c' => $x5c], ['transactionId' => '43'], $strangerKey);

        try {
            Jws::verifyApple($forged, $rootCert);
            $this->fail('EXPECTED a forged signature to be refused.');
        } catch (StoreFailed $e) {
            $this->assertSame('bad_signature', $e->reason);
        }
    }

    public function test_malformed_input_is_refused_rather_than_crashing(): void
    {
        foreach (['', 'a.b', 'a.b.c.d', 'not-json.not-json.sig'] as $bad) {
            try {
                Jws::decode($bad);
                $this->fail("EXPECTED '{$bad}' to be refused.");
            } catch (StoreFailed $e) {
                $this->assertSame('bad_signature', $e->reason);
            }
        }
    }

    // ---- helpers ---------------------------------------------------------------------

    /** @return array{0: string, 1: string} private PEM, public PEM */
    private function ecKeyPair(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'] + $this->config());
        $this->assertNotFalse($key, openssl_error_string() ?: 'openssl_pkey_new failed');
        openssl_pkey_export($key, $private, null, $this->config());

        return [$private, openssl_pkey_get_details($key)['key']];
    }

    /** @return array{0: string, 1: string} */
    private function rsaKeyPair(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048] + $this->config());
        $this->assertNotFalse($key, openssl_error_string() ?: 'openssl_pkey_new failed');
        openssl_pkey_export($key, $private, null, $this->config());

        return [$private, openssl_pkey_get_details($key)['key']];
    }

    /**
     * An EC certificate, self-signed or signed by an issuer.
     *
     * @return array{0: string, 1: string} private key PEM, certificate PEM
     */
    private function certificate(string $commonName, ?string $issuerCert = null, ?string $issuerKey = null): array
    {
        [$private] = $this->ecKeyPair();
        $key = openssl_pkey_get_private($private);
        $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256'] + $this->config());
        $this->assertNotFalse($csr, openssl_error_string() ?: 'openssl_csr_new failed');

        $cert = openssl_csr_sign($csr, $issuerCert, $issuerKey === null ? $key : openssl_pkey_get_private($issuerKey), 365, ['digest_alg' => 'sha256'] + $this->config(), random_int(1, PHP_INT_MAX));
        $this->assertNotFalse($cert, openssl_error_string() ?: 'openssl_csr_sign failed');
        openssl_x509_export($cert, $pem);

        return [$private, $pem];
    }

    private function pemToBase64Der(string $pem): string
    {
        return (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
    }

    /** @return array<string, string> */
    private function config(): array
    {
        return $this->opensslConfig === null ? [] : ['config' => $this->opensslConfig];
    }
}
