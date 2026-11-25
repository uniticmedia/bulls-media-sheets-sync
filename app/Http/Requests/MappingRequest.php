<?php

namespace App\Http\Requests;

use App\Models\SyncMapping;
use App\Models\SyncSource;
use App\Services\Sync\SchemaGuard;
use App\Services\Sync\ValueCaster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class MappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is controlled by the AdminBasicAuth middleware
    }

    protected function prepareForValidation(): void
    {
        $columns = collect($this->input('columns', []))
            ->filter(fn ($column) => is_array($column)
                && (trim((string) ($column['source_column'] ?? '')) !== '' || trim((string) ($column['target_column'] ?? '')) !== ''))
            ->map(fn (array $column) => [
                'source_column' => trim((string) ($column['source_column'] ?? '')),
                'target_column' => strtolower(trim((string) ($column['target_column'] ?? ''))),
                'data_type' => (string) ($column['data_type'] ?? 'string'),
            ])
            ->values()
            ->all();

        $this->merge([
            'tab_name' => trim((string) $this->input('tab_name')),
            'target_table' => strtolower(trim((string) $this->input('target_table'))),
            'is_active' => $this->boolean('is_active'),
            'columns' => $columns,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tab_name' => ['required', 'string', 'max:100'],
            'target_table' => [
                'required', 'string',
                function (string $attribute, mixed $value, \Closure $fail) {
                    try {
                        SchemaGuard::assertTable((string) $value, (array) config('sheets-sync.protected_tables', []));
                    } catch (InvalidArgumentException $e) {
                        $fail($e->getMessage());
                    }
                },
                Rule::unique('sync_mappings', 'target_table')
                    ->where(fn ($query) => $query
                        ->where('source_id', $this->sourceId())
                        ->where('tab_name', $this->input('tab_name')))
                    ->ignore($this->currentMapping()?->id),
            ],
            'header_row' => ['required', 'integer', 'min:1', 'max:200'],
            'is_active' => ['boolean'],
            'columns' => ['required', 'array', 'min:1', 'max:100'],
            'columns.*.source_column' => ['required', 'string', 'max:190'],
            'columns.*.target_column' => [
                'required', 'string', 'distinct',
                function (string $attribute, mixed $value, \Closure $fail) {
                    try {
                        SchemaGuard::assertColumn((string) $value);
                    } catch (InvalidArgumentException $e) {
                        $fail($e->getMessage());
                    }
                },
            ],
            'columns.*.data_type' => ['required', Rule::in(ValueCaster::TYPES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_table.unique' => 'This tab is already mapped to that table for this source.',
            'columns.required' => 'Add at least one column to the mapping.',
            'columns.*.target_column.distinct' => 'Each database column can only be used once.',
        ];
    }

    private function currentMapping(): ?SyncMapping
    {
        $mapping = $this->route('mapping');

        return $mapping instanceof SyncMapping ? $mapping : null;
    }

    private function sourceId(): ?int
    {
        $source = $this->route('source');

        return $source instanceof SyncSource ? $source->id : $this->currentMapping()?->source_id;
    }
}