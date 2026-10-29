<x-app-layout title="Isi checklist">
    <div class="mb-4">
        <h1 class="text-3xl text-slate-900">{{ $staffMode ? 'Checklist saya' : 'Isi checklist harian' }}</h1>
        <p class="text-sm text-slate-500">
            {{ $staffMode ? 'Tandai setiap tugas, lalu simpan.' : 'Pilih jabatan dan staff yang dicek, lalu tandai setiap tugas. Satu checklist = satu staff, satu jabatan, satu tanggal.' }}
        </p>
    </div>

    @error('duplicate') <p class="mb-4 rounded-xl bg-amber-100 px-4 py-3 text-sm text-amber-800">{{ $message }}</p> @enderror

    @if ($staffMode)
        <form method="GET" action="{{ route('fill.create') }}" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
            <div>
                <label for="date" class="label">Tanggal</label>
                <input id="date" type="date" name="date" value="{{ $date->toDateString() }}" max="{{ $today->toDateString() }}" class="input">
            </div>
            <button class="btn-secondary">Buka</button>
        </form>
    @else
        <form method="GET" action="{{ route('fill.create') }}" class="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
            @if ($departments->count() > 1)
                <div>
                    <label for="department" class="label">Departemen</label>
                    <select id="department" name="department" class="input" onchange="this.form.submit()">
                        @foreach ($departments as $option)
                            <option value="{{ $option->id }}" @selected($department?->id === $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <input type="hidden" name="department" value="{{ $department?->id }}">
            @endif
            <div>
                <label for="position" class="label">Jabatan</label>
                <select id="position" name="position" class="input" required>
                    <option value="">Pilih jabatan</option>
                    @foreach ($positions as $option)
                        <option value="{{ $option->id }}" @selected($position?->id === $option->id)>{{ $option->name }} ({{ $option->active_tasks_count }} tugas)</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="date" class="label">Tanggal</label>
                <input id="date" type="date" name="date" value="{{ $date->toDateString() }}" max="{{ $today->toDateString() }}" required class="input">
            </div>
            <div>
                <label for="employee" class="label">Nama staff yang dicek</label>
                <select id="employee" name="employee" class="input" required>
                    <option value="">Pilih staff</option>
                    @foreach ($employees as $option)
                        <option value="{{ $option->id }}" @selected($employee?->id === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="checker" class="label">Dicek oleh (HOD / atasan)</label>
                <input id="checker" name="checker" value="{{ $checker }}" maxlength="255" class="input">
            </div>
            <div class="sm:col-span-2 lg:col-span-5 lg:text-right">
                <button name="mulai" value="1" class="btn-primary w-full sm:w-auto">Mulai checklist</button>
            </div>
        </form>
    @endif

    @if ($problem)
        <p class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $problem }}</p>
    @elseif ($mode === 'locked')
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-100 px-4 py-3 text-sm text-amber-800">
            Checklist ini sudah direview oleh {{ $checklist->reviewer?->name() ?? 'GM / HR' }} dan terkunci. Perubahan hanya lewat HR.
            <a href="{{ route('checklists.show', $checklist) }}" class="font-semibold underline">Lihat checklist</a>
        </div>
    @elseif ($mode === 'empty')
        <p class="mb-4 rounded-xl bg-slate-100 px-4 py-3 text-sm text-slate-600">Jabatan ini belum punya tugas aktif. Minta HR menambahkannya di menu Kelola tugas.</p>
    @elseif ($mode)
        @include('checklists.partials.form')
    @endif

    @if ($staffMode && $history->isNotEmpty())
        <div class="card mt-6 p-4">
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
