<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Sheets Sync')</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        body { background: #f6f7f9; }
        .navbar-brand small { font-weight: 400; opacity: .7; }
        .table > :not(caption) > * > * { vertical-align: middle; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .85em; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="{{ route('dashboard') }}">Sheets Sync <small>Google Sheets &rarr; MySQL</small></a>
        <ul class="navbar-nav me-auto">
            <li class="nav-item"><a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">Dashboard</a></li>
            <li class="nav-item"><a class="nav-link {{ request()->routeIs('sources.*', 'mappings.*') ? 'active' : '' }}" href="{{ route('sources.index') }}">Sources</a></li>
            <li class="nav-item"><a class="nav-link {{ request()->routeIs('runs.*') ? 'active' : '' }}" href="{{ route('runs.index') }}">Run history</a></li>
        </ul>
    </div>
</nav>

<main class="container pb-5">
    @if (session('status'))
        <div class="alert alert-{{ session('status_level', 'success') }} alert-dismissible fade show" role="alert">
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <strong>Please fix the following:</strong>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>