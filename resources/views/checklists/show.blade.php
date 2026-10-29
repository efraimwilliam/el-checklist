<x-app-layout :title="'Checklist '.$checklist->employee->name">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">{{ $checklist->employee->name }}</h1>
            <p class="text-sm text-slate-500">{{ $checklist->date->translatedFormat('l, d F Y') }} · {{ $checklist->department->name }} · {{ $checklist->position_name }}</p>
        </div>
        <div class="text-right">
            <x-status-badge :status="$checklist->status" />
            @if ($checklist->score() !== null)
                <p class="mt-1 text-sm text-slate-500">Skor {{ $checklist->score() }}%</p>
            @endif
        </div>
    </div>

    @if ($checklist->review_note || $checklist->reviewer)
        <div class="mb-4 rounded-lg border px-4 py-3 text-sm {{ $checklist->status === \App\Enums\ChecklistStatus::Returned ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-slate-200 bg-white text-slate-700' }}">
            @if ($checklist->reviewer)
                <p class="font-medium">Direview {{ $checklist->reviewer->name() }}, {{ $checklist->reviewed_at?->format('d M Y H:i') }}</p>
            @endif
            @if ($checklist->review_note)
                <p class="mt-1 whitespace-pre-line">{{ $checklist->review_note }}</p>
            @endif
        </div>
    @endif

    @if ($canEdit)
        <form method="POST" action="{{ route('checklists.update', $checklist) }}">
            @csrf
            @method('PUT')
    @endif

    @if ($errors->any())
        <p class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">Ada isian yang perlu dilengkapi. Periksa tugas yang ditandai merah.</p>
    @endif

    <div class="space-y-3">
        @forelse ($checklist->items->groupBy(fn ($item) => $item->section ?? '') as $section => $items)
            @if ($section !== '')
                <h2 class="pt-2 text-sm font-semibold uppercase tracking-wide text-slate-500">{{ $section }}</h2>
            @endif
            @foreach ($items as $item)
                @php($current = old("items.{$item->id}.status", $item->status?->value))
                <div class="card p-4 {{ $errors->has("items.{$item->id}.*") ? 'border-rose-300' : '' }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ $item->title }}</p>
                            @if ($item->standard)
                                <p class="mt-1 text-sm text-slate-500">{{ $item->standard }}</p>
                            @endif
                        </div>
                        @unless ($canEdit)
                            <x-status-badge :status="$item->status" />
                        @endunless
                    </div>

                    @if ($canEdit)
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($statuses as $status)
                                <label class="cursor-pointer">
                                    <input type="radio" name="items[{{ $item->id }}][status]" value="{{ $status->value }}" class="peer sr-only" @checked($current === $status->value)>
                                    <span class="inline-block rounded-lg border border-slate-300 px-3 py-1.5 text-sm peer-checked:border-sky-700 peer-checked:bg-sky-700 peer-checked:text-white">{{ $status->label() }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error("items.{$item->id}.status") <p class="mt-1 text-sm text-rose-700">{{ $message }}</p> @enderror
                        <input name="items[{{ $item->id }}][note]" value="{{ old("items.{$item->id}.note", $item->note) }}" placeholder="Catatan (wajib bila Pending)" maxlength="1000" class="input mt-2">
                        @error("items.{$item->id}.note") <p class="mt-1 text-sm text-rose-700">{{ $message }}</p> @enderror
                    @elseif ($item->note)
                        <p class="mt-2 text-sm text-slate-600">Catatan: {{ $item->note }}</p>
                    @endif
                </div>
            @endforeach
        @empty
            <p class="card p-6 text-sm text-slate-500">Jabatan ini belum punya daftar tugas.</p>
        @endforelse
    </div>

    @if ($canEdit)
            <div class="card mt-4 p-4">
                <label for="note" class="label">Catatan umum</label>
                <textarea id="note" name="note" rows="2" maxlength="2000" class="input">{{ old('note', $checklist->note) }}</textarea>
                <div class="mt-3 flex flex-wrap justify-end gap-2">
                    <button name="action" value="save" class="btn-secondary">Simpan dulu</button>
                    <button name="action" value="submit" class="btn-primary">Kirim untuk review</button>
                </div>
            </div>
        </form>
    @elseif ($checklist->note)
        <div class="card mt-4 p-4 text-sm"><span class="font-medium">Catatan umum:</span> {{ $checklist->note }}</div>
    @endif

    @if ($canReview)
        <form method="POST" action="{{ route('checklists.review', $checklist) }}" class="card mt-4 p-4">
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
