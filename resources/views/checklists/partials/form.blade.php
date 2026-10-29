@php
    $checkedClass = ['done' => 'peer-checked:bg-[#2a5c43]', 'ongoing' => 'peer-checked:bg-[#9a7410]', 'pending' => 'peer-checked:bg-[#b23a24]'];
    $isEdit = $mode === 'edit';
    $number = 0;
    $total = count($rows);
    $extras = old('extras', $isEdit ? $checklist->extraTasks->map(fn ($e) => ['title' => $e->title, 'status' => $e->status->value, 'note' => $e->note])->all() : []);
    $hasSections = collect($rows)->pluck('section')->filter()->isNotEmpty();
@endphp
@vite(['resources/js/checklist.js'])
<noscript><style>[data-note] { display: block !important; }</style></noscript>

<div class="card mb-4 flex flex-wrap items-start justify-between gap-3 p-5">
    <div>
        <h2 class="font-display text-3xl leading-tight text-slate-900">{{ $position->name }}</h2>
        <p class="mt-1 text-sm text-slate-500">
            {{ $position->department->name }} · {{ $date->translatedFormat('d M Y') }} ·
            Staff: <strong class="text-slate-700">{{ $employee->name }}</strong>
            @if ($checker && ! $staffMode) · Dicek: {{ $checker }} @endif
        </p>
    </div>
    <div class="flex items-center gap-4 text-sm">
        <button type="button" data-fill-done class="btn-secondary !px-4 !py-1.5">Sisa tugas = Done</button>
        <button type="button" data-clear class="font-medium text-slate-600 hover:text-rose-700">Kosongkan</button>
    </div>
</div>

@if ($isEdit)
    <p class="mb-4 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
        Checklist ini sudah pernah disimpan ({{ $checklist->updated_at->translatedFormat('d M Y H:i') }}). Menyimpan lagi akan memperbarui isiannya, bukan membuat yang baru.
    </p>
@endif

@if ($errors->any() && ! $errors->has('duplicate'))
    <p class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">Ada isian yang perlu dilengkapi. Periksa tugas yang ditandai merah.</p>
@endif

<form method="POST" action="{{ $isEdit ? route('checklists.update', $checklist) : route('fill.store') }}" data-checklist-form data-pending-note="{{ config('checklist.pending_note_required') ? '1' : '0' }}">
    @csrf
    @if ($isEdit) @method('PUT') @else
        <input type="hidden" name="position" value="{{ $position->id }}">
        <input type="hidden" name="employee" value="{{ $employee->id }}">
        <input type="hidden" name="date" value="{{ $date->toDateString() }}">
    @endif

    <div class="card px-5 py-3">
        @foreach (collect($rows)->groupBy(fn ($row) => $row['section'] ?? '') as $section => $group)
            <div class="{{ $loop->first ? '' : 'mt-3 border-t border-slate-200 pt-3' }}">
                @if ($hasSections)
                    <div class="flex items-center justify-between py-2 text-xs font-semibold uppercase tracking-[0.1em]">
                        <span class="text-gold-600">{{ $section !== '' ? $section : 'Tugas' }}</span>
                        <span class="text-slate-400">{{ $group->count() }} tugas</span>
                    </div>
                @endif

                @foreach ($group as $row)
                    @php
                        $number++;
                        $key = $row['key'];
                        $current = old("items.{$key}.status", $row['status']);
                        $noteValue = old("items.{$key}.note", $row['note']);
                        $showNote = in_array($current, ['ongoing', 'pending'], true) || filled($noteValue);
                    @endphp
                    <div data-item class="rounded-lg border-t border-slate-100 py-3 first:border-t-0 {{ $errors->has("items.{$key}.*") ? 'ring-2 ring-rose-300' : '' }}">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                            <div class="min-w-0 sm:flex-1">
                                <p class="text-base font-medium leading-snug text-slate-800">
                                    <span data-num class="mr-0.5 font-semibold tabular-nums {{ $current ? 'text-slate-400' : 'text-rose-600' }}">{{ $number }}.</span>
                                    {{ $row['title'] }}
                                </p>
                                @if ($row['standard'])
                                    <details class="mt-1 text-sm">
                                        <summary class="cursor-pointer select-none font-semibold text-sky-700">Cara / standar</summary>
                                        <p class="mt-1.5 rounded-lg bg-slate-50 px-3 py-2 text-slate-600">{{ $row['standard'] }}</p>
                                    </details>
                                @endif
                            </div>
                            <div class="flex w-full shrink-0 overflow-hidden rounded-full border border-slate-200 bg-white text-xs font-semibold sm:inline-flex sm:w-auto" role="radiogroup" aria-label="Status tugas {{ $number }}">
                                @foreach ($statuses as $status)
                                    <label class="flex-1 cursor-pointer text-center">
                                        <input type="radio" name="items[{{ $key }}][status]" value="{{ $status->value }}" class="peer sr-only" @checked($current === $status->value)>
                                        <span class="block px-4 py-2 text-slate-500 transition peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-gold-500 {{ $checkedClass[$status->value] }}">{{ $status->label() }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <button type="button" data-note-toggle class="mt-1 text-sm font-semibold text-sky-700 {{ $showNote ? 'hidden' : '' }}">+ Catatan</button>
                        <div data-note class="mt-2 {{ $showNote ? '' : 'hidden' }}">
                            <input name="items[{{ $key }}][note]" value="{{ $noteValue }}" maxlength="1000" class="input" placeholder="{{ $current === 'pending' ? 'Alasan wajib diisi, mis. menunggu barang dari store' : 'Catatan (opsional)' }}">
                            @error("items.{$key}.note") <p class="mt-1 text-sm text-rose-700">{{ $message }}</p> @enderror
                        </div>
                        @error("items.{$key}.status") <p class="mt-1 text-sm text-rose-700">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    {{-- Tugas tambahan: pekerjaan di luar daftar (mis. membantu banquet). Tidak dihitung dalam skor. --}}
    <div class="card mt-4 p-5" data-extras>
        <div class="flex items-center justify-between">
            <h2 class="font-display text-xl">Tugas tambahan</h2>
            <button type="button" data-extra-add class="text-sm font-semibold text-sky-700">+ Tambah tugas</button>
        </div>
        <p class="mt-1 text-sm text-slate-500">Pekerjaan di luar daftar, misalnya membantu banquet. Tidak dihitung dalam skor.</p>
        <div data-extra-list class="mt-3 space-y-2">
            @foreach ($extras as $i => $extra)
                @include('checklists.partials.extra-row', ['index' => $i, 'extra' => $extra, 'statuses' => $statuses])
            @endforeach
        </div>
        <template data-extra-template>
            @include('checklists.partials.extra-row', ['index' => '__INDEX__', 'extra' => ['title' => '', 'status' => 'done', 'note' => ''], 'statuses' => $statuses])
        </template>
    </div>

    <div class="card mt-4 grid gap-4 p-5 {{ $staffMode ? '' : 'sm:grid-cols-3' }}">
        @unless ($staffMode)
            <div>
                <label for="checked_by" class="label">Dicek oleh</label>
                <input id="checked_by" name="checked_by" value="{{ old('checked_by', $isEdit ? $checklist->checked_by : $checker) }}" maxlength="255" class="input">
            </div>
        @endunless
        <div class="{{ $staffMode ? '' : 'sm:col-span-2' }}">
            <label for="note" class="label">Catatan umum</label>
            <textarea id="note" name="note" rows="2" maxlength="2000" class="input" placeholder="Temuan lain yang perlu dicatat">{{ old('note', $isEdit ? $checklist->note : '') }}</textarea>
        </div>
    </div>

    {{-- Bilah progres menempel di bawah layar --}}
    <div class="sticky bottom-3 z-10 mt-4 rounded-2xl bg-ink px-5 py-3 text-white shadow-lg">
        <p data-problem class="mb-2 hidden rounded-lg bg-rose-600/90 px-3 py-1.5 text-sm"></p>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-white/80">
                <span data-progress class="font-semibold text-white">0 / {{ $total }}</span> tugas terisi · Skor sementara
                <span data-score class="font-semibold">0%</span>
            </p>
            <button class="btn-primary">Simpan checklist</button>
        </div>
    </div>
</form>
