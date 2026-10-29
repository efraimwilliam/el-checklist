<x-app-layout title="Review checklist">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <h1 class="text-xl font-semibold">Review checklist</h1>
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
                <label class="label" for="status">Status</label>
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
            <thead class="bg-slate-50 text-left text-slate-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Pegawai</th>
                    <th class="px-4 py-2 font-medium">Departemen / jabatan</th>
                    <th class="px-4 py-2 font-medium">Skor</th>
                    <th class="px-4 py-2 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($checklists as $checklist)
                    <tr>
                        <td class="px-4 py-2"><a href="{{ route('checklists.show', $checklist) }}" class="font-medium text-sky-700 hover:underline">{{ $checklist->employee->name }}</a></td>
                        <td class="px-4 py-2 text-slate-600">{{ $checklist->department->name }} · {{ $checklist->position_name }}</td>
                        <td class="px-4 py-2">{{ $checklist->score() !== null ? $checklist->score().'%' : '—' }}</td>
                        <td class="px-4 py-2"><x-status-badge :status="$checklist->status" /></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Belum ada checklist untuk tanggal ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
