<x-app-layout title="{{ __('Isi checklist') }}">
    <div class="mb-4">
        <h1 class="text-3xl text-slate-900">{{ $staffMode ? 'Checklist saya' : 'Isi checklist harian' }}</h1>
        @unless ($staffMode)
            <p class="mt-0.5 font-medium text-gold-700">{{ $date->translatedFormat('l, d F Y') }}</p>
        @endunless
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">@foreach ($errors->all() as $message) <p>{{ $message }}</p> @endforeach</div>
    @endif
    @if ($problem)
        <p class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $problem }}</p>
    @endif

    @if ($staffMode)
        @unless ($problem)
            <form method="POST" action="{{ route('fill.start') }}" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
                @csrf
                <input type="hidden" name="position" value="{{ $position->id }}">
                <input type="hidden" name="employee" value="{{ $employee->id }}">
                <div>
                    <label for="date" class="label">{{ __('Tanggal') }}</label>
                    <div class="relative" data-date-field data-max="{{ $today->toDateString() }}">
                        <input type="hidden" name="date" value="{{ old('date', $date->toDateString()) }}" data-date-value>
                        <button type="button" id="date" class="input flex min-w-56 items-center gap-2 text-left" data-date-trigger aria-haspopup="dialog" aria-expanded="false"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8a6a2a" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg><span data-date-label>{{ \Carbon\Carbon::parse(old('date', $date->toDateString()))->translatedFormat('l, d M Y') }}</span></button>
                        <div class="cal-panel hidden" data-date-panel role="dialog" aria-label="{{ __('Pilih tanggal') }}"></div>
                    </div>
                </div>
                <button class="btn-primary">Buka checklist {{ $position->name }}</button>
            </form>
        @endunless
    @else
        @php
            $counts = $board->countBy('state');
            $todo = ($counts['none'] ?? 0);
            $states = [
                'none' => [__('Belum dibuat'), 'pill-neutral'],
                'empty' => [__('Belum diisi'), 'pill-pending'],
                'partial' => [__('Belum lengkap'), 'pill-ongoing'],
                'complete' => [__('Lengkap'), 'pill-done'],
                'reviewed' => [__('Direview oleh GM/HR'), 'pill-done'],
                'hod_reviewed' => [__('Direview oleh HOD'), 'pill-ongoing'],
                'noschedule' => [__('Jadwal belum diisi'), 'pill-neutral'],
            ];
        @endphp

        {{-- Tanggal & departemen: memuat ulang papan tim (form GET terpisah dari form mulai checklist). --}}
        <form id="board-form" method="GET" action="{{ route('fill.create') }}" class="mb-4 flex flex-wrap items-end gap-3">
            <div class="flex items-end gap-1.5">
                <a href="{{ route('fill.create', ['date' => $date->subDay()->toDateString(), 'department' => $department?->id]) }}" class="hod-step" aria-label="{{ __('Hari sebelumnya') }}" title="{{ __('Hari sebelumnya') }}">‹</a>
                <div class="relative" data-date-field data-max="{{ $today->toDateString() }}" data-submit>
                    <input type="hidden" name="date" value="{{ $date->toDateString() }}" data-date-value>
                    <button type="button" class="input flex h-11 min-w-56 items-center gap-2 text-left" data-date-trigger aria-haspopup="dialog" aria-expanded="false"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8a6a2a" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg><span data-date-label>{{ $date->translatedFormat('l, d M Y') }}</span></button>
                    <div class="cal-panel hidden" data-date-panel role="dialog" aria-label="{{ __('Pilih tanggal') }}"></div>
                </div>
                @if ($date->lt($today))
                    <a href="{{ route('fill.create', ['date' => $date->addDay()->toDateString(), 'department' => $department?->id]) }}" class="hod-step" aria-label="{{ __('Hari berikutnya') }}" title="{{ __('Hari berikutnya') }}">›</a>
                @else
                    <span class="hod-step opacity-30" aria-hidden="true">›</span>
                @endif
            </div>
            @if ($departments->count() > 1)
                <div class="min-w-48 flex-1 sm:flex-none">
                    <label for="department" class="sr-only">{{ __('Departemen') }}</label>
                    <select id="department" name="department" class="input !h-11" onchange="this.form.submit()">
                        @foreach ($departments as $option)
                            <option value="{{ $option->id }}" @selected($department?->id === $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <input type="hidden" name="department" value="{{ $department?->id }}">
            @endif
            @unless ($date->isSameDay($today))
                <a href="{{ route('fill.create', ['department' => $department?->id]) }}" class="btn-secondary h-11">{{ __('Hari ini') }}</a>
            @endunless
            <noscript><button class="btn-secondary">{{ __('Tampilkan') }}</button></noscript>
        </form>

        @error('assign') <p class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</p> @enderror

        @if ($summary)
            <div class="card mb-4 border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                <p class="font-semibold">Checklist {{ \Carbon\Carbon::parse($summary['date'])->translatedFormat('d M Y') }}: {{ $summary['created'] }} dibuat{{ $summary['existing'] ? ', '.$summary['existing'].' sudah ada' : '' }}.</p>
                <ul class="mt-1 list-inside list-disc text-emerald-900">
                    @if ($summary['off']) <li>{{ $summary['off'] }} libur (OFF)</li> @endif
                    @foreach ($summary['leave'] as $code => $count) <li>{{ $count }} izin / cuti ({{ $code }})</li> @endforeach
                    @if ($summary['unscheduled']) <li>{{ $summary['unscheduled'] }} belum ada jadwal di Absensi, tidak dibuatkan</li> @endif
                    @if ($summary['no_position'])
                        <li class="text-amber-800">{{ count($summary['no_position']) }} belum diatur jabatan checklistnya: {{ collect($summary['no_position'])->pluck('name')->implode(', ') }}.
                            <a href="{{ route('team.positions.edit') }}" class="font-semibold underline">{{ __('Atur jabatan staff') }}</a></li>
                    @endif
                </ul>
            </div>
        @endif

        @if ($scheduleProblem)
            <p class="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">Jadwal Absensi belum bisa dibaca, jadi staff yang belum punya jadwal belum bisa ditandai: {{ $scheduleProblem }}</p>
        @endif

        {{-- Ringkasan hari itu: angka sekaligus penyaring daftar staff. --}}
        <div class="hod-stats mb-4" role="group" aria-label="{{ __('Saring staff') }}">
            <button type="button" data-board-filter="all" aria-pressed="true"><strong>{{ $board->count() }}</strong><span>{{ __('Semua staff') }}</span></button>
            <button type="button" data-board-filter="todo" aria-pressed="false"><strong>{{ ($counts['none'] ?? 0) + ($counts['empty'] ?? 0) + ($counts['partial'] ?? 0) }}</strong><span>{{ __('Perlu diisi') }}</span></button>
            <button type="button" data-board-filter="complete" aria-pressed="false"><strong>{{ $counts['complete'] ?? 0 }}</strong><span>{{ __('Lengkap') }}</span></button>
            <button type="button" data-board-filter="reviewed" aria-pressed="false"><strong>{{ ($counts['reviewed'] ?? 0) + ($counts['hod_reviewed'] ?? 0) }}</strong><span>{{ __('Direview') }}</span></button>
        </div>

        {{-- Satu tombol untuk semua staff yang masuk kerja (jadwal Absensi). --}}
        @if ($department)
            <div class="card mb-4 flex flex-col gap-3 border-gold-500/40 bg-[#fbf6ea] p-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="font-semibold text-slate-900">
                        @if ($todo)
                            {{ $todo }} staff belum punya checklist {{ $date->isSameDay($today) ? 'hari ini' : $date->translatedFormat('d M') }}
                        @else
                            Semua staff sudah punya checklist {{ $date->isSameDay($today) ? 'hari ini' : $date->translatedFormat('d M') }}
                        @endif
                    </p>
                    <p class="text-sm text-slate-600">{{ __('Sesuai jadwal Absensi: yang libur, cuti, izin, atau jadwalnya kosong dilewati.') }}</p>
                </div>
                <form method="POST" action="{{ route('fill.assign-all') }}" class="flex shrink-0 flex-wrap gap-2 [&>button]:flex-1 sm:[&>button]:flex-none">
                    @csrf
                    <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                    <input type="hidden" name="department" value="{{ $department->id }}">
                    <button class="btn-primary">Buat checklist {{ $date->isSameDay($today) ? 'hari ini' : 'tanggal ini' }}</button>
                    @if ($departments->count() > 1)
                        <button name="department" value="all" class="btn-secondary">{{ __('Semua departemen') }}</button>
                    @endif
                </form>
            </div>
        @endif

        {{-- Papan tim: ketuk nama staff untuk mulai / melanjutkan. --}}
        <div class="card mb-4">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                <h2 class="font-display text-xl text-slate-900">Tim {{ $department?->name }}</h2>
                @if ($board->count() > 6)
                    <input type="search" data-board-search placeholder="{{ __('Cari nama staff') }}" aria-label="{{ __('Cari nama staff') }}" class="input !w-full !py-2 sm:!w-60">
                @endif
            </div>
            <ul class="divide-y divide-slate-100" data-board>
                @forelse ($board as $row)
                    @php
                        $e = $row['employee'];
                        [$label, $pill] = $states[$row['state']];
                        $first = $row['checklists']->first();
                        $answered = $row['checklists']->sum(fn ($c) => $c->answeredCount());
                        $total = $row['checklists']->sum(fn ($c) => $c->items->count());
                        $group = in_array($row['state'], ['none', 'empty', 'partial'], true) ? 'todo' : (in_array($row['state'], ['reviewed', 'hod_reviewed'], true) ? 'reviewed' : $row['state']);
                    @endphp
                    <li class="hod-row{{ $row['state'] === 'noschedule' ? ' opacity-60' : '' }}" data-board-row data-group="{{ $group }}" data-name="{{ \Illuminate\Support\Str::lower($e->name) }}">
                        <span class="hod-avatar" aria-hidden="true">{{ collect(explode(' ', $e->name))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-slate-900">{{ $e->name }}</p>
                            <p class="truncate text-sm text-slate-500">
                                @if ($row['checklists']->isNotEmpty())
                                    {{ $row['checklists']->pluck('position_name')->implode(', ') }}
                                @elseif ($row['state'] === 'noschedule')
                                    Tidak ada jadwal di Absensi untuk tanggal ini
                                @else
                                    {{ $e->checklistPosition?->name ?? 'Jabatan checklist belum diatur' }}
                                @endif
                            </p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="{{ $pill }}">{{ $label }}</span>
                                @if ($total)
                                    <span class="text-xs tabular-nums text-slate-500">{{ $answered }} / {{ $total }} terisi</span>
                                    <span class="h-1.5 w-16 overflow-hidden rounded-full bg-slate-100 sm:w-24"><span class="block h-full rounded-full bg-gold-500" style="width: {{ round($answered / $total * 100) }}%"></span></span>
                                @endif
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap justify-end gap-1.5">
                            @if ($row['checklists']->count() > 1)
                                @foreach ($row['checklists'] as $c)
                                    <a href="{{ $c->isReviewed() ? route('checklists.show', $c) : route('checklists.fill', $c) }}" class="btn-secondary !px-3 !py-1.5">{{ $c->position_name }}</a>
                                @endforeach
                            @elseif ($first)
                                <a href="{{ $first->isReviewed() || $first->isComplete() ? route('checklists.show', $first) : route('checklists.fill', $first) }}"
                                   class="{{ in_array($row['state'], ['empty', 'partial'], true) ? 'btn-primary' : 'btn-secondary' }} !px-4 !py-1.5">
                                    {{ match ($row['state']) { 'empty' => 'Isi', 'partial' => 'Lanjutkan', default => 'Lihat' } }}
                                </a>
                            @elseif ($row['state'] === 'noschedule')
                                <button type="button" disabled class="btn-secondary !px-4 !py-1.5" title="{{ __('Jadwal belum diisi di Absensi') }}">{{ __('Mulai') }}</button>
                            @elseif ($row['canStart'])
                                <form method="POST" action="{{ route('fill.start') }}">
                                    @csrf
                                    <input type="hidden" name="position" value="{{ $e->checklist_position_id }}">
                                    <input type="hidden" name="employee" value="{{ $e->id }}">
                                    <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                                    <button class="btn-primary !px-4 !py-1.5">{{ __('Mulai') }}</button>
                                </form>
                            @else
                                <a href="{{ route('team.positions.edit') }}" class="btn-secondary !px-4 !py-1.5">{{ __('Atur jabatan') }}</a>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-sm text-slate-500">{{ __('Belum ada staff aktif di departemen ini.') }}</li>
                @endforelse
                <li class="hidden px-4 py-6 text-center text-sm text-slate-500" data-board-empty>{{ __('Tidak ada staff yang cocok.') }}</li>
            </ul>
        </div>

        {{-- Jarang dipakai: jabatan lain untuk seorang staff. --}}
        <details class="card mb-4 p-4" @if ($errors->hasAny(['position', 'employee', 'date'])) open @endif>
            <summary class="cursor-pointer font-semibold text-slate-800">{{ __('Checklist dengan jabatan lain') }}</summary>
            <p class="mb-3 mt-1 text-sm text-slate-500">{{ __('Untuk staff yang hari ini mengerjakan tugas jabatan lain (misalnya menggantikan rekan).') }}</p>
            <form method="POST" action="{{ route('fill.start') }}" class="grid gap-3 sm:grid-cols-3 sm:items-end">
                @csrf
                <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                <div>
                    <label for="employee" class="label">{{ __('Nama staff') }}</label>
                    <select id="employee" name="employee" class="input" required>
                        <option value="">{{ __('Pilih staff') }}</option>
                        @foreach ($employees as $option)
                            <option value="{{ $option->id }}" @selected((int) old('employee', $employee?->id) === $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="position" class="label">{{ __('Jabatan') }}</label>
                    <select id="position" name="position" class="input" required>
                        <option value="">{{ __('Pilih jabatan') }}</option>
                        @foreach ($positions as $option)
                            <option value="{{ $option->id }}" @selected((int) old('position', $position?->id) === $option->id)>{{ $option->name }} ({{ $option->active_tasks_count }} tugas)</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn-primary">{{ __('Mulai checklist') }}</button>
            </form>
        </details>


        <script>
            (() => {
                const rows = [...document.querySelectorAll('[data-board-row]')];
                const empty = document.querySelector('[data-board-empty]');
                const search = document.querySelector('[data-board-search]');
                let group = 'all';
                const apply = () => {
                    const q = (search?.value ?? '').trim().toLowerCase();
                    let shown = 0;
                    rows.forEach((row) => {
                        const ok = (group === 'all' || row.dataset.group === group) && (!q || row.dataset.name.includes(q));
                        row.hidden = !ok;
                        shown += ok;
                    });
                    empty?.classList.toggle('hidden', shown > 0 || rows.length === 0);
                };
                document.querySelectorAll('[data-board-filter]').forEach((button, _, all) => button.addEventListener('click', () => {
                    group = button.dataset.boardFilter;
                    all.forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
                    apply();
                }));
                search?.addEventListener('input', apply);
            })();
        </script>
    @endif

    @if ($resume->isNotEmpty())
        <div class="card mb-4 p-4">
            <h2 class="mb-2 font-display text-xl">{{ __('Lanjutkan checklist') }}</h2>
            <p class="mb-2 text-sm text-slate-500">{{ $staffMode ? 'Checklist yang belum lengkap, 7 hari terakhir.' : 'Checklist yang belum lengkap di hari atau departemen lain, 7 hari terakhir.' }}</p>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($resume as $draft)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                        <a href="{{ route('checklists.fill', $draft) }}" class="font-medium text-sky-700 hover:underline">{{ $draft->employee->name }} · {{ $draft->position_name }} · {{ $draft->date->translatedFormat('d M Y') }}</a>
                        <span class="pill-neutral">{{ $draft->answeredCount() ? $draft->answeredCount().' / '.$draft->items->count().' terisi' : 'Belum diisi' }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($staffMode && $history->isNotEmpty())
        <div class="card p-4">
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Riwayat 14 terakhir</h2>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($history as $past)
                    <li class="flex items-center justify-between py-2">
                        <a href="{{ route('checklists.show', $past) }}" class="text-sky-700 hover:underline">{{ $past->date->translatedFormat('l, d M Y') }} · {{ $past->position_name }}</a>
                        <x-status-badge :status="$past->status" />
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</x-app-layout>
