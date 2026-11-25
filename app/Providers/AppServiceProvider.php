<?php

namespace App\Providers;

use App\Services\Sheets\GoogleSheetsClient;
use App\Services\Sheets\ServiceAccountToken;
use App\Services\Sheets\SheetsClient;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SheetsClient::class, function () {
            return new GoogleSheetsClient(
                new ServiceAccountToken((string) config('sheets-sync.credentials_path'))
            );
        });
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();
    }
}