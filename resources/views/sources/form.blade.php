@extends('layouts.app')

@section('title', ($source->exists ? 'Edit file' : 'Add file').' - Sheets Sync')

@section('content')
    <h1 class="h3 mb-3">{{ $source->exists ? 'Edit file' : 'Add spreadsheet file' }}</h1>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ $source->exists ? route('sources.update', $source) : route('sources.store') }}">
                @csrf
                @if ($source->exists)
                    @method('PUT')
                @endif

                <div class="mb-3">
                    <label for="name" class="form-label">Name</label>
                    <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
                           value="{{ old('name', $source->name) }}" maxlength="120" required autofocus>
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="mb-3">
                    <label for="spreadsheet_id" class="form-label">Spreadsheet ID or URL</label>
                    <input type="text" id="spreadsheet_id" name="spreadsheet_id"
                           class="form-control mono @error('spreadsheet_id') is-invalid @enderror"
                           value="{{ old('spreadsheet_id', $source->spreadsheet_id) }}" required>
                    <div class="form-text">
                        The long part of the URL: docs.google.com/spreadsheets/d/<strong>&lt;this part&gt;</strong>/edit.
                        Remember to share the file with the service account's e-mail address.
                    </div>
                    @error('spreadsheet_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="form-check form-switch mb-4">
                    <input type="hidden" name="is_active" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
                           @checked(old('is_active', $source->is_active))>
                    <label class="form-check-label" for="is_active">Include in the daily sync</label>
                </div>

                <button class="btn btn-primary">{{ $source->exists ? 'Save changes' : 'Create file' }}</button>
                <a href="{{ $source->exists ? route('sources.show', $source) : route('sources.index') }}" class="btn btn-link">Cancel</a>
            </form>
        </div>
    </div>

    @if ($source->exists)
        <form method="POST" action="{{ route('sources.destroy', $source) }}" class="mt-4"
              onsubmit="return confirm('Delete this file and all its mappings? Data already stored in the database tables is kept.');">
            @csrf
            @method('DELETE')
            <button class="btn btn-sm btn-outline-danger">Delete this file</button>
        </form>
    @endif
@endsection