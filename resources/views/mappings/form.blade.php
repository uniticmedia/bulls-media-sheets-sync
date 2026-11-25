@extends('layouts.app')

@section('title', ($mapping->exists ? 'Edit mapping' : 'Add mapping').' - Sheets Sync')

@section('content')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('sources.index') }}">Sources</a></li>
            <li class="breadcrumb-item"><a href="{{ route('sources.show', $source) }}">{{ $source->name }}</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $mapping->exists ? 'Edit mapping' : 'Add mapping' }}</li>
        </ol>
    </nav>

    <h1 class="h3 mb-3">{{ $mapping->exists ? 'Edit mapping' : 'Add mapping' }}</h1>

    <form method="POST"
          action="{{ $mapping->exists ? route('mappings.update', $mapping) : route('sources.mappings.store', $source) }}">
        @csrf
        @if ($mapping->exists)
            @method('PUT')
        @endif

        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="tab_name" class="form-label">Tab name</label>
                        <input type="text" id="tab_name" name="tab_name" class="form-control @error('tab_name') is-invalid @enderror"
                               value="{{ old('tab_name', $mapping->tab_name) }}" maxlength="100" required>
                        <div class="form-text">Exactly as shown on the tab at the bottom of the sheet.</div>
                        @error('tab_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label for="target_table" class="form-label">Database table</label>
                        <input type="text" id="target_table" name="target_table" class="form-control mono @error('target_table') is-invalid @enderror"
                               value="{{ old('target_table', $mapping->target_table) }}" maxlength="40" placeholder="e.g. sales"
                               pattern="[a-z][a-z0-9_]*" required>
                        <div class="form-text">Created automatically if it does not exist.</div>
                        @error('target_table') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2">
                        <label for="header_row" class="form-label">Header row</label>
                        <input type="number" id="header_row" name="header_row" class="form-control @error('header_row') is-invalid @enderror"
                               value="{{ old('header_row', $mapping->header_row) }}" min="1" max="200" required>
                        @error('header_row') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
                                   @checked(old('is_active', $mapping->is_active))>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white">
                <strong>Columns</strong>
                <div class="text-muted small">
                    Sheet columns are matched by their <em>header name</em> (case and punctuation are ignored), so their
                    position in the file does not matter. A database column that does not exist yet is added automatically.
                </div>
            </div>
            <div class="card-body">
                <table class="table table-sm align-middle mb-2">
                    <thead>
                    <tr>
                        <th style="width:35%">Sheet column (header)</th>
                        <th style="width:35%">Database column</th>
                        <th style="width:22%">Type</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody id="columns-body">
                    @foreach ($columns as $i => $column)
                        @include('mappings._column_row', ['i' => $i, 'column' => $column, 'types' => $types])
                    @endforeach
                    </tbody>
                </table>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="add-column">+ Add column</button>
            </div>
        </div>

        <button class="btn btn-primary">{{ $mapping->exists ? 'Save mapping' : 'Create mapping' }}</button>
        <a href="{{ route('sources.show', $source) }}" class="btn btn-link">Cancel</a>
    </form>

    <template id="column-row-template">
        @include('mappings._column_row', [
            'i' => '__INDEX__',
            'column' => ['source_column' => '', 'target_column' => '', 'data_type' => 'string'],
            'types' => $types,
        ])
    </template>
@endsection

@push('scripts')
    <script>
        (function () {
            var body = document.getElementById('columns-body');
            var template = document.getElementById('column-row-template');
            var next = body.querySelectorAll('tr').length;

            document.getElementById('add-column').addEventListener('click', function () {
                body.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(next++)));
            });

            body.addEventListener('click', function (event) {
                var button = event.target.closest('.remove-column');
                if (button && body.querySelectorAll('tr').length > 1) {
                    button.closest('tr').remove();
                }
            });
        })();
    </script>
@endpush