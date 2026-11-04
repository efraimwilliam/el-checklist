<x-app-layout title="{{ __('Data tugas') }}">
    @include('laporan.partials.head', ['active' => 'data', 'picker' => true, 'heading' => __('Data tugas'), 'action' => route('laporan.data'), 'keep' => $filters])
    @php $statusLabels = ['done' => 'Done', 'ongoing' => 'Ongoing', 'pending' => 'Pending', 'empty' => __('Belum diisi')]; @endphp

    <form method="GET" action="{{ route('laporan.data') }}" class="card mb-6 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4 print:hidden">
        <input type="hidden" name="from" value="{{ $from->toDateString() }}">
        <input type="hidden" name="to" value="{{ $to->toDateString() }}">
        <label class="text-sm text-slate-600">{{ __('Departemen') }}
            <select name="department_id" class="input mt-1 w-full" onchange="this.form.submit()">
                <option value="">{{ __('Semua') }}</option>
                @foreach ($departments as $department)<option value="{{ $department->id }}" @selected($filters['department_id'] === $department->id)>{{ $department->name }}</option>@endforeach
            </select>
        </label>
        <label class="text-sm text-slate-600">{{ __('Jabatan') }}
            <select name="position" class="input mt-1 w-full">
                <option value="">{{ __('Semua') }}</option>
                @foreach ($positions as $position)<option @selected($filters['position'] === $position)>{{ $position }}</option>@endforeach
            </select>
        </label>
        <label class="text-sm text-slate-600">{{ __('Staff') }}
            <select name="employee_id" class="input mt-1 w-full">
                <option value="">{{ __('Semua') }}</option>
                @foreach ($employees as $id => $name)<option value="{{ $id }}" @selected($filters['employee_id'] === $id)>{{ $name }}</option>@endforeach
            </select>
        </label>
        <label class="text-sm text-slate-600">{{ __('Status') }}
            <select name="status" class="input mt-1 w-full">
                <option value="">{{ __('Semua') }}</option>
                @foreach ($statusLabels as $value => $label)<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="text-sm text-slate-600 sm:col-span-2">{{ __('Cari tugas atau catatan') }}
            <input type="search" name="q" value="{{ $filters['q'] }}" maxlength="100" class="input mt-1 w-full">
        </label>
        <label class="flex items-center gap-2 self-end pb-2 text-sm text-slate-600"><input type="checkbox" name="has_note" value="1" @checked($filters['has_note'])> {{ __('Hanya yang ada catatan') }}</label>
        <div class="flex items-end gap-2">
            <button class="btn-primary">{{ __('Terapkan') }}</button>
            <a href="{{ route('laporan.data', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" class="btn-secondary">{{ __('Atur ulang') }}</a>
        </div>
    </form>

    <section class="card mb-6 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
            <p class="text-sm text-slate-600">{{ number_format($rows->total(), 0, ',', '.') }} {{ __('isian tugas') }}</p>
            <a href="{{ $exportUrl }}" class="btn-secondary print:hidden">{{ __('Unduh Excel') }}</a>
        </div>
        @if ($rows->count())
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr>
                        <th class="px-4 py-2">{{ __('Tanggal') }}</th><th class="px-4 py-2">{{ __('Departemen') }}</th><th class="px-4 py-2">{{ __('Staff') }}</th><th class="px-4 py-2">{{ __('Jabatan') }}</th>
                        <th class="px-4 py-2">{{ __('Tugas') }}</th><th class="px-4 py-2">{{ __('Status') }}</th><th class="px-4 py-2">{{ __('Catatan / nilai') }}</th><th class="px-4 py-2 text-right">{{ __('Foto') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2"><a class="underline" href="{{ route('checklists.show', $row->checklist_id) }}">{{ \Carbon\CarbonImmutable::parse($row->date)->translatedFormat('d M Y') }}</a></td>
                                <td class="px-4 py-2">{{ $row->department }}</td><td class="px-4 py-2">{{ $row->employee }}</td><td class="px-4 py-2">{{ $row->position }}</td>
                                <td class="px-4 py-2">{{ $row->title }}</td>
                                <td class="px-4 py-2 {{ $row->status === 'pending' ? 'font-semibold text-rose-700' : '' }}">{{ $statusLabels[$row->status ?? 'empty'] }}</td>
                                <td class="px-4 py-2 text-slate-600">{{ $row->note }}@if ($row->params)<span class="block text-xs text-slate-500">{{ $row->params }}</span>@endif</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row->photos ?: '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex items-center justify-between p-4 text-sm print:hidden">
                <span class="text-slate-500">{{ __('Halaman') }} {{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>
                <span class="flex gap-2">
                    @if ($rows->previousPageUrl())<a class="btn-secondary" href="{{ $rows->previousPageUrl() }}">&larr; {{ __('Sebelumnya') }}</a>@endif
                    @if ($rows->hasMorePages())<a class="btn-secondary" href="{{ $rows->nextPageUrl() }}">{{ __('Berikutnya') }} &rarr;</a>@endif
                </span>
            </div>
        @else
            <p class="p-5 text-sm text-slate-500">{{ __('Tidak ada data untuk filter ini.') }}</p>
        @endif
    </section>
</x-app-layout>
