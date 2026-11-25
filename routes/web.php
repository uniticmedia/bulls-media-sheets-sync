<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MappingController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\SourceController;
use App\Http\Middleware\AdminBasicAuth;
use Illuminate\Support\Facades\Route;

Route::middleware(AdminBasicAuth::class)->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    // Spreadsheet files
    Route::resource('sources', SourceController::class);
    Route::post('sources/{source}/run', [SourceController::class, 'run'])->name('sources.run');

    // Tab -> table mappings (listed on the source page, so no index/show routes)
    Route::resource('sources.mappings', MappingController::class)->shallow()->except(['index', 'show']);
    Route::post('mappings/{mapping}/run', [MappingController::class, 'run'])->name('mappings.run');

    // Run history
    Route::get('runs', [RunController::class, 'index'])->name('runs.index');
    Route::post('runs/run-all', [RunController::class, 'runAll'])->name('runs.run-all');
});