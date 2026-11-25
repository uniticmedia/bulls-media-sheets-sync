<?php

namespace Tests\Support;

use App\Services\Sheets\SheetsClient;
use RuntimeException;

/**
 * In-memory stand-in for the Google Sheets API, so tests never touch the network.
 */
final class FakeSheetsClient implements SheetsClient
{
    public int $calls = 0;

    /** @var array<string, array<string, array<int, array<int, mixed>>>> */
    private array $sheets = [];

    /**
     * @param  array<int, array<int, mixed>>  $rows  header row first, like the real API returns them
     */
    public function set(string $spreadsheetId, string $tab, array $rows): self
    {
        $this->sheets[$spreadsheetId][$tab] = $rows;

        return $this;
    }

    public function values(string $spreadsheetId, string $tab): array
    {
        $this->calls++;

        if (! isset($this->sheets[$spreadsheetId])) {
            throw new RuntimeException('Spreadsheet not found. Check the spreadsheet ID.');
        }

        return $this->sheets[$spreadsheetId][$tab]
            ?? throw new RuntimeException("Tab '{$tab}' was not found in the spreadsheet.");
    }
}