<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRun extends Model
{
    public const RUNNING = 'running';

    public const SUCCESS = 'success';

    public const WARNING = 'warning';

    public const FAILED = 'failed';

    protected $fillable = [
        'sync_mapping_id', 'status', 'rows_read', 'rows_written', 'rows_skipped',
        'columns_added', 'message', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'columns_added' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(SyncMapping::class, 'sync_mapping_id');
    }

    /**
     * One-line summary for flash messages, e.g. "3 mapping(s) synced: 2 ok, 1 with warnings, 0 failed."
     *
     * @param  array<int, SyncRun>  $runs
     * @return array{0: string, 1: string} [message, bootstrap alert level]
     */
    public static function summarize(array $runs): array
    {
        if ($runs === []) {
            return ['Nothing to sync: there are no active mappings.', 'warning'];
        }

        $count = fn (string $status) => count(array_filter($runs, fn (SyncRun $run) => $run->status === $status));
        $failed = $count(self::FAILED);
        $warnings = $count(self::WARNING);

        $message = sprintf(
            '%d mapping(s) synced: %d ok, %d with warnings, %d failed.',
            count($runs), $count(self::SUCCESS), $warnings, $failed
        );

        return [$message, $failed > 0 ? 'danger' : ($warnings > 0 ? 'warning' : 'success')];
    }
}