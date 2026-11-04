{{-- Satu kendala di track record kamar: ringkasan + pekerjaan yang dicatat (hanya Ongoing / Pending / Done, tanpa catatan sistem). --}}
@php
    $tones = ['baru' => 'bg-rose-50 text-rose-700', 'dikerjakan' => 'bg-sky-50 text-sky-800', 'tertunda' => 'bg-amber-50 text-amber-800', 'selesai' => 'bg-emerald-50 text-emerald-800'];
    $labels = \App\Models\RoomIssue::statusLabels();
    $work = $past->updates->whereIn('kind', ['catatan', 'tunda', 'selesai']);
@endphp
<li class="rounded-xl bg-slate-50 px-4 py-3 text-sm">
    <p class="flex flex-wrap items-center gap-2">
        @can('view', $past)<a class="font-medium text-slate-900 underline decoration-slate-300 underline-offset-2" href="{{ route('kamar.issues.show', $past) }}">{{ $past->description }}</a>@else<span class="font-medium text-slate-900">{{ $past->description }}</span>@endcan
        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $tones[$past->status] }}">{{ $labels[$past->status] }}</span>
        @if ($past->recurrence > 1)<span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900">{{ __('Berulang ke-:n', ['n' => $past->recurrence]) }}</span>@endif
    </p>
    <p class="mt-0.5 text-xs text-slate-500">
        {{ $past->created_at->translatedFormat('d M Y') }} · {{ $past->category?->name ?? __('Tanpa kategori') }} · {{ $past->department?->name ?? __('Belum ditugaskan') }}
        @if ($past->claimer) · {{ __('dikerjakan') }} {{ $past->claimer->name() }}@endif
    </p>
    @if ($work->isNotEmpty())
        <ul class="mt-2 space-y-1 border-l-2 border-slate-200 pl-3 text-slate-700">
            @foreach ($work as $entry)
                <li><span class="text-xs text-slate-500">{{ $entry->created_at->translatedFormat('d M H:i') }} · {{ $entry->user?->name() ?? '—' }}:</span> {{ $entry->body }}
                    @if ($entry->photos->isNotEmpty())<span class="mt-1 flex flex-wrap gap-1.5">@foreach ($entry->photos as $photo)<a href="{{ route('kamar.issues.photo', [$past, $photo]) }}" target="_blank" rel="noopener"><img src="{{ route('kamar.issues.photo', [$past, $photo]) }}" alt="{{ __('Foto pekerjaan') }}" loading="lazy" class="h-14 w-14 rounded-lg object-cover ring-1 ring-slate-200"></a>@endforeach</span>@endif
                </li>
            @endforeach
        </ul>
    @else
        <p class="mt-1 text-xs text-slate-400">{{ __('Belum ada pekerjaan yang dicatat.') }}</p>
    @endif
</li>
