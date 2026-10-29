<x-app-layout title="Riwayat checklist">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <h1 class="text-3xl text-slate-900">Riwayat checklist</h1>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div>
                <label class="label" for="date">Tanggal</label>
                <input id="date" type="date" name="date" value="{{ $date->toDateString() }}" class="input">
            </div>
            <div>
                <label class="label" for="department">Departemen</label>
                <select id="department" name="department" class="input">
                    <option value="">Semua</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected(($filters['department'] ?? null) == $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="status">Review</label>
                <select id="status" name="status" class="input">
                    <option value="">Semua</option>
                    @foreach (\App\Enums\ChecklistStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? null) === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn-secondary">Tampilkan</button>
        </form>
    </div>

    <div class="card overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-[0.14em] text-slate-500">
                <tr>
                    <th class="px-4 py-2.5 font-medium">Staff</th>
                    <th class="px-4 py-2.5 font-medium">Jabatan</th>
                    <th class="px-4 py-2.5 font-medium">Dicek oleh</th>
                    <th class="px-4 py-2.5 font-medium">Skor</th>
                    <th class="px-4 py-2.5 font-medium">Status tugas</th>
                    <th class="px-4 py-2.5 font-medium">Review</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($checklists as $checklist)
                    @php($counts = $checklist->counts())
                    <tr>
                        <td class="px-4 py-2.5">
                            <a href="{{ route('checklists.show', $checklist) }}" class="font-semibold text-slate-800 hover:text-sky-700">{{ $checklist->employee->name }}</a>
                            <span class="block text-xs text-slate-500">{{ $checklist->department->name }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-slate-600">{{ $checklist->position_name }}</td>
                        <td class="px-4 py-2.5 text-slate-600">{{ $checklist->checked_by ?? 'Diisi sendiri' }}</td>
                        <td class="px-4 py-2.5 font-semibold {{ ($checklist->score() ?? 0) >= 90 ? 'text-emerald-700' : (($checklist->score() ?? 0) >= 70 ? 'text-amber-800' : 'text-rose-700') }}">{{ $checklist->score() !== null ? $checklist->score().'%' : '—' }}</td>
                        <td class="px-4 py-2.5">
                            <div class="flex flex-wrap gap-1">
                                <span class="pill-done">{{ $counts['done'] }} Done</span>
                                @if ($counts['ongoing']) <span class="pill-ongoing">{{ $counts['ongoing'] }} Ongoing</span> @endif
                                @if ($counts['pending']) <span class="pill-pending">{{ $counts['pending'] }} Pending</span> @endif
                            </div>
                        </td>
                        <td class="px-4 py-2.5"><x-status-badge :status="$checklist->status" /></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">Belum ada checklist untuk tanggal ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
