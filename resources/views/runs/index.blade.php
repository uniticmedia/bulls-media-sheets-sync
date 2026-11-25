@extends('layouts.app')

@section('title', 'Run history - Sheets Sync')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Run history</h1>
        <div class="btn-group btn-group-sm" role="group" aria-label="Filter by status">
            <a class="btn btn-outline-secondary {{ $status ? '' : 'active' }}" href="{{ route('runs.index') }}">All</a>
            @foreach (['success', 'warning', 'failed'] as $filter)
                <a class="btn btn-outline-secondary {{ $status === $filter ? 'active' : '' }}"
                   href="{{ route('runs.index', ['status' => $filter]) }}">{{ ucfirst($filter) }}</a>
            @endforeach
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body p-0">
            @include('runs._table', ['runs' => $runs])
        </div>
    </div>

    {{ $runs->links() }}
@endsection