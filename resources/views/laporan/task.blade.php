<x-app-layout title="{{ $title }}">
    @include('laporan.partials.head', ['active' => 'issues', 'picker' => true, 'heading' => $title, 'action' => route('laporan.task'), 'keep' => $query])

    <p class="-mt-3 mb-5 text-sm text-slate-500"><a class="underline" href="{{ route('laporan.issues', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'department_id' => $department->id]) }}">&larr; {{ __('Kembali ke Masalah & Pending') }}</a> · {{ $department->name }}</p>

    <section class="card mb-6 p-5">
        <h2 class="font-display text-xl text-slate-900">{{ __('Per hari') }}</h2>
        @if ($detail['trend'])
            <div class="mt-3 flex items-end gap-1 overflow-x-auto" style="height: 8rem" role="img" aria-label="{{ __('Grafik jumlah tugas bermasalah per hari') }}">
                @php $max = max(1, max(array_map(fn ($d) => $d['pending'] + $d['ongoing'] + $d['blank'], $detail['trend']))); @endphp
                @foreach ($detail['trend'] as $day)
                    @php $bad = $day['pending'] + $day['ongoing'] + $day['blank']; @endphp
                    <div class="flex h-full min-w-6 flex-1 flex-col items-center justify-end gap-1" title="{{ $day['date'] }}: Pending {{ $day['pending'] }}, Ongoing {{ $day['ongoing'] }}, {{ __('Kosong') }} {{ $day['blank'] }}">
                        <div class="w-full rounded-t {{ $day['pending'] ? 'bg-rose-600' : 'bg-amber-400' }}" style="height: {{ $bad / $max * 100 }}%; min-height: {{ $bad ? '4px' : '0' }}"></div>
                        <span class="text-[10px] text-slate-500">{{ \Carbon\CarbonImmutable::parse($day['date'])->format('d/m') }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <p class="mt-2 text-sm text-slate-500">{{ __('Belum ada data.') }}</p>
        @endif
    </section>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        <section class="card overflow-hidden">
            <h2 class="border-b border-slate-100 p-5 font-display text-xl text-slate-900">{{ __('Staff yang paling sering') }}</h2>
            @forelse ($detail['staff'] as $row)
                <div class="flex justify-between border-b border-slate-100 px-5 py-2 text-sm last:border-0"><span>{{ $row['name'] }}</span><span class="tabular-nums text-slate-600">Pending {{ $row['pending'] }} · Ongoing {{ $row['ongoing'] }} · {{ __('Kosong') }} {{ $row['blank'] }} / {{ $row['total'] }}</span></div>
            @empty
                <p class="p-5 text-sm text-slate-500">{{ __('Tidak ada.') }}</p>
            @endforelse
        </section>
        <section class="card overflow-hidden">
            <h2 class="border-b border-slate-100 p-5 font-display text-xl text-slate-900">{{ __('Kejadian') }}</h2>
            @forelse ($detail['events'] as $event)
                <div class="border-b border-slate-100 px-5 py-2 text-sm last:border-0">
                    <a class="underline" href="{{ route('checklists.show', $event['checklist_id']) }}">{{ \Carbon\CarbonImmutable::parse($event['date'])->translatedFormat('d M Y') }}</a> · {{ $event['employee'] }} · <strong>{{ $event['status'] ? ucfirst($event['status']) : __('Kosong') }}</strong>
                    @if ($event['note'])<p class="text-slate-600">{{ $event['note'] }}</p>@endif
                </div>
            @empty
                <p class="p-5 text-sm text-slate-500">{{ __('Tidak ada.') }}</p>
            @endforelse
        </section>
    </div>
</x-app-layout>
