<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1b1511">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <script>
        window.__i18n = @json(\App\Support\Translations::forJs());
        window.t = (text) => (window.__i18n && window.__i18n[text]) || text;
    </script>
    @vite(['resources/css/app.css', 'resources/js/date-picker.js', 'resources/js/photo-viewer.js'])
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
                        <span class="block text-[11px] uppercase tracking-[0.12em] text-white/60">{{ __('Daily Department Checklist') }}</span>
                    </span>
                </a>
                <div class="flex items-center gap-3 text-[13px] text-white/70">
                    <x-language-switch />
                    <span class="hidden sm:inline">{{ auth()->user()->name() }} · {{ auth()->user()->role->label() }}@if (auth()->user()->employee?->department) · {{ auth()->user()->employee->department->name }}@endif</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="rounded-full bg-white/10 px-3.5 py-1.5 text-[13px] font-semibold text-white transition hover:bg-white/20">{{ __('Keluar') }}</button>
                    </form>
                </div>
            </div>
            <nav class="mt-2 flex gap-6 overflow-x-auto text-sm">
                @can('view-checklists')
                    <a href="{{ route('dashboard') }}" class="{{ $navLink(request()->routeIs('dashboard')) }}">{{ __('Dashboard') }}</a>
                @endcan
                @can('fill-checklists')
                    <a href="{{ route('fill.create') }}" class="{{ $navLink(request()->routeIs('fill.*', 'checklists.today')) }}">{{ auth()->user()->role === \App\Enums\Role::Staff ? __('Checklist saya') : __('Isi checklist') }}</a>
                @endcan
                @can('view-checklists')
                    <a href="{{ route('checklists.index') }}" class="{{ $navLink(request()->routeIs('checklists.index', 'checklists.show')) }}">{{ __('Riwayat') }}</a>
                    <a href="{{ route('laporan.index') }}" class="{{ $navLink(request()->routeIs('laporan.*')) }}">{{ __('Laporan') }}</a>
                @endcan
                @can('manage-team-tasks')
                    <a href="{{ route('team.index') }}" class="{{ $navLink(request()->routeIs('team.index', 'team.tasks.*', 'team.positions.*')) }}">{{ __('Atur tugas') }}</a>
                    <a href="{{ route('team.catalog') }}" class="{{ $navLink(request()->routeIs('team.catalog')) }}">{{ __('Daftar tugas') }}</a>
                @endcan
                @can('manage-tasks')
                    <a href="{{ route('admin.tasks.index') }}" class="{{ $navLink(request()->routeIs('admin.tasks.*', 'admin.positions.*')) }}">{{ __('Kelola tugas') }}</a>
                @endcan
                @can('manage-access')
                    <a href="{{ route('admin.access.index') }}" class="{{ $navLink(request()->routeIs('admin.access.*', 'admin.sync')) }}">{{ __('Akses pengguna') }}</a>
                @endcan
                @canany(['manage-access', 'manage-team-tasks'])
                    <a href="{{ route('admin.settings.edit') }}" class="{{ $navLink(request()->routeIs('admin.settings.*')) }}">{{ __('Pengaturan') }}</a>
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

<dialog id="photo-viewer" class="m-auto max-h-[90vh] max-w-[min(56rem,calc(100vw-2rem))] bg-transparent p-0 backdrop:bg-[#1b1511]/80">
    <div class="relative">
        <img data-photo-viewer-image alt="" class="max-h-[85vh] w-auto rounded-2xl bg-white object-contain shadow-2xl">
        <button type="button" data-close-photo class="absolute right-3 top-3 inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/95 text-2xl leading-none text-slate-800 shadow hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500" aria-label="{{ __('Tutup foto') }}">{{ __('×') }}</button>
    </div>
</dialog>
</body>
</html>
