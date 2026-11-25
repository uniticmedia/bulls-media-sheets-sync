<?php

namespace App\Services\Sheets;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Merr access token per Google service account (JWT bearer).
 * https://developers.google.com/identity/protocols/oauth2/service-account
 * Nenshkruan JWT-ne me private key nga JSON-i dhe e kemben me token 1-oresh,
 * pastaj e ruan ne cache per 50 minuta.
 */
final class ServiceAccountToken
{
    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const CACHE_KEY = 'sheets-sync:google-access-token';

    public function __construct(private string $credentialsPath)
    {
    }

    public function get(): string
    {
        return Cache::remember(self::CACHE_KEY, 3000, fn () => $this->fetch());
    }

    private function fetch(): string
    {
        $credentials = $this->credentials();

        $response = Http::asForm()->timeout(30)->post($credentials['token_uri'] ?? self::DEFAULT_TOKEN_URI, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => self::buildAssertion($credentials, time()),
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            $reason = $response->json('error_description') ?? $response->json('error') ?? 'HTTP '.$response->status();

            throw new RuntimeException("Google rejected the service account credentials: {$reason}");
        }

        return (string) $response->json('access_token');
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(): array
    {
        if (! is_file($this->credentialsPath) || ! is_readable($this->credentialsPath)) {
            throw new RuntimeException(
                "Google service account file not found at '{$this->credentialsPath}'. See README, section \"Google API credentials\"."
            );
        }

        $data = json_decode((string) file_get_contents($this->credentialsPath), true);

        if (! is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            throw new RuntimeException('The service account file is not valid: client_email / private_key are missing.');
        }

        return $data;
    }

    /**
     * Nderton & nenshkruan JWT-ne. Nuk ben asgje tjeter pervec OpenSSL.
     *
     * @param  array<string, mixed>  $credentials  decoded service account JSON
     */
    public static function buildAssertion(array $credentials, int $now): string
    {
        $claims = [
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $credentials['token_uri'] ?? self::DEFAULT_TOKEN_URI,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $signingInput = self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'.self::base64Url((string) json_encode($claims));

        $key = openssl_pkey_get_private((string) $credentials['private_key']);

        if ($key === false) {
            throw new RuntimeException('The private_key in the service account file could not be read.');
        }

        if (! openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the Google JWT assertion.');
        }

        return $signingInput.'.'.self::base64Url($signature);
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}