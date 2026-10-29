@php($old = fn (string $key) => old('_task') == ($task?->id) || ! $task && ! old('_task') ? old($key, $task?->{$key}) : $task?->{$key})
<div class="grid gap-3 sm:grid-cols-3">
    <div class="sm:col-span-1">
        <label class="label">Bagian (opsional)</label>
        <input name="section" value="{{ $old('section') }}" maxlength="255" class="input" placeholder="mis. Awal shift">
    </div>
    <div class="sm:col-span-2">
        <label class="label">Tugas</label>
        <input name="title" value="{{ $old('title') }}" required maxlength="500" class="input">
    </div>
</div>
<div>
    <label class="label">Cara / standar (opsional)</label>
    <textarea name="standard" rows="2" maxlength="2000" class="input">{{ $old('standard') }}</textarea>
</div>
<div>
    <label class="label">Keterangan (opsional)</label>
    <input name="remarks" value="{{ $old('remarks') }}" maxlength="1000" class="input" placeholder="mis. Bulanan">
</div>
