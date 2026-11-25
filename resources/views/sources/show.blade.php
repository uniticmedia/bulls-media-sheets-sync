@extends('layouts.app')

@section('title', $source->name.' - Sheets Sync')

@php
    $badges = ['success' => 'success', 'warning' => 'warning text-dark', 'failed' => 'danger', 'running' => 'secondary'];
@endphp

@section('content')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('sources.index') }}">Sources</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $source->name }}</li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">{{ $source->name }}
                @unless ($source->is_active)
                    <span class="badge text-bg-secondary fs-6 align-middle">paused</span>
                @endunless
            </h1>
            <a class="mono" href="https://docs.google.com/spreadsheets/d/{{ $source->spreadsheet_id }}" target="_blank" rel="noopener noreferrer">{{ $source->spreadsheet_id }}</a>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('sources.edit', $source) }}" class="btn btn-outline-secondary">Edit file</a>
            <form method="POST" action="{{ route('sources.run', $source) }}">
                @csrf
                <button class="btn btn-primary">Run this file now</button>
            </form>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h5 mb-0">Mappings</h2>
        <a href="{{ route('sources.mappings.create', $source) }}" class="btn btn-sm btn-success">Add mapping</a>
    </div>

    @forelse ($source->mappings as $mapping)
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-semibold">
                            Tab <span class="mono">{{ $mapping->tab_name }}</span>
                            &rarr; table <span class="mono">{{ $mapping->target_table }}</span>
                            @unless ($mapping->is_active)
                                <span class="badge text-bg-secondary">paused</span>
                            @endunless
                        </div>
                        <div class="text-muted small">
                            Header in row {{ $mapping->header_row }} &middot; {{ $mapping->columns->count() }} column(s)
                            @if ($mapping->latestRun)
                                &middot; last run
                                <span class="badge text-bg-{{ $badges[$mapping->latestRun->status] ?? 'secondary' }}">{{ $mapping->latestRun->status }}</span>
                                {{ $mapping->latestRun->started_at?->diffForHumans() }}
                                ({{ $mapping->latestRun->rows_written }} rows)
                            @else
                                &middot; never run
                            @endif
                        </div>
                        @if ($mapping->latestRun?->message)
                            <div class="small mt-1 {{ $mapping->latestRun->status === 'failed' ? 'text-danger' : 'text-muted' }}">
                                {{ $mapping->latestRun->message }}
                            </div>
                        @endif
                    </div>
                    <div class="d-flex gap-2">
                        <form method="POST" action="{{ route('mappings.run', $mapping) }}">
                            @csrf
                            <button class="btn btn-sm btn-outline-primary">Run</button>
                        </form>
                        <a href="{{ route('mappings.edit', $mapping) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                        <form method="POST" action="{{ route('mappings.destroy', $mapping) }}"
                              onsubmit="return confirm('Delete this mapping? Data already stored in the table is kept.');">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </div>
                </div>

                <details class="mt-2">
                    <summary class="small text-muted">Column mapping</summary>
                    <table class="table table-sm mt-2 mb-0 w-auto">
                        <thead><tr><th>Sheet column</th><th></th><th>Database column</th><th>Type</th></tr></thead>
                        <tbody>
                        @foreach ($mapping->columns as $column)
                            <tr>
                                <td class="mono">{{ $column->source_column }}</td>
                                <td>&rarr;</td>
                                <td class="mono">{{ $column->target_column }}</td>
                                <td class="text-muted">{{ $column->data_type }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </details>
            </div>
        </div>
    @empty
        <div class="alert alert-info">
            No mappings yet. A mapping says which <strong>tab</strong> to read, which <strong>columns</strong> to take
            and which <strong>database table</strong> to store them in.
        </div>
    @endforelse
@endsection