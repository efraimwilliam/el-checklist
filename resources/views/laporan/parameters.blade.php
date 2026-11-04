<x-app-layout title="{{ __('Parameter') }}">
    @include('laporan.partials.head', ['active' => 'parameters', 'picker' => true, 'heading' => __('Nilai parameter'), 'action' => route('laporan.parameters'), 'keep' => ['department_id' => $departmentId]])

    <form method="GET" action="{{ route('laporan.parameters') }}" class="mb-6 print:hidden">
        <input type="hidden" name="from" value="{{ $from->toDateString() }}">
        <input type="hidden" name="to" value="{{ $to->toDateString() }}">
        <label class="text-sm text-slate-600">{{ __('Departemen') }}
            <select name="department_id" class="input mt-1" onchange="this.form.submit()">
                <option value="">{{ __('Semua departemen') }}</option>
                @foreach ($departments as $department)<option value="{{ $department->id }}" @selected($departmentId === $department->id)>{{ $department->name }}</option>@endforeach
            </select>
        </label>
    </form>

    <section class="card mb-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5">
            <h2 class="font-display text-xl text-slate-900">{{ __('Ringkasan nilai angka dan stand meter') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('Rangkuman angka yang diisi pegawai pada tugas checklist berparameter angka atau stand meter (mis. suhu chiller, stand meter listrik), pada rentang tanggal yang dipilih. Satu baris = satu parameter pada satu tugas.') }}</p>
            <dl class="mt-3 grid gap-x-6 gap-y-1.5 text-xs text-slate-500 sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="inline font-semibold text-slate-700">{{ __('Data') }}:</dt> <dd class="inline">{{ __('berapa kali angka itu diisi.') }}</dd></div>
                <div><dt class="inline font-semibold text-slate-700">Min · {{ __('Rata-rata') }} · Maks:</dt> <dd class="inline">{{ __('angka terkecil, rata-rata, dan terbesar yang pernah diisi.') }}</dd></div>
                <div><dt class="inline font-semibold text-slate-700">{{ __('Di luar batas') }}:</dt> <dd class="inline">{{ __('berapa angka yang melewati batas min / maks yang ditetapkan di tugas (merah = ada).') }}</dd></div>
                <div><dt class="inline font-semibold text-slate-700">{{ __('Konsumsi') }}:</dt> <dd class="inline">{{ __('hanya untuk stand meter: angka terakhir dikurangi angka pertama dalam rentang, yaitu pemakaian selama periode itu.') }}</dd></div>
                <div><dt class="inline font-semibold text-slate-700">{{ __('Track record') }}:</dt> <dd class="inline">{{ __('10 angka terakhir beserta grafik dan siapa yang mengisi.') }}</dd></div>
            </dl>
        </div>
        @if ($summary)
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr>
                        <th class="px-4 py-2">{{ __('Tugas') }}</th><th class="px-4 py-2">{{ __('Parameter') }}</th><th class="px-4 py-2">{{ __('Departemen') }}</th>
                        <th class="px-4 py-2 text-right" title="{{ __('Berapa kali angka diisi') }}">{{ __('Data') }}</th><th class="px-4 py-2 text-right" title="{{ __('Angka terkecil yang diisi') }}">Min</th><th class="px-4 py-2 text-right" title="{{ __('Rata-rata semua angka yang diisi') }}">{{ __('Rata-rata') }}</th><th class="px-4 py-2 text-right" title="{{ __('Angka terbesar yang diisi') }}">Maks</th>
                        <th class="px-4 py-2 text-right" title="{{ __('Jumlah angka yang melewati batas min / maks tugas') }}">{{ __('Di luar batas') }}</th><th class="px-4 py-2 text-right" title="{{ __('Stand meter: angka terakhir dikurangi angka pertama') }}">{{ __('Konsumsi') }}</th><th class="px-4 py-2"><span class="sr-only">{{ __('Riwayat') }}</span></th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @php
                            // Satu tugas punya beberapa parameter: nama tugas ditulis sekali, mencakup semua barisnya.
                            $taskRows = [];
                            foreach ($summary as $item) {
                                $taskRows[$item['department'].'|'.$item['title']] = ($taskRows[$item['department'].'|'.$item['title']] ?? 0) + 1;
                            }
                            $taskShown = [];
                        @endphp
                        @foreach ($summary as $row)
                            @php
                                $taskKey = $row['department'].'|'.$row['title'];
                                $taskFirst = ! isset($taskShown[$taskKey]);
                                $taskShown[$taskKey] = true;
                            @endphp
                            <tr class="{{ $taskFirst && ! $loop->first ? 'border-t-[6px] border-slate-200' : '' }}">
                                @if ($taskFirst)
                                    <td class="bg-amber-50/40 px-4 py-2 align-top" rowspan="{{ $taskRows[$taskKey] }}">
                                        <span class="font-semibold text-slate-900">{{ $row['title'] }}</span>
                                        <span class="mt-0.5 block text-xs text-slate-500">{{ $row['positions'] }}</span>
                                        <span class="mt-1 block text-[11px] font-semibold uppercase tracking-wide text-gold-700">{{ $taskRows[$taskKey] }} {{ __('parameter') }}</span>
                                    </td>
                                @endif
                                <td class="px-4 py-2">{{ $row['parameter'] }}@if ($row['unit']) <span class="text-slate-500">({{ $row['unit'] }})</span>@endif</td>
                                <td class="px-4 py-2">{{ $row['department'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['readings'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['min'] + 0 }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['avg'] + 0 }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['max'] + 0 }}</td>
                                <td class="px-4 py-2 text-right tabular-nums {{ $row['outOfRange'] ? 'font-semibold text-rose-700' : '' }}">{{ $row['outOfRange'] ?: '' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['usage'] !== null ? ($row['usage'] + 0).($row['unit'] ? ' '.$row['unit'] : '') : '' }}</td>
                                <td class="px-4 py-2 text-right"><button type="button" class="whitespace-nowrap text-sm font-semibold text-sky-700 hover:underline" data-history-toggle aria-expanded="false" aria-controls="history-{{ $loop->index }}">{{ __('Track record') }} ▾</button></td>
                            </tr>
                            {{-- Track record: 10 pembacaan terakhir beserta grafik garis kecil (lama ke baru). --}}
                            <tr id="history-{{ $loop->index }}" hidden data-history-row class="bg-slate-50/60">
                                <td colspan="10" class="px-4 py-4">
                                    @php
                                        // Grafik garis dengan sumbu: Y = nilai (satuan parameter), X = tanggal pembacaan; garis putus-putus = batas min / maks tugas.
                                        $points = array_reverse($row['history']);
                                        $count = count($points);
                                        $values = array_column($points, 'value');
                                        $limits = array_values(array_filter([$row['limitMin'], $row['limitMax']], fn ($v) => $v !== null));
                                        $all = array_merge($values, $limits);
                                        $lo = $all ? min($all) : 0;
                                        $hi = $all ? max($all) : 0;
                                        $pad = $hi > $lo ? ($hi - $lo) * 0.12 : max(abs($hi) * 0.05, 1);
                                        $yMin = $lo - $pad;
                                        $yMax = $hi + $pad;
                                        [$W, $H, $L, $R, $T, $B] = [560, 250, 66, 18, 16, 54];
                                        $pw = $W - $L - $R;
                                        $ph = $H - $T - $B;
                                        $px = fn (int $i) => round($count > 1 ? $L + $i * $pw / ($count - 1) : $L + $pw / 2, 1);
                                        $py = fn (float $v) => round($T + $ph - ($v - $yMin) / ($yMax - $yMin) * $ph, 1);
                                        $fmt = fn (float $v) => rtrim(rtrim(number_format($v, ($yMax - $yMin) >= 20 ? 0 : (($yMax - $yMin) >= 2 ? 1 : 2), ',', '.'), '0'), ',') ?: '0';
                                        $ticks = array_map(fn ($k) => $yMin + $k * ($yMax - $yMin) / 4, range(0, 4));
                                        $labelAt = $count <= 6 ? range(0, $count - 1) : array_values(array_unique([0, (int) round(($count - 1) / 3), (int) round(2 * ($count - 1) / 3), $count - 1]));
                                        $unit = $row['unit'] ? ' '.$row['unit'] : '';
                                    @endphp
                                    <div class="grid gap-4 xl:grid-cols-[minmax(0,36rem)_minmax(0,1fr)]">
                                        <div>
                                            <svg viewBox="0 0 {{ $W }} {{ $H }}" class="h-auto w-full max-w-xl" role="img" aria-label="{{ __('Grafik :parameter dari lama ke baru', ['parameter' => $row['parameter']]) }}">
                                                {{-- rentang normal (di antara batas min dan maks) --}}
                                                @if ($row['limitMin'] !== null && $row['limitMax'] !== null)
                                                    <rect x="{{ $L }}" y="{{ $py($row['limitMax']) }}" width="{{ $pw }}" height="{{ max(0, $py($row['limitMin']) - $py($row['limitMax'])) }}" fill="#2a5c43" opacity="0.08"/>
                                                @endif
                                                {{-- garis bantu dan angka sumbu Y --}}
                                                @foreach ($ticks as $tick)
                                                    <line x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $py($tick) }}" y2="{{ $py($tick) }}" stroke="#e5ddd0" stroke-width="1"/>
                                                    <text x="{{ $L - 8 }}" y="{{ $py($tick) + 4 }}" text-anchor="end" font-size="11" fill="#6b5f55">{{ $fmt($tick) }}</text>
                                                @endforeach
                                                <line x1="{{ $L }}" x2="{{ $L }}" y1="{{ $T }}" y2="{{ $T + $ph }}" stroke="#a39687" stroke-width="1"/>
                                                <line x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $T + $ph }}" y2="{{ $T + $ph }}" stroke="#a39687" stroke-width="1"/>
                                                {{-- batas min / maks tugas --}}
                                                @foreach ([['Batas maks', $row['limitMax']], ['Batas min', $row['limitMin']]] as [$limitLabel, $limitValue])
                                                    @if ($limitValue !== null)
                                                        <line x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $py($limitValue) }}" y2="{{ $py($limitValue) }}" stroke="#b23a24" stroke-width="1.2" stroke-dasharray="5 4"/>
                                                        <text x="{{ $W - $R - 2 }}" y="{{ $py($limitValue) - 4 }}" text-anchor="end" font-size="10" fill="#b23a24">{{ __($limitLabel) }} {{ $fmt($limitValue) }}</text>
                                                    @endif
                                                @endforeach
                                                {{-- garis dan titik pembacaan --}}
                                                @if ($count > 1)<polyline fill="none" stroke="#8a6a2a" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="{{ collect($points)->map(fn ($p, $i) => $px($i).','.$py($p['value']))->implode(' ') }}"/>@endif
                                                @foreach ($points as $i => $p)
                                                    <circle cx="{{ $px($i) }}" cy="{{ $py($p['value']) }}" r="4" fill="{{ $p['out'] ? '#b23a24' : '#8a6a2a' }}" stroke="#fff" stroke-width="1.5"><title>{{ \Carbon\CarbonImmutable::parse($p['date'])->translatedFormat('d M Y') }} · {{ $p['employee'] }} · {{ $fmt($p['value']) }}{{ $unit }}{{ $p['out'] ? ' ('.__('di luar batas').')' : '' }}</title></circle>
                                                @endforeach
                                                {{-- tanggal di sumbu X --}}
                                                @foreach ($labelAt as $i)
                                                    <line x1="{{ $px($i) }}" x2="{{ $px($i) }}" y1="{{ $T + $ph }}" y2="{{ $T + $ph + 4 }}" stroke="#a39687"/>
                                                    <text x="{{ $px($i) }}" y="{{ $T + $ph + 17 }}" text-anchor="middle" font-size="11" fill="#6b5f55">{{ \Carbon\CarbonImmutable::parse($points[$i]['date'])->translatedFormat('d M') }}</text>
                                                @endforeach
                                                {{-- judul sumbu --}}
                                                <text x="{{ $L + $pw / 2 }}" y="{{ $H - 6 }}" text-anchor="middle" font-size="11" font-weight="600" fill="#44392f">{{ __('Tanggal pembacaan') }}</text>
                                                <text transform="translate(14 {{ $T + $ph / 2 }}) rotate(-90)" text-anchor="middle" font-size="11" font-weight="600" fill="#44392f">{{ $row['parameter'] }}{{ $row['unit'] ? ' ('.$row['unit'].')' : '' }}</text>
                                            </svg>
                                            <p class="text-xs text-slate-500">
                                                {{ __('Lama') }} &rarr; {{ __('baru') }} · {{ $count }} {{ __('pembacaan terakhir') }}
                                                @if ($count > 1) · {{ __('berubah') }} <span class="font-semibold {{ end($values) - $values[0] >= 0 ? 'text-slate-700' : 'text-slate-700' }}">{{ end($values) - $values[0] >= 0 ? '+' : '−' }}{{ $fmt(abs(end($values) - $values[0])) }}{{ $unit }}</span>@endif
                                                @if ($row['limitMin'] !== null || $row['limitMax'] !== null) · <span class="text-rose-700">{{ __('garis putus-putus = batas tugas') }}</span>@endif
                                            </p>
                                        </div>
                                        <ul class="divide-y divide-slate-200 text-sm">
                                            @foreach ($row['history'] as $reading)
                                                <li class="flex flex-wrap items-center justify-between gap-2 py-1.5">
                                                    <span class="text-slate-600">{{ \Carbon\CarbonImmutable::parse($reading['date'])->translatedFormat('d M Y') }} · {{ $reading['employee'] }}</span>
                                                    <span class="flex items-center gap-2"><span class="font-semibold tabular-nums {{ $reading['out'] ? 'text-rose-700' : 'text-slate-900' }}">{{ $reading['value'] + 0 }}@if ($row['unit']) {{ $row['unit'] }}@endif</span><a class="text-xs text-sky-700 hover:underline" href="{{ route('checklists.show', $reading['checklist_id']) }}">{{ __('Lihat') }}</a></span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="p-5 text-sm text-slate-500">{{ __('Belum ada tugas berparameter angka atau stand meter yang terisi pada rentang ini.') }}</p>
        @endif
    </section>

    <script>
        document.querySelectorAll('[data-history-toggle]').forEach((button) => button.addEventListener('click', () => {
            const row = document.getElementById(button.getAttribute('aria-controls'));
            row.hidden = !row.hidden;
            button.setAttribute('aria-expanded', String(!row.hidden));
            button.textContent = @json(__('Track record')) + (row.hidden ? ' ▾' : ' ▴');
        }));
    </script>

    <section class="card mb-6 overflow-hidden">
        <h2 class="border-b border-slate-100 p-5 font-display text-xl text-slate-900">{{ __('Di luar batas atau bukan nilai normal') }}</h2>
        @forelse ($exceptions as $event)
            <div class="border-b border-slate-100 px-5 py-3 text-sm last:border-0">
                <p class="text-slate-900"><a class="underline" href="{{ route('checklists.show', $event['checklist_id']) }}">{{ $event['title'] }}</a> · {{ $event['parameter'] }}: <strong class="text-rose-700">{{ $event['value'] }}</strong> <span class="text-slate-500">({{ $event['expected'] }})</span></p>
                <p class="text-slate-500">{{ $event['employee'] }} · {{ $event['department'] }} · {{ \Carbon\CarbonImmutable::parse($event['date'])->translatedFormat('d M Y') }}</p>
            </div>
        @empty
            <p class="p-5 text-sm text-slate-500">{{ __('Tidak ada pembacaan di luar batas.') }}</p>
        @endforelse
    </section>
</x-app-layout>
