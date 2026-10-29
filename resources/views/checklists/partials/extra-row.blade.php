<div data-extra-row class="grid gap-2 sm:grid-cols-[1fr_9rem_1fr_auto]">
    <input name="extras[{{ $index }}][title]" value="{{ $extra['title'] ?? '' }}" maxlength="500" class="input" placeholder="Pekerjaan tambahan">
    <select name="extras[{{ $index }}][status]" class="input" aria-label="Status tugas tambahan">
        @foreach ($statuses as $status)
            <option value="{{ $status->value }}" @selected(($extra['status'] ?? 'done') === $status->value)>{{ $status->label() }}</option>
        @endforeach
    </select>
    <input name="extras[{{ $index }}][note]" value="{{ $extra['note'] ?? '' }}" maxlength="1000" class="input" placeholder="Catatan (opsional)">
    <button type="button" data-extra-remove class="px-2 text-slate-400 hover:text-rose-700" aria-label="Hapus">✕</button>
</div>
