<?php

namespace App\Http\Controllers;

use App\Models\SyncRun;
use App\Services\Sync\SyncService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RunController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');

        $runs = SyncRun::with('mapping.source')
            ->when(in_array($status, [SyncRun::SUCCESS, SyncRun::WARNING, SyncRun::FAILED, SyncRun::RUNNING], true),
                fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('runs.index', ['runs' => $runs, 'status' => $status]);
    }

    public function runAll(SyncService $sync): RedirectResponse
    {
        @set_time_limit(600);

        [$message, $level] = SyncRun::summarize($sync->syncAll());

        return back()->with('status', $message)->with('status_level', $level);
    }
}