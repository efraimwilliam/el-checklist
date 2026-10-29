@php
    $checked = ['done' => 'peer-checked:bg-[#2a5c43]', 'ongoing' => 'peer-checked:bg-[#9a7410]', 'pending' => 'peer-checked:bg-[#b23a24]'];
    $number = 0;
    $total = $checklist->items->count();
@endphp
<x-app-layout :title="'Checklist '.$checklist->employee->name">
    @if ($canEdit)
        @vite(['resources/js/checklist.js'])
        <noscript><style>[data-note] { display: block !important; }</style></noscript>
    @endif

    <div class="card mb-4 flex flex-wrap items-start justify-between gap-3 p-5">
        <div>
            <h1 class="text-3xl leading-tight text-slate-900">{{ $checklist->position_name }}</h1>
            <p class="mt-1 text-[13px] text-slate-500">
                {{ $checklist->department->name }} · {{ $checklist->date->translatedFormat('d M Y') }} ·
                Staff: <strong class="text-slate-700">{{ $checklist->employee->name }}</strong>
                @if ($checklist->reviewer) · Direview: {{ $checklist->reviewer->name() }} @endif
            </p>
        </div>
        <div class="flex flex-col items-end gap-2">
            <div class="flex items-center gap-3">
                <x-status-badge :status="$checklist->status" />
                @if ($checklist->score() !== null)
                    <span class="font-display text-2xl text-sky-700">{{ $checklist->score() }}%</span>
                @endif
            </div>
            @if ($canEdit)
                <div class="flex items-center gap-4 text-[13px]">
                    <button type="button" data-fill-done class="btn-secondary !px-4 !py-1.5">Sisa tugas = Done</button>
                    <button type="button" data-clear class="font-medium text-slate-600 hover:text-rose-700">Kosongkan</button>
                </div>
            @endif
        </div>
    </div>

    @if ($checklist->review_note || $checklist->reviewer)
        <div class="mb-4 rounded-xl border px-4 py-3 text-sm {{ $checklist->status === \App\Enums\ChecklistStatus::Returned ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-slate-200 bg-white text-slate-700' }}">
            @if ($checklist->reviewer)
                <p class="font-medium">Direview {{ $checklist->reviewer->name() }}, {{ $checklist->reviewed_at?->format('d M Y H:i') }}</p>
            @endif
            @if ($checklist->review_note)
                <p class="mt-1 whitespace-pre-line">{{ $checklist->review_note }}</p>
            @endif
        </div>
    @endif

    @if ($errors->any())
        <p class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">Ada isian yang perlu dilengkapi. Periksa tugas yang ditandai merah.</p>
    @endif

    @if ($canEdit)
        <form method="POST" action="{{ route('checklists.update', $checklist) }}" data-checklist-form data-pending-note="{{ config('checklist.pending_note_required') ? '1' : '0' }}">
            @csrf
            @method('PUT')
    @endif

    <div class="card px-5 py-3">
        @forelse ($checklist->items->groupBy(fn ($item) => $item->section ?? '') as $section => $items)
            <div class="{{ $loop->first ? '' : 'mt-3 border-t border-slate-200 pt-3' }}">
                @if ($section !== '' || $checklist->items->pluck('section')->filter()->isNotEmpty())
                    <div class="flex items-center justify-between py-2 text-[11px] font-semibold uppercase tracking-[0.18em]">
                        <span class="text-gold-600">{{ $section !== '' ? $section : 'Tugas' }}</span>
                        <span class="text-slate-400">{{ $items->count() }} tugas</span>
                    </div>
                @endif

                @foreach ($items as $item)
                    @php
                        $number++;
                        $current = old("items.{$item->id}.status", $item->status?->value);
                        $noteValue = old("items.{$item->id}.note", $item->note);
                        $showNote = in_array($current, ['ongoing', 'pending'], true) || filled($noteValue);
                    @endphp
                    <div data-item class="border-t border-slate-100 py-3 first:border-t-0 {{ $errors->has("items.{$item->id}.*") ? 'ring-2 ring-rose-300' : '' }} rounded-lg">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                            <div class="min-w-0 sm:flex-1">
                                <p class="text-[15px] font-medium leading-snug text-slate-800">
                                    <span data-num class="font-display text-lg {{ $current ? 'text-slate-400' : 'text-rose-600' }}">{{ $number }}.</span>
                                    {{ $item->title }}
                                </p>
                                @if ($item->standard)
                                    <details class="mt-1 text-[13px]">
                                        <summary class="cursor-pointer select-none font-semibold text-sky-700">Cara / standar</summary>
                                        <p class="mt-1.5 rounded-lg bg-slate-50 px-3 py-2 text-slate-600">{{ $item->standard }}</p>
                                    </details>
                                @endif
                            </div>

                            @if ($canEdit)
                                <div class="flex w-full shrink-0 overflow-hidden rounded-full border border-slate-200 bg-white text-xs font-semibold sm:inline-flex sm:w-auto" role="radiogroup" aria-label="Status tugas {{ $number }}">
                                    @foreach ($statuses as $status)
                                        <label class="flex-1 cursor-pointer text-center">
                                            <input type="radio" name="items[{{ $item->id }}][status]" value="{{ $status->value }}" class="peer sr-only" @checked($current === $status->value)>
                                            <span class="block px-4 py-2 text-slate-500 transition peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-gold-500 {{ $checked[$status->value] }}">{{ $status->label() }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            @else
                                <x-status-badge :status="$item->status" />
                            @endif
                        </div>

                        @if ($canEdit)
                            <button type="button" data-note-toggle class="mt-1 text-[13px] font-semibold text-sky-700 {{ $showNote ? 'hidden' : '' }}">+ Catatan</button>
                            <div data-note class="mt-2 {{ $showNote ? '' : 'hidden' }}">
                                <input name="items[{{ $item->id }}][note]" value="{{ $noteValue }}" maxlength="1000" class="input" placeholder="{{ $current === 'pending' ? 'Alasan wajib diisi, mis. menunggu barang dari store' : 'Catatan (opsional)' }}">
                                @error("items.{$item->id}.note") <p class="mt-1 text-sm text-rose-700">{{ $message }}</p> @enderror
                            </div>
                            @error("items.{$item->id}.status") <p class="mt-1 text-sm text-rose-700">{{ $message }}</p> @enderror
                        @elseif ($item->note)
                            <p class="mt-1.5 text-[13px] text-slate-500">Catatan: {{ $item->note }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @empty
            <p class="py-4 text-sm text-slate-500">Jabatan ini belum punya daftar tugas.</p>
        @endforelse
    </div>

    @if ($canEdit)
            <div class="card mt-4 p-5">
                <label for="note" class="label">Catatan umum</label>
                <textarea id="note" name="note" rows="2" maxlength="2000" class="input" placeholder="Temuan lain yang perlu dicatat">{{ old('note', $checklist->note) }}</textarea>
            </div>

            {{-- Bilah progres menempel di bawah layar --}}
            <div class="sticky bottom-3 z-10 mt-4 rounded-2xl bg-ink px-5 py-3 text-white shadow-lg">
                <p data-problem class="mb-2 hidden rounded-lg bg-rose-600/90 px-3 py-1.5 text-[13px]"></p>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-[13px] text-white/80">
                        <span data-progress class="font-semibold text-white">0 / {{ $total }}</span> tugas terisi · Skor sementara
                        <span data-score class="font-semibold">0%</span>
                    </p>
                    <div class="flex gap-2">
                        <button name="action" value="save" class="btn !bg-white/10 !text-white hover:!bg-white/20">Simpan dulu</button>
                        <button name="action" value="submit" class="btn-primary">Kirim untuk review</button>
                    </div>
                </div>
            </div>
        </form>
    @elseif ($checklist->note)
        <div class="card mt-4 p-5 text-sm"><span class="font-medium">Catatan umum:</span> {{ $checklist->note }}</div>
    @endif

    @if ($canReview)
        <form method="POST" action="{{ route('checklists.review', $checklist) }}" class="card mt-4 p-5">
            @csrf
            <label for="review_note" class="label">Catatan review</label>
            <textarea id="review_note" name="review_note" rows="2" maxlength="2000" class="input" placeholder="Wajib bila dikembalikan">{{ old('review_note') }}</textarea>
            @error('review_note') <p class="mt-1 text-sm text-rose-700">{{ $message }}</p> @enderror
            <div class="mt-3 flex flex-wrap justify-end gap-2">
                <button name="decision" value="return" class="btn-danger">Kembalikan</button>
                <button name="decision" value="approve" class="btn-primary">Setujui</button>
            </div>
        </form>
    @endif
</x-app-layout>
