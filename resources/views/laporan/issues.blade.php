<x-app-layout title="{{ __('Masalah & Pending') }}">
    @php
        $followLabels = ['open' => __('Terbuka'), 'handling' => __('Ditangani'), 'resolved' => __('Selesai')];
        $followTones = ['open' => 'bg-rose-50 text-rose-700', 'handling' => 'bg-amber-50 text-amber-800', 'resolved' => 'bg-emerald-50 text-emerald-800'];
        $openCount = collect($open)->where('auto', false)->where('status', '!=', 'resolved')->count();
        $pendingTotal = collect($ranked)->sum('pending');
        $dateParams = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        $searchNote = $search ? __('Tidak ada yang cocok dengan “:q”.', ['q' => $search]) : null;
        $noResults = $search && ! $open && ! $ranked && ! $chronic && ! $reasons;
    @endphp

    @include('laporan.partials.head', ['picker' => true, 'heading' => __('Masalah & Pending'), 'action' => route('laporan.issues'), 'keep' => ['department_id' => $departmentId, 'q' => $search]])

    {{-- Ringkasan angka: langsung terbaca sebelum masuk ke daftar --}}
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <a href="#terbuka" class="card p-4 transition hover:border-amber-300">
            <p class="text-sm text-slate-500">{{ __('Masalah terbuka') }}</p>
            <p class="mt-1 font-display text-3xl tabular-nums {{ $openCount ? 'text-rose-700' : 'text-slate-900' }}">{{ $openCount }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('Belum ditangani atau belum selesai') }}</p>
        </a>
        <a href="#bermasalah" class="card p-4 transition hover:border-amber-300">
            <p class="text-sm text-slate-500">{{ __('Tugas bermasalah') }}</p>
            <p class="mt-1 font-display text-3xl tabular-nums text-slate-900">{{ count($ranked) }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ $pendingTotal }} {{ __('Pending di antaranya') }}</p>
        </a>
        <a href="#berulang" class="card p-4 transition hover:border-amber-300">
            <p class="text-sm text-slate-500">{{ __('Pending berulang') }}</p>
            <p class="mt-1 font-display text-3xl tabular-nums {{ count($chronic) ? 'text-amber-800' : 'text-slate-900' }}">{{ count($chronic) }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('Staff dan tugas yang sama, berulang') }}</p>
        </a>
        <a href="#alasan" class="card p-4 transition hover:border-amber-300">
            <p class="text-sm text-slate-500">{{ __('Alasan tercatat') }}</p>
            <p class="mt-1 font-display text-3xl tabular-nums text-slate-900">{{ count($reasons) }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('Maksimal 100 terbaru') }}</p>
        </a>
    </div>

    {{-- Saring: departemen dan pencarian. Pilihan departemen langsung diterapkan. --}}
    <form method="GET" action="{{ route('laporan.issues') }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_auto] sm:items-end print:hidden">
        <input type="hidden" name="from" value="{{ $from->toDateString() }}">
        <input type="hidden" name="to" value="{{ $to->toDateString() }}">
        <div>
            <label for="issues-department" class="label">{{ __('Departemen') }}</label>
            <select id="issues-department" name="department_id" class="input w-full" onchange="this.form.submit()">
                <option value="">{{ __('Semua departemen') }}</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected($departmentId === $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="issues-search" class="label">{{ __('Cari tugas, alasan, atau staff') }}</label>
            <input id="issues-search" type="search" name="q" value="{{ $search }}" class="input w-full" maxlength="100" placeholder="{{ __('mis. AC, listrik, nama staff') }}">
        </div>
        <div class="flex gap-2">
            <button class="btn-primary flex-1 sm:flex-none">{{ __('Cari') }}</button>
        </div>
    </form>

    @if ($noResults)
        <div class="card mb-6 p-10 text-center">
            <svg class="mx-auto mb-3 text-slate-300" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <p class="font-display text-xl text-slate-900">{{ __('Tidak ditemukan') }}</p>
            <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">{{ __('Tidak ada tugas, alasan, atau staff yang cocok dengan “:q” pada rentang ini. Periksa ejaannya, coba kata yang lebih pendek, atau perluas rentang tanggal.', ['q' => $search]) }}</p>
        </div>
    @else
    {{-- Tab: satu bagian tampil sekali supaya halaman tidak memanjang. Tanpa JavaScript semua bagian tetap tampil berurutan. --}}
    <div class="mb-5 overflow-x-auto print:hidden" role="tablist" aria-label="{{ __('Bagian laporan') }}">
        <div class="flex gap-2 pb-1">
            @foreach ([['terbuka', __('Masalah terbuka'), $openCount], ['bermasalah', __('Tugas bermasalah'), count($ranked)], ['berulang', __('Pending berulang'), count($chronic)], ['peta', __('Peta panas'), null], ['alasan', __('Alasan Pending'), count($reasons)]] as [$key, $label, $total])
                <a href="#{{ $key }}" role="tab" data-tab="{{ $key }}" class="shrink-0 rounded-full border border-slate-200 bg-white px-4 py-1.5 text-sm text-slate-700 hover:border-amber-300 hover:bg-amber-50">{{ $label }}@if ($total !== null) <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs tabular-nums text-slate-600">{{ $total }}</span>@endif</a>
            @endforeach
        </div>
    </div>

    {{-- 1. Masalah terbuka: yang perlu ditindaklanjuti --}}
    <section id="terbuka" data-tab-panel="terbuka" class="card mb-6 overflow-hidden">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 p-5">
            <div>
                <h2 class="font-display text-xl text-slate-900">{{ __('Masalah terbuka') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('Pending yang belum selesai, tertua dulu. Pending yang esoknya sudah Done ditandai selesai otomatis.') }}</p>
            </div>
            <a class="btn-secondary print:hidden" href="{{ route('laporan.issues', $dateParams + ['department_id' => $departmentId, 'q' => $search, 'all' => $showAll ? null : 1]) }}">{{ $showAll ? __('Sembunyikan yang selesai') : __('Tampilkan yang selesai') }}</a>
        </div>

        @forelse ($open as $issue)
            <article data-reveal-item class="border-b border-slate-100 p-5 last:border-0">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-medium text-slate-900">{{ $issue['title'] }}</p>
                        <p class="mt-0.5 text-sm text-slate-500">
                            {{ $issue['employee'] }} · {{ $issue['department'] }} · {{ \Carbon\CarbonImmutable::parse($issue['date'])->translatedFormat('d M Y') }}
                            @if (! $issue['auto'] && $issue['status'] !== 'resolved')
                                · <span class="{{ $issue['age'] >= 3 ? 'font-semibold text-rose-700' : '' }}">{{ $issue['age'] }} {{ __('hari lalu') }}</span>
                            @endif
                        </p>
                    </div>
                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold {{ $issue['auto'] || $issue['status'] === 'resolved' ? $followTones['resolved'] : $followTones[$issue['status']] }}">
                        {{ $issue['auto'] ? __('Selesai otomatis') : $followLabels[$issue['status']] }}
                    </span>
                </div>

                @if ($issue['reason'])
                    <p class="mt-3 rounded-xl bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700"><span class="font-medium text-slate-500">{{ __('Alasan') }}:</span> {{ $issue['reason'] }}</p>
                @endif
                @if ($issue['note'])
                    <p class="mt-2 text-sm text-slate-600"><span class="font-medium text-slate-500">{{ __('Tindak lanjut') }}:</span> {{ $issue['note'] }}</p>
                @endif

                @if ($canFollowUp && ! $issue['auto'] && $issue['status'] !== 'resolved')
                    <form method="POST" action="{{ route('laporan.followup', $issue['item_id']) }}" class="mt-3 grid gap-2 sm:grid-cols-[10rem_minmax(0,1fr)_auto] print:hidden">
                        @csrf
                        <label class="sr-only" for="status-{{ $issue['item_id'] }}">{{ __('Status') }}</label>
                        <select id="status-{{ $issue['item_id'] }}" name="status" class="input">
                            @foreach ($followLabels as $value => $label)<option value="{{ $value }}" @selected($issue['status'] === $value)>{{ $label }}</option>@endforeach
                        </select>
                        <label class="sr-only" for="note-{{ $issue['item_id'] }}">{{ __('Catatan penanganan') }}</label>
                        <input id="note-{{ $issue['item_id'] }}" type="text" name="note" value="{{ $issue['note'] }}" maxlength="1000" placeholder="{{ __('Apa yang sudah dilakukan? mis. teknisi sudah dipanggil') }}" class="input">
                        <button class="btn-secondary">{{ __('Simpan') }}</button>
                    </form>
                @elseif ($canFollowUp && $issue['status'] === 'resolved' && ! $issue['auto'])
                    <p class="mt-3 text-sm text-emerald-800">{{ __('Sudah selesai.') }} {{ $issue['note'] }}</p>
                @endif
            </article>
        @empty
            <div class="p-8 text-center">
                <p class="font-medium text-slate-800">{{ __('Tidak ada masalah terbuka.') }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ $searchNote ?? __('Semua Pending pada rentang dan filter ini sudah ditangani.') }}</p>
            </div>
        @endforelse
    </section>

    {{-- 2. Tugas bermasalah: tugas mana yang paling sering bermasalah --}}
    <section id="bermasalah" data-tab-panel="bermasalah" class="card mb-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5">
            <h2 class="font-display text-xl text-slate-900">{{ __('Tugas paling bermasalah') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('Bermasalah = Pending, Ongoing, atau kosong. Ketuk tugas untuk melihat setiap kejadiannya.') }}</p>
        </div>

        @forelse ($ranked as $row)
            <a data-reveal-item href="{{ route('laporan.task', $dateParams + ['department_id' => $row['department_id'], 'title' => $row['title']]) }}"
                class="flex flex-col gap-3 border-b border-slate-100 p-5 transition last:border-0 hover:bg-amber-50/50 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0 sm:max-w-[45%]">
                    <p class="font-medium text-slate-900">{{ $row['title'] }}</p>
                    <p class="mt-0.5 text-sm text-slate-500">{{ $row['department'] }} · {{ $row['positions'] }}</p>
                </div>
                <div class="flex flex-1 flex-wrap items-center gap-x-5 gap-y-2 text-sm sm:justify-end">
                    <span><span class="font-semibold tabular-nums text-rose-700">{{ $row['pending'] }}</span> <span class="text-slate-500">Pending</span></span>
                    <span><span class="font-semibold tabular-nums">{{ $row['ongoing'] }}</span> <span class="text-slate-500">Ongoing</span></span>
                    <span><span class="font-semibold tabular-nums">{{ $row['blank'] }}</span> <span class="text-slate-500">{{ __('kosong') }}</span></span>
                    <span class="text-slate-500">{{ $row['pendingStaff'] }} {{ __('staff') }}</span>
                    <span class="flex w-32 items-center gap-2" title="{{ $row['problemRate'] }}% {{ __('dari :n isian', ['n' => $row['total']]) }}">
                        <span class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full bg-rose-500" style="width: {{ min(100, $row['problemRate']) }}%"></span></span>
                        <span class="w-10 text-right tabular-nums text-slate-700">{{ $row['problemRate'] }}%</span>
                    </span>
                </div>
            </a>
        @empty
            <div class="p-8 text-center">
                <p class="font-medium text-slate-800">{{ __('Tidak ada tugas bermasalah.') }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ $searchNote ?? __('Coba perluas rentang tanggal atau ubah filter.') }}</p>
            </div>
        @endforelse
    </section>

    {{-- 3. Pending berulang --}}
    <section id="berulang" data-tab-panel="berulang" class="card mb-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5">
            <h2 class="font-display text-xl text-slate-900">{{ __('Pending berulang') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('Tugas yang sama dan staff yang sama, Pending :streak hari berturut-turut atau :count kali atau lebih.', ['streak' => $streak, 'count' => $count]) }}</p>
        </div>

        @forelse ($chronic as $row)
            <div data-reveal-item class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5 last:border-0">
                <div class="min-w-0">
                    <p class="font-medium text-slate-900">{{ $row['title'] }}</p>
                    <p class="mt-0.5 text-sm text-slate-500">{{ $row['employee'] }} · {{ $row['department'] }} · {{ __('terakhir') }} {{ \Carbon\CarbonImmutable::parse($row['last'])->translatedFormat('d M Y') }}</p>
                </div>
                <div class="flex gap-2 text-sm">
                    <span class="rounded-full bg-amber-50 px-3 py-1 font-semibold text-amber-800">{{ $row['streak'] }} {{ __('hari berturut-turut') }}</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-700">{{ $row['times'] }}×</span>
                </div>
            </div>
        @empty
            <div class="p-8 text-center">
                <p class="font-medium text-slate-800">{{ __('Tidak ada Pending berulang.') }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ $searchNote ?? __('Bagus, tidak ada tugas yang terus-menerus Pending.') }}</p>
            </div>
        @endforelse
    </section>

    {{-- 4. Peta panas: kapan masalah sering muncul --}}
    <section id="peta" data-tab-panel="peta" class="card mb-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5">
            <h2 class="font-display text-xl text-slate-900">{{ __('Peta panas: Pending per hari') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('Persentase tugas Pending per departemen dan hari dalam seminggu. Makin gelap, makin sering bermasalah.') }}</p>
        </div>
        @if ($heatmap['rows'])
            <div class="overflow-x-auto p-5">
                <table class="w-full min-w-[32rem] text-center text-sm">
                    <thead><tr><th class="pb-2 text-left font-normal text-slate-500">{{ __('Departemen') }}</th>@foreach ($heatmap['labels'] as $label)<th class="pb-2 font-normal text-slate-500">{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($heatmap['rows'] as $row)
                            <tr>
                                <td class="py-1 pr-3 text-left text-slate-800">{{ $row['name'] }}</td>
                                @foreach ($row['cells'] as $cell)
                                    <td class="p-1"><div class="rounded-lg px-2 py-2 tabular-nums" style="background: rgba(190, 18, 60, {{ $cell['rate'] === null ? 0 : min(0.85, 0.08 + $cell['rate'] / 100 * 2.5) }}); color: {{ ($cell['rate'] ?? 0) >= 25 ? '#fff' : '#334155' }}" title="{{ $cell['pending'] }} / {{ $cell['items'] }} Pending">{{ $cell['rate'] === null ? '—' : $cell['rate'].'%' }}</div></td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="p-8 text-center"><p class="text-sm text-slate-500">{{ __('Belum ada data pada rentang ini.') }}</p></div>
        @endif
    </section>

    {{-- 5. Alasan Pending --}}
    <section id="alasan" data-tab-panel="alasan" class="card mb-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5">
            <h2 class="font-display text-xl text-slate-900">{{ __('Alasan Pending') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('100 alasan terbaru. Gunakan kotak cari di atas untuk menyaring.') }}</p>
        </div>
        @forelse ($reasons as $reason)
            <div data-reveal-item class="border-b border-slate-100 px-5 py-3.5 last:border-0">
                <p class="text-sm text-slate-800">"{{ $reason['note'] }}"</p>
                <p class="mt-1 text-xs text-slate-500">
                    <a class="font-medium text-slate-700 underline decoration-slate-300 underline-offset-2 hover:text-slate-900" href="{{ route('checklists.show', $reason['checklist_id']) }}">{{ $reason['title'] }}</a>
                    · {{ $reason['employee'] }} · {{ $reason['department'] }} · {{ \Carbon\CarbonImmutable::parse($reason['date'])->translatedFormat('d M Y') }}
                </p>
            </div>
        @empty
            <div class="p-8 text-center"><p class="text-sm text-slate-500">{{ $searchNote ?? __('Tidak ada alasan Pending yang cocok.') }}</p></div>
        @endforelse
    </section>

    @endif

    <script>
        (() => {
            const STEP = 10;
            const tabs = [...document.querySelectorAll('[data-tab]')];
            const panels = [...document.querySelectorAll('[data-tab-panel]')];
            if (!panels.length) return;
            const active = 'border-amber-700 bg-amber-50 font-semibold text-amber-900';
            const idle = 'border-slate-200 bg-white text-slate-700';
            const shown = new Map(panels.map((panel) => [panel, STEP]));
            const labels = { more: @json(__('Tampilkan :n lagi')), left: @json(__('tersisa')) };

            // Tombol "Tampilkan lagi" otomatis ditekan saat terlihat di layar (scroll sampai bawah); tombolnya tetap ada sebagai cadangan.
            const observer = 'IntersectionObserver' in window
                ? new IntersectionObserver((entries) => entries.forEach((entry) => {
                    if (!entry.isIntersecting) return;
                    const panel = entry.target.parentElement;
                    shown.set(panel, shown.get(panel) + STEP);
                    reveal(panel);
                }), { rootMargin: '240px' })
                : null;

            // Tiap daftar menampilkan 10 baris dulu; tombol (atau scroll sampai bawah) menambah 10 baris lagi.
            const reveal = (panel, all = false) => {
                const items = [...panel.querySelectorAll('[data-reveal-item]')];
                const limit = all ? items.length : shown.get(panel);
                items.forEach((item, index) => { item.hidden = index >= limit; });
                let more = panel.querySelector('[data-reveal-more]');
                const remaining = Math.max(0, items.length - limit);
                if (!remaining) {
                    if (more) { observer?.unobserve(more); more.remove(); }
                    return;
                }
                if (!more) {
                    more = document.createElement('button');
                    more.type = 'button';
                    more.dataset.revealMore = '';
                    more.className = 'block w-full border-t border-slate-100 bg-slate-50 px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-amber-50 print:hidden';
                    more.addEventListener('click', () => { shown.set(panel, shown.get(panel) + STEP); reveal(panel); });
                    panel.append(more);
                }
                more.textContent = `${labels.more.replace(':n', Math.min(STEP, remaining))} · ${remaining} ${labels.left}`;
                // Amati ulang supaya halaman yang masih pendek tetap memuat lagi sampai layar penuh.
                if (observer) { observer.unobserve(more); observer.observe(more); }
            };

            const activate = (key) => {
                if (!panels.some((panel) => panel.dataset.tabPanel === key)) key = panels[0].dataset.tabPanel;
                panels.forEach((panel) => { panel.hidden = panel.dataset.tabPanel !== key; });
                tabs.forEach((tab) => {
                    const on = tab.dataset.tab === key;
                    tab.setAttribute('aria-selected', String(on));
                    tab.classList.remove(...(on ? idle : active).split(' '));
                    tab.classList.add(...(on ? active : idle).split(' '));
                });
            };

            panels.forEach((panel) => reveal(panel));
            activate(location.hash.slice(1));

            const go = (key) => { history.replaceState(null, '', '#' + key); activate(key); };
            tabs.forEach((tab) => tab.addEventListener('click', (event) => { event.preventDefault(); go(tab.dataset.tab); }));
            document.querySelectorAll('a[href^="#"]').forEach((link) => {
                const key = link.getAttribute('href').slice(1);
                if (!link.dataset.tab && panels.some((panel) => panel.dataset.tabPanel === key)) {
                    link.addEventListener('click', (event) => { event.preventDefault(); go(key); });
                }
            });
            window.addEventListener('hashchange', () => activate(location.hash.slice(1)));

            // Cetak: semua bagian dan semua baris ikut tercetak.
            window.addEventListener('beforeprint', () => { panels.forEach((panel) => { panel.hidden = false; reveal(panel, true); }); });
            window.addEventListener('afterprint', () => { panels.forEach((panel) => reveal(panel)); activate(location.hash.slice(1)); });
        })();
    </script>
</x-app-layout>
