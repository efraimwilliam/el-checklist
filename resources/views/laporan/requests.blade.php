<x-app-layout title="{{ __('Laporan permintaan tamu') }}">
    @php
        $kpi = $report['kpi'];
        $minutes = fn ($value) => $value === null ? '—' : $value.' '.__('mnt');
        $speedTone = fn ($value) => $value === null ? 'text-slate-400' : ($value <= $target ? 'text-emerald-700' : ($value <= $target * 2 ? 'text-amber-800' : 'text-rose-700'));
        $hourMax = max(1, collect($report['by_hour'])->max('count'));
        $dayMax = max(1, max($report['by_weekday']));
        $dayNames = [1 => __('Sen'), 2 => __('Sel'), 3 => __('Rab'), 4 => __('Kam'), 5 => __('Jum'), 6 => __('Sab'), 7 => __('Min')];
        $kindMax = max(1, collect($report['by_kind'])->max('count') ?? 1);
        $th = 'px-4 py-3';
    @endphp

    @include('laporan.partials.head', ['picker' => true, 'heading' => __('Permintaan tamu'), 'action' => route('laporan.requests'), 'keep' => ['department_id' => $departmentId]])

    @if ($departments->count() > 1)
        <form method="GET" action="{{ route('laporan.requests') }}" class="mb-5 print:hidden">
            <input type="hidden" name="from" value="{{ $from->toDateString() }}"><input type="hidden" name="to" value="{{ $to->toDateString() }}">
            <label class="text-sm text-slate-600">{{ __('Departemen tujuan') }}
                <select name="department_id" class="input mt-1" onchange="this.form.submit()">
                    <option value="">{{ __('Semua departemen') }}</option>
                    @foreach ($departments as $department)<option value="{{ $department->id }}" @selected($departmentId === $department->id)>{{ $department->name }}</option>@endforeach
                </select>
            </label>
        </form>
    @endif

    @if ($kpi['total'] === 0)
        <x-ui.empty-state :title="__('Belum ada permintaan pada rentang ini.')" :text="__('Permintaan dicatat dari menu Permintaan tamu atau diketik di obrolan, mis. 1206 req 2 mineral water.')">
            @can('guest-requests')<x-ui.button :href="route('permintaan.index')" variant="secondary">{{ __('Buka Permintaan tamu') }}</x-ui.button>@endcan
        </x-ui.empty-state>
    @else
        {{-- KPI --}}
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="card p-4">
                <p class="text-xs text-slate-500">{{ __('Permintaan') }}</p>
                <p class="mt-1 font-display text-3xl tabular-nums text-slate-900">{{ $kpi['total'] }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ $kpi['done'] }} {{ __('selesai') }} · {{ $kpi['open'] }} {{ __('belum') }} · {{ $kpi['urgent'] }} {{ __('mendesak') }}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs text-slate-500">{{ __('Rata-rata sampai diambil') }}</p>
                <p class="mt-1 font-display text-3xl tabular-nums {{ $speedTone($kpi['avg_claim']) }}">{{ $minutes($kpi['avg_claim']) }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ __('Target :n menit', ['n' => $target]) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs text-slate-500">{{ __('Diambil tepat waktu') }}</p>
                <p class="mt-1 font-display text-3xl tabular-nums {{ $kpi['on_time'] === null ? 'text-slate-400' : ($kpi['on_time'] >= 80 ? 'text-emerald-700' : ($kpi['on_time'] >= 60 ? 'text-amber-800' : 'text-rose-700')) }}">{{ $kpi['on_time'] !== null ? $kpi['on_time'].'%' : '—' }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ __('diambil ≤ :n menit setelah dicatat', ['n' => $target]) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs text-slate-500">{{ __('Rata-rata sampai selesai') }}</p>
                <p class="mt-1 font-display text-3xl tabular-nums text-slate-900">{{ $minutes($kpi['avg_done']) }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ __(':n dicatat dari ketik bebas di obrolan', ['n' => $kpi['from_chat']]) }}</p>
            </div>
        </div>

        {{-- Per jam: batang = jumlah permintaan, warna = rata-rata menit sampai diambil pada jam itu. --}}
        <section class="card mb-6 p-5">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 class="text-section">{{ __('Beban per jam') }}</h2>
                    <p class="text-sm text-slate-500">{{ __('Jam permintaan dicatat (WITA). Warna batang = kecepatan diambil pada jam itu.') }} @if ($report['peak_hour'] !== null)<b class="text-slate-700">{{ __('Paling sibuk: :h', ['h' => sprintf('%02d.00–%02d.00', $report['peak_hour'], ($report['peak_hour'] + 1) % 24)]) }}</b>@endif</p>
                </div>
                <p class="flex flex-wrap gap-3 text-xs text-slate-500">
                    <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-emerald-600"></span>≤ {{ $target }} {{ __('mnt') }}</span>
                    <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-gold-500"></span>≤ {{ $target * 2 }} {{ __('mnt') }}</span>
                    <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-rose-600"></span>{{ __('lebih lama') }}</span>
                </p>
            </div>
            <div class="overflow-x-auto">
                <div class="flex h-44 min-w-[36rem] items-end gap-1" role="img" aria-label="{{ __('Jumlah permintaan per jam') }}">
                    @foreach ($report['by_hour'] as $hour => $cell)
                        @php($tone = $cell['claim'] === null ? 'bg-slate-300' : ($cell['claim'] <= $target ? 'bg-emerald-600' : ($cell['claim'] <= $target * 2 ? 'bg-gold-500' : 'bg-rose-600')))
                        <div class="flex h-full flex-1 flex-col items-center justify-end gap-1" title="{{ sprintf('%02d.00', $hour) }} · {{ $cell['count'] }} {{ __('permintaan') }}{{ $cell['claim'] !== null ? ' · '.__('diambil rata-rata :n mnt', ['n' => $cell['claim']]) : '' }}">
                            @if ($cell['count'])<span class="text-[10px] tabular-nums text-slate-500">{{ $cell['count'] }}</span>@endif
                            <div class="w-full rounded-t {{ $cell['count'] ? $tone : 'bg-slate-100' }}" style="height: {{ max(2, round($cell['count'] / $hourMax * 100)) }}%"></div>
                            <span class="text-[10px] tabular-nums text-slate-400">{{ sprintf('%02d', $hour) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="mt-5 grid grid-cols-7 gap-2 border-t border-slate-100 pt-4">
                @foreach ($report['by_weekday'] as $day => $count)
                    <div class="text-center">
                        <div class="mx-auto h-1.5 w-full overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-gold-500" style="width: {{ round($count / $dayMax * 100) }}%"></div></div>
                        <p class="mt-1 text-xs text-slate-500">{{ $dayNames[$day] }}</p>
                        <p class="text-sm font-semibold tabular-nums text-slate-800">{{ $count }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="mb-6 grid gap-6 lg:grid-cols-2">
            {{-- Per jenis --}}
            <section class="card overflow-hidden">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-section">{{ __('Per jenis') }}</h2>
                    <p class="text-sm text-slate-500">{{ __('Yang paling sering diminta dan seberapa cepat ditangani.') }}</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gold-50 text-left text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                            <tr><th class="{{ $th }}">{{ __('Jenis') }}</th><th class="{{ $th }} text-right">{{ __('Permintaan') }}</th><th class="{{ $th }} text-right">{{ __('Diambil') }}</th><th class="{{ $th }} text-right">{{ __('Selesai') }}</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($report['by_kind'] as $row)
                                <tr>
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-slate-800">{{ $row['label'] }}</p>
                                        <span class="mt-1 block h-1.5 w-full max-w-40 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full bg-gold-500" style="width: {{ round($row['count'] / $kindMax * 100) }}%"></span></span>
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ $row['count'] }}@if ($row['open'])<span class="block text-xs text-rose-700">{{ $row['open'] }} {{ __('belum') }}</span>@endif</td>
                                    <td class="px-4 py-3 text-right tabular-nums {{ $speedTone($row['claim']) }}">{{ $minutes($row['claim']) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-slate-700">{{ $minutes($row['done']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Per petugas --}}
            <section class="card overflow-hidden">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-section">{{ __('Per petugas') }}</h2>
                    <p class="text-sm text-slate-500">{{ __('Yang menekan Saya ambil / Selesai. Waktu kerja = dari diambil sampai selesai.') }}</p>
                </div>
                @if ($report['by_staff'])
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gold-50 text-left text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                                <tr><th class="{{ $th }}">{{ __('Petugas') }}</th><th class="{{ $th }} text-right">{{ __('Diambil') }}</th><th class="{{ $th }} text-right">{{ __('Selesai') }}</th><th class="{{ $th }} text-right">{{ __('Respons') }}</th><th class="{{ $th }} text-right">{{ __('Kerja') }}</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($report['by_staff'] as $row)
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-slate-800">{{ $row['name'] }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums">{{ $row['taken'] }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums">{{ $row['done'] }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums {{ $speedTone($row['claim']) }}">{{ $minutes($row['claim']) }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums text-slate-700">{{ $minutes($row['work']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="px-5 py-6 text-sm text-slate-500">{{ __('Belum ada permintaan yang diambil.') }}</p>
                @endif
            </section>
        </div>

        <div class="mb-6 grid gap-6 lg:grid-cols-3">
            {{-- Per departemen --}}
            <section class="card p-5">
                <h2 class="text-section">{{ __('Per departemen tujuan') }}</h2>
                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    @foreach ($report['by_department'] as $row)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="font-medium text-slate-800">{{ $row['name'] }}</span>
                            <span class="text-right tabular-nums">{{ $row['count'] }} <span class="block text-xs {{ $speedTone($row['claim']) }}">{{ __('diambil') }} {{ $minutes($row['claim']) }}</span></span>
                        </li>
                    @endforeach
                </ul>
            </section>
            {{-- Kamar paling banyak meminta --}}
            <section class="card p-5">
                <h2 class="text-section">{{ __('Kamar paling banyak meminta') }}</h2>
                <ul class="mt-3 flex flex-wrap gap-2 text-sm">
                    @foreach ($report['by_room'] as $row)
                        <li class="rounded-lg bg-gold-50 px-3 py-1.5 ring-1 ring-gold-200"><b class="tabular-nums">{{ $row['room'] }}</b> <span class="text-slate-500">· {{ $row['count'] }}×</span></li>
                    @endforeach
                </ul>
            </section>
            {{-- Masih menunggu --}}
            <section class="card p-5">
                <h2 class="text-section">{{ __('Masih menunggu diambil') }}</h2>
                @forelse ($report['waiting'] as $item)
                    <p class="mt-2 flex items-center justify-between gap-2 text-sm">
                        <span><b>{{ __('Kamar') }} {{ $item->room?->number }}</b> · {{ $item->label() }}</span>
                        <span class="shrink-0 text-xs text-rose-700">{{ $item->created_at->diffForHumans(short: true) }}</span>
                    </p>
                @empty
                    <p class="mt-2 text-sm text-slate-500">{{ __('Tidak ada. Semua permintaan sudah diambil.') }}</p>
                @endforelse
            </section>
        </div>

        {{-- Paling lama diambil --}}
        @if ($report['slowest']->isNotEmpty())
            <section class="card mb-6 overflow-hidden">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-section">{{ __('Paling lama diambil') }}</h2>
                    <p class="text-sm text-slate-500">{{ __('Bahan evaluasi: jam berapa, jenis apa, dan siapa yang akhirnya mengambil.') }}</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gold-50 text-left text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                            <tr><th class="{{ $th }}">{{ __('Dicatat') }}</th><th class="{{ $th }}">{{ __('Kamar') }}</th><th class="{{ $th }}">{{ __('Permintaan') }}</th><th class="{{ $th }}">{{ __('Diambil oleh') }}</th><th class="{{ $th }} text-right">{{ __('Menunggu') }}</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($report['slowest'] as $item)
                                <tr>
                                    <td class="px-4 py-3 tabular-nums text-slate-600">{{ $item->created_at->translatedFormat('d M H:i') }}</td>
                                    <td class="px-4 py-3 font-medium">{{ $item->room?->number }}</td>
                                    <td class="px-4 py-3">{{ $item->label() }}@if ($item->priority === 'mendesak') <x-ui.badge tone="pending">{{ __('Mendesak') }}</x-ui.badge>@endif</td>
                                    <td class="px-4 py-3 text-slate-700">{{ $item->claimer?->name() ?? '—' }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums {{ $speedTone($item->minutesToClaim()) }}">{{ $minutes($item->minutesToClaim()) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif
</x-app-layout>
