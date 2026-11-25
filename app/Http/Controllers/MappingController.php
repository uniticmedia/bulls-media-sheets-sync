<?php

namespace App\Http\Controllers;

use App\Http\Requests\MappingRequest;
use App\Models\SyncMapping;
use App\Models\SyncMappingColumn;
use App\Models\SyncRun;
use App\Models\SyncSource;
use App\Services\Sync\SyncService;
use App\Services\Sync\ValueCaster;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class MappingController extends Controller
{
    public function create(SyncSource $source): View
    {
        return $this->form($source, new SyncMapping(['header_row' => 1, 'is_active' => true]));
    }

    public function store(MappingRequest $request, SyncSource $source): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($source, $data) {
            $mapping = $source->mappings()->create(Arr::except($data, ['columns']));
            $this->saveColumns($mapping, $data['columns']);
        });

        return redirect()->route('sources.show', $source)
            ->with('status', 'Mapping created. The table is created or extended on the next sync.');
    }

    public function edit(SyncMapping $mapping): View
    {
        return $this->form($mapping->source, $mapping);
    }

    public function update(MappingRequest $request, SyncMapping $mapping): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($mapping, $data) {
            $mapping->update(Arr::except($data, ['columns']));
            $this->saveColumns($mapping, $data['columns']);
        });

        return redirect()->route('sources.show', $mapping->source_id)
            ->with('status', 'Mapping updated. New columns are added to the table on the next sync.');
    }

    public function destroy(SyncMapping $mapping): RedirectResponse
    {
        $sourceId = $mapping->source_id;
        $mapping->delete();

        return redirect()->route('sources.show', $sourceId)
            ->with('status', 'Mapping deleted. Data already stored in the target table was kept.');
    }

    public function run(SyncMapping $mapping, SyncService $sync): RedirectResponse
    {
        @set_time_limit(300);

        [$message, $level] = SyncRun::summarize([$sync->runMapping($mapping)]);

        return back()->with('status', $message)->with('status_level', $level);
    }

    private function form(SyncSource $source, SyncMapping $mapping): View
    {
        return view('mappings.form', [
            'source' => $source,
            'mapping' => $mapping,
            'columns' => $this->columnsForForm($mapping),
            'types' => ValueCaster::TYPES,
        ]);
    }

    /**
     * @return array<int, array{source_column: string, target_column: string, data_type: string}>
     */
    private function columnsForForm(SyncMapping $mapping): array
    {
        $old = old('columns');

        if (is_array($old) && $old !== []) {
            return array_values($old);
        }

        if ($mapping->exists && $mapping->columns()->exists()) {
            return $mapping->columns->map(fn (SyncMappingColumn $column) => $column->only(['source_column', 'target_column', 'data_type']))->all();
        }

        return [['source_column' => '', 'target_column' => '', 'data_type' => 'string']];
    }

    /**
     * @param  array<int, array{source_column: string, target_column: string, data_type: string}>  $columns
     */
    private function saveColumns(SyncMapping $mapping, array $columns): void
    {
        SyncMappingColumn::where('mapping_id', $mapping->id)->delete();

        foreach ($columns as $column) {
            $mapping->columns()->create(Arr::only($column, ['source_column', 'target_column', 'data_type']));
        }
    }
}