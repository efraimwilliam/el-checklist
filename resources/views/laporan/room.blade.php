<x-app-layout title="{{ __('Kamar') }} {{ $room->number }}">
    @include('laporan.partials.head', ['picker' => true, 'heading' => __('Kamar').' '.$room->number, 'action' => route('laporan.room', $room), 'keep' => []])
    @php $tones = ['baru' => 'bg-rose-50 text-rose-700', 'dikerjakan' => 'bg-sky-50 text-sky-800', 'tertunda' => 'bg-amber-50 text-amber-800', 'selesai' => 'bg-emerald-50 text-emerald-800']; $labels = \App\Models\RoomIssue::statusLabels(); @endphp

    <p class="-mt-3 mb-5 text-sm text-slate-500"><a class="underline" href="{{ route('laporan.rooms', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">&larr; {{ __('Kembali ke Peta kamar') }}</a>
        @if ($room->floor) · {{ __('Lantai') }} {{ $room->floor }}@endif @if ($room->type) · {{ $room->type }}@endif · {{ $issues->count() }} {{ __('kendala pada rentang ini') }}</p>

    <section class="card mb-6 overflow-hidden">
        @forelse ($issues as $issue)
            <a href="{{ route('kamar.issues.show', $issue) }}" class="flex flex-col gap-1 border-b border-slate-100 p-5 transition last:border-0 hover:bg-amber-50/50 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="text-sm text-slate-900">{{ $issue->description }}@if ($issue->recurrence > 1) <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900">{{ __('Berulang ke-:n', ['n' => $issue->recurrence]) }}</span>@endif</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $issue->created_at->translatedFormat('d M Y H:i') }} · {{ $issue->category?->name ?? __('Tanpa kategori') }} · {{ $issue->department?->name ?? __('Belum ditugaskan') }}</p>
                </div>
                <span class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold {{ $tones[$issue->status] }}">{{ $labels[$issue->status] }}</span>
            </a>
        @empty
            <p class="p-10 text-center text-sm text-slate-500">{{ __('Tidak ada kendala untuk kamar ini pada rentang tanggal tersebut.') }}</p>
        @endforelse
    </section>
</x-app-layout>
