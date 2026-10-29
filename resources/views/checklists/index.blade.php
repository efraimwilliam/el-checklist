<x-app-layout title="Riwayat checklist">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <h1 class="text-3xl text-slate-900">Riwayat checklist</h1>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div>
                <span class="label" id="range-caption">Tanggal</span>
                <div class="relative" data-range-field data-max="{{ $today->toDateString() }}">
                    <input type="hidden" name="from" value="{{ $from->toDateString() }}" data-range-from>
                    <input type="hidden" name="to" value="{{ $to->toDateString() }}" data-range-to>
                    <button type="button" class="input flex min-w-64 items-center gap-2 text-left" data-range-trigger aria-haspopup="dialog" aria-expanded="false" aria-labelledby="range-caption">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8a6a2a" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>
                        <span data-range-label>{{ \App\Support\DateRange::label($from, $to) }}</span>
                    </button>
                    <div class="cal-panel hidden" data-range-panel role="dialog" aria-label="Pilih rentang tanggal"></div>
                </div>
            </div>
            <div>
                <label class="label" for="department">Departemen</label>
                <select id="department" name="department" class="input" onchange="this.form.submit()">
                    <option value="">Semua</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected(($filters['department'] ?? null) == $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="status">Review</label>
                <select id="status" name="status" class="input" onchange="this.form.submit()">
                    <option value="">Semua</option>
                    @foreach (\App\Enums\ChecklistStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? null) === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>

    <div class="card overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-[0.08em] text-slate-500">
                <tr>
                    <th class="px-4 py-2.5 font-medium">Staff</th>
                    <th class="px-4 py-2.5 font-medium">Tanggal</th>
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
                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $checklist->date->translatedFormat('d M Y') }}</td>
                        <td class="px-4 py-2.5 text-slate-600">{{ $checklist->position_name }}</td>
                        <td class="px-4 py-2.5 text-slate-600">{{ $checklist->checked_by ?? 'Diisi sendiri' }}</td>
                        <td class="px-4 py-2.5 font-semibold {{ ($checklist->score() ?? 0) >= 90 ? 'text-emerald-700' : (($checklist->score() ?? 0) >= 70 ? 'text-amber-800' : 'text-rose-700') }}">{{ $checklist->score() !== null ? $checklist->score().'%' : '—' }}</td>
                        <td class="px-4 py-2.5">
                            <div class="flex flex-wrap gap-1">
                                @unless ($checklist->isComplete() || $checklist->isReviewed())
                                    <span class="pill-neutral">Belum lengkap {{ $checklist->answeredCount() }}/{{ $checklist->items->count() }}</span>
                                @endunless
                                <span class="pill-done">{{ $counts['done'] }} Done</span>
                                @if ($counts['ongoing']) <span class="pill-ongoing">{{ $counts['ongoing'] }} Ongoing</span> @endif
                                @if ($counts['pending']) <span class="pill-pending">{{ $counts['pending'] }} Pending</span> @endif
                            </div>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-status-badge :status="$checklist->status" />
                                @can('review', $checklist)
                                    <form method="POST" action="{{ route('checklists.review', $checklist) }}">
                                        @csrf
                                        <button class="btn-secondary !px-3 !py-1 text-xs">Tandai direview</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-slate-500">Belum ada checklist pada rentang tanggal ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
