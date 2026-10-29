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
        <form method="POST" action="{{ route('fill.start') }}" class="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
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
            <div>
                <label for="checker" class="label">Dicek oleh (HOD / atasan)</label>
                <input id="checker" name="checker" value="{{ old('checker', $checker) }}" maxlength="255" class="input">
            </div>
            <div class="sm:col-span-2 lg:col-span-5 lg:text-right">
                <button class="btn-primary w-full sm:w-auto">Mulai checklist</button>
            </div>
        </form>
    @endif

    @if ($resume->isNotEmpty())
        <div class="card mb-4 p-4">
            <h2 class="mb-2 font-display text-xl">Lanjutkan checklist</h2>
            <p class="mb-2 text-sm text-slate-500">Checklist yang sudah mulai diisi tetapi belum lengkap (7 hari terakhir).</p>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($resume as $draft)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                        <a href="{{ route('checklists.fill', $draft) }}" class="font-medium text-sky-700 hover:underline">{{ $draft->employee->name }} · {{ $draft->position_name }} · {{ $draft->date->translatedFormat('d M Y') }}</a>
                        <span class="pill-neutral">{{ $draft->answeredCount() }} / {{ $draft->items->count() }} terisi</span>
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
