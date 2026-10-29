<x-app-layout title="Laporan">
    @php
        $scoreTone = fn (?int $score) => $score === null ? 'text-slate-400' : ($score >= 90 ? 'text-emerald-800' : ($score >= 70 ? 'text-amber-800' : 'text-rose-700'));
        $date = $to;
    @endphp

    <div class="mb-5 flex flex-wrap items-start justify-between gap-4 print:mb-3">
        <div>
            <h1 class="text-3xl text-slate-900">Laporan</h1>
            <p class="text-sm text-slate-500">{{ \App\Support\DateRange::label($from, $to) }} · {{ $days }} hari</p>
        </div>
        <div class="flex flex-wrap items-center gap-3 print:hidden">
            <form method="GET" action="{{ route('laporan.index') }}">
                <div class="relative w-max max-w-full" data-range-field data-max="{{ $today->toDateString() }}">
                    <input type="hidden" name="from" value="{{ $from->toDateString() }}" data-range-from>
                    <input type="hidden" name="to" value="{{ $to->toDateString() }}" data-range-to>
                    <button type="button" class="input flex min-w-64 items-center gap-3 text-left" data-range-trigger aria-haspopup="dialog" aria-expanded="false">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8a6a2a" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>
                        <span class="font-medium text-slate-800" data-range-label>{{ \App\Support\DateRange::label($from, $to) }}</span>
                    </button>
                    <div class="cal-panel hidden" data-range-panel role="dialog" aria-label="Pilih rentang tanggal"></div>
                </div>
            </form>
            <a href="{{ $exportUrl }}" class="btn-secondary">Unduh Excel</a>
            <button type="button" onclick="window.print()" class="btn-secondary">Cetak</button>
        </div>
    </div>

    {{-- Ringkasan + pembanding periode sebelumnya --}}
    @php
        $delta = function (?int $now, ?int $before, string $unit = '') {
            if ($now === null || $before === null) return ['text' => 'belum ada pembanding', 'tone' => 'text-slate-500'];
            $diff = $now - $before;
            return ['text' => ($diff > 0 ? '+' : ($diff < 0 ? '' : '±')).$diff.$unit.' dari periode sebelumnya', 'tone' => $diff === 0 ? 'text-slate-500' : ($diff > 0 ? 'text-emerald-800' : 'text-rose-700')];
        };
        $scoreDelta = $delta($kpi['score'], $previous['kpi']['score'], ' poin');
        $pendingDelta = $delta($kpi['pending'], $previous['kpi']['pending']);
        $checklistDelta = $delta($kpi['checklists'], $previous['kpi']['checklists']);
    @endphp
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-3">
        <div class="card p-4">
            <p class="text-sm text-slate-500">Skor rata-rata</p>
            <p class="mt-1 font-display text-3xl tabular-nums {{ $scoreTone($kpi['score']) }}">{{ $kpi['score'] !== null ? $kpi['score'].'%' : '—' }}</p>
            <p class="mt-1 text-xs {{ $scoreDelta['tone'] }}">{{ $scoreDelta['text'] }}</p>
        </div>
        <div class="card p-4">
            <p class="text-sm text-slate-500">Checklist lengkap</p>
            <p class="mt-1 font-display text-3xl tabular-nums text-slate-900">{{ $kpi['completion'] !== null ? $kpi['completion'].'%' : '—' }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ $kpi['complete'] }} dari {{ $kpi['checklists'] }} checklist ({{ $checklistDelta['text'] }})</p>
        </div>
        <div class="card p-4">
            <p class="text-sm text-slate-500">Tugas Pending</p>
            <p class="mt-1 font-display text-3xl tabular-nums text-rose-700">{{ $kpi['pending'] }}</p>
            <p class="mt-1 text-xs {{ $pendingDelta['tone'] }}">{{ $pendingDelta['text'] }}</p>
        </div>
        <div class="card p-4">
            <p class="text-sm text-slate-500">Staff belum punya checklist</p>
            <p class="mt-1 font-display text-3xl tabular-nums {{ $kpi['missing'] ? 'text-rose-700' : 'text-slate-900' }}">{{ $kpi['missing'] }}</p>
            <p class="mt-1 text-xs text-slate-500">Dalam rentang ini</p>
        </div>
        <div class="card p-4">
            <p class="text-sm text-slate-500">Backlog review</p>
            <p class="mt-1 font-display text-3xl tabular-nums {{ $kpi['backlog'] ? 'text-amber-800' : 'text-slate-900' }}">{{ $kpi['backlog'] }}</p>
            <p class="mt-1 text-xs text-slate-500">Lengkap, belum direview, lebih dari 2 hari</p>
        </div>
        <div class="card p-4">
            <p class="text-sm text-slate-500">Foto bukti kurang</p>
            <p class="mt-1 font-display text-3xl tabular-nums {{ $kpi['photos'] ? 'text-amber-800' : 'text-slate-900' }}">{{ $kpi['photos'] }}</p>
            <p class="mt-1 text-xs text-slate-500">Tugas yang wajib foto, belum difoto</p>
        </div>
    </div>

    {{-- Per departemen --}}
    <section class="card mb-6 overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="font-display text-xl text-slate-900">Per departemen</h2>
            <p class="text-sm text-slate-500">Kelengkapan, pending, dan review. Staff yang belum punya checklist bisa dibuka.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-[#fbf6ea] text-left text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Departemen</th>
                        <th class="px-4 py-3 text-right">Checklist</th>
                        <th class="px-4 py-3">Lengkap</th>
                        <th class="px-4 py-3 text-right">Pending</th>
                        <th class="px-4 py-3 text-right">Belum direview</th>
                        <th class="px-4 py-3 text-right">Foto kurang</th>
                        <th class="px-4 py-3">Staff belum punya checklist</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-800">{{ $row['name'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $row['checklists'] }}</td>
                            <td class="px-4 py-3">
                                @if ($row['completion'] !== null)
                                    <div class="flex items-center gap-2">
                                        <span class="h-2 w-24 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full bg-gold-500" style="width: {{ $row['completion'] }}%"></span></span>
                                        <span class="tabular-nums text-slate-700">{{ $row['completion'] }}%</span>
                                    </div>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-rose-700">{{ $row['pending'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $row['unreviewed'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums {{ $row['photos'] ? 'text-amber-800' : '' }}">{{ $row['photos'] }}</td>
                            <td class="px-4 py-3">
                                @if (count($row['missing']))
                                    <details>
                                        <summary class="cursor-pointer font-semibold text-rose-700">{{ count($row['missing']) }} staff</summary>
                                        <p class="mt-1 text-xs text-slate-600">{{ implode(', ', $row['missing']) }}</p>
                                    </details>
                                @else
                                    <span class="text-emerald-800">Semua sudah punya</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">Belum ada departemen yang bisa Anda lihat.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        {{-- Tugas sering tertunda --}}
        <section class="card p-5">
            <h2 class="font-display text-xl text-slate-900">Tugas sering tertunda</h2>
            <p class="mb-4 text-sm text-slate-500">Paling sering berstatus Pending dalam rentang ini.</p>
            @php $maxPending = max(1, collect($topPending)->max('pending') ?? 1); @endphp
            <ul class="space-y-3">
                @forelse ($topPending as $row)
                    <li>
                        <div class="flex items-start justify-between gap-3 text-sm">
                            <span class="min-w-0 text-slate-800">{{ $row['title'] }} <span class="text-xs text-slate-500">· {{ $row['department'] }}</span></span>
                            <span class="shrink-0 tabular-nums font-semibold text-rose-700">{{ $row['pending'] }}×</span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-[#a8341f]" style="width: {{ round($row['pending'] / $maxPending * 100) }}%"></div></div>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-slate-500">Tidak ada tugas yang tertunda.</li>
                @endforelse
            </ul>
        </section>

        {{-- Alasan Pending terbaru --}}
        <section class="card p-5">
            <h2 class="font-display text-xl text-slate-900">Alasan Pending terbaru</h2>
            <p class="mb-4 text-sm text-slate-500">Catatan dari tugas yang Pending, terbaru dulu.</p>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($reasons as $reason)
                    <li class="py-2.5">
                        <p class="font-medium text-slate-800">{{ $reason['title'] }}</p>
                        <p class="text-slate-600">"{{ $reason['note'] }}"</p>
                        <p class="text-xs text-slate-500">{{ $reason['employee'] }} · {{ $reason['department'] }} · {{ \Carbon\Carbon::parse($reason['date'])->translatedFormat('d M Y') }}</p>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-slate-500">Belum ada alasan Pending dalam rentang ini.</li>
                @endforelse
            </ul>
        </section>
    </div>

    {{-- Jabatan & staff --}}
    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        <section class="card overflow-hidden">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="font-display text-xl text-slate-900">Skor per jabatan</h2>
                <p class="text-sm text-slate-500">Terendah dulu. Jabatan dengan skor rendah perlu dibina atau tugasnya ditinjau.</p>
            </div>
            <table class="min-w-full text-sm">
                <thead class="bg-[#fbf6ea] text-left text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                    <tr><th class="px-4 py-2.5">Jabatan</th><th class="px-4 py-2.5 text-right">Checklist</th><th class="px-4 py-2.5 text-right">Skor</th><th class="px-4 py-2.5 text-right">Pending</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($positions as $row)
                        <tr>
                            <td class="px-4 py-2.5"><span class="font-medium text-slate-800">{{ $row['position'] }}</span> <span class="text-xs text-slate-500">· {{ $row['department'] }}</span></td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ $row['checklists'] }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums {{ $scoreTone($row['score']) }}">{{ $row['score'] !== null ? $row['score'].'%' : '—' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-rose-700">{{ $row['pending'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">Belum ada checklist dalam rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="card overflow-hidden">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="font-display text-xl text-slate-900">Skor per staff (10 terendah)</h2>
                <p class="text-sm text-slate-500">Hanya staff yang sudah punya checklist dalam rentang ini.</p>
            </div>
            <table class="min-w-full text-sm">
                <thead class="bg-[#fbf6ea] text-left text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                    <tr><th class="px-4 py-2.5">Staff</th><th class="px-4 py-2.5 text-right">Checklist</th><th class="px-4 py-2.5 text-right">Skor</th><th class="px-4 py-2.5 text-right">Pending</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($staff as $row)
                        <tr>
                            <td class="px-4 py-2.5 font-medium text-slate-800">{{ $row['name'] }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ $row['checklists'] }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums {{ $scoreTone($row['score']) }}">{{ $row['score'] !== null ? $row['score'].'%' : '—' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-rose-700">{{ $row['pending'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">Belum ada checklist dalam rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        {{-- Tugas sering dilewati per staff --}}
        <section class="card p-5">
            <h2 class="font-display text-xl text-slate-900">Tugas sering dilewati</h2>
            <p class="mb-4 text-sm text-slate-500">Tugas yang berulang kali Pending atau dibiarkan kosong untuk satu staff.</p>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($skipped as $row)
                    <li class="flex items-start justify-between gap-3 py-2.5">
                        <span class="min-w-0"><span class="font-medium text-slate-800">{{ $row['employee'] }}</span> <span class="text-slate-500">· {{ $row['title'] }}</span></span>
                        <span class="shrink-0 tabular-nums text-rose-700">{{ $row['times'] }}× <span class="text-xs text-slate-500">({{ $row['blank'] }} kosong)</span></span>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-slate-500">Tidak ada tugas yang berulang kali dilewati.</li>
                @endforelse
            </ul>
        </section>

        {{-- Kebiasaan pengisian & review --}}
        <section class="card p-5">
            <h2 class="font-display text-xl text-slate-900">Kebiasaan pengisian & review</h2>
            <p class="mb-4 text-sm text-slate-500">Checklist diisi di hari yang sama dengan tanggalnya, dan berapa lama sampai direview.</p>
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div class="rounded-xl bg-[#fbf6ea] p-4">
                    <dt class="text-slate-500">Diisi di hari yang sama</dt>
                    <dd class="mt-1 font-display text-2xl tabular-nums text-slate-900">{{ $habits['sameDayRate'] !== null ? $habits['sameDayRate'].'%' : '—' }}</dd>
                    <dd class="text-xs text-slate-500">{{ $habits['sameDay'] }} dari {{ $habits['total'] }} checklist</dd>
                </div>
                <div class="rounded-xl bg-[#fbf6ea] p-4">
                    <dt class="text-slate-500">Diisi belakangan</dt>
                    <dd class="mt-1 font-display text-2xl tabular-nums text-slate-900">{{ $habits['later'] }}</dd>
                    <dd class="text-xs text-slate-500">Checklist yang baru dibuat setelah tanggalnya</dd>
                </div>
                <div class="rounded-xl bg-[#fbf6ea] p-4">
                    <dt class="text-slate-500">Rata-rata sampai direview</dt>
                    <dd class="mt-1 font-display text-2xl tabular-nums text-slate-900">{{ $habits['reviewAvgDays'] !== null ? $habits['reviewAvgDays'].' hari' : '—' }}</dd>
                    <dd class="text-xs text-slate-500">{{ $habits['reviewed'] }} checklist sudah direview</dd>
                </div>
                <div class="rounded-xl bg-[#fbf6ea] p-4">
                    <dt class="text-slate-500">Paling lama menunggu</dt>
                    <dd class="mt-1 font-display text-2xl tabular-nums text-slate-900">{{ $habits['reviewMaxDays'] !== null ? $habits['reviewMaxDays'].' hari' : '—' }}</dd>
                    <dd class="text-xs text-slate-500">Dari tanggal checklist ke review</dd>
                </div>
            </dl>
        </section>
    </div>

    {{-- Pola per hari dalam seminggu --}}
    <section class="card mb-6 p-5">
        <h2 class="font-display text-xl text-slate-900">Pola per hari</h2>
        <p class="mb-4 text-sm text-slate-500">Persentase tugas Pending per hari dalam seminggu. Hari dengan Pending tinggi bisa menandakan beban kerja atau jadwal yang perlu dicek.</p>
        <div class="grid grid-cols-7 gap-2">
            @foreach ($weekdays as $day)
                <div class="flex flex-col items-center gap-2">
                    <div class="flex h-36 w-full items-end justify-center rounded-xl bg-slate-50 p-1.5">
                        <div class="w-full rounded-lg bg-gradient-to-t from-[#8a5a2b] to-[#c9a24f]" style="height: {{ max(2, $day['rate'] ?? 0) }}%" title="{{ $day['label'] }}: {{ $day['rate'] !== null ? $day['rate'].'% Pending' : 'tidak ada data' }}"></div>
                    </div>
                    <p class="text-xs font-semibold text-slate-600">{{ substr($day['label'], 0, 3) }}</p>
                    <p class="text-xs tabular-nums text-slate-500">{{ $day['rate'] !== null ? $day['rate'].'%' : '—' }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Catatan tindak lanjut: pengguna menambahkan informasi untuk periode ini. --}}
    <section id="catatan" class="card mb-6 p-5">
        <h2 class="font-display text-xl text-slate-900">Catatan tindak lanjut</h2>
        <p class="mb-4 text-sm text-slate-500">Tambahkan informasi untuk periode ini, misalnya penyebab, rencana perbaikan, atau keputusan rapat.</p>

        <form method="POST" action="{{ route('laporan.notes.store') }}" class="mb-6 grid gap-3 rounded-2xl border border-dashed border-gold-500/60 bg-[#fdfaf3] p-4 print:hidden">
            @csrf
            <input type="hidden" name="from" value="{{ $from->toDateString() }}">
            <input type="hidden" name="to" value="{{ $to->toDateString() }}">
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
                <div>
                    <label for="note-department" class="label">Departemen</label>
                    <select id="note-department" name="department_id" class="input" @if (! $canChooseAll) required @endif>
                        @if ($canChooseAll)<option value="">Semua departemen</option>@else<option value="">Pilih departemen</option>@endif
                        @foreach ($noteDepartments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end justify-end">
                    <button class="btn-primary">Tambah catatan</button>
                </div>
            </div>
            <div>
                <label for="note-body" class="label">Catatan</label>
                <textarea id="note-body" name="body" rows="4" maxlength="2000" required class="input leading-relaxed" placeholder="Contoh: Barang minibar habis sejak minggu lalu, pembelian dijadwalkan Senin."></textarea>
            </div>
        </form>

        <ul class="divide-y divide-slate-100">
            @forelse ($notes as $note)
                <li class="py-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                        <span>
                            <strong class="text-slate-700">{{ $note->department?->name ?? 'Semua departemen' }}</strong>
                            · {{ $note->author?->name() ?? 'Pengguna dihapus' }}
                            · {{ $note->created_at->translatedFormat('d M Y H:i') }}
                            · periode {{ $note->period_from->translatedFormat('d M') }} – {{ $note->period_to->translatedFormat('d M Y') }}
                        </span>
                        @if ($note->created_by === $userId || $canOverride)
                            <form method="POST" action="{{ route('laporan.notes.destroy', $note) }}" class="print:hidden" onsubmit="return confirm('Hapus catatan ini?')">
                                @csrf
                                @method('DELETE')
                                <button class="font-semibold text-slate-500 hover:text-rose-700">Hapus</button>
                            </form>
                        @endif
                    </div>
                    <p class="mt-2 whitespace-pre-line text-[15px] leading-relaxed text-slate-800">{{ $note->body }}</p>
                </li>
            @empty
                <li class="py-6 text-center text-sm text-slate-500">Belum ada catatan untuk periode ini.</li>
            @endforelse
        </ul>
    </section>
</x-app-layout>
