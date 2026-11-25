<?php

namespace App\Services\Sync;

use App\Models\SyncMapping;
use App\Models\SyncRun;
use App\Models\SyncSource;
use App\Services\Sheets\SheetsClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Si ndodh sinkronizimi I te dhenave:
 *
 *   lexo tabin -> gjej kolonat që përputhen & i konverton vlerat -> krijon/ndryshon tabelen -> zevendeson rreshtat e atij mapp-i
 *
 * Si punon: "zevendesimi per mapping". Çdo rresht në tabelë mban mend se nga cili mapp-i e shkroi
 * (sync_mapping_id). Ne cdo RUN, fshihen rreshtat e atij mapping-u & futen serish me update
 * brenda nje transaksioni. Dmth nqs i ben RUN 2x nuk dyfishohen te dhenat,
 * kap ndryshimet + fshirjet qe behen ne sheet, plotesojme me kolonat e reja,
 * dhe lejon qe disa file të shkruajne ne te njejten tabele pa prekur rreshtat e njeri-tjetrit.
 */
final class SyncService
{
    public function __construct(
        private SheetsClient $client,
        private TableManager $tables,
        private RowExtractor $extractor,
    ) {
    }

    /**
     * @return array<int, SyncRun>
     */
    public function syncAll(): array
    {
        $runs = [];

        foreach (SyncSource::query()->where('is_active', true)->orderBy('id')->get() as $source) {
            foreach ($this->syncSource($source) as $run) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    /**
     * @return array<int, SyncRun>
     */
    public function syncSource(SyncSource $source): array
    {
        $mappings = $source->mappings()->where('is_active', true)->with('columns')->orderBy('id')->get();

        // Nje tab qe shkon ne disa tabela, e shkarkojme vetem nje here kur bejme RUN.
        $sheetCache = [];
        $runs = [];

        foreach ($mappings as $mapping) {
            $mapping->setRelation('source', $source);
            $runs[] = $this->runMapping($mapping, $sheetCache);
        }

        return $runs;
    }

    /**
     * Nuk nxjerr exception per probleme me te dhenat, konfigurimin apo Google-n: rezultati ruhet ne run-in e kthyer.
     *
     * @param  array<string, array<int, array<int, mixed>>>  $sheetCache
     */
    public function runMapping(SyncMapping $mapping, array &$sheetCache = []): SyncRun
    {
        $mapping->loadMissing(['source', 'columns']);

        $run = SyncRun::create([
            'sync_mapping_id' => $mapping->id,
            'status' => SyncRun::RUNNING,
            'started_at' => now(),
        ]);

        $lock = Cache::lock('sheets-sync:mapping:'.$mapping->id, 900);

        if (! $lock->get()) {
            return $this->finish($run, SyncRun::FAILED, 'Another sync of this mapping is still running.');
        }

        try {
            return $this->execute($mapping, $run, $sheetCache);
        } catch (Throwable $e) {
            if (! $e instanceof RuntimeException && ! $e instanceof InvalidArgumentException) {
                report($e); // error-et e papritura (si te databazes) shkojne edhe ne log
            }

            return $this->finish($run, SyncRun::FAILED, $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheetCache
     */
    private function execute(SyncMapping $mapping, SyncRun $run, array &$sheetCache): SyncRun
    {
        $columns = $mapping->columns->map(fn ($column) => [
            'source_column' => $column->source_column,
            'target_column' => $column->target_column,
            'data_type' => $column->data_type,
        ])->all();

        if ($columns === []) {
            throw new RuntimeException('This mapping has no columns configured.');
        }

        $source = $mapping->source;
        $cacheKey = $source->id."\0".$mapping->tab_name;
        $sheet = $sheetCache[$cacheKey] ??= $this->client->values($source->spreadsheet_id, $mapping->tab_name);

        // Nxjerr te dhenat fillimisht: header i keq ose tab qe s'arrihet deshton para se te preket databaza.
        $extracted = $this->extractor->extract($sheet, $mapping->header_row, $columns);

        // DDL-ja ekzekutohet para transaksionit te te dhenave, jashte tij.
        $added = $this->tables->ensure($mapping->target_table, $columns);

        $written = $this->replaceRows($mapping, count($columns), $extracted['rows']);

        $warnings = [];

        if ($extracted['missing_columns'] !== []) {
            $warnings[] = 'Columns not found in the sheet (stored as NULL): '.implode(', ', $extracted['missing_columns']).'.';
        }

        if ($extracted['cast_errors'] > 0) {
            $warnings[] = "{$extracted['cast_errors']} value(s) could not be converted and were stored as NULL: "
                .implode('; ', $extracted['error_samples']).($extracted['cast_errors'] > count($extracted['error_samples']) ? '; …' : '');
        }

        return $this->finish(
            $run,
            $warnings === [] ? SyncRun::SUCCESS : SyncRun::WARNING,
            $warnings === [] ? null : implode("\n", $warnings),
            [
                'rows_read' => $extracted['read'],
                'rows_written' => $written,
                'rows_skipped' => $extracted['skipped_empty'],
                'columns_added' => $added === [] ? null : $added,
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function replaceRows(SyncMapping $mapping, int $columnCount, array $rows): int
    {
        $table = $mapping->target_table;
        $syncedAt = now();

        // Mbaj numrin e parametrave per INSERT poshte limiteve te databazes.
        $perInsert = max(1, min((int) config('sheets-sync.chunk_size', 500), intdiv(900, $columnCount + 2)));

        DB::transaction(function () use ($table, $mapping, $rows, $syncedAt, $perInsert) {
            DB::table($table)->where('sync_mapping_id', $mapping->id)->delete();

            foreach (array_chunk($rows, $perInsert) as $chunk) {
                DB::table($table)->insert(array_map(
                    fn (array $row) => $row + ['sync_mapping_id' => $mapping->id, 'synced_at' => $syncedAt],
                    $chunk
                ));
            }
        });

        return count($rows);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function finish(SyncRun $run, string $status, ?string $message = null, array $attributes = []): SyncRun
    {
        $run->update($attributes + [
            'status' => $status,
            'message' => $message === null ? null : Str::limit($message, 2000),
            'finished_at' => now(),
        ]);

        return $run;
    }
}