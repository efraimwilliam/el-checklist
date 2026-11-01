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
    @php($me = auth()->user())
    @php($initials = collect(explode(' ', $me->name()))->filter()->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode(''))
    @php($navLink = fn (bool $active) => 'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-t-xl px-3.5 py-2.5 transition '.($active ? 'bg-white/[0.07] font-semibold text-gold-400 shadow-[inset_0_-3px_0_#c9a24f]' : 'text-white/70 hover:bg-white/[0.05] hover:text-white'))
    @php($menuItem = fn (bool $active) => 'flex items-center justify-between gap-6 rounded-lg px-2.5 py-2 text-slate-800 hover:bg-amber-50 '.($active ? 'bg-amber-50 font-semibold' : ''))
    @php($teamActive = request()->routeIs('team.*', 'admin.tasks.*', 'admin.positions.*'))
    @php($accessActive = request()->routeIs('admin.access.*', 'admin.sync', 'admin.settings.*'))
    <header class="bg-ink text-white print:hidden">
        <div class="gold-rule h-[3px]"></div>
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 pt-3 sm:px-6">
            <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-3">
                <img src="{{ asset('images/logo-el.png') }}" alt="éL" class="h-8 w-auto shrink-0">
                <span class="hidden h-8 w-px shrink-0 bg-white/20 sm:block"></span>
                <span class="min-w-0 leading-tight">
                    <span class="block truncate text-base font-semibold text-gold-400">éL Hotel &amp; Resort Bali Sanur</span>
                    <span class="block truncate text-[11px] uppercase tracking-[0.12em] text-white/60">{{ __('Daily Department Checklist') }}</span>
                </span>
            </a>
            <div class="flex shrink-0 items-center gap-2.5 sm:gap-3">
                {{-- Pegawai (Staff) tidak perlu ganti bahasa di header; HOD, HR, GM, dan Administrator tetap melihatnya. --}}
                @unless ($me->role === \App\Enums\Role::Staff)
                    <x-language-switch />
                @endunless
                <a href="{{ route('profile.edit') }}" title="{{ __('Profil saya') }}" class="flex items-center gap-2.5 rounded-full border border-white/10 bg-white/[0.06] py-1 pl-1 pr-3 transition hover:bg-white/10">
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-gradient-to-br from-gold-400 to-gold-600 text-xs font-bold text-[#2a1d12]" aria-hidden="true">{{ $initials }}</span>
                    <span class="hidden text-left leading-tight sm:block">
                        <span class="block text-[13px] font-semibold">{{ $me->name() }}</span>
                        <span class="block text-[11px] text-white/60">{{ $me->role->label() }}@if ($me->employee?->department) · {{ $me->employee->department->name }}@endif</span>
                    </span>
                </a>
                @if (\App\Support\PortalClient::viaPortal())
                    <a href="{{ \App\Support\PortalClient::homeUrl() }}" class="hidden rounded-full border border-white/10 px-3 py-1.5 text-xs text-white/70 transition hover:bg-white/10 hover:text-white sm:block">{{ __('Dashboard Utama') }}</a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" title="{{ __('Keluar') }}" class="grid h-9 w-9 place-items-center rounded-full border border-white/10 text-white/70 transition hover:bg-white/10 hover:text-white">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
                        <span class="sr-only">{{ __('Keluar') }}</span>
                    </button>
                </form>
            </div>
        </div>
        <div class="mt-3 border-t border-white/10 bg-[#251d17]">
            <nav class="mx-auto flex max-w-6xl flex-wrap items-stretch gap-x-1 px-2 text-sm sm:px-6" aria-label="{{ __('Menu utama') }}">
                @can('view-checklists')
                    <a href="{{ route('dashboard') }}" class="{{ $navLink(request()->routeIs('dashboard')) }}">{{ __('Dashboard') }}</a>
                @endcan
                @can('fill-checklists')
                    @if ($me->role === \App\Enums\Role::Staff)
                        <a href="{{ route('fill.create') }}" class="{{ $navLink(request()->routeIs('fill.*', 'checklists.today')) }}">{{ __('Checklist saya') }}</a>
                    @else
                        <div class="group relative flex shrink-0">
                            <button type="button" aria-haspopup="true" class="{{ $navLink(request()->routeIs('fill.*', 'checklists.today', 'checklists.tasks.*', 'checklists.fill')) }}">{{ __('Checklist') }} <span class="text-[10px] opacity-70" aria-hidden="true">▾</span></button>
                            <div class="invisible absolute left-0 top-full z-30 w-60 pt-1 opacity-0 transition group-hover:visible group-hover:opacity-100 group-focus-within:visible group-focus-within:opacity-100">
                                <div class="rounded-2xl bg-[#fffdf9] p-2 shadow-xl ring-1 ring-black/5">
                                    <a href="{{ route('fill.assign') }}" class="{{ $menuItem(request()->routeIs('fill.assign', 'checklists.tasks.*')) }}">{{ __('Atur checklist') }}<span class="text-slate-400">›</span></a>
                                    <a href="{{ route('fill.create') }}" class="{{ $menuItem(request()->routeIs('fill.create', 'checklists.today', 'checklists.fill')) }}">{{ __('Isi checklist') }}<span class="text-slate-400">›</span></a>
                                </div>
                            </div>
                        </div>
                    @endif
                @endcan
                <a href="{{ route('checklists.index') }}" class="{{ $navLink(request()->routeIs('checklists.index', 'checklists.show')) }}">{{ __('Riwayat') }}</a>
                @can('view-checklists')
                    <a href="{{ route('laporan.index') }}" class="{{ $navLink(request()->routeIs('laporan.*')) }}">{{ __('Laporan') }}</a>
                @endcan
                @canany(['manage-team-tasks', 'manage-tasks'])
                    <div class="group relative flex shrink-0">
                        <button type="button" aria-haspopup="true" class="{{ $navLink($teamActive) }}">{{ __('Tugas') }} <span class="text-[10px] opacity-70" aria-hidden="true">▾</span></button>
                        <div class="invisible absolute left-0 top-full z-30 w-60 pt-1 opacity-0 transition group-hover:visible group-hover:opacity-100 group-focus-within:visible group-focus-within:opacity-100">
                            <div class="rounded-2xl bg-[#fffdf9] p-2 shadow-xl ring-1 ring-black/5">
                                @can('manage-team-tasks')
                                    <a href="{{ route('team.index') }}" class="{{ $menuItem(request()->routeIs('team.index', 'team.tasks.*', 'team.positions.*')) }}">{{ __('Atur tugas') }}<span class="text-slate-400">›</span></a>
                                    <a href="{{ route('team.catalog') }}" class="{{ $menuItem(request()->routeIs('team.catalog')) }}">{{ __('Daftar checklist') }}<span class="text-slate-400">›</span></a>
                                @endcan
                                @can('manage-tasks')
                                    <a href="{{ route('admin.tasks.index') }}" class="{{ $menuItem(request()->routeIs('admin.tasks.*', 'admin.positions.*')) }}">{{ __('Kelola tugas') }}<span class="text-slate-400">›</span></a>
                                @endcan
                            </div>
                        </div>
                    </div>
                @endcanany
                @canany(['manage-access', 'manage-team-tasks'])
                    <div class="group relative flex shrink-0">
                        <button type="button" aria-haspopup="true" class="{{ $navLink($accessActive) }}">{{ __('Kelola') }} <span class="text-[10px] opacity-70" aria-hidden="true">▾</span></button>
                        <div class="invisible absolute left-0 top-full z-30 w-60 pt-1 opacity-0 transition group-hover:visible group-hover:opacity-100 group-focus-within:visible group-focus-within:opacity-100">
                            <div class="rounded-2xl bg-[#fffdf9] p-2 shadow-xl ring-1 ring-black/5">
                                @can('manage-access')
                                    <a href="{{ route('admin.access.index') }}" class="{{ $menuItem(request()->routeIs('admin.access.*', 'admin.sync')) }}">{{ __('Akses pengguna') }}<span class="text-slate-400">›</span></a>
                                @endcan
                                <a href="{{ route('admin.settings.edit') }}" class="{{ $menuItem(request()->routeIs('admin.settings.*')) }}">{{ __('Pengaturan') }}<span class="text-slate-400">›</span></a>
                            </div>
                        </div>
                    </div>
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

<dialog id="confirm-dialog" aria-labelledby="confirm-title" class="m-auto w-[min(26rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-[#2a1d12]/50">
    <div class="space-y-4 p-6">
        <h2 id="confirm-title" class="font-display text-2xl text-slate-900">{{ __('Yakin?') }}</h2>
        <p class="text-sm text-slate-600" data-confirm-message></p>
        <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
            <button type="button" class="btn-secondary focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500" data-confirm-cancel>{{ __('Batal') }}</button>
            <button type="button" class="btn-danger focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500" data-confirm-ok>{{ __('Lanjutkan') }}</button>
        </div>
    </div>
</dialog>

<script>
    // Konfirmasi di dalam aplikasi (pengganti confirm() bawaan browser). Formulir atau tombol bertanda data-confirm / data-confirm-button.
    (() => {
        const dialog = document.getElementById('confirm-dialog');
        const message = dialog.querySelector('[data-confirm-message]');
        let pending = null;

        const ask = (element, text) => {
            pending = element;
            message.textContent = text;
            dialog.showModal();
        };

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!form.dataset?.confirm || form.dataset.confirmed === '1') return;
            event.preventDefault();
            ask(form, form.dataset.confirm);
        });

        document.addEventListener('click', (event) => {
            const button = event.target.closest('[data-confirm-button]');
            if (!button || button.dataset.confirmed === '1') return;
            event.preventDefault();
            ask(button, button.dataset.confirmButton);
        });

        dialog.querySelector('[data-confirm-ok]').addEventListener('click', () => {
            const element = pending;
            pending = null;
            dialog.close('ok');
            if (!element) return;
            element.dataset.confirmed = '1';
            if (element.tagName === 'FORM') element.requestSubmit();
            else element.click();
            element.dataset.confirmed = '';
        });

        dialog.querySelector('[data-confirm-cancel]').addEventListener('click', () => {
            pending = null;
            dialog.close('cancel');
        });

        // Untuk skrip lain (mis. hapus foto): bertanya lalu menunggu jawaban. Esc / klik di luar = tidak.
        let answer = null;
        window.askConfirm = (text) => new Promise((resolve) => {
            answer = resolve;
            message.textContent = text;
            dialog.returnValue = '';
            dialog.showModal();
        });
        dialog.addEventListener('close', () => {
            if (answer) answer(dialog.returnValue === 'ok');
            answer = null;
        });
    })();
</script>

<dialog id="photo-viewer" class="m-auto max-h-[90vh] max-w-[min(56rem,calc(100vw-2rem))] bg-transparent p-0 backdrop:bg-[#1b1511]/80">
    <div class="relative">
        <img data-photo-viewer-image alt="" class="max-h-[85vh] w-auto rounded-2xl bg-white object-contain shadow-2xl">
        <button type="button" data-close-photo class="absolute right-3 top-3 inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/95 text-2xl leading-none text-slate-800 shadow hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500" aria-label="{{ __('Tutup foto') }}">{{ __('×') }}</button>
    </div>
</dialog>
</body>
</html>
