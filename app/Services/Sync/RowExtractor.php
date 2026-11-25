<?php

namespace App\Services\Sync;

use InvalidArgumentException;
use RuntimeException;

/**
 * Merr te dhenat raw nga Sheets API dhe i kthen ne rreshta te cilesuar sipas emrit te kolones ne databaze,
 * sipas mapping-ut qe kemi konfiguruar.
 */
final class RowExtractor
{
    private const MAX_ERROR_SAMPLES = 5;

    public function __construct(private ValueCaster $caster)
    {
    }

    /**
     * @param  array<int, array<int, mixed>>  $sheet  raw values, header row included
     * @param  int  $headerRow  1-based row number that holds the column titles
     * @param  array<int, array{source_column: string, target_column: string, data_type: string}>  $columns
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     read: int,
     *     skipped_empty: int,
     *     missing_columns: array<int, string>,
     *     cast_errors: int,
     *     error_samples: array<int, string>
     * }
     */
    public function extract(array $sheet, int $headerRow, array $columns): array
    {
        $sheet = array_values($sheet);
        $columns = array_values($columns);
        $header = $sheet[$headerRow - 1] ?? null;

        if (! is_array($header) || $header === []) {
            throw new RuntimeException("Header row {$headerRow} was not found: the tab is empty or the header row number is wrong.");
        }

        $index = HeaderMatcher::index($header);

        // Llogarit pozicionin e kolonave nje here ne fillim, jo per cdo rresht.
        $positions = [];
        $missing = [];

        foreach ($columns as $column) {
            $position = $index[HeaderMatcher::normalize($column['source_column'])] ?? null;

            if ($position === null) {
                $missing[] = $column['source_column'];
            }

            $positions[] = $position;
        }

        if (count($missing) === count($columns)) {
            $found = implode(', ', array_map(fn ($h) => "'".trim((string) $h)."'", array_filter($header, fn ($h) => trim((string) $h) !== '')));

            throw new RuntimeException("None of the configured columns were found in header row {$headerRow}. Found: {$found}.");
        }

        $rows = [];
        $read = 0;
        $skipped = 0;
        $castErrors = 0;
        $samples = [];

        foreach (array_slice($sheet, $headerRow) as $offset => $cells) {
            $read++;
            $cells = is_array($cells) ? array_values($cells) : [];
            $sheetRow = $headerRow + $offset + 1;

            $record = [];
            $hasData = false;

            foreach ($columns as $i => $column) {
                $raw = $positions[$i] === null ? null : ($cells[$positions[$i]] ?? null);

                if ($raw !== null && (! is_string($raw) || trim($raw) !== '')) {
                    $hasData = true;
                }

                try {
                    $record[$column['target_column']] = $this->caster->cast($raw, $column['data_type']);
                } catch (InvalidArgumentException $e) {
                    $record[$column['target_column']] = null;
                    $castErrors++;

                    if (count($samples) < self::MAX_ERROR_SAMPLES) {
                        $samples[] = "row {$sheetRow}, column '{$column['source_column']}': {$e->getMessage()}";
                    }
                }
            }

            if (! $hasData) {
                $skipped++;

                continue;
            }

            $rows[] = $record;
        }

        return [
            'rows' => $rows,
            'read' => $read,
            'skipped_empty' => $skipped,
            'missing_columns' => $missing,
            'cast_errors' => $castErrors,
            'error_samples' => $samples,
        ];
    }
}