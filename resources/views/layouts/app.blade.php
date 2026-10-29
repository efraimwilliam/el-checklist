<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1b1511">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @vite(['resources/css/app.css', 'resources/js/date-picker.js'])
</head>
<body class="min-h-screen bg-white text-slate-800 antialiased">
@auth
    @php($navLink = fn (bool $active) => 'whitespace-nowrap border-b-2 py-2.5 transition '.($active ? 'border-gold-500 font-semibold text-gold-400' : 'border-transparent text-white/70 hover:text-white'))
    <header class="bg-ink text-white print:hidden">
        <div class="gold-rule h-[3px]"></div>
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 pt-3">
                <a href="{{ route('home') }}" class="flex items-center gap-3">
                    <img src="{{ asset('images/logo-el.png') }}" alt="éL" class="h-8 w-auto">
                    <span class="h-8 w-px bg-white/20"></span>
                    <span class="leading-tight">
                        <span class="block text-base font-semibold text-gold-400">éL Hotel &amp; Resort Bali Sanur</span>
                        <span class="block text-[11px] uppercase tracking-[0.12em] text-white/60">Daily Department Checklist</span>
                    </span>
                </a>
                <div class="flex items-center gap-3 text-[13px] text-white/70">
                    <span class="hidden sm:inline">{{ auth()->user()->name() }} · {{ auth()->user()->role->label() }}@if (auth()->user()->employee?->department) · {{ auth()->user()->employee->department->name }}@endif</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="rounded-full bg-white/10 px-3.5 py-1.5 text-[13px] font-semibold text-white transition hover:bg-white/20">Keluar</button>
                    </form>
                </div>
            </div>
            <nav class="mt-2 flex gap-6 overflow-x-auto text-sm">
                @can('view-checklists')
                    <a href="{{ route('dashboard') }}" class="{{ $navLink(request()->routeIs('dashboard')) }}">Dashboard</a>
                @endcan
                @can('fill-checklists')
                    <a href="{{ route('fill.create') }}" class="{{ $navLink(request()->routeIs('fill.*', 'checklists.today')) }}">{{ auth()->user()->role === \App\Enums\Role::Staff ? 'Checklist saya' : 'Isi checklist' }}</a>
                @endcan
                @can('view-checklists')
                    <a href="{{ route('checklists.index') }}" class="{{ $navLink(request()->routeIs('checklists.index', 'checklists.show')) }}">Riwayat</a>
                    <a href="{{ route('laporan.index') }}" class="{{ $navLink(request()->routeIs('laporan.*')) }}">Laporan</a>
                @endcan
                @can('manage-team-tasks')
                    <a href="{{ route('team.index') }}" class="{{ $navLink(request()->routeIs('team.index', 'team.tasks.*', 'team.positions.*')) }}">Atur tugas</a>
                    <a href="{{ route('team.catalog') }}" class="{{ $navLink(request()->routeIs('team.catalog')) }}">Daftar tugas</a>
                @endcan
                @can('manage-tasks')
                    <a href="{{ route('admin.tasks.index') }}" class="{{ $navLink(request()->routeIs('admin.tasks.*', 'admin.positions.*')) }}">Kelola tugas</a>
                @endcan
                @can('manage-access')
                    <a href="{{ route('admin.access.index') }}" class="{{ $navLink(request()->routeIs('admin.access.*', 'admin.sync')) }}">Akses pengguna</a>
                @endcan
                @canany(['manage-access', 'manage-team-tasks'])
                    <a href="{{ route('admin.settings.edit') }}" class="{{ $navLink(request()->routeIs('admin.settings.*')) }}">Pengaturan</a>
                @endcanany
            </nav>
        </div>
    </header>
@endauth
<main class="mx-auto max-w-6xl px-4 py-6 sm:px-6">
    @if (session('status'))
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    {{ $slot }}
</main>
</body>
</html>
