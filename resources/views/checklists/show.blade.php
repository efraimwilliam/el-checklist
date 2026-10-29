<x-app-layout :title="'Checklist '.$checklist->employee->name">
    @php
        $counts = $checklist->counts();
        $total = $checklist->items->count();
        $score = $checklist->score();
        $scoreClass = $score === null ? 'text-slate-400' : ($score >= 90 ? 'text-emerald-700' : ($score >= 70 ? 'text-amber-800' : 'text-rose-700'));
    @endphp

    <div class="card mb-4 p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-3xl leading-tight text-slate-900">{{ $checklist->employee->name }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $checklist->position_name }} · {{ $checklist->department->name }}</p>
            </div>
            <x-status-badge :status="$checklist->status" />
        </div>

        <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="label !mb-0.5">Tanggal</dt><dd>{{ $checklist->date->translatedFormat('d M Y') }}</dd></div>
            <div><dt class="label !mb-0.5">Dicek oleh</dt><dd>{{ $checklist->checked_by ?? 'Diisi sendiri' }}</dd></div>
            <div>
                <dt class="label !mb-0.5">Skor</dt>
                <dd><span class="font-display text-2xl {{ $scoreClass }}">{{ $score !== null ? $score.'%' : '—' }}</span> <span class="text-slate-500">({{ $counts['done'] }}/{{ $total }} Done)</span></dd>
            </div>
            <div><dt class="label !mb-0.5">Disimpan</dt><dd>{{ $checklist->updated_at->format('Y-m-d H:i:s') }}@if ($checklist->filler) <span class="text-slate-500">oleh {{ $checklist->filler->name() }}</span>@endif</dd></div>
            <div class="lg:col-span-4">
                <dt class="label !mb-0.5">Review</dt>
                <dd>
                    @if ($checklist->isReviewed())
                        Direview {{ $checklist->reviewer?->name() ?? '—' }}, {{ $checklist->reviewed_at?->format('d M Y H:i') }}
                    @else
                        Belum direview
                    @endif
                </dd>
            </div>
        </dl>

        <div class="mt-4 flex flex-wrap gap-2">
            <span class="pill-done">{{ $counts['done'] }} Done</span>
            <span class="pill-ongoing">{{ $counts['ongoing'] }} Ongoing</span>
            <span class="pill-pending">{{ $counts['pending'] }} Pending</span>
        </div>
    </div>

    <div class="card px-5 py-3">
        @php($number = 0)
        @foreach ($checklist->items->groupBy(fn ($item) => $item->section ?? '') as $section => $items)
            <div class="{{ $loop->first ? '' : 'mt-3 border-t border-slate-200 pt-3' }}">
                @if ($section !== '' || $checklist->items->pluck('section')->filter()->isNotEmpty())
                    <div class="flex items-center justify-between py-2 text-xs font-semibold uppercase tracking-[0.1em]">
                        <span class="text-gold-600">{{ $section !== '' ? $section : 'Tugas' }}</span>
                        <span class="text-slate-400">{{ $items->count() }} tugas</span>
                    </div>
                @endif
                @foreach ($items as $item)
                    @php($number++)
                    <div class="border-t border-slate-100 py-2.5 first:border-t-0">
                        <div class="flex items-start justify-between gap-4">
                            <p class="text-base leading-snug text-slate-800"><span class="mr-0.5 font-semibold tabular-nums text-slate-400">{{ $number }}.</span> {{ $item->title }}</p>
                            <x-status-badge :status="$item->status" class="shrink-0" />
                        </div>
                        @if ($item->note)
                            <p class="mt-1 text-sm text-slate-500">Catatan: {{ $item->note }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    @if ($checklist->extraTasks->isNotEmpty())
        <div class="card mt-4 px-5 py-3">
            <h2 class="py-2 font-display text-xl">Tugas tambahan</h2>
            @foreach ($checklist->extraTasks as $extra)
                <div class="flex items-start justify-between gap-4 border-t border-slate-100 py-2.5">
                    <div>
                        <p class="text-base text-slate-800">{{ $extra->title }}</p>
                        @if ($extra->note) <p class="mt-1 text-sm text-slate-500">Catatan: {{ $extra->note }}</p> @endif
                    </div>
                    <x-status-badge :status="$extra->status" class="shrink-0" />
                </div>
            @endforeach
        </div>
    @endif

    @if ($checklist->note)
        <div class="card mt-4 p-5 text-sm"><span class="font-medium">Catatan umum:</span> {{ $checklist->note }}</div>
    @endif

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2 print:hidden">
        <div class="flex flex-wrap gap-2">
            @if ($canEdit)
                <a href="{{ route('checklists.fill', $checklist) }}" class="btn-secondary">Edit</a>
            @endif
            <button type="button" onclick="window.print()" class="btn-secondary">Cetak</button>
            @if ($canDelete)
                <form method="POST" action="{{ route('checklists.destroy', $checklist) }}" onsubmit="return confirm('Hapus checklist ini? Tindakan ini tidak bisa dibatalkan.')">
                    @csrf
                    @method('DELETE')
                    <button class="btn-secondary !text-rose-700">Hapus</button>
                </form>
            @endif
        </div>
        @if (! $checklist->isReviewed() && ! $checklist->isComplete())
            <p class="text-sm text-amber-800">Belum lengkap ({{ $checklist->answeredCount() }} / {{ $total }} tugas terisi). Belum bisa direview.</p>
        @elseif ($canReview)
            <form method="POST" action="{{ route('checklists.review', $checklist) }}">
                @csrf
                <button class="btn-primary">Tandai sudah direview</button>
            </form>
        @endif
    </div>
</x-app-layout>
