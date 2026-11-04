<x-app-layout title="{{ __('Laporan bulanan') }}">
    <div class="mb-5">
        <h1 class="text-3xl text-slate-900">{{ __('Laporan bulanan') }}</h1>
        <p class="text-sm text-slate-500">{{ __('Dari ringkasan harian yang disimpan tiap malam (12 bulan terakhir), jadi tetap ada walau checklist lama dihapus.') }}</p>
    </div>

    <form method="GET" action="{{ route('laporan.monthly') }}" class="mb-6 print:hidden">
        <label class="text-sm text-slate-600">{{ __('Departemen') }}
            <select name="department_id" class="input mt-1" onchange="this.form.submit()">
                <option value="">{{ __('Semua departemen') }}</option>
                @foreach ($departments as $department)<option value="{{ $department->id }}" @selected($departmentId === $department->id)>{{ $department->name }}</option>@endforeach
            </select>
        </label>
    </form>

    <section class="card mb-6 overflow-hidden">
        @if ($months)
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr>
                        <th class="px-4 py-2">{{ __('Bulan') }}</th><th class="px-4 py-2 text-right">{{ __('Hari') }}</th><th class="px-4 py-2 text-right">{{ __('Checklist') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Skor') }}</th><th class="px-4 py-2 text-right">Pending</th><th class="px-4 py-2 text-right">{{ __('% Pending') }}</th><th class="px-4 py-2 text-right">{{ __('Kosong') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($months as $row)
                            <tr @class(['bg-amber-50' => $row['month'] === $month])>
                                <td class="px-4 py-2"><a class="underline" href="{{ route('laporan.monthly', ['department_id' => $departmentId, 'bulan' => $row['month']]) }}">{{ \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $row['month'].'-01')->translatedFormat('F Y') }}</a></td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['days'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ number_format($row['checklists'], 0, ',', '.') }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['score'] !== null ? $row['score'].'%' : '—' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-rose-700">{{ number_format($row['pending'], 0, ',', '.') }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['pendingRate'] !== null ? $row['pendingRate'].'%' : '—' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ number_format($row['blank'], 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="p-5 text-sm text-slate-500">{{ __('Belum ada ringkasan tersimpan. Jalankan "php artisan laporan:snapshot --backfill" sekali untuk mengisi data lama.') }}</p>
        @endif
    </section>

    @if ($byDepartment)
        <section class="card mb-6 overflow-hidden">
            <h2 class="border-b border-slate-100 p-5 font-display text-xl text-slate-900">{{ __('Per departemen') }} · {{ \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->translatedFormat('F Y') }}</h2>
            @foreach ($byDepartment as $row)
                <div class="flex flex-wrap justify-between gap-2 border-b border-slate-100 px-5 py-2 text-sm last:border-0">
                    <span>{{ $row['name'] }}</span>
                    <span class="tabular-nums text-slate-600">{{ __('Skor') }} {{ $row['score'] !== null ? $row['score'].'%' : '—' }} · {{ number_format($row['checklists'], 0, ',', '.') }} {{ __('checklist') }} · Pending {{ $row['pending'] }} · {{ __('Kosong') }} {{ $row['blank'] }}</span>
                </div>
            @endforeach
        </section>
    @endif
</x-app-layout>
