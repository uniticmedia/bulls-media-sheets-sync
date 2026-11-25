<?php

namespace App\Console\Commands;

use App\Models\SyncMapping;
use App\Models\SyncRun;
use App\Models\SyncSource;
use App\Services\Sync\SyncService;
use Illuminate\Console\Command;

class SheetsSyncCommand extends Command
{
    protected $signature = 'sheets:sync
                            {--source= : Only sync the source with this ID}
                            {--mapping= : Only sync the mapping with this ID (even if it is switched off)}';

    protected $description = 'Read the configured Google Sheets and store their data in the database';

    public function handle(SyncService $sync): int
    {
        if ($mappingId = $this->option('mapping')) {
            $runs = [$sync->runMapping(SyncMapping::with(['source', 'columns'])->findOrFail($mappingId))];
        } elseif ($sourceId = $this->option('source')) {
            $runs = $sync->syncSource(SyncSource::findOrFail($sourceId));
        } else {
            $runs = $sync->syncAll();
        }

        if ($runs === []) {
            $this->warn('Nothing to sync: no active sources with active mappings.');

            return self::SUCCESS;
        }

        $this->table(
            ['Run', 'Source / tab', 'Target table', 'Status', 'Read', 'Written', 'Skipped', 'New columns'],
            array_map(function (SyncRun $run) {
                $run->load('mapping.source');

                return [
                    $run->id,
                    $run->mapping->source->name.' / '.$run->mapping->tab_name,
                    $run->mapping->target_table,
                    $run->status,
                    $run->rows_read,
                    $run->rows_written,
                    $run->rows_skipped,
                    implode(', ', $run->columns_added ?? []),
                ];
            }, $runs),
        );

        foreach ($runs as $run) {
            if ($run->message) {
                $line = "#{$run->id}: {$run->message}";
                $run->status === SyncRun::FAILED ? $this->error($line) : $this->warn($line);
            }
        }

        $failed = count(array_filter($runs, fn (SyncRun $run) => $run->status === SyncRun::FAILED));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}