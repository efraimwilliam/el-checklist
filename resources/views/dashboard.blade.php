<x-app-layout title="{{ __('Dashboard') }}">
    @php
        $palette = ['done' => '#2d5a3f', 'ongoing' => '#c9a24f', 'pending' => '#a8341f', 'blank' => '#e3dccf'];
        $scoreTone = fn (?int $score) => $score === null ? 'text-slate-400' : ($score >= 90 ? 'text-emerald-800' : ($score >= 70 ? 'text-amber-800' : 'text-rose-700'));
        $scoreFill = fn (?int $score) => $score === null ? '#e3dccf' : ($score >= 90 ? '#2d5a3f' : ($score >= 70 ? '#c9a24f' : '#a8341f'));
        $dist = $distribution;
        $distTotal = array_sum($dist);
        $date = $to;
    @endphp

    <div class="mb-5">
        <h1 class="text-3xl text-slate-900">{{ __('Dashboard') }}</h1>
        <p class="text-sm text-slate-500">{{ __('Progres checklist per departemen, tren, dan hal yang perlu perhatian.') }}</p>

        <form method="GET" action="{{ route('dashboard') }}" class="mt-4" data-dashboard-filter>
            <div class="relative w-max max-w-full" data-range-field data-max="{{ $today->toDateString() }}">
                <input type="hidden" name="from" value="{{ $from->toDateString() }}" data-range-from>
                <input type="hidden" name="to" value="{{ $to->toDateString() }}" data-range-to>
                <button type="button" class="input flex min-w-64 items-center gap-3 text-left" data-range-trigger aria-haspopup="dialog" aria-expanded="false"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8a6a2a" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg><span class="font-medium text-slate-800" data-range-label>{{ \App\Support\DateRange::label($from, $to) }}</span></button>
                <div class="cal-panel hidden" data-range-panel role="dialog" aria-label="{{ __('Pilih rentang tanggal') }}"></div>
            </div>
        </form>
    </div>

    <div id="dashboard-body" class="transition-opacity">
    {{-- Ringkasan periode --}}
    <p class="mb-2 text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">
        {{ \App\Support\DateRange::label($from, $to) }} · {{ $days }} hari
    </p>
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4">
            <p class="text-sm text-slate-500">{{ __('Rata-rata skor') }}</p>
            <p class="mt-1 font-display text-3xl tabular-nums {{ $scoreTone($kpi['score']) }}">{{ $kpi['score'] !== null ? $kpi['score'].'%' : '—' }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('Persentase tugas Done') }}</p>
        </div>
        <div class="card p-4">
            <p class="text-sm text-slate-500">{{ __('Checklist') }}</p>
            <p class="mt-1 font-display text-3xl tabular-nums text-slate-900">{{ $kpi['checklists'] }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('Dibuat dalam periode') }}</p>
        </div>
        <div class="card p-4">
            <p class="text-sm text-slate-500">{{ __('Tugas Pending') }}</p>
            <p class="mt-1 font-display text-3xl tabular-nums text-rose-700">{{ $kpi['pending'] }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ $kpi['ongoing'] }} Ongoing</p>
        </div>
        <div class="card p-4">
            <p class="text-sm text-slate-500">{{ __('Belum direview') }}</p>
            <p class="mt-1 font-display text-3xl tabular-nums text-amber-800">{{ $kpi['unreviewed'] }}</p>
            <p class="mt-1 text-xs text-slate-500"><a href="{{ route('checklists.index', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'status' => 'saved']) }}" class="font-semibold text-sky-700 hover:underline">{{ __('Lihat di Riwayat') }}</a></p>
        </div>
    </div>

    {{-- Status departemen --}}
    <section class="card mb-6 p-5">
        <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-display text-2xl text-slate-900">{{ __('Status departemen') }}</h2>
            <p class="text-sm text-slate-500">{{ __('Jabatan dicek & status isi dalam rentang') }} {{ $from->translatedFormat('d M') }} – {{ $to->translatedFormat('d M Y') }}</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @forelse ($departments as $department)
                @php
                    $pill = match ($department['state']) { 'full' => 'pill-done', 'partial' => 'pill-ongoing', default => 'pill-pending' };
                    $label = $department['state'] === 'none' ? __('Belum isi') : $department['positions_checked'].'/'.$department['positions_total'].' jabatan';
                @endphp
                <a href="{{ route('checklists.index', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'department' => $department['id']]) }}" class="block rounded-2xl border border-slate-200 bg-[#fdfaf3] p-4 transition hover:border-gold-500">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="font-display text-lg leading-snug text-slate-900">{{ $department['name'] }}</h3>
                        <span class="{{ $pill }} shrink-0">{{ $label }}</span>
                    </div>
                    <p class="text-xs text-slate-500">{{ $department['last_date'] ? __('Terakhir').' '.$department['last_date']->translatedFormat('d M Y') : __('Belum pernah diisi') }}</p>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100" role="img" aria-label="Skor {{ $department['score'] ?? 0 }} persen">
                        <div class="h-full rounded-full" style="width: {{ $department['score'] ?? 0 }}%; background: {{ $scoreFill($department['score']) }}"></div>
                    </div>
                    <p class="mt-2 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-slate-600">
                        <span>{{ __('Skor') }} <strong class="{{ $scoreTone($department['score']) }}">{{ $department['score'] !== null ? $department['score'].'%' : '—' }}</strong></span>
                        <span>{{ $department['checklists'] }} checklist</span>
                        <span class="text-rose-700">{{ $department['pending'] }} pending</span>
                    </p>
                </a>
            @empty
                <p class="text-sm text-slate-500">{{ __('Belum ada departemen yang bisa Anda lihat.') }}</p>
            @endforelse
        </div>
    </section>

    <div class="mb-6 grid gap-6 lg:grid-cols-3">
        {{-- Tren skor harian --}}
        <section class="card p-5 lg:col-span-2">
            <h2 class="font-display text-xl text-slate-900">{{ __('Tren skor harian') }}</h2>
            <p class="mb-3 text-sm text-slate-500">{{ __('Persentase tugas Done per hari. Hari tanpa checklist dilewati.') }}</p>
            @php
                $w = 640; $h = 220; $padL = 34; $padR = 12; $padT = 12; $padB = 28;
                $plotW = $w - $padL - $padR; $plotH = $h - $padT - $padB;
                $n = count($trend);
                $xAt = fn (int $i) => $padL + ($n > 1 ? $i * $plotW / ($n - 1) : $plotW / 2);
                $yAt = fn (int $score) => $padT + $plotH * (1 - $score / 100);
                $path = ''; $gap = true; $points = [];
                foreach ($trend as $i => $day) {
                    if ($day['score'] === null) { $gap = true; continue; }
                    $path .= ($gap ? 'M' : 'L').round($xAt($i), 1).' '.round($yAt($day['score']), 1).' ';
                    $gap = false;
                    $points[] = ['x' => $xAt($i), 'y' => $yAt($day['score']), 'day' => $day];
                }
                $hasData = count($points) > 0;
                $tickEvery = max(1, (int) ceil($n / 7));
            @endphp
            <svg viewBox="0 0 {{ $w }} {{ $h }}" class="w-full" role="img" aria-label="{{ __('Grafik tren skor harian') }}">
                @foreach ([0, 50, 100] as $tick)
                    <line x1="{{ $padL }}" x2="{{ $w - $padR }}" y1="{{ $yAt($tick) }}" y2="{{ $yAt($tick) }}" stroke="#eadfce" stroke-dasharray="{{ $tick === 0 ? '0' : '3 4' }}"/>
                    <text x="{{ $padL - 6 }}" y="{{ $yAt($tick) + 4 }}" text-anchor="end" font-size="11" fill="#7b6d61">{{ $tick }}%</text>
                @endforeach
                @foreach ($trend as $i => $day)
                    @if ($i % $tickEvery === 0 || $i === $n - 1)
                        <text x="{{ $xAt($i) }}" y="{{ $h - 8 }}" text-anchor="middle" font-size="11" fill="#7b6d61">{{ $day['date']->format('d/m') }}</text>
                    @endif
                @endforeach
                @if ($hasData)
                    <path d="{{ $path }}" fill="none" stroke="#b08a3e" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
                    @foreach ($points as $point)
                        <circle cx="{{ round($point['x'], 1) }}" cy="{{ round($point['y'], 1) }}" r="4" fill="#fff" stroke="#b08a3e" stroke-width="2">
                            <title>{{ $point['day']['date']->translatedFormat('d M Y') }}: skor {{ $point['day']['score'] }}% · {{ $point['day']['checklists'] }} checklist · {{ $point['day']['pending'] }} pending</title>
                        </circle>
                    @endforeach
                @else
                    <text x="{{ $w / 2 }}" y="{{ $h / 2 }}" text-anchor="middle" font-size="13" fill="#7b6d61">{{ __('Belum ada checklist dalam periode ini') }}</text>
                @endif
            </svg>
        </section>

        {{-- Sebaran status tugas --}}
        <section class="card p-5">
            <h2 class="font-display text-xl text-slate-900">{{ __('Status tugas') }}</h2>
            <p class="mb-3 text-sm text-slate-500">{{ __('Semua tugas dalam periode.') }}</p>
            @if ($distTotal > 0)
                @php $offset = 0; @endphp
                <div class="flex items-center gap-4">
                    <svg viewBox="0 0 42 42" class="h-36 w-36 shrink-0 -rotate-90" role="img" aria-label="{{ __('Diagram sebaran status tugas') }}">
                        <circle cx="21" cy="21" r="15.9155" fill="none" stroke="#f3ede3" stroke-width="6"/>
                        @foreach (['done', 'ongoing', 'pending', 'blank'] as $key)
                            @php $part = $dist[$key] / $distTotal * 100; @endphp
                            @if ($part > 0)
                                <circle cx="21" cy="21" r="15.9155" fill="none" stroke="{{ $palette[$key] }}" stroke-width="6" stroke-dasharray="{{ round($part, 2) }} {{ round(100 - $part, 2) }}" stroke-dashoffset="{{ round(-$offset, 2) }}"/>
                                @php $offset += $part; @endphp
                            @endif
                        @endforeach
                    </svg>
                    <ul class="space-y-1.5 text-sm">
                        @foreach (['done' => __('Done'), 'ongoing' => __('Ongoing'), 'pending' => __('Pending'), 'blank' => __('Belum diisi')] as $key => $label)
                            <li class="flex items-center gap-2">
                                <span class="h-2.5 w-2.5 rounded-full" style="background: {{ $palette[$key] }}"></span>
                                <span class="text-slate-600">{{ $label }}</span>
                                <strong class="ml-auto pl-3 tabular-nums text-slate-900">{{ $dist[$key] }}</strong>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @else
                <p class="py-8 text-center text-sm text-slate-500">{{ __('Belum ada data.') }}</p>
            @endif
        </section>
    </div>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        {{-- Skor per departemen --}}
        <section class="card p-5">
            <h2 class="font-display text-xl text-slate-900">{{ __('Skor per departemen') }}</h2>
            <p class="mb-4 text-sm text-slate-500">{{ __('Rata-rata dalam periode.') }}</p>
            <ul class="space-y-3">
                @forelse ($deptScores as $row)
                    <li>
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="font-medium text-slate-800">{{ $row['name'] }}</span>
                            <span class="tabular-nums"><strong class="{{ $scoreTone($row['score']) }}">{{ $row['score'] !== null ? $row['score'].'%' : '—' }}</strong> <span class="text-xs text-slate-500">· {{ $row['checklists'] }} checklist</span></span>
                        </div>
                        <div class="mt-1 h-2.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full" style="width: {{ $row['score'] ?? 0 }}%; background: {{ $scoreFill($row['score']) }}"></div>
                        </div>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-slate-500">{{ __('Belum ada data.') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- Tugas sering tertunda --}}
        <section class="card p-5">
            <h2 class="font-display text-xl text-slate-900">{{ __('Tugas sering tertunda') }}</h2>
            <p class="mb-4 text-sm text-slate-500">{{ __('Paling sering berstatus Pending dalam periode.') }}</p>
            @php $maxPending = max(1, collect($topPending)->max('pending') ?? 1); @endphp
            <ul class="space-y-3">
                @forelse ($topPending as $row)
                    <li>
                        <div class="flex items-start justify-between gap-3 text-sm">
                            <span class="min-w-0 text-slate-800">{{ $row['title'] }} <span class="text-xs text-slate-500">· {{ $row['department'] }}</span></span>
                            <span class="shrink-0 tabular-nums font-semibold text-rose-700">{{ $row['pending'] }}×</span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-[#a8341f]" style="width: {{ round($row['pending'] / $maxPending * 100) }}%"></div>
                        </div>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-slate-500">{{ __('Tidak ada tugas yang tertunda dalam periode ini.') }}</li>
                @endforelse
            </ul>
        </section>
    </div>

    {{-- Status review --}}
    <section class="card p-5">
        <h2 class="font-display text-xl text-slate-900">{{ __('Status review') }}</h2>
        <p class="mb-4 text-sm text-slate-500">{{ __('Checklist dalam periode berdasarkan tahap review.') }}</p>
        @php
            $reviewTotal = array_sum($reviews);
            $reviewRows = [
                'saved' => [__('Belum direview'), '#c9bba3'],
                'hod_reviewed' => [__('Direview oleh HOD'), '#c9a24f'],
                'reviewed' => [__('Direview oleh GM/HR'), '#2d5a3f'],
            ];
        @endphp
        @if ($reviewTotal > 0)
            <div class="flex h-4 overflow-hidden rounded-full bg-slate-100" role="img" aria-label="{{ __('Sebaran status review') }}">
                @foreach ($reviewRows as $key => [$label, $color])
                    @if ($reviews[$key] > 0)
                        <div title="{{ $label }}: {{ $reviews[$key] }}" style="width: {{ $reviews[$key] / $reviewTotal * 100 }}%; background: {{ $color }}"></div>
                    @endif
                @endforeach
            </div>
            <ul class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                @foreach ($reviewRows as $key => [$label, $color])
                    <li class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ $color }}"></span><span class="text-slate-600">{{ $label }}</span><strong class="tabular-nums text-slate-900">{{ $reviews[$key] }}</strong></li>
                @endforeach
            </ul>
        @else
            <p class="py-4 text-center text-sm text-slate-500">{{ __('Belum ada checklist dalam periode ini.') }}</p>
        @endif
    </section>

    {{-- Chronology Report: laporan kejadian tamu yang dibuat dalam periode (yang boleh dilihat pengguna ini). --}}
    <section class="card mt-6 p-5">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 class="font-display text-xl text-slate-900">{{ __('Chronology Report') }}</h2>
                <p class="text-sm text-slate-500">{{ __('Laporan kejadian tamu yang dibuat dalam periode.') }}</p>
            </div>
            <a href="{{ route('chronology.index') }}" class="text-sm font-semibold text-sky-700 hover:underline">{{ __('Buka Chronology Report') }} &rsaquo;</a>
        </div>

        @if ($chronology['total'] > 0)
            <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Laporan') }}</p><p class="mt-1 font-display text-2xl tabular-nums text-slate-900">{{ $chronology['total'] }}</p><p class="text-xs text-slate-500">{{ $chronology['final'] }} {{ __('final') }} · {{ $chronology['draft'] }} {{ __('draft') }}</p></div>
                <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Tamu') }}</p><p class="mt-1 font-display text-2xl tabular-nums text-slate-900">{{ $chronology['uniqueGuests'] }}</p><p class="text-xs text-slate-500">{{ $chronology['guests'] }} {{ __('entri tamu') }}</p></div>
                <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Rata-rata poin') }}</p><p class="mt-1 font-display text-2xl tabular-nums text-slate-900">{{ $chronology['entriesAverage'] !== null ? number_format($chronology['entriesAverage'], 1, ',', '.') : '—' }}</p><p class="text-xs text-slate-500">{{ __('per laporan') }}</p></div>
                <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Terkait kendala kamar') }}</p><p class="mt-1 font-display text-2xl tabular-nums text-slate-900">{{ $chronology['linkedIssues'] }}</p><p class="text-xs text-slate-500">{{ __('laporan') }}</p></div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                @foreach ([['sources', __('Sumber booking')], ['nationalities', __('Kewarganegaraan')], ['rooms', __('Kamar tamu')]] as [$key, $label])
                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-slate-700">{{ $label }}</h3>
                        @forelse ($chronology[$key] as $item)
                            @php $share = $chronology['total'] ? round($item['count'] / $chronology['total'] * 100) : 0; @endphp
                            <div class="mb-2">
                                <div class="flex justify-between gap-2 text-sm"><span class="truncate text-slate-800">{{ $item['label'] }}</span><span class="tabular-nums text-slate-500">{{ $item['count'] }}</span></div>
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-gold-500" style="width: {{ $share }}%"></div></div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">{{ __('Belum ada data.') }}</p>
                        @endforelse
                    </div>
                @endforeach
            </div>

            <div class="mt-6">
                <h3 class="mb-2 text-sm font-semibold text-slate-700">{{ __('Laporan terbaru') }}</h3>
                <ul class="divide-y divide-slate-100">
                    @foreach ($chronology['recent'] as $item)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                            <a href="{{ route('chronology.show', $item['id']) }}" class="min-w-0 font-medium text-slate-900 hover:underline">{{ $item['guest'] }}@if ($item['room']) <span class="font-normal text-slate-500">· {{ __('Kamar') }} {{ $item['room'] }}</span>@endif</a>
                            <span class="flex items-center gap-3 text-xs text-slate-500">
                                <span>{{ $item['author'] }} · {{ $item['date'] }}</span>
                                <span class="{{ $item['status'] === 'final' ? 'pill-done' : 'pill-ongoing' }}">{{ $item['status'] === 'final' ? __('Final') : __('Draft') }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @else
            <p class="py-4 text-center text-sm text-slate-500">{{ __('Belum ada Chronology Report dalam periode ini.') }}</p>
        @endif
    </section>
    </div>

    <script>
        // Tanggal diganti: data dashboard diambil di latar lalu hanya bagian ringkasan yang diganti (tanpa muat ulang halaman).
        (() => {
            const form = document.querySelector('[data-dashboard-filter]');
            const body = document.getElementById('dashboard-body');
            let latest = 0;

            const load = async (url, push) => {
                const run = ++latest;
                body.classList.add('opacity-50', 'pointer-events-none');
                body.setAttribute('aria-busy', 'true');
                try {
                    const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin' });
                    if (!response.ok) throw new Error(String(response.status));
                    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                    const next = page.getElementById('dashboard-body');
                    if (!next) throw new Error('missing');
                    if (run !== latest) return; // permintaan yang lebih baru sudah jalan
                    body.innerHTML = next.innerHTML;
                    if (push) history.pushState(null, '', url);
                } catch (error) {
                    window.location.href = url; // gagal: muat halaman biasa
                } finally {
                    if (run === latest) {
                        body.classList.remove('opacity-50', 'pointer-events-none');
                        body.setAttribute('aria-busy', 'false');
                    }
                }
            };

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                load(`${form.action}?${new URLSearchParams(new FormData(form))}`, true);
            });

            window.addEventListener('popstate', () => load(location.href, false));
        })();
    </script>
</x-app-layout>
