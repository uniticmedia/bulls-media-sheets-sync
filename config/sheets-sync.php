<?php

return [

/*
    |--------------------------------------------------------------------------
    | Google service account key
    |--------------------------------------------------------------------------
    | Path-i i JSON-it te key-it nga Google Cloud Console.
    | Shiko README, pjesa "Google API credentials".
    */
    'credentials_path' => env('GOOGLE_SHEETS_CREDENTIALS', storage_path('app/google/service-account.json')),

    /*
    |--------------------------------------------------------------------------
    | Daily schedule
    |--------------------------------------------------------------------------
    | Ora (format 24h, timezone e serverit) kur behet `php artisan sheets:sync`.
    | Scheduler-i i Laravel-it duhet thirrur cdo minute nga cron.
    */
    'schedule_time' => env('SHEETS_SYNC_TIME', '02:00'),

/*
    |--------------------------------------------------------------------------
    | Insert chunk size
    |--------------------------------------------------------------------------
    | Maksimumi i rreshtave per nje INSERT. Ulet vete nese tabela ka shume kolona,
    | qe te mos kalojme limitin e parametrave te databazes.
    */
    'chunk_size' => 500,

/*
    |--------------------------------------------------------------------------
    | Protected tables
    |--------------------------------------------------------------------------
    | Tabelat qe sync-u s'mund t'i preke asnjehere (krijoje, ndryshoje apo shkruaje),
    | edhe nese config-u thote ndryshe. Te gjitha qe fillojne me "sync_" jane te mbrojtura.
    */
    'protected_tables' => [
        'users',
        'password_reset_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'migrations',
    ],

/*
    |--------------------------------------------------------------------------
    | Admin UI credentials (HTTP Basic)
    |--------------------------------------------------------------------------
    | Admin UI nuk hapet jashte environment-eve local/testing nqs nuk vendos psw & username.
    */
    'admin' => [
        'user' => env('SHEETS_ADMIN_USER', 'admin'),
        'password' => env('SHEETS_ADMIN_PASSWORD'),
    ],

];