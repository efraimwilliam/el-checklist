<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1b1511">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Checklist">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <script>
        // Dibuka di dalam panel obrolan (iframe): header aplikasi dan tombol obrolan disembunyikan (lihat app.css .in-frame).
        if (window.self !== window.top) document.documentElement.classList.add('in-frame');
        window.__i18n = @json(\App\Support\Translations::forJs());
        window.t = (text) => (window.__i18n && window.__i18n[text]) || text;
    </script>
    @vite(['resources/css/app.css', 'resources/js/date-picker.js', 'resources/js/photo-viewer.js', 'resources/js/password-toggle.js', 'resources/js/pull-refresh.js'])
</head>
<body @class(['bg-white text-slate-800 antialiased', 'min-h-screen' => ! $fullHeight, 'chat-fixed flex h-dvh flex-col overflow-hidden' => $fullHeight])>
@auth
    @php($me = auth()->user())
    @php($initials = collect(explode(' ', $me->name()))->filter()->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode(''))
    @php($navLink = fn (bool $active) => 'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-t-xl px-3.5 py-2.5 transition '.($active ? 'bg-white/[0.07] font-semibold text-gold-400 shadow-[inset_0_-3px_0_#c9a24f]' : 'text-white/70 hover:bg-white/[0.05] hover:text-white'))
    @php($menuItem = fn (bool $active) => 'flex items-center justify-between gap-6 rounded-lg px-2.5 py-2 text-slate-800 hover:bg-amber-50 '.($active ? 'bg-amber-50 font-semibold' : ''))
    @php($teamActive = request()->routeIs('team.*', 'admin.tasks.*', 'admin.positions.*', 'admin.rooms.*', 'admin.categories.*'))
    @php($accessActive = request()->routeIs('admin.access.*', 'admin.sync', 'admin.settings.*'))
    @php($newIssues = \Illuminate\Support\Facades\Gate::allows('room-issues') ? $me->newRoomIssueCount() : 0)
    @php($unreadChats = \Illuminate\Support\Facades\Gate::allows('chat') ? $me->unreadChatCount() : 0)
    <header data-app-header @class(['shrink-0 bg-ink text-white print:hidden', 'max-md:hidden' => $bareOnMobile])>
        <div class="gold-rule h-[3px]"></div>
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 pt-3 max-md:pb-3 sm:px-6">
            <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-3">
                <img src="{{ asset('images/icon-checklist.svg') }}" alt="Checklist" class="h-9 w-9 shrink-0 rounded-[22%]">
                <span class="hidden h-8 w-px shrink-0 bg-white/20 sm:block"></span>
                <span class="min-w-0 leading-tight">
                    <span class="block truncate text-base font-semibold text-gold-400">éL Hotel &amp; Resort Bali Sanur</span>
                    <span class="block truncate text-[11px] uppercase tracking-[0.12em] text-white/60">{{ __('Daily Department Checklist') }}</span>
                </span>
            </a>
            <div class="flex shrink-0 items-center gap-2.5 sm:gap-3">
                {{-- Pegawai (Staff) tidak perlu ganti bahasa di header; HOD, HR, GM, dan Administrator tetap melihatnya. --}}
                @unless ($me->role === \App\Enums\Role::Staff)
                    <div class="max-md:hidden"><x-language-switch /></div>
                @endunless
                <a href="{{ route('profile.edit') }}" title="{{ __('Profil saya') }}" class="flex items-center gap-2.5 rounded-full border border-white/10 bg-white/[0.06] py-1 pl-1 pr-3 transition hover:bg-white/10">
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-gradient-to-br from-gold-400 to-gold-600 text-xs font-bold text-[#2a1d12]" aria-hidden="true">{{ $initials }}</span>
                    <span class="hidden min-w-0 max-w-72 truncate text-left text-[13px] font-semibold leading-tight sm:block">
                        {{ $me->name() }}
                        <span class="font-normal text-white/60">· {{ $me->role->label() }}@if ($me->employee?->department) · {{ $me->employee->department->name }}@endif</span>
                    </span>
                </a>
                @if (\App\Support\PortalClient::viaPortal())
                    {{-- Kembali ke beranda Portal (pilihan sistem lain). Hanya ikon; keterangannya muncul saat di-hover (title). --}}
                    <a href="{{ \App\Support\PortalClient::homeUrl() }}" title="{{ __('Kembali ke Dashboard Utama') }}" aria-label="{{ __('Kembali ke Dashboard Utama') }}" class="grid h-9 w-9 place-items-center rounded-full border border-white/10 text-white/70 transition hover:bg-white/10 hover:text-white">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
                    </a>
                @endif
                {{-- HP: satu tombol menu (☰) menggantikan baris menu; titik merah = ada kendala baru / pesan belum dibaca. --}}
                <button type="button" data-mobile-menu-open aria-haspopup="dialog" aria-label="{{ __('Buka menu') }}{{ $newIssues + $unreadChats ? ' ('.__('ada yang baru').')' : '' }}"
                        class="relative grid h-9 w-9 place-items-center rounded-full border border-white/10 text-white/80 transition hover:bg-white/10 hover:text-white md:hidden">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    @if ($newIssues + $unreadChats)
                        <span class="absolute -right-0.5 -top-0.5 h-3 w-3 rounded-full border-2 border-ink bg-rose-600" aria-hidden="true"></span>
                    @endif
                </button>
                <form method="POST" action="{{ route('logout') }}" class="max-md:hidden">
                    @csrf
                    <button type="submit" title="{{ __('Keluar') }}" aria-label="{{ __('Keluar') }}" class="grid h-9 w-9 place-items-center rounded-full border border-white/10 text-white/70 transition hover:bg-white/10 hover:text-white">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                    </button>
                </form>
            </div>
        </div>
        <div class="mt-3 border-t border-white/10 bg-[#251d17] max-md:hidden">
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
                                    <a href="{{ route('fill.assign') }}" class="{{ $menuItem(request()->routeIs('fill.assign', 'checklists.tasks.*')) }}">{{ __('Atur checklist') }}</a>
                                    <a href="{{ route('fill.create') }}" class="{{ $menuItem(request()->routeIs('fill.create', 'checklists.today', 'checklists.fill')) }}">{{ __('Isi checklist') }}</a>
                                </div>
                            </div>
                        </div>
                    @endif
                @endcan
                @can('room-issues')
                    <a href="{{ route('kamar.issues.index') }}" class="{{ $navLink(request()->routeIs('kamar.*')) }}" @if ($newIssues) aria-label="{{ __('Kendala') }}, {{ $newIssues }} {{ __('baru untuk Anda') }}" @endif>{{ __('Kendala') }}@if ($newIssues) <span class="ml-1.5 inline-flex min-w-5 items-center justify-center rounded-full bg-rose-600 px-1.5 text-[11px] font-semibold leading-5 text-white" title="{{ $newIssues }} {{ __('baru untuk Anda') }}">{{ $newIssues > 99 ? '99+' : $newIssues }}</span>@endif</a>
                @endcan
                @can('chat')
                    <a href="{{ route('chat.index') }}" class="{{ $navLink(request()->routeIs('chat.*')) }}" @if ($unreadChats) aria-label="{{ __('Obrolan') }}, {{ $unreadChats }} {{ __('pesan baru') }}" @endif>{{ __('Obrolan') }}@if ($unreadChats) <span class="ml-1.5 inline-flex min-w-5 items-center justify-center rounded-full bg-rose-600 px-1.5 text-[11px] font-semibold leading-5 text-white">{{ $unreadChats > 99 ? '99+' : $unreadChats }}</span>@endif</a>
                @endcan
                @can('chronology-reports')
                    <a href="{{ route('chronology.index') }}" class="{{ $navLink(request()->routeIs('chronology.*')) }}">Chronology Report</a>
                @endcan
                @can('view-checklists')
                    <div class="group relative flex shrink-0">
                        <button type="button" aria-haspopup="true" class="{{ $navLink(request()->routeIs('laporan.*')) }}">{{ __('Laporan') }} <span class="text-[10px] opacity-70" aria-hidden="true">▾</span></button>
                        <div class="invisible absolute left-0 top-full z-30 w-60 pt-1 opacity-0 transition group-hover:visible group-hover:opacity-100 group-focus-within:visible group-focus-within:opacity-100">
                            <div class="rounded-2xl bg-[#fffdf9] p-2 shadow-xl ring-1 ring-black/5">
                                <a href="{{ route('laporan.index') }}" class="{{ $menuItem(request()->routeIs('laporan.index')) }}">{{ __('Ringkasan') }}</a>
                                <a href="{{ route('laporan.issues') }}" class="{{ $menuItem(request()->routeIs('laporan.issues', 'laporan.task', 'laporan.followup')) }}">{{ __('Masalah & Pending') }}</a>
                                <a href="{{ route('laporan.parameters') }}" class="{{ $menuItem(request()->routeIs('laporan.parameters')) }}">{{ __('Parameter') }}</a>
                                <a href="{{ route('laporan.monthly') }}" class="{{ $menuItem(request()->routeIs('laporan.monthly')) }}">{{ __('Bulanan') }}</a>
                                <a href="{{ route('laporan.rooms') }}" class="{{ $menuItem(request()->routeIs('laporan.rooms', 'laporan.room')) }}">{{ __('Peta kamar') }}</a>
                                <a href="{{ route('laporan.data') }}" class="{{ $menuItem(request()->routeIs('laporan.data', 'laporan.data.export')) }}">{{ __('Data') }}</a>
                            </div>
                        </div>
                    </div>
                @endcan
                @canany(['manage-team-tasks', 'manage-tasks'])
                    <div class="group relative flex shrink-0">
                        <button type="button" aria-haspopup="true" class="{{ $navLink($teamActive) }}">{{ __('Tugas') }} <span class="text-[10px] opacity-70" aria-hidden="true">▾</span></button>
                        <div class="invisible absolute left-0 top-full z-30 w-60 pt-1 opacity-0 transition group-hover:visible group-hover:opacity-100 group-focus-within:visible group-focus-within:opacity-100">
                            <div class="rounded-2xl bg-[#fffdf9] p-2 shadow-xl ring-1 ring-black/5">
                                @can('manage-team-tasks')
                                    <a href="{{ route('team.index') }}" class="{{ $menuItem(request()->routeIs('team.index', 'team.tasks.*', 'team.positions.*')) }}">{{ __('Atur tugas pegawai') }}</a>
                                    <a href="{{ route('team.catalog') }}" class="{{ $menuItem(request()->routeIs('team.catalog')) }}">{{ __('Kamus daftar tugas') }}</a>
                                @endcan
                                @can('manage-tasks')
                                    <a href="{{ route('admin.tasks.index') }}" class="{{ $menuItem(request()->routeIs('admin.tasks.*', 'admin.positions.*')) }}">{{ __('Jabatan dan tugas') }}</a>
                                    {{-- Data kamar: submenu yang terbuka saat diarahkan kursor (di layar kecil tampil menjorok di bawahnya). --}}
                                    @php($roomsTab = request()->routeIs('admin.rooms.index') ? (request('tab') === 'kategori' ? 'kategori' : (request('tab') === 'denah' ? 'denah' : 'kamar')) : (request()->routeIs('admin.plans.*') ? 'denah' : null))
                                    <div class="group/sub relative">
                                        <a href="{{ route('admin.rooms.index') }}" class="{{ $menuItem(request()->routeIs('admin.rooms.*', 'admin.categories.*', 'admin.plans.*')) }}" aria-haspopup="true">{{ __('Data kamar') }}<span class="text-slate-400">›</span></a>
                                        <div class="mt-1 space-y-0.5 pl-3 sm:invisible sm:absolute sm:left-full sm:top-0 sm:z-40 sm:mt-0 sm:w-56 sm:pl-1.5 sm:opacity-0 sm:transition sm:group-hover/sub:visible sm:group-hover/sub:opacity-100 sm:group-focus-within/sub:visible sm:group-focus-within/sub:opacity-100">
                                            <div class="rounded-2xl bg-[#fffdf9] p-2 shadow-xl ring-1 ring-black/5">
                                                <a href="{{ route('admin.rooms.index', ['tab' => 'kamar']) }}" class="{{ $menuItem($roomsTab === 'kamar' || request()->routeIs('admin.rooms.import')) }}">{{ __('Kamar') }}</a>
                                                <a href="{{ route('admin.rooms.index', ['tab' => 'kategori']) }}" class="{{ $menuItem($roomsTab === 'kategori') }}">{{ __('Kategori kendala') }}</a>
                                                <a href="{{ route('admin.rooms.index', ['tab' => 'denah']) }}" class="{{ $menuItem($roomsTab === 'denah') }}">{{ __('Denah') }}</a>
                                            </div>
                                        </div>
                                    </div>
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
                                    <a href="{{ route('admin.access.index') }}" class="{{ $menuItem(request()->routeIs('admin.access.*', 'admin.sync')) }}">{{ __('Akses pengguna') }}</a>
                                @endcan
                                <a href="{{ route('admin.settings.edit') }}" class="{{ $menuItem(request()->routeIs('admin.settings.*')) }}">{{ __('Pengaturan') }}</a>
                            </div>
                        </div>
                    </div>
                @endcanany
                <a href="{{ route('checklists.index') }}" class="{{ $navLink(request()->routeIs('checklists.index', 'checklists.show')) }}">{{ __('Riwayat') }}</a>
            </nav>
        </div>
    </header>

    {{-- Menu HP: panel dari kanan berisi semua menu (sama dengan baris menu di layar lebar). --}}
    @php($drawerLink = fn (bool $active) => 'flex items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-[15px] transition '.($active ? 'bg-white/[0.08] font-semibold text-gold-400' : 'text-white/85 hover:bg-white/[0.06]'))
    @php($badge = fn (int $n) => $n ? '<span class="inline-flex min-w-5 items-center justify-center rounded-full bg-rose-600 px-1.5 text-[11px] font-semibold leading-5 text-white">'.($n > 99 ? '99+' : $n).'</span>' : '')
    <dialog data-mobile-menu aria-label="{{ __('Menu utama') }}" class="m-0 ml-auto h-dvh max-h-dvh w-[min(20rem,86vw)] max-w-none bg-ink p-0 text-white shadow-2xl backdrop:bg-black/50 md:hidden print:hidden">
        <div class="flex h-full flex-col">
            <div class="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
                <a href="{{ route('profile.edit') }}" class="flex min-w-0 items-center gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-gradient-to-br from-gold-400 to-gold-600 text-xs font-bold text-[#2a1d12]" aria-hidden="true">{{ $initials }}</span>
                    <span class="min-w-0 leading-tight">
                        <span class="block truncate text-sm font-semibold">{{ $me->name() }}</span>
                        <span class="block truncate text-xs text-white/60">{{ $me->role->label() }}@if ($me->employee?->department) · {{ $me->employee->department->name }}@endif</span>
                    </span>
                </a>
                <button type="button" data-mobile-menu-close class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-2xl leading-none text-white/70 hover:bg-white/10" aria-label="{{ __('Tutup menu') }}">×</button>
            </div>
            <nav class="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2 py-3" aria-label="{{ __('Menu utama') }}">
                @can('view-checklists')
                    <a href="{{ route('dashboard') }}" class="{{ $drawerLink(request()->routeIs('dashboard')) }}">{{ __('Dashboard') }}</a>
                @endcan
                @can('fill-checklists')
                    @if ($me->role === \App\Enums\Role::Staff)
                        <a href="{{ route('fill.create') }}" class="{{ $drawerLink(request()->routeIs('fill.*', 'checklists.today')) }}">{{ __('Checklist saya') }}</a>
                    @else
                        <p class="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-white/40">{{ __('Checklist') }}</p>
                        <a href="{{ route('fill.assign') }}" class="{{ $drawerLink(request()->routeIs('fill.assign', 'checklists.tasks.*')) }}">{{ __('Atur checklist') }}</a>
                        <a href="{{ route('fill.create') }}" class="{{ $drawerLink(request()->routeIs('fill.create', 'checklists.today', 'checklists.fill')) }}">{{ __('Isi checklist') }}</a>
                    @endif
                @endcan
                <a href="{{ route('checklists.index') }}" class="{{ $drawerLink(request()->routeIs('checklists.index', 'checklists.show')) }}">{{ __('Riwayat') }}</a>
                @can('room-issues')
                    <a href="{{ route('kamar.issues.index') }}" class="{{ $drawerLink(request()->routeIs('kamar.*')) }}">{{ __('Kendala') }}{!! $badge($newIssues) !!}</a>
                @endcan
                @can('chat')
                    <a href="{{ route('chat.index') }}" class="{{ $drawerLink(request()->routeIs('chat.*')) }}">{{ __('Obrolan') }}{!! $badge($unreadChats) !!}</a>
                @endcan
                @can('chronology-reports')
                    <a href="{{ route('chronology.index') }}" class="{{ $drawerLink(request()->routeIs('chronology.*')) }}">Chronology Report</a>
                @endcan
                @can('view-checklists')
                    <p class="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-white/40">{{ __('Laporan') }}</p>
                    <a href="{{ route('laporan.index') }}" class="{{ $drawerLink(request()->routeIs('laporan.index')) }}">{{ __('Ringkasan') }}</a>
                    <a href="{{ route('laporan.issues') }}" class="{{ $drawerLink(request()->routeIs('laporan.issues', 'laporan.task', 'laporan.followup')) }}">{{ __('Masalah & Pending') }}</a>
                    <a href="{{ route('laporan.parameters') }}" class="{{ $drawerLink(request()->routeIs('laporan.parameters')) }}">{{ __('Parameter') }}</a>
                    <a href="{{ route('laporan.monthly') }}" class="{{ $drawerLink(request()->routeIs('laporan.monthly')) }}">{{ __('Bulanan') }}</a>
                    <a href="{{ route('laporan.rooms') }}" class="{{ $drawerLink(request()->routeIs('laporan.rooms', 'laporan.room')) }}">{{ __('Peta kamar') }}</a>
                    <a href="{{ route('laporan.data') }}" class="{{ $drawerLink(request()->routeIs('laporan.data', 'laporan.data.export')) }}">{{ __('Data') }}</a>
                @endcan
                @canany(['manage-team-tasks', 'manage-tasks'])
                    <p class="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-white/40">{{ __('Tugas') }}</p>
                    @can('manage-team-tasks')
                        <a href="{{ route('team.index') }}" class="{{ $drawerLink(request()->routeIs('team.index', 'team.tasks.*', 'team.positions.*')) }}">{{ __('Atur tugas pegawai') }}</a>
                        <a href="{{ route('team.catalog') }}" class="{{ $drawerLink(request()->routeIs('team.catalog')) }}">{{ __('Kamus daftar tugas') }}</a>
                    @endcan
                    @can('manage-tasks')
                        <a href="{{ route('admin.tasks.index') }}" class="{{ $drawerLink(request()->routeIs('admin.tasks.*', 'admin.positions.*')) }}">{{ __('Jabatan dan tugas') }}</a>
                        <a href="{{ route('admin.rooms.index') }}" class="{{ $drawerLink(request()->routeIs('admin.rooms.*', 'admin.categories.*', 'admin.plans.*')) }}">{{ __('Data kamar') }}</a>
                    @endcan
                @endcanany
                @canany(['manage-access', 'manage-team-tasks'])
                    <p class="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-white/40">{{ __('Kelola') }}</p>
                    @can('manage-access')
                        <a href="{{ route('admin.access.index') }}" class="{{ $drawerLink(request()->routeIs('admin.access.*', 'admin.sync')) }}">{{ __('Akses pengguna') }}</a>
                    @endcan
                    <a href="{{ route('admin.settings.edit') }}" class="{{ $drawerLink(request()->routeIs('admin.settings.*')) }}">{{ __('Pengaturan') }}</a>
                @endcanany
            </nav>
            <div class="flex items-center justify-between gap-3 border-t border-white/10 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                @unless ($me->role === \App\Enums\Role::Staff)
                    <x-language-switch />
                @else
                    <span></span>
                @endunless
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex items-center gap-2 rounded-full border border-white/10 px-3.5 py-2 text-sm text-white/80 hover:bg-white/10">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                        {{ __('Keluar') }}
                    </button>
                </form>
            </div>
        </div>
    </dialog>
    <script>
        (() => {
            const menu = document.querySelector('[data-mobile-menu]');
            if (!menu) return;
            document.querySelectorAll('[data-mobile-menu-open]').forEach((button) => button.addEventListener('click', () => menu.showModal()));
            menu.querySelector('[data-mobile-menu-close]').addEventListener('click', () => menu.close());
            menu.addEventListener('click', (event) => { if (event.target === menu) menu.close(); });
            // Layar diperlebar (HP diputar / jendela dibesarkan): menu HP ditutup karena baris menu biasa tampil.
            window.matchMedia('(min-width: 768px)').addEventListener('change', (event) => { if (event.matches) menu.close(); });
        })();
    </script>
@endauth
<main @class(['mx-auto w-full max-w-6xl', 'px-4 py-6 sm:px-6' => ! $fullHeight, 'flex min-h-0 flex-1 flex-col md:px-6 md:py-4' => $fullHeight])>
    @if (session('status') && ! $fullHeight)
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
@auth
    @can('chat')
        @unless (request()->routeIs('chat.*'))
            {{-- Tombol obrolan melayang: membuka obrolan di panel tanpa meninggalkan halaman (iframe /obrolan). --}}
            <div data-chat-widget data-chat-url="{{ route('chat.index') }}" data-unread-url="{{ route('chat.unread') }}" class="print:hidden">
                <button type="button" data-chat-fab aria-expanded="false" aria-controls="chat-panel"
                        aria-label="{{ __('Obrolan') }}{{ $unreadChats ? ', '.$unreadChats.' '.__('pesan baru') : '' }}" title="{{ __('Obrolan') }}"
                        class="fixed right-4 z-40 grid h-14 w-14 place-items-center rounded-full bg-[#b08a3e] text-white shadow-lg ring-4 ring-white/70 transition hover:bg-[#9a7733] focus:outline-none focus-visible:ring-gold-400 bottom-[max(1rem,env(safe-area-inset-bottom))] sm:right-6">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 016.5 3h11A2.5 2.5 0 0120 5.5v8a2.5 2.5 0 01-2.5 2.5H10l-4.5 4v-4A1.5 1.5 0 014 14.5v-9z" stroke-linejoin="round"/><path d="M8 8h8M8 11.5h5" stroke-linecap="round"/></svg>
                    <span data-chat-fab-badge @if (! $unreadChats) hidden @endif class="absolute -right-1 -top-1 min-w-5 rounded-full border-2 border-white bg-rose-600 px-1 text-[11px] font-semibold leading-4 text-white">{{ $unreadChats > 99 ? '99+' : $unreadChats }}</span>
                </button>
                {{-- HP: pelapis polos di belakang panel. Celah di atas papan ketik (area yang disisihkan iOS untuk bilah Sebelumnya / Berikutnya / Selesai)
                     tidak lagi memperlihatkan isi halaman di belakangnya. --}}
                <div hidden data-chat-backdrop class="fixed inset-0 z-[49] bg-[#f4efe6] sm:hidden"></div>
                <section id="chat-panel" hidden data-chat-panel role="dialog" aria-label="{{ __('Obrolan') }}"
                         class="fixed inset-0 z-50 flex flex-col overflow-hidden bg-white shadow-2xl sm:inset-auto sm:bottom-24 sm:right-6 sm:h-[min(42rem,calc(100dvh-8rem))] sm:w-[25rem] sm:rounded-2xl sm:ring-1 sm:ring-black/10">
                    <div class="flex shrink-0 items-center justify-between gap-2 bg-ink px-3 py-2 pt-[max(0.5rem,env(safe-area-inset-top))] text-white">
                        <span class="flex items-center gap-1 text-sm font-semibold sm:gap-2 sm:pl-1">
                            {{-- HP: panah kembali seperti layar baru (menutup panel, halaman di bawahnya tetap seperti semula). --}}
                            <button type="button" data-chat-close class="-ml-1 grid h-9 w-9 place-items-center rounded-full text-white/90 hover:bg-white/10 sm:hidden" aria-label="{{ __('Kembali') }}">
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <svg class="hidden h-4 w-4 text-gold-400 sm:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 016.5 3h11A2.5 2.5 0 0120 5.5v8a2.5 2.5 0 01-2.5 2.5H10l-4.5 4v-4A1.5 1.5 0 014 14.5v-9z" stroke-linejoin="round"/></svg>
                            {{ __('Obrolan') }}
                        </span>
                        <span class="flex items-center gap-1">
                            <button type="button" data-chat-close class="hidden h-8 w-8 place-items-center rounded-full text-xl leading-none text-white/80 hover:bg-white/10 sm:grid" aria-label="{{ __('Tutup obrolan') }}">×</button>
                        </span>
                    </div>
                    <div class="relative min-h-0 flex-1">
                        <iframe data-chat-frame title="{{ __('Obrolan') }}" class="h-full w-full border-0 bg-white"></iframe>
                        <div data-chat-loading class="absolute inset-0 grid place-items-center bg-white text-sm text-slate-400">{{ __('Memuat…') }}</div>
                    </div>
                </section>
            </div>
            @vite(['resources/js/chat-widget.js'])
        @endunless
    @endcan
@endauth
@auth
    <script>
        // Service worker (notifikasi obrolan + bisa dipasang ke layar utama). Tidak menyimpan halaman, jadi data selalu dari server.
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => { navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {}); });
        }
    </script>
    @can('chat')
        @php($pushConfig = ['vapid' => \App\Push\VapidKeys::publicKey(), 'subscribe' => route('chat.push.subscribe'), 'unsubscribe' => route('chat.push.unsubscribe'), 'status' => route('chat.push.status')])
        <script>window.__push = @json($pushConfig);</script>
    @endcan
@endauth
</body>
</html>
