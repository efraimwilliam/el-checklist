<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
@auth
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
            <a href="{{ route('home') }}" class="font-semibold text-sky-800">{{ config('app.name') }}</a>
            <nav class="flex flex-1 flex-wrap gap-4 text-sm">
                <a href="{{ route('checklists.today') }}" class="hover:text-sky-700 {{ request()->routeIs('checklists.today') ? 'font-semibold text-sky-700' : '' }}">Checklist saya</a>
                @can('review-checklists')
                    <a href="{{ route('checklists.index') }}" class="hover:text-sky-700 {{ request()->routeIs('checklists.index') ? 'font-semibold text-sky-700' : '' }}">Review</a>
                @endcan
                @can('manage-tasks')
                    <a href="{{ route('admin.tasks.index') }}" class="hover:text-sky-700 {{ request()->routeIs('admin.tasks.*', 'admin.positions.*') ? 'font-semibold text-sky-700' : '' }}">Kelola tugas</a>
                @endcan
                @can('manage-access')
                    <a href="{{ route('admin.access.index') }}" class="hover:text-sky-700 {{ request()->routeIs('admin.access.*', 'admin.sync') ? 'font-semibold text-sky-700' : '' }}">Akses pengguna</a>
                @endcan
            </nav>
            <div class="flex items-center gap-3 text-sm">
                <span class="text-slate-500">{{ auth()->user()->name() }} · {{ auth()->user()->role->label() }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="text-slate-600 hover:text-rose-700">Keluar</button>
                </form>
            </div>
        </div>
    </header>
@endauth
<main class="mx-auto max-w-5xl px-4 py-6">
    @if (session('status'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    {{ $slot }}
</main>
</body>
</html>
