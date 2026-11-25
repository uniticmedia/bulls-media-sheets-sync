@extends('layouts.app')

@section('title', 'Dashboard - Sheets Sync')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Dashboard</h1>
        <form method="POST" action="{{ route('runs.run-all') }}" onsubmit="return confirm('Run all active mappings now?');">
            @csrf
            <button class="btn btn-primary">Run all now</button>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm h-100"><div class="card-body">
                <div class="text-muted small">Spreadsheet files</div>
                <div class="display-6">{{ $sourceCount }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm h-100"><div class="card-body">
                <div class="text-muted small">Mappings (tab &rarr; table)</div>
                <div class="display-6">{{ $mappingCount }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm h-100"><div class="card-body">
                <div class="text-muted small">Failed runs, last 24h</div>
                <div class="display-6 {{ $failedLastDay > 0 ? 'text-danger' : '' }}">{{ $failedLastDay }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm h-100"><div class="card-body">
                <div class="text-muted small">Automatic daily run</div>
                <div class="display-6">{{ $scheduleTime }}</div>
                <div class="text-muted small">server time</div>
            </div></div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>Latest runs</strong>
            <a href="{{ route('runs.index') }}" class="small">All runs</a>
        </div>
        <div class="card-body p-0">
            @include('runs._table', ['runs' => $runs])
        </div>
    </div>
@endsection