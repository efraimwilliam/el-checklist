<x-app-layout title="{{ $name }}">
    @include('laporan.partials.head', ['picker' => true, 'heading' => $name, 'action' => route('laporan.building'), 'keep' => ['nama' => $name]])
    @php $tones = ['baru' => 'bg-rose-50 text-rose-700', 'dikerjakan' => 'bg-sky-50 text-sky-800', 'tertunda' => 'bg-amber-50 text-amber-800', 'selesai' => 'bg-emerald-50 text-emerald-800']; $labels = \App\Models\RoomIssue::statusLabels(); $dateParams = ['from' => $from->toDateString(), 'to' => $to->toDateString()]; @endphp

    <p class="-mt-3 mb-5 text-sm text-slate-500"><a class="underline" href="{{ route('laporan.rooms', $dateParams) }}">&larr; {{ __('Kembali ke Peta kamar') }}</a> · {{ $rooms->count() }} {{ __('kamar') }} · {{ $issues->count() }} {{ __('kendala pada rentang ini') }}</p>

    @if ($rooms->isNotEmpty())
        <section class="card mb-6 p-5">
            <h2 class="font-display text-xl text-slate-900">{{ __('Kamar di sini') }}</h2>
            <div class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($rooms as $room)
                    @php $n = $byRoom[$room->id] ?? 0; @endphp
                    <a href="{{ route('laporan.room', ['room' => $room->id] + $dateParams) }}" class="rounded-lg border px-2.5 py-1 text-xs font-semibold {{ $n ? 'border-rose-200 bg-rose-50 text-rose-800 hover:bg-rose-100' : 'border-slate-200 bg-slate-50 text-slate-600 hover:bg-slate-100' }}">{{ $room->number }}@if ($n) · {{ $n }}×@endif</a>
                @endforeach
            </div>
        </section>
    @else
        <div class="card mb-6 p-6 text-sm text-slate-600">{{ __('Belum ada kamar yang kolom "Gedung"-nya :name. Atur di Data kamar (ubah kamar, atau "Atur gedung" untuk banyak kamar sekaligus).', ['name' => '"'.$name.'"']) }}</div>
    @endif

    <section class="card mb-6 overflow-hidden">
        @forelse ($issues as $issue)
            <a href="{{ route('kamar.issues.show', $issue) }}" class="flex flex-col gap-1 border-b border-slate-100 p-5 transition last:border-0 hover:bg-amber-50/50 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="text-sm text-slate-900"><span class="font-display text-lg">{{ $issue->room->number }}</span> · {{ $issue->description }}@if ($issue->recurrence > 1) <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900">{{ __('Berulang ke-:n', ['n' => $issue->recurrence]) }}</span>@endif</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $issue->created_at->translatedFormat('d M Y H:i') }} · {{ $issue->category?->name ?? __('Tanpa kategori') }} · {{ $issue->department?->name ?? __('Belum ditugaskan') }}</p>
                </div>
                <span class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold {{ $tones[$issue->status] }}">{{ $labels[$issue->status] }}</span>
            </a>
        @empty
            <p class="p-10 text-center text-sm text-slate-500">{{ __('Tidak ada kendala di gedung / area ini pada rentang tanggal tersebut.') }}</p>
        @endforelse
    </section>
</x-app-layout>
