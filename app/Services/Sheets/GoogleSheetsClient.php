<?php

namespace App\Services\Sheets;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads tabs through the Google Sheets REST API (spreadsheets.values.get).
 */
final class GoogleSheetsClient implements SheetsClient
{
    private const BASE_URL = 'https://sheets.googleapis.com/v4/spreadsheets';

    private const MAX_ATTEMPTS = 3;

    public function __construct(private ServiceAccountToken $token)
    {
    }

    public function values(string $spreadsheetId, string $tab): array
    {
        // A1 notation: a bare, quoted sheet title selects the whole tab.
        $range = "'".str_replace("'", "''", $tab)."'";
        $url = self::BASE_URL.'/'.rawurlencode($spreadsheetId).'/values/'.rawurlencode($range);

        $response = $this->get($url);

        if ($response->successful()) {
            return $response->json('values') ?? [];
        }

        throw new RuntimeException($this->describeFailure($response, $tab));
    }

    private function get(string $url): Response
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = Http::withToken($this->token->get())
                    ->acceptJson()
                    ->timeout(60)
                    ->get($url, [
                        'majorDimension' => 'ROWS',
                        'valueRenderOption' => 'UNFORMATTED_VALUE',
                        'dateTimeRenderOption' => 'SERIAL_NUMBER',
                    ]);
            } catch (ConnectionException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new RuntimeException('Could not reach the Google Sheets API: '.$e->getMessage());
                }

                usleep(500_000 * $attempt);

                continue;
            }

            $retryable = $response->status() === 429 || $response->serverError();

            if (! $retryable || $attempt >= self::MAX_ATTEMPTS) {
                return $response;
            }

            usleep(1_000_000 * $attempt);
        }
    }

    private function describeFailure(Response $response, string $tab): string
    {
        $googleMessage = (string) ($response->json('error.message') ?? $response->body());

        return match (true) {
            $response->status() === 400 && str_contains($googleMessage, 'Unable to parse range')
                => "Tab '{$tab}' was not found in the spreadsheet.",
            $response->status() === 403
                => "Access denied. Share the spreadsheet with the service account's client_email (Viewer is enough) "
                    .'and make sure the Google Sheets API is enabled. Google said: '.$googleMessage,
            $response->status() === 404
                => 'Spreadsheet not found. Check the spreadsheet ID.',
            default => "Google Sheets API error (HTTP {$response->status()}): {$googleMessage}",
        };
    }
}