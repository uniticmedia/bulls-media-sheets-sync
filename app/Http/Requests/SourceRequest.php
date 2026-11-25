<?php

namespace App\Http\Requests;

use App\Models\SyncSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is controlled by the AdminBasicAuth middleware
    }

    protected function prepareForValidation(): void
    {
        $spreadsheetId = trim((string) $this->input('spreadsheet_id'));

        // Accept a pasted URL as well as a bare ID.
        if (preg_match('#/spreadsheets/d/([A-Za-z0-9_-]+)#', $spreadsheetId, $matches)) {
            $spreadsheetId = $matches[1];
        }

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'spreadsheet_id' => $spreadsheetId,
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->route('source');

        return [
            'name' => ['required', 'string', 'max:120'],
            'spreadsheet_id' => [
                'required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('sync_sources', 'spreadsheet_id')->ignore($current instanceof SyncSource ? $current->id : null),
            ],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'spreadsheet_id.regex' => 'The spreadsheet ID may only contain letters, digits, "-" and "_". You can also paste the full spreadsheet URL.',
            'spreadsheet_id.unique' => 'This spreadsheet is already configured.',
        ];
    }
}