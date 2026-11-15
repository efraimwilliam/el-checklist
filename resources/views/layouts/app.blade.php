<!DOCTYPE html>
@php($chatTool = filled(request()->input('obrolan', old('obrolan'))))
<html lang="id" @class(['chat-tool' => $chatTool])>
<head>
    <meta charset="utf-8">
    {{-- Tampilan dikunci di skala 1 (tanpa zoom): mencegah layar "bergerak" karena cubit, ketuk dua kali, atau zoom otomatis iPhone saat
         kolom isian diketuk. Safari iPhone mengabaikan user-scalable, jadi cubitnya juga dicegah di bawah (gesture*) dan di app.css (touch-action). --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <script>
        ['gesturestart', 'gesturechange', 'gestureend'].forEach((type) => document.addEventListener(type, (event) => event.preventDefault(), { passive: false }));
    </script>
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
        // Form yang dibuka dari obrolan (menu lampiran / perintah /p /k /c, html.chat-tool): Batal / tutup dialog utama memberi tahu obrolan.
        if (document.documentElement.classList.contains('chat-tool') && window.parent !== window) {
            const closeTool = () => window.parent.postMessage({ type: 'chat-tool-close' }, location.origin);
            document.addEventListener('close', (event) => { if (event.target.matches?.('[data-chat-tool-main]')) closeTool(); }, true);
            document.addEventListener('click', (event) => { if (event.target.closest?.('[data-chat-tool-close]')) { event.preventDefault(); closeTool(); } });
        }
        window.__i18n = @json(\App\Support\Translations::forJs());
        window.t = (text) => (window.__i18n && window.__i18n[text]) || text;
    </script>
    @vite(['resources/css/app.css', 'resources/js/date-picker.js', 'resources/js/photo-viewer.js', 'resources/js/password-toggle.js', 'resources/js/pull-refresh.js', 'resources/js/ui.js'])
</head>
<body @class(['bg-white text-slate-800 antialiased', 'min-h-screen' => ! $fullHeight, 'chat-fixed flex h-dvh flex-col overflow-hidden' => $fullHeight])>
@auth
    @php($me = auth()->user())
    @php($initials = collect(explode(' ', $me->name()))->filter()->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode(''))
    @php($navLink = fn (bool $active) => 'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-t-xl px-3.5 py-2.5 transition '.($active ? 'bg-white/[0.07] font-semibold text-gold-400 shadow-[inset_0_-3px_0_#c9a24f]' : 'text-white/70 hover:bg-white/[0.05] hover:text-white'))
    @php($menuItem = fn (bool $active) => 'flex items-center justify-between gap-6 rounded-lg px-2.5 py-2 text-slate-800 hover:bg-amber-50 '.($active ? 'bg-amber-50 font-semibold' : ''))
    @php($canRequests = \Illuminate\Support\Facades\Gate::allows('guest-requests'))
    @php($canIssues = \Illuminate\Support\Facades\Gate::allows('room-issues'))
    @php($newRequests = $canRequests ? $me->newGuestRequestCount() : 0)
    @php($newIssues = $canIssues ? $me->newRoomIssueCount() : 0)
    @php($newService = $newIssues + $newRequests)
    {{-- Menu "Operasional": Kendala, Permintaan tamu, Chronology Report (yang fiturnya menyala). Dua atau lebih = dropdown, satu = tautan biasa. --}}
    @php($serviceItems = array_values(array_filter([
        $canIssues ? ['route' => 'kamar.issues.index', 'active' => ['kamar.*'], 'label' => __('Kendala'), 'count' => $newIssues] : null,
        $canRequests ? ['route' => 'permintaan.index', 'active' => ['permintaan.*'], 'label' => __('Permintaan tamu'), 'count' => $newRequests] : null,
        \Illuminate\Support\Facades\Gate::allows('chronology-reports') ? ['route' => 'chronology.index', 'active' => ['chronology.*'], 'label' => 'Chronology Report', 'count' => 0] : null,
    ])))
    @php($serviceActive = request()->routeIs('kamar.*', 'permintaan.*', 'chronology.*'))
    @php($unreadChats = \Illuminate\Support\Facades\Gate::allows('chat') ? $me->unreadChatCount() : 0)
    {{-- SATU sumber menu untuk baris menu atas (komputer), panel ☰ (HP), dan navigasi bawah (HP). Menu baru cukup ditambahkan di sini.
         Entri: label, route, active, count (angka merah), items (dropdown / kelompok), children (submenu, hanya di komputer). --}}
    <?php
        $is = fn (string ...$patterns) => request()->routeIs(...$patterns);
        $link = fn (string $label, string $route, bool $active, int $count = 0, array $params = [], array $children = []) => compact('label', 'route', 'active', 'count', 'params', 'children');
        $can = fn (string $ability) => \Illuminate\Support\Facades\Gate::allows($ability);
        $isStaff = $me->role === \App\Enums\Role::Staff;
        $roomsTab = $is('admin.rooms.index') ? (request('tab') === 'kategori' ? 'kategori' : (request('tab') === 'denah' ? 'denah' : 'kamar')) : ($is('admin.plans.*') ? 'denah' : null);
        $menu = [];
        if ($can('view-checklists')) {
            $menu[] = $link(__('Dashboard'), 'dashboard', $is('dashboard'));
        }
        if ($can('fill-checklists')) {
            $menu[] = $isStaff
                ? $link(__('Checklist saya'), 'fill.create', $is('fill.*', 'checklists.today'))
                : ['label' => __('Checklist'), 'active' => $is('fill.*', 'checklists.today', 'checklists.tasks.*', 'checklists.fill'), 'count' => 0, 'items' => [
                    $link(__('Atur checklist'), 'fill.assign', $is('fill.assign', 'checklists.tasks.*')),
                    $link(__('Isi checklist'), 'fill.create', $is('fill.create', 'checklists.today', 'checklists.fill')),
                ]];
        }
        $menu[] = $link(__('Riwayat'), 'checklists.index', $is('checklists.index', 'checklists.show'));
        $service = array_map(fn ($item) => $link($item['label'], $item['route'], $is(...$item['active']), $item['count']), $serviceItems);
        if (count($service) > 1) {
            $menu[] = ['label' => __('Operasional'), 'active' => $serviceActive, 'count' => $newService, 'items' => $service];
        } elseif ($service) {
            $menu[] = $service[0];
        }
        if ($can('chat')) {
            $menu[] = $link(__('Obrolan'), 'chat.index', $is('chat.*'), $unreadChats);
        }
        if ($can('reports')) {
            $menu[] = ['label' => __('Laporan'), 'active' => $is('laporan.*'), 'count' => 0, 'items' => array_values(array_filter([
                $link(__('Ringkasan'), 'laporan.index', $is('laporan.index')),
                $link(__('Masalah & Pending'), 'laporan.issues', $is('laporan.issues', 'laporan.task', 'laporan.followup')),
                $link(__('Parameter'), 'laporan.parameters', $is('laporan.parameters')),
                $link(__('Bulanan'), 'laporan.monthly', $is('laporan.monthly')),
                $can('room-issues') ? $link(__('Peta kamar'), 'laporan.rooms', $is('laporan.rooms', 'laporan.room')) : null,
                $link(__('Data'), 'laporan.data', $is('laporan.data', 'laporan.data.export')),
            ]))];
        }
        if ($can('manage-team-tasks') || $can('manage-tasks')) {
            $menu[] = ['label' => __('Tugas'), 'active' => $is('team.*', 'admin.tasks.*', 'admin.positions.*', 'admin.rooms.*', 'admin.categories.*', 'admin.plans.*'), 'count' => 0, 'items' => array_values(array_filter([
                $can('manage-team-tasks') ? $link(__('Atur tugas pegawai'), 'team.index', $is('team.index', 'team.tasks.*', 'team.positions.*')) : null,
                $can('manage-team-tasks') ? $link(__('Kamus daftar tugas'), 'team.catalog', $is('team.catalog')) : null,
                $can('manage-tasks') ? $link(__('Jabatan dan tugas'), 'admin.tasks.index', $is('admin.tasks.*', 'admin.positions.*')) : null,
                $can('manage-tasks') ? $link(__('Data kamar'), 'admin.rooms.index', $is('admin.rooms.*', 'admin.categories.*', 'admin.plans.*'), 0, [], [
                    $link(__('Kamar'), 'admin.rooms.index', $roomsTab === 'kamar' || $is('admin.rooms.import'), 0, ['tab' => 'kamar']),
                    $link(__('Kategori kendala'), 'admin.rooms.index', $roomsTab === 'kategori', 0, ['tab' => 'kategori']),
                    $link(__('Denah'), 'admin.rooms.index', $roomsTab === 'denah', 0, ['tab' => 'denah']),
                ]) : null,
            ]))];
        }
        if ($can('manage-access') || $can('manage-team-tasks')) {
            $menu[] = ['label' => __('Kelola'), 'active' => $is('admin.access.*', 'admin.sync', 'admin.settings.*'), 'count' => 0, 'items' => array_values(array_filter([
                $can('manage-access') ? $link(__('Akses pengguna'), 'admin.access.index', $is('admin.access.*', 'admin.sync')) : null,
                $link(__('Pengaturan'), 'admin.settings.edit', $is('admin.settings.*')),
            ]))];
        }
    ?>
    <header data-app-header @class(['shrink-0 bg-ink text-white print:hidden', 'max-md:hidden' => $bareOnMobile])>
        <div class="gold-rule h-[3px]"></div>
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 pt-3 max-md:pb-3 sm:px-6">
            <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-3">
                <img src="{{ asset('images/icon-checklist.svg') }}" alt="Checklist" class="h-9 w-9 shrink-0 rounded-[22%]">
                <span class="hidden h-8 w-px shrink-0 bg-white/20 sm:block"></span>
                <span class="min-w-0 leading-tight">
                    <span class="block truncate text-base font-semibold text-gold-400"><span class="sm:hidden">éL Hotel Bali Sanur</span><span class="max-sm:hidden">éL Hotel &amp; Resort Bali Sanur</span></span>
                    <span class="block truncate text-[11px] uppercase tracking-[0.12em] text-white/60">{{ __('Daily Department Checklist') }}</span>
                </span>
            </a>
            <div class="flex shrink-0 items-center gap-2.5 sm:gap-3">
                {{-- Pegawai (Staff) tidak perlu ganti bahasa di header; HOD, HR, GM, dan Administrator tetap melihatnya. --}}
                @unless ($me->role === \App\Enums\Role::Staff)
                    <div class="max-md:hidden"><x-language-switch /></div>
                @endunless
                <a href="{{ route('profile.edit') }}" title="{{ __('Profil saya') }}" class="flex items-center gap-2.5 rounded-full border border-white/10 bg-white/[0.06] py-1 pl-1 pr-3 transition hover:bg-white/10">
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-gradient-to-br from-gold-400 to-gold-600 text-xs font-bold text-ink-soft" aria-hidden="true">{{ $initials }}</span>
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
                <button type="button" data-mobile-menu-open aria-haspopup="dialog" aria-label="{{ __('Buka menu') }}{{ $newService + $unreadChats ? ' ('.__('ada yang baru').')' : '' }}"
                        class="relative grid h-9 w-9 place-items-center rounded-full border border-white/10 text-white/80 transition hover:bg-white/10 hover:text-white md:hidden">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    @if ($newService + $unreadChats)
                        <span class="absolute -right-0.5 -top-0.5 h-3 w-3 rounded-full border-2 border-ink bg-rose-600" aria-hidden="true"></span>
                    @endif
                </button>
                <form method="POST" action="{{ route('logout') }}" class="max-md:hidden" data-confirm="{{ __('Anda akan keluar dari aplikasi. Pekerjaan yang sudah tersimpan tetap aman.') }}" data-confirm-title="{{ __('Keluar dari aplikasi?') }}" data-confirm-ok="{{ __('Keluar') }}" data-confirm-tone="primary">
                    @csrf
                    <button type="submit" title="{{ __('Keluar') }}" aria-label="{{ __('Keluar') }}" class="grid h-9 w-9 place-items-center rounded-full border border-white/10 text-white/70 transition hover:bg-white/10 hover:text-white">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                    </button>
                </form>
            </div>
        </div>
        <div class="mt-3 border-t border-white/10 bg-[#251d17] max-md:hidden">
            <nav class="mx-auto flex max-w-6xl flex-wrap items-stretch gap-x-1 px-2 text-sm sm:px-6" aria-label="{{ __('Menu utama') }}">
                {{-- Dropdown terbuka saat kursor di atasnya atau saat fokus papan ketik di dalamnya; aria-expanded dan Esc diatur ui.js. --}}
                @foreach ($menu as $index => $entry)
                    @isset($entry['items'])
                        <div class="group relative flex shrink-0" data-nav-menu>
                            <button type="button" aria-haspopup="true" aria-expanded="false" aria-controls="nav-menu-{{ $index }}" class="{{ $navLink($entry['active']) }}" @if ($entry['count']) aria-label="{{ $entry['label'] }}, {{ $entry['count'] }} {{ __('baru untuk Anda') }}" @endif>{{ $entry['label'] }}<x-ui.count :n="$entry['count']" class="ml-1.5" /> <span class="text-[10px] opacity-70" aria-hidden="true">▾</span></button>
                            <div id="nav-menu-{{ $index }}" class="invisible absolute left-0 top-full z-30 w-60 pt-1 opacity-0 transition group-hover:visible group-hover:opacity-100 group-focus-within:visible group-focus-within:opacity-100 group-data-[suppress]:invisible! group-data-[suppress]:opacity-0!">
                                <div class="rounded-2xl bg-[#fffdf9] p-2 shadow-menu ring-1 ring-black/5">
                                    @foreach ($entry['items'] as $item)
                                        @if ($item['children'])
                                            {{-- Submenu: terbuka saat diarahkan kursor / difokus --}}
                                            <div class="group/sub relative">
                                                <a href="{{ route($item['route'], $item['params']) }}" class="{{ $menuItem($item['active']) }}" aria-haspopup="true" @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}<span class="text-slate-400" aria-hidden="true">›</span></a>
                                                <div class="mt-1 space-y-0.5 pl-3 sm:invisible sm:absolute sm:left-full sm:top-0 sm:z-40 sm:mt-0 sm:w-56 sm:pl-1.5 sm:opacity-0 sm:transition sm:group-hover/sub:visible sm:group-hover/sub:opacity-100 sm:group-focus-within/sub:visible sm:group-focus-within/sub:opacity-100">
                                                    <div class="rounded-2xl bg-[#fffdf9] p-2 shadow-menu ring-1 ring-black/5">
                                                        @foreach ($item['children'] as $child)
                                                            <a href="{{ route($child['route'], $child['params']) }}" class="{{ $menuItem($child['active']) }}" @if ($child['active']) aria-current="page" @endif>{{ $child['label'] }}</a>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                        @else
                                            <a href="{{ route($item['route'], $item['params']) }}" class="{{ $menuItem($item['active']) }}" @if ($item['active']) aria-current="page" @endif><span>{{ $item['label'] }}</span><x-ui.count :n="$item['count']" class="ml-1.5" /></a>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @else
                        <a href="{{ route($entry['route'], $entry['params']) }}" class="{{ $navLink($entry['active']) }}" @if ($entry['active']) aria-current="page" @endif @if ($entry['count']) aria-label="{{ $entry['label'] }}, {{ $entry['count'] }} {{ $entry['route'] === 'chat.index' ? __('pesan baru') : __('baru untuk Anda') }}" @endif>{{ $entry['label'] }}<x-ui.count :n="$entry['count']" class="ml-1.5" /></a>
                    @endisset
                @endforeach
            </nav>
        </div>
    </header>

    {{-- Menu HP: panel dari kanan berisi semua menu (sama dengan baris menu di layar lebar). --}}
    @php($drawerLink = fn (bool $active) => 'flex items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-[15px] transition '.($active ? 'bg-white/[0.08] font-semibold text-gold-400' : 'text-white/85 hover:bg-white/[0.06]'))
    <dialog data-mobile-menu aria-label="{{ __('Menu utama') }}" class="m-0 ml-auto h-dvh max-h-dvh w-[min(20rem,86vw)] max-w-none bg-ink p-0 text-white shadow-2xl backdrop:bg-black/50 md:hidden print:hidden">
        <div class="flex h-full flex-col">
            <div class="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
                <a href="{{ route('profile.edit') }}" class="flex min-w-0 items-center gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-gradient-to-br from-gold-400 to-gold-600 text-xs font-bold text-ink-soft" aria-hidden="true">{{ $initials }}</span>
                    <span class="min-w-0 leading-tight">
                        <span class="block truncate text-sm font-semibold">{{ $me->name() }}</span>
                        <span class="block truncate text-xs text-white/60">{{ $me->role->label() }}@if ($me->employee?->department) · {{ $me->employee->department->name }}@endif</span>
                    </span>
                </a>
                <button type="button" data-mobile-menu-close class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-2xl leading-none text-white/70 hover:bg-white/10" aria-label="{{ __('Tutup menu') }}">×</button>
            </div>
            <nav class="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2 py-3" aria-label="{{ __('Menu utama') }}">
                @foreach ($menu as $entry)
                    @isset($entry['items'])
                        <p class="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-white/40">{{ $entry['label'] }}</p>
                        @foreach ($entry['items'] as $item)
                            <a href="{{ route($item['route'], $item['params']) }}" class="{{ $drawerLink($item['active']) }}" @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}<x-ui.count :n="$item['count']" /></a>
                        @endforeach
                    @else
                        {{-- Tautan tunggal sesudah kelompok: garis pemisah supaya tidak terbaca sebagai bagian kelompok itu. --}}
                        @if (isset($menu[$loop->index - 1]['items']))<div class="mx-3 my-2 border-t border-white/10"></div>@endif
                        <a href="{{ route($entry['route'], $entry['params']) }}" class="{{ $drawerLink($entry['active']) }}" @if ($entry['active']) aria-current="page" @endif>{{ $entry['label'] }}<x-ui.count :n="$entry['count']" /></a>
                    @endisset
                @endforeach
            </nav>
            <div class="flex items-center justify-between gap-3 border-t border-white/10 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                @unless ($me->role === \App\Enums\Role::Staff)
                    <x-language-switch />
                @else
                    <span></span>
                @endunless
                <form method="POST" action="{{ route('logout') }}" data-confirm="{{ __('Anda akan keluar dari aplikasi. Pekerjaan yang sudah tersimpan tetap aman.') }}" data-confirm-title="{{ __('Keluar dari aplikasi?') }}" data-confirm-ok="{{ __('Keluar') }}" data-confirm-tone="primary">
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
            // Didelegasikan: tombol Menu di navigasi bawah ditulis sesudah skrip ini.
            document.addEventListener('click', (event) => { if (event.target.closest('[data-mobile-menu-open]')) menu.showModal(); });
            menu.querySelector('[data-mobile-menu-close]').addEventListener('click', () => menu.close());
            menu.addEventListener('click', (event) => { if (event.target === menu) menu.close(); });
            // Layar diperlebar (HP diputar / jendela dibesarkan): menu HP ditutup karena baris menu biasa tampil.
            window.matchMedia('(min-width: 768px)').addEventListener('change', (event) => { if (event.matches) menu.close(); });
        })();
    </script>

    {{-- Navigasi bawah HP (< 768 px): pekerjaan harian dalam satu ketukan; "Menu" membuka panel ☰ yang sama. Tidak tampil di layar obrolan,
         di dalam panel / form obrolan, dan di halaman yang punya bilah bawah sendiri (data-bottom-bar, lihat app.css). --}}
    <?php
        $icons = [
            'checklist' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>',
            'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
            'issue' => '<path d="M14.7 6.3a4 4 0 00-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 005.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
            'request' => '<path d="M4 18h16M5 18a7 7 0 0114 0M12 8V6M10 6h4"/>',
            'chat' => '<path d="M4 5.5A2.5 2.5 0 016.5 3h11A2.5 2.5 0 0120 5.5v8a2.5 2.5 0 01-2.5 2.5H10l-4.5 4v-4A1.5 1.5 0 014 14.5z"/>',
        ];
        $bottomNav = array_values(array_filter([
            $can('fill-checklists') ? ['label' => __('Checklist'), 'route' => 'fill.create', 'active' => $is('fill.*', 'checklists.*'), 'icon' => 'checklist', 'count' => 0]
                : ($can('view-checklists') ? ['label' => __('Dashboard'), 'route' => 'dashboard', 'active' => $is('dashboard'), 'icon' => 'dashboard', 'count' => 0] : null),
            $canIssues ? ['label' => __('Kendala'), 'route' => 'kamar.issues.index', 'active' => $is('kamar.*'), 'icon' => 'issue', 'count' => $newIssues] : null,
            $canRequests ? ['label' => __('Permintaan'), 'route' => 'permintaan.index', 'active' => $is('permintaan.*'), 'icon' => 'request', 'count' => $newRequests] : null,
            $can('chat') ? ['label' => __('Obrolan'), 'route' => 'chat.index', 'active' => $is('chat.*'), 'icon' => 'chat', 'count' => $unreadChats] : null,
        ]));
        $showBottomNav = ! $fullHeight && ! $chatTool && ! $is('chat.*');
    ?>
    @if ($showBottomNav)
        <nav data-bottom-nav aria-label="{{ __('Navigasi cepat') }}" class="fixed inset-x-0 bottom-0 z-40 grid border-t border-slate-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden print:hidden" style="grid-template-columns: repeat({{ count($bottomNav) + 1 }}, minmax(0, 1fr))">
            @foreach ($bottomNav as $item)
                <a href="{{ route($item['route']) }}" @if ($item['active']) aria-current="page" @endif @if ($item['icon'] === 'chat') data-chat-open @endif
                   class="relative flex min-h-14 flex-col items-center justify-center gap-0.5 px-1 text-[11px] {{ $item['active'] ? 'font-semibold text-gold-700' : 'text-slate-500' }}">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="{{ $item['active'] ? '2.2' : '1.8' }}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons[$item['icon']] !!}</svg>
                    <span class="max-w-full truncate">{{ $item['label'] }}</span>
                    <span class="absolute left-1/2 top-1 ml-1.5" @if ($item['icon'] === 'chat') data-chat-unread @endif @if (! $item['count']) hidden @endif>
                        <span class="inline-flex min-w-5 items-center justify-center rounded-full bg-rose-600 px-1.5 text-[11px] font-semibold leading-5 text-white" data-count>{{ $item['count'] > 99 ? '99+' : $item['count'] }}</span>
                    </span>
                </a>
            @endforeach
            <button type="button" data-mobile-menu-open aria-haspopup="dialog" class="flex min-h-14 flex-col items-center justify-center gap-0.5 px-1 text-[11px] text-slate-500">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                <span>{{ __('Menu') }}</span>
            </button>
        </nav>
    @endif
@endauth
{{-- Tombol obrolan melayang tampil: isi halaman diberi ruang bawah supaya tidak tertutup tombol itu. --}}
@php($chatFab = auth()->check() && \Illuminate\Support\Facades\Gate::allows('chat') && ! request()->routeIs('chat.*'))
<main @class(['mx-auto w-full max-w-6xl', 'px-4 py-6 sm:px-6' => ! $fullHeight, 'pb-24' => ($chatFab || ($showBottomNav ?? false)) && ! $fullHeight, 'flex min-h-0 flex-1 flex-col md:px-6 md:py-4' => $fullHeight])>
    {{-- Pesan sukses dari server tampil sebagai toast (resources/js/ui.js). Teks tetap ada di halaman bila JavaScript mati. --}}
    @if (session('status') && ! $fullHeight)
        <div data-flash="{{ session('status') }}" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    {{ $slot }}
</main>

<div class="toasts print:hidden" data-toasts role="status" aria-live="polite"></div>

<dialog id="confirm-dialog" aria-labelledby="confirm-title" class="dialog-panel w-[min(26rem,calc(100vw-2rem))]">
    <div class="space-y-4 p-6">
        <h2 id="confirm-title" class="text-section" data-confirm-title>{{ __('Yakin?') }}</h2>
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
        const title = dialog.querySelector('[data-confirm-title]');
        const ok = dialog.querySelector('[data-confirm-ok]');
        const defaults = { title: title.textContent, ok: ok.textContent };
        let pending = null;

        // Judul, teks tombol, dan warna tombol bisa diatur per formulir / tombol: data-confirm-title, data-confirm-ok,
        // data-confirm-tone="primary" (emas, untuk aksi yang tidak berbahaya; bawaan merah).
        const setup = (options = {}) => {
            title.textContent = options.confirmTitle || defaults.title;
            ok.textContent = options.confirmOk || defaults.ok;
            ok.className = ok.className.replace(/\bbtn-(danger|primary)\b/, options.confirmTone === 'primary' ? 'btn-primary' : 'btn-danger');
        };

        const ask = (element, text) => {
            pending = element;
            setup(element.dataset);
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
        window.askConfirm = (text, options = {}) => new Promise((resolve) => {
            answer = resolve;
            setup({ confirmTitle: options.title, confirmOk: options.ok, confirmTone: options.tone });
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

<dialog id="photo-viewer" class="m-auto max-h-[90vh] max-w-[min(56rem,calc(100vw-2rem))] bg-transparent p-0 backdrop:bg-ink/80">
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
                        class="fixed right-4 z-40 grid h-14 w-14 place-items-center rounded-full bg-gold-600 text-white shadow-lg ring-4 ring-white/70 transition hover:bg-gold-700 focus:outline-none focus-visible:ring-gold-400 bottom-[max(1rem,env(safe-area-inset-bottom))] sm:right-6">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 016.5 3h11A2.5 2.5 0 0120 5.5v8a2.5 2.5 0 01-2.5 2.5H10l-4.5 4v-4A1.5 1.5 0 014 14.5v-9z" stroke-linejoin="round"/><path d="M8 8h8M8 11.5h5" stroke-linecap="round"/></svg>
                    <span data-chat-fab-badge @if (! $unreadChats) hidden @endif class="absolute -right-1 -top-1 min-w-5 rounded-full border-2 border-white bg-rose-600 px-1 text-[11px] font-semibold leading-4 text-white">{{ $unreadChats > 99 ? '99+' : $unreadChats }}</span>
                </button>
                <section id="chat-panel" hidden data-chat-panel role="dialog" aria-label="{{ __('Obrolan') }}"
                         class="fixed z-50 flex flex-col overflow-hidden bg-white shadow-2xl max-sm:hidden sm:bottom-24 sm:right-6 sm:h-[min(42rem,calc(100dvh-8rem))] sm:w-[25rem] sm:rounded-2xl sm:ring-1 sm:ring-black/10">
                    <div class="flex shrink-0 items-center justify-between gap-2 bg-ink px-3 py-2 pt-[max(0.5rem,env(safe-area-inset-top))] text-white">
                        <span class="flex items-center gap-2 pl-1 text-sm font-semibold">
                            <svg class="h-4 w-4 text-gold-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 016.5 3h11A2.5 2.5 0 0120 5.5v8a2.5 2.5 0 01-2.5 2.5H10l-4.5 4v-4A1.5 1.5 0 014 14.5v-9z" stroke-linejoin="round"/></svg>
                            {{ __('Obrolan') }}
                        </span>
                        <span class="flex items-center gap-1">
                            <button type="button" data-chat-close class="grid h-8 w-8 place-items-center rounded-full text-xl leading-none text-white/80 hover:bg-white/10" aria-label="{{ __('Tutup obrolan') }}">×</button>
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
