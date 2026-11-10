<x-app-layout title="{{ __('Riwayat penanganan :place', ['place' => $issue->placeTitle()]) }}">
    @php
        $labels = \App\Models\RoomIssue::statusLabels();
        $kinds = [
            'dicatat' => [__('Kendala dicatat'), 'bg-rose-500'], 'laporan' => [__('Laporan tambahan'), 'bg-rose-400'], 'ambil' => [__('Diambil'), 'bg-sky-500'],
            'catatan' => [__('Ongoing'), 'bg-sky-400'], 'tunda' => [__('Pending'), 'bg-amber-500'], 'selesai' => [__('Done'), 'bg-emerald-600'],
            'lepas' => [__('Pengambilan dilepas'), 'bg-slate-400'], 'berulang' => [__('Muncul lagi'), 'bg-amber-400'],
        ];
        // 135 -> "2 j 15 m"; kurang dari semenit -> "< 1 m".
        $span = function (?int $minutes) {
            if ($minutes === null) return '—';
            if ($minutes < 1) return '< 1 m';
            $days = intdiv($minutes, 1440); $hours = intdiv($minutes % 1440, 60); $mins = $minutes % 60;
            return trim(($days ? $days.' h ' : '').($hours ? $hours.' j ' : '').($mins && ! $days ? $mins.' m' : ''));
        };
    @endphp

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 print:hidden">
        <a href="{{ route('kamar.issues.show', $issue) }}" class="text-sm text-sky-700 hover:underline">{{ __('← Kembali ke kendala') }}</a>
        <button type="button" class="btn-primary" onclick="window.print()">{{ __('Cetak / simpan PDF') }}</button>
    </div>

    <article class="card mx-auto max-w-4xl p-6 sm:p-8 print:max-w-none print:border-0 print:p-0 print:shadow-none">
        <header class="border-b border-slate-200 pb-4">
            <p class="text-xs font-semibold uppercase tracking-[0.12em] text-gold-700">eL Hotel &amp; Resort Sanur</p>
            <h1 class="mt-1 text-2xl text-slate-900 sm:text-3xl">{{ __('Riwayat Penanganan Kendala') }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('Kendala') }} #{{ $issue->id }} · {{ $issue->placeTitle() }}</p>
        </header>

        {{-- Ringkasan --}}
        <dl class="mt-5 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-slate-500">{{ $issue->room_id ? __('Kamar') : __('Lokasi') }}</dt><dd class="font-medium text-slate-900">{{ $issue->placeName() }}{{ $issue->room?->floor ? ' · '.__('Lantai').' '.$issue->room->floor : '' }}{{ $issue->room?->building ? ' · '.$issue->room->building : '' }}{{ $issue->room?->type ? ' · '.$issue->room->type : '' }}</dd></div>
            <div><dt class="text-slate-500">{{ __('Kategori') }}</dt><dd class="font-medium text-slate-900">{{ $issue->category?->name ?? __('Tanpa kategori') }}</dd></div>
            <div><dt class="text-slate-500">{{ __('Status') }}</dt><dd class="font-medium text-slate-900">{{ $labels[$issue->status] }}@if ($issue->priority === 'mendesak') · {{ __('Mendesak') }}@endif @if ($issue->recurrence > 1) · {{ __('Berulang ke-:n', ['n' => $issue->recurrence]) }}@endif</dd></div>
            <div><dt class="text-slate-500">{{ __('Sumber laporan') }}</dt><dd class="font-medium text-slate-900">{{ $sources[$issue->source] ?? $issue->source }}</dd></div>
            <div><dt class="text-slate-500">{{ __('Diteruskan ke') }}</dt><dd class="font-medium text-slate-900">{{ $issue->targetLabel() }}</dd></div>
            <div><dt class="text-slate-500">{{ __('Dikerjakan oleh') }}</dt><dd class="font-medium text-slate-900">{{ $issue->claimer?->name() ?? '—' }}</dd></div>
        </dl>

        <div class="mt-5 grid grid-cols-3 gap-3 rounded-xl bg-slate-50 p-4 text-center text-sm print:border print:border-slate-200 print:bg-white">
            <div><p class="text-xs text-slate-500">{{ __('Waktu respons') }}</p><p class="font-display text-lg text-slate-900">{{ $span($respondedAfter) }}</p><p class="text-[11px] text-slate-400">{{ __('dicatat sampai diambil') }}</p></div>
            <div><p class="text-xs text-slate-500">{{ __('Waktu penanganan') }}</p><p class="font-display text-lg text-slate-900">{{ $span($handledAfter) }}</p><p class="text-[11px] text-slate-400">{{ __('diambil sampai Done') }}</p></div>
            <div><p class="text-xs text-slate-500">{{ $issue->isDone() ? __('Total') : __('Berjalan') }}</p><p class="font-display text-lg text-slate-900">{{ $span($totalMinutes) }}</p><p class="text-[11px] text-slate-400">{{ $issue->isDone() ? __('dicatat sampai Done') : __('sejak dicatat') }}</p></div>
        </div>

        {{-- Kronologi --}}
        <h2 class="mt-7 font-display text-xl text-slate-900">{{ __('Kronologi') }}</h2>
        <ol class="mt-3">
            @foreach ($events as $event)
                @php [$label, $dot] = $kinds[$event['kind']] ?? [$event['kind'], 'bg-slate-300']; $previous = $loop->first ? null : $events[$loop->index - 1]['at']; @endphp
                <li class="relative break-inside-avoid pb-5 pl-7 last:pb-0">
                    @unless ($loop->last)<span class="absolute bottom-0 left-[0.4375rem] top-3 w-px bg-slate-200" aria-hidden="true"></span>@endunless
                    <span class="absolute left-0 top-1.5 h-3.5 w-3.5 rounded-full ring-4 ring-white {{ $dot }}" aria-hidden="true"></span>
                    <p class="flex flex-wrap items-baseline gap-x-2 text-sm">
                        <span class="font-semibold text-slate-900">{{ $label }}</span>
                        <span class="text-slate-500">{{ $event['at']->translatedFormat('d M Y, H:i') }} WITA</span>
                        @if ($previous)<span class="text-xs text-slate-400">+ {{ $span((int) $previous->diffInMinutes($event['at'])) }}</span>@endif
                    </p>
                    <p class="mt-0.5 text-sm text-slate-700">{{ $event['body'] }}</p>
                    <p class="text-xs text-slate-500">{{ $event['actor'] ?? __('Pengguna dihapus') }}@if ($event['department']) · {{ $event['department'] }}@endif</p>
                    @if ($event['photos']->isNotEmpty())
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($event['photos'] as $photo)
                                <img src="{{ route('kamar.issues.photo', [$issue, $photo]) }}" alt="{{ __('Foto pekerjaan') }}" loading="lazy" class="h-28 w-40 rounded-lg object-cover ring-1 ring-slate-200 print:h-32 print:w-44">
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>

        @unless ($issue->isDone())
            <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 print:border print:border-amber-200">{{ __('Kendala ini belum Done. Kronologi mencatat kejadian sampai :time.', ['time' => now()->translatedFormat('d M Y, H:i')]) }}</p>
        @endunless

        {{-- Tanda tangan --}}
        <div class="mt-8 grid grid-cols-2 gap-8 text-center text-sm text-slate-600 break-inside-avoid">
            <div><p>{{ __('Dibuat oleh') }}</p><div class="mx-auto mt-14 w-48 border-t border-slate-400 pt-1">{{ $printedBy }}</div></div>
            <div><p>{{ __('Diketahui oleh') }}</p><div class="mx-auto mt-14 w-48 border-t border-slate-400 pt-1">&nbsp;</div></div>
        </div>
        <p class="mt-6 text-center text-xs text-slate-400">{{ __('Dicetak :time oleh :name', ['time' => now()->translatedFormat('d M Y, H:i'), 'name' => $printedBy]) }}</p>
    </article>
</x-app-layout>
