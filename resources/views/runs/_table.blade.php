@php
    $badges = ['success' => 'success', 'warning' => 'warning text-dark', 'failed' => 'danger', 'running' => 'secondary'];
@endphp

<div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
        <tr>
            <th>#</th>
            <th>Source / tab</th>
            <th>Target table</th>
            <th>Status</th>
            <th class="text-end">Read</th>
            <th class="text-end">Written</th>
            <th class="text-end">Skipped</th>
            <th>Started</th>
            <th>Details</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($runs as $run)
            <tr>
                <td class="text-muted">{{ $run->id }}</td>
                <td>
                    @if ($run->mapping)
                        <a href="{{ route('sources.show', $run->mapping->source_id) }}">{{ $run->mapping->source->name }}</a>
                        <span class="text-muted">/ {{ $run->mapping->tab_name }}</span>
                    @else
                        <span class="text-muted">(deleted mapping)</span>
                    @endif
                </td>
                <td class="mono">{{ $run->mapping?->target_table }}</td>
                <td><span class="badge text-bg-{{ $badges[$run->status] ?? 'secondary' }}">{{ $run->status }}</span></td>
                <td class="text-end">{{ $run->rows_read }}</td>
                <td class="text-end">{{ $run->rows_written }}</td>
                <td class="text-end">{{ $run->rows_skipped }}</td>
                <td class="text-nowrap">{{ $run->started_at?->format('Y-m-d H:i:s') }}</td>
                <td class="small">
                    @if ($run->columns_added)
                        <div class="text-success">New columns: <span class="mono">{{ implode(', ', $run->columns_added) }}</span></div>
                    @endif
                    @if ($run->message)
                        <div class="{{ $run->status === 'failed' ? 'text-danger' : 'text-muted' }}">{{ $run->message }}</div>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-center text-muted py-4">No runs yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>