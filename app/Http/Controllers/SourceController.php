<?php

namespace App\Http\Controllers;

use App\Http\Requests\SourceRequest;
use App\Models\SyncRun;
use App\Models\SyncSource;
use App\Services\Sync\SyncService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class SourceController extends Controller
{
    public function index(): View
    {
        return view('sources.index', [
            'sources' => SyncSource::withCount('mappings')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('sources.form', ['source' => new SyncSource(['is_active' => true])]);
    }

    public function store(SourceRequest $request): RedirectResponse
    {
        $source = SyncSource::create($request->validated());

        return redirect()->route('sources.show', $source)
            ->with('status', 'Source created. Now add a mapping (tab -> table).');
    }

    public function show(SyncSource $source): View
    {
        $source->load(['mappings.columns', 'mappings.latestRun']);

        return view('sources.show', ['source' => $source]);
    }

    public function edit(SyncSource $source): View
    {
        return view('sources.form', ['source' => $source]);
    }

    public function update(SourceRequest $request, SyncSource $source): RedirectResponse
    {
        $source->update($request->validated());

        return redirect()->route('sources.show', $source)->with('status', 'Source updated.');
    }

    public function destroy(SyncSource $source): RedirectResponse
    {
        $source->delete();

        return redirect()->route('sources.index')
            ->with('status', 'Source deleted. Data already stored in the target tables was kept.');
    }

    public function run(SyncSource $source, SyncService $sync): RedirectResponse
    {
        @set_time_limit(300);

        [$message, $level] = SyncRun::summarize($sync->syncSource($source));

        return back()->with('status', $message)->with('status_level', $level);
    }
}
