@extends('layouts.app')

@section('title', 'Sources - Sheets Sync')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Spreadsheet files</h1>
        <a href="{{ route('sources.create') }}" class="btn btn-primary">Add file</a>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Spreadsheet ID</th>
                    <th class="text-center">Mappings</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($sources as $source)
                    <tr>
                        <td><a class="fw-semibold text-decoration-none" href="{{ route('sources.show', $source) }}">{{ $source->name }}</a></td>
                        <td class="mono">
                            <a href="https://docs.google.com/spreadsheets/d/{{ $source->spreadsheet_id }}" target="_blank" rel="noopener noreferrer">{{ \Illuminate\Support\Str::limit($source->spreadsheet_id, 28) }}</a>
                        </td>
                        <td class="text-center">{{ $source->mappings_count }}</td>
                        <td>
                            @if ($source->is_active)
                                <span class="badge text-bg-success">active</span>
                            @else
                                <span class="badge text-bg-secondary">paused</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('sources.show', $source) }}" class="btn btn-sm btn-outline-secondary">Open</a>
                            <a href="{{ route('sources.edit', $source) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <form method="POST" action="{{ route('sources.run', $source) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-outline-primary">Run</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No files yet. Click <strong>Add file</strong> to configure the first one.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection