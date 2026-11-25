<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| The Laravel scheduler needs ONE cron entry on the server:
|
|     * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
|
| (scripts/setup-ubuntu.sh installs it for you.)
*/

Schedule::command('sheets:sync')
    ->dailyAt((string) config('sheets-sync.schedule_time', '02:00'))
    ->withoutOverlapping()
    ->onFailure(function () {
        logger()->error('Scheduled sheets:sync finished with at least one failed mapping. See the sync_runs table or the admin UI.');
    });