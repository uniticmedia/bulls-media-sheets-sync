<?php

namespace App\Http\Controllers;

use App\Models\SyncMapping;
use App\Models\SyncRun;
use App\Models\SyncSource;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('dashboard', [
            'sourceCount' => SyncSource::count(),
            'mappingCount' => SyncMapping::count(),
            'failedLastDay' => SyncRun::where('status', SyncRun::FAILED)->where('created_at', '>=', now()->subDay())->count(),
            'runs' => SyncRun::with('mapping.source')->latest('id')->limit(10)->get(),
            'scheduleTime' => config('sheets-sync.schedule_time'),
        ]);
    }
}