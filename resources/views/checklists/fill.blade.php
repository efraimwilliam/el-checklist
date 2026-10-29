<x-app-layout title="Isi checklist">
    <div class="mb-4">
        <h1 class="text-3xl text-slate-900">{{ $staffMode ? 'Checklist saya' : 'Isi checklist harian' }}</h1>
        <p class="text-sm text-slate-500">
            {{ $staffMode ? 'Buka checklist hari ini lalu pilih status tiap tugas. Setiap pilihan tersimpan otomatis.' : 'Pilih jabatan dan staff yang dicek. Setiap pilihan tersimpan otomatis, jadi bisa dilanjutkan kapan saja.' }}
        </p>
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
                    <label for="date" class="label">Tanggal</label>
                    <input id="date" type="date" name="date" value="{{ old('date', $date->toDateString()) }}" max="{{ $today->toDateString() }}" required class="input">
                </div>
                <button class="btn-primary">Buka checklist {{ $position->name }}</button>
            </form>
        @endunless
    @else
        {{-- Ganti departemen memuat ulang daftar jabatan dan staff (form terpisah agar tidak bersarang). --}}
        <form id="department-form" method="GET" action="{{ route('fill.create') }}"></form>
        <form method="POST" action="{{ route('fill.start') }}" class="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
            @csrf
            @if ($departments->count() > 1)
                <div>
                    <label for="department" class="label">Departemen</label>
                    <select id="department" name="department" form="department-form" class="input" onchange="this.form.submit()">
                        @foreach ($departments as $option)
                            <option value="{{ $option->id }}" @selected($department?->id === $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label for="position" class="label">Jabatan</label>
                <select id="position" name="position" class="input" required>
                    <option value="">Pilih jabatan</option>
                    @foreach ($positions as $option)
                        <option value="{{ $option->id }}" @selected((int) old('position', $position?->id) === $option->id)>{{ $option->name }} ({{ $option->active_tasks_count }} tugas)</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="date" class="label">Tanggal</label>
                <input id="date" type="date" name="date" value="{{ old('date', $date->toDateString()) }}" max="{{ $today->toDateString() }}" required class="input">
            </div>
            <div>
                <label for="employee" class="label">Nama staff yang dicek</label>
                <select id="employee" name="employee" class="input" required>
                    <option value="">Pilih staff</option>
                    @foreach ($employees as $option)
                        <option value="{{ $option->id }}" @selected((int) old('employee', $employee?->id) === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2 lg:col-span-4 lg:text-right">
                <button class="btn-primary w-full sm:w-auto">Mulai checklist</button>
            </div>
        </form>
    @endif

    @unless ($staffMode)
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
                            <a href="{{ route('team.positions.edit') }}" class="font-semibold underline">Atur jabatan staff</a></li>
                    @endif
                </ul>
            </div>
        @endif

        <div class="card mb-4 p-4">
            <h2 class="font-display text-xl">Buat checklist untuk semua staff</h2>
            <p class="mb-3 text-sm text-slate-500">Satu tombol membuatkan checklist kosong untuk setiap staff yang masuk kerja menurut jadwal di Absensi. Yang libur, cuti, izin, atau jadwalnya belum diisi dilewati. Aman ditekan berulang.</p>
            <form method="POST" action="{{ route('fill.assign-all') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                @if ($departments->count() > 1)
                    <div>
                        <label for="assign-department" class="label">Departemen</label>
                        <select id="assign-department" name="department" class="input">
                            <option value="all">Semua departemen</option>
                            @foreach ($departments as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <label for="assign-date" class="label">Tanggal</label>
                    <input id="assign-date" type="date" name="date" value="{{ $today->toDateString() }}" max="{{ $today->toDateString() }}" required class="input">
                </div>
                <button class="btn-primary">Buat checklist hari ini</button>
                <a href="{{ route('team.positions.edit') }}" class="btn-secondary">Atur jabatan staff</a>
            </form>

            <details class="mt-4 border-t border-slate-100 pt-3">
                <summary class="cursor-pointer text-sm font-semibold text-sky-700">Pembuatan otomatis tiap hari</summary>
                <p class="mb-2 mt-2 text-sm text-slate-500">Isi jam (WITA) supaya checklist dibuatkan sendiri setiap hari; kosongkan untuk mematikan. Jadwal yang baru diisi sesudahnya bisa disusul dengan tombol di atas.</p>
                <div class="divide-y divide-slate-100">
                    @foreach ($departments as $option)
                        <form method="POST" action="{{ route('fill.auto-assign', $option) }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                            @csrf
                            @method('PUT')
                            <span class="font-medium text-slate-700">{{ $option->name }}@if ($option->auto_assigned_on) <span class="font-normal text-slate-500">· terakhir {{ $option->auto_assigned_on->translatedFormat('d M') }}</span>@endif</span>
                            <span class="flex items-center gap-2">
                                <input type="time" name="auto_assign_at" value="{{ $option->auto_assign_at ? substr($option->auto_assign_at, 0, 5) : '' }}" class="input !w-32" aria-label="Jam otomatis {{ $option->name }}">
                                <button class="btn-secondary !px-4 !py-1.5">Simpan</button>
                            </span>
                        </form>
                    @endforeach
                </div>
            </details>
        </div>
    @endunless

    @if ($resume->isNotEmpty())
        <div class="card mb-4 p-4">
            <h2 class="mb-2 font-display text-xl">Lanjutkan checklist</h2>
            <p class="mb-2 text-sm text-slate-500">Checklist yang belum lengkap (dibuatkan sekaligus atau baru terisi sebagian), 7 hari terakhir.</p>
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
