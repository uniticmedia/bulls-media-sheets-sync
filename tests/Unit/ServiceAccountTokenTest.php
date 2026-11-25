<?php

namespace Tests\Unit;

use App\Services\Sheets\ServiceAccountToken;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ServiceAccountTokenTest extends TestCase
{
    public function test_builds_a_valid_signed_jwt_assertion(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            // Happens on Windows/XAMPP when PHP cannot find openssl.cnf (set the OPENSSL_CONF environment variable).
            $this->markTestSkipped('OpenSSL could not generate a test key on this machine.');
        }

        openssl_pkey_export($key, $privatePem);
        $publicPem = openssl_pkey_get_details($key)['key'];

        $jwt = ServiceAccountToken::buildAssertion([
            'client_email' => 'sync@example-project.iam.gserviceaccount.com',
            'private_key' => $privatePem,
        ], 1_700_000_000);

        [$header, $claims, $signature] = explode('.', $jwt);

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode($this->decode($header), true));

        $payload = json_decode($this->decode($claims), true);
        $this->assertSame('sync@example-project.iam.gserviceaccount.com', $payload['iss']);
        $this->assertSame('https://www.googleapis.com/auth/spreadsheets.readonly', $payload['scope']);
        $this->assertSame('https://oauth2.googleapis.com/token', $payload['aud']);
        $this->assertSame(1_700_000_000, $payload['iat']);
        $this->assertSame(1_700_003_600, $payload['exp']);

        // The signature must verify against the matching public key.
        $this->assertSame(1, openssl_verify($header.'.'.$claims, $this->decode($signature), $publicPem, OPENSSL_ALGO_SHA256));
        $this->assertStringNotContainsString('=', $jwt);
        $this->assertStringNotContainsString('+', $jwt);
    }

    public function test_rejects_an_unreadable_private_key(): void
    {
        $this->expectException(RuntimeException::class);

        ServiceAccountToken::buildAssertion(['client_email' => 'a@b.c', 'private_key' => 'not a key'], time());
    }

    private function decode(string $base64Url): string
    {
        return (string) base64_decode(strtr($base64Url, '-_', '+/'));
    }
}